<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/home/lib/bootstrap.php';
require APP_HOME . '/lib/epaper.php';

$id = (string) ($_GET['id'] ?? '');
if (!epaper_exists($id)) {
    http_response_code(404);
    exit('Creative nicht gefunden.');
}

$zip = epaper_zip($id);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . str_replace('/', '_', $id) . '.zip"');
header('Content-Length: ' . filesize($zip));
header('X-Content-Type-Options: nosniff');
readfile($zip);
unlink($zip);
