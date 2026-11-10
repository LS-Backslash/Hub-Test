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
require __DIR__ . '/db.php';
require __DIR__ . '/live.php';

$configFile = APP_CONFIG . '/config.php';
$GLOBALS['APP_CONFIG'] = is_file($configFile) ? (require $configFile) : [];

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['APP_CONFIG'][$key] ?? $default;
}

// Unerwartete Fehler: ins Log schreiben, im Browser eine verständliche Meldung zeigen.
set_exception_handler(static function (Throwable $e): void {
    error_log('Unbehandelter Fehler: ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'FEHLER: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $isDb = $e instanceof PDOException || str_contains($e->getMessage(), 'Datenbank');
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:15vh auto;padding:0 16px;color:#3C3C3B">'
        . '<h1 style="font-size:22px;color:#000">Es ist ein Fehler aufgetreten.</h1>'
        . '<p>' . ($isDb ? 'Die Datenbank ist nicht erreichbar oder nicht konfiguriert.' : 'Die Seite konnte nicht geladen werden.')
        . ' Details stehen in <code>home/logs/php-error.log</code>.</p></body>';
});
