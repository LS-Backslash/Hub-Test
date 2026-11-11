<?php
declare(strict_types=1);

/**
 * Verbindungstest Google Ad Manager – NUR LESEND, legt nichts an und ändert nichts.
 * Aufruf: php8.4 /data/wwwcust/ads/home/bin/gam-test.php
 *
 * Gibt keine Schlüssel aus. Bei Aufträgen/Werbebuchungen/Werbetreibenden nur Anzahlen (keine Kundennamen).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';
require APP_HOME . '/lib/gam.php';

$problems = 0;
function row(?bool $ok, string $label, string $detail = ''): void
{
    global $problems;
    if ($ok === false) {
        $problems++;
    }
    echo ($ok === null ? '  ·  ' : ($ok ? ' OK  ' : ' !!  ')) . mb_str_pad($label, 36) . $detail . "\n";
}

echo "\n== Konfiguration ==\n";
try {
    $cfg = gam_config();
    row(true, 'Netzwerk-Code', $cfg['network_code']);
    $key = gam_key();
    row(true, 'Schlüsseldatei', $cfg['key_file']);
    row(null, 'Dienstkonto', $key['client_email'] . ' (Projekt ' . ($key['project_id'] ?? '?') . ')');
    $perms = substr(sprintf('%o', fileperms($cfg['key_file'])), -3);
    row($perms === '600' || $perms === '400', 'Dateirechte Schlüssel', $perms . ($perms === '600' || $perms === '400' ? '' : ' → chmod 600 empfohlen'));
} catch (Throwable $e) {
    row(false, 'Konfiguration', $e->getMessage());
    exit(1);
}

echo "\n== Anmeldung ==\n";
try {
    gam_token();
    row(true, 'Zugriffstoken von Google', 'erhalten');
} catch (Throwable $e) {
    row(false, 'Zugriffstoken von Google', $e->getMessage());
    exit(1);
}

$net = $cfg['network_code'];
echo "\n== Neue REST-API (Beta) ==\n";
$rest = [
    'Netzwerk' => ["networks/{$net}", [], static fn ($d) => ($d['displayName'] ?? '?') . ' · Zeitzone ' . ($d['timeZone'] ?? '?') . ' · Währung ' . ($d['currencyCode'] ?? '?')],
    'Anzeigenblöcke (lesen)' => ["networks/{$net}/adUnits", ['pageSize' => 10], static fn ($d) => count($d['adUnits'] ?? []) . ' gelesen' . (isset($d['totalSize']) ? " von {$d['totalSize']}" : '')],
    'Placements (lesen)' => ["networks/{$net}/placements", ['pageSize' => 10], static fn ($d) => count($d['placements'] ?? []) . ' gelesen' . (isset($d['totalSize']) ? " von {$d['totalSize']}" : '')],
    'Aufträge (lesen)' => ["networks/{$net}/orders", ['pageSize' => 1], static fn ($d) => isset($d['totalSize']) ? "{$d['totalSize']} vorhanden" : 'lesbar'],
    'Werbebuchungen (lesen)' => ["networks/{$net}/lineItems", ['pageSize' => 1], static fn ($d) => isset($d['totalSize']) ? "{$d['totalSize']} vorhanden" : 'lesbar'],
    'Werbetreibende (lesen)' => ["networks/{$net}/companies", ['pageSize' => 1], static fn ($d) => isset($d['totalSize']) ? "{$d['totalSize']} vorhanden" : 'lesbar'],
    'Creatives (lesen)' => ["networks/{$net}/creatives", ['pageSize' => 1], static fn ($d) => isset($d['totalSize']) ? "{$d['totalSize']} vorhanden" : 'lesbar'],
    'Targeting-Schlüssel (lesen)' => ["networks/{$net}/customTargetingKeys", ['pageSize' => 1], static fn ($d) => isset($d['totalSize']) ? "{$d['totalSize']} vorhanden" : 'lesbar'],
];
$adUnits = [];
foreach ($rest as $label => [$path, $query, $describe]) {
    try {
        $d = gam_rest('GET', $path, $query);
        row(true, $label, $describe($d));
        if (str_ends_with($path, '/adUnits')) {
            $adUnits = $d['adUnits'] ?? [];
        }
    } catch (GamError $e) {
        row(false, $label, $e->getMessage());
    }
    usleep(300000); // schonend: max. ~3 Anfragen pro Sekunde
}
if ($adUnits) {
    echo "\n  Erste Anzeigenblöcke (Name · Code):\n";
    foreach (array_slice($adUnits, 0, 10) as $u) {
        echo '    - ' . ($u['displayName'] ?? '?') . ' · ' . ($u['adUnitCode'] ?? '?') . "\n";
    }
}

echo "\n== Alte SOAP-API ({$cfg['soap_version']}) ==\n";
try {
    $n = gam_soap('NetworkService', 'getCurrentNetwork');
    row(true, 'Netzwerk', ($n->displayName ?? '?') . ' · Code ' . ($n->networkCode ?? '?'));
} catch (Throwable $e) {
    row(false, 'Netzwerk', $e->getMessage());
}
try {
    $u = gam_soap('UserService', 'getCurrentUser');
    row(true, 'Rolle des Dienstkontos', ($u->roleName ?? '?') . ' (Rollen-ID ' . ($u->roleId ?? '?') . ')');
} catch (Throwable $e) {
    row(false, 'Rolle des Dienstkontos', $e->getMessage());
}

echo "\n" . ($problems === 0 ? "Alles in Ordnung.\n" : "{$problems} Punkt(e) mit !! – Ausgabe bitte an Claude schicken (enthält keine Schlüssel).\n");
exit($problems === 0 ? 0 : 1);
