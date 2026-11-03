<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/home/lib/bootstrap.php';
require APP_HOME . '/lib/auth.php';   // nur für CSRF-Schutz, kein Login nötig
require APP_HOME . '/lib/epaper.php';

$values = ['customer' => '', 'date' => '', 'link' => ''];
$errors = [];
$success = null;

if (epaper_post_too_large()) {
    $errors['_form'] = 'Die hochgeladenen Dateien sind zusammen zu groß.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    [$values, $errors] = epaper_validate($_POST, $_FILES);
    if ($errors === []) {
        // Schritt 2: HTML erzeugen, Bilder und HTML strukturiert ablegen.
        $success = $values;
    }
}

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
</style>
</head>
<body>

<header class="pd-top">
  <div class="pd-top__inner">
    <img class="pd-top__logo" src="../assets/pd/pd-digital-logo.svg" alt="pd digital">
    <nav class="pd-crumb" aria-label="Brotkrumen">
      <span>Creative-Tools</span><span aria-hidden="true">›</span><b>Template Generator</b>
    </nav>
  </div>
</header>

<main class="pd-page">
  <div>
    <div class="pd-over">ePaper</div>
    <h1>Werbezwischenseite erstellen</h1>
  </div>

  <?php if ($success): ?>
    <div class="pd-alert pd-alert--success" role="status">
      <strong>Eingaben geprüft.</strong> Ordner: <code><?= h($success['folder']) ?></code> ·
      Querformat <?= (int) $success['image_landscape']['width'] ?> × <?= (int) $success['image_landscape']['height'] ?> px ·
      Hochformat <?= (int) $success['image_portrait']['width'] ?> × <?= (int) $success['image_portrait']['height'] ?> px.
      <br>Das Erzeugen des HTML und das Speichern folgen in Schritt 2.
    </div>
  <?php elseif ($errors): ?>
    <div class="pd-alert pd-alert--error" role="alert">
      <strong>Bitte die markierten Angaben prüfen.</strong>
      <?php if (isset($errors['_form'])): ?><ul><li><?= h($errors['_form']) ?></li></ul><?php endif; ?>
      <?php if (!isset($errors['_form'])): ?>Bilder müssen nach einem Fehler erneut ausgewählt werden.<?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="pd-grid-2">
    <!-- Eingabe -->
    <section class="pd-card" aria-labelledby="h-input">
      <div class="pd-card__head"><h2 class="pd-over" id="h-input">Eingabe</h2></div>
      <form class="pd-card__body" method="post" enctype="multipart/form-data" id="ep-form" novalidate>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

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

        <div class="pd-code" id="folder">Ordner: <?= h($success['folder'] ?? '—') ?></div>

        <button class="pd-btn pd-btn--primary pd-btn--block" type="submit">Generieren &amp; Speichern</button>
      </form>
    </section>

    <!-- Vorschau -->
    <section class="pd-card" aria-labelledby="h-preview">
      <div class="pd-card__head"><h2 class="pd-over" id="h-preview">Vorschau</h2></div>
      <div class="pd-card__body">
        <div class="ep-preview">
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
  const customer = $('customer'), date = $('date'), link = $('link');

  // Gleiche Logik wie epaper_folder_name() in PHP.
  function folderName(name, d) {
    let slug = name.trim().toLowerCase()
      .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60).replace(/-+$/, '');
    return d + '_' + (slug || 'kunde');
  }

  function updateFolder() {
    $('folder').textContent = 'Ordner: ' + (customer.value.trim() && date.value ? folderName(customer.value, date.value) : '—');
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

  customer.addEventListener('input', updateFolder);
  date.addEventListener('input', updateFolder);
  link.addEventListener('input', updateLink);
  updateFolder();
  updateLink();

  // Browser-Prüfung vor dem Absenden; der Server prüft trotzdem alles erneut.
  $('ep-form').addEventListener('submit', (e) => {
    if (!e.target.checkValidity()) { e.preventDefault(); e.target.reportValidity(); }
  });
})();
</script>
</body>
</html>
