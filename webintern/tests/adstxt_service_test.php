<?php
declare(strict_types=1);

/**
 * Integrationstest Ads.txt-Service gegen eine TEST-Datenbank.
 * Erwartet: lokaler Webserver mit tests/fixtures unter $argv[1] (z. B. http://127.0.0.1:8099).
 * Achtung: leert alle adstxt_*-Tabellen! Nie gegen die echte Datenbank laufen lassen.
 *
 * Aufruf: php webintern/tests/adstxt_service_test.php http://127.0.0.1:8099
 */

require __DIR__ . '/../home/lib/bootstrap.php';
require APP_HOME . '/lib/adstxt/service.php';

if (getenv('ADSTXT_TEST_DB') !== '1') {
    exit("Sicherheitsstopp: nur mit ADSTXT_TEST_DB=1 ausführen (leert die adstxt-Tabellen).\n");
}
$base = rtrim($argv[1] ?? '', '/');
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

db();
db()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['adstxt_changes', 'adstxt_outputs', 'adstxt_source_cache', 'adstxt_sources', 'adstxt_blocks', 'adstxt_runs', 'adstxt_publishers'] as $t) {
    db()->exec("TRUNCATE {$t}");
}
db()->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "Publisher\n";
[$sk, $e] = adstxt_publisher_save(['name' => 'Südkurier', 'domain' => 'https://www.suedkurier.de/', 'active' => 1, 'wants_ads' => 1, 'wants_app_ads' => 1, 'header_lines' => "OWNERDOMAIN=suedkurier.de\nCONTACT=ads@suedkurier.de"]);
check('angelegt', $e, []);
check('Domain bereinigt', adstxt_publisher($sk)['domain'], 'www.suedkurier.de');
check('Kürzel', adstxt_publisher($sk)['slug'], 'suedkurier');
[$sk2] = adstxt_publisher_save(['name' => 'Südkurier', 'wants_ads' => 1, 'active' => 1]);
check('Kürzel eindeutig', adstxt_publisher($sk2)['slug'], 'suedkurier-2');
adstxt_publisher_set_active($sk2, false);
[, $e] = adstxt_publisher_save(['name' => 'X', 'wants_ads' => 0, 'wants_app_ads' => 0]);
check('ohne Datei abgelehnt', isset($e['files']), true);
[, $e] = adstxt_publisher_save(['name' => 'X', 'wants_ads' => 1, 'header_lines' => 'google.com, pub-1, DIRECT']);
check('Datensatz in Kopfzeilen abgelehnt', isset($e['header_lines']), true);
[$ka] = adstxt_publisher_save(['name' => 'ka-news', 'domain' => 'ka-news.de', 'active' => 1, 'wants_ads' => 1]);

echo "\nQuellen und Einträge\n";
[$gs] = adstxt_source_save(['name' => 'Dienstleister global', 'url' => "{$base}/global.txt", 'file_type' => 'both', 'active' => 1]);
[$ps] = adstxt_source_save(['name' => 'Vermarkter Süd', 'url' => "{$base}/sued.txt", 'file_type' => 'ads', 'publisher_id' => $sk, 'active' => 1]);
[$bad] = adstxt_source_save(['name' => 'Kaputte Quelle', 'url' => "{$base}/gibtsnicht.txt", 'file_type' => 'ads', 'active' => 1]);
[, $e] = adstxt_source_save(['name' => 'X', 'url' => 'ftp://x', 'file_type' => 'ads']);
check('nur http(s)', isset($e['url']), true);
[$gb, $e, $an] = adstxt_block_save(['name' => 'Google', 'file_type' => 'ads', 'active' => 1, 'content' => "google.com, pub-111, DIRECT, f08c47fec0942fa0\nkaputte zeile"]);
check('Block gespeichert', $e, []);
check('Block-Analyse', [$an['records'], count($an['invalid'])], [1, 1]);
[$ab] = adstxt_block_save(['name' => 'App-Partner', 'file_type' => 'app-ads', 'active' => 1, 'publisher_id' => $sk, 'content' => 'applovin.com, abc, DIRECT']);

echo "\nLauf 1\n";
$r = adstxt_run('manual');
check('Status Warnung (eine Quelle kaputt)', $r['status'], 'warning');
check('Quellen ok/Warnung/Fehler', $r['sources'], ['ok' => 2, 'warning' => 0, 'error' => 1]);
check('3 Dateien (SK ads+app-ads, ka ads)', $r['files'], 3);
check('alle 3 neu', count($r['changed']), 3);

$skAds = adstxt_output($sk, 'ads');
check('SK ads.txt: OWNERDOMAIN vom Publisher', str_contains($skAds['content'], "OWNERDOMAIN=suedkurier.de\n"), true);
check('SK ads.txt: fremde OWNERDOMAIN verworfen', str_contains($skAds['content'], 'OWNERDOMAIN=dienstleister.de'), false);
check('SK ads.txt: Datensätze ohne Dubletten', $skAds['record_count'], 5);
check('SK ads.txt: kein App-Eintrag', str_contains($skAds['content'], 'applovin'), false);
$skApp = adstxt_output($sk, 'app-ads');
check('SK app-ads.txt: App-Eintrag', str_contains($skApp['content'], 'applovin.com, abc, DIRECT'), true);
check('SK app-ads.txt: globale Quelle (beide)', str_contains($skApp['content'], 'rubiconproject.com'), true);
check('SK app-ads.txt: kein Google-ads-Block', str_contains($skApp['content'], '# --- Eintrag: Google ---'), false);
$kaAds = adstxt_output($ka, 'ads');
check('ka ads.txt: OWNERDOMAIN aus globaler Quelle', str_contains($kaAds['content'], 'OWNERDOMAIN=dienstleister.de'), true);
check('ka ads.txt: keine Südkurier-Quelle', str_contains($kaAds['content'], 'openx.com'), false);
check('inaktiver Publisher ohne Datei', adstxt_output($sk2, 'ads'), null);
check('Status kaputte Quelle', adstxt_source($bad)['last_status'], 'error');

echo "\nLauf 2 (nichts geändert)\n";
$r = adstxt_run('manual');
check('keine Änderungen', $r['changed'], []);
check('Änderungsprotokoll: 3 Einträge', (int) db_value('SELECT COUNT(*) FROM adstxt_changes'), 3);

echo "\nLauf 3 (Quelle bricht ein → letzter Stand bleibt)\n";
db_run('UPDATE adstxt_sources SET url = ? WHERE id = ?', ["{$base}/global-kaputt.html", $gs]);
$r = adstxt_run('cron');
check('keine Änderung trotz HTML-Antwort', $r['changed'], []);
check('Quelle als Fehler markiert', adstxt_source($gs)['last_status'], 'error');
check('Meldung nennt Weiterverwendung', str_contains(adstxt_source($gs)['last_message'], 'letzter Stand'), true);
check('Datei unverändert', adstxt_output($ka, 'ads')['body_hash'], $kaAds['body_hash']);

echo "\nLauf 4 (Eintrag geändert → Protokoll)\n";
adstxt_block_save(['name' => 'Google', 'file_type' => 'ads', 'active' => 1, 'content' => "google.com, pub-111, DIRECT, f08c47fec0942fa0\ngoogle.com, pub-222, RESELLER, f08c47fec0942fa0"], $gb);
$r = adstxt_run('manual');
check('2 Dateien geändert (SK + ka ads.txt)', count($r['changed']), 2);
$c = db_one('SELECT * FROM adstxt_changes WHERE publisher_id = ? ORDER BY id DESC LIMIT 1', [$ka]);
check('Protokoll: hinzugefügt', $c['added'], 'google.com, pub-222, RESELLER, f08c47fec0942fa0');
check('Protokoll: nichts entfernt', (int) $c['removed_count'], 0);

echo "\nParallelschutz\n";
$lock = acquire_lock('adstxt-run');
try {
    adstxt_run('manual');
    check('zweiter Lauf blockiert', false, true);
} catch (RuntimeException $e) {
    check('zweiter Lauf blockiert', str_contains($e->getMessage(), 'bereits'), true);
}
flock($lock, LOCK_UN);

echo "\n" . ($failed === 0 ? "Alle {$count} Tests bestanden.\n" : "{$failed} von {$count} Tests fehlgeschlagen.\n");
exit($failed === 0 ? 0 : 1);
