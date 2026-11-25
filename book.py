#!/usr/bin/env python3
"""WeasyPrint photo book generator for bea-utiful.

Replaces book.php + browser printing. One 25 x 20 cm page per
photograph, full-bleed on black. Captions sit in the four corners:
film type (top-left), date (top-right), image title (bottom-left),
bea-utiful.me (bottom-right).

Usage:
  python3 book.py                          # latest month that has dated photos
  python3 book.py --month 2026-10          # specific month
  python3 book.py --roll roll-2            # whole roll in frame order
  python3 book.py --draft                   # ignore "featured", include all dated
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
JOURNAL_DIR = PROJECT_DIR / "photos" / "film"
CSS_FILE = PROJECT_DIR / "book-print.css"
BUILD_DIR = PROJECT_DIR / "book-build"
SITE_URL = "bea-utiful.me"
NO_DATE_TEXT = "Undated"

# Month -> cover colour: twelve hues spaced 30 degrees apart on the
# colour wheel, starting December = blue. Books without a month
# (whole rolls) fall back to the caption ink off-white.
MONTH_COLOURS = {
    12: "#0000ff",  # December — blue
    1: "#0080ff",   # January — azure
    2: "#00ffff",   # February — cyan
    3: "#00ff80",   # March — spring green
    4: "#00ff00",   # April — green
    5: "#80ff00",   # May — yellow-green
    6: "#ffff00",   # June — yellow
    7: "#ff8000",   # July — orange
    8: "#ff0000",   # August — red
    9: "#ff0080",   # September — rose
    10: "#ff00ff",  # October — magenta
    11: "#8000ff",  # November — purple
}


def cover_colour(month: str | None) -> str:
    """Cover background colour for a YYYY-MM book; falls back to black otherwise."""
    if not month:
        return "#000000"
    number = int(month.split("-")[1])
    return MONTH_COLOURS.get(number, "#000000")


def contrast_text_colour(background_hex: str) -> str:
    """Black on bright backgrounds, warm off-white on dark ones."""
    value = background_hex.lstrip("#")
    red, green, blue = (int(value[i : i + 2], 16) / 255 for i in (0, 2, 4))
    luminance = 0.2126 * red + 0.7152 * green + 0.0722 * blue
    return "#000000" if luminance >= 0.25 else "#f2efe6"

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


def is_portrait(image_path: Path) -> bool:
    """True if the image is taller than it is wide (after EXIF rotation)."""
    try:
        from PIL import Image, ImageOps

        with Image.open(image_path) as im:
            transposed = ImageOps.exif_transpose(im)
            width, height = (transposed or im).size
    except Exception:
        return False
    return height > width


def parse_date(raw: str) -> tuple[str, list[str] | None]:
    """Accept YYYY-MM-DD or YYYY/MM/DD; return ('', None) if not a full date."""
    value = str(raw).strip()
    for fmt in ("%Y-%m-%d", "%Y/%m/%d"):
        try:
            parsed = datetime.strptime(value, fmt)
        except ValueError:
            continue
        iso = parsed.strftime("%Y-%m-%d")
        return iso, iso.split("-")
    return "", None


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

            date, iso_parts = parse_date(details.get("date"))

            relative = image_path.relative_to(PROJECT_DIR)
            entries.append({
                "portrait": is_portrait(image_path),
                "roll": roll_dir.name,
                "roll_number": roll_number,
                "frame": index + 1,
                "title": str(details.get("title", "")).strip(),
                "src": str(relative),
                "date": date,
                "iso_parts": iso_parts,
                "featured": details.get("featured") is True,
                "film_type": film_type,
            })
    return entries


def select_book(entries: list[dict], month: str | None, roll: str | None, draft: bool):
    if roll:
        selected = [e for e in entries if e["roll"] == roll]
        title = f"Roll {int(roll.split('-')[1]):02d}"
        return selected, title, None, None

    months: dict[str, None] = {}
    for e in entries:
        if e["iso_parts"]:
            months[f"{e['iso_parts'][0]}-{e['iso_parts'][1]}"] = None

    if month is None:
        month = next(reversed(months), None) if months else None
    if not month or not re.fullmatch(r"\d{4}-\d{2}", month):
        return [], "Empty Book", None, None

    in_month = [e for e in entries if e["iso_parts"] and f"{e['iso_parts'][0]}-{e['iso_parts'][1]}" == month]
    featured = [e for e in in_month if e["featured"]]
    chosen = featured if (featured and not draft) else in_month
    chosen.sort(key=lambda e: (e["date"], e["roll_number"], e["frame"]))

    try:
        title = datetime.strptime(month, "%Y-%m").strftime("%B %Y")
    except ValueError:
        title = month
    return chosen, title, month, len(chosen)


def display_day(entry: dict) -> str:
    if not entry["iso_parts"]:
        return NO_DATE_TEXT
    try:
        return datetime.strptime(entry["date"], "%Y-%m-%d").strftime("%d/%m/%Y")
    except ValueError:
        return entry["date"]


def html_for(entries: list[dict], title: str, bg_colour: str, photo_count: int | None) -> str:
    parts: list[str] = []
    fg_colour = contrast_text_colour(bg_colour)
    parts.append(f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{escape(title)} — Photo Book | bea-utiful</title>
<link rel="stylesheet" href="{CSS_FILE.name}">
</head>
<body>
<section class="sheet cover-sheet" style="background: {bg_colour}">
  <div class="cover-title" style="color: {fg_colour}">
    <p class="cover-kicker">Photo Journal</p>
    <h1>{escape(title)}</h1>
  </div>
</section>""")

    if not entries:
        parts.append("""
<section class="sheet">
  <p class="notice">No dated, featured photographs were found for this book.
  Add dates in YYYY-MM-DD format and "featured": true to your metadata.json
  files, then rebuild.</p>
</section>""")

    # Inside covers: blank sheets in the cover colour, facing the front
    # cover and the back cover respectively.
    inside_cover = f"""
<section class="sheet cover-sheet" style="background: {bg_colour}">
</section>"""
    parts.append(inside_cover)

    for entry in entries:
        parts.append(f"""
<section class="sheet">
  <p class="corner-label bottom-left">{escape(entry['film_type'])}</p>
  <p class="corner-label top-left">{escape(display_day(entry))}</p>
  <figure class="photo-frame"><img class="{'normal' if entry['portrait'] else 'rotated'}" src="{quote(entry['src'])}" alt=""></figure>
  <p class="corner-label bottom-right">{escape(entry['title'])}</p>
  <p class="corner-label top-right">{escape(SITE_URL)}</p>
</section>""")

    # Back cover: blank page in the same colour as the front cover.
    parts.append(f"""
<section class="sheet cover-sheet" style="background: {bg_colour}">
</section>""")

    # Inside back cover: mirrors the inside front cover.
    parts.append(inside_cover)

    parts.append("""
</body>
</html>""")
    return "".join(parts)


def export_pages_as_pngs(pdf_path: Path, out_dir: Path, dpi: int = 300) -> int:
    """Render every page of the PDF to a PNG in out_dir; return page count."""
    import pymupdf  # noqa: PLC0415 — imported after arg parsing on purpose

    out_dir.mkdir(parents=True, exist_ok=True)
    for stale in out_dir.glob("page-*.png"):
        stale.unlink()
    count = 0
    with pymupdf.open(pdf_path) as doc:
        for page in doc:
            count += 1
            pix = page.get_pixmap(dpi=dpi)
            pix.save(out_dir / f"page-{count:03d}.png")
    return count


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate the bea-utiful photo book PDF.")
    parser.add_argument("--month", help="YYYY-MM, defaults to the latest month with dated photos")
    parser.add_argument("--roll", help="roll-N to build a whole roll")
    parser.add_argument("--draft", action="store_true", help="ignore featured, include all dated photos")
    parser.add_argument("--open", action="store_true", help="open the finished PDF")
    args = parser.parse_args()

    entries = collect_entries()
    selected, title, month, photo_count = select_book(entries, args.month, args.roll, args.draft)

    BUILD_DIR.mkdir(exist_ok=True)
    html_path = BUILD_DIR / "book.html"
    html_path.write_text(
        html_for(selected, title, cover_colour(month), photo_count), encoding="utf-8"
    )

    slug = title.lower().replace(" ", "-")
    pdf_path = BUILD_DIR / f"book-{slug}.pdf"

    from weasyprint import HTML  # noqa: PLC0415 — imported after arg parsing on purpose

    HTML(
        string=html_for(selected, title, cover_colour(month), photo_count),
        base_url=str(PROJECT_DIR),
    ).write_pdf(str(pdf_path), stylesheets=[CSS_FILE])
    print(f"Wrote {pdf_path} ({len(selected)} photos)")

    png_dir = BUILD_DIR / "pngs"
    pages = export_pages_as_pngs(pdf_path, png_dir)
    print(f"Wrote {pages} PNGs to {png_dir}")

    if args.open:
        subprocess.run(["open", str(pdf_path)], check=False)
    return 0


if __name__ == "__main__":
    sys.exit(main())
