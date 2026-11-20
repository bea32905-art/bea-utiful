#!/usr/bin/env python3
"""WeasyPrint photo book generator for bea-utiful.

Replaces book.php + browser printing. One A4 landscape sheet per day:
left half is a contact-sheet style black panel, right half follows the
site palette from style.css.

Usage:
  python3 book.py                          # latest month that has dated photos
  python3 book.py --month 2026-10          # specific month
  python3 book.py --roll roll-2            # whole roll in frame order
  python3 book.py --draft                  # ignore "featured", include all dated
  python3 book.py --open                   # open the PDF when done
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from datetime import datetime
from html import escape
from pathlib import Path
from urllib.parse import quote

PROJECT_DIR = Path(__file__).resolve().parent
JOURNAL_DIR = PROJECT_DIR / "photos" / "photo journal"
CSS_FILE = PROJECT_DIR / "book-print.css"
BUILD_DIR = PROJECT_DIR / "book-build"
DEFAULT_CAMERA = "Minolta X-300"

IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp", ".gif"}
WEB_SUBDIRS = {"1 - web"}


def load_json(path: Path) -> dict:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return data if isinstance(data, dict) else {}


def normalize_key(value: str) -> str:
    return re.sub(r"\s+", " ", value.strip())


def collect_roll_images(roll_dir: Path) -> list[Path]:
    """All image files in a roll, preferring the '1 - Web' export folder."""
    files = [p for p in roll_dir.rglob("*") if p.suffix.lower() in IMAGE_EXTENSIONS and p.is_file()]
    preferred = [p for p in files if p.parent.name.lower() in WEB_SUBDIRS]
    chosen = preferred if preferred else files
    return sorted(chosen, key=lambda p: p.name.lower())


def haiku_lines(details: dict) -> list[str]:
    haiku = details.get("haiku")
    if isinstance(haiku, list):
        lines = [str(line).strip() for line in haiku]
        return [line for line in lines if line]
    legacy = str(details.get("description", "")).strip()
    return [legacy] if legacy else []


def collect_entries() -> list[dict]:
    entries: list[dict] = []
    roll_dirs = sorted(p for p in JOURNAL_DIR.iterdir() if p.is_dir()) if JOURNAL_DIR.is_dir() else []

    for roll_dir in roll_dirs:
        match = re.fullmatch(r"roll-(\d+)", roll_dir.name)
        if not match:
            continue
        roll_number = int(match.group(1))
        metadata = load_json(roll_dir / "metadata.json")
        image_details = metadata.get("images") if isinstance(metadata.get("images"), dict) else {}
        film_type = str(metadata.get("film_type", "")).strip()
        camera = str(metadata.get("camera", "")).strip() or DEFAULT_CAMERA

        for index, image_path in enumerate(collect_roll_images(roll_dir)):
            filename = image_path.name
            details = image_details.get(filename)
            if not isinstance(details, dict):
                wanted = normalize_key(filename)
                for key, candidate in image_details.items():
                    if isinstance(candidate, dict) and normalize_key(str(key)) == wanted:
                        details = candidate
                        break
            if not isinstance(details, dict):
                continue

            date = str(details.get("date", "")).strip()
            iso_parts = None
            if re.fullmatch(r"\d{4}-\d{2}-\d{2}", date):
                iso_parts = date.split("-")
            else:
                date = ""

            relative = image_path.relative_to(PROJECT_DIR)
            entries.append({
                "roll": roll_dir.name,
                "roll_number": roll_number,
                "frame": index + 1,
                "title": str(details.get("title", "")).strip() or f"{index + 1:02d}",
                "src": str(relative),
                "date": date,
                "iso_parts": iso_parts,
                "haiku": haiku_lines(details),
                "notes": str(details.get("notes", "")).strip(),
                "featured": details.get("featured") is True,
                "film_type": film_type,
                "camera": camera,
            })
    return entries


def select_book(entries: list[dict], month: str | None, roll: str | None, draft: bool):
    if roll:
        selected = [e for e in entries if e["roll"] == roll]
        title = f"Roll {int(roll.split('-')[1]):02d}"
        subtitle = ""
        return selected, title, subtitle

    months: dict[str, None] = {}
    for e in entries:
        if e["iso_parts"]:
            months[f"{e['iso_parts'][0]}-{e['iso_parts'][1]}"] = None

    if month is None:
        month = next(reversed(months), None) if months else None
    if not month or not re.fullmatch(r"\d{4}-\d{2}", month):
        return [], "Empty Book", "No dated photographs found."

    in_month = [e for e in entries if e["iso_parts"] and f"{e['iso_parts'][0]}-{e['iso_parts'][1]}" == month]
    featured = [e for e in in_month if e["featured"]]
    chosen = featured if (featured and not draft) else in_month
    chosen.sort(key=lambda e: (e["date"], e["roll_number"], e["frame"]))

    try:
        title = datetime.strptime(month, "%Y-%m").strftime("%B %Y")
    except ValueError:
        title = month
    subtitle = f"{len(chosen)} {'day' if len(chosen) == 1 else 'days'}"
    return chosen, title, subtitle


def display_day(entry: dict) -> str:
    if not entry["iso_parts"]:
        return "Undated"
    try:
        return datetime.strptime(entry["date"], "%Y-%m-%d").strftime("%d/%m/%Y")
    except ValueError:
        return entry["date"]


def html_for(entries: list[dict], title: str, subtitle: str) -> str:
    parts: list[str] = []
    parts.append(f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{escape(title)} — Photo Book | bea-utiful</title>
<link rel="stylesheet" href="{CSS_FILE.name}">
</head>
<body>
<section class="sheet cover-sheet">
  <div class="panel panel-cover">
    <p class="corner-label top-left">bea-utiful</p>
    <p class="corner-label bottom-left">Photo Journal / 35mm</p>
  </div>
  <div class="page">
    <h1>{escape(title)}</h1>
    {f'<p class="subtitle">{escape(subtitle)}</p>' if subtitle else ''}
  </div>
</section>""")

    if not entries:
        parts.append("""
<section class="sheet">
  <div class="panel"></div>
  <div class="page">
    <p class="writing">No dated, featured photographs were found for this book.
    Add dates in YYYY-MM-DD format and "featured": true to your metadata.json
    files, then rebuild.</p>
  </div>
</section>""")

    for entry in entries:
        lines = "".join(f"<span>{escape(line)}</span>" for line in entry["haiku"])
        placeholder = "" if lines else " unwritten"
        writing = lines or "<span>(no writing yet)</span>"
        parts.append(f"""
<section class="sheet">
  <div class="panel">
    <p class="corner-label top-left">{escape(entry['film_type'])}</p>
    <p class="corner-label top-right">{escape(entry['title'])}</p>
    <figure class="contact-frame"><img src="{quote(entry['src'])}" alt=""></figure>
    <p class="corner-label bottom-left">{escape(entry['camera'])}</p>
  </div>
  <div class="page">
    <p class="date-line">{escape(display_day(entry))}</p>
    <p class="writing{placeholder}">{writing}</p>
    {f'<p class="footnote">{escape(entry["notes"])}</p>' if entry['notes'] else ''}
  </div>
</section>""")

    parts.append("""
<section class="sheet colophon-sheet">
  <div class="panel panel-cover">
    <p class="corner-label top-left">bea-utiful</p>
    <p class="corner-label bottom-left">Shot on 35mm film. Handmade book.</p>
  </div>
  <div class="page">
    <p class="colophon-copy">bea-utiful photo journal</p>
  </div>
</section>
</body>
</html>""")
    return "".join(parts)


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate the bea-utiful photo book PDF.")
    parser.add_argument("--month", help="YYYY-MM, defaults to the latest month with dated photos")
    parser.add_argument("--roll", help="roll-N to build a whole roll")
    parser.add_argument("--draft", action="store_true", help="ignore featured, include all dated photos")
    parser.add_argument("--open", action="store_true", help="open the finished PDF")
    args = parser.parse_args()

    entries = collect_entries()
    selected, title, subtitle = select_book(entries, args.month, args.roll, args.draft)

    BUILD_DIR.mkdir(exist_ok=True)
    html_path = BUILD_DIR / "book.html"
    html_path.write_text(html_for(selected, title, subtitle), encoding="utf-8")

    slug = title.lower().replace(" ", "-")
    pdf_path = BUILD_DIR / f"book-{slug}.pdf"

    from weasyprint import HTML  # noqa: PLC0415 — imported after arg parsing on purpose

    HTML(string=html_for(selected, title, subtitle), base_url=str(PROJECT_DIR)).write_pdf(
        str(pdf_path), stylesheets=[CSS_FILE]
    )
    print(f"Wrote {pdf_path} ({len(selected)} spreads)")

    if args.open:
        subprocess.run(["open", str(pdf_path)], check=False)
    return 0


if __name__ == "__main__":
    sys.exit(main())
