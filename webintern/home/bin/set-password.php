<?php
declare(strict_types=1);

/**
 * Setzt das Passwort für einen geschützten Bereich.
 * Aufruf auf dem Server:  php8.4 home/bin/set-password.php adstxt
 * Das Passwort wird verdeckt abgefragt und nur als Hash gespeichert.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$area = $argv[1] ?? '';
if (!preg_match('/^[a-z0-9_-]+$/', $area)) {
    fwrite(STDERR, "Aufruf: php set-password.php <bereich>   (z. B. adstxt)\n");
    exit(1);
}

function prompt_hidden(string $label): string
{
    fwrite(STDOUT, $label);
    shell_exec('stty -echo 2>/dev/null');
    $value = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
    return $value;
}

$pw1 = prompt_hidden('Neues Passwort: ');
$pw2 = prompt_hidden('Wiederholen: ');

if ($pw1 !== $pw2) {
    fwrite(STDERR, "Passwörter stimmen nicht überein.\n");
    exit(1);
}
if (mb_strlen($pw1) < 12) {
    fwrite(STDERR, "Bitte mindestens 12 Zeichen verwenden.\n");
    exit(1);
}

$file = APP_CONFIG . "/{$area}.passwordhash";
write_file_atomic($file, password_hash($pw1, PASSWORD_DEFAULT) . "\n");
chmod($file, 0640);
fwrite(STDOUT, "Passwort für Bereich '{$area}' gespeichert.\n");
