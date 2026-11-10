<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/home/lib/bootstrap.php';
require APP_HOME . '/lib/auth.php';   // nur für CSRF-Schutz
require APP_HOME . '/lib/epaper.php';

auth_start_session(); // vor jeder Ausgabe, damit das CSRF-Token in der Sitzung landet

const PER_PAGE = 50;

// Verlag: aus der URL, sonst zuletzt gewählter (Cookie), sonst "alle".
$publisher = (string) ($_GET['verlag'] ?? $_COOKIE['epaper_publisher'] ?? 'alle');
if ($publisher !== 'alle' && !isset(EPAPER_PUBLISHERS[$publisher])) {
    $publisher = 'alle';
}
if (isset($_GET['verlag'])) {
    epaper_remember_publisher($publisher);
}

$period = (string) ($_GET['zeit'] ?? 'aktuell');
if (!isset(EPAPER_PERIODS[$period])) {
    $period = 'aktuell';
}
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);

$page = max(1, (int) ($_GET['seite'] ?? 1));
$result = epaper_query($publisher, $period, $search, $page, PER_PAGE);
$counts = $result['counts'];
$total = $result['total'];
$pages = max(1, (int) ceil($total / PER_PAGE));
if ($page > $pages) {
    $page = $pages;
    $result = epaper_query($publisher, $period, $search, $page, PER_PAGE);
}
$items = $result['items'];

/** URL mit den aktuellen Filtern, einzelne Werte überschreibbar. */
function list_url(array $override = []): string
{
    global $publisher, $period, $search;
    $params = array_merge(['verlag' => $publisher, 'zeit' => $period, 'q' => $search], $override);
    $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null && $v !== 1);
    return '?' . http_build_query($params);
}
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
  .ep-toolbar { display: flex; align-items: center; justify-content: space-between; gap: var(--space-4); flex-wrap: wrap; padding: var(--space-4) var(--space-6); border-bottom: 1px solid var(--border); }
  .ep-search { display: flex; gap: var(--space-2); flex: 1 1 260px; max-width: 420px; }
  .ep-search .pd-input { min-height: 36px; font-size: var(--fs-sm); }
  .ep-search .pd-btn { min-height: 36px; font-size: var(--fs-sm); padding: 0 14px; }
  .ep-pub-logo { height: 22px; width: auto; display: block; }
  .ep-table-wrap { overflow-x: auto; }
  .ep-table { width: 100%; border-collapse: collapse; font-size: var(--fs-sm); }
  .ep-table th { text-align: left; font-size: var(--fs-micro); letter-spacing: .12em; text-transform: uppercase; color: var(--text-muted); font-weight: 500; padding: 12px var(--space-3); border-bottom: 1px solid var(--border); white-space: nowrap; }
  .ep-table td { padding: 12px var(--space-3); border-bottom: 1px dotted var(--border-strong); vertical-align: middle; }
  .ep-table tr:last-child td { border-bottom: 0; }
  .ep-table tr:hover td { background: var(--pd-grey-100); }
  .ep-thumb { width: 72px; height: 48px; object-fit: cover; border-radius: 2px; border: 1px solid var(--border); display: block; }
  .ep-cust { font-weight: 500; color: var(--text-strong); }
  .ep-folder { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); overflow-wrap: anywhere; }
  .ep-pub { min-width: 90px; }
  /* Link darf auf 2 Zeilen umbrechen (danach abgeschnitten) – so wird die Tabelle nie breiter als die Seite. */
  .ep-url { min-width: 170px; max-width: 240px; overflow: hidden; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
  .ep-actions { display: flex; gap: var(--space-2); white-space: nowrap; }
  .ep-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 500; padding: 3px 9px; border-radius: 999px; white-space: nowrap; background: var(--pd-grey-200); color: var(--pd-grey-800); }
  .ep-badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--b, var(--pd-grey-500)); }
  .ep-badge--live { --b: #6FB51E; background: #F0FADB; }
  .ep-badge--error { --b: var(--pd-red); background: var(--danger-bg); color: #7A2207; cursor: help; }
  .ep-copy { border: 0; background: none; cursor: pointer; color: var(--text-muted); font-size: 15px; padding: 2px 6px; border-radius: 4px; vertical-align: middle; }
  .ep-copy:hover { background: var(--pd-grey-200); color: var(--pd-black); }
  .ep-copy.is-done { color: #4F9A10; }
  .ep-empty { padding: var(--space-12) var(--space-6); text-align: center; color: var(--text-muted); }
  .ep-pager { display: flex; align-items: center; justify-content: space-between; gap: var(--space-4); padding: var(--space-3) var(--space-6); border-top: 1px solid var(--border); font-size: var(--fs-sm); color: var(--text-muted); }
  .ep-pager nav { display: flex; gap: var(--space-2); }
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
    <nav class="pd-tabs" aria-label="Verlag">
      <a class="pd-tab<?= $publisher === 'alle' ? ' is-active' : '' ?>" href="<?= h(list_url(['verlag' => 'alle', 'seite' => null])) ?>"<?= $publisher === 'alle' ? ' aria-current="page"' : '' ?>>
        Alle <span class="pd-tab__count"><?= (int) $counts['alle'] ?></span>
      </a>
      <?php foreach (EPAPER_PUBLISHERS as $slug => $p): $on = $publisher === $slug; ?>
        <a class="pd-tab<?= $on ? ' is-active' : '' ?>" href="<?= h(list_url(['verlag' => $slug, 'seite' => null])) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
          <?= h($p['name']) ?> <span class="pd-tab__count"><?= (int) ($counts[$slug] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="ep-toolbar">
      <div class="pd-seg" role="group" aria-label="Zeitraum">
        <?php foreach (EPAPER_PERIODS as $key => $label): ?>
          <a class="<?= $period === $key ? 'is-active' : '' ?>" href="<?= h(list_url(['zeit' => $key, 'seite' => null])) ?>"
             title="<?= h(['aktuell' => 'Letzte 30 Tage und kommende', 'kommend' => 'Ab heute', 'alle' => 'Alle Erscheinungstermine'][$key]) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
      <form class="ep-search" method="get" role="search">
        <input type="hidden" name="verlag" value="<?= h($publisher) ?>">
        <input type="hidden" name="zeit" value="<?= h($period) ?>">
        <input class="pd-input" type="search" name="q" value="<?= h($search) ?>" placeholder="Kunde, Ordner oder Link suchen …" aria-label="Suche">
        <button class="pd-btn pd-btn--secondary" type="submit">Suchen</button>
      </form>
      <?php if ($publisher !== 'alle'): ?>
        <img class="ep-pub-logo" src="../assets/pd/verlage/<?= h(EPAPER_PUBLISHERS[$publisher]['logo']) ?>" alt="<?= h(epaper_publisher_name($publisher)) ?>">
      <?php endif; ?>
    </div>

    <?php if (!$items): ?>
      <p class="ep-empty">
        <?php if ($search !== ''): ?>Keine Treffer für „<?= h($search) ?>“.
        <?php elseif ($period !== 'alle'): ?>Keine Creatives in diesem Zeitraum. <a href="<?= h(list_url(['zeit' => 'alle'])) ?>">Alle anzeigen</a>
        <?php else: ?>Noch keine Creatives gespeichert.<?php endif; ?>
      </p>
    <?php else: ?>
    <div class="ep-table-wrap">
      <table class="ep-table">
        <thead>
          <tr>
            <th></th><th>Erscheinung</th>
            <?php if ($publisher === 'alle'): ?><th>Verlag</th><?php endif; ?>
            <th>Kunde / Ordner</th><th>Link</th><th>Status</th><th>Geändert</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it):
            $base = EPAPER_CREATIVES_URL . '/' . $it['id'] . '/'; ?>
          <tr>
            <td><img class="ep-thumb" src="<?= h($base . $it['files']['landscape']['file']) ?>" alt="" loading="lazy"></td>
            <td><?= h(date_de(strtotime($it['date']), false)) ?></td>
            <?php if ($publisher === 'alle'): ?><td class="ep-pub"><?= h(epaper_publisher_name($it['publisher'])) ?></td><?php endif; ?>
            <td><div class="ep-cust"><?= h($it['customer']) ?></div><div class="ep-folder"><?= h($it['folder']) ?></div></td>
            <td><a class="ep-url" href="<?= h($it['link']) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($it['link']) ?>"><?= h($it['link']) ?></a></td>
            <td style="white-space:nowrap">
              <?php if ($it['live_url']): ?>
                <a class="ep-badge ep-badge--live" href="<?= h($it['live_url']) ?>" target="_blank" rel="noopener"
                   title="Liegt auf ads.pddigital.de und kann gebucht werden – noch nicht im Ad-Server gebucht">Bereitgestellt</a>
                <button type="button" class="ep-copy" data-copy="<?= h($it['live_url']) ?>" title="Adresse kopieren" aria-label="Adresse auf ads.pddigital.de kopieren"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="1.5"/><path d="M5 15V5a1 1 0 0 1 1-1h10"/></svg></button>
              <?php elseif ($it['upload_error']): ?>
                <span class="ep-badge ep-badge--error" title="<?= h($it['upload_error']) ?>">Upload-Fehler</span>
              <?php else: ?>
                <span class="ep-badge" title="Nur auf dem internen Server gespeichert">Nicht bereitgestellt</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap"><?= h(date_de(strtotime($it['updated_at']), false)) ?><div class="ep-folder"><?= h(date('H:i', strtotime($it['updated_at']))) ?> Uhr</div></td>
            <td><div class="ep-actions">
              <a class="pd-btn pd-btn--ghost" href="<?= h($base . 'index.html') ?>" target="_blank" rel="noopener">Vorschau</a>
              <a class="pd-btn pd-btn--ghost" href="zip.php?id=<?= h(rawurlencode($it['id'])) ?>">ZIP</a>
              <?php if (!$it['live_url'] && live_enabled()): ?>
                <form method="post" action="./"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= h($it['id']) ?>"><input type="hidden" name="return" value="list">
                  <button class="pd-btn pd-btn--ghost" name="action" value="publish">Hochladen</button></form>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="ep-pager">
      <span><?= $total ?> Creative<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? " · Seite {$page} von {$pages}" : '' ?></span>
      <?php if ($pages > 1): ?>
        <nav aria-label="Seiten">
          <?php if ($page > 1): ?><a class="pd-btn pd-btn--ghost" href="<?= h(list_url(['seite' => $page - 1])) ?>">← Zurück</a><?php endif; ?>
          <?php if ($page < $pages): ?><a class="pd-btn pd-btn--ghost" href="<?= h(list_url(['seite' => $page + 1])) ?>">Weiter →</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>
</main>
<script>
document.querySelectorAll('[data-copy]').forEach((btn) => btn.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(btn.dataset.copy); btn.classList.add('is-done'); btn.title = 'Kopiert'; }
  catch { window.prompt('Adresse kopieren:', btn.dataset.copy); }
  setTimeout(() => { btn.classList.remove('is-done'); btn.title = 'Adresse kopieren'; }, 1500);
}));
</script>
</body>
</html>
