<?php
declare(strict_types=1);

/**
 * Cronjob: Abgleich – prüft bei allen aktiven Publishern, ob ihre ads.txt/app-ads.txt unserem Stand entspricht.
 *
 * Crontab (Benutzer ads, täglich um 10:43):
 *   43 10 * * * /usr/bin/php8.4 /data/wwwcust/ads/home/cron/adstxt-check.php >> /data/wwwcust/ads/home/logs/cron.log 2>&1
 *
 * Exit-Code: 0 = alle aktuell, 1 = mindestens ein Verlag veraltet oder nicht erreichbar.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';
require APP_HOME . '/lib/adstxt/service.php';
require APP_HOME . '/lib/adstxt/verify.php';

$lock = acquire_lock('adstxt-check');
if ($lock === null) {
    exit(0);
}
$c = adstxt_verify();
exit($c['outdated'] + $c['error'] > 0 ? 1 : 0);
