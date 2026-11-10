<?php
// Vorlage: auf dem Server nach config.php kopieren und Werte eintragen.
// config.php wird NICHT ins Git eingecheckt (enthält das Datenbank-Passwort).
//   cp config.example.php config.php && chmod 640 config.php
return [
    'http_user_agent' => 'pu-webintern/1.0 (ads.mypdv.de)',

    // MySQL 8 auf demselben Server (nur über localhost erreichbar).
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'ads',
        'user' => 'ads',
        'pass' => 'HIER_PASSWORT_EINTRAGEN',
    ],

    // Externer Live-Server (SFTP). Solange Benutzer/Passwort Platzhalter sind, wird nichts hochgeladen.
    'live' => [
        'host' => 'webextern.pdv1.mediengruppe.pd',
        'port' => 22,
        'user' => 'HIER_BENUTZER_EINTRAGEN',
        'pass' => 'HIER_PASSWORT_EINTRAGEN',
        'path' => '/var/www/html/ads/www',          // Zielordner auf dem Live-Server
        'url' => 'https://ads.pddigital.de',         // unter dieser Adresse ist der Zielordner öffentlich erreichbar
        'known_hosts' => '/data/wwwcust/ads/home/.ssh/known_hosts',
    ],
];
