# Bild-Prompts — Prada vs. Gucci One-Pager

5 Bilder werden benötigt. Die Prompts sind inhaltlich auf den Artikel abgestimmt.
Wichtig: Keine Markennamen oder Logos in den Bildern — Bildgeneratoren dürfen keine
geschützten Markenzeichen reproduzieren. Die Prompts beschreiben Stil und Ästhetik.

Bilder bitte ablegen unter: `clients/mode-test/assets/`
Dateinamen: `hero.jpg`, `karte-prada.jpg`, `karte-gucci.jpg`, `karte-qualitaet.jpg`, `karte-tipps.jpg`

---

## Bild 1 — Hero-Panorama (Vergleichsbild Prada vs. Gucci)

**Dateiname:** `hero.jpg`
**Seitenverhältnis:** 16:5 (Panorama-Banner)
**Mindestauflösung:** 1600 × 500 px
**Verwendung:** Volle Breite oben im Artikel

**Prompt:**
```
Editorial fashion photography, split composition: left side features a sleek structured black leather handbag with a fine crosshatch texture and a small triangular metal logo plate, placed on a cool white marble surface — minimal, intellectual, "quiet luxury" aesthetic. Right side features a beige-brown monogram canvas bag with ornate gold metal hardware (a horsebit clasp), placed on warm oak wood — maximalist, heritage, glamour aesthetic. Clean studio lighting, white background between the two halves. High resolution, no text, no people. Photorealistic, fashion magazine quality.
```

**Midjourney:** `--ar 16:5 --style raw --q 2`
**DALL·E 3 Zusatz:** `photorealistic, no text, no watermark, no people`

---

## Bild 2 — Karte Prada: Saffiano & Re-Nylon

**Dateiname:** `karte-prada.jpg`
**Seitenverhältnis:** 4:3
**Mindestauflösung:** 600 × 450 px
**Verwendung:** Bildbereich der Prada-Karte

**Prompt:**
```
High-end product photography of two luxury handbags on a cold white marble surface. One bag is a small structured black leather tote with a distinctive crosshatch-embossed (Saffiano-like) texture and a simple triangular metal plate. The second is a compact black nylon crossbody bag with clean minimal hardware. Soft diffused studio lighting, slight shadow. Overhead or 3/4 angle view. Minimalist, modern, intellectual mood. No text, no people, no visible brand logos. Photorealistic, sharp detail.
```

**Midjourney:** `--ar 4:3 --style raw`
**DALL·E 3 Zusatz:** `product photography, no text, no watermark, photorealistic`

---

## Bild 3 — Karte Gucci: Canvas & Heritage

**Dateiname:** `karte-gucci.jpg`
**Seitenverhältnis:** 4:3
**Mindestauflösung:** 600 × 450 px
**Verwendung:** Bildbereich der Gucci-Karte

**Prompt:**
```
Luxurious lifestyle fashion photograph of two designer handbags styled together. One is a medium beige-and-brown monogram canvas shoulder bag with a prominent golden horseshoe-shaped metal clasp. The second is a small round quilted leather crossbody bag in burgundy red with gold chain strap. Styled on a warm wooden surface with a silk scarf and dried flowers as props. Warm golden hour lighting, rich textures, glamorous maximalist mood. Editorial quality, no text, no visible brand names or logos. Photorealistic.
```

**Midjourney:** `--ar 4:3 --style raw`
**DALL·E 3 Zusatz:** `product photography, lifestyle, no text, no logos, photorealistic`

---

## Bild 4 — Karte Qualität: Material & Handwerkskunst (Makro)

**Dateiname:** `karte-qualitaet.jpg`
**Seitenverhältnis:** 4:3
**Mindestauflösung:** 600 × 450 px
**Verwendung:** Bildbereich der Qualitäts-Karte

**Prompt:**
```
Extreme close-up macro photography showing luxury leather craftsmanship. Split composition: left half shows a black leather surface with a fine diagonal crosshatch embossing (water-resistant, structured), right half shows soft quilted leather in dark beige with precise hand-stitching and a small gold metal rivet. Ultra sharp focus, studio lighting highlighting the texture details. No text, no people, no logos. Represents quality comparison between two types of luxury leather goods manufacturing. Photorealistic, editorial quality.
```

**Midjourney:** `--ar 4:3 --style raw --q 2`
**DALL·E 3 Zusatz:** `macro photography, extreme detail, no text, photorealistic`

---

## Bild 5 — Karte Tipps: Luxusboutique / Shopping

**Dateiname:** `karte-tipps.jpg`
**Seitenverhältnis:** 4:3
**Mindestauflösung:** 600 × 450 px
**Verwendung:** Bildbereich der Kauftipps-Karte

**Prompt:**
```
Editorial photograph of an elegant woman in her 30s standing inside a bright, minimalist luxury fashion boutique. She is thoughtfully examining a structured leather handbag, comparing it with another bag placed on a white display table. Marble floors, soft natural light from large windows, clean white walls with subtle display shelving. The woman is stylishly dressed in neutral tones. Candid, aspirational mood. No visible brand logos or store names. No text. Photorealistic, high fashion editorial quality.
```

**Midjourney:** `--ar 4:3 --style raw`
**DALL·E 3 Zusatz:** `editorial photography, no text, no logos, photorealistic`

---

## Hinweise zur Bildgenerierung

| Tool | Zusatz-Hinweis |
|------|----------------|
| GPT-Image (DALL·E 3) | Füge `photorealistic, no text, no watermark` ans Ende jedes Prompts |
| Midjourney | `--ar 4:3` (Karten) oder `--ar 16:5` (Hero), `--style raw --q 2` |
| Stable Diffusion | Negative Prompt: `text, watermark, logo, brand name, deformed, blurry, low quality` |
| Adobe Firefly | „Kein Text" und „Keine Logos" in den Einstellungen aktivieren |

## Nachher

Bilder als `hero.jpg`, `karte-prada.jpg`, `karte-gucci.jpg`, `karte-qualitaet.jpg`, `karte-tipps.jpg`
in `clients/mode-test/assets/` ablegen.

Dann in `index.html` bei jeder Karte:
1. `class="has-image"` zur `.op-card__image-wrap` hinzufügen
2. Den `<!-- <img ...> -->` Kommentar aktivieren (Kommentar-Zeichen entfernen)
