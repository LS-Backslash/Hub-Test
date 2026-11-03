<?php
declare(strict_types=1);

/**
 * Einfacher Passwortschutz (ein gemeinsames Passwort pro Bereich).
 * Der Hash liegt außerhalb des Webroots in home/config/<bereich>.passwordhash
 * und wird mit home/bin/set-password.php erzeugt.
 */

const AUTH_MAX_ATTEMPTS = 5;      // Fehlversuche ...
const AUTH_LOCK_SECONDS = 300;    // ... dann 5 Minuten Sperre
const AUTH_IDLE_TIMEOUT = 28800;  // Abmeldung nach 8 h Inaktivität

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('webintern_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function csrf_token(): string
{
    auth_start_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Ungültige Anfrage (CSRF).');
    }
}

function auth_is_logged_in(string $area): bool
{
    auth_start_session();
    $last = $_SESSION['auth'][$area] ?? null;
    if ($last === null || time() - $last > AUTH_IDLE_TIMEOUT) {
        unset($_SESSION['auth'][$area]);
        return false;
    }
    $_SESSION['auth'][$area] = time();
    return true;
}

function auth_logout(string $area): void
{
    auth_start_session();
    unset($_SESSION['auth'][$area]);
    session_regenerate_id(true);
}

/** Zeigt bei Bedarf das Login-Formular und beendet das Skript. */
function auth_require(string $area, string $title): void
{
    if (auth_is_logged_in($area)) {
        if (($_POST['action'] ?? '') === 'logout') {
            csrf_check();
            auth_logout($area);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
            exit;
        }
        return;
    }

    $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
        csrf_check();
        $error = auth_try_login($area, (string) $_POST['password']);
        if ($error === null) {
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'));
            exit;
        }
    }

    http_response_code(401);
    render_login($title, $error);
    exit;
}

/** @return string|null Fehlermeldung oder null bei Erfolg */
function auth_try_login(string $area, string $password): ?string
{
    $hashFile = APP_CONFIG . '/' . basename($area) . '.passwordhash';
    $hash = is_file($hashFile) ? trim((string) file_get_contents($hashFile)) : '';
    if ($hash === '') {
        return 'Für diesen Bereich ist noch kein Passwort gesetzt.';
    }

    // Fehlversuche pro IP zählen (nur Zähler, wird nach Ablauf verworfen).
    $throttleFile = 'auth-throttle-' . basename($area) . '.json';
    $throttle = read_json($throttleFile);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = hash('sha256', $ip);
    $now = time();
    $throttle = array_filter($throttle, static fn ($e) => $now - $e['first'] < AUTH_LOCK_SECONDS);
    $entry = $throttle[$key] ?? ['count' => 0, 'first' => $now];

    if ($entry['count'] >= AUTH_MAX_ATTEMPTS) {
        return 'Zu viele Fehlversuche. Bitte in ein paar Minuten erneut versuchen.';
    }

    if (!password_verify($password, $hash)) {
        $entry['count']++;
        $throttle[$key] = $entry;
        write_json($throttleFile, $throttle);
        app_log('auth', "Fehlgeschlagener Login für Bereich {$area}");
        return 'Passwort falsch.';
    }

    unset($throttle[$key]);
    write_json($throttleFile, $throttle);
    session_regenerate_id(true);
    $_SESSION['auth'][$area] = $now;
    return null;
}

function render_login(string $title, ?string $error): void
{
    $token = csrf_token();
    ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($title) ?> – Anmeldung</title>
<style>
  body { font-family: system-ui, sans-serif; background: #f3f4f6; display: grid; place-items: center; min-height: 100vh; margin: 0; }
  form { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.08); width: min(320px, 90vw); }
  h1 { font-size: 1.2rem; margin: 0 0 1rem; }
  input, button { width: 100%; box-sizing: border-box; padding: .6rem; font-size: 1rem; margin-top: .5rem; }
  button { background: #1f2937; color: #fff; border: 0; border-radius: 4px; cursor: pointer; }
  .error { color: #b91c1c; font-size: .9rem; }
</style>
</head>
<body>
<form method="post">
  <h1><?= h($title) ?></h1>
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= h($token) ?>">
  <label>Passwort <input type="password" name="password" autofocus required autocomplete="current-password"></label>
  <button type="submit">Anmelden</button>
</form>
</body>
</html>
    <?php
}
