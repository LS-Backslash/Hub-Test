<?php
declare(strict_types=1);

/**
 * Übertragung auf den externen Live-Server (SFTP über cURL/libssh).
 *
 * Konfiguration in config.php, Eintrag 'live':
 *   'host' => 'ads.pddigital.de', 'port' => 22, 'user' => '…', 'pass' => '…',
 *   'path' => '/var/www/html/ads/www',          (Zielordner = Webroot des Live-Servers)
 *   'url'  => 'https://ads.pddigital.de',        (öffentliche Adresse dieses Ordners)
 *   'known_hosts' => '/data/wwwcust/ads/home/.ssh/known_hosts'  (Fingerabdruck des Servers)
 *
 * Sicherheit: Die Verbindung wird nur aufgebaut, wenn der Fingerabdruck des Servers in
 * known_hosts steht (Schutz vor untergeschobenen Servern).
 */

const LIVE_TIMEOUT = 30;

final class LiveError extends RuntimeException
{
}

/** Gibt die geprüfte Konfiguration zurück oder null, wenn der Live-Server (noch) nicht eingerichtet ist. */
function live_config(): ?array
{
    $c = config('live');
    if (!is_array($c)) {
        return null;
    }
    foreach (['host', 'user', 'pass', 'path', 'url'] as $k) {
        $v = (string) ($c[$k] ?? '');
        if ($v === '' || str_starts_with($v, 'HIER_')) {
            return null;
        }
    }
    $c['port'] = (int) ($c['port'] ?? 22);
    $c['path'] = '/' . trim((string) $c['path'], '/');
    $c['url'] = rtrim((string) $c['url'], '/');
    $c['known_hosts'] = (string) ($c['known_hosts'] ?? (getenv('HOME') ?: APP_HOME) . '/.ssh/known_hosts');
    return $c;
}

function live_enabled(): bool
{
    return live_config() !== null;
}

/** Öffentliche Adresse einer Datei relativ zum Zielordner, z. B. "adstxt/suedkurier/ads.txt". */
function live_url(string $relPath): ?string
{
    $c = live_config();
    return $c ? $c['url'] . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($relPath, '/')))) : null;
}

/** Nur sichere relative Pfade zulassen (kein "..", keine Sonderzeichen). */
function live_check_path(string $relPath): string
{
    $relPath = ltrim($relPath, '/');
    if ($relPath === '' || !preg_match('#^[a-z0-9._/-]+$#i', $relPath) || preg_match('#(^|/)\.\.?(/|$)#', $relPath)) {
        throw new LiveError("Ungültiger Zielpfad „{$relPath}“.");
    }
    return $relPath;
}

/** Wiederverwendbare cURL-Verbindung (eine SSH-Sitzung für mehrere Dateien). */
function live_handle(): CurlHandle
{
    static $ch = null;
    $c = live_config();
    if ($c === null) {
        throw new LiveError('Live-Server ist nicht konfiguriert (config.php, Eintrag "live").');
    }
    if (!is_file($c['known_hosts'])) {
        throw new LiveError('Fingerabdruck des Live-Servers fehlt (' . $c['known_hosts'] . '). Siehe README „Live-Server einrichten“.');
    }
    if ($ch === null) {
        $ch = curl_init();
    }
    curl_reset($ch);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD => $c['user'] . ':' . $c['pass'],
        CURLOPT_SSH_AUTH_TYPES => CURLSSH_AUTH_PASSWORD | CURLSSH_AUTH_KEYBOARD,
        CURLOPT_SSH_KNOWNHOSTS => $c['known_hosts'],
        CURLOPT_PROTOCOLS => CURLPROTO_SFTP,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => LIVE_TIMEOUT,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    return $ch;
}

function live_remote(string $relPath = ''): string
{
    $c = live_config();
    return $c['path'] . ($relPath !== '' ? '/' . $relPath : '');
}

function live_base_url(string $remotePath): string
{
    $c = live_config();
    // Pfad im URL kodieren, Schrägstriche erhalten.
    $encoded = implode('/', array_map('rawurlencode', explode('/', $remotePath)));
    return 'sftp://' . $c['host'] . ':' . $c['port'] . $encoded;
}

/** Wandelt cURL-Fehler in verständliche Meldungen um. */
function live_error(CurlHandle $ch): LiveError
{
    $c = live_config();
    $msg = match (curl_errno($ch)) {
        6 => "Server „{$c['host']}“ nicht gefunden (Name falsch oder DNS).",
        7 => "Keine Verbindung zu {$c['host']}:{$c['port']} (Server aus, falscher Port oder Firewall).",
        28 => 'Zeitüberschreitung bei der Verbindung zum Live-Server.',
        51, 60 => 'Fingerabdruck des Live-Servers unbekannt oder geändert – Verbindung aus Sicherheitsgründen abgelehnt.',
        67 => 'Anmeldung am Live-Server abgelehnt (Benutzer oder Passwort falsch).',
        78 => 'Datei oder Ordner auf dem Live-Server nicht gefunden.',
        9 => 'Keine Berechtigung auf dem Live-Server (Ordnerrechte).',
        default => 'Fehler bei der Übertragung: ' . (curl_error($ch) ?: 'cURL-Fehler ' . curl_errno($ch)),
    };
    return new LiveError($msg);
}

/**
 * Lädt Inhalt hoch. Erst als .tmp, dann umbenennen – so sieht niemand eine halbe Datei.
 * Fehlende Ordner werden angelegt.
 */
function live_put(string $relPath, string $content): void
{
    $relPath = live_check_path($relPath);
    $remote = live_remote($relPath);
    $tmp = $remote . '.tmp-' . bin2hex(random_bytes(4));

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);

    $ch = live_handle();
    curl_setopt_array($ch, [
        CURLOPT_URL => live_base_url($tmp),
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $stream,
        CURLOPT_INFILESIZE => strlen($content),
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
        CURLOPT_NEW_FILE_PERMS => 0644,
        CURLOPT_NEW_DIRECTORY_PERMS => 0755,
        // "*" = Fehler ignorieren (Datei existiert beim ersten Mal noch nicht).
        CURLOPT_POSTQUOTE => ["*rm {$remote}", "rename {$tmp} {$remote}"],
    ]);
    $ok = curl_exec($ch);
    fclose($stream);
    if ($ok === false) {
        $err = live_error($ch);
        live_delete_quiet([$tmp]);
        throw $err;
    }
}

function live_put_file(string $relPath, string $localFile): void
{
    $content = file_get_contents($localFile);
    if ($content === false) {
        throw new LiveError("Lokale Datei nicht lesbar: {$localFile}");
    }
    live_put($relPath, $content);
}

/** Löscht Dateien (relativ) und danach leere Ordner; fehlende Dateien sind kein Fehler. */
function live_delete(array $relPaths, array $relDirs = []): void
{
    $cmds = [];
    foreach ($relPaths as $p) {
        $cmds[] = '*rm ' . live_remote(live_check_path($p));
    }
    foreach ($relDirs as $d) {
        $cmds[] = '*rmdir ' . live_remote(live_check_path($d));
    }
    if ($cmds === []) {
        return;
    }
    $ch = live_handle();
    curl_setopt_array($ch, [
        CURLOPT_URL => live_base_url(live_remote()) . '/',
        CURLOPT_NOBODY => true,
        CURLOPT_QUOTE => $cmds,
    ]);
    if (curl_exec($ch) === false) {
        throw live_error($ch);
    }
}

/** Interne Aufräumhilfe mit absoluten Pfaden, Fehler werden ignoriert. */
function live_delete_quiet(array $absPaths): void
{
    try {
        $ch = live_handle();
        curl_setopt_array($ch, [
            CURLOPT_URL => live_base_url(live_remote()) . '/',
            CURLOPT_NOBODY => true,
            CURLOPT_QUOTE => array_map(static fn ($p) => "*rm {$p}", $absPaths),
        ]);
        curl_exec($ch);
    } catch (Throwable) {
    }
}

/**
 * Verbindungstest: Anmeldung, Zielordner lesbar, Schreiben + Löschen einer Testdatei.
 *
 * @return array{ok: bool, message: string}
 */
function live_test(): array
{
    if (!live_enabled()) {
        return ['ok' => false, 'message' => 'nicht konfiguriert (config.php, Eintrag "live")'];
    }
    try {
        $ch = live_handle();
        curl_setopt_array($ch, [CURLOPT_URL => live_base_url(live_remote()) . '/', CURLOPT_DIRLISTONLY => true]);
        if (curl_exec($ch) === false) {
            throw live_error($ch);
        }
        $name = '.webintern-test-' . bin2hex(random_bytes(3));
        live_put($name, 'test');
        live_delete([$name]);
        return ['ok' => true, 'message' => 'Anmeldung, Lesen und Schreiben in ' . live_remote() . ' ok'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
