<?php
declare(strict_types=1);

/**
 * Abgleich („Ampel“): Stimmt die ads.txt/app-ads.txt auf der Website des Verlags mit unserem Stand überein?
 *
 * Verglichen werden Datensätze und Kopfzeilen inhaltlich (normalisiert, ohne Kommentare,
 * Reihenfolge egal). Grün = identisch, Gelb = Zeilen fehlen oder sind zu viel, Rot = nicht abrufbar.
 */

require_once __DIR__ . '/core.php';

const ADSTXT_VERIFY_TIMEOUT = 12;
const ADSTXT_VERIFY_PARALLEL = 20;

const ADSTXT_CHECK_LABELS = [
    'ok' => 'Aktuell',
    'outdated' => 'Veraltet',
    'error' => 'Problem',
    'skipped' => 'Nicht prüfbar',
];

/** Normalisierte Zeilen einer Datei: Schlüssel => Zeile. */
function adstxt_line_map(string $text): array
{
    $map = [];
    foreach (preg_split('/\R/', $text) as $raw) {
        $p = adstxt_parse_line($raw);
        if ($p['type'] === 'record' || $p['type'] === 'variable') {
            $map[$p['key']] ??= $p['line'];
        }
    }
    return $map;
}

/**
 * Vergleicht unseren Stand mit der Datei beim Verlag.
 *
 * @return array{missing: list<string>, extra: list<string>, remote_generated: ?string}
 */
function adstxt_compare(string $ours, string $theirs): array
{
    $a = adstxt_line_map($ours);
    $b = adstxt_line_map($theirs);
    $generated = preg_match('/Automatisch erzeugt am (\d{2}\.\d{2}\.\d{4} \d{2}:\d{2})/', $theirs, $m) ? $m[1] : null;
    return [
        'missing' => array_values(array_diff_key($a, $b)),
        'extra' => array_values(array_diff_key($b, $a)),
        'remote_generated' => $generated,
    ];
}

/** Bewertet eine Antwort von der Website des Verlags. */
function adstxt_evaluate(array $response, string $ours): array
{
    if (!$response['ok']) {
        $msg = $response['status'] === 404 ? 'Datei nicht gefunden (404)' : 'Nicht abrufbar: ' . ($response['error'] ?? 'unbekannt');
        return ['status' => 'error', 'message' => $msg, 'missing' => [], 'extra' => [], 'remote_generated' => null];
    }
    $body = $response['body'];
    if (preg_match('/^\s*(<!doctype|<html|<\?xml|\{)/i', $body)) {
        return ['status' => 'error', 'message' => 'Unter der Adresse liegt keine Textdatei, sondern eine Webseite (z. B. Fehlerseite)',
            'missing' => [], 'extra' => [], 'remote_generated' => null];
    }
    if (!mb_check_encoding($body, 'UTF-8')) {
        $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
    }
    $c = adstxt_compare($ours, $body);
    if ($c['missing'] === [] && $c['extra'] === []) {
        return ['status' => 'ok', 'message' => 'Stimmt mit unserem Stand überein'] + $c;
    }
    $parts = [];
    if ($c['missing']) {
        $parts[] = count($c['missing']) . ' Zeile(n) fehlen';
    }
    if ($c['extra']) {
        $parts[] = count($c['extra']) . ' Zeile(n) zu viel';
    }
    return ['status' => 'outdated', 'message' => implode(', ', $parts)] + $c;
}

/**
 * Prüft alle aktiven Publisher (oder nur einen) und speichert das Ergebnis.
 *
 * @return array{ok: int, outdated: int, error: int, skipped: int}
 */
function adstxt_verify(?int $publisherId = null): array
{
    $publishers = $publisherId ? array_filter([adstxt_publisher($publisherId)], static fn ($p) => $p && $p['active']) : adstxt_publishers(false);
    $outputs = [];
    foreach (db_all('SELECT publisher_id, file_type, content, changed_at FROM adstxt_outputs') as $o) {
        $outputs[$o['publisher_id']][$o['file_type']] = $o;
    }
    $targets = [];
    foreach ($publishers as $p) {
        foreach (['ads' => 'wants_ads', 'app-ads' => 'wants_app_ads'] as $type => $flag) {
            if ($p[$flag]) {
                $o = $outputs[$p['id']][$type] ?? null;
                $targets["{$p['id']}|{$type}"] = ['publisher' => $p, 'type' => $type, 'content' => $o['content'] ?? null, 'changed_at' => $o['changed_at'] ?? null];
            }
        }
    }

    $counts = ['ok' => 0, 'outdated' => 0, 'error' => 0, 'skipped' => 0];
    $now = db_now();
    $save = static function (array $t, string $status, ?string $url, ?int $http, string $message, array $c = []) use ($now, &$counts): void {
        $counts[$status]++;
        db_run('INSERT INTO adstxt_checks (publisher_id, file_type, checked_at, status, url, http_status, message,
                    missing_count, extra_count, missing, extra, remote_generated, our_changed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at), status = VALUES(status), url = VALUES(url),
                    http_status = VALUES(http_status), message = VALUES(message), missing_count = VALUES(missing_count),
                    extra_count = VALUES(extra_count), missing = VALUES(missing), extra = VALUES(extra),
                    remote_generated = VALUES(remote_generated), our_changed_at = VALUES(our_changed_at)', [
            $t['publisher']['id'], $t['type'], $now, $status, $url, $http, mb_substr($message, 0, 500),
            count($c['missing'] ?? []), count($c['extra'] ?? []),
            implode("\n", array_slice($c['missing'] ?? [], 0, 2000)), implode("\n", array_slice($c['extra'] ?? [], 0, 2000)),
            $c['remote_generated'] ?? null, $t['changed_at'],
        ]);
    };

    // Erst https, bei Verbindungsfehler http (laut ads.txt-Spezifikation beides zulässig).
    $urls = [];
    foreach ($targets as $key => $t) {
        if ($t['publisher']['domain'] === '') {
            $save($t, 'skipped', null, null, 'Keine Domain hinterlegt');
        } elseif ($t['content'] === null) {
            $save($t, 'skipped', null, null, 'Bei uns noch keine Datei erzeugt');
        } else {
            $urls[$key] = 'https://' . $t['publisher']['domain'] . '/' . ADSTXT_FILE_NAMES[$t['type']];
        }
    }
    $responses = fetch_many($urls, ADSTXT_VERIFY_TIMEOUT, ADSTXT_VERIFY_PARALLEL);
    $retry = [];
    foreach ($responses as $key => $res) {
        // Keine Verbindung über https (z. B. kein Zertifikat) → http versuchen; bei Zeitüberschreitung nicht nochmal warten.
        if ($res['status'] === 0 && !str_contains((string) $res['error'], 'Timeout')) {
            $retry[$key] = 'http://' . substr($urls[$key], strlen('https://'));
        }
    }
    foreach (fetch_many($retry, ADSTXT_VERIFY_TIMEOUT, ADSTXT_VERIFY_PARALLEL) as $key => $res) {
        if ($res['status'] !== 0) {
            $responses[$key] = $res;
            $urls[$key] = $retry[$key];
        }
    }

    foreach ($responses as $key => $res) {
        $t = $targets[$key];
        $e = adstxt_evaluate($res, $t['content']);
        $save($t, $e['status'], $urls[$key], $res['status'] ?: null, $e['message'], $e);
    }

    app_log('adstxt', sprintf('Abgleich: %d aktuell, %d veraltet, %d Problem, %d nicht prüfbar',
        $counts['ok'], $counts['outdated'], $counts['error'], $counts['skipped']));
    return $counts;
}

/** Gespeicherte Prüfergebnisse inkl. Publisher-Daten. */
function adstxt_checks(?string $status = null): array
{
    $sql = 'SELECT c.*, p.name, p.slug, p.domain, o.changed_at AS output_changed_at
            FROM adstxt_checks c
            JOIN adstxt_publishers p ON p.id = c.publisher_id AND p.active = 1
            LEFT JOIN adstxt_outputs o ON o.publisher_id = c.publisher_id AND o.file_type = c.file_type
            WHERE (c.file_type = \'ads\' AND p.wants_ads = 1 OR c.file_type = \'app-ads\' AND p.wants_app_ads = 1)';
    $params = [];
    if ($status !== null) {
        $sql .= ' AND c.status = ?';
        $params[] = $status;
    }
    // Probleme zuerst, dann veraltet, dann aktuell.
    return db_all($sql . " ORDER BY FIELD(c.status, 'error', 'outdated', 'skipped', 'ok'), p.name, c.file_type", $params);
}

/** Zusammenfassung für Übersicht/Navigation. */
function adstxt_check_counts(): array
{
    $counts = ['ok' => 0, 'outdated' => 0, 'error' => 0, 'skipped' => 0];
    foreach (adstxt_checks() as $c) {
        $counts[$c['status']]++;
    }
    return $counts;
}
