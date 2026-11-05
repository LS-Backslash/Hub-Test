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
| `www/adstxt/` | Ads.txt Organizer (mit Passwortschutz) |

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

Crontab des Benutzers `ads` (`crontab -e`). Beispiel für einen Abruf alle 6 Stunden:

```
0 */6 * * * /usr/bin/php8.4 /data/wwwcust/ads/home/cron/adstxt-fetch.php >> /data/wwwcust/ads/home/logs/cron.log 2>&1
```

Cronjobs nutzen `acquire_lock()`, damit sie nicht doppelt laufen.
