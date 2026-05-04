#!/usr/bin/env python3
"""POE image generation script for banner/ad creation."""

import argparse
import os
import sys
from datetime import datetime
from pathlib import Path

import httpx
import fastapi_poe as fp
from dotenv import load_dotenv

load_dotenv()

BOTS = {
    "nano": "Nano-Banana-Pro",
    "gpt": "GPT-Image-2",
}


def generate_image(prompt: str, bot: str, client: str, filename: str | None = None) -> Path:
    api_key = os.getenv("POE_API_KEY")
    if not api_key:
        sys.exit("POE_API_KEY nicht gesetzt. Bitte .env prüfen.")

    bot_name = BOTS.get(bot, bot)
    out_dir = Path("clients") / client / "assets"
    out_dir.mkdir(parents=True, exist_ok=True)

    print(f"Bot: {bot_name}")
    print(f"Prompt: {prompt}")
    print("Generiere...")

    message = fp.ProtocolMessage(role="user", content=prompt)
    image_url = None
    full_text = ""

    for chunk in fp.get_bot_response_sync(
        messages=[message],
        bot_name=bot_name,
        api_key=api_key,
    ):
        if chunk.attachment and chunk.attachment.content_type.startswith("image/"):
            image_url = chunk.attachment.url
        if chunk.text:
            full_text += chunk.text

    # Fallback: Markdown image URL aus Text extrahieren
    if not image_url and full_text:
        import re
        match = re.search(r"!\[.*?\]\((https?://\S+?)\)", full_text)
        if match:
            image_url = match.group(1)

    if not image_url:
        print("Antwort:", full_text)
        sys.exit("Kein Bild in der Antwort gefunden.")

    # Bild herunterladen
    ext = "png"
    if "jpeg" in image_url or "jpg" in image_url:
        ext = "jpg"
    elif "webp" in image_url:
        ext = "webp"

    if not filename:
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        filename = f"{bot}_{timestamp}.{ext}"
    elif not Path(filename).suffix:
        filename = f"{filename}.{ext}"

    out_path = out_dir / filename

    response = httpx.get(image_url, follow_redirects=True)
    response.raise_for_status()
    out_path.write_bytes(response.content)

    print(f"Gespeichert: {out_path}")
    return out_path


def main():
    parser = argparse.ArgumentParser(description="Bild via POE generieren")
    parser.add_argument("prompt", help="Bildprompt")
    parser.add_argument(
        "--bot", choices=list(BOTS.keys()) + list(BOTS.values()),
        default="nano",
        help="Bot: 'nano' (Nano-Banana-Pro) oder 'gpt' (GPT-Image-2)",
    )
    parser.add_argument("--client", required=True, help="Kundenordner unter clients/")
    parser.add_argument("--filename", help="Dateiname (ohne Pfad, optional)")
    args = parser.parse_args()

    generate_image(
        prompt=args.prompt,
        bot=args.bot,
        client=args.client,
        filename=args.filename,
    )


if __name__ == "__main__":
    main()
