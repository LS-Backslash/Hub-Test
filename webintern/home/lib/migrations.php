<?php
declare(strict_types=1);

/**
 * Datenbank-Migrationen. Neue Änderungen IMMER als neuen Eintrag unten anhängen,
 * bestehende Einträge nie verändern (sie sind auf dem Server schon eingespielt).
 *
 * Tabellen-Präfixe: epaper_… (ePaper-Tool), adstxt_… (Ads.txt Organizer).
 */

const DB_TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

return [

    '2026_11_05_001_epaper_creatives' => [
        'CREATE TABLE epaper_creatives (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            publisher VARCHAR(40) NOT NULL,
            folder VARCHAR(80) NOT NULL,
            customer VARCHAR(100) NOT NULL,
            pub_date DATE NOT NULL,
            link TEXT NOT NULL,
            landscape_file VARCHAR(20) NOT NULL,
            landscape_width INT UNSIGNED NOT NULL,
            landscape_height INT UNSIGNED NOT NULL,
            portrait_file VARCHAR(20) NOT NULL,
            portrait_width INT UNSIGNED NOT NULL,
            portrait_height INT UNSIGNED NOT NULL,
            live_url TEXT NULL,
            uploaded_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_publisher_folder (publisher, folder),
            KEY ix_pub_date (pub_date),
            KEY ix_publisher_date (publisher, pub_date)
        ) ' . DB_TABLE_OPTIONS,
    ],

    '2026_11_05_002_adstxt' => [
        // Publisher (Website/App-Betreiber), für die Dateien erzeugt werden.
        'CREATE TABLE adstxt_publishers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(60) NOT NULL,
            name VARCHAR(120) NOT NULL,
            domain VARCHAR(190) NOT NULL DEFAULT \'\',
            active TINYINT(1) NOT NULL DEFAULT 1,
            wants_ads TINYINT(1) NOT NULL DEFAULT 1,
            wants_app_ads TINYINT(1) NOT NULL DEFAULT 0,
            header_lines TEXT NOT NULL,
            notes TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_slug (slug)
        ) ' . DB_TABLE_OPTIONS,

        // Externe Quellen; publisher_id NULL = gilt für alle Publisher.
        'CREATE TABLE adstxt_sources (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            publisher_id INT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            url VARCHAR(500) NOT NULL,
            file_type ENUM(\'ads\',\'app-ads\',\'both\') NOT NULL DEFAULT \'ads\',
            active TINYINT(1) NOT NULL DEFAULT 1,
            last_fetch_at DATETIME NULL,
            last_status ENUM(\'ok\',\'warning\',\'error\') NULL,
            last_message VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY ix_publisher (publisher_id),
            CONSTRAINT fk_sources_publisher FOREIGN KEY (publisher_id) REFERENCES adstxt_publishers (id)
        ) ' . DB_TABLE_OPTIONS,

        // Letzter gültiger Inhalt je Quelle (wird bei Fehlern weiterverwendet).
        'CREATE TABLE adstxt_source_cache (
            source_id INT UNSIGNED NOT NULL PRIMARY KEY,
            content MEDIUMTEXT NOT NULL,
            content_hash CHAR(64) NOT NULL,
            record_count INT UNSIGNED NOT NULL,
            fetched_at DATETIME NOT NULL,
            CONSTRAINT fk_cache_source FOREIGN KEY (source_id) REFERENCES adstxt_sources (id) ON DELETE CASCADE
        ) ' . DB_TABLE_OPTIONS,

        // Manuelle Einträge als benannte Blöcke; publisher_id NULL = global.
        'CREATE TABLE adstxt_blocks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            publisher_id INT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            file_type ENUM(\'ads\',\'app-ads\',\'both\') NOT NULL DEFAULT \'ads\',
            content MEDIUMTEXT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY ix_publisher (publisher_id),
            CONSTRAINT fk_blocks_publisher FOREIGN KEY (publisher_id) REFERENCES adstxt_publishers (id)
        ) ' . DB_TABLE_OPTIONS,

        // Aktuell erzeugte Datei je Publisher und Dateityp.
        'CREATE TABLE adstxt_outputs (
            publisher_id INT UNSIGNED NOT NULL,
            file_type ENUM(\'ads\',\'app-ads\') NOT NULL,
            content MEDIUMTEXT NOT NULL,
            body_hash CHAR(64) NOT NULL,
            record_count INT UNSIGNED NOT NULL,
            generated_at DATETIME NOT NULL,
            changed_at DATETIME NOT NULL,
            PRIMARY KEY (publisher_id, file_type),
            CONSTRAINT fk_outputs_publisher FOREIGN KEY (publisher_id) REFERENCES adstxt_publishers (id)
        ) ' . DB_TABLE_OPTIONS,

        // Läufe (Cron oder manuell).
        'CREATE TABLE adstxt_runs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            trigger_type ENUM(\'cron\',\'manual\') NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            status ENUM(\'running\',\'ok\',\'warning\',\'error\') NOT NULL DEFAULT \'running\',
            summary TEXT NULL,
            KEY ix_started (started_at)
        ) ' . DB_TABLE_OPTIONS,

        // Änderungsprotokoll: welche Zeilen kamen je Datei hinzu bzw. fielen weg.
        'CREATE TABLE adstxt_changes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            run_id INT UNSIGNED NULL,
            publisher_id INT UNSIGNED NOT NULL,
            file_type ENUM(\'ads\',\'app-ads\') NOT NULL,
            changed_at DATETIME NOT NULL,
            added MEDIUMTEXT NOT NULL,
            removed MEDIUMTEXT NOT NULL,
            added_count INT UNSIGNED NOT NULL,
            removed_count INT UNSIGNED NOT NULL,
            KEY ix_publisher_date (publisher_id, changed_at),
            KEY ix_date (changed_at),
            CONSTRAINT fk_changes_publisher FOREIGN KEY (publisher_id) REFERENCES adstxt_publishers (id)
        ) ' . DB_TABLE_OPTIONS,
    ],

];
