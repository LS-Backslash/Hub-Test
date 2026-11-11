<?php
declare(strict_types=1);

/**
 * Datenprobe Ad Manager – NUR LESEND, bewusst klein gehalten:
 *  - Zeitraum: gestern · max. 5 Berichte · max. 20 gespeicherte Zeilen je Bericht · max. 5 MB je Bericht
 *  - max. 3 Durchläufe pro Tag · keine parallelen Abfragen (gam_exclusive) · Bremse zwischen Anfragen
 *  - Berichte über SOAP runReportJob: es wird im Ad Manager nichts gespeichert oder angelegt
 *
 * Ausgabe im Terminal: nur Zahlen und Namen der Verlags-Anzeigenblöcke (keine Werbetreibenden/Aufträge).
 * Beispielzeilen werden auf dem Server unter home/data/gam-probe/ abgelegt (für die spätere Web-Übersicht).
 *
 * Aufruf: php8.4 /data/wwwcust/ads/home/bin/gam-probe.php
 *         php8.4 …/gam-probe.php --verlag=123456   (gezielte Abrufe für diesen Anzeigenblock statt des ersten)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';
require APP_HOME . '/lib/connectors/gam-reports.php';

const PROBE_MAX_RUNS_PER_DAY = 3;
const PROBE_KEEP_ROWS = 20;
const PROBE_DIR = 'gam-probe';

// Erst Konfiguration prüfen – Einrichtungsfehler sollen keinen der Tagesläufe verbrauchen.
gam_config();
gam_key();

// --- Tagesgrenze ---------------------------------------------------------
$counterFile = PROBE_DIR . '/runs.json';
$runs = read_json($counterFile, []);
$today = date('Y-m-d');
$runs = array_filter($runs, static fn ($d) => $d === $today);
if (count($runs) >= PROBE_MAX_RUNS_PER_DAY && !in_array('--force', $argv, true)) {
    fwrite(STDERR, 'Heute schon ' . count($runs) . " Mal ausgeführt (Grenze " . PROBE_MAX_RUNS_PER_DAY . "). Morgen wieder.\n");
    exit(1);
}
$runs[] = $today;
write_json($counterFile, array_values($runs));

function out(string $s = ''): void
{
    echo $s . "\n";
}
function line(?bool $ok, string $label, string $detail = ''): void
{
    echo ($ok === null ? '  ·  ' : ($ok ? ' OK  ' : ' !!  ')) . mb_str_pad($label, 34) . $detail . "\n";
}
function human_bytes(int $b): string
{
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format($b / 1024, 1, ',', '.') . ' KB';
}
function save_sample(string $name, array $report, array $query): void
{
    write_json(PROBE_DIR . "/{$name}.json", ['created_at' => date('c'), 'query' => $query] + $report);
}

$chosen = null;
foreach ($argv as $a) {
    if (preg_match('/^--verlag=(\d+)$/', $a, $m)) {
        $chosen = $m[1];
    }
}

gam_exclusive(static function () use ($chosen): void {
    $net = gam_config()['network_code'];
    $yesterday = date('d.m.Y', strtotime('-1 day'));
    out("\nDatenprobe Netzwerk {$net} · Zeitraum: gestern ({$yesterday})");

    // --- 1. Struktur (kein Bericht, nur Zählabfragen) ----------------------
    out("\n== 1. Struktur ==");
    $rootId = null;
    $topLevel = [];
    try {
        $network = gam_soap('NetworkService', 'getCurrentNetwork');
        $rootId = (string) $network->effectiveRootAdUnitId;
        $page = gam_soap('InventoryService', 'getAdUnitsByStatement', ['filterStatement' => ['query' => 'LIMIT 1']]);
        line(true, 'Anzeigenblöcke gesamt', (string) ($page->totalResultSetSize ?? '?'));
        $tl = gam_soap('InventoryService', 'getAdUnitsByStatement',
            ['filterStatement' => ['query' => "WHERE parentId = {$rootId} AND status = 'ACTIVE' ORDER BY name LIMIT 100"]]);
        $topLevel = $tl->results ?? [];
        line(true, 'Oberste Ebene (aktiv)', ($tl->totalResultSetSize ?? count($topLevel)) . ' Anzeigenblöcke');
        foreach ($topLevel as $u) {
            out('        - ' . $u->name . ' (ID ' . $u->id . ')');
        }
        $li = gam_soap('LineItemService', 'getLineItemsByStatement', ['filterStatement' => ['query' => "WHERE status = 'DELIVERING' LIMIT 1"]]);
        line(true, 'Werbebuchungen, die gerade laufen', (string) ($li->totalResultSetSize ?? '?'));
    } catch (Throwable $e) {
        line(false, 'Struktur', $e->getMessage());
    }

    // --- 2.–5. Berichte (max. 5) --------------------------------------------
    $probes = [
        'verlage' => ['Kennzahlen je Verlag (oberste Ebene)', [
            'dimensions' => ['AD_UNIT_ID', 'AD_UNIT_NAME'], 'adUnitView' => 'TOP_LEVEL', 'dateRangeType' => 'YESTERDAY',
            'columns' => ['AD_SERVER_IMPRESSIONS', 'AD_SERVER_CLICKS', 'AD_SERVER_CTR'],
        ]],
        'viewability' => ['Sichtbarkeit (Viewability)', [
            'dimensions' => ['AD_UNIT_ID', 'AD_UNIT_NAME'], 'adUnitView' => 'TOP_LEVEL', 'dateRangeType' => 'YESTERDAY',
            'columns' => ['AD_SERVER_ACTIVE_VIEW_MEASURABLE_IMPRESSIONS', 'AD_SERVER_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS_RATE'],
        ]],
        'umsatz' => ['Umsatz (Ad Server)', [
            'dimensions' => ['AD_UNIT_ID', 'AD_UNIT_NAME'], 'adUnitView' => 'TOP_LEVEL', 'dateRangeType' => 'YESTERDAY',
            'columns' => ['AD_SERVER_CPM_AND_CPC_REVENUE', 'AD_SERVER_WITHOUT_CPD_AVERAGE_ECPM'],
        ]],
    ];
    // Gezielte Abrufe nur für EINEN Verlag (den ersten der obersten Ebene).
    if ($topLevel) {
        $one = $topLevel[0];
        foreach ($topLevel as $u) {
            if ($chosen !== null && (string) $u->id === $chosen) {
                $one = $u;
            }
        }
        $filter = ['query' => 'WHERE PARENT_AD_UNIT_ID = ' . (int) $one->id];
        $probes['werbebuchungen'] = ["Werbebuchungen nur für „{$one->name}“", [
            'dimensions' => ['LINE_ITEM_ID', 'LINE_ITEM_NAME', 'LINE_ITEM_TYPE', 'ORDER_NAME'], 'adUnitView' => 'HIERARCHICAL',
            'dateRangeType' => 'YESTERDAY', 'statement' => $filter,
            'columns' => ['AD_SERVER_IMPRESSIONS', 'AD_SERVER_CLICKS', 'AD_SERVER_CTR'],
        ]];
        $probes['formate'] = ["Formate nur für „{$one->name}“", [
            'dimensions' => ['CREATIVE_SIZE_DELIVERED'], 'adUnitView' => 'HIERARCHICAL',
            'dateRangeType' => 'YESTERDAY', 'statement' => $filter,
            'columns' => ['AD_SERVER_IMPRESSIONS', 'AD_SERVER_CLICKS'],
        ]];
    }

    $n = 1;
    foreach (array_slice($probes, 0, 5, true) as $name => [$label, $query]) {
        $n++;
        out("\n== {$n}. {$label} ==");
        try {
            $r = gam_report_run($query, PROBE_KEEP_ROWS);
            save_sample($name, $r, $query);
            line(true, 'Bericht gerechnet', "{$r['total_rows']} Zeilen · " . human_bytes($r['bytes']) . " · {$r['seconds']} s");
            line(null, 'Spalten', implode(', ', array_map(static fn ($h) => preg_replace('/^(Dimension|Column)\./', '', $h), $r['header'])));
            if ($name === 'verlage' || $name === 'viewability' || $name === 'umsatz') {
                // Verlagsnamen sind Strukturinfo; Zahlen dazu ausgeben (max. 20 Zeilen).
                foreach ($r['rows'] as $row) {
                    out('        ' . implode(' | ', $row));
                }
            } else {
                out('        (Beispielzeilen gespeichert in home/data/' . PROBE_DIR . "/{$name}.json – nicht im Terminal, da Kundennamen enthalten sein können)");
            }
        } catch (Throwable $e) {
            line(false, 'Bericht', $e->getMessage());
        }
    }
    out("\nFertig. Ausgabe bitte an Claude schicken (enthält keine Schlüssel und keine Werbetreibenden).");
});
