<?php
declare(strict_types=1);

/**
 * Seiten des Ads.txt Organizers. Jede Funktion gibt eine komplette Seite aus.
 */

/* ------------------------------------------------------------------------
 * Übersicht
 * ---------------------------------------------------------------------- */
function page_overview(): void
{
    $publishers = adstxt_publishers(false);
    $outputs = [];
    foreach (db_all('SELECT publisher_id, file_type, record_count, changed_at FROM adstxt_outputs') as $o) {
        $outputs[$o['publisher_id']][$o['file_type']] = $o;
    }
    $src = db_one("SELECT COUNT(*) AS n, SUM(last_status = 'ok') AS ok, SUM(last_status = 'warning') AS warn, SUM(last_status = 'error') AS err
                   FROM adstxt_sources WHERE active = 1");
    $lastRun = db_one('SELECT * FROM adstxt_runs ORDER BY id DESC LIMIT 1');
    $changes7 = (int) db_value('SELECT COUNT(*) FROM adstxt_changes WHERE changed_at >= ?', [date('Y-m-d H:i:s', strtotime('-7 days'))]);
    $problems = db_all("SELECT s.*, p.name AS publisher_name FROM adstxt_sources s LEFT JOIN adstxt_publishers p ON p.id = s.publisher_id
                        WHERE s.active = 1 AND s.last_status IN ('warning','error') ORDER BY s.last_status = 'error' DESC, s.name");

    ui_start('uebersicht', 'Übersicht', [['Übersicht', null]], ui_run_button('uebersicht'));
    ?>
    <div class="pd-head">
      <div><div class="pd-over">Ads.txt Organizer</div><h1>Übersicht</h1>
        <p>Die Dateien werden automatisch alle 12 Stunden aus Quellen und Einträgen neu erzeugt. Mit „Jetzt aktualisieren“ geht es sofort.</p></div>
    </div>

    <div class="pd-stats">
      <div class="pd-stat"><div class="pd-stat__n"><?= count($publishers) ?></div><div class="pd-stat__l">Aktive Publisher</div>
        <div class="pd-stat__s"><?= (int) db_value('SELECT COUNT(*) FROM adstxt_publishers WHERE active = 0') ?> inaktiv</div></div>
      <div class="pd-stat" style="--c: <?= ($src['err'] ?? 0) > 0 ? 'var(--pd-red)' : (($src['warn'] ?? 0) > 0 ? 'var(--pd-amber)' : '#6FB51E') ?>">
        <div class="pd-stat__n"><?= (int) $src['n'] ?></div><div class="pd-stat__l">Aktive Quellen</div>
        <div class="pd-stat__s"><?= (int) $src['ok'] ?> ok · <?= (int) $src['warn'] ?> Warnung · <?= (int) $src['err'] ?> Fehler</div></div>
      <div class="pd-stat" style="--c: var(--pd-cyan)"><div class="pd-stat__n"><?= $changes7 ?></div><div class="pd-stat__l">Dateiänderungen (7 Tage)</div>
        <div class="pd-stat__s"><a href="<?= h(ui_url('protokoll')) ?>">Zum Protokoll</a></div></div>
      <div class="pd-stat" style="--c: var(--pd-navy)">
        <div class="pd-stat__n" style="font-size:20px;line-height:34px"><?= $lastRun ? h(ui_date($lastRun['started_at'])) : '—' ?></div>
        <div class="pd-stat__l">Letzte Aktualisierung</div>
        <div class="pd-stat__s"><?= $lastRun ? ui_badge($lastRun['status']) . ' ' . ($lastRun['trigger_type'] === 'cron' ? 'automatisch' : 'manuell') : 'noch nie' ?></div></div>
    </div>

    <?php if ($problems): ?>
    <section class="pd-card">
      <div class="pd-card__head"><h2 class="pd-over">Quellen mit Problemen</h2></div>
      <div class="pd-table-wrap"><table class="pd-table">
        <thead><tr><th>Quelle</th><th>Gilt für</th><th>Status</th><th>Meldung</th><th>Letzter Abruf</th></tr></thead>
        <tbody>
        <?php foreach ($problems as $s): ?>
          <tr>
            <td><a class="pd-t-strong" href="<?= h(ui_url('quelle-bearbeiten', ['id' => $s['id']])) ?>"><?= h($s['name']) ?></a></td>
            <td><?= ui_scope($s['publisher_name']) ?></td>
            <td><?= ui_badge($s['last_status']) ?></td>
            <td class="pd-t-sub"><?= h($s['last_message']) ?></td>
            <td><?= h(ui_date($s['last_fetch_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <?php endif; ?>

    <section class="pd-card">
      <div class="pd-card__head"><h2 class="pd-over">Dateien je Publisher</h2>
        <a class="pd-btn pd-btn--secondary pd-btn--sm" href="<?= h(ui_url('publisher-bearbeiten')) ?>"><?= ui_icon('plus') ?> Publisher</a></div>
      <?php if (!$publishers): ?>
        <div class="pd-empty"><b>Noch keine Publisher</b>Leg zuerst einen Publisher an, dann Quellen und Einträge.</div>
      <?php else: ?>
      <div class="pd-table-wrap"><table class="pd-table">
        <thead><tr><th>Publisher</th><th>ads.txt</th><th>app-ads.txt</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($publishers as $p): ?>
          <tr>
            <td><a class="pd-t-strong" href="<?= h(ui_url('publisher-bearbeiten', ['id' => $p['id']])) ?>"><?= h($p['name']) ?></a>
              <div class="pd-t-sub"><?= h($p['domain'] ?: '—') ?></div></td>
            <?php foreach (['ads' => 'wants_ads', 'app-ads' => 'wants_app_ads'] as $type => $flag): $o = $outputs[$p['id']][$type] ?? null; ?>
              <td>
                <?php if (!$p[$flag]): ?><span class="pd-t-sub">nicht benötigt</span>
                <?php elseif (!$o): ?><?= ui_badge(null, 'Noch nicht erzeugt') ?>
                <?php else: ?>
                  <a href="<?= h(ui_url('vorschau', ['id' => $p['id'], 'typ' => $type])) ?>"><?= (int) $o['record_count'] ?> Einträge</a>
                  <div class="pd-t-sub">geändert <?= h(ui_date($o['changed_at'])) ?></div>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td><div class="pd-t-actions"><a class="pd-btn pd-btn--ghost pd-btn--sm" href="<?= h(ui_url('vorschau', ['id' => $p['id']])) ?>">Vorschau</a></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
    <?php
    ui_end();
}

/* ------------------------------------------------------------------------
 * Publisher
 * ---------------------------------------------------------------------- */
function page_publishers(): void
{
    $filter = (string) ($_GET['status'] ?? 'aktiv');
    $q = trim((string) ($_GET['q'] ?? ''));
    $all = adstxt_publishers();
    $counts = ['aktiv' => 0, 'inaktiv' => 0, 'alle' => count($all)];
    foreach ($all as $p) {
        $counts[$p['active'] ? 'aktiv' : 'inaktiv']++;
    }
    $rows = array_filter($all, static function ($p) use ($filter, $q) {
        if ($filter === 'aktiv' && !$p['active'] || $filter === 'inaktiv' && $p['active']) {
            return false;
        }
        return $q === '' || mb_stripos($p['name'] . ' ' . $p['domain'] . ' ' . $p['slug'], $q) !== false;
    });
    $srcCounts = array_column(db_all('SELECT publisher_id, COUNT(*) AS n FROM adstxt_sources WHERE publisher_id IS NOT NULL GROUP BY publisher_id'), 'n', 'publisher_id');
    $blkCounts = array_column(db_all('SELECT publisher_id, COUNT(*) AS n FROM adstxt_blocks WHERE publisher_id IS NOT NULL GROUP BY publisher_id'), 'n', 'publisher_id');

    ui_start('publisher', 'Publisher', [['Publisher', null]],
        '<a class="pd-btn pd-btn--primary pd-btn--sm" href="' . h(ui_url('publisher-bearbeiten')) . '">' . ui_icon('plus') . ' Publisher anlegen</a>');
    ?>
    <div class="pd-head"><div><div class="pd-over">Verwaltung</div><h1>Publisher</h1>
      <p>Für jeden aktiven Publisher werden die gewählten Dateien erzeugt. Deaktivierte Publisher bleiben erhalten und lassen sich wieder einschalten.</p></div></div>

    <section class="pd-card">
      <nav class="pd-tabs" aria-label="Status">
        <?php foreach (['aktiv' => 'Aktiv', 'inaktiv' => 'Inaktiv', 'alle' => 'Alle'] as $k => $label): ?>
          <a class="pd-tab<?= $filter === $k ? ' is-active' : '' ?>" href="<?= h(ui_url('publisher', ['status' => $k, 'q' => $q])) ?>"<?= $filter === $k ? ' aria-current="page"' : '' ?>>
            <?= h($label) ?> <span class="pd-tab__count"><?= $counts[$k] ?></span></a>
        <?php endforeach; ?>
      </nav>
      <div class="pd-toolbar">
        <form method="get" role="search">
          <input type="hidden" name="seite" value="publisher"><input type="hidden" name="status" value="<?= h($filter) ?>">
          <label class="pd-label" for="q" style="position:absolute;left:-9999px">Suche</label>
          <input class="pd-input" id="q" type="search" name="q" value="<?= h($q) ?>" placeholder="Name, Domain oder Kürzel …" style="min-width:260px">
          <button class="pd-btn pd-btn--secondary pd-btn--sm">Suchen</button>
        </form>
      </div>
      <?php if (!$rows): ?>
        <div class="pd-empty"><b><?= $q !== '' ? 'Keine Treffer' : 'Keine Publisher' ?></b>
          <?= $q !== '' ? 'Für „' . h($q) . '“ gibt es keinen Publisher.' : '<a href="' . h(ui_url('publisher-bearbeiten')) . '">Ersten Publisher anlegen</a>' ?></div>
      <?php else: ?>
      <div class="pd-table-wrap"><table class="pd-table">
        <thead><tr><th>Publisher</th><th>Dateien</th><th class="pd-num">Eigene Quellen</th><th class="pd-num">Eigene Einträge</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
          <tr class="<?= $p['active'] ? '' : 'is-muted' ?>">
            <td><a class="pd-t-strong" href="<?= h(ui_url('publisher-bearbeiten', ['id' => $p['id']])) ?>"><?= h($p['name']) ?></a>
              <div class="pd-t-sub"><?= h($p['domain'] ?: '—') ?> · <span class="pd-t-mono"><?= h($p['slug']) ?></span></div></td>
            <td><?= implode(' ', array_filter([$p['wants_ads'] ? 'ads.txt' : null, $p['wants_app_ads'] ? 'app-ads.txt' : null])) ?></td>
            <td class="pd-num"><?= (int) ($srcCounts[$p['id']] ?? 0) ?></td>
            <td class="pd-num"><?= (int) ($blkCounts[$p['id']] ?? 0) ?></td>
            <td><?= $p['active'] ? ui_badge('ok', 'Aktiv') : ui_badge('off') ?></td>
            <td><div class="pd-t-actions">
              <a class="pd-btn pd-btn--ghost pd-btn--sm" href="<?= h(ui_url('vorschau', ['id' => $p['id']])) ?>">Vorschau</a>
              <a class="pd-btn pd-btn--ghost pd-btn--sm" href="<?= h(ui_url('publisher-bearbeiten', ['id' => $p['id']])) ?>">Bearbeiten</a>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
    <?php
    ui_end();
}

function page_publisher_form(?int $id, ?array $form, array $errors): void
{
    $p = $id ? adstxt_publisher($id) : null;
    if ($id && !$p) {
        ui_redirect('publisher', [], 'Publisher nicht gefunden.', 'error');
    }
    $v = $form ?? $p ?? ['name' => '', 'domain' => '', 'active' => 1, 'wants_ads' => 1, 'wants_app_ads' => 0, 'header_lines' => '', 'notes' => ''];
    $isNew = $p === null;
    $title = $isNew ? 'Publisher anlegen' : $p['name'];

    ui_start('publisher', $title, [['Publisher', ui_url('publisher')], [$title, null]]);
    ?>
    <div class="pd-head"><div><div class="pd-over">Publisher</div><h1><?= h($title) ?></h1>
      <?php if (!$isNew): ?><p>Kürzel <span class="pd-t-mono"><?= h($p['slug']) ?></span> – Teil der späteren Download-Adresse, bleibt deshalb fest.</p><?php endif; ?></div>
      <?php if (!$isNew): ?><?= $p['active'] ? ui_badge('ok', 'Aktiv') : ui_badge('off') ?><?php endif; ?>
    </div>

    <div class="pd-grid-side">
      <section class="pd-card">
        <div class="pd-card__head"><h2 class="pd-over">Stammdaten</h2></div>
        <form class="pd-card__body" method="post" action="<?= h(ui_url('publisher-bearbeiten', ['id' => $id])) ?>" novalidate>
          <?= ui_csrf() ?>
          <div class="pd-row">
            <div class="<?= ui_field_class($errors, 'name') ?>">
              <label class="pd-label" for="name">Name</label>
              <input class="pd-input" id="name" name="name" required maxlength="120" value="<?= h($v['name']) ?>" placeholder="z. B. Südkurier">
              <?= ui_field_error($errors, 'name') ?>
            </div>
            <div class="<?= ui_field_class($errors, 'domain') ?>">
              <label class="pd-label" for="domain">Domain <small>(optional)</small></label>
              <input class="pd-input" id="domain" name="domain" maxlength="190" value="<?= h($v['domain']) ?>" placeholder="suedkurier.de" inputmode="url">
              <?= ui_field_error($errors, 'domain') ?>
            </div>
          </div>

          <fieldset class="<?= ui_field_class($errors, 'files') ?>" style="border:0;padding:0;margin:0">
            <legend class="pd-label" style="margin-bottom:6px">Dateien erzeugen</legend>
            <div class="pd-checks">
              <label class="pd-check"><input type="checkbox" name="wants_ads" value="1"<?= !empty($v['wants_ads']) ? ' checked' : '' ?>> ads.txt</label>
              <label class="pd-check"><input type="checkbox" name="wants_app_ads" value="1"<?= !empty($v['wants_app_ads']) ? ' checked' : '' ?>> app-ads.txt</label>
            </div>
            <?= ui_field_error($errors, 'files') ?>
          </fieldset>

          <div class="<?= ui_field_class($errors, 'header_lines') ?>">
            <label class="pd-label" for="header_lines">Kopfzeilen <small>(haben Vorrang vor Angaben aus Quellen)</small></label>
            <textarea class="pd-textarea" id="header_lines" name="header_lines" rows="4" style="min-height:110px"
                      placeholder="OWNERDOMAIN=suedkurier.de&#10;CONTACT=ads@suedkurier.de"><?= h($v['header_lines']) ?></textarea>
            <span class="pd-hint">Erlaubt: OWNERDOMAIN, MANAGERDOMAIN, CONTACT, SUBDOMAIN, INVENTORYPARTNERDOMAIN – je eine pro Zeile. Gilt für ads.txt und app-ads.txt.</span>
            <?= ui_field_error($errors, 'header_lines') ?>
          </div>

          <div class="pd-field">
            <label class="pd-label" for="notes">Notizen <small>(intern)</small></label>
            <textarea class="pd-textarea pd-textarea--text" id="notes" name="notes" rows="2"><?= h($v['notes']) ?></textarea>
          </div>

          <label class="pd-check"><input type="checkbox" name="active" value="1"<?= !empty($v['active']) ? ' checked' : '' ?>> Aktiv (Dateien werden erzeugt)</label>

          <div class="pd-form-actions">
            <button class="pd-btn pd-btn--primary" name="action" value="publisher_save">Speichern</button>
            <a class="pd-btn pd-btn--ghost" href="<?= h(ui_url('publisher')) ?>">Abbrechen</a>
          </div>
        </form>
      </section>

      <?php if (!$isNew):
          $sources = adstxt_sources($p['id']);
          $blocks = adstxt_blocks($p['id']); ?>
      <div style="display:flex;flex-direction:column;gap:var(--space-6)">
        <section class="pd-card">
          <div class="pd-card__head"><h2 class="pd-over">Dateien</h2></div>
          <div class="pd-card__body" style="gap:var(--space-3)">
            <?php foreach (['ads' => 'wants_ads', 'app-ads' => 'wants_app_ads'] as $type => $flag): if (!$p[$flag]) continue; $o = adstxt_output($p['id'], $type); ?>
              <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--space-3)">
                <div><b><?= h(ADSTXT_FILE_NAMES[$type]) ?></b><div class="pd-t-sub"><?= $o ? (int) $o['record_count'] . ' Einträge · ' . h(ui_date($o['changed_at'])) : 'noch nicht erzeugt' ?></div></div>
                <a class="pd-btn pd-btn--ghost pd-btn--sm" href="<?= h(ui_url('vorschau', ['id' => $p['id'], 'typ' => $type])) ?>">Vorschau</a>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
        <section class="pd-card">
          <div class="pd-card__head"><h2 class="pd-over">Eigene Quellen (<?= count($sources) ?>)</h2>
            <a class="pd-btn pd-btn--ghost pd-btn--sm" href="<?= h(ui_url('quelle-bearbeiten', ['publisher' => $p['id']])) ?>"><?= ui_icon('plus') ?> Quelle</a></div>
          <div class="pd-card__body" style="gap:0;padding-top:var(--space-2);padding-bottom:var(--space-2)">
            <?php if (!$sources): ?><p class="pd-t-sub" style="margin:8px 0">Keine. Globale Quellen gelten trotzdem.</p><?php endif; ?>
            <ul class="pd-list"><?php foreach ($sources as $s): ?>
              <li><a href="<?= h(ui_url('quelle-bearbeiten', ['id' => $s['id']])) ?>"><?= h($s['name']) ?></a> · <?= h(ADSTXT_FILE_TYPES[$s['file_type']]) ?> <?= ui_badge($s['active'] ? $s['last_status'] : 'off') ?></li>
            <?php endforeach; ?></ul>
          </div>
        </section>
        <section class="pd-card">
          <div class="pd-card__head"><h2 class="pd-over">Eigene Einträge (<?= count($blocks) ?>)</h2>
            <a class="pd-btn pd-btn--ghost pd-btn--sm" href="<?= h(ui_url('eintrag-bearbeiten', ['publisher' => $p['id']])) ?>"><?= ui_icon('plus') ?> Eintrag</a></div>
          <div class="pd-card__body" style="gap:0;padding-top:var(--space-2);padding-bottom:var(--space-2)">
            <?php if (!$blocks): ?><p class="pd-t-sub" style="margin:8px 0">Keine. Globale Einträge gelten trotzdem.</p><?php endif; ?>
            <ul class="pd-list"><?php foreach ($blocks as $b): ?>
              <li><a href="<?= h(ui_url('eintrag-bearbeiten', ['id' => $b['id']])) ?>"><?= h($b['name']) ?></a> · <?= h(ADSTXT_FILE_TYPES[$b['file_type']]) ?><?= $b['active'] ? '' : ' ' . ui_badge('off') ?></li>
            <?php endforeach; ?></ul>
          </div>
        </section>
        <form method="post" action="<?= h(ui_url('publisher-bearbeiten', ['id' => $p['id']])) ?>"><?= ui_csrf() ?>
          <?php if ($p['active']): ?>
            <button class="pd-btn pd-btn--danger pd-btn--block" name="action" value="publisher_toggle"
                    data-confirm="„<?= h($p['name']) ?>“ deaktivieren? Für diesen Publisher werden dann keine Dateien mehr erzeugt.">Publisher deaktivieren</button>
          <?php else: ?>
            <button class="pd-btn pd-btn--secondary pd-btn--block" name="action" value="publisher_toggle">Publisher wieder aktivieren</button>
          <?php endif; ?>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php
    ui_end();
}

/* ------------------------------------------------------------------------
 * Quellen
 * ---------------------------------------------------------------------- */
function page_sources(): void
{
    $scope = (string) ($_GET['gilt'] ?? '');
    $sources = match (true) {
        $scope === 'global' => adstxt_sources(null, true),
        ctype_digit($scope) => adstxt_sources((int) $scope),
        default => adstxt_sources(),
    };
    $publishers = adstxt_publishers();

    ui_start('quellen', 'Quellen', [['Quellen', null]],
        '<a class="pd-btn pd-btn--primary pd-btn--sm" href="' . h(ui_url('quelle-bearbeiten')) . '">' . ui_icon('plus') . ' Quelle anlegen</a>');
    ?>
    <div class="pd-head"><div><div class="pd-over">Bausteine</div><h1>Quellen</h1>
      <p>Externe Adressen, die bei jeder Aktualisierung abgerufen werden. Schlägt ein Abruf fehl oder sieht der Inhalt unplausibel aus, bleibt der letzte gültige Stand aktiv.</p></div></div>

    <section class="pd-card">
      <div class="pd-toolbar">
        <form method="get">
          <input type="hidden" name="seite" value="quellen">
          <label class="pd-label" for="gilt">Gilt für</label>
          <select class="pd-input pd-select" id="gilt" name="gilt" onchange="this.form.submit()">
            <option value="">Alle anzeigen</option>
            <option value="global"<?= $scope === 'global' ? ' selected' : '' ?>>Nur globale</option>
            <?php foreach ($publishers as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $scope === (string) $p['id'] ? ' selected' : '' ?>>Nur <?= h($p['name']) ?></option><?php endforeach; ?>
          </select>
          <noscript><button class="pd-btn pd-btn--secondary pd-btn--sm">Filtern</button></noscript>
        </form>
      </div>
      <?php if (!$sources): ?>
        <div class="pd-empty"><b>Keine Quellen</b><a href="<?= h(ui_url('quelle-bearbeiten')) ?>">Quelle anlegen</a></div>
      <?php else: ?>
      <div class="pd-table-wrap"><table class="pd-table">
        <thead><tr><th>Quelle</th><th>Gilt für</th><th>Datei</th><th>Status</th><th class="pd-num">Einträge</th><th>Letzter Abruf</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sources as $s): ?>
          <tr class="<?= $s['active'] ? '' : 'is-muted' ?>">
            <td><a class="pd-t-strong" href="<?= h(ui_url('quelle-bearbeiten', ['id' => $s['id']])) ?>"><?= h($s['name']) ?></a>
              <span class="pd-t-sub pd-t-trunc" title="<?= h($s['url']) ?>"><?= h($s['url']) ?></span></td>
            <td><?= ui_scope($s['publisher_name']) ?></td>
            <td><?= h(ADSTXT_FILE_TYPES[$s['file_type']]) ?></td>
            <td><?= $s['active'] ? ui_badge($s['last_status']) : ui_badge('off') ?>
              <?php if ($s['last_message'] && $s['last_status'] !== 'ok'): ?><div class="pd-t-sub pd-t-trunc" title="<?= h($s['last_message']) ?>"><?= h($s['last_message']) ?></div><?php endif; ?></td>
            <td class="pd-num"><?= $s['cached_records'] !== null ? (int) $s['cached_records'] : '—' ?></td>
            <td><?= h(ui_date($s['last_fetch_at'])) ?></td>
            <td><div class="pd-t-actions">
              <?php if ($s['cached_records'] !== null): ?><a class="pd-btn pd-btn--ghost pd-btn--sm" href="<?= h(ui_url('quelle-inhalt', ['id' => $s['id']])) ?>">Inhalt</a><?php endif; ?>
              <form method="post" action="<?= h(ui_url('quellen', ['id' => $s['id']])) ?>"><?= ui_csrf() ?><input type="hidden" name="return" value="quellen">
                <button class="pd-btn pd-btn--ghost pd-btn--sm" name="action" value="source_fetch" data-busy="Rufe ab …"><span>Abrufen</span></button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
    <?php
    ui_end();
}

function page_source_form(?int $id, ?array $form, array $errors): void
{
    $s = $id ? adstxt_source($id) : null;
    if ($id && !$s) {
        ui_redirect('quellen', [], 'Quelle nicht gefunden.', 'error');
    }
    $preset = isset($_GET['publisher']) ? (int) $_GET['publisher'] : null;
    $v = $form ?? $s ?? ['name' => '', 'url' => '', 'publisher_id' => $preset, 'file_type' => 'ads', 'active' => 1];
    $selected = ($v['publisher_id'] ?? '') === '' || $v['publisher_id'] === null ? null : (int) $v['publisher_id'];
    $title = $s ? $s['name'] : 'Quelle anlegen';

    ui_start('quellen', $title, [['Quellen', ui_url('quellen')], [$title, null]]);
    ?>
    <div class="pd-head"><div><div class="pd-over">Quelle</div><h1><?= h($title) ?></h1></div>
      <?php if ($s): ?><?= $s['active'] ? ui_badge($s['last_status']) : ui_badge('off') ?><?php endif; ?></div>

    <div class="pd-grid-side">
      <section class="pd-card">
        <div class="pd-card__head"><h2 class="pd-over">Einstellungen</h2></div>
        <form class="pd-card__body" method="post" action="<?= h(ui_url('quelle-bearbeiten', ['id' => $id])) ?>" novalidate>
          <?= ui_csrf() ?>
          <div class="<?= ui_field_class($errors, 'name') ?>">
            <label class="pd-label" for="name">Name</label>
            <input class="pd-input" id="name" name="name" required maxlength="120" value="<?= h($v['name']) ?>" placeholder="z. B. Dienstleister X – Hauptliste">
            <?= ui_field_error($errors, 'name') ?>
          </div>
          <div class="<?= ui_field_class($errors, 'url') ?>">
            <label class="pd-label" for="url">Adresse</label>
            <input class="pd-input" id="url" name="url" type="url" required maxlength="500" value="<?= h($v['url']) ?>" placeholder="https://dienstleister.example/ads.txt" inputmode="url">
            <span class="pd-hint">Muss Text im ads.txt-Format liefern. Zugangsdaten gehören nicht in die Adresse.</span>
            <?= ui_field_error($errors, 'url') ?>
          </div>
          <?= ui_scope_select($selected, adstxt_publishers(), $errors) ?>
          <?= ui_type_radios((string) $v['file_type'], $errors) ?>
          <label class="pd-check"><input type="checkbox" name="active" value="1"<?= !empty($v['active']) ? ' checked' : '' ?>> Aktiv</label>
          <div class="pd-form-actions">
            <button class="pd-btn pd-btn--primary" name="action" value="source_save_fetch" data-busy="Speichere und rufe ab …"><span>Speichern &amp; abrufen</span></button>
            <button class="pd-btn pd-btn--secondary" name="action" value="source_save">Nur speichern</button>
            <span class="pd-spacer"></span>
            <?php if ($s): ?><button class="pd-btn pd-btn--danger" name="action" value="source_delete" data-confirm="Quelle „<?= h($s['name']) ?>“ endgültig löschen?">Löschen</button><?php endif; ?>
          </div>
        </form>
      </section>
      <?php if ($s): ?>
      <section class="pd-card">
        <div class="pd-card__head"><h2 class="pd-over">Letzter Abruf</h2></div>
        <div class="pd-card__body" style="gap:var(--space-3)">
          <div><?= ui_badge($s['last_status']) ?> <span class="pd-t-sub"><?= h(ui_date($s['last_fetch_at'])) ?></span></div>
          <?php if ($s['last_message']): ?><p class="pd-t-sub" style="margin:0"><?= h($s['last_message']) ?></p><?php endif; ?>
          <div class="pd-t-sub">Aktiver Stand: <?= $s['cached_records'] !== null ? (int) $s['cached_records'] . ' Einträge vom ' . h(ui_date($s['cached_at'])) : 'noch keiner' ?></div>
          <?php if ($s['cached_records'] !== null): ?><a class="pd-btn pd-btn--ghost pd-btn--sm" href="<?= h(ui_url('quelle-inhalt', ['id' => $s['id']])) ?>">Inhalt ansehen</a><?php endif; ?>
        </div>
      </section>
      <?php endif; ?>
    </div>
    <?php
    ui_end();
}

function page_source_content(?int $id): void
{
    $s = $id ? adstxt_source($id) : null;
    if (!$s || $s['cached_content'] === null) {
        ui_redirect('quellen', [], 'Für diese Quelle gibt es noch keinen gespeicherten Inhalt.', 'warning');
    }
    $a = adstxt_analyze($s['cached_content']);
    ui_start('quellen', $s['name'], [['Quellen', ui_url('quellen')], [$s['name'], ui_url('quelle-bearbeiten', ['id' => $id])], ['Inhalt', null]]);
    ?>
    <div class="pd-head"><div><div class="pd-over">Aktiver Stand</div><h1><?= h($s['name']) ?></h1>
      <p><?= (int) $a['records'] ?> Einträge, <?= (int) $a['variables'] ?> Kopfzeilen, abgerufen <?= h(ui_date($s['cached_at'])) ?>.</p></div></div>
    <?php if ($a['invalid']): ?>
      <section class="pd-card"><div class="pd-card__head"><h2 class="pd-over">Ungültige Zeilen (werden ignoriert)</h2></div>
        <div class="pd-card__body"><ul class="pd-list"><?php foreach (array_slice($a['invalid'], 0, 100) as $inv): ?>
          <li><span class="pd-t-mono">Zeile <?= (int) $inv['line_no'] ?>: <?= h($inv['raw']) ?></span> – <?= h($inv['message']) ?></li>
        <?php endforeach; ?></ul></div></section>
    <?php endif; ?>
    <pre class="pd-pre"><?= h($s['cached_content']) ?></pre>
    <?php
    ui_end();
}

/* ------------------------------------------------------------------------
 * Einträge (manuelle Blöcke)
 * ---------------------------------------------------------------------- */
function page_blocks(): void
{
    $scope = (string) ($_GET['gilt'] ?? '');
    $blocks = match (true) {
        $scope === 'global' => adstxt_blocks(null, true),
        ctype_digit($scope) => adstxt_blocks((int) $scope),
        default => adstxt_blocks(),
    };
    $publishers = adstxt_publishers();

    ui_start('eintraege', 'Einträge', [['Einträge', null]],
        '<a class="pd-btn pd-btn--primary pd-btn--sm" href="' . h(ui_url('eintrag-bearbeiten')) . '">' . ui_icon('plus') . ' Eintrag anlegen</a>');
    ?>
    <div class="pd-head"><div><div class="pd-over">Bausteine</div><h1>Einträge</h1>
      <p>Manuell gepflegte Zeilen, zu Blöcken gruppiert (z. B. „Google“). Globale Einträge gelten für alle Publisher – so muss eine Änderung nur einmal gemacht werden.</p></div></div>
    <section class="pd-card">
      <div class="pd-toolbar">
        <form method="get">
          <input type="hidden" name="seite" value="eintraege">
          <label class="pd-label" for="gilt">Gilt für</label>
          <select class="pd-input pd-select" id="gilt" name="gilt" onchange="this.form.submit()">
            <option value="">Alle anzeigen</option>
            <option value="global"<?= $scope === 'global' ? ' selected' : '' ?>>Nur globale</option>
            <?php foreach ($publishers as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $scope === (string) $p['id'] ? ' selected' : '' ?>>Nur <?= h($p['name']) ?></option><?php endforeach; ?>
          </select>
          <noscript><button class="pd-btn pd-btn--secondary pd-btn--sm">Filtern</button></noscript>
        </form>
      </div>
      <?php if (!$blocks): ?>
        <div class="pd-empty"><b>Keine Einträge</b><a href="<?= h(ui_url('eintrag-bearbeiten')) ?>">Eintrag anlegen</a></div>
      <?php else: ?>
      <div class="pd-table-wrap"><table class="pd-table">
        <thead><tr><th>Eintrag</th><th>Gilt für</th><th>Datei</th><th class="pd-num">Zeilen</th><th>Prüfung</th><th>Geändert</th></tr></thead>
        <tbody>
        <?php foreach ($blocks as $b): $a = adstxt_analyze($b['content']); ?>
          <tr class="<?= $b['active'] ? '' : 'is-muted' ?>">
            <td><a class="pd-t-strong" href="<?= h(ui_url('eintrag-bearbeiten', ['id' => $b['id']])) ?>"><?= h($b['name']) ?></a><?= $b['active'] ? '' : ' ' . ui_badge('off') ?></td>
            <td><?= ui_scope($b['publisher_name']) ?></td>
            <td><?= h(ADSTXT_FILE_TYPES[$b['file_type']]) ?></td>
            <td class="pd-num"><?= $a['records'] + $a['variables'] ?></td>
            <td><?= $a['invalid'] ? ui_badge('warning', count($a['invalid']) . ' ungültig') : ui_badge('ok') ?></td>
            <td><?= h(ui_date($b['updated_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
    <?php
    ui_end();
}

function page_block_form(?int $id, ?array $form, array $errors, ?array $analysis): void
{
    $b = $id ? adstxt_block($id) : null;
    if ($id && !$b) {
        ui_redirect('eintraege', [], 'Eintrag nicht gefunden.', 'error');
    }
    $preset = isset($_GET['publisher']) ? (int) $_GET['publisher'] : null;
    $v = $form ?? $b ?? ['name' => '', 'publisher_id' => $preset, 'file_type' => 'ads', 'content' => '', 'active' => 1];
    $analysis ??= $b ? adstxt_analyze($b['content']) : null;
    $selected = ($v['publisher_id'] ?? '') === '' || $v['publisher_id'] === null ? null : (int) $v['publisher_id'];
    $title = $b ? $b['name'] : 'Eintrag anlegen';

    ui_start('eintraege', $title, [['Einträge', ui_url('eintraege')], [$title, null]]);
    ?>
    <div class="pd-head"><div><div class="pd-over">Eintrag</div><h1><?= h($title) ?></h1></div></div>
    <div class="pd-grid-side">
      <section class="pd-card">
        <div class="pd-card__head"><h2 class="pd-over">Inhalt</h2></div>
        <form class="pd-card__body" method="post" action="<?= h(ui_url('eintrag-bearbeiten', ['id' => $id])) ?>" novalidate>
          <?= ui_csrf() ?>
          <div class="<?= ui_field_class($errors, 'name') ?>">
            <label class="pd-label" for="name">Name</label>
            <input class="pd-input" id="name" name="name" required maxlength="120" value="<?= h($v['name']) ?>" placeholder="z. B. Google AdX direkt">
            <?= ui_field_error($errors, 'name') ?>
          </div>
          <div class="pd-row">
            <?= ui_scope_select($selected, adstxt_publishers(), $errors) ?>
            <?= ui_type_radios((string) $v['file_type'], $errors) ?>
          </div>
          <div class="<?= ui_field_class($errors, 'content') ?>">
            <label class="pd-label" for="content">Zeilen</label>
            <textarea class="pd-textarea" id="content" name="content" rows="12" spellcheck="false"
                      placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0"><?= h($v['content']) ?></textarea>
            <span class="pd-hint">Eine Zeile pro Eintrag: Domain, Konto-ID, DIRECT/RESELLER, optional Zertifizierungs-ID. Kommentare mit #. Schreibweise wird automatisch vereinheitlicht.</span>
            <?= ui_field_error($errors, 'content') ?>
          </div>
          <label class="pd-check"><input type="checkbox" name="active" value="1"<?= !empty($v['active']) ? ' checked' : '' ?>> Aktiv</label>
          <div class="pd-form-actions">
            <button class="pd-btn pd-btn--primary" name="action" value="block_save">Speichern</button>
            <button class="pd-btn pd-btn--secondary" name="action" value="block_check">Nur prüfen</button>
            <span class="pd-spacer"></span>
            <?php if ($b): ?><button class="pd-btn pd-btn--danger" name="action" value="block_delete" data-confirm="Eintrag „<?= h($b['name']) ?>“ endgültig löschen?">Löschen</button><?php endif; ?>
          </div>
        </form>
      </section>
      <section class="pd-card" aria-live="polite">
        <div class="pd-card__head"><h2 class="pd-over">Prüfung</h2></div>
        <div class="pd-card__body" style="gap:var(--space-3)">
          <?php if ($analysis === null): ?>
            <p class="pd-t-sub" style="margin:0">Mit „Nur prüfen“ siehst du vor dem Speichern, ob alle Zeilen gültig sind.</p>
          <?php else: ?>
            <div><?= $analysis['invalid'] ? ui_badge('warning', count($analysis['invalid']) . ' ungültige Zeile(n)') : ui_badge('ok', 'Alle Zeilen gültig') ?></div>
            <div class="pd-t-sub"><?= (int) $analysis['records'] ?> Einträge · <?= (int) $analysis['variables'] ?> Kopfzeilen</div>
            <?php if ($analysis['invalid']): ?><ul class="pd-list"><?php foreach ($analysis['invalid'] as $inv): ?>
              <li><b>Zeile <?= (int) $inv['line_no'] ?>:</b> <?= h($inv['message']) ?><div class="pd-t-mono pd-t-sub"><?= h($inv['raw']) ?></div></li>
            <?php endforeach; ?></ul><p class="pd-t-sub" style="margin:0">Ungültige Zeilen werden beim Erzeugen ignoriert.</p><?php endif; ?>
          <?php endif; ?>
        </div>
      </section>
    </div>
    <?php
    ui_end();
}

/* ------------------------------------------------------------------------
 * Vorschau
 * ---------------------------------------------------------------------- */
function page_preview(?int $id, string $type): void
{
    $publishers = adstxt_publishers(false);
    $p = $id ? adstxt_publisher($id) : ($publishers[0] ?? null);
    if (!$p) {
        ui_start('vorschau', 'Vorschau', [['Vorschau', null]]);
        echo '<div class="pd-card"><div class="pd-empty"><b>Noch keine Publisher</b><a href="' . h(ui_url('publisher-bearbeiten')) . '">Publisher anlegen</a></div></div>';
        ui_end();
        return;
    }
    $types = array_keys(array_filter(['ads' => $p['wants_ads'], 'app-ads' => $p['wants_app_ads']]));
    if (!in_array($type, $types, true)) {
        $type = $types[0] ?? 'ads';
    }
    $build = adstxt_preview($p, $type);
    $stored = adstxt_output($p['id'], $type);
    $pending = $stored === null || $stored['body_hash'] !== $build['body_hash'];

    ui_start('vorschau', 'Vorschau', [['Vorschau', null], [$p['name'], null]], ui_run_button('vorschau'));
    ?>
    <div class="pd-head">
      <div><div class="pd-over">Vorschau</div><h1><?= h($p['name']) ?> · <?= h(ADSTXT_FILE_NAMES[$type]) ?></h1>
        <p><?= (int) $build['record_count'] ?> Einträge aus aktuellen Quellen und Einträgen.
          <?= $stored ? 'Gespeicherter Stand vom ' . h(ui_date($stored['generated_at'])) . '.' : 'Noch nicht gespeichert.' ?></p></div>
      <form method="get" style="display:flex;gap:var(--space-2);align-items:center">
        <input type="hidden" name="seite" value="vorschau">
        <label class="pd-label" for="pub" style="position:absolute;left:-9999px">Publisher</label>
        <select class="pd-input pd-select" id="pub" name="id" onchange="this.form.submit()" style="min-height:36px;font-size:var(--fs-sm)">
          <?php foreach ($publishers as $o): ?><option value="<?= (int) $o['id'] ?>"<?= (int) $o['id'] === (int) $p['id'] ? ' selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="pd-btn pd-btn--secondary pd-btn--sm">Anzeigen</button></noscript>
      </form>
    </div>

    <?php if (!$p['active']): ?><div class="pd-alert pd-alert--warning">Dieser Publisher ist deaktiviert – es werden keine Dateien erzeugt.</div><?php endif; ?>
    <?php if ($pending && $p['active']): ?>
      <div class="pd-alert pd-alert--warning">Die Vorschau weicht vom gespeicherten Stand ab. Die Änderungen werden bei der nächsten Aktualisierung übernommen.</div>
    <?php endif; ?>

    <div class="pd-grid-side">
      <section class="pd-card">
        <nav class="pd-tabs" aria-label="Datei">
          <?php foreach ($types as $t): ?>
            <a class="pd-tab<?= $t === $type ? ' is-active' : '' ?>" href="<?= h(ui_url('vorschau', ['id' => $p['id'], 'typ' => $t])) ?>"<?= $t === $type ? ' aria-current="page"' : '' ?>><?= h(ADSTXT_FILE_NAMES[$t]) ?></a>
          <?php endforeach; ?>
        </nav>
        <div class="pd-card__body">
          <pre class="pd-pre"><?= h($build['content']) ?></pre>
          <div class="pd-form-actions">
            <?php if ($stored): ?><a class="pd-btn pd-btn--secondary pd-btn--sm" href="<?= h(ui_url('download', ['id' => $p['id'], 'typ' => $type])) ?>"><?= ui_icon('download') ?> Gespeicherten Stand herunterladen</a><?php endif; ?>
          </div>
        </div>
      </section>
      <section class="pd-card">
        <div class="pd-card__head"><h2 class="pd-over">Prüfbericht</h2></div>
        <div class="pd-card__body" style="gap:var(--space-4)">
          <div><b><?= count($build['duplicates']) ?> Dubletten entfernt</b>
            <?php if ($build['duplicates']): ?><details class="pd-details"><summary>Anzeigen</summary><ul class="pd-list">
              <?php foreach (array_slice($build['duplicates'], 0, 200) as $d): ?><li><span class="pd-t-mono"><?= h($d['line']) ?></span><div class="pd-t-sub">aus „<?= h($d['label']) ?>“, behalten aus „<?= h($d['kept_in']) ?>“</div></li><?php endforeach; ?>
            </ul></details><?php endif; ?></div>
          <div><b><?= count($build['overridden']) ?> Kopfzeilen überstimmt</b>
            <?php if ($build['overridden']): ?><details class="pd-details"><summary>Anzeigen</summary><ul class="pd-list">
              <?php foreach ($build['overridden'] as $o): ?><li><span class="pd-t-mono"><?= h($o['line']) ?></span><div class="pd-t-sub">aus „<?= h($o['label']) ?>“ – <?= h($o['reason']) ?></div></li><?php endforeach; ?>
            </ul></details><?php endif; ?></div>
          <div><b><?= count($build['invalid']) ?> ungültige Zeilen ignoriert</b>
            <?php if ($build['invalid']): ?><details class="pd-details"<?= count($build['invalid']) <= 5 ? ' open' : '' ?>><summary>Anzeigen</summary><ul class="pd-list">
              <?php foreach (array_slice($build['invalid'], 0, 200) as $i): ?><li><span class="pd-t-mono"><?= h($i['raw']) ?></span><div class="pd-t-sub">aus „<?= h($i['label']) ?>“ – <?= h($i['message']) ?></div></li><?php endforeach; ?>
            </ul></details><?php endif; ?></div>
          <p class="pd-t-sub" style="margin:0">Vorrang bei Kopfzeilen: Publisher-Kopfzeilen → eigene Einträge → eigene Quellen → globale Einträge → globale Quellen.</p>
        </div>
      </section>
    </div>
    <?php
    ui_end();
}

/* ------------------------------------------------------------------------
 * Protokoll
 * ---------------------------------------------------------------------- */
function page_log(): void
{
    $runs = db_all('SELECT * FROM adstxt_runs ORDER BY id DESC LIMIT 30');
    $changes = db_all('SELECT c.*, p.name AS publisher_name FROM adstxt_changes c JOIN adstxt_publishers p ON p.id = c.publisher_id ORDER BY c.id DESC LIMIT 100');

    ui_start('protokoll', 'Protokoll', [['Protokoll', null]]);
    ?>
    <div class="pd-head"><div><div class="pd-over">Verlauf</div><h1>Protokoll</h1>
      <p>Welche Zeilen sich wann in welcher Datei geändert haben, und wie die Aktualisierungen gelaufen sind.</p></div></div>

    <section class="pd-card">
      <div class="pd-card__head"><h2 class="pd-over">Dateiänderungen (letzte 100)</h2></div>
      <?php if (!$changes): ?><div class="pd-empty"><b>Noch keine Änderungen</b>Nach der ersten Aktualisierung erscheinen sie hier.</div><?php else: ?>
      <div class="pd-table-wrap"><table class="pd-table">
        <thead><tr><th>Zeitpunkt</th><th>Publisher</th><th>Datei</th><th>Änderung</th></tr></thead>
        <tbody>
        <?php foreach ($changes as $c): ?>
          <tr>
            <td style="white-space:nowrap"><?= h(ui_date($c['changed_at'])) ?></td>
            <td><a href="<?= h(ui_url('vorschau', ['id' => $c['publisher_id'], 'typ' => $c['file_type']])) ?>"><?= h($c['publisher_name']) ?></a></td>
            <td><?= h(ADSTXT_FILE_NAMES[$c['file_type']]) ?></td>
            <td><span class="pd-diff-add"><?= (int) $c['added_count'] ?></span> &nbsp; <span class="pd-diff-del"><?= (int) $c['removed_count'] ?></span>
              <?php if ($c['added_count'] + $c['removed_count'] > 0): ?>
              <details class="pd-details"><summary>Zeilen anzeigen</summary><div class="pd-t-mono" style="line-height:1.7">
                <?php foreach (array_filter(explode("\n", $c['added'])) as $l): ?><div class="pd-diff-add"><?= h($l) ?></div><?php endforeach; ?>
                <?php foreach (array_filter(explode("\n", $c['removed'])) as $l): ?><div class="pd-diff-del"><?= h($l) ?></div><?php endforeach; ?>
              </div></details><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>

    <section class="pd-card">
      <div class="pd-card__head"><h2 class="pd-over">Aktualisierungen (letzte 30)</h2></div>
      <?php if (!$runs): ?><div class="pd-empty"><b>Noch keine Aktualisierung</b></div><?php else: ?>
      <div class="pd-table-wrap"><table class="pd-table">
        <thead><tr><th>Start</th><th>Auslöser</th><th>Status</th><th>Quellen ok / Warnung / Fehler</th><th class="pd-num">Dateien</th><th class="pd-num">Geändert</th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r): $s = json_decode((string) $r['summary'], true) ?: []; ?>
          <tr>
            <td style="white-space:nowrap"><?= h(ui_date($r['started_at'])) ?></td>
            <td><?= $r['trigger_type'] === 'cron' ? 'automatisch' : 'manuell' ?></td>
            <td><?= ui_badge($r['status']) ?><?php if (!empty($s['error'])): ?><div class="pd-t-sub"><?= h($s['error']) ?></div><?php endif; ?></td>
            <td><?= isset($s['sources']) ? (int) $s['sources']['ok'] . ' / ' . (int) $s['sources']['warning'] . ' / ' . (int) $s['sources']['error'] : '—' ?></td>
            <td class="pd-num"><?= (int) ($s['files'] ?? 0) ?></td>
            <td class="pd-num"><?= isset($s['changed']) ? count($s['changed']) : 0 ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
    <?php
    ui_end();
}
