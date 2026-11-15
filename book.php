<?php
declare(strict_types=1);

/**
 * Photo book generator for bea-utiful.
 *
 * Renders a print-ready, paginated webpage using the shared site stylesheet:
 * A4 landscape — photograph on the left, haiku and notes on the right.
 *
 * Usage:
 *   book.php                      -> latest month that has featured photos
 *   book.php?month=2026-10        -> specific month (grouped across rolls)
 *   book.php?roll=roll-2          -> whole roll in frame order
 *   book.php?month=2026-10&draft  -> prints ALL dated photos, ignores featured
 *
 * Output: print from the browser (Ctrl+P -> Save as PDF, A4 landscape,
 * background graphics on). Page size and margins are defined in style.css.
 */

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeMetadataKey(string $value): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($value));
    return is_string($normalized) ? $normalized : trim($value);
}

function haikuLines(array $data): array
{
    if (isset($data['haiku']) && is_array($data['haiku'])) {
        $lines = [];
        foreach ($data['haiku'] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }
    $legacy = trim((string) ($data['description'] ?? ''));
    return $legacy !== '' ? [$legacy] : [];
}

function loadJsonFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function collectRollImageFiles(string $rollDirectory): array
{
    if (!is_dir($rollDirectory)) {
        return [];
    }

    $files = glob($rollDirectory . '/**/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [];
    if ($files === []) {
        $files = glob($rollDirectory . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [];
    }

    $relativeFiles = [];
    foreach ($files as $path) {
        $relativeFiles[] = str_replace('\\', '/', substr((string) $path, strlen($rollDirectory) + 1));
    }

    usort($relativeFiles, static fn(string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));

    $preferred = [];
    $fallback = [];
    foreach ($relativeFiles as $relativePath) {
        $directory = dirname($relativePath);
        if ($directory === '1 - Web' || $directory === '1 - web') {
            $preferred[] = $relativePath;
            continue;
        }
        $fallback[] = $relativePath;
    }

    return $preferred !== [] ? $preferred : $fallback;
}

function imageUrlForRoll(string $rollName, string $relativePath): string
{
    $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $relativePath)), static fn(string $segment): bool => $segment !== ''));
    return 'photos/photo%20journal/' . rawurlencode($rollName) . '/' . implode('/', array_map('rawurlencode', $segments));
}

/* ---- Collect every image with metadata across all rolls ---- */

$journalDirectory = __DIR__ . '/photos/photo journal';
$entries = [];
$rollNumbers = [];

foreach (glob($journalDirectory . '/roll-*', GLOB_ONLYDIR) ?: [] as $rollDir) {
    $rollName = basename((string) $rollDir);
    if (!preg_match('/^roll-(\d+)$/', $rollName, $m)) {
        continue;
    }
    $rollNumber = (int) $m[1];
    $rollNumbers[$rollName] = $rollNumber;

    $metadata = loadJsonFile($rollDir . '/metadata.json');
    $imageDetails = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];

    $files = collectRollImageFiles($rollDir);

    foreach ($files as $index => $file) {
        $filename = basename($file);
        $details = $imageDetails[$filename] ?? null;
        if (!is_array($details)) {
            foreach ($imageDetails as $key => $candidate) {
                if (is_array($candidate) && normalizeMetadataKey((string) $key) === normalizeMetadataKey($filename)) {
                    $details = $candidate;
                    break;
                }
            }
            if (!is_array($details)) {
                continue;
            }
        }

        $date = trim((string) ($details['date'] ?? ''));
        $isIso = (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm);

        $entries[] = [
            'roll' => $rollName,
            'roll_number' => $rollNumber,
            'frame' => $index + 1,
            'filename' => $filename,
            'url' => imageUrlForRoll($rollName, $file),
            'date' => $isIso ? $date : '',
            'iso_parts' => $isIso ? [$dm[1], $dm[2], $dm[3]] : null,
            'haiku' => haikuLines($details),
            'notes' => trim((string) ($details['notes'] ?? '')),
            'featured' => ($details['featured'] ?? false) === true,
        ];
    }
}

/* ---- Select the pages for this book ---- */

$mode = 'month';
$monthParam = trim((string) ($_GET['month'] ?? ''));
$rollParam = trim((string) ($_GET['roll'] ?? ''));
$draftMode = isset($_GET['draft']);

if ($rollParam !== '' && isset($rollNumbers[$rollParam])) {
    $mode = 'roll';
    $selected = array_values(array_filter($entries, static fn(array $e): bool => $e['roll'] === $rollParam));
    $bookTitle = sprintf('Roll %02d', $rollNumbers[$rollParam]);
    $bookSubtitle = '';
} else {
    $months = [];
    foreach ($entries as $entry) {
        if ($entry['date'] !== '') {
            $months[$entry['iso_parts'][0] . '-' . $entry['iso_parts'][1]] = true;
        }
    }
    if ($monthParam === '') {
        $monthParam = $months === [] ? '' : array_key_last($months);
    }
    if ($monthParam === '' || !preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
        $selected = [];
        $bookTitle = 'Empty Book';
        $bookSubtitle = 'No dated photographs found.';
    } else {
        $inMonth = array_values(array_filter(
            $entries,
            static fn(array $e): bool => $e['iso_parts'] !== null && ($e['iso_parts'][0] . '-' . $e['iso_parts'][1]) === $monthParam
        ));
        $featured = array_values(array_filter($inMonth, static fn(array $e): bool => $e['featured']));
        $chosen = ($featured !== [] && !$draftMode) ? $featured : $inMonth;
        usort($chosen, static fn(array $a, array $b): int => [$a['date'], $a['roll_number'], $a['frame']] <=> [$b['date'], $b['roll_number'], $b['frame']]);
        $selected = $chosen;

        $bookTitle = DateTime::createFromFormat('!Y-m', $monthParam)?->format('F Y') ?: $monthParam;
        $bookSubtitle = count($selected) . ' ' . (count($selected) === 1 ? 'day' : 'days');
    }
}

$coverNote = match ($mode) {
    'roll' => 'Photo Journal / 35mm',
    default => 'Photo Journal',
};

function displayDay(array $entry): string
{
    if ($entry['iso_parts'] === null) {
        return 'Undated';
    }
    return DateTime::createFromFormat('!Y-m-d', $entry['date'])?->format('d/m/Y') ?: $entry['date'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape($bookTitle) ?> — Photo Book | bea-utiful</title>

    <link rel="stylesheet" href="style.css">

    <script>
        window.addEventListener('load', () => {
            const printBar = document.getElementById('print-bar');
            document.getElementById('print-btn').addEventListener('click', () => window.print());
            printBar.hidden = false;

            if (new URLSearchParams(location.search).has('autoprint')) {
                window.print();
            }
        });
    </script>
</head>
<body class="page-book">
    <div class="no-print-ui" id="print-bar" hidden>
        <span>Photo book ready — print to PDF (A4 landscape, background graphics: on)</span>
        <button id="print-btn">Print</button>
    </div>

    <section class="cover">
        <p class="eyebrow"><?= escape($coverNote) ?></p>
        <h1><?= escape($bookTitle) ?></h1>
        <?php if ($bookSubtitle !== ''): ?>
            <p class="subtitle"><?= escape($bookSubtitle) ?></p>
        <?php endif; ?>
        <div class="rule"></div>
    </section>

    <?php if ($selected === []): ?>
        <section class="colophon">
            <p>No dated, featured photographs were found for this book.</p>
            <p>Add dates in YYYY-MM-DD format and "featured": true to your
               metadata.json files, then reload this page.</p>
        </section>
    <?php else: ?>
        <?php foreach ($selected as $entry): ?>
            <section class="day">
                <div class="date-line">
                    <span><?= escape(displayDay($entry)) ?></span>
                    <span class="frame-ref"><?= escape(sprintf('Roll %02d · Frame %02d', $entry['roll_number'], $entry['frame'])) ?></span>
                </div>
                <figure class="frame">
                    <img src="<?= escape($entry['url']) ?>" alt="">
                </figure>
                <?php if ($entry['haiku'] !== []): ?>
                    <p class="haiku"><?php foreach ($entry['haiku'] as $line): ?><span class="line"><?= escape($line) ?></span><?php endforeach; ?></p>
                <?php endif; ?>
                <?php if ($entry['notes'] !== ''): ?>
                    <p class="footnote"><?= escape($entry['notes']) ?></p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <section class="colophon">
            <p>bea-utiful photo journal</p>
            <p><?= escape($bookTitle) ?></p>
            <p>Shot on 35mm film. Handmade book.</p>
        </section>
    <?php endif; ?>

</body>
</html>