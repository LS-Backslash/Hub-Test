<?php
declare(strict_types=1);

/**
 * Integrationstest Live-Upload (ePaper + Ads.txt) gegen TEST-Datenbank und TEST-SFTP-Server.
 * Erwartet in config.php einen 'live'-Eintrag auf einen lokalen SFTP-Server, dessen Zielordner
 * auch lokal unter $argv[1] lesbar ist (z. B. /var/www/html/ads/www).
 * Achtung: leert adstxt_*- und epaper_creatives-Tabellen!
 *
 * Aufruf: ADSTXT_TEST_DB=1 php webintern/tests/live_sync_test.php /var/www/html/ads/www
 */

require __DIR__ . '/../home/lib/bootstrap.php';
require APP_HOME . '/lib/epaper.php';
require APP_HOME . '/lib/adstxt/service.php';

if (getenv('ADSTXT_TEST_DB') !== '1') {
    exit("Sicherheitsstopp: nur mit ADSTXT_TEST_DB=1 ausführen.\n");
}
$remoteRoot = rtrim($argv[1] ?? '', '/');
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
foreach (['adstxt_changes', 'adstxt_outputs', 'adstxt_source_cache', 'adstxt_sources', 'adstxt_blocks', 'adstxt_runs', 'adstxt_publishers', 'epaper_creatives'] as $t) {
    db()->exec("TRUNCATE {$t}");
}
db()->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "Verbindung\n";
check('live_enabled', live_enabled(), true);
check('live_test', live_test()['ok'], true);
check('URL', live_url('adstxt/suedkurier/ads.txt'), 'https://ads.pddigital.de/adstxt/suedkurier/ads.txt');
try {
    live_put('../ausbruch.txt', 'x');
    check('Pfad mit .. abgelehnt', false, true);
} catch (LiveError) {
    check('Pfad mit .. abgelehnt', true, true);
}

echo "\nAds.txt\n";
[$sk] = adstxt_publisher_save(['name' => 'Südkurier', 'domain' => 'suedkurier.de', 'active' => 1, 'wants_ads' => 1, 'header_lines' => 'OWNERDOMAIN=suedkurier.de']);
[$bl] = adstxt_block_save(['name' => 'Google', 'file_type' => 'both', 'active' => 1, 'content' => 'google.com, pub-111, DIRECT, f08c47fec0942fa0']);
$file = "{$remoteRoot}/adstxt/suedkurier/ads.txt";

$r = adstxt_run('manual');
check('Lauf 1: 1 hochgeladen', $r['live']['uploaded'] ?? null, 1);
check('Datei liegt auf dem Live-Server', is_file($file), true);
check('Inhalt identisch', @file_get_contents($file), adstxt_output($sk, 'ads')['content']);
check('Status ok', adstxt_live_status(adstxt_publisher($sk), adstxt_output($sk, 'ads'), 'ads')['status'], 'ok');
check('keine .tmp-Reste', glob("{$remoteRoot}/adstxt/suedkurier/*.tmp-*"), []);

$r = adstxt_run('cron');
check('Lauf 2: nichts hochgeladen', $r['live']['uploaded'], 0);

adstxt_block_save(['name' => 'Google', 'file_type' => 'both', 'active' => 1, 'content' => "google.com, pub-111, DIRECT, f08c47fec0942fa0\ngoogle.com, pub-222, RESELLER"], $bl);
$r = adstxt_run('manual');
check('Lauf 3: Änderung hochgeladen', $r['live']['uploaded'], 1);
check('neuer Inhalt live', str_contains((string) @file_get_contents($file), 'pub-222'), true);

adstxt_publisher_save(['name' => 'Südkurier', 'domain' => 'suedkurier.de', 'active' => 1, 'wants_ads' => 1, 'wants_app_ads' => 1, 'header_lines' => 'OWNERDOMAIN=suedkurier.de'], $sk);
$r = adstxt_run('manual');
check('app-ads.txt dazu', [$r['live']['uploaded'], is_file("{$remoteRoot}/adstxt/suedkurier/app-ads.txt")], [1, true]);

adstxt_publisher_save(['name' => 'Südkurier', 'domain' => 'suedkurier.de', 'active' => 1, 'wants_ads' => 1, 'wants_app_ads' => 0, 'header_lines' => 'OWNERDOMAIN=suedkurier.de'], $sk);
$r = adstxt_run('manual');
check('app-ads.txt abgewählt → gelöscht', [$r['live']['deleted'], is_file("{$remoteRoot}/adstxt/suedkurier/app-ads.txt")], [1, false]);
check('ads.txt bleibt', is_file($file), true);

adstxt_publisher_set_active($sk, false);
$r = adstxt_run('manual');
check('deaktiviert → gelöscht', [$r['live']['deleted'], is_file($file)], [1, false]);
check('leerer Ordner entfernt', is_dir("{$remoteRoot}/adstxt/suedkurier"), false);

adstxt_publisher_set_active($sk, true);
echo "\nAusfall des Live-Servers\n";
$realPass = $GLOBALS['APP_CONFIG']['live']['pass'];
$GLOBALS['APP_CONFIG']['live']['pass'] = 'falsch';
$r = adstxt_run('manual');
check('Upload schlägt fehl', $r['live']['failed'], 1);
check('Lauf-Status Warnung', $r['status'], 'warning');
check('Fehlermeldung verständlich', str_contains($r['live']['errors'][0] ?? '', 'Anmeldung am Live-Server abgelehnt'), true);
check('Status in der Anzeige: Fehler', adstxt_live_status(adstxt_publisher($sk), adstxt_output($sk, 'ads'), 'ads')['status'], 'error');
$GLOBALS['APP_CONFIG']['live']['pass'] = $realPass;
$s = adstxt_sync(); // das macht der Cronjob live-sync.php
check('Nachholen klappt', [$s['uploaded'], is_file($file)], [1, true]);
check('Fehler zurückgesetzt', adstxt_output($sk, 'ads')['upload_error'], null);

echo "\nePaper\n";
$id = 'suedkurier/2026-11-20_test-kunde';
$local = EPAPER_CREATIVES_DIR . '/' . $id;
@mkdir($local, 0775, true);
$img = imagecreatetruecolor(16, 9);
imagejpeg($img, "{$local}/quer.jpg");
imagejpeg(imagecreatetruecolor(9, 16), "{$local}/hoch.jpg");
file_put_contents("{$local}/index.html", '<html>test</html>');
$now = db_now();
db_run('INSERT INTO epaper_creatives (publisher, folder, customer, pub_date, link, landscape_file, landscape_width, landscape_height,
        portrait_file, portrait_width, portrait_height, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 16, 9, ?, 9, 16, ?, ?)',
    ['suedkurier', '2026-11-20_test-kunde', 'Test Kunde', '2026-11-20', 'https://beispiel.de', 'quer.jpg', 'hoch.jpg', $now, $now]);

$GLOBALS['APP_CONFIG']['live']['pass'] = 'falsch';
check('Upload bei Ausfall: Fehler', epaper_publish($id) !== null, true);
check('Fehler gespeichert', epaper_get($id)['upload_error'] !== null, true);
check('keine Live-URL', epaper_get($id)['live_url'], null);
$GLOBALS['APP_CONFIG']['live']['pass'] = $realPass;
check('Nachholen per Cron', epaper_sync(), [1, 0]);
$item = epaper_get($id);
check('Live-URL gesetzt', $item['live_url'], 'https://ads.pddigital.de/epaper/suedkurier/2026-11-20_test-kunde/index.html');
check('Fehler zurückgesetzt', $item['upload_error'], null);
check('Dateien live', [is_file("{$remoteRoot}/epaper/{$id}/index.html"), is_file("{$remoteRoot}/epaper/{$id}/quer.jpg"), is_file("{$remoteRoot}/epaper/{$id}/hoch.jpg")], [true, true, true]);
check('Bild unverändert', md5_file("{$remoteRoot}/epaper/{$id}/quer.jpg"), md5_file("{$local}/quer.jpg"));
check('nichts mehr ausstehend', epaper_sync(), [0, 0]);

// Aufräumen lokal
epaper_remove_dir(EPAPER_CREATIVES_DIR . '/suedkurier');
db()->exec('TRUNCATE epaper_creatives');

echo "\n" . ($failed === 0 ? "Alle {$count} Tests bestanden.\n" : "{$failed} von {$count} Tests fehlgeschlagen.\n");
exit($failed === 0 ? 0 : 1);
