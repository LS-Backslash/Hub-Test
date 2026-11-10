<?php
declare(strict_types=1);

/**
 * Ads.txt Organizer: Datenbankzugriff, Abruf der Quellen und Erzeugen der Dateien.
 */

require_once __DIR__ . '/core.php';

const ADSTXT_FILE_TYPES = ['ads' => 'ads.txt', 'app-ads' => 'app-ads.txt', 'both' => 'beide'];
const ADSTXT_FETCH_TIMEOUT = 25;

/* ------------------------------------------------------------------------
 * Publisher
 * ---------------------------------------------------------------------- */

function adstxt_publishers(bool $includeInactive = true): array
{
    return db_all('SELECT * FROM adstxt_publishers' . ($includeInactive ? '' : ' WHERE active = 1') . ' ORDER BY active DESC, name');
}

function adstxt_publisher(int $id): ?array
{
    return db_one('SELECT * FROM adstxt_publishers WHERE id = ?', [$id]);
}

/** Kürzel aus dem Namen, eindeutig gemacht. */
function adstxt_unique_slug(string $name, ?int $exceptId = null): string
{
    $base = strtr(mb_strtolower(trim($name)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: '';
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', $base) ?? '', '-') ?: 'publisher';
    $base = rtrim(substr($base, 0, 50), '-');
    $slug = $base;
    for ($i = 2; db_value('SELECT id FROM adstxt_publishers WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0]) !== null; $i++) {
        $slug = "{$base}-{$i}";
    }
    return $slug;
}

/**
 * Prüft und speichert einen Publisher. Gibt [id, Fehler] zurück.
 *
 * @return array{0: ?int, 1: array<string, string>}
 */
function adstxt_publisher_save(array $in, ?int $id = null): array
{
    $data = [
        'name' => trim((string) ($in['name'] ?? '')),
        'domain' => strtolower(trim((string) ($in['domain'] ?? ''))),
        'active' => !empty($in['active']) ? 1 : 0,
        'wants_ads' => !empty($in['wants_ads']) ? 1 : 0,
        'wants_app_ads' => !empty($in['wants_app_ads']) ? 1 : 0,
        'header_lines' => trim(str_replace("\r", '', (string) ($in['header_lines'] ?? ''))),
        'notes' => trim((string) ($in['notes'] ?? '')),
    ];
    $data['domain'] = preg_replace('#^https?://|/.*$#', '', $data['domain']) ?? '';

    $errors = [];
    if ($data['name'] === '' || mb_strlen($data['name']) > 120) {
        $errors['name'] = 'Bitte einen Namen (max. 120 Zeichen) angeben.';
    }
    if ($data['domain'] !== '' && !preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $data['domain'])) {
        $errors['domain'] = 'Bitte eine Domain ohne https:// angeben, z. B. beispiel.de';
    }
    if (!$data['wants_ads'] && !$data['wants_app_ads']) {
        $errors['files'] = 'Mindestens eine Datei (ads.txt oder app-ads.txt) auswählen.';
    }
    foreach (preg_split('/\R/', $data['header_lines']) as $line) {
        $p = adstxt_parse_line($line);
        if ($p['type'] === 'record') {
            $errors['header_lines'] = 'Hier nur Kopfzeilen wie OWNERDOMAIN=… eintragen. Datensätze gehören in „Einträge“.';
            break;
        }
        if ($p['type'] === 'invalid') {
            $errors['header_lines'] = "Ungültige Zeile „" . trim($line) . "“: {$p['message']}";
            break;
        }
    }
    if ($errors) {
        return [null, $errors];
    }

    $now = db_now();
    if ($id === null) {
        db_run('INSERT INTO adstxt_publishers (slug, name, domain, active, wants_ads, wants_app_ads, header_lines, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            adstxt_unique_slug($data['name']), $data['name'], $data['domain'], $data['active'], $data['wants_ads'],
            $data['wants_app_ads'], $data['header_lines'], $data['notes'], $now, $now,
        ]);
        $id = (int) db()->lastInsertId();
    } else {
        // Das Kürzel bleibt stabil (es ist Teil der Download-Adresse).
        db_run('UPDATE adstxt_publishers SET name = ?, domain = ?, active = ?, wants_ads = ?, wants_app_ads = ?, header_lines = ?, notes = ?, updated_at = ?
                WHERE id = ?', [
            $data['name'], $data['domain'], $data['active'], $data['wants_ads'], $data['wants_app_ads'],
            $data['header_lines'], $data['notes'], $now, $id,
        ]);
    }
    return [$id, []];
}

function adstxt_publisher_set_active(int $id, bool $active): void
{
    db_run('UPDATE adstxt_publishers SET active = ?, updated_at = ? WHERE id = ?', [$active ? 1 : 0, db_now(), $id]);
}

/* ------------------------------------------------------------------------
 * Quellen und Einträge (Blöcke)
 * ---------------------------------------------------------------------- */

function adstxt_sources(?int $publisherId = null, bool $onlyGlobal = false): array
{
    $sql = 'SELECT s.*, p.name AS publisher_name, c.record_count AS cached_records, c.fetched_at AS cached_at
            FROM adstxt_sources s
            LEFT JOIN adstxt_publishers p ON p.id = s.publisher_id
            LEFT JOIN adstxt_source_cache c ON c.source_id = s.id';
    $params = [];
    if ($onlyGlobal) {
        $sql .= ' WHERE s.publisher_id IS NULL';
    } elseif ($publisherId !== null) {
        $sql .= ' WHERE s.publisher_id = ?';
        $params[] = $publisherId;
    }
    return db_all($sql . ' ORDER BY s.publisher_id IS NOT NULL, p.name, s.name', $params);
}

function adstxt_source(int $id): ?array
{
    return db_one('SELECT s.*, c.content AS cached_content, c.record_count AS cached_records, c.fetched_at AS cached_at
                   FROM adstxt_sources s LEFT JOIN adstxt_source_cache c ON c.source_id = s.id WHERE s.id = ?', [$id]);
}

function adstxt_blocks(?int $publisherId = null, bool $onlyGlobal = false): array
{
    $sql = 'SELECT b.*, p.name AS publisher_name FROM adstxt_blocks b LEFT JOIN adstxt_publishers p ON p.id = b.publisher_id';
    $params = [];
    if ($onlyGlobal) {
        $sql .= ' WHERE b.publisher_id IS NULL';
    } elseif ($publisherId !== null) {
        $sql .= ' WHERE b.publisher_id = ?';
        $params[] = $publisherId;
    }
    return db_all($sql . ' ORDER BY b.publisher_id IS NOT NULL, p.name, b.name', $params);
}

function adstxt_block(int $id): ?array
{
    return db_one('SELECT * FROM adstxt_blocks WHERE id = ?', [$id]);
}

/** Gemeinsame Prüfung für Quelle/Block: Name, Geltungsbereich, Dateityp. */
function adstxt_validate_common(array $in, array &$errors): array
{
    $publisherId = ($in['publisher_id'] ?? '') === '' ? null : (int) $in['publisher_id'];
    if ($publisherId !== null && adstxt_publisher($publisherId) === null) {
        $errors['publisher_id'] = 'Unbekannter Publisher.';
    }
    $fileType = (string) ($in['file_type'] ?? '');
    if (!isset(ADSTXT_FILE_TYPES[$fileType])) {
        $errors['file_type'] = 'Bitte die Datei auswählen.';
    }
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) {
        $errors['name'] = 'Bitte einen Namen (max. 120 Zeichen) angeben.';
    }
    return [$publisherId, $fileType, $name, !empty($in['active']) ? 1 : 0];
}

/** @return array{0: ?int, 1: array<string, string>} */
function adstxt_source_save(array $in, ?int $id = null): array
{
    $errors = [];
    [$publisherId, $fileType, $name, $active] = adstxt_validate_common($in, $errors);
    $url = trim((string) ($in['url'] ?? ''));
    if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false || strlen($url) > 500) {
        $errors['url'] = 'Bitte eine vollständige Adresse mit https:// angeben.';
    }
    if ($errors) {
        return [null, $errors];
    }
    $now = db_now();
    if ($id === null) {
        db_run('INSERT INTO adstxt_sources (publisher_id, name, url, file_type, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$publisherId, $name, $url, $fileType, $active, $now, $now]);
        return [(int) db()->lastInsertId(), []];
    }
    $old = adstxt_source($id);
    db_run('UPDATE adstxt_sources SET publisher_id = ?, name = ?, url = ?, file_type = ?, active = ?, updated_at = ? WHERE id = ?',
        [$publisherId, $name, $url, $fileType, $active, $now, $id]);
    if ($old !== null && $old['url'] !== $url) {
        // Neue Adresse: alter Zwischenspeicher passt nicht mehr.
        db_run('DELETE FROM adstxt_source_cache WHERE source_id = ?', [$id]);
        db_run('UPDATE adstxt_sources SET last_status = NULL, last_message = NULL, last_fetch_at = NULL WHERE id = ?', [$id]);
    }
    return [$id, []];
}

/** @return array{0: ?int, 1: array<string, string>, 2: array} [id, Fehler, Analyse] */
function adstxt_block_save(array $in, ?int $id = null): array
{
    $errors = [];
    [$publisherId, $fileType, $name, $active] = adstxt_validate_common($in, $errors);
    $content = trim(str_replace("\r", '', (string) ($in['content'] ?? '')));
    $analysis = adstxt_analyze($content);
    if ($analysis['records'] === 0 && $analysis['variables'] === 0) {
        $errors['content'] = 'Der Block enthält keine gültigen Zeilen.';
    }
    if ($errors) {
        return [null, $errors, $analysis];
    }
    $now = db_now();
    if ($id === null) {
        db_run('INSERT INTO adstxt_blocks (publisher_id, name, file_type, content, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$publisherId, $name, $fileType, $content, $active, $now, $now]);
        $id = (int) db()->lastInsertId();
    } else {
        db_run('UPDATE adstxt_blocks SET publisher_id = ?, name = ?, file_type = ?, content = ?, active = ?, updated_at = ? WHERE id = ?',
            [$publisherId, $name, $fileType, $content, $active, $now, $id]);
    }
    return [$id, [], $analysis];
}

function adstxt_delete(string $table, int $id): void
{
    if (!in_array($table, ['adstxt_sources', 'adstxt_blocks'], true)) {
        throw new InvalidArgumentException('Löschen nur für Quellen und Einträge erlaubt.');
    }
    db_run("DELETE FROM {$table} WHERE id = ?", [$id]);
}

/* ------------------------------------------------------------------------
 * Abruf der Quellen
 * ---------------------------------------------------------------------- */

/** Ruft eine Quelle ab und aktualisiert den Zwischenspeicher, wenn der Inhalt plausibel ist. */
function adstxt_fetch_source(array $source): array
{
    $previous = db_value('SELECT record_count FROM adstxt_source_cache WHERE source_id = ?', [$source['id']]);
    $response = fetch_url($source['url'], ADSTXT_FETCH_TIMEOUT);
    $check = adstxt_check_source($response, $previous === null ? null : (int) $previous);
    $now = db_now();

    if ($check['accept']) {
        db_run('INSERT INTO adstxt_source_cache (source_id, content, content_hash, record_count, fetched_at) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE content = VALUES(content), content_hash = VALUES(content_hash),
                                        record_count = VALUES(record_count), fetched_at = VALUES(fetched_at)',
            [$source['id'], $check['body'], hash('sha256', $check['body']), $check['records'], $now]);
    } elseif ($previous !== null) {
        $check['message'] .= ' – letzter Stand wird weiterverwendet';
    }

    db_run('UPDATE adstxt_sources SET last_fetch_at = ?, last_status = ?, last_message = ? WHERE id = ?',
        [$now, $check['status'], mb_substr($check['message'], 0, 500), $source['id']]);
    app_log('adstxt', "Quelle #{$source['id']} {$source['name']}: {$check['status']} – {$check['message']}");
    return $check;
}

/* ------------------------------------------------------------------------
 * Dateien erzeugen
 * ---------------------------------------------------------------------- */

/** Sammelt die Bausteine eines Publishers für einen Dateityp. */
function adstxt_collect_parts(array $publisher, string $fileType): array
{
    $parts = [];
    if (trim($publisher['header_lines']) !== '') {
        $parts[] = ['level' => ADSTXT_LEVEL_PUBLISHER_HEADER, 'label' => 'Kopfzeilen ' . $publisher['name'], 'text' => $publisher['header_lines']];
    }

    $types = [$fileType, 'both'];
    $blocks = db_all('SELECT * FROM adstxt_blocks WHERE active = 1 AND file_type IN (?, ?) AND (publisher_id = ? OR publisher_id IS NULL) ORDER BY name, id',
        [...$types, $publisher['id']]);
    foreach ($blocks as $b) {
        $parts[] = [
            'level' => $b['publisher_id'] === null ? ADSTXT_LEVEL_GLOBAL_BLOCK : ADSTXT_LEVEL_PUBLISHER_BLOCK,
            'label' => 'Eintrag: ' . $b['name'],
            'text' => $b['content'],
        ];
    }

    $sources = db_all('SELECT s.*, c.content FROM adstxt_sources s JOIN adstxt_source_cache c ON c.source_id = s.id
                       WHERE s.active = 1 AND s.file_type IN (?, ?) AND (s.publisher_id = ? OR s.publisher_id IS NULL) ORDER BY s.name, s.id',
        [...$types, $publisher['id']]);
    foreach ($sources as $s) {
        $parts[] = [
            'level' => $s['publisher_id'] === null ? ADSTXT_LEVEL_GLOBAL_SOURCE : ADSTXT_LEVEL_PUBLISHER_SOURCE,
            'label' => 'Quelle: ' . $s['name'],
            'text' => $s['content'],
        ];
    }
    return $parts;
}

/** Erzeugt die Datei, ohne zu speichern (für die Vorschau). */
function adstxt_preview(array $publisher, string $fileType): array
{
    return adstxt_build($publisher, $fileType, adstxt_collect_parts($publisher, $fileType));
}

/** Erzeugt und speichert eine Datei; protokolliert Änderungen. Gibt true zurück, wenn sich etwas geändert hat. */
function adstxt_generate(array $publisher, string $fileType, ?int $runId): bool
{
    $build = adstxt_preview($publisher, $fileType);
    $old = db_one('SELECT content, body_hash FROM adstxt_outputs WHERE publisher_id = ? AND file_type = ?', [$publisher['id'], $fileType]);
    $now = db_now();

    if ($old !== null && $old['body_hash'] === $build['body_hash']) {
        db_run('UPDATE adstxt_outputs SET generated_at = ? WHERE publisher_id = ? AND file_type = ?', [$now, $publisher['id'], $fileType]);
        return false;
    }

    $diff = adstxt_diff($old['content'] ?? '', $build['content']);
    db_transaction(static function () use ($publisher, $fileType, $build, $diff, $runId, $now): void {
        db_run('INSERT INTO adstxt_outputs (publisher_id, file_type, content, body_hash, record_count, generated_at, changed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE content = VALUES(content), body_hash = VALUES(body_hash), record_count = VALUES(record_count),
                                        generated_at = VALUES(generated_at), changed_at = VALUES(changed_at)',
            [$publisher['id'], $fileType, $build['content'], $build['body_hash'], $build['record_count'], $now, $now]);
        db_run('INSERT INTO adstxt_changes (run_id, publisher_id, file_type, changed_at, added, removed, added_count, removed_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$runId, $publisher['id'], $fileType, $now, implode("\n", $diff['added']), implode("\n", $diff['removed']),
             count($diff['added']), count($diff['removed'])]);
    });
    return true;
}

/**
 * Kompletter Lauf: alle benötigten Quellen abrufen, alle aktiven Publisher neu erzeugen.
 *
 * @return array{run_id: int, status: string, sources: array, changed: list<string>, files: int}
 */
function adstxt_run(string $trigger): array
{
    $lock = acquire_lock('adstxt-run');
    if ($lock === null) {
        throw new RuntimeException('Es läuft bereits eine Aktualisierung. Bitte in einer Minute erneut versuchen.');
    }

    $now = db_now();
    db_run('INSERT INTO adstxt_runs (trigger_type, started_at, status) VALUES (?, ?, ?)', [$trigger, $now, 'running']);
    $runId = (int) db()->lastInsertId();
    $result = ['run_id' => $runId, 'status' => 'ok', 'sources' => ['ok' => 0, 'warning' => 0, 'error' => 0], 'changed' => [], 'files' => 0];

    try {
        // Nur Quellen, die von mindestens einem aktiven Publisher genutzt werden.
        $sources = db_all('SELECT s.* FROM adstxt_sources s LEFT JOIN adstxt_publishers p ON p.id = s.publisher_id
                           WHERE s.active = 1 AND (s.publisher_id IS NULL OR p.active = 1) ORDER BY s.id');
        foreach ($sources as $source) {
            $check = adstxt_fetch_source($source);
            $result['sources'][$check['status']]++;
        }

        foreach (adstxt_publishers(false) as $publisher) {
            foreach (['ads' => 'wants_ads', 'app-ads' => 'wants_app_ads'] as $type => $flag) {
                if (!$publisher[$flag]) {
                    continue;
                }
                $result['files']++;
                if (adstxt_generate($publisher, $type, $runId)) {
                    $result['changed'][] = $publisher['name'] . ' – ' . ADSTXT_FILE_NAMES[$type];
                }
            }
        }

        if (live_enabled()) {
            $result['live'] = adstxt_sync();
        }

        $liveFailed = ($result['live']['failed'] ?? 0) > 0;
        $result['status'] = $result['sources']['error'] > 0 || $result['sources']['warning'] > 0 || $liveFailed ? 'warning' : 'ok';
    } catch (Throwable $e) {
        $result['status'] = 'error';
        $result['error'] = $e->getMessage();
        app_log('adstxt', 'Lauf abgebrochen: ' . $e->getMessage());
    } finally {
        db_run('UPDATE adstxt_runs SET finished_at = ?, status = ?, summary = ? WHERE id = ?',
            [db_now(), $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE), $runId]);
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    app_log('adstxt', sprintf('Lauf #%d (%s): %s – Quellen ok/Warnung/Fehler %d/%d/%d, %d Datei(en), %d geändert%s',
        $runId, $trigger, $result['status'], $result['sources']['ok'], $result['sources']['warning'], $result['sources']['error'],
        $result['files'], count($result['changed']),
        isset($result['live']) ? sprintf(', live: %d hochgeladen, %d gelöscht, %d Fehler', $result['live']['uploaded'], $result['live']['deleted'], $result['live']['failed']) : ''));
    return $result;
}

/** Aktuelle Datei eines Publishers (für Download/Bereitstellung). */
function adstxt_output(int $publisherId, string $fileType): ?array
{
    return db_one('SELECT * FROM adstxt_outputs WHERE publisher_id = ? AND file_type = ?', [$publisherId, $fileType]);
}

/* ------------------------------------------------------------------------
 * Live-Server
 * ---------------------------------------------------------------------- */

/** Pfad einer Datei auf dem Live-Server, z. B. "adstxt/suedkurier/ads.txt". */
function adstxt_live_path(string $slug, string $fileType): string
{
    return "adstxt/{$slug}/" . ADSTXT_FILE_NAMES[$fileType];
}

/**
 * Gleicht den Live-Server ab: geänderte Dateien hochladen, Dateien von deaktivierten
 * Publishern bzw. abgewählten Dateitypen löschen.
 *
 * @return array{uploaded: int, deleted: int, failed: int, errors: list<string>}
 */
function adstxt_sync(): array
{
    $r = ['uploaded' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => []];
    if (!live_enabled()) {
        return $r;
    }
    $rows = db_all('SELECT o.*, p.slug, p.name, p.active, p.wants_ads, p.wants_app_ads
                    FROM adstxt_outputs o JOIN adstxt_publishers p ON p.id = o.publisher_id ORDER BY p.name, o.file_type');

    foreach ($rows as $o) {
        if ($r['failed'] >= 3 && $r['uploaded'] + $r['deleted'] === 0) {
            $r['errors'][] = 'Abgebrochen nach 3 Fehlern – Live-Server vermutlich nicht erreichbar.';
            break;
        }
        $wanted = $o['active'] && $o[$o['file_type'] === 'ads' ? 'wants_ads' : 'wants_app_ads'];
        $path = adstxt_live_path($o['slug'], $o['file_type']);
        try {
            if ($wanted && $o['uploaded_hash'] !== $o['body_hash']) {
                live_put($path, $o['content']);
                db_run('UPDATE adstxt_outputs SET uploaded_hash = ?, uploaded_at = ?, upload_error = NULL WHERE publisher_id = ? AND file_type = ?',
                    [$o['body_hash'], db_now(), $o['publisher_id'], $o['file_type']]);
                $r['uploaded']++;
            } elseif (!$wanted && $o['uploaded_hash'] !== null) {
                live_delete([$path], ["adstxt/{$o['slug']}"]); // Ordner nur, wenn er danach leer ist
                db_run('UPDATE adstxt_outputs SET uploaded_hash = NULL, uploaded_at = NULL, upload_error = NULL WHERE publisher_id = ? AND file_type = ?',
                    [$o['publisher_id'], $o['file_type']]);
                $r['deleted']++;
            }
        } catch (Throwable $e) {
            db_run('UPDATE adstxt_outputs SET upload_error = ? WHERE publisher_id = ? AND file_type = ?',
                [mb_substr($e->getMessage(), 0, 500), $o['publisher_id'], $o['file_type']]);
            $r['failed']++;
            $r['errors'][] = "{$o['name']} – " . ADSTXT_FILE_NAMES[$o['file_type']] . ': ' . $e->getMessage();
            app_log('adstxt', "Live-Upload fehlgeschlagen ({$path}): " . $e->getMessage());
        }
    }
    return $r;
}

/**
 * Live-Status einer Datei für die Anzeige.
 *
 * @return array{status: string, text: string, url: ?string}
 */
function adstxt_live_status(array $publisher, ?array $output, string $fileType): array
{
    $url = live_url(adstxt_live_path($publisher['slug'], $fileType));
    if (!live_enabled()) {
        return ['status' => 'off', 'text' => 'Live-Server nicht eingerichtet', 'url' => null];
    }
    if ($output === null) {
        return ['status' => 'off', 'text' => 'Noch nicht erzeugt', 'url' => $url];
    }
    if ($output['upload_error']) {
        return ['status' => 'error', 'text' => 'Upload fehlgeschlagen: ' . $output['upload_error'], 'url' => $url];
    }
    if ($output['uploaded_hash'] === null) {
        return ['status' => 'warning', 'text' => 'Noch nicht hochgeladen', 'url' => $url];
    }
    if ($output['uploaded_hash'] !== $output['body_hash']) {
        return ['status' => 'warning', 'text' => 'Neuer Stand wird beim nächsten Lauf hochgeladen', 'url' => $url];
    }
    return ['status' => 'ok', 'text' => 'Live seit ' . date_de(strtotime($output['uploaded_at'])), 'url' => $url];
}

