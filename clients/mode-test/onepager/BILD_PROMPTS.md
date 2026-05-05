# Bild-Prompts für den One-Pager

Sobald du weißt, welches Thema der One-Pager behandelt,
kannst du diese Prompts mit deinem konkreten Sujet anpassen.
Die Platzhalter `[THEMA]` und `[KONTEXT] ` sind beim finalen Befüllen auszutauschen.

---

## Bild 1 — Hero-Bild (Panorama / Banner)

**Seitenverhältnis:** 16:9 oder 3:1  
**Empfohlene Auflösung:** 1600 × 600 px

**Prompt:**
```
A wide panoramic editorial photo for a German regional newspaper article about [THEMA].
Clean, bright composition with natural light. Photojournalism style, no text overlays.
Horizontal format, high resolution, suitable as a full-width header image.
Color palette: whites, soft blues, neutral tones. Professional quality.
```

---

## Bild 2 — Karte 1 (Info-Karte oben links)

**Seitenverhältnis:** 4:3  
**Empfohlene Auflösung:** 600 × 450 px

**Prompt:**
```
Editorial photograph for a news article card about [TEILTHEMA 1].
Clean background, good lighting, journalistic style. 
No text in image. Horizontal format, 4:3 ratio.
Authentic, trustworthy mood matching a German quality newspaper.
```

---

## Bild 3 — Karte 2 (Info-Karte oben rechts)

**Seitenverhältnis:** 4:3  
**Empfohlene Auflösung:** 600 × 450 px

**Prompt:**
```
Editorial photograph for a news article card about [TEILTHEMA 2].
Modern, clean composition. Natural colors, professional quality.
Horizontal 4:3 format, no overlaid text, journalistic aesthetic.
```

---

## Bild 4 — Karte 3 (Info-Karte unten links)

**Seitenverhältnis:** 4:3  
**Empfohlene Auflösung:** 600 × 450 px

**Prompt:**
```
Editorial photograph illustrating [TEILTHEMA 3] for a regional German newspaper.
Bright, clean, minimal. Horizontal 4:3 format.
No stock-photo look — authentic, real-life context, high quality.
```

---

## Bild 5 — Karte 4 (Info-Karte unten rechts) *(optional)*

**Seitenverhältnis:** 4:3  
**Empfohlene Auflösung:** 600 × 450 px

**Prompt:**
```
Editorial photograph for [TEILTHEMA 4], suitable for a news service page.
Clean composition, professional photography standard.
Horizontal 4:3, no text elements, authentic mood.
```

---

## Hinweise zur Bildgenerierung

- **GPT-Image (DALL·E 3):** Füge am Ende jedes Prompts hinzu: *"photorealistic, no text"*
- **Midjourney:** Füge `--ar 16:9` (Hero) oder `--ar 4:3` (Karten) hinzu, sowie `--style raw`
- **Stable Diffusion:** Nutze Negative Prompt: `text, watermark, logo, deformed, blurry`

Bilder bitte unter `clients/mode-test/assets/` ablegen (z.B. `hero.jpg`, `karte-1.jpg` etc.)
