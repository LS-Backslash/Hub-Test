<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/home/lib/bootstrap.php';
require APP_HOME . '/lib/auth.php';   // nur für CSRF-Schutz, kein Login nötig
require APP_HOME . '/lib/epaper.php';

auth_start_session(); // vor jeder Ausgabe, damit das CSRF-Token in der Sitzung landet

// Zuletzt gewählter Verlag (Cookie, gemeinsam mit der Übersicht) als Vorauswahl.
$rememberedPublisher = (string) ($_COOKIE['epaper_publisher'] ?? '');
$values = [
    'publisher' => isset(EPAPER_PUBLISHERS[$rememberedPublisher]) ? $rememberedPublisher : '',
    'customer' => '', 'date' => '', 'link' => '',
];
$errors = [];
$folderExists = false;

if (epaper_post_too_large()) {
    $errors['_form'] = 'Die hochgeladenen Dateien sind zusammen zu groß.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'publish') {
    // "Erneut hochladen" für ein bereits gespeichertes Creative.
    csrf_check();
    $id = (string) ($_POST['id'] ?? '');
    if (epaper_exists($id)) {
        epaper_publish($id);
    }
    $back = ($_POST['return'] ?? '') === 'list' ? 'creatives.php' : '?saved=' . rawurlencode($id);
    header('Location: ' . $back, true, 303);
    exit;
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    [$values, $errors] = epaper_validate($_POST, $_FILES);
    $overwrite = ($_POST['overwrite'] ?? '') === '1';

    if ($errors === [] && epaper_exists($values['id']) && !$overwrite) {
        $folderExists = true;
        $errors['_form'] = "Den Ordner „{$values['id']}“ gibt es schon. Zum Ersetzen „Vorhandenen Ordner überschreiben“ anhaken und die Bilder erneut auswählen.";
    }

    if ($errors === []) {
        try {
            epaper_save($values, $overwrite);
            epaper_remember_publisher($values['publisher']);
            if (live_enabled()) {
                @set_time_limit(120);
                epaper_publish($values['id']); // Ergebnis steht danach am Creative (live_url bzw. upload_error)
            }
            // Post/Redirect/Get: Neuladen der Seite sendet das Formular nicht erneut.
            header('Location: ?saved=' . rawurlencode($values['id']), true, 303);
            exit;
        } catch (Throwable $e) {
            error_log('epaper_save: ' . $e->getMessage());
            $errors['_form'] = 'Speichern fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

$saved = isset($_GET['saved']) ? epaper_get((string) $_GET['saved']) : null;
$success = $saved;

$maxMb = EPAPER_MAX_IMAGE_BYTES / 1024 / 1024;

function field_class(array $errors, string $field): string
{
    return isset($errors[$field]) ? 'pd-field is-invalid' : 'pd-field';
}

function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<span class="pd-error-text" id="err-' . h($field) . '">' . h($errors[$field]) . '</span>' : '';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Template Generator · ePaper Werbezwischenseiten</title>
<link rel="stylesheet" href="../assets/pd/pd.css">
<style>
  .ep-preview-head { background: var(--pd-navy); color: var(--pd-white); padding: 10px var(--space-4); font-size: var(--fs-micro); letter-spacing: .12em; text-transform: uppercase; font-weight: 500; }
  .ep-preview { border: 1px solid var(--border); border-radius: var(--radius-control); overflow: hidden; }
  .ep-preview__body { padding: var(--space-4); display: flex; flex-direction: column; gap: var(--space-4); }
  .ep-images { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); }
  .ep-slot { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
  .ep-box { aspect-ratio: 1; background: var(--surface-sunken); border: 1px dashed var(--border-strong); border-radius: var(--radius-control);
            display: grid; place-items: center; overflow: hidden; color: var(--text-faint); font-size: var(--fs-sm); }
  .ep-box img { width: 100%; height: 100%; object-fit: contain; display: block; }
  .ep-box.has-image { border-style: solid; background: repeating-conic-gradient(#f0f0ef 0 25%, #fafafa 0 50%) 0 0 / 16px 16px; }
  .ep-meta { font-size: 12px; color: var(--text-muted); min-height: 18px; }
  .ep-meta.is-warn { color: var(--danger); }
  .ep-link { background: var(--surface-sunken); border: 1px solid var(--border); border-radius: var(--radius-control); padding: 10px 12px; font-size: var(--fs-sm); word-break: break-all; }
  .ep-link:empty::before { content: "Link wird hier angezeigt …"; color: var(--text-faint); font-style: italic; }
  .ep-frame { display: block; width: 100%; height: 420px; border: 0; border-bottom: 1px solid var(--border); background: var(--pd-white); }
  .ep-live { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-3); }
  .ep-live a { word-break: break-all; }
  .ep-check { display: flex; align-items: center; gap: var(--space-2); font-size: var(--fs-sm); color: var(--text-strong); }
  .ep-check input { width: 18px; height: 18px; accent-color: var(--pd-blue); }
</style>
</head>
<body>

<header class="pd-top">
  <div class="pd-top__inner">
    <img class="pd-top__logo" src="../assets/pd/pd-digital-logo.svg" alt="pd digital">
    <nav class="pd-crumb" aria-label="Brotkrumen">
      <span>Creative-Tools</span><span aria-hidden="true">›</span><b>Template Generator</b>
    </nav>
    <div class="pd-top__right"><a href="creatives.php">Alle Creatives anzeigen →</a></div>
  </div>
</header>

<main class="pd-page">
  <div>
    <div class="pd-over">ePaper</div>
    <h1>Werbezwischenseite erstellen</h1>
  </div>

  <?php if ($success): ?>
    <div class="pd-alert pd-alert--success" role="status">
      <strong>Creative gespeichert:</strong> <?= h(epaper_publisher_name($success['publisher'])) ?> · <code><?= h($success['id']) ?></code> ·
      <a href="<?= h(EPAPER_CREATIVES_URL . '/' . $success['id'] . '/index.html') ?>" target="_blank" rel="noopener">Vorschau öffnen</a> ·
      <a href="zip.php?id=<?= h(rawurlencode($success['id'])) ?>">ZIP herunterladen</a>
    </div>
    <?php if ($success['live_url']): ?>
      <div class="pd-alert pd-alert--success ep-live" role="status">
        <strong>Live:</strong>
        <a href="<?= h($success['live_url']) ?>" target="_blank" rel="noopener" id="ep-live-url"><?= h($success['live_url']) ?></a>
        <button type="button" class="pd-btn pd-btn--secondary pd-btn--sm" data-copy="<?= h($success['live_url']) ?>">Adresse kopieren</button>
      </div>
    <?php elseif (live_enabled()): ?>
      <div class="pd-alert pd-alert--error ep-live" role="alert">
        <strong>Noch nicht live:</strong> <?= h($success['upload_error'] ?: 'Upload ausstehend.') ?>
        Der Cronjob versucht es automatisch erneut.
        <form method="post" style="display:inline"><?= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">' ?>
          <input type="hidden" name="id" value="<?= h($success['id']) ?>">
          <button class="pd-btn pd-btn--secondary pd-btn--sm" name="action" value="publish">Jetzt erneut hochladen</button>
        </form>
      </div>
    <?php else: ?>
      <div class="pd-alert ep-live" style="background:var(--pd-grey-100);border-color:var(--border-strong)">Live-Server ist noch nicht eingerichtet – das Creative liegt nur lokal.</div>
    <?php endif; ?>
  <?php elseif ($errors): ?>
    <div class="pd-alert pd-alert--error" role="alert">
      <strong>Bitte die Angaben prüfen.</strong>
      <?php if (isset($errors['_form'])): ?><ul><li><?= h($errors['_form']) ?></li></ul><?php endif; ?>
      Bilder müssen nach einem Fehler erneut ausgewählt werden.
    </div>
  <?php endif; ?>

  <div class="pd-grid-2">
    <!-- Eingabe -->
    <section class="pd-card" aria-labelledby="h-input">
      <div class="pd-card__head"><h2 class="pd-over" id="h-input">Eingabe</h2></div>
      <form class="pd-card__body" method="post" enctype="multipart/form-data" id="ep-form" novalidate>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <div class="<?= field_class($errors, 'publisher') ?>">
          <label class="pd-label" for="publisher">Verlag</label>
          <select class="pd-input pd-select" id="publisher" name="publisher" required>
            <option value="" disabled<?= $values['publisher'] === '' ? ' selected' : '' ?>>Bitte wählen …</option>
            <?php foreach (EPAPER_PUBLISHERS as $slug => $p): ?>
              <option value="<?= h($slug) ?>"<?= $values['publisher'] === $slug ? ' selected' : '' ?>><?= h($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'publisher') ?>
        </div>

        <div class="pd-row">
          <div class="<?= field_class($errors, 'customer') ?>">
            <label class="pd-label" for="customer">Kunden-Name</label>
            <input class="pd-input" id="customer" name="customer" type="text" maxlength="100" required
                   placeholder="Kundenname" value="<?= h($values['customer']) ?>" autocomplete="off">
            <?= field_error($errors, 'customer') ?>
          </div>
          <div class="<?= field_class($errors, 'date') ?>">
            <label class="pd-label" for="date">Erscheinungsdatum</label>
            <input class="pd-input" id="date" name="date" type="date" required value="<?= h($values['date']) ?>">
            <?= field_error($errors, 'date') ?>
          </div>
        </div>

        <div class="<?= field_class($errors, 'link') ?>">
          <label class="pd-label" for="link">Link <small>(muss mit http:// oder https:// beginnen)</small></label>
          <input class="pd-input" id="link" name="link" type="url" required inputmode="url"
                 placeholder="https://beispiel.de" value="<?= h($values['link']) ?>" autocomplete="off">
          <?= field_error($errors, 'link') ?>
        </div>

        <div class="<?= field_class($errors, 'image_landscape') ?>">
          <label class="pd-label" for="image_landscape">Bild Querformat <small>($$$BILD_QUER$$$)</small></label>
          <input class="pd-file" id="image_landscape" name="image_landscape" type="file" required
                 accept="image/jpeg,image/png,image/gif,image/webp" data-orientation="landscape" data-target="prev-landscape">
          <?= field_error($errors, 'image_landscape') ?>
        </div>

        <div class="<?= field_class($errors, 'image_portrait') ?>">
          <label class="pd-label" for="image_portrait">Bild Hochformat <small>($$$BILD_HOCH$$$)</small></label>
          <input class="pd-file" id="image_portrait" name="image_portrait" type="file" required
                 accept="image/jpeg,image/png,image/gif,image/webp" data-orientation="portrait" data-target="prev-portrait">
          <?= field_error($errors, 'image_portrait') ?>
        </div>

        <span class="pd-hint">JPG, PNG, GIF oder WebP, je max. <?= (int) $maxMb ?> MB.</span>

        <div class="pd-code" id="folder">Ordner: —</div>

        <?php if ($folderExists): ?>
          <label class="ep-check"><input type="checkbox" name="overwrite" value="1"> Vorhandenen Ordner überschreiben</label>
        <?php endif; ?>

        <button class="pd-btn pd-btn--primary pd-btn--block" type="submit">Generieren &amp; Speichern</button>
      </form>
    </section>

    <!-- Vorschau -->
    <section class="pd-card" aria-labelledby="h-preview">
      <div class="pd-card__head"><h2 class="pd-over" id="h-preview">Vorschau</h2></div>
      <div class="pd-card__body">
        <?php if ($success): ?>
        <div class="ep-preview">
          <div class="ep-preview-head">Gespeichert · <?= h($success['id']) ?></div>
          <iframe class="ep-frame" title="Vorschau der Werbezwischenseite"
                  src="<?= h(EPAPER_CREATIVES_URL . '/' . $success['id'] . '/index.html') ?>"></iframe>
          <div class="ep-preview__body">
            <div class="ep-meta">
              Querformat <?= (int) $success['files']['landscape']['width'] ?> × <?= (int) $success['files']['landscape']['height'] ?> px ·
              Hochformat <?= (int) $success['files']['portrait']['width'] ?> × <?= (int) $success['files']['portrait']['height'] ?> px
            </div>
            <div class="ep-link"><a href="<?= h($success['link']) ?>" target="_blank" rel="noopener noreferrer"><?= h($success['link']) ?></a></div>
          </div>
        </div>
        <?php endif; ?>
        <div class="ep-preview"<?= $success ? ' hidden id="ep-live"' : '' ?>>
          <div class="ep-preview-head">Template-Ausgabe</div>
          <div class="ep-preview__body">
            <div class="ep-images">
              <div class="ep-slot">
                <div class="ep-box" id="prev-landscape">Querformat</div>
                <div class="ep-meta" id="prev-landscape-meta"></div>
              </div>
              <div class="ep-slot">
                <div class="ep-box" id="prev-portrait">Hochformat</div>
                <div class="ep-meta" id="prev-portrait-meta"></div>
              </div>
            </div>
            <div class="ep-link" id="prev-link"></div>
          </div>
        </div>
      </div>
    </section>
  </div>
</main>

<script>
(() => {
  const MAX_BYTES = <?= (int) EPAPER_MAX_IMAGE_BYTES ?>;
  const $ = (id) => document.getElementById(id);
  const publisher = $('publisher'), customer = $('customer'), date = $('date'), link = $('link');

  // Gleiche Logik wie epaper_folder_name() in PHP.
  function folderName(name, d) {
    let slug = name.trim().toLowerCase()
      .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60).replace(/-+$/, '');
    return d + '_' + (slug || 'kunde');
  }

  function updateFolder() {
    const ready = publisher.value && customer.value.trim() && date.value;
    $('folder').textContent = 'Ordner: ' + (ready ? publisher.value + '/' + folderName(customer.value, date.value) : '—');
  }

  function updateLink() {
    const v = link.value.trim();
    const box = $('prev-link');
    box.textContent = '';
    if (!v) return;
    if (/^https?:\/\/\S+$/i.test(v)) {
      const a = document.createElement('a');
      a.href = v; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = v;
      box.appendChild(a);
    } else {
      box.textContent = v + '  ⚠ muss mit http:// oder https:// beginnen';
    }
  }

  function formatSize(bytes) {
    return bytes >= 1048576 ? (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.round(bytes / 1024) + ' KB';
  }

  document.querySelectorAll('input[type=file][data-target]').forEach((input) => {
    input.addEventListener('change', () => {
      const box = $(input.dataset.target), meta = $(input.dataset.target + '-meta');
      const file = input.files[0];
      box.classList.remove('has-image');
      meta.classList.remove('is-warn');
      meta.textContent = '';
      input.setCustomValidity('');
      if (!file) { box.textContent = input.dataset.orientation === 'landscape' ? 'Querformat' : 'Hochformat'; return; }

      const img = new Image();
      img.alt = '';
      img.onload = () => {
        const w = img.naturalWidth, h = img.naturalHeight;
        const wrong = input.dataset.orientation === 'landscape' ? w <= h : h <= w;
        let text = w + ' × ' + h + ' px · ' + formatSize(file.size);
        let problem = '';
        if (wrong) problem = input.dataset.orientation === 'landscape' ? 'Kein Querformat' : 'Kein Hochformat';
        if (file.size > MAX_BYTES) problem = 'Zu groß (max. ' + (MAX_BYTES / 1048576) + ' MB)';
        if (problem) { text += ' · ⚠ ' + problem; meta.classList.add('is-warn'); input.setCustomValidity(problem); }
        meta.textContent = text;
      };
      img.onerror = () => { meta.textContent = '⚠ Datei ist kein lesbares Bild'; meta.classList.add('is-warn'); };
      img.src = URL.createObjectURL(file);
      box.textContent = '';
      box.appendChild(img);
      box.classList.add('has-image');
    });
  });

  // Nach dem Speichern: sobald neu eingegeben wird, wieder die Live-Vorschau zeigen.
  const live = $('ep-live');
  if (live) $('ep-form').addEventListener('change', () => { live.hidden = false; }, { once: true });

  publisher.addEventListener('change', updateFolder);
  customer.addEventListener('input', updateFolder);
  date.addEventListener('input', updateFolder);
  link.addEventListener('input', updateLink);
  updateFolder();
  updateLink();

  // Live-Adresse in die Zwischenablage kopieren.
  document.querySelectorAll('[data-copy]').forEach((btn) => btn.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(btn.dataset.copy); btn.textContent = 'Kopiert ✓'; }
    catch { window.prompt('Adresse kopieren:', btn.dataset.copy); }
    setTimeout(() => { btn.textContent = 'Adresse kopieren'; }, 2000);
  }));

  // Browser-Prüfung vor dem Absenden; der Server prüft trotzdem alles erneut.
  $('ep-form').addEventListener('submit', (e) => {
    if (!e.target.checkValidity()) { e.preventDefault(); e.target.reportValidity(); }
  });
})();
</script>
</body>
</html>
