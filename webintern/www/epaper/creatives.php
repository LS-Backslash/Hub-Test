<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/home/lib/bootstrap.php';
require APP_HOME . '/lib/epaper.php';

$items = epaper_list();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Alle Creatives · ePaper Werbezwischenseiten</title>
<link rel="stylesheet" href="../assets/pd/pd.css">
<style>
  .ep-table-wrap { overflow-x: auto; }
  .ep-table { width: 100%; border-collapse: collapse; font-size: var(--fs-sm); }
  .ep-table th { text-align: left; font-size: var(--fs-micro); letter-spacing: .12em; text-transform: uppercase; color: var(--text-muted); font-weight: 500; padding: 12px var(--space-4); border-bottom: 1px solid var(--border); white-space: nowrap; }
  .ep-table td { padding: 12px var(--space-4); border-bottom: 1px dotted var(--border-strong); vertical-align: middle; }
  .ep-table tr:last-child td { border-bottom: 0; }
  .ep-table tr:hover td { background: var(--pd-grey-100); }
  .ep-thumb { width: 72px; height: 48px; object-fit: cover; border-radius: 2px; border: 1px solid var(--border); display: block; }
  .ep-cust { font-weight: 500; color: var(--text-strong); }
  .ep-folder { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
  .ep-url { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
  .ep-actions { display: flex; gap: var(--space-2); white-space: nowrap; }
  .ep-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 500; padding: 3px 9px; border-radius: 999px; white-space: nowrap; background: var(--pd-grey-200); color: var(--pd-grey-800); }
  .ep-badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--b, var(--pd-grey-500)); }
  .ep-badge--live { --b: #6FB51E; background: #F0FADB; }
  .ep-empty { padding: var(--space-12) var(--space-6); text-align: center; color: var(--text-muted); }
</style>
</head>
<body>

<header class="pd-top">
  <div class="pd-top__inner">
    <img class="pd-top__logo" src="../assets/pd/pd-digital-logo.svg" alt="pd digital">
    <nav class="pd-crumb" aria-label="Brotkrumen">
      <span>Creative-Tools</span><span aria-hidden="true">›</span><a href="./">Template Generator</a><span aria-hidden="true">›</span><b>Alle Creatives</b>
    </nav>
    <div class="pd-top__right"><a class="pd-btn pd-btn--primary" href="./">+ Neues Creative</a></div>
  </div>
</header>

<main class="pd-page">
  <div>
    <div class="pd-over">ePaper</div>
    <h1>Alle Creatives</h1>
  </div>

  <section class="pd-card">
    <?php if (!$items): ?>
      <p class="ep-empty">Noch keine Creatives gespeichert.</p>
    <?php else: ?>
    <div class="ep-table-wrap">
      <table class="ep-table">
        <thead>
          <tr><th></th><th>Erscheinung</th><th>Kunde / Ordner</th><th>Link</th><th>Status</th><th>Geändert</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it):
            $base = EPAPER_CREATIVES_URL . '/' . $it['folder'] . '/'; ?>
          <tr>
            <td><img class="ep-thumb" src="<?= h($base . $it['files']['landscape']['file']) ?>" alt="" loading="lazy"></td>
            <td><?= h(date_de(strtotime($it['date']), false)) ?></td>
            <td><div class="ep-cust"><?= h($it['customer']) ?></div><div class="ep-folder"><?= h($it['folder']) ?></div></td>
            <td><a class="ep-url" href="<?= h($it['link']) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($it['link']) ?>"><?= h($it['link']) ?></a></td>
            <td>
              <?php if ($it['live_url']): ?>
                <a class="ep-badge ep-badge--live" href="<?= h($it['live_url']) ?>" target="_blank" rel="noopener">Live</a>
              <?php else: ?>
                <span class="ep-badge">Lokal</span>
              <?php endif; ?>
            </td>
            <td><?= h(date_de(strtotime($it['updated_at']))) ?></td>
            <td><div class="ep-actions">
              <a class="pd-btn pd-btn--ghost" href="<?= h($base . 'index.html') ?>" target="_blank" rel="noopener">Vorschau</a>
              <a class="pd-btn pd-btn--ghost" href="zip.php?folder=<?= h(rawurlencode($it['folder'])) ?>">ZIP</a>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
