# pu-webintern – interne Skripte

Server: `pu-webintern.pdv1.mediengruppe.pd` (PHP 8.4 FPM, Apache, Benutzer `ads`).

## Struktur (entspricht dem Server)

```
home/  ->  /data/wwwcust/ads/home   (NICHT öffentlich: Code, Konfiguration, Daten, Logs)
www/   ->  /data/wwwcust/ads/www    (Webroot: nur schlanke Einstiegspunkte)
```

| Pfad | Zweck |
|---|---|
| `home/lib/` | Gemeinsame Bibliothek (bootstrap, HTTP, Ablage, Login) |
| `home/config/` | `config.php` und Passwort-Hashes (nicht im Git) |
| `home/data/` | Dateibasierte Ablage (der Server hat keinen DB-Treiber) |
| `home/logs/` | `php-error.log` und Anwendungslogs |
| `home/cron/` | Cronjob-Skripte |
| `home/bin/` | Hilfsskripte für die Kommandozeile |
| `www/epaper/` | ePaper-Werbezwischenseiten (ohne Login) |
| `www/epaper/creatives/` | Erzeugte Creatives (je Ordner: `index.html`, `quer.*`, `hoch.*`) – muss für `ads` beschreibbar sein |
| `home/templates/` | HTML-Vorlage der Werbezwischenseite |
| `www/adstxt/` | Ads.txt Organizer (mit Passwortschutz) |

## Server-Besonderheiten

- **Kein cURL**: HTTP läuft über `fetch_url()` in `home/lib/http.php` (PHP-Streams).
- **Kein PDO-Treiber / keine DB**: Ablage per `read_json()` / `write_json()` (atomar).
- **Kein intl**: Datumsformatierung über `date_de()`.
- Zeitzone wird in `bootstrap.php` fest auf `Europe/Berlin` gesetzt (Server steht auf UTC).
- Limits im Web: 30 s Laufzeit, 128 MB RAM, 2 MB Upload. Lange Abläufe gehören in Cronjobs.

## Einrichtung auf dem Server

1. Inhalt von `home/` nach `/data/wwwcust/ads/home/`, von `www/` nach `/data/wwwcust/ads/www/` kopieren.
2. `home/config/config.example.php` nach `home/config/config.php` kopieren.
3. `home/data`, `home/logs` und `www/epaper/creatives` müssen für den Benutzer `ads` beschreibbar sein.
4. Passwort für den Ads.txt-Bereich setzen (wird verdeckt abgefragt, nur der Hash wird gespeichert):
   ```bash
   php8.4 /data/wwwcust/ads/home/bin/set-password.php adstxt
   ```
5. Die bisherige `www/index.php` mit `phpinfo()` entfernen.

## Cronjobs

Crontab des Benutzers `ads` (`crontab -e`). Beispiel für einen Abruf alle 6 Stunden:

```
0 */6 * * * /usr/bin/php8.4 /data/wwwcust/ads/home/cron/adstxt-fetch.php >> /data/wwwcust/ads/home/logs/cron.log 2>&1
```

Cronjobs nutzen `acquire_lock()`, damit sie nicht doppelt laufen.
