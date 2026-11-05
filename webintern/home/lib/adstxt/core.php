<?php
declare(strict_types=1);

/**
 * Ads.txt-Kern: Zeilen prüfen, Bausteine zusammenführen, Dubletten entfernen, Änderungen erkennen.
 * Reine Funktionen ohne Datenbank – siehe tests/adstxt_core_test.php.
 *
 * Format (IAB ads.txt 1.1, gilt gleich für app-ads.txt):
 *   Datensatz:  <Domain>, <Konto-ID>, <DIRECT|RESELLER>[, <Zertifizierungs-ID>][;<Erweiterung>]
 *   Variable:   <NAME>=<Wert>   (CONTACT, SUBDOMAIN, INVENTORYPARTNERDOMAIN, OWNERDOMAIN, MANAGERDOMAIN)
 *   Kommentar:  # …
 */

const ADSTXT_VARIABLES = ['OWNERDOMAIN', 'MANAGERDOMAIN', 'CONTACT', 'SUBDOMAIN', 'INVENTORYPARTNERDOMAIN'];
/** Variablen, die pro Datei nur einmal vorkommen dürfen. */
const ADSTXT_SINGLE_VARIABLES = ['OWNERDOMAIN'];

/** Ebenen in Vorrang-Reihenfolge (kleiner = gewinnt bei Kopfzeilen). */
const ADSTXT_LEVEL_PUBLISHER_HEADER = 1;
const ADSTXT_LEVEL_PUBLISHER_BLOCK = 2;
const ADSTXT_LEVEL_PUBLISHER_SOURCE = 3;
const ADSTXT_LEVEL_GLOBAL_BLOCK = 4;
const ADSTXT_LEVEL_GLOBAL_SOURCE = 5;

const ADSTXT_FILE_NAMES = ['ads' => 'ads.txt', 'app-ads' => 'app-ads.txt'];

/**
 * Zerlegt eine Zeile.
 *
 * @return array{type: string, line?: string, key?: string, value?: string, domain?: string, message?: string}
 *   type: empty | comment | record | variable | invalid
 *   line: normalisierte Zeile (bei record/variable), key: Dubletten-Schlüssel
 */
function adstxt_parse_line(string $raw): array
{
    $line = trim(str_replace(["\r", "\u{FEFF}", "\t"], ['', '', ' '], $raw));
    if ($line === '') {
        return ['type' => 'empty'];
    }
    if ($line[0] === '#') {
        return ['type' => 'comment'];
    }
    // Kommentar am Zeilenende abschneiden.
    $hash = strpos($line, '#');
    if ($hash !== false) {
        $line = rtrim(substr($line, 0, $hash));
    }

    // Variable: NAME=Wert (vor dem "=" kein Komma).
    $eq = strpos($line, '=');
    $comma = strpos($line, ',');
    if ($eq !== false && ($comma === false || $eq < $comma)) {
        $name = strtoupper(trim(substr($line, 0, $eq)));
        $value = trim(substr($line, $eq + 1));
        if (!in_array($name, ADSTXT_VARIABLES, true)) {
            return ['type' => 'invalid', 'message' => "Unbekannte Variable „{$name}“"];
        }
        if ($value === '') {
            return ['type' => 'invalid', 'message' => "Variable {$name} ohne Wert"];
        }
        if (in_array($name, ['OWNERDOMAIN', 'MANAGERDOMAIN', 'SUBDOMAIN', 'INVENTORYPARTNERDOMAIN'], true)) {
            $value = strtolower($value);
        }
        return ['type' => 'variable', 'name' => $name, 'value' => $value, 'line' => "{$name}={$value}", 'key' => $name . '=' . strtolower($value)];
    }

    // Datensatz, optional mit Erweiterung nach ";".
    $extension = '';
    $semi = strpos($line, ';');
    if ($semi !== false) {
        $extension = trim(substr($line, $semi + 1));
        $line = trim(substr($line, 0, $semi));
    }
    $fields = array_map('trim', explode(',', $line));
    if (count($fields) < 3) {
        return ['type' => 'invalid', 'message' => 'Zu wenige Felder (erwartet: Domain, Konto-ID, DIRECT/RESELLER)'];
    }
    if (count($fields) > 4) {
        return ['type' => 'invalid', 'message' => 'Zu viele Felder'];
    }

    $domain = strtolower($fields[0]);
    $account = $fields[1];
    $relationship = strtoupper($fields[2]);
    $certId = $fields[3] ?? '';

    if (!preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/', $domain)) {
        return ['type' => 'invalid', 'message' => "Ungültige Domain „{$fields[0]}“"];
    }
    if ($account === '' || preg_match('/\s/', $account)) {
        return ['type' => 'invalid', 'message' => 'Konto-ID fehlt oder enthält Leerzeichen'];
    }
    if (!in_array($relationship, ['DIRECT', 'RESELLER'], true)) {
        return ['type' => 'invalid', 'message' => "Beziehung muss DIRECT oder RESELLER sein, nicht „{$fields[2]}“"];
    }
    if ($certId !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $certId)) {
        return ['type' => 'invalid', 'message' => "Ungültige Zertifizierungs-ID „{$certId}“"];
    }

    $normalized = "{$domain}, {$account}, {$relationship}" . ($certId !== '' ? ", {$certId}" : '') . ($extension !== '' ? ";{$extension}" : '');
    return [
        'type' => 'record',
        'line' => $normalized,
        // Konto-IDs werden ohne Groß/Klein verglichen (z. B. "pub-123" vs. "PUB-123").
        'key' => $domain . '|' . strtolower($account) . '|' . $relationship,
        'domain' => $domain,
        'cert' => $certId,
    ];
}

/**
 * Prüft einen Textblock (Quelle oder manuelle Einträge).
 *
 * @return array{records: int, variables: int, invalid: list<array{line_no: int, raw: string, message: string}>}
 */
function adstxt_analyze(string $text): array
{
    $result = ['records' => 0, 'variables' => 0, 'invalid' => []];
    foreach (preg_split('/\R/', $text) as $i => $raw) {
        $p = adstxt_parse_line($raw);
        match ($p['type']) {
            'record' => $result['records']++,
            'variable' => $result['variables']++,
            'invalid' => $result['invalid'][] = ['line_no' => $i + 1, 'raw' => trim($raw), 'message' => $p['message']],
            default => null,
        };
    }
    return $result;
}

/**
 * Baut eine ads.txt bzw. app-ads.txt aus Bausteinen.
 *
 * @param array $publisher ['name' => …, 'domain' => …]
 * @param list<array{level: int, label: string, text: string}> $parts in beliebiger Reihenfolge
 * @return array{
 *   content: string, body: string, body_hash: string, record_count: int,
 *   duplicates: list<array{line: string, label: string, kept_in: string}>,
 *   invalid: list<array{raw: string, label: string, message: string}>,
 *   overridden: list<array{line: string, label: string, reason: string}>,
 *   origins: array<string, string>
 * }
 */
function adstxt_build(array $publisher, string $fileType, array $parts, ?DateTimeInterface $now = null): array
{
    // Stabil nach Ebene sortieren (gleiche Ebene: Reihenfolge wie übergeben).
    $indexed = [];
    foreach (array_values($parts) as $i => $part) {
        $indexed[] = [$part['level'], $i, $part];
    }
    usort($indexed, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $parts = array_column($indexed, 2);

    $groups = [];        // label => list<line>
    $seenRecords = [];   // key => label
    $variables = [];     // name => [level => list<[line, key, label]>]
    $duplicates = [];
    $invalid = [];
    $origins = [];       // normalisierte Zeile => label

    foreach ($parts as $part) {
        foreach (preg_split('/\R/', $part['text']) as $raw) {
            $p = adstxt_parse_line($raw);
            if ($p['type'] === 'invalid') {
                $invalid[] = ['raw' => trim($raw), 'label' => $part['label'], 'message' => $p['message']];
            } elseif ($p['type'] === 'variable') {
                $variables[$p['name']][$part['level']][] = [$p['line'], $p['key'], $part['label']];
            } elseif ($p['type'] === 'record') {
                if (isset($seenRecords[$p['key']])) {
                    [$keptLabel, $keptIndex] = $seenRecords[$p['key']];
                    $keptLine = $groups[$keptLabel][$keptIndex];
                    // Gleicher Eintrag: fehlende Zertifizierungs-ID aus der späteren Fundstelle ergänzen.
                    if ($p['cert'] !== '' && adstxt_parse_line($keptLine)['cert'] === '') {
                        $groups[$keptLabel][$keptIndex] = $p['line'];
                        unset($origins[$keptLine]);
                        $origins[$p['line']] = $keptLabel;
                    }
                    $duplicates[] = ['line' => $p['line'], 'label' => $part['label'], 'kept_in' => $keptLabel];
                    continue;
                }
                $groups[$part['label']][] = $p['line'];
                $seenRecords[$p['key']] = [$part['label'], array_key_last($groups[$part['label']])];
                $origins[$p['line']] = $part['label'];
            }
        }
    }

    // Kopfzeilen: je Variable gilt nur die Ebene mit dem höchsten Vorrang; dort Dubletten entfernen.
    $headerLines = [];
    $overridden = [];
    foreach (ADSTXT_VARIABLES as $name) {
        if (!isset($variables[$name])) {
            continue;
        }
        ksort($variables[$name]);
        $winningLevel = array_key_first($variables[$name]);
        $seen = [];
        foreach ($variables[$name] as $level => $entries) {
            foreach ($entries as [$line, $key, $label]) {
                if ($level !== $winningLevel) {
                    $overridden[] = ['line' => $line, 'label' => $label, 'reason' => "{$name} ist auf höherer Ebene gesetzt"];
                    continue;
                }
                if (isset($seen[$key])) {
                    continue;
                }
                if (in_array($name, ADSTXT_SINGLE_VARIABLES, true) && $seen !== []) {
                    $overridden[] = ['line' => $line, 'label' => $label, 'reason' => "{$name} darf nur einmal vorkommen"];
                    continue;
                }
                $seen[$key] = true;
                $headerLines[] = $line;
                $origins[$line] = $label;
            }
        }
    }

    // Dateiinhalt ohne Zeitstempel (für Änderungserkennung).
    $body = '';
    if ($headerLines) {
        $body .= implode("\n", $headerLines) . "\n";
    }
    foreach ($groups as $label => $lines) {
        $body .= "\n# --- " . adstxt_comment_safe($label) . " ---\n" . implode("\n", $lines) . "\n";
    }

    $now ??= new DateTimeImmutable();
    $title = ADSTXT_FILE_NAMES[$fileType] . ' für ' . $publisher['name'] . ($publisher['domain'] !== '' ? " ({$publisher['domain']})" : '');
    $content = '# ' . adstxt_comment_safe($title) . "\n"
        . '# Automatisch erzeugt am ' . $now->format('d.m.Y H:i') . " – bitte nicht von Hand bearbeiten.\n"
        . ($body !== '' ? "\n" . ltrim($body, "\n") : '');

    return [
        'content' => $content,
        'body' => $body,
        'body_hash' => hash('sha256', $body),
        'record_count' => count($seenRecords),
        'duplicates' => $duplicates,
        'invalid' => $invalid,
        'overridden' => $overridden,
        'origins' => $origins,
    ];
}

/** Verhindert Zeilenumbrüche in Kommentaren. */
function adstxt_comment_safe(string $text): string
{
    return trim(preg_replace('/[\r\n#]+/', ' ', $text) ?? '');
}

/**
 * Vergleicht zwei Datei-Bodies und liefert hinzugekommene/weggefallene Zeilen (ohne Kommentare).
 *
 * @return array{added: list<string>, removed: list<string>}
 */
function adstxt_diff(string $oldBody, string $newBody): array
{
    $lines = static function (string $body): array {
        $out = [];
        foreach (preg_split('/\R/', $body) as $l) {
            $l = trim($l);
            if ($l !== '' && $l[0] !== '#') {
                $out[$l] = true;
            }
        }
        return array_keys($out);
    };
    $old = $lines($oldBody);
    $new = $lines($newBody);
    return ['added' => array_values(array_diff($new, $old)), 'removed' => array_values(array_diff($old, $new))];
}

/**
 * Bewertet einen frisch abgerufenen Quellinhalt.
 *
 * @return array{accept: bool, status: string, message: string, records: int, invalid: int}
 *   accept=false → letzten gültigen Stand weiterverwenden.
 */
function adstxt_check_source(array $response, ?int $previousRecords): array
{
    if (!$response['ok']) {
        return ['accept' => false, 'status' => 'error', 'message' => 'Abruf fehlgeschlagen: ' . ($response['error'] ?? 'unbekannt'), 'records' => 0, 'invalid' => 0];
    }
    $body = $response['body'];
    if (strlen($body) > 5 * 1024 * 1024) {
        return ['accept' => false, 'status' => 'error', 'message' => 'Antwort größer als 5 MB', 'records' => 0, 'invalid' => 0];
    }
    if (preg_match('/^\s*(<!doctype|<html|<\?xml|\{|\[)/i', $body)) {
        return ['accept' => false, 'status' => 'error', 'message' => 'Antwort ist keine ads.txt (HTML/JSON/XML erhalten)', 'records' => 0, 'invalid' => 0];
    }
    if (!mb_check_encoding($body, 'UTF-8')) {
        $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
    }
    $a = adstxt_analyze($body);
    if ($a['records'] === 0 && $a['variables'] === 0) {
        return ['accept' => false, 'status' => 'error', 'message' => 'Keine gültigen Einträge gefunden', 'records' => 0, 'invalid' => count($a['invalid'])];
    }
    if ($previousRecords !== null && $previousRecords >= 10 && $a['records'] < $previousRecords * 0.5) {
        return ['accept' => false, 'status' => 'warning',
            'message' => "Nur noch {$a['records']} statt {$previousRecords} Einträge – nicht übernommen, letzter Stand bleibt aktiv",
            'records' => $a['records'], 'invalid' => count($a['invalid'])];
    }
    $msg = "{$a['records']} Einträge";
    if ($a['invalid']) {
        $msg .= ', ' . count($a['invalid']) . ' ungültige Zeile(n) ignoriert';
    }
    return ['accept' => true, 'status' => $a['invalid'] ? 'warning' : 'ok', 'message' => $msg, 'records' => $a['records'], 'invalid' => count($a['invalid']), 'body' => $body];
}
