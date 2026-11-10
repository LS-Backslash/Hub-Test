<?php
declare(strict_types=1);

/**
 * Tests für den Abgleich (Ampel). Teil 1 ohne DB, Teil 2 gegen TEST-Datenbank + lokalen Webserver.
 * Aufruf: ADSTXT_TEST_DB=1 php webintern/tests/adstxt_verify_test.php <docroot-des-testservers> <host:port>
 * Achtung: leert die adstxt-Tabellen!
 */

require __DIR__ . '/../home/lib/bootstrap.php';
require APP_HOME . '/lib/adstxt/service.php';
require APP_HOME . '/lib/adstxt/verify.php';

$failed = 0;
$count = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failed, $count;
    $count++;
    if ($actual === $expected) {
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}\n       erwartet: " . var_export($expected, true) . "\n       erhalten: " . var_export($actual, true) . "\n";
}
$ok = static fn (string $b, int $s = 200) => ['ok' => $s === 200, 'status' => $s, 'body' => $b, 'error' => $s === 200 ? null : "HTTP {$s}"];

echo "Vergleich (ohne Datenbank)\n";
$ours = "# ads.txt für X\n# Automatisch erzeugt am 10.11.2026 14:11 – bitte nicht von Hand bearbeiten.\nOWNERDOMAIN=x.de\n\n# --- Quelle ---\ngoogle.com, pub-1, DIRECT, f08c47fec0942fa0\nrubicon.com, 9, RESELLER\n";
$same = "rubicon.com,9,reseller\r\n# anderer Kommentar\nGOOGLE.com , pub-1 , direct , f08c47fec0942fa0\nownerdomain=X.de\n";
check('gleich trotz Reihenfolge/Schreibweise', adstxt_evaluate($ok($same), $ours)['status'], 'ok');
$e = adstxt_evaluate($ok("google.com, pub-1, DIRECT, f08c47fec0942fa0\nOWNERDOMAIN=x.de\nalt.com, 5, DIRECT\n"), $ours);
check('veraltet', $e['status'], 'outdated');
check('fehlende Zeile erkannt', $e['missing'], ['rubicon.com, 9, RESELLER']);
check('überzählige Zeile erkannt', $e['extra'], ['alt.com, 5, DIRECT']);
check('Meldung', $e['message'], '1 Zeile(n) fehlen, 1 Zeile(n) zu viel');
check('Stand beim Verlag aus Kopfzeile', adstxt_evaluate($ok("# Automatisch erzeugt am 01.11.2026 05:17 – x\ngoogle.com, pub-1, DIRECT"), $ours)['remote_generated'], '01.11.2026 05:17');
check('404 = Problem', adstxt_evaluate($ok('', 404), $ours)['message'], 'Datei nicht gefunden (404)');
check('Fehlerseite = Problem', adstxt_evaluate($ok('<!DOCTYPE html><title>Seite nicht gefunden</title>'), $ours)['status'], 'error');

echo "\nAusweichadressen und Zertifikate (simulierter Abruf)\n";
$web = [
    // apex antwortet nicht, www schon (wie suedkurier.de)
    'https://apex.example/ads.txt' => ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Timeout was reached', 'errno' => 28],
    'https://www.apex.example/ads.txt' => ['ok' => true, 'status' => 200, 'body' => 'google.com, pub-1, DIRECT', 'error' => null, 'errno' => 0],
    // Zertifikat fehlerhaft: sicher → Fehler 60, ohne Prüfung → Datei
    'https://cert.example/ads.txt' => ['secure' => ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'SSL peer certificate or SSH remote key was not OK', 'errno' => 60],
                                       'insecure' => ['ok' => true, 'status' => 200, 'body' => 'google.com, pub-1, DIRECT', 'error' => null, 'errno' => 0]],
];
$calls = [];
$fake = static function (array $urls, bool $verify) use ($web, &$calls): array {
    $out = [];
    foreach ($urls as $k => $u) {
        $calls[] = ($verify ? '' : 'ohne-Prüfung ') . $u;
        $r = $web[$u] ?? ['ok' => false, 'status' => 0, 'body' => '', 'error' => "Couldn't connect to server", 'errno' => 7];
        $out[$k] = isset($r['secure']) ? ($verify ? $r['secure'] : $r['insecure']) : $r;
    }
    return $out;
};
check('Kandidaten ohne www', adstxt_candidate_urls('x.de', 'ads.txt'), ['https://x.de/ads.txt', 'https://www.x.de/ads.txt', 'http://x.de/ads.txt', 'http://www.x.de/ads.txt']);
check('Kandidaten mit www', adstxt_candidate_urls('www.x.de', 'ads.txt'), ['https://www.x.de/ads.txt', 'http://www.x.de/ads.txt']);
$r = adstxt_fetch_with_fallback(['a' => ['apex.example', 'ads.txt'], 'c' => ['cert.example', 'ads.txt'], 'tot' => ['tot.example', 'ads.txt']], $fake);
check('apex tot → www gefunden', [$r['a']['response']['status'], $r['a']['url']], [200, 'https://www.apex.example/ads.txt']);
check('apex: keine Zertifikatswarnung', $r['a']['cert_problem'], false);
check('Zertifikat: Datei trotzdem gelesen', [$r['c']['response']['status'], $r['c']['url']], [200, 'https://cert.example/ads.txt']);
check('Zertifikat: als Problem markiert', $r['c']['cert_problem'], true);
check('Zertifikat: nur diese Adresse ohne Prüfung', array_values(array_filter($calls, fn ($c) => str_starts_with($c, 'ohne-Prüfung'))), ['ohne-Prüfung https://cert.example/ads.txt']);
check('alles tot: 4 Adressen versucht', count($r['tot']['tried']), 4);
check('alles tot: Meldung der Hauptadresse', [$r['tot']['url'], $r['tot']['response']['errno']], ['https://tot.example/ads.txt', 7]);

echo "\nTipps für die IT des Verlags\n";
$base = ['domain' => 'suedkurier.de', 'file_type' => 'ads', 'status' => 'ok', 'url' => 'https://suedkurier.de/ads.txt', 'message' => '',
         'cert_problem' => 0, 'remote_generated' => null, 'missing_count' => 0, 'extra_count' => 0];
$live = 'https://ads.pddigital.de/adstxt/suedkurier/ads.txt';
check('alles gut: kein Tipp', adstxt_check_hint($base, $live), null);
$h = adstxt_check_hint(['url' => 'https://www.suedkurier.de/ads.txt'] + $base, $live);
check('nur mit www: Titel', $h['title'], 'Domain ohne www antwortet nicht');
check('nur mit www: Weiterleitung empfohlen', str_contains($h['text'], 'Weiterleitung (301) von suedkurier.de auf www.suedkurier.de'), true);
$h = adstxt_check_hint(['cert_problem' => 1, 'url' => 'https://www.az.de/ads.txt', 'domain' => 'www.az.de'] + $base, $live);
check('Zertifikat: Titel', $h['title'], 'HTTPS-Zertifikat fehlerhaft');
check('Zertifikat: Prüf-Link', str_contains($h['text'], 'ssllabs.com/ssltest/analyze.html?d=www.az.de'), true);
$h = adstxt_check_hint(['url' => 'http://suedkurier.de/ads.txt'] + $base, $live);
check('nur http', $h['title'], 'Nur über http erreichbar');
$h = adstxt_check_hint(['status' => 'outdated', 'missing_count' => 2, 'remote_generated' => '01.11.2026 05:17'] + $base, $live);
check('veraltet: Stand und Abhol-URL', [str_contains($h['text'], 'Stand vom 01.11.2026 05:17'), str_contains($h['text'], $live)], [true, true]);
$h = adstxt_check_hint(['status' => 'error', 'message' => 'Datei nicht gefunden (404)', 'url' => null] + $base, $live);
check('404: Abholen erklären', [$h['title'], str_contains($h['text'], $live)], ['Datei fehlt', true]);
$h = adstxt_check_hint(['status' => 'error', 'message' => 'Nicht abrufbar: Timeout was reached (auch mit www. und http versucht)'] + $base, $live);
check('antwortet nicht', $h['title'], 'Website antwortet nicht');
check('mehrere Befunde kombiniert', adstxt_check_hint(['url' => 'https://www.suedkurier.de/ads.txt', 'cert_problem' => 1, 'status' => 'outdated'] + $base, $live)['title'],
    'Domain ohne www antwortet nicht · HTTPS-Zertifikat fehlerhaft · Datei nicht aktuell');

if (getenv('ADSTXT_TEST_DB') !== '1') {
    echo "\n(Teil 2 übersprungen – ADSTXT_TEST_DB=1 fehlt)\n";
    exit($failed ? 1 : 0);
}
$docroot = rtrim($argv[1] ?? '', '/');
$host = $argv[2] ?? '127.0.0.1:8098';

echo "\nAbgleich über HTTP (mit Datenbank)\n";
db();
db()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['adstxt_checks', 'adstxt_changes', 'adstxt_outputs', 'adstxt_source_cache', 'adstxt_sources', 'adstxt_blocks', 'adstxt_runs', 'adstxt_publishers'] as $t) {
    db()->exec("TRUNCATE {$t}");
}
db()->exec('SET FOREIGN_KEY_CHECKS = 1');

$ids = [];
foreach (['aktuell', 'veraltet', 'fehlt', 'fehlerseite', 'ohnedomain'] as $name) {
    [$ids[$name]] = adstxt_publisher_save(['name' => "Verlag {$name}", 'active' => 1, 'wants_ads' => 1, 'wants_app_ads' => $name === 'aktuell' ? 1 : 0]);
    if ($name !== 'ohnedomain') {
        // Test-"Domain" = Pfad auf dem lokalen Testserver (https schlägt fehl → http-Rückfall wird mitgetestet).
        db_run('UPDATE adstxt_publishers SET domain = ? WHERE id = ?', ["{$host}/{$name}", $ids[$name]]);
    }
}
adstxt_block_save(['name' => 'Google', 'file_type' => 'ads', 'active' => 1, 'content' => "google.com, pub-1, DIRECT, f08c47fec0942fa0\nrubicon.com, 9, RESELLER"]);
adstxt_run('manual');

// "Websites" der Verlage anlegen
foreach (['aktuell', 'veraltet', 'fehlt', 'fehlerseite'] as $n) {
    @mkdir("{$docroot}/{$n}", 0775, true);
    @unlink("{$docroot}/{$n}/ads.txt");
}
$current = adstxt_output($ids['aktuell'], 'ads')['content'];
file_put_contents("{$docroot}/aktuell/ads.txt", implode("\n", array_reverse(explode("\n", $current))));
file_put_contents("{$docroot}/veraltet/ads.txt", "# Automatisch erzeugt am 01.11.2026 05:17 – bitte nicht von Hand bearbeiten.\ngoogle.com, pub-1, DIRECT, f08c47fec0942fa0\nalt.com, 5, DIRECT\n");
file_put_contents("{$docroot}/fehlerseite/ads.txt", "<!DOCTYPE html><html><body>Wartung</body></html>");
// app-ads.txt von "aktuell" fehlt absichtlich → Problem

$c = adstxt_verify();
check('Zählung', $c, ['ok' => 1, 'outdated' => 1, 'error' => 3, 'skipped' => 1]);
$get = static fn (string $n, string $t = 'ads') => db_one('SELECT * FROM adstxt_checks WHERE publisher_id = ? AND file_type = ?', [$ids[$n], $t]);
check('aktuell: grün', $get('aktuell')['status'], 'ok');
check('aktuell: http-Rückfall genutzt', str_starts_with((string) $get('aktuell')['url'], 'http://'), true);
check('aktuell app-ads.txt fehlt: rot', [$get('aktuell', 'app-ads')['status'], str_starts_with($get('aktuell', 'app-ads')['message'], 'Datei nicht gefunden (404) – geprüft unter http://')], ['error', true]);
check('veraltet: gelb', $get('veraltet')['status'], 'outdated');
check('veraltet: Stand beim Verlag', $get('veraltet')['remote_generated'], '01.11.2026 05:17');
check('veraltet: fehlende Zeile', $get('veraltet')['missing'], 'rubicon.com, 9, RESELLER');
check('veraltet: überzählige Zeile', $get('veraltet')['extra'], 'alt.com, 5, DIRECT');
check('fehlt: rot', $get('fehlt')['status'], 'error');
check('Fehlerseite: rot', $get('fehlerseite')['status'], 'error');
check('ohne Domain: grau', [$get('ohnedomain')['status'], $get('ohnedomain')['message']], ['skipped', 'Keine Domain hinterlegt']);

echo "\nVerlag aktualisiert → wieder prüfen\n";
copy("{$docroot}/aktuell/ads.txt", "{$docroot}/veraltet/ads.txt");
$c = adstxt_verify($ids['veraltet']);
check('Einzelprüfung: jetzt grün', [$c['ok'], $get('veraltet')['status']], [1, 'ok']);
check('Übersichtszähler', adstxt_check_counts(), ['ok' => 2, 'outdated' => 0, 'error' => 3, 'skipped' => 1]);

adstxt_publisher_set_active($ids['fehlt'], false);
check('deaktivierte Publisher fallen aus der Ampel', adstxt_check_counts()['error'], 2);

echo "\n" . ($failed === 0 ? "Alle {$count} Tests bestanden.\n" : "{$failed} von {$count} Tests fehlgeschlagen.\n");
exit($failed === 0 ? 0 : 1);
