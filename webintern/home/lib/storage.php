<?php
declare(strict_types=1);

/**
 * Dateibasierte Ablage (es gibt auf dem Server keinen PDO-/DB-Treiber).
 * Schreibvorgänge sind atomar: erst Temp-Datei, dann rename().
 */

function read_json(string $file, mixed $default = []): mixed
{
    $path = APP_DATA . '/' . ltrim($file, '/');
    if (!is_file($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $default;
    }
    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

function write_json(string $file, mixed $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    write_file_atomic(APP_DATA . '/' . ltrim($file, '/'), $json . "\n");
}

function write_file_atomic(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Verzeichnis nicht anlegbar: {$dir}");
    }
    $tmp = tempnam($dir, '.tmp_');
    if ($tmp === false || file_put_contents($tmp, $content) === false) {
        throw new RuntimeException("Schreiben fehlgeschlagen: {$path}");
    }
    chmod($tmp, 0664);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Umbenennen fehlgeschlagen: {$path}");
    }
}
