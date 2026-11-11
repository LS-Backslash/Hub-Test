<?php
declare(strict_types=1);

/**
 * ePaper-Werbezwischenseiten: Validierung, HTML-Erzeugung, Ablage und Übersicht.
 *
 * Ablage:   www/epaper/creatives/<verlag>/<datum>_<kunde>/{index.html, quer.*, hoch.*}
 * Metadaten: Tabelle epaper_creatives (MySQL)
 * Die gleiche Ordnerstruktur wird in Schritt 3 auf dem Live-Server angelegt.
 */

/** Verlage: Kürzel (= Ordnername) => Anzeigename und Logo. Neue Verlage nur hier ergänzen. */
const EPAPER_PUBLISHERS = [
    'augsburger-allgemeine' => ['name' => 'Augsburger Allgemeine', 'logo' => 'augsburger-allgemeine.png'],
    'suedkurier' => ['name' => 'Südkurier', 'logo' => 'suedkurier.png'],
    'mainpost' => ['name' => 'Main-Post', 'logo' => 'mainpost.png'],
    'ka-news' => ['name' => 'ka-news', 'logo' => 'ka-news.png'],
    'allgaeuer-zeitung' => ['name' => 'Allgäuer Zeitung', 'logo' => 'allgaeuer-zeitung.png'],
];

const EPAPER_MAX_IMAGE_BYTES = 10 * 1024 * 1024; // 10 MB pro Bild
const EPAPER_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

/** Öffentlicher Ablageort (im Webroot, damit die Vorschau direkt aufrufbar ist). */
const EPAPER_CREATIVES_DIR = APP_HOME . '/../www/epaper/creatives';
/** URL relativ zu www/epaper/. */
const EPAPER_CREATIVES_URL = 'creatives';
/** Frühere JSON-Ablage unter home/data/ (nur noch für den einmaligen Import). */
const EPAPER_META_DIR = 'epaper';
/** Kennung eines Creatives: "<verlag>/<datum>_<kunde>". */
const EPAPER_ID_PATTERN = '#^([a-z0-9-]{1,40})/(\d{4}-\d{2}-\d{2}_[a-z0-9-]{1,60})$#';

function epaper_publisher_name(string $slug): string
{
    return EPAPER_PUBLISHERS[$slug]['name'] ?? $slug;
}

/**
 * Ordnername aus Erscheinungsdatum und Kundenname, z. B. "2026-11-05_muster-gmbh".
 * Die gleiche Logik steckt im JavaScript der Formularseite (Live-Anzeige).
 */
function epaper_folder_name(string $customer, string $date): string
{
    $slug = mb_strtolower(trim($customer));
    $slug = strtr($slug, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug) ?: '';
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-');
    $slug = rtrim(substr($slug, 0, 60), '-');
    return $date . '_' . ($slug !== '' ? $slug : 'kunde');
}

/** Prüft die Kennung "<verlag>/<ordner>" und ob der Verlag bekannt ist. */
function epaper_is_valid_id(string $id): bool
{
    return preg_match(EPAPER_ID_PATTERN, $id, $m) === 1 && isset(EPAPER_PUBLISHERS[$m[1]]);
}

function epaper_exists(string $id): bool
{
    return epaper_is_valid_id($id) && is_dir(EPAPER_CREATIVES_DIR . '/' . $id);
}

/* ------------------------------------------------------------------------
 * Formular prüfen
 * ---------------------------------------------------------------------- */

/**
 * Gibt [Werte, Fehler] zurück; Fehler ist ein Array feld => Meldung.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function epaper_validate(array $post, array $files): array
{
    $errors = [];
    $values = [
        'publisher' => trim((string) ($post['publisher'] ?? '')),
        'customer' => trim((string) ($post['customer'] ?? '')),
        'date' => trim((string) ($post['date'] ?? '')),
        'link' => trim((string) ($post['link'] ?? '')),
    ];

    if (!isset(EPAPER_PUBLISHERS[$values['publisher']])) {
        $errors['publisher'] = 'Bitte einen Verlag auswählen.';
    }

    if ($values['customer'] === '') {
        $errors['customer'] = 'Bitte einen Kundennamen angeben.';
    } elseif (mb_strlen($values['customer']) > 100) {
        $errors['customer'] = 'Der Kundenname darf höchstens 100 Zeichen lang sein.';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['date']);
    if ($date === false || $date->format('Y-m-d') !== $values['date']) {
        $errors['date'] = 'Bitte ein gültiges Erscheinungsdatum wählen.';
    }

    $scheme = strtolower((string) parse_url($values['link'], PHP_URL_SCHEME));
    if ($values['link'] === '') {
        $errors['link'] = 'Bitte einen Link angeben.';
    } elseif (!in_array($scheme, ['http', 'https'], true) || filter_var($values['link'], FILTER_VALIDATE_URL) === false) {
        $errors['link'] = 'Der Link muss eine gültige Adresse sein und mit http:// oder https:// beginnen.';
    }

    foreach (['image_landscape' => 'landscape', 'image_portrait' => 'portrait'] as $field => $orientation) {
        $result = epaper_check_image($files[$field] ?? null, $orientation);
        if (is_string($result)) {
            $errors[$field] = $result;
        } else {
            $values[$field] = $result;
        }
    }

    if (!isset($errors['publisher']) && !isset($errors['customer']) && !isset($errors['date'])) {
        $values['folder'] = epaper_folder_name($values['customer'], $values['date']);
        $values['id'] = $values['publisher'] . '/' . $values['folder'];
    }

    return [$values, $errors];
}

/**
 * @return array{tmp: string, type: string, ext: string, width: int, height: int, size: int, name: string}|string
 *         Bildinfos oder Fehlermeldung
 */
function epaper_check_image(?array $file, string $orientation): array|string
{
    $label = $orientation === 'landscape' ? 'Querformat' : 'Hochformat';
    $maxMb = EPAPER_MAX_IMAGE_BYTES / 1024 / 1024;

    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return "Bitte ein Bild im {$label} hochladen.";
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return "Das Bild ist zu groß (max. {$maxMb} MB).";
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return 'Das Hochladen ist fehlgeschlagen. Bitte erneut versuchen.';
    }
    if ($file['size'] > EPAPER_MAX_IMAGE_BYTES) {
        return "Das Bild ist zu groß (max. {$maxMb} MB).";
    }

    // Dateityp am Inhalt erkennen, nicht an Endung oder Browser-Angabe.
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset(EPAPER_IMAGE_TYPES[$type])) {
        return 'Erlaubt sind nur JPG, PNG, GIF oder WebP.';
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || $info[0] < 1 || $info[1] < 1) {
        return 'Die Datei ist kein lesbares Bild.';
    }
    [$width, $height] = $info;

    if ($orientation === 'landscape' && $width <= $height) {
        return "Das Bild ist kein Querformat ({$width} × {$height} px).";
    }
    if ($orientation === 'portrait' && $height <= $width) {
        return "Das Bild ist kein Hochformat ({$width} × {$height} px).";
    }

    return [
        'tmp' => $file['tmp_name'],
        'type' => $type,
        'ext' => EPAPER_IMAGE_TYPES[$type],
        'width' => $width,
        'height' => $height,
        'size' => (int) $file['size'],
        'name' => (string) $file['name'],
    ];
}

/** Erkennt, ob die Anfrage größer als post_max_size war (dann ist $_POST leer). */
function epaper_post_too_large(): bool
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $length > 0 && $_POST === [] && $_FILES === [];
}

/* ------------------------------------------------------------------------
 * HTML erzeugen und Creative-Ordner anlegen
 * ---------------------------------------------------------------------- */

/** Füllt die HTML-Vorlage. Alle Werte werden für HTML escaped. */
function epaper_render_html(array $values, string $landscapeFile, string $portraitFile): string
{
    $template = file_get_contents(APP_HOME . '/templates/epaper-interstitial.html');
    if ($template === false) {
        throw new RuntimeException('HTML-Vorlage nicht gefunden.');
    }
    return strtr($template, [
        '{{TITLE}}' => h('Anzeige – ' . $values['customer']),
        '{{LINK}}' => h($values['link']),
        '{{BILD_QUER}}' => h($landscapeFile),
        '{{BILD_HOCH}}' => h($portraitFile),
        '{{ALT}}' => h('Anzeige: ' . $values['customer']),
    ]);
}

/**
 * Legt den Creative-Ordner an: index.html + quer.<ext> + hoch.<ext>.
 * Wird erst in einem Temp-Ordner gebaut und dann umbenannt, damit nie ein halber Ordner entsteht.
 */
function epaper_save(array $values, bool $overwrite): void
{
    $id = $values['id'];
    if (!epaper_is_valid_id($id)) {
        throw new RuntimeException('Ungültiger Verlag oder Ordnername.');
    }
    if (!is_dir(EPAPER_CREATIVES_DIR) || !is_writable(EPAPER_CREATIVES_DIR)) {
        throw new RuntimeException('Ablageordner fehlt oder ist nicht beschreibbar: www/epaper/creatives');
    }

    $publisherDir = EPAPER_CREATIVES_DIR . '/' . $values['publisher'];
    if (!is_dir($publisherDir) && !mkdir($publisherDir, 0775) && !is_dir($publisherDir)) {
        throw new RuntimeException('Verlagsordner konnte nicht angelegt werden.');
    }

    $target = EPAPER_CREATIVES_DIR . '/' . $id;
    if (is_dir($target) && !$overwrite) {
        throw new RuntimeException('Ordner existiert bereits.');
    }

    $tmp = $publisherDir . '/.tmp_' . bin2hex(random_bytes(6));
    if (!mkdir($tmp, 0775)) {
        throw new RuntimeException('Temporärer Ordner konnte nicht angelegt werden.');
    }

    $landscape = 'quer.' . $values['image_landscape']['ext'];
    $portrait = 'hoch.' . $values['image_portrait']['ext'];
    $replaced = false;

    try {
        if (!move_uploaded_file($values['image_landscape']['tmp'], "{$tmp}/{$landscape}")
            || !move_uploaded_file($values['image_portrait']['tmp'], "{$tmp}/{$portrait}")) {
            throw new RuntimeException('Bilder konnten nicht gespeichert werden.');
        }
        if (file_put_contents("{$tmp}/index.html", epaper_render_html($values, $landscape, $portrait)) === false) {
            throw new RuntimeException('index.html konnte nicht geschrieben werden.');
        }
        foreach ([$landscape, $portrait, 'index.html'] as $f) {
            chmod("{$tmp}/{$f}", 0664);
        }

        $old = null;
        if (is_dir($target)) {
            $old = $publisherDir . '/.old_' . bin2hex(random_bytes(6));
            rename($target, $old);
            $replaced = true;
        }
        if (!rename($tmp, $target)) {
            if ($old !== null) {
                rename($old, $target);
            }
            throw new RuntimeException('Ordner konnte nicht angelegt werden.');
        }
        if ($old !== null) {
            epaper_remove_dir($old);
        }
    } catch (Throwable $e) {
        epaper_remove_dir($tmp);
        throw $e;
    }

    $now = db_now();
    db_run(
        'INSERT INTO epaper_creatives
            (publisher, folder, customer, pub_date, link,
             landscape_file, landscape_width, landscape_height,
             portrait_file, portrait_width, portrait_height,
             live_url, uploaded_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)
         ON DUPLICATE KEY UPDATE
            customer = VALUES(customer), pub_date = VALUES(pub_date), link = VALUES(link),
            landscape_file = VALUES(landscape_file), landscape_width = VALUES(landscape_width), landscape_height = VALUES(landscape_height),
            portrait_file = VALUES(portrait_file), portrait_width = VALUES(portrait_width), portrait_height = VALUES(portrait_height),
            live_url = NULL, uploaded_at = NULL, upload_error = NULL, -- neue Version muss neu hochgeladen werden
            updated_at = VALUES(updated_at)',
        [
            $values['publisher'], $values['folder'], $values['customer'], $values['date'], $values['link'],
            $landscape, $values['image_landscape']['width'], $values['image_landscape']['height'],
            $portrait, $values['image_portrait']['width'], $values['image_portrait']['height'],
            $now, $now,
        ]
    );

    app_log('epaper', "Creative gespeichert: {$id}" . ($replaced ? ' (überschrieben)' : ''));
}

/** Nur für interne Temp-/Alt-Ordner beim Speichern; es gibt bewusst keine Löschfunktion für Creatives. */
function epaper_remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $path = "{$dir}/{$entry}";
            is_dir($path) ? epaper_remove_dir($path) : unlink($path);
        }
    }
    rmdir($dir);
}

/** Wandelt eine DB-Zeile in das Format um, das die Seiten verwenden. */
function epaper_row_to_item(array $r): array
{
    return [
        'id' => $r['publisher'] . '/' . $r['folder'],
        'publisher' => $r['publisher'],
        'folder' => $r['folder'],
        'customer' => $r['customer'],
        'date' => $r['pub_date'],
        'link' => $r['link'],
        'files' => [
            'html' => 'index.html',
            'landscape' => ['file' => $r['landscape_file'], 'width' => (int) $r['landscape_width'], 'height' => (int) $r['landscape_height']],
            'portrait' => ['file' => $r['portrait_file'], 'width' => (int) $r['portrait_width'], 'height' => (int) $r['portrait_height']],
        ],
        'live_url' => $r['live_url'],
        'uploaded_at' => $r['uploaded_at'],
        'upload_error' => $r['upload_error'] ?? null,
        'created_at' => $r['created_at'],
        'updated_at' => $r['updated_at'],
    ];
}

function epaper_get(string $id): ?array
{
    if (!epaper_is_valid_id($id)) {
        return null;
    }
    [$publisher, $folder] = explode('/', $id, 2);
    $row = db_one('SELECT * FROM epaper_creatives WHERE publisher = ? AND folder = ?', [$publisher, $folder]);
    return $row !== null && epaper_exists($id) ? epaper_row_to_item($row) : null;
}

/* ------------------------------------------------------------------------
 * Übersicht
 * ---------------------------------------------------------------------- */

/** Zeiträume für die Übersicht. */
const EPAPER_PERIODS = [
    'aktuell' => 'Aktuell',   // letzte 30 Tage + kommende
    'kommend' => 'Kommend',   // ab heute
    'alle' => 'Alle',
];

/**
 * Sucht Creatives nach Verlag, Zeitraum und Suchbegriff (seitenweise).
 *
 * @return array{items: list<array>, total: int, counts: array<string, int>}
 *         counts: je Verlag + 'alle', jeweils nach Zeitraum/Suche, aber ohne Verlagsfilter
 */
function epaper_query(string $publisher, string $period, string $search, int $page, int $perPage): array
{
    $where = [];
    $params = [];
    $from = match ($period) {
        'aktuell' => date('Y-m-d', strtotime('-30 days')),
        'kommend' => date('Y-m-d'),
        default => null,
    };
    if ($from !== null) {
        $where[] = 'pub_date >= ?';
        $params[] = $from;
    }
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $where[] = '(customer LIKE ? OR folder LIKE ? OR link LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $counts = ['alle' => 0] + array_fill_keys(array_keys(EPAPER_PUBLISHERS), 0);
    foreach (db_all("SELECT publisher, COUNT(*) AS n FROM epaper_creatives {$whereSql} GROUP BY publisher", $params) as $r) {
        $counts[$r['publisher']] = (int) $r['n'];
        $counts['alle'] += (int) $r['n'];
    }

    if ($publisher !== 'alle') {
        $whereSql .= ($whereSql ? ' AND ' : 'WHERE ') . 'publisher = ?';
        $params[] = $publisher;
    }
    $total = $publisher === 'alle' ? $counts['alle'] : ($counts[$publisher] ?? 0);

    // "Kommend" chronologisch aufsteigend (nächster Termin oben), sonst neueste zuerst.
    $order = $period === 'kommend' ? 'pub_date ASC, updated_at ASC' : 'pub_date DESC, updated_at DESC';
    $offset = max(0, ($page - 1) * $perPage);
    $rows = db_all("SELECT * FROM epaper_creatives {$whereSql} ORDER BY {$order} LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset, $params);

    return ['items' => array_map('epaper_row_to_item', $rows), 'total' => $total, 'counts' => $counts];
}

/** Erstellt ein ZIP des Creative-Ordners (für manuellen Upload). Gibt den Pfad zur Temp-Datei zurück. */
function epaper_zip(string $id): string
{
    $dir = EPAPER_CREATIVES_DIR . '/' . $id;
    $zipPath = tempnam(sys_get_temp_dir(), 'epaper_');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP konnte nicht erstellt werden.');
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if (is_file("{$dir}/{$entry}")) {
            // Gleiche Struktur wie auf dem Live-Server: <verlag>/<ordner>/<datei>
            $zip->addFile("{$dir}/{$entry}", "{$id}/{$entry}");
        }
    }
    $zip->close();
    return $zipPath;
}

/** Merkt sich den Verlag (bzw. "alle") für Formular und Übersicht, 1 Jahr. */
function epaper_remember_publisher(string $value): void
{
    if ($value === 'alle' || isset(EPAPER_PUBLISHERS[$value])) {
        setcookie('epaper_publisher', $value, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/* ------------------------------------------------------------------------
 * Live-Server
 * ---------------------------------------------------------------------- */

/**
 * Lädt ein Creative auf den Live-Server (epaper/<verlag>/<ordner>/…).
 * Reihenfolge: Bilder zuerst, index.html zuletzt – so ist die Seite nie ohne Bilder erreichbar.
 *
 * @return string|null Fehlermeldung oder null bei Erfolg
 */
function epaper_publish(string $id): ?string
{
    $item = epaper_get($id);
    if ($item === null) {
        return 'Creative nicht gefunden.';
    }
    if (!live_enabled()) {
        return 'Live-Server ist noch nicht eingerichtet.';
    }
    [$publisher, $folder] = explode('/', $id, 2);
    $local = EPAPER_CREATIVES_DIR . '/' . $id;
    try {
        foreach ([$item['files']['landscape']['file'], $item['files']['portrait']['file'], 'index.html'] as $file) {
            live_put_file("epaper/{$id}/{$file}", "{$local}/{$file}");
        }
        db_run('UPDATE epaper_creatives SET live_url = ?, uploaded_at = ?, upload_error = NULL WHERE publisher = ? AND folder = ?',
            [live_url("epaper/{$id}/index.html"), db_now(), $publisher, $folder]);
        app_log('epaper', "Live hochgeladen: {$id}");
        return null;
    } catch (Throwable $e) {
        db_run('UPDATE epaper_creatives SET upload_error = ? WHERE publisher = ? AND folder = ?',
            [mb_substr($e->getMessage(), 0, 500), $publisher, $folder]);
        app_log('epaper', "Upload fehlgeschlagen ({$id}): " . $e->getMessage());
        return $e->getMessage();
    }
}

/** Lädt alle noch nicht hochgeladenen Creatives hoch (für den Cronjob). Gibt [ok, fehler] zurück. */
function epaper_sync(int $limit = 50): array
{
    if (!live_enabled()) {
        return [0, 0];
    }
    $ok = $failed = 0;
    foreach (db_all('SELECT publisher, folder FROM epaper_creatives WHERE live_url IS NULL ORDER BY pub_date LIMIT ' . (int) $limit) as $r) {
        epaper_publish($r['publisher'] . '/' . $r['folder']) === null ? $ok++ : $failed++;
        if ($failed >= 3 && $ok === 0) {
            break; // Server offenbar nicht erreichbar – nicht weiter probieren.
        }
    }
    return [$ok, $failed];
}

