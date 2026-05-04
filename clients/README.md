# Clients

Ein Ordner pro Kunde. Struktur basiert auf `_template/`.

## Ordnerstruktur

```
clients/
└── [kundenname]/
    ├── assets/          Logos, Bilder, Rohdaten
    ├── guidelines/      brand.md + hochgeladene Style Guides
    ├── design-system/   MASTER.md (generiert via UI/UX Pro Max)
    └── banners/         fertige Werbemittel
```

## Neuen Kunden anlegen

```bash
cp -r clients/_template clients/[kundenname]
```
