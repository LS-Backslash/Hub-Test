<?php
declare(strict_types=1);

/**
 * Cronjob: externe Quellen abrufen und alle ads.txt/app-ads.txt neu erzeugen.
 *
 * Crontab (Benutzer ads, alle 12 Stunden um 05:17 und 17:17):
 *   17 5,17 * * * /usr/bin/php8.4 /data/wwwcust/ads/home/cron/adstxt-update.php >> /data/wwwcust/ads/home/logs/cron.log 2>&1
 *
 * Exit-Code: 0 = ok, 1 = Warnungen (z. B. eine Quelle nicht erreichbar), 2 = Fehler.
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}
require dirname(__DIR__) . '/lib/bootstrap.php';
require APP_HOME . '/lib/adstxt/service.php';

try {
    $result = adstxt_run('cron');
} catch (Throwable $e) {
    app_log('adstxt', 'Cronjob nicht gestartet: ' . $e->getMessage());
    exit(2);
}

if ($result['changed']) {
    app_log('adstxt', 'Geändert: ' . implode(', ', $result['changed']));
}
exit(['ok' => 0, 'warning' => 1][$result['status']] ?? 2);
