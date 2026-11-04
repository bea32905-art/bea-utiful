# bea-utiful

PHP photo journal for bea-utiful.co.uk. Upload the project files to your Hostinger PHP hosting; no build step or database is required.

## Add photographs to Roll 1

Upload up to 36 `.jpg`, `.jpeg`, `.png`, `.webp`, or `.gif` files into `photos/roll-1/`. Files are shown in natural filename order, so names such as `01.jpg`, `02.jpg`, and `03.jpg` keep the gallery ordered. The responsive masonry layout preserves each photograph's original proportions.

Edit `photos/roll-1/metadata.json` to add the film type, start and end dates, roll notes, and optional per-image date, description, and notes. Per-image entries are keyed by the exact image filename.

## Add photographs to Roll 3

Upload up to 36 `.jpg`, `.jpeg`, `.png`, `.webp`, or `.gif` files into `photos/roll-3/`. Edit `photos/roll-3/metadata.json` to add the film type, dates, roll notes, and optional per-image details. Roll 3 appears in the Photo Journal and shows an empty-state message until photographs are added.

## Write the About page

Edit `about.json` and add each paragraph as a string in its `paragraphs` array. For example:

```json
{
  "paragraphs": [
    "Write the first paragraph here.",
    "Write another paragraph here."
  ]
}
```

The home, photography, and photo journal pages are intentionally small link pages. Roll 2 currently has a simple placeholder page ready for future content.
