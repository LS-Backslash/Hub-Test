# pu-webintern – interne Skripte

Server: `ads.mypdv.de` bzw. `pu-webintern.pdv1.mediengruppe.pd` (nur Hausnetz; PHP 8.4 FPM, Apache, MySQL 8.0 lokal, Benutzer `ads`).

## Struktur (entspricht dem Server)

```
home/  ->  /data/wwwcust/ads/home   (NICHT öffentlich: Code, Konfiguration, Daten, Logs)
www/   ->  /data/wwwcust/ads/www    (Webroot: nur schlanke Einstiegspunkte)
```

| Pfad | Zweck |
|---|---|
| `home/lib/` | Gemeinsame Bibliothek (bootstrap, Datenbank, HTTP, Ablage, Login) |
| `home/lib/migrations.php` | Tabellen-Definitionen; werden automatisch eingespielt |
| `home/config/` | `config.php` (inkl. DB-Passwort) und Passwort-Hashes (nicht im Git) |
| `home/data/` | Sperrdateien und kleine Laufzeitdaten |
| `home/logs/` | `php-error.log` und Anwendungslogs |
| `home/cron/` | Cronjob-Skripte |
| `home/bin/` | Hilfsskripte für die Kommandozeile |
| `www/epaper/` | ePaper-Werbezwischenseiten (ohne Login) |
| `www/epaper/creatives/` | Erzeugte Creatives als `<verlag>/<datum>_<kunde>/` mit `index.html`, `quer.*`, `hoch.*` (gleiche Struktur wie später auf dem Live-Server) – muss für `ads` beschreibbar sein |
| `home/templates/` | HTML-Vorlage der Werbezwischenseite |
| `www/adstxt/` | Ads.txt Organizer (mit Passwortschutz) – Logik in `home/lib/adstxt/` |
| `tests/` | Tests (werden nicht auf den Server kopiert) |

## Server-Besonderheiten

- **Datenbank:** MySQL 8.0 auf `localhost`, Datenbank `ads`, Benutzer `ads` (alle Rechte nur auf `ads`).
  Zugriff über `db()`, `db_run()`, `db_one()`, `db_all()` in `home/lib/db.php`.
  Tabellen werden beim ersten Zugriff automatisch angelegt (`home/lib/migrations.php`);
  Präfixe `epaper_…` und `adstxt_…`. Verwaltung per HeidiSQL/DBeaver über SSH-Tunnel (DB nimmt nur lokale Verbindungen an).
- **HTTP:** `fetch_url()` nutzt cURL (seit Nov. 2026 installiert), sonst PHP-Streams.
- **Kein intl**: Datumsformatierung über `date_de()`.
- Zeitzone wird in `bootstrap.php` fest auf `Europe/Berlin` gesetzt (Server steht auf UTC).
- Limits im Web: 30 s Laufzeit, 128 MB RAM, 2 MB Upload. Lange Abläufe gehören in Cronjobs.

## Einrichtung auf dem Server

1. Inhalt von `home/` nach `/data/wwwcust/ads/home/`, von `www/` nach `/data/wwwcust/ads/www/` kopieren.
2. Konfiguration anlegen und das DB-Passwort eintragen (nur auf dem Server, nie im Git):
   ```bash
   cd /data/wwwcust/ads/home/config && cp config.example.php config.php && chmod 640 config.php && nano config.php
   ```
3. `home/data`, `home/logs` und `www/epaper/creatives` müssen für den Benutzer `ads` beschreibbar sein.
4. Passwort für den Ads.txt-Bereich setzen (wird verdeckt abgefragt, nur der Hash wird gespeichert):
   ```bash
   php8.4 /data/wwwcust/ads/home/bin/set-password.php adstxt
   ```
5. Umgebung prüfen und Tabellen einrichten (zeigt Erweiterungen, Ordnerrechte, DB-Verbindung, Internetzugang):
   ```bash
   php8.4 /data/wwwcust/ads/home/bin/check.php
   ```
6. Falls es schon Creatives aus der JSON-Zeit gibt, einmalig übernehmen:
   ```bash
   php8.4 /data/wwwcust/ads/home/bin/import-epaper-json.php
   ```
7. Die bisherige `www/index.php` mit `phpinfo()` entfernen.

## Cronjobs

Crontab des Benutzers `ads` bearbeiten (`crontab -e`) und diese Zeile eintragen –
Ads.txt-Aktualisierung alle 12 Stunden (05:17 und 17:17 Uhr):

```
17 5,17 * * * /usr/bin/php8.4 /data/wwwcust/ads/home/cron/adstxt-update.php >> /data/wwwcust/ads/home/logs/cron.log 2>&1
```

Zusätzlich alle 15 Minuten: fehlgeschlagene Uploads zum Live-Server nachholen (tut nichts, wenn nichts aussteht):

```
*/15 * * * * /usr/bin/php8.4 /data/wwwcust/ads/home/cron/live-sync.php >> /data/wwwcust/ads/home/logs/cron.log 2>&1
```

Und täglich um 10:43 Uhr der Abgleich (Ampel: haben die Verlage die aktuelle Datei?):

```
43 10 * * * /usr/bin/php8.4 /data/wwwcust/ads/home/cron/adstxt-check.php >> /data/wwwcust/ads/home/logs/cron.log 2>&1
```

Prüfen: `crontab -l`. Einmal von Hand testen: `php8.4 /data/wwwcust/ads/home/cron/adstxt-update.php; echo $?`
(0 = ok, 1 = Warnung, z. B. eine Quelle nicht erreichbar, 2 = Fehler). Läufe erscheinen auch im Tool unter „Protokoll“.
Parallel laufende Aktualisierungen (Cron + „Jetzt aktualisieren“) werden per Sperre verhindert.

## Live-Server (extern)

- Zugang: SFTP auf `webextern.pdv1.mediengruppe.pd:22`, Zielordner `/var/www/html/ads/www`,
  öffentlich unter `https://ads.pddigital.de`. Zugangsdaten nur in `config.php` (Eintrag `live`).
- Fingerabdruck des Servers einmalig speichern (die Verbindung wird sonst abgelehnt):
  `ssh-keyscan -p 22 webextern.pdv1.mediengruppe.pd >> ~/.ssh/known_hosts`
- Struktur auf dem Live-Server:
  `epaper/<verlag>/<datum>_<kunde>/{index.html,quer.*,hoch.*}` und `adstxt/<publisher>/{ads.txt,app-ads.txt}`.
- ePaper: Upload direkt nach dem Speichern (Bilder zuerst, `index.html` zuletzt). Bei Fehlern Status „Fehler“,
  Button „Hochladen“ bzw. automatisch über `live-sync.php`.
- Ads.txt: nach jeder Aktualisierung werden nur geänderte Dateien hochgeladen; Dateien deaktivierter Publisher
  bzw. abgewählter Dateitypen werden gelöscht.
- Jede Datei wird erst als `.tmp-…` hochgeladen und dann umbenannt – niemand bekommt eine halbe Datei.
- Test: `php8.4 /data/wwwcust/ads/home/bin/check.php` (Abschnitt „Live-Server“ schreibt und löscht eine Testdatei).

## Ads.txt Organizer – Funktionsweise

- **Bausteine:** Publisher-Kopfzeilen, Einträge (manuelle Blöcke) und Quellen (externe URLs), jeweils global oder
  pro Publisher und für `ads.txt`, `app-ads.txt` oder beide.
- **Vorrang bei Kopfzeilen** (OWNERDOMAIN, CONTACT …): Publisher-Kopfzeilen → eigene Einträge → eigene Quellen →
  globale Einträge → globale Quellen. Je Variable gilt nur die höchste Ebene, OWNERDOMAIN nur einmal.
- **Dubletten** (gleiche Domain + Konto-ID + Beziehung) werden entfernt; die erste Fundstelle in obiger Reihenfolge
  bleibt, eine fehlende Zertifizierungs-ID wird ergänzt.
- **Absicherung:** Ist eine Quelle nicht erreichbar, liefert HTML/JSON, ist leer oder schrumpft um mehr als die Hälfte,
  wird der letzte gültige Stand weiterverwendet.
- **Bereitstellung für die Verlage:** `https://ads.pddigital.de/adstxt/<publisher>/ads.txt` (Adresse steht beim Publisher).

## Abgleich (Ampel)

Seite „Abgleich“ im Ads.txt Organizer: ruft `https://<domain>/ads.txt` bzw. `app-ads.txt` jedes aktiven Publishers ab
(bei Verbindungsfehlern `http://`) und vergleicht den Inhalt mit unserem Stand – Reihenfolge, Kommentare und
Schreibweise zählen nicht. Grün = identisch, Gelb = Zeilen fehlen/zu viel (mit Liste und „Stand beim Verlag“ aus
unserer Kopfzeile), Rot = nicht abrufbar/404/Webseite statt Datei, Grau = keine Domain oder noch keine Datei.
Antwortet `https://<domain>` nicht, werden der Reihe nach `https://www.<domain>`, `http://<domain>` und
`http://www.<domain>` versucht. Bei fehlerhaftem HTTPS-Zertifikat wird die (öffentliche) Datei ohne Zertifikatsprüfung
gelesen und verglichen, der Befund aber als „Zertifikat fehlerhaft“ markiert. Zu jedem Befund gibt es einen
„Tipp für die IT des Verlags“ zum Kopieren (www fehlt, nur http, Zertifikat, 404, Webseite statt Datei, keine Antwort, veraltet).
20 Abrufe parallel; täglich per Cron, sonst „Jetzt prüfen“ bzw. „Neu prüfen“ je Publisher.

## Tests (lokal, nicht auf dem Server)

```bash
php webintern/tests/adstxt_core_test.php
# Integrationstest – NUR gegen eine Test-Datenbank, leert die adstxt-Tabellen:
php -S 127.0.0.1:8099 -t webintern/tests/fixtures &
ADSTXT_TEST_DB=1 php webintern/tests/adstxt_service_test.php http://127.0.0.1:8099
# Live-Upload – braucht einen lokalen Test-SFTP-Server im 'live'-Eintrag der Test-config.php:
ADSTXT_TEST_DB=1 php webintern/tests/live_sync_test.php /var/www/html/ads/www
# Abgleich – lokaler Webserver simuliert die Verlags-Websites:
php -S 127.0.0.1:8098 -t /tmp/verlage &
ADSTXT_TEST_DB=1 php webintern/tests/adstxt_verify_test.php /tmp/verlage 127.0.0.1:8098
```
