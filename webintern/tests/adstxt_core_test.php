<?php
declare(strict_types=1);

/**
 * Tests für home/lib/adstxt/core.php (ohne Datenbank).
 * Aufruf: php webintern/tests/adstxt_core_test.php
 */

require __DIR__ . '/../home/lib/adstxt/core.php';

$failed = 0;
$count = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failed, $count;
    $count++;
    if ($actual === $expected) {
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}\n       erwartet: " . var_export($expected, true) . "\n       erhalten: " . var_export($actual, true) . "\n";
}

echo "Zeilen prüfen\n";
$p = adstxt_parse_line('  Google.com ,pub-1234567890 , direct, f08c47fec0942fa0  # Kommentar');
check('normalisiert Datensatz', $p['line'], 'google.com, pub-1234567890, DIRECT, f08c47fec0942fa0');
check('Typ record', $p['type'], 'record');
check('ohne Zertifizierungs-ID', adstxt_parse_line('appnexus.com,1234,RESELLER')['line'], 'appnexus.com, 1234, RESELLER');
check('Erweiterung bleibt', adstxt_parse_line('ex.com, 1, DIRECT; ext=1')['line'], 'ex.com, 1, DIRECT;ext=1');
check('Kommentarzeile', adstxt_parse_line('# hallo')['type'], 'comment');
check('Leerzeile', adstxt_parse_line("  \r")['type'], 'empty');
check('BOM am Anfang', adstxt_parse_line("\u{FEFF}google.com, pub-1, DIRECT")['type'], 'record');
check('falsche Beziehung', adstxt_parse_line('google.com, pub-1, PARTNER')['type'], 'invalid');
check('zu wenige Felder', adstxt_parse_line('google.com, pub-1')['type'], 'invalid');
check('ungültige Domain', adstxt_parse_line('google, pub-1, DIRECT')['type'], 'invalid');
check('Variable normalisiert', adstxt_parse_line('ownerdomain = Example.COM')['line'], 'OWNERDOMAIN=example.com');
check('CONTACT behält Schreibweise', adstxt_parse_line('contact=Ads@Example.com')['line'], 'CONTACT=Ads@Example.com');
check('unbekannte Variable', adstxt_parse_line('FOO=bar')['type'], 'invalid');
check('Dublette trotz Groß/Klein', adstxt_parse_line('GOOGLE.com, PUB-1, direct')['key'], adstxt_parse_line('google.com, pub-1, DIRECT')['key']);
check('DIRECT und RESELLER sind verschieden', adstxt_parse_line('x.com, 1, DIRECT')['key'] === adstxt_parse_line('x.com, 1, RESELLER')['key'], false);

echo "\nZusammenführen\n";
$pub = ['name' => 'Testverlag', 'domain' => 'test.de'];
$parts = [
    ['level' => ADSTXT_LEVEL_GLOBAL_SOURCE, 'label' => 'Quelle: Dienstleister A', 'text' => "OWNERDOMAIN=dienstleister.de\nCONTACT=ops@dienstleister.de\ngoogle.com, pub-1, DIRECT\nrubicon.com, 99, RESELLER\nkaputt, x"],
    ['level' => ADSTXT_LEVEL_PUBLISHER_SOURCE, 'label' => 'Quelle: Vermarkter Süd', 'text' => "MANAGERDOMAIN=vermarkter.de\nCONTACT=ads@vermarkter.de\nrubicon.com, 99, RESELLER\nopenx.com, 5, DIRECT"],
    ['level' => ADSTXT_LEVEL_PUBLISHER_HEADER, 'label' => 'Publisher-Kopfzeilen', 'text' => "OWNERDOMAIN=test.de"],
    ['level' => ADSTXT_LEVEL_GLOBAL_BLOCK, 'label' => 'Eintrag: Google direkt', 'text' => "google.com, pub-1, DIRECT, f08c47fec0942fa0\ngoogle.com, pub-1, RESELLER"],
];
$r = adstxt_build($pub, 'ads', $parts, new DateTimeImmutable('2026-11-05 12:00'));
$lines = array_values(array_filter(explode("\n", $r['content']), fn ($l) => $l !== '' && $l[0] !== '#'));
check('Kopfzeilen nach Vorrang', array_slice($lines, 0, 3), ['OWNERDOMAIN=test.de', 'MANAGERDOMAIN=vermarkter.de', 'CONTACT=ads@vermarkter.de']);
check('Dubletten über Quellen entfernt', $r['record_count'], 4);
check('rubicon doppelt erkannt', count(array_filter($r['duplicates'], fn ($d) => str_starts_with($d['line'], 'rubicon.com'))), 1);
check('erste Fundstelle gewinnt (Publisher vor global)', $r['origins']['rubicon.com, 99, RESELLER'], 'Quelle: Vermarkter Süd');
check('google aus globalem Eintrag vor globaler Quelle', $r['origins']['google.com, pub-1, DIRECT, f08c47fec0942fa0'], 'Eintrag: Google direkt');
check('ungültige Zeile gemeldet', count($r['invalid']), 1);
check('überstimmte Kopfzeilen gemeldet', count($r['overridden']), 2); // OWNERDOMAIN + CONTACT aus globaler Quelle
check('Titelzeile', explode("\n", $r['content'])[0], '# ads.txt für Testverlag (test.de)');
check('Zeitstempel nicht im Body', str_contains($r['body'], '05.11.2026'), false);

$r2 = adstxt_build($pub, 'ads', array_reverse($parts), new DateTimeImmutable('2026-11-06 08:00'));
check('Ergebnis unabhängig von Übergabereihenfolge', $r2['body_hash'], $r['body_hash']);

$single = adstxt_build($pub, 'app-ads', [
    ['level' => 2, 'label' => 'A', 'text' => "OWNERDOMAIN=a.de\nOWNERDOMAIN=b.de\nMANAGERDOMAIN=m1.de\nMANAGERDOMAIN=m2.de"],
]);
check('OWNERDOMAIN nur einmal', substr_count($single['content'], 'OWNERDOMAIN='), 1);
check('MANAGERDOMAIN mehrfach erlaubt', substr_count($single['content'], 'MANAGERDOMAIN='), 2);
check('app-ads.txt Titel', explode("\n", $single['content'])[0], '# app-ads.txt für Testverlag (test.de)');

$empty = adstxt_build($pub, 'ads', []);
check('leere Datei hat nur Kopfkommentar', substr_count($empty['content'], "\n"), 2);

echo "\nÄnderungen\n";
$d = adstxt_diff("A=1\nx.com, 1, DIRECT\n# k\ny.com, 2, DIRECT\n", "x.com, 1, DIRECT\nz.com, 3, RESELLER\n");
check('hinzugefügt', $d['added'], ['z.com, 3, RESELLER']);
check('entfernt', $d['removed'], ['A=1', 'y.com, 2, DIRECT']);

echo "\nQuellen bewerten\n";
$ok = fn (string $b) => ['ok' => true, 'status' => 200, 'body' => $b, 'error' => null];
check('normaler Inhalt', adstxt_check_source($ok("a.com, 1, DIRECT\nb.com, 2, RESELLER"), null)['accept'], true);
check('HTML abgelehnt', adstxt_check_source($ok('<!DOCTYPE html><html>Fehler</html>'), 5)['accept'], false);
check('leer abgelehnt', adstxt_check_source($ok("# nur Kommentar\n"), 5)['accept'], false);
check('Fehler abgelehnt', adstxt_check_source(['ok' => false, 'status' => 500, 'body' => '', 'error' => 'HTTP 500'], 5)['status'], 'error');
$big = implode("\n", array_map(fn ($i) => "s{$i}.com, {$i}, DIRECT", range(1, 40)));
check('starker Rückgang abgelehnt', adstxt_check_source($ok("a.com, 1, DIRECT\nb.com, 2, DIRECT"), 40)['status'], 'warning');
check('starker Rückgang: nicht übernommen', adstxt_check_source($ok("a.com, 1, DIRECT\nb.com, 2, DIRECT"), 40)['accept'], false);
check('normaler Rückgang ok', adstxt_check_source($ok($big), 50)['accept'], true);
check('Latin-1 wird umgewandelt', adstxt_check_source($ok("# Gr\xFC\xDFe\na.com, 1, DIRECT"), null)['accept'], true);

echo "\n" . ($failed === 0 ? "Alle {$count} Tests bestanden.\n" : "{$failed} von {$count} Tests fehlgeschlagen.\n");
exit($failed === 0 ? 0 : 1);
