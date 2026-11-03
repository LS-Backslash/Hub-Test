<?php
declare(strict_types=1);

/**
 * Gemeinsamer Einstieg für alle Skripte (Web und Cron).
 *
 * Einbinden aus www/<projekt>/index.php:
 *   require dirname(__DIR__, 2) . '/home/lib/bootstrap.php';
 * Einbinden aus home/cron/<job>.php:
 *   require dirname(__DIR__) . '/lib/bootstrap.php';
 */

const APP_HOME = __DIR__ . '/..';
const APP_DATA = APP_HOME . '/data';
const APP_LOGS = APP_HOME . '/logs';
const APP_CONFIG = APP_HOME . '/config';

// Der Server steht auf UTC; die CLI-Konfiguration kann abweichen, daher hier fest setzen.
date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

// Fehler nie anzeigen, immer in eigene Logdatei schreiben.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_LOGS . '/php-error.log');
error_reporting(E_ALL);

require __DIR__ . '/helpers.php';
require __DIR__ . '/storage.php';
require __DIR__ . '/http.php';

$configFile = APP_CONFIG . '/config.php';
$GLOBALS['APP_CONFIG'] = is_file($configFile) ? (require $configFile) : [];

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['APP_CONFIG'][$key] ?? $default;
}
