# bea-utiful

PHP photo journal for bea-utiful.co.uk. Upload the project files to your Hostinger PHP hosting; no build step or database is required.

## Photo Journal

Upload up to 36 `.jpg`, `.jpeg`, `.png`, `.webp`, or `.gif` files into a roll folder under `photos/photo journal/`, such as `photos/photo journal/roll-1/`. Files are shown in natural filename order, so names such as `01.jpg`, `02.jpg`, and `03.jpg` keep the gallery ordered. The responsive masonry layout preserves each photograph's original proportions.

Edit each roll's `metadata.json` to add film type, start and end dates, roll notes, and optional per-image date, description, and notes. Per-image entries are keyed by the exact image filename.

## Digital photographs

Digital photo albums live in their own folders under `photos/digital/`. The Solar Eclipse album displays notes from `photos/digital/Solar Eclipse August 2026/metadata.json`, the `eclipse a4.png` artwork, and up to 16 images in natural filename order from its `Individual/` folder.

## About pages

The Photo Journal's **About** page describes the project. Edit `about.json` and add each paragraph as a string in its `paragraphs` array. The Home page's **About Me** page has separate text in `about-me.json` and a portrait image named `about-me.jpeg` beside `index.php`.

For either JSON file, add paragraphs as strings in its `paragraphs` array. For example:

```json
{
  "paragraphs": [
    "Write the first paragraph here.",
    "Write another paragraph here."
  ]
}
```

The home, photography, and photo journal pages are intentionally small link pages. Rolls without photographs show an empty-state message until images are added.
