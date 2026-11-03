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
