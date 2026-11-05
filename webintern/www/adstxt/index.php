<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/home/lib/bootstrap.php';
require APP_HOME . '/lib/auth.php';
require APP_HOME . '/lib/adstxt/service.php';
require APP_HOME . '/lib/adstxt/ui.php';
require APP_HOME . '/lib/adstxt/pages.php';

auth_require('adstxt', 'Ads.txt Organizer');

$page = (string) ($_GET['seite'] ?? 'uebersicht');
$id = isset($_GET['id']) && $_GET['id'] !== '' ? (int) $_GET['id'] : null;

/* ------------------------------------------------------------------------
 * Download (vor jeder HTML-Ausgabe)
 * ---------------------------------------------------------------------- */
if ($page === 'download') {
    $type = (string) ($_GET['typ'] ?? 'ads');
    $publisher = $id ? adstxt_publisher($id) : null;
    $output = $publisher && isset(ADSTXT_FILE_NAMES[$type]) ? adstxt_output($publisher['id'], $type) : null;
    if ($output === null) {
        http_response_code(404);
        exit('Datei nicht gefunden.');
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $publisher['slug'] . '-' . ADSTXT_FILE_NAMES[$type] . '"');
    header('X-Content-Type-Options: nosniff');
    echo $output['content'];
    exit;
}

/* ------------------------------------------------------------------------
 * Aktionen (POST)
 * ---------------------------------------------------------------------- */
$errors = [];
$form = null;        // zuletzt gesendete Formularwerte (bei Fehlern wieder anzeigen)
$analysis = null;    // Prüfergebnis für Einträge

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'run':
            @set_time_limit(300);
            ignore_user_abort(true);
            try {
                $r = adstxt_run('manual');
                $msg = sprintf('Aktualisierung abgeschlossen: %d Datei(en), davon %d geändert. Quellen: %d ok, %d Warnung, %d Fehler.',
                    $r['files'], count($r['changed']), $r['sources']['ok'], $r['sources']['warning'], $r['sources']['error']);
                $return = in_array($_POST['return'] ?? '', ['uebersicht', 'vorschau'], true) ? $_POST['return'] : 'uebersicht';
                ui_redirect($return, $return === 'vorschau' ? ['id' => $id] : [], $msg, $r['status'] === 'ok' ? 'success' : 'warning');
            } catch (Throwable $e) {
                ui_redirect('uebersicht', [], $e->getMessage(), 'error');
            }

        case 'publisher_save':
            [$newId, $errors] = adstxt_publisher_save($_POST, $id);
            if (!$errors) {
                ui_redirect('publisher-bearbeiten', ['id' => $newId], 'Publisher gespeichert. Die Dateien werden beim nächsten Lauf aktualisiert.');
            }
            $form = $_POST;
            $page = 'publisher-bearbeiten';
            break;

        case 'publisher_toggle':
            if ($id && ($p = adstxt_publisher($id))) {
                adstxt_publisher_set_active($id, !$p['active']);
                ui_redirect('publisher-bearbeiten', ['id' => $id], $p['active'] ? 'Publisher deaktiviert – für ihn werden keine Dateien mehr erzeugt.' : 'Publisher wieder aktiviert.');
            }
            break;

        case 'source_save':
        case 'source_save_fetch':
            [$newId, $errors] = adstxt_source_save($_POST, $id);
            if (!$errors) {
                $msg = 'Quelle gespeichert.';
                if ($action === 'source_save_fetch') {
                    $check = adstxt_fetch_source(adstxt_source($newId));
                    $msg .= ' Abruf: ' . $check['message'] . '.';
                    ui_redirect('quelle-bearbeiten', ['id' => $newId], $msg, $check['status'] === 'ok' ? 'success' : ($check['status'] === 'warning' ? 'warning' : 'error'));
                }
                ui_redirect('quelle-bearbeiten', ['id' => $newId], $msg);
            }
            $form = $_POST;
            $page = 'quelle-bearbeiten';
            break;

        case 'source_fetch':
            if ($id && ($s = adstxt_source($id))) {
                $check = adstxt_fetch_source($s);
                $return = (string) ($_POST['return'] ?? 'quellen');
                ui_redirect($return === 'quelle-bearbeiten' ? $return : 'quellen', ['id' => $return === 'quelle-bearbeiten' ? $id : null],
                    "„{$s['name']}“: {$check['message']}", $check['status'] === 'ok' ? 'success' : ($check['status'] === 'warning' ? 'warning' : 'error'));
            }
            break;

        case 'source_delete':
            if ($id && ($s = adstxt_source($id))) {
                adstxt_delete('adstxt_sources', $id);
                ui_redirect('quellen', [], "Quelle „{$s['name']}“ gelöscht.");
            }
            break;

        case 'block_check':
            $form = $_POST;
            $analysis = adstxt_analyze((string) ($_POST['content'] ?? ''));
            $page = 'eintrag-bearbeiten';
            break;

        case 'block_save':
            [$newId, $errors, $analysis] = adstxt_block_save($_POST, $id);
            if (!$errors) {
                $msg = 'Eintrag gespeichert.' . ($analysis['invalid'] ? ' ' . count($analysis['invalid']) . ' ungültige Zeile(n) werden ignoriert.' : '');
                ui_redirect('eintrag-bearbeiten', ['id' => $newId], $msg, $analysis['invalid'] ? 'warning' : 'success');
            }
            $form = $_POST;
            $page = 'eintrag-bearbeiten';
            break;

        case 'block_delete':
            if ($id && ($b = adstxt_block($id))) {
                adstxt_delete('adstxt_blocks', $id);
                ui_redirect('eintraege', [], "Eintrag „{$b['name']}“ gelöscht.");
            }
            break;
    }
}

/* ------------------------------------------------------------------------
 * Seiten
 * ---------------------------------------------------------------------- */
match ($page) {
    'publisher' => page_publishers(),
    'publisher-bearbeiten' => page_publisher_form($id, $form, $errors),
    'quellen' => page_sources(),
    'quelle-bearbeiten' => page_source_form($id, $form, $errors),
    'quelle-inhalt' => page_source_content($id),
    'eintraege' => page_blocks(),
    'eintrag-bearbeiten' => page_block_form($id, $form, $errors, $analysis),
    'vorschau' => page_preview($id, (string) ($_GET['typ'] ?? 'ads')),
    'protokoll' => page_log(),
    default => page_overview(),
};
