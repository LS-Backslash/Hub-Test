<?php
declare(strict_types=1);

/**
 * Einmaliger Import: überträgt Creatives aus der früheren JSON-Ablage
 * (home/data/epaper/<verlag>/<ordner>.json) in die Tabelle epaper_creatives.
 * Mehrfaches Ausführen ist unschädlich (vorhandene Einträge werden übersprungen).
 *
 * Aufruf: php8.4 /data/wwwcust/ads/home/bin/import-epaper-json.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';
require APP_HOME . '/lib/epaper/core.php';

$files = glob(APP_DATA . '/' . EPAPER_META_DIR . '/*/*.json') ?: [];
$imported = $skipped = 0;

foreach ($files as $file) {
    $m = json_decode((string) file_get_contents($file), true);
    if (!is_array($m) || !isset($m['publisher'], $m['folder'], $m['files'])) {
        echo "übersprungen (unlesbar): {$file}\n";
        $skipped++;
        continue;
    }
    $stmt = db_run(
        'INSERT IGNORE INTO epaper_creatives
            (publisher, folder, customer, pub_date, link,
             landscape_file, landscape_width, landscape_height,
             portrait_file, portrait_width, portrait_height,
             live_url, uploaded_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $m['publisher'], $m['folder'], $m['customer'], $m['date'], $m['link'],
            $m['files']['landscape']['file'], $m['files']['landscape']['width'], $m['files']['landscape']['height'],
            $m['files']['portrait']['file'], $m['files']['portrait']['width'], $m['files']['portrait']['height'],
            $m['live_url'] ?? null,
            isset($m['uploaded_at']) ? date('Y-m-d H:i:s', strtotime($m['uploaded_at'])) : null,
            date('Y-m-d H:i:s', strtotime($m['created_at'] ?? 'now')),
            date('Y-m-d H:i:s', strtotime($m['updated_at'] ?? 'now')),
        ]
    );
    if ($stmt->rowCount() === 1) {
        $imported++;
    } else {
        $skipped++;
    }
}

echo "Importiert: {$imported}, übersprungen: {$skipped}, Dateien gesamt: " . count($files) . "\n";
if ($imported > 0) {
    echo "Die JSON-Dateien unter home/data/epaper/ werden nicht mehr gebraucht und können gelöscht werden.\n";
}
