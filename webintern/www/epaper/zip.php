<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/home/lib/bootstrap.php';
require APP_HOME . '/lib/epaper.php';

$folder = (string) ($_GET['folder'] ?? '');
if (!epaper_folder_exists($folder)) {
    http_response_code(404);
    exit('Creative nicht gefunden.');
}

$zip = epaper_zip($folder);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $folder . '.zip"');
header('Content-Length: ' . filesize($zip));
header('X-Content-Type-Options: nosniff');
readfile($zip);
unlink($zip);
