<?php
declare(strict_types=1);

/**
 * Ad-Manager-Berichte über die SOAP-ReportService-API.
 *
 * Warum SOAP: runReportJob rechnet einen Bericht, ohne ihn im Ad Manager zu speichern. Die REST-API legt
 * dagegen einen gespeicherten Bericht im (Ströer-)Netzwerk an – das wollen wir mit einem Lesezugang vermeiden.
 *
 * Schutz: Wartezeit, Download-Größe und gelesene Zeilen sind begrenzt; Aufrufe laufen über gam_soap()
 * (Bremse) und sollten in gam_exclusive() stehen (keine parallelen Abfragen unserer Tools).
 */

require_once __DIR__ . '/gam.php';

const GAM_REPORT_MAX_WAIT = 120;            // Sekunden, dann Abbruch
const GAM_REPORT_POLL_INTERVAL = 5;         // Sekunden zwischen Statusabfragen
const GAM_REPORT_MAX_BYTES = 5 * 1024 * 1024; // größere Berichte werden nicht heruntergeladen

/**
 * Rechnet einen Bericht und liest das Ergebnis (CSV).
 *
 * @param array $query ReportQuery, z. B. ['dimensions' => ['AD_UNIT_NAME'], 'columns' => ['AD_SERVER_IMPRESSIONS'],
 *                     'dateRangeType' => 'YESTERDAY', 'adUnitView' => 'TOP_LEVEL', 'statement' => ['query' => 'WHERE …']]
 * @param int $keepRows so viele Datenzeilen werden zurückgegeben (alle werden gezählt)
 * @return array{header: list<string>, rows: list<list<string>>, total_rows: int, bytes: int, seconds: float}
 */
function gam_report_run(array $query, int $keepRows = 20): array
{
    $start = microtime(true);
    $query += ['timeZoneType' => 'PUBLISHER'];

    $job = gam_soap('ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $query]]);
    $jobId = $job->id ?? null;
    if (!$jobId) {
        throw new GamError('Bericht wurde von Google nicht angenommen.');
    }

    // Warten, bis Google fertig gerechnet hat.
    do {
        sleep(GAM_REPORT_POLL_INTERVAL);
        $status = (string) gam_soap('ReportService', 'getReportJobStatus', ['reportJobId' => $jobId]);
        if ($status === 'FAILED') {
            throw new GamError('Google konnte den Bericht nicht erstellen (Status FAILED).');
        }
        if (microtime(true) - $start > GAM_REPORT_MAX_WAIT) {
            throw new GamError('Bericht dauert länger als ' . GAM_REPORT_MAX_WAIT . ' s – abgebrochen (Zeitraum/Detailgrad verkleinern).');
        }
    } while ($status !== 'COMPLETED');

    $url = (string) gam_soap('ReportService', 'getReportDownloadUrlWithOptions', [
        'reportJobId' => $jobId,
        'reportDownloadOptions' => ['exportFormat' => 'CSV_DUMP', 'includeReportProperties' => false,
            'includeTotalsRow' => false, 'useGzipCompression' => true],
    ]);

    $gz = gam_report_download($url);
    $csv = gzdecode($gz);
    if ($csv === false) {
        throw new GamError('Berichtsdatei konnte nicht entpackt werden.');
    }

    $header = [];
    $rows = [];
    $total = 0;
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    while (($line = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($header === []) {
            $header = $line;
            continue;
        }
        if ($line === [null]) {
            continue;
        }
        $total++;
        if (count($rows) < $keepRows) {
            $rows[] = $line;
        }
    }
    fclose($fh);

    return ['header' => $header, 'rows' => $rows, 'total_rows' => $total, 'bytes' => strlen($gz),
        'seconds' => round(microtime(true) - $start, 1)];
}

/** Lädt die (signierte) Berichtsdatei herunter – mit Größenbremse. */
function gam_report_download(string $url): string
{
    if (!preg_match('#^https://#', $url)) {
        throw new GamError('Unerwartete Download-Adresse für den Bericht.');
    }
    $data = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$data): int {
            $data .= $chunk;
            return strlen($data) > GAM_REPORT_MAX_BYTES ? 0 : strlen($chunk); // 0 = Abbruch
        },
    ]);
    if ($proxy = config('http_proxy')) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    if ($ca = config('http_ca_file')) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (strlen($data) > GAM_REPORT_MAX_BYTES) {
        throw new GamError('Bericht größer als ' . (GAM_REPORT_MAX_BYTES / 1024 / 1024) . ' MB – Download abgebrochen (Detailgrad verkleinern).');
    }
    if ($ok === false || $status !== 200) {
        throw new GamError("Berichtsdatei konnte nicht geladen werden (HTTP {$status}).");
    }
    return $data;
}
