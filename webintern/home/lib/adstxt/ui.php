<?php
declare(strict_types=1);

/**
 * Oberflächen-Helfer für den Ads.txt Organizer (Layout, Navigation, Meldungen, Formularfelder).
 */

const ADSTXT_PAGES = [
    'uebersicht' => ['Übersicht', 'grid'],
    'publisher' => ['Publisher', 'building'],
    'quellen' => ['Quellen', 'link'],
    'eintraege' => ['Einträge', 'list'],
    'vorschau' => ['Vorschau', 'file'],
    'abgleich' => ['Abgleich', 'signal'],
    'protokoll' => ['Protokoll', 'clock'],
];

function ui_url(string $page, array $params = []): string
{
    return '?' . http_build_query(['seite' => $page] + array_filter($params, static fn ($v) => $v !== null && $v !== ''));
}

/** Leitet nach POST um (Post/Redirect/Get) und merkt sich eine Meldung. */
function ui_redirect(string $page, array $params = [], ?string $message = null, string $type = 'success'): never
{
    if ($message !== null) {
        $_SESSION['flash'] = ['type' => $type, 'text' => $message];
    }
    header('Location: ' . ui_url($page, $params), true, 303);
    exit;
}

function ui_flash(): string
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$flash) {
        return '';
    }
    $cls = $flash['type'] === 'error' ? 'pd-alert--error' : ($flash['type'] === 'warning' ? 'pd-alert--warning' : 'pd-alert--success');
    return '<div class="pd-alert ' . $cls . '" role="status" aria-live="polite">' . h($flash['text']) . '</div>';
}

/** Einfache Linien-Symbole (24×24, currentColor). */
function ui_icon(string $name): string
{
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1M10 21v-3h4v3"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'file' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'signal' => '<rect x="7" y="2.5" width="10" height="19" rx="3"/><circle cx="12" cy="7" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="17" r="1.6"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.9-3M4 4v4h4M4 13a8 8 0 0 0 14.9 3M20 20v-4h-4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'logout' => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 16l-4-4 4-4M6 12h10"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

function ui_badge(?string $status, ?string $text = null): string
{
    $labels = ['ok' => 'OK', 'warning' => 'Warnung', 'error' => 'Fehler', 'running' => 'Läuft', 'off' => 'Inaktiv', null => 'Noch nicht abgerufen'];
    $cls = match ($status) {
        'ok' => 'ok', 'warning' => 'warning', 'error' => 'error', 'running' => 'info', default => 'off',
    };
    return '<span class="pd-badge pd-badge--' . $cls . '">' . h($text ?? $labels[$status] ?? (string) $status) . '</span>';
}

/** Ampel für den Abgleich (Farbe + Text, nie nur Farbe). */
function ui_check_badge(?string $status): string
{
    return match ($status) {
        'ok' => ui_badge('ok', 'Aktuell'),
        'outdated' => ui_badge('warning', 'Veraltet'),
        'error' => ui_badge('error', 'Problem'),
        'skipped' => ui_badge('off', 'Nicht prüfbar'),
        default => ui_badge('off', 'Noch nicht geprüft'),
    };
}

function ui_scope(?string $publisherName): string
{
    return $publisherName === null
        ? '<span class="pd-badge pd-badge--info">Alle Publisher</span>'
        : h($publisherName);
}

function ui_date(?string $dt, bool $withTime = true): string
{
    return $dt ? date_de(strtotime($dt), $withTime) : '—';
}

function ui_field_class(array $errors, string $field): string
{
    return isset($errors[$field]) ? 'pd-field is-invalid' : 'pd-field';
}

function ui_field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<span class="pd-error-text" id="err-' . h($field) . '">' . h($errors[$field]) . '</span>' : '';
}

function ui_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Auswahl Geltungsbereich (alle oder ein Publisher). */
function ui_scope_select(?int $selected, array $publishers, array $errors): string
{
    $html = '<div class="' . ui_field_class($errors, 'publisher_id') . '"><label class="pd-label" for="publisher_id">Gilt für</label>'
        . '<select class="pd-input pd-select" id="publisher_id" name="publisher_id">'
        . '<option value="">Alle Publisher (global)</option>';
    foreach ($publishers as $p) {
        $html .= '<option value="' . (int) $p['id'] . '"' . ($selected === (int) $p['id'] ? ' selected' : '') . '>'
            . h($p['name']) . ($p['active'] ? '' : ' (inaktiv)') . '</option>';
    }
    return $html . '</select>' . ui_field_error($errors, 'publisher_id') . '</div>';
}

/** Auswahl Dateityp als Radio-Gruppe. */
function ui_type_radios(string $selected, array $errors): string
{
    $html = '<fieldset class="' . ui_field_class($errors, 'file_type') . '" style="border:0;padding:0;margin:0">'
        . '<legend class="pd-label" style="margin-bottom:6px">Datei</legend><div class="pd-checks">';
    foreach (ADSTXT_FILE_TYPES as $value => $label) {
        $html .= '<label class="pd-check"><input type="radio" name="file_type" value="' . h($value) . '"'
            . ($selected === $value ? ' checked' : '') . '> ' . h($label) . '</label>';
    }
    return $html . '</div>' . ui_field_error($errors, 'file_type') . '</fieldset>';
}

/** Seitenrahmen öffnen. */
function ui_start(string $active, string $title, array $crumbs = [], string $barRight = ''): void
{
    $warn = (int) db_value("SELECT COUNT(*) FROM adstxt_sources WHERE active = 1 AND last_status IN ('warning','error')");
    $checkCounts = adstxt_check_counts();
    $checkWarn = $checkCounts['outdated'] + $checkCounts['error'];
    ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($title) ?> · Ads.txt Organizer</title>
<link rel="stylesheet" href="../assets/pd/pd.css">
<style>
  .pd-alert--warning { background: #FFF6E5; border-color: var(--pd-amber); color: #6B4100; }
  .pd-live { display: flex; flex-direction: column; gap: 6px; padding: var(--space-3); background: var(--surface-sunken); border-radius: var(--radius-control); }
  .pd-live__row { display: flex; align-items: center; gap: var(--space-2); }
  .pd-live__url { flex: 1; min-width: 0; overflow-wrap: anywhere; }
</style>
</head>
<body>
<div class="pd-app">
  <aside class="pd-side" aria-label="Hauptnavigation">
    <div class="pd-side__brand">
      <img src="../assets/pd/pd-digital-logo.svg" alt="pd digital">
      <div><b>Ads.txt Organizer</b><span>Creative-Tools</span></div>
    </div>
    <nav class="pd-nav">
      <?php foreach (ADSTXT_PAGES as $key => [$label, $icon]): ?>
        <a href="<?= h(ui_url($key)) ?>" class="<?= $key === $active ? 'is-active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
          <?= ui_icon($icon) ?><span><?= h($label) ?></span>
          <?php if ($key === 'quellen' && $warn > 0): ?><span class="pd-nav__count pd-nav__count--warn" title="Quellen mit Warnung oder Fehler"><?= $warn ?></span><?php endif; ?>
          <?php if ($key === 'abgleich' && $checkWarn > 0): ?><span class="pd-nav__count pd-nav__count--warn" title="Verlage mit veralteter oder fehlender Datei"><?= $checkWarn ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="pd-side__foot">
      <form method="post" action="<?= h(ui_url('uebersicht')) ?>"><?= ui_csrf() ?>
        <button class="pd-btn pd-btn--ghost pd-btn--sm" name="action" value="logout"><?= ui_icon('logout') ?> Abmelden</button>
      </form>
    </div>
  </aside>
  <div class="pd-main">
    <header class="pd-bar">
      <nav class="pd-crumb" aria-label="Brotkrumen">
        <span>Ads.txt</span>
        <?php foreach ($crumbs as $i => [$label, $url]): ?>
          <span aria-hidden="true">›</span>
          <?php if ($url && $i < count($crumbs) - 1): ?><a href="<?= h($url) ?>"><?= h($label) ?></a><?php else: ?><b><?= h($label) ?></b><?php endif; ?>
        <?php endforeach; ?>
      </nav>
      <div class="pd-bar__right"><?= $barRight ?></div>
    </header>
    <main class="pd-page">
      <?= ui_flash() ?>
    <?php
}

function ui_end(): void
{
    ?>
    </main>
  </div>
</div>
<script>
// Ladezustand für Buttons mit data-busy, Rückfrage für data-confirm.
document.querySelectorAll('[data-copy]').forEach((btn) => btn.addEventListener('click', async () => {
  const label = btn.textContent;
  try { await navigator.clipboard.writeText(btn.dataset.copy); btn.textContent = 'Kopiert'; }
  catch { window.prompt('Adresse kopieren:', btn.dataset.copy); }
  setTimeout(() => { btn.textContent = label; }, 1500);
}));
document.addEventListener('submit', (e) => {
  const btn = e.submitter;
  if (btn && btn.dataset.confirm && !confirm(btn.dataset.confirm)) { e.preventDefault(); return; }
  if (btn && btn.dataset.busy) { btn.classList.add('is-busy'); btn.lastChild.textContent = ' ' + btn.dataset.busy; setTimeout(() => btn.disabled = true, 0); }
});
</script>
</body>
</html>
    <?php
}

/** Button „Jetzt aktualisieren“ für die Kopfzeile. */
function ui_run_button(string $returnPage = 'uebersicht'): string
{
    $params = $returnPage === 'vorschau' && isset($_GET['id']) ? ['id' => (int) $_GET['id']] : [];
    return '<form method="post" action="' . h(ui_url($returnPage, $params)) . '">' . ui_csrf()
        . '<input type="hidden" name="return" value="' . h($returnPage) . '">'
        . '<button class="pd-btn pd-btn--primary pd-btn--sm" name="action" value="run" data-busy="Aktualisiere … (bis zu 1 Min.)">'
        . ui_icon('refresh') . '<span> Jetzt aktualisieren</span></button></form>';
}
