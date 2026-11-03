<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/home/lib/bootstrap.php';
require APP_HOME . '/lib/auth.php';

auth_require('adstxt', 'Ads.txt Organizer');

// --- ab hier nur für angemeldete Nutzer ---
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Ads.txt Organizer</title>
</head>
<body>
<h1>Ads.txt Organizer</h1>
<p>Grundgerüst – Fachlogik folgt.</p>
<form method="post">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <button name="action" value="logout">Abmelden</button>
</form>
</body>
</html>
