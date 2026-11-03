<?php
declare(strict_types=1);

/** HTML-Escaping für Ausgaben. */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Einfaches Anwendungslog (eine Datei pro Kanal, z. B. "adstxt"). */
function app_log(string $channel, string $message): void
{
    $channel = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?: 'app';
    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    file_put_contents(APP_LOGS . "/{$channel}.log", $line, FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $line);
    }
}

/** Datum deutsch formatieren (ohne intl-Extension, die auf dem Server fehlt). */
function date_de(int|\DateTimeInterface $time, bool $withTime = true): string
{
    $ts = $time instanceof \DateTimeInterface ? $time->getTimestamp() : $time;
    return date($withTime ? 'd.m.Y H:i' : 'd.m.Y', $ts);
}

/**
 * Exklusive Sperre, damit ein Cronjob nicht doppelt läuft.
 * Gibt das Handle zurück (offen halten!) oder null, wenn bereits gesperrt.
 *
 * @return resource|null
 */
function acquire_lock(string $name)
{
    $fh = fopen(APP_DATA . '/' . basename($name) . '.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
        return null;
    }
    return $fh;
}
