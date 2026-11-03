<?php
declare(strict_types=1);

/**
 * ePaper-Werbezwischenseiten: Validierung der Formulareingaben.
 * (Schritt 1. HTML-Erzeugung und Speichern folgen in Schritt 2.)
 */

const EPAPER_MAX_IMAGE_BYTES = 10 * 1024 * 1024; // 10 MB pro Bild
const EPAPER_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

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
    $slug = substr($slug, 0, 60);
    return $date . '_' . ($slug !== '' ? $slug : 'kunde');
}

/**
 * Prüft das Formular. Gibt [Werte, Fehler] zurück; Fehler ist ein Array feld => Meldung.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function epaper_validate(array $post, array $files): array
{
    $errors = [];
    $values = [
        'customer' => trim((string) ($post['customer'] ?? '')),
        'date' => trim((string) ($post['date'] ?? '')),
        'link' => trim((string) ($post['link'] ?? '')),
    ];

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

    if (!isset($errors['customer']) && !isset($errors['date'])) {
        $values['folder'] = epaper_folder_name($values['customer'], $values['date']);
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

    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return "Bitte ein Bild im {$label} hochladen.";
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return 'Das Bild ist zu groß (max. ' . (EPAPER_MAX_IMAGE_BYTES / 1024 / 1024) . ' MB).';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return 'Das Hochladen ist fehlgeschlagen. Bitte erneut versuchen.';
    }
    if ($file['size'] > EPAPER_MAX_IMAGE_BYTES) {
        return 'Das Bild ist zu groß (max. ' . (EPAPER_MAX_IMAGE_BYTES / 1024 / 1024) . ' MB).';
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
 * Schritt 2: HTML erzeugen und Creative-Ordner anlegen
 * ---------------------------------------------------------------------- */

/** Öffentlicher Ablageort (im Webroot, damit die Vorschau direkt aufrufbar ist). */
const EPAPER_CREATIVES_DIR = APP_HOME . '/../www/epaper/creatives';
/** URL relativ zu www/epaper/. */
const EPAPER_CREATIVES_URL = 'creatives';
/** Metadaten (nicht öffentlich). */
const EPAPER_META_DIR = 'epaper';
const EPAPER_FOLDER_PATTERN = '/^\d{4}-\d{2}-\d{2}_[a-z0-9-]{1,60}$/';

function epaper_is_valid_folder(string $folder): bool
{
    return preg_match(EPAPER_FOLDER_PATTERN, $folder) === 1;
}

function epaper_folder_exists(string $folder): bool
{
    return epaper_is_valid_folder($folder) && is_dir(EPAPER_CREATIVES_DIR . '/' . $folder);
}

/** Füllt die HTML-Vorlage. Alle Werte werden für HTML-Attribute escaped. */
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
    $folder = $values['folder'];
    if (!epaper_is_valid_folder($folder)) {
        throw new RuntimeException('Ungültiger Ordnername.');
    }
    if (!is_dir(EPAPER_CREATIVES_DIR) || !is_writable(EPAPER_CREATIVES_DIR)) {
        throw new RuntimeException('Ablageordner fehlt oder ist nicht beschreibbar: www/epaper/creatives');
    }

    $target = EPAPER_CREATIVES_DIR . '/' . $folder;
    if (is_dir($target) && !$overwrite) {
        throw new RuntimeException('Ordner existiert bereits.');
    }

    $tmp = EPAPER_CREATIVES_DIR . '/.tmp_' . bin2hex(random_bytes(6));
    if (!mkdir($tmp, 0775)) {
        throw new RuntimeException('Temporärer Ordner konnte nicht angelegt werden.');
    }

    try {
        $landscape = 'quer.' . $values['image_landscape']['ext'];
        $portrait = 'hoch.' . $values['image_portrait']['ext'];

        if (!move_uploaded_file($values['image_landscape']['tmp'], "{$tmp}/{$landscape}")
            || !move_uploaded_file($values['image_portrait']['tmp'], "{$tmp}/{$portrait}")) {
            throw new RuntimeException('Bilder konnten nicht gespeichert werden.');
        }
        chmod("{$tmp}/{$landscape}", 0664);
        chmod("{$tmp}/{$portrait}", 0664);

        if (file_put_contents("{$tmp}/index.html", epaper_render_html($values, $landscape, $portrait)) === false) {
            throw new RuntimeException('index.html konnte nicht geschrieben werden.');
        }
        chmod("{$tmp}/index.html", 0664);

        $old = null;
        if (is_dir($target)) {
            $old = EPAPER_CREATIVES_DIR . '/.old_' . bin2hex(random_bytes(6));
            rename($target, $old);
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

    $previous = read_json(EPAPER_META_DIR . "/{$folder}.json", []);
    write_json(EPAPER_META_DIR . "/{$folder}.json", [
        'folder' => $folder,
        'customer' => $values['customer'],
        'date' => $values['date'],
        'link' => $values['link'],
        'files' => [
            'html' => 'index.html',
            'landscape' => ['file' => $landscape, 'width' => $values['image_landscape']['width'], 'height' => $values['image_landscape']['height']],
            'portrait' => ['file' => $portrait, 'width' => $values['image_portrait']['width'], 'height' => $values['image_portrait']['height']],
        ],
        'created_at' => $previous['created_at'] ?? date('c'),
        'updated_at' => date('c'),
        // Schritt 3: wird nach dem Upload auf den Live-Server gesetzt.
        'live_url' => null,
        'uploaded_at' => null,
    ]);

    app_log('epaper', "Creative gespeichert: {$folder}" . ($old !== null ? ' (überschrieben)' : ''));
}

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

function epaper_get(string $folder): ?array
{
    if (!epaper_folder_exists($folder)) {
        return null;
    }
    $meta = read_json(EPAPER_META_DIR . "/{$folder}.json", null);
    return is_array($meta) ? $meta : null;
}

/** Alle Creatives, neuestes Erscheinungsdatum zuerst. */
function epaper_list(): array
{
    $items = [];
    foreach (glob(APP_DATA . '/' . EPAPER_META_DIR . '/*.json') ?: [] as $file) {
        $folder = basename($file, '.json');
        if ($meta = epaper_get($folder)) {
            $items[] = $meta;
        }
    }
    usort($items, static fn ($a, $b) => [$b['date'], $b['updated_at']] <=> [$a['date'], $a['updated_at']]);
    return $items;
}

/** Erstellt ein ZIP des Creative-Ordners (für manuellen Upload). Gibt den Pfad zur Temp-Datei zurück. */
function epaper_zip(string $folder): string
{
    $dir = EPAPER_CREATIVES_DIR . '/' . $folder;
    $zipPath = tempnam(sys_get_temp_dir(), 'epaper_');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP konnte nicht erstellt werden.');
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if (is_file("{$dir}/{$entry}")) {
            $zip->addFile("{$dir}/{$entry}", "{$folder}/{$entry}");
        }
    }
    $zip->close();
    return $zipPath;
}
