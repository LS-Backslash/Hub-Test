<?php
declare(strict_types=1);

/**
 * Cronjob: holt fehlgeschlagene Uploads zum Live-Server nach (ePaper-Creatives und ads.txt-Dateien).
 * Tut nichts und baut keine Verbindung auf, wenn nichts aussteht.
 *
 * Crontab (Benutzer ads, alle 15 Minuten):
 *   *\/15 * * * * /usr/bin/php8.4 /data/wwwcust/ads/home/cron/live-sync.php >> /data/wwwcust/ads/home/logs/cron.log 2>&1
 *   (ohne den Backslash vor dem Schrägstrich)
 *
 * Exit-Code: 0 = ok/nichts zu tun, 1 = mindestens ein Upload fehlgeschlagen.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';
require APP_HOME . '/lib/epaper.php';
require APP_HOME . '/lib/adstxt/service.php';

if (!live_enabled()) {
    exit(0);
}
$lock = acquire_lock('live-sync');
if ($lock === null) {
    exit(0); // läuft schon
}

[$epOk, $epFailed] = epaper_sync();

// ads.txt nur hochladen, wenn gerade keine Aktualisierung läuft (die lädt am Ende selbst hoch).
$adstxt = ['uploaded' => 0, 'deleted' => 0, 'failed' => 0];
$runLock = acquire_lock('adstxt-run');
if ($runLock !== null) {
    $adstxt = adstxt_sync();
    flock($runLock, LOCK_UN);
}

if ($epOk + $epFailed + $adstxt['uploaded'] + $adstxt['deleted'] + $adstxt['failed'] > 0) {
    app_log('live', sprintf('Nachgeholt – ePaper: %d ok, %d Fehler · ads.txt: %d hochgeladen, %d gelöscht, %d Fehler',
        $epOk, $epFailed, $adstxt['uploaded'], $adstxt['deleted'], $adstxt['failed']));
}
exit($epFailed + $adstxt['failed'] > 0 ? 1 : 0);
