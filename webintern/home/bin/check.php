<?php
declare(strict_types=1);

/**
 * Prüft die Server-Umgebung und richtet die Datenbank-Tabellen ein.
 * Aufruf auf dem Server:  php8.4 /data/wwwcust/ads/home/bin/check.php
 * Optional mit Test-URL:  php8.4 .../check.php https://example.com/ads.txt
 *
 * Gibt keine Zugangsdaten aus.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$problems = 0;
function line(bool|null $ok, string $label, string $detail = ''): void
{
    global $problems;
    $mark = $ok === null ? '  ·  ' : ($ok ? ' OK  ' : ' !!  ');
    if ($ok === false) {
        $problems++;
    }
    echo $mark . str_pad($label, 34) . $detail . "\n";
}

echo "\n== PHP ==\n";
line(version_compare(PHP_VERSION, '8.3', '>='), 'PHP-Version', PHP_VERSION . ' (' . PHP_SAPI . ')');
foreach (['pdo_mysql', 'curl', 'openssl', 'mbstring', 'fileinfo', 'gd', 'zip', 'iconv', 'json'] as $ext) {
    line(extension_loaded($ext), "Erweiterung {$ext}");
}
line(null, 'Zeitzone (gesetzt)', date_default_timezone_get() . ', jetzt ' . date('d.m.Y H:i'));

echo "\n== Ordner ==\n";
foreach (['home/data' => APP_DATA, 'home/logs' => APP_LOGS, 'www/epaper/creatives' => APP_HOME . '/../www/epaper/creatives'] as $label => $dir) {
    line(is_dir($dir) && is_writable($dir), "beschreibbar: {$label}", is_dir($dir) ? '' : 'fehlt');
}
$configFile = APP_CONFIG . '/config.php';
if (is_file($configFile)) {
    $perms = substr(sprintf('%o', fileperms($configFile)), -3);
    line(!((int) $perms[2] & 4), 'config.php nicht für alle lesbar', "Rechte {$perms}" . ((int) $perms[2] & 4 ? ' → chmod 640 config.php' : ''));
} else {
    line(false, 'config.php vorhanden', 'fehlt → config.example.php kopieren');
}

echo "\n== Datenbank ==\n";
try {
    $pdo = db(); // verbindet und spielt Migrationen ein
    line(true, 'Verbindung', (string) $pdo->query('SELECT VERSION()')->fetchColumn());
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    line(in_array('epaper_creatives', $tables, true) && in_array('adstxt_publishers', $tables, true), 'Tabellen eingerichtet', count($tables) . ' Tabellen');
    $migrations = $pdo->query('SELECT id FROM schema_migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    line(null, 'Migrationen', implode(', ', $migrations));
} catch (Throwable $e) {
    line(false, 'Verbindung', $e->getMessage());
}

echo "\n== Internet (ausgehend) ==\n";
$url = $argv[1] ?? 'https://www.google.com/robots.txt';
$t = microtime(true);
$res = fetch_url($url, 15);
line($res['ok'], 'Abruf ' . parse_url($url, PHP_URL_HOST), $res['ok']
    ? sprintf('HTTP %d, %d Bytes, %.1f s', $res['status'], strlen($res['body']), microtime(true) - $t)
    : ($res['error'] ?? 'Fehler'));

echo "\n" . ($problems === 0 ? "Alles in Ordnung.\n" : "{$problems} Problem(e) gefunden (mit !! markiert).\n");
exit($problems === 0 ? 0 : 1);
