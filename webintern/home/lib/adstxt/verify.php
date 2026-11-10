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
function adstxt_verify(?int $publisherId = null, ?callable $fetch = null): array
{
    $fetch ??= static fn (array $urls, bool $verifyTls) => fetch_many($urls, ADSTXT_VERIFY_TIMEOUT, ADSTXT_VERIFY_PARALLEL, $verifyTls);
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
    $save = static function (array $t, string $status, ?string $url, ?int $http, string $message, array $c = [], bool $cert = false) use ($now, &$counts): void {
        $counts[$status]++;
        db_run('INSERT INTO adstxt_checks (publisher_id, file_type, checked_at, status, url, http_status, message, cert_problem,
                    missing_count, extra_count, missing, extra, remote_generated, our_changed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at), status = VALUES(status), url = VALUES(url),
                    http_status = VALUES(http_status), message = VALUES(message), cert_problem = VALUES(cert_problem),
                    missing_count = VALUES(missing_count),
                    extra_count = VALUES(extra_count), missing = VALUES(missing), extra = VALUES(extra),
                    remote_generated = VALUES(remote_generated), our_changed_at = VALUES(our_changed_at)', [
            $t['publisher']['id'], $t['type'], $now, $status, $url, $http, mb_substr($message, 0, 500), $cert ? 1 : 0,
            count($c['missing'] ?? []), count($c['extra'] ?? []),
            implode("\n", array_slice($c['missing'] ?? [], 0, 2000)), implode("\n", array_slice($c['extra'] ?? [], 0, 2000)),
            $c['remote_generated'] ?? null, $t['changed_at'],
        ]);
    };

    $domains = [];
    foreach ($targets as $key => $t) {
        if ($t['publisher']['domain'] === '') {
            $save($t, 'skipped', null, null, 'Keine Domain hinterlegt');
        } elseif ($t['content'] === null) {
            $save($t, 'skipped', null, null, 'Bei uns noch keine Datei erzeugt');
        } else {
            $domains[$key] = [$t['publisher']['domain'], ADSTXT_FILE_NAMES[$t['type']]];
        }
    }

    foreach (adstxt_fetch_with_fallback($domains, $fetch) as $key => $r) {
        $t = $targets[$key];
        $e = adstxt_evaluate($r['response'], $t['content']);
        $msg = $e['message'];
        if ($r['response']['ok'] && $r['url'] !== $r['tried'][0]) {
            $msg .= ' – gefunden unter ' . $r['url'] . ', ' . $r['tried'][0] . ' antwortet nicht';
        } elseif ($r['response']['status'] !== 0 && $r['url'] !== $r['tried'][0]) {
            $msg .= ' – geprüft unter ' . $r['url'] . ', da ' . $r['tried'][0] . ' nicht antwortet';
        } elseif ($r['response']['status'] === 0 && count($r['tried']) > 1) {
            $msg .= ' (auch mit www. und http versucht)';
        }
        if ($r['cert_problem']) {
            $msg .= ' – Zertifikat der Website fehlerhaft';
        }
        $save($t, $e['status'], $r['url'], $r['response']['status'] ?: null, $msg, $e, $r['cert_problem']);
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

/**
 * Adressen, unter denen die Datei gesucht wird – in dieser Reihenfolge.
 * Laut ads.txt-Spezifikation liegt die Datei im Stamm der Domain; viele Verlage antworten aber nur mit "www.".
 */
function adstxt_candidate_urls(string $domain, string $file): array
{
    $hosts = [$domain];
    if (!str_starts_with($domain, 'www.')) {
        $hosts[] = 'www.' . $domain;
    }
    $urls = [];
    foreach (['https', 'http'] as $scheme) {
        foreach ($hosts as $host) {
            $urls[] = "{$scheme}://{$host}/{$file}";
        }
    }
    return $urls;
}

/** cURL-Fehler, die auf ein ungültiges oder unvollständiges Zertifikat hindeuten. */
const ADSTXT_CERT_ERRNOS = [51, 60];

/**
 * Ruft für jedes Ziel die erste erreichbare Adresse ab.
 * Bei Zertifikatsfehlern wird dieselbe Adresse ohne Zertifikatsprüfung gelesen (nur Lesen einer öffentlichen
 * Datei, es werden keine Daten gesendet) und das Ergebnis als „Zertifikat fehlerhaft“ markiert.
 *
 * @param array<string, array{0: string, 1: string}> $domains Schlüssel => [Domain, Dateiname]
 * @param callable(array<string,string>, bool): array $fetch
 * @return array<string, array{response: array, url: string, tried: list<string>, cert_problem: bool}>
 */
function adstxt_fetch_with_fallback(array $domains, callable $fetch): array
{
    $state = [];
    foreach ($domains as $key => [$domain, $file]) {
        $state[$key] = ['candidates' => adstxt_candidate_urls($domain, $file), 'i' => 0, 'insecure' => false,
            'cert_problem' => false, 'tried' => [], 'first' => null, 'done' => null];
    }

    for ($round = 0; $round < 8; $round++) {
        $secure = $insecure = [];
        foreach ($state as $key => $st) {
            if ($st['done'] === null) {
                $url = $st['candidates'][$st['i']];
                $st['insecure'] ? $insecure[$key] = $url : $secure[$key] = $url;
            }
        }
        if (!$secure && !$insecure) {
            break;
        }
        $results = ($secure ? $fetch($secure, true) : []) + ($insecure ? $fetch($insecure, false) : []);

        foreach ($results as $key => $res) {
            $st = &$state[$key];
            $url = $st['candidates'][$st['i']];
            if (!in_array($url, $st['tried'], true)) {
                $st['tried'][] = $url;
            }
            $st['first'] ??= $res;
            if ($res['status'] !== 0) {
                $st['done'] = ['response' => $res, 'url' => $url];
            } elseif (!$st['insecure'] && in_array($res['errno'] ?? 0, ADSTXT_CERT_ERRNOS, true)) {
                $st['insecure'] = true;      // gleiche Adresse noch einmal, ohne Zertifikatsprüfung
                $st['cert_problem'] = true;
            } elseif ($st['i'] + 1 < count($st['candidates'])) {
                $st['i']++;
                $st['insecure'] = false;
            } else {
                // Alles fehlgeschlagen: die Meldung der eigentlichen Adresse zeigen.
                $st['done'] = ['response' => $st['first'], 'url' => $st['tried'][0]];
            }
            unset($st);
        }
    }

    $out = [];
    foreach ($state as $key => $st) {
        $done = $st['done'] ?? ['response' => $st['first'] ?? ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Abbruch', 'errno' => 0], 'url' => $st['candidates'][0]];
        $out[$key] = $done + ['tried' => $st['tried'], 'cert_problem' => $st['cert_problem'] && $done['response']['status'] !== 0];
    }
    return $out;
}


/**
 * Formuliert für einen auffälligen Befund einen Hinweis, den man direkt an die IT des Verlags schicken kann.
 *
 * @param array $c Zeile aus adstxt_checks (mit domain, file_type, status, url, message, cert_problem, remote_generated, missing_count, extra_count)
 * @param string|null $liveUrl unsere Abhol-Adresse auf ads.pddigital.de
 * @return array{title: string, text: string}|null
 */
function adstxt_check_hint(array $c, ?string $liveUrl): ?array
{
    $file = ADSTXT_FILE_NAMES[$c['file_type']];
    $domain = (string) $c['domain'];
    if ($domain === '') {
        return null;
    }
    $root = "https://{$domain}/{$file}";
    // Genutzte Adresse ohne Schema und Dateinamen, z. B. "www.suedkurier.de".
    $usedHost = $c['url'] ? preg_replace('#^https?://|/' . preg_quote($file, '#') . '$#', '', (string) $c['url']) : '';
    $usedScheme = $c['url'] ? (string) parse_url($c['url'], PHP_URL_SCHEME) : '';
    $pickup = $liveUrl ? "\nAktuelle Datei zum Abholen: {$liveUrl}" : '';
    $hints = [];

    if (in_array($c['status'], ['ok', 'outdated'], true)) {
        if ($usedHost === "www.{$domain}") {
            $hints[] = ['Domain ohne www antwortet nicht',
                "Die {$file} ist nur unter www.{$domain} erreichbar. Die Adresse {$root} antwortet nicht.\n"
                . "Laut IAB-ads.txt-Standard muss die Datei im Stamm der Domain abrufbar sein – manche Crawler der Werbepartner fragen nur dort.\n"
                . "Bitte dafür sorgen, dass {$root} erreichbar ist, am einfachsten per Weiterleitung (301) von {$domain} auf www.{$domain}."];
        }
        if ($usedScheme === 'http') {
            $hints[] = ['Nur über http erreichbar',
                "Die {$file} ist nur unverschlüsselt über http erreichbar, nicht über https.\n"
                . "Bitte HTTPS für {$usedHost} einrichten (oder http auf https weiterleiten), damit {$root} funktioniert."];
        }
        if (!empty($c['cert_problem'])) {
            $hints[] = ['HTTPS-Zertifikat fehlerhaft',
                "Das HTTPS-Zertifikat von {$usedHost} wird von Prüfprogrammen nicht akzeptiert. Browser gleichen das oft aus, automatische Crawler (z. B. der Werbepartner beim Auslesen der {$file}) jedoch nicht.\n"
                . "Häufigste Ursache: Der Webserver sendet das Zwischenzertifikat (Intermediate) nicht mit. Seltener: Zertifikat abgelaufen oder für den Hostnamen nicht gültig.\n"
                . "Bitte die vollständige Zertifikatskette auf dem Webserver hinterlegen. Prüfen lässt sich das z. B. mit https://www.ssllabs.com/ssltest/analyze.html?d={$usedHost}"];
        }
        if ($c['status'] === 'outdated') {
            $stand = $c['remote_generated'] ? "Auf der Website liegt der Stand vom {$c['remote_generated']}." : 'Die Datei auf der Website wurde nicht aus unserem System erzeugt oder wurde verändert.';
            $hints[] = ['Datei nicht aktuell',
                "Die {$file} auf {$domain} entspricht nicht dem aktuellen Stand ({$c['missing_count']} Zeile(n) fehlen, {$c['extra_count']} Zeile(n) zu viel). {$stand}\n"
                . "Bitte prüfen, ob der automatische Abruf (Cronjob) läuft und die Datei unverändert übernommen wird.{$pickup}"];
        }
    } elseif ($c['status'] === 'error') {
        $msg = (string) $c['message'];
        if (str_contains($msg, '404')) {
            $hints[] = ['Datei fehlt',
                "Unter {$root} ist keine {$file} vorhanden (Fehler 404).\n"
                . "Bitte die Datei regelmäßig (z. B. per Cronjob alle 12 Stunden) von unserer Adresse abholen und unter /{$file} im Stamm der Domain ablegen.{$pickup}"];
        } elseif (str_contains($msg, 'Webseite')) {
            $hints[] = ['Webseite statt Textdatei',
                "Unter {$root} wird eine Webseite (HTML, z. B. Fehler- oder Startseite) ausgeliefert statt der {$file}.\n"
                . "Bitte sicherstellen, dass /{$file} als reine Textdatei (Content-Type text/plain) ausgeliefert wird.{$pickup}"];
        } elseif (preg_match('/resolve|Name or service/i', $msg)) {
            $hints[] = ['Domain nicht auflösbar',
                "Die Domain {$domain} ist im DNS nicht auflösbar. Bitte im Tool prüfen, ob die Domain richtig geschrieben ist."];
        } else {
            $hints[] = ['Website antwortet nicht',
                "Die Website {$domain} antwortet nicht auf Abrufe von {$root} (auch mit www. und über http versucht).\n"
                . "Mögliche Ursachen: Firewall oder Bot-Schutz blockiert automatische Abrufe, oder der Webserver ist für diese Adresse nicht eingerichtet.\n"
                . "Bitte prüfen, ob {$root} von außen ohne Browser (z. B. mit curl) abrufbar ist.{$pickup}"];
        }
    }

    if (!$hints) {
        return null;
    }
    return [
        'title' => implode(' · ', array_column($hints, 0)),
        'text' => "Hinweis zur {$file} von {$domain}:\n\n" . implode("\n\n", array_column($hints, 1)),
    ];
}
