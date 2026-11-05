<?php
declare(strict_types=1);

/**
 * Datenbankzugriff (MySQL 8 über PDO).
 *
 * - Verbindung wird erst beim ersten db()-Aufruf aufgebaut.
 * - Tabellen werden über home/lib/migrations.php automatisch angelegt/aktualisiert
 *   (einmal pro Prozess geprüft, per GET_LOCK gegen parallele Läufe geschützt).
 * - Zeitstempel kommen aus PHP (Europe/Berlin), nicht aus NOW() der Datenbank.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = config('db');
    if (!is_array($cfg) || ($cfg['pass'] ?? '') === '' || ($cfg['pass'] ?? '') === 'HIER_PASSWORT_EINTRAGEN') {
        throw new RuntimeException('Datenbank ist nicht konfiguriert (home/config/config.php, Eintrag "db").');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'] ?? 'localhost',
        (int) ($cfg['port'] ?? 3306),
        $cfg['name'] ?? 'ads'
    );
    $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    db_migrate($pdo);
    return $pdo;
}

/** Aktueller Zeitstempel im DB-Format (lokale Zeit). */
function db_now(): string
{
    return date('Y-m-d H:i:s');
}

/** Führt eine Abfrage mit Parametern aus. */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

function db_value(string $sql, array $params = []): mixed
{
    $value = db_run($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/** Führt $fn in einer Transaktion aus. */
function db_transaction(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Legt fehlende Tabellen an bzw. spielt neue Migrationen ein. */
function db_migrate(PDO $pdo): array
{
    $migrations = require __DIR__ . '/migrations.php';

    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        id VARCHAR(100) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $applied = $pdo->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $pending = array_diff(array_keys($migrations), $applied);
    if ($pending === []) {
        return [];
    }

    // Nur ein Prozess migriert gleichzeitig.
    if ((int) $pdo->query("SELECT GET_LOCK('webintern_migrate', 30)")->fetchColumn() !== 1) {
        throw new RuntimeException('Migration läuft bereits in einem anderen Prozess.');
    }
    $done = [];
    try {
        $applied = $pdo->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($migrations as $id => $statements) {
            if (in_array($id, $applied, true)) {
                continue;
            }
            foreach ((array) $statements as $sql) {
                $pdo->exec($sql);
            }
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (id, applied_at) VALUES (?, ?)');
            $stmt->execute([$id, db_now()]);
            $done[] = $id;
            app_log('db', "Migration ausgeführt: {$id}");
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('webintern_migrate')");
    }
    return $done;
}
