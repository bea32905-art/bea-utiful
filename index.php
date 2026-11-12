<?php
declare(strict_types=1);

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeMetadataKey(string $value): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($value));
    return is_string($normalized) ? $normalized : trim($value);
}

function lookupImageDetails(array $imageDetails, string $filename): array
{
    if (isset($imageDetails[$filename]) && is_array($imageDetails[$filename])) {
        return $imageDetails[$filename];
    }

    $normalizedFilename = normalizeMetadataKey($filename);
    foreach ($imageDetails as $imageName => $details) {
        if (is_array($details) && normalizeMetadataKey((string) $imageName) === $normalizedFilename) {
            return $details;
        }
    }

    return [];
}

function collectRollImageFiles(string $rollDirectory): array
{
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

function rollImageUrl(string $rollName, string $relativePath): string
{
    $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $relativePath)), static fn(string $segment): bool => $segment !== ''));
    return 'photos/photo%20journal/' . rawurlencode($rollName) . '/' . implode('/', array_map('rawurlencode', $segments));
}

/**
 * Stored dates are ISO (YYYY-MM-DD); render as DD/MM/YYYY for readers.
 * Legacy or non-conforming values are displayed as-is rather than guessed at.
 */
function displayDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return 'Not added yet';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
        return $matches[3] . '/' . $matches[2] . '/' . $matches[1];
    }
    return $value;
}

/**
 * Returns the poem lines for an image. New schema: "haiku" array.
 * Legacy "description" strings are tolerated until the remaining
 * metadata files are migrated.
 */
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

    $legacyDescription = trim((string) ($data['description'] ?? ''));
    return $legacyDescription !== '' ? [$legacyDescription] : [];
}

function metaValue(array $data, string $key): string
{
    $value = trim((string) ($data[$key] ?? ''));
    return $value !== '' ? $value : 'Not added yet';
}

/**
 * Discover rolls from the filesystem so new rolls appear automatically:
 * create photos/photo journal/roll-N/, drop in scans and metadata.json, done.
 */
$journalDirectory = __DIR__ . '/photos/photo journal';
$rolls = [];
foreach (glob($journalDirectory . '/roll-*', GLOB_ONLYDIR) ?: [] as $rollDirectoryPath) {
    $rollName = basename((string) $rollDirectoryPath);
    if (preg_match('/^roll-(\d+)$/', $rollName, $matches)) {
        $rolls[$rollName] = [
            'number' => (int) $matches[1],
            'directory' => (string) $rollDirectoryPath,
        ];
    }
}
uasort($rolls, static fn(array $a, array $b): int => $a['number'] <=> $b['number']);

$staticPages = ['home', 'photography', 'journal', 'digital', 'about', 'about-me', 'photo'];
$page = $_GET['page'] ?? 'home';
$validPages = array_merge($staticPages, array_keys($rolls));
if (!in_array($page, $validPages, true)) {
    $page = 'home';
}

$rollName = '';
if (array_key_exists($page, $rolls)) {
    $rollName = $page;
} elseif ($page === 'photo') {
    $requestedRoll = (string) ($_GET['roll'] ?? '');
    $rollName = isset($rolls[$requestedRoll]) ? $requestedRoll : (array_key_last($rolls) ?: '');
}

$rollLabel = $rollName !== '' ? sprintf('Roll %02d', $rolls[$rollName]['number']) : '';
$rollDirectory = $rollName !== '' ? $rolls[$rollName]['directory'] : '';

$metadata = [];
if ($rollDirectory !== '') {
    $metadataFile = $rollDirectory . '/metadata.json';
    if (is_file($metadataFile)) {
        $decoded = json_decode((string) file_get_contents($metadataFile), true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }
}
$imageDetails = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];

$imageFiles = collectRollImageFiles($rollDirectory);
$imageNames = array_map('basename', $imageFiles);
$imagePathMap = [];
foreach ($imageFiles as $relativePath) {
    $imagePathMap[basename($relativePath)] = $relativePath;
}

/*
 * Permalinks are filename-keyed so they survive re-scans, removals and
 * reordering. Legacy ?image=N URLs still resolve (redirected to the
 * filename form implicitly) so old links keep working.
 */
$nameIndex = array_flip($imageNames);
$photoParam = trim((string) ($_GET['photo'] ?? ''));
$photoKey = '';
$photoIndex = 0;

if ($photoParam !== '') {
    if (isset($nameIndex[$photoParam])) {
        $photoKey = $photoParam;
    } else {
        foreach ($imageNames as $name) {
            if (normalizeMetadataKey($name) === normalizeMetadataKey($photoParam)) {
                $photoKey = $name;
                break;
            }
        }
    }
    if ($photoKey !== '') {
        $photoIndex = $nameIndex[$photoKey];
    }
}

$legacyIndex = filter_var($_GET['image'] ?? false, FILTER_VALIDATE_INT);
if ($photoKey === '' && $legacyIndex !== false && $imageNames !== []) {
    $photoIndex = max(0, min($legacyIndex, count($imageNames) - 1));
    $photoKey = $imageNames[$photoIndex];
}

$detail = $photoKey;
$detailData = $detail !== '' ? lookupImageDetails($imageDetails, $detail) : [];
$detailLines = haikuLines($detailData);
$detailFrameLabel = sprintf('Frame %02d', $photoIndex + 1);
$detailTitle = trim((string) ($detailData['title'] ?? '')) !== ''
    ? (string) $detailData['title']
    : $detailFrameLabel;
$photoUrl = static fn(string $filename): string =>
    rollImageUrl($rollName, $filename);
$detailPhotoHref = static fn(string $filename): string =>
    '?page=photo&amp;roll=' . escape($rollName) . '&amp;photo=' . rawurlencode($filename);

$pageTitles = [
    'home' => 'Home',
    'photography' => 'Photography',
    'journal' => 'Photo Journal',
    'digital' => 'Digital',
    'about' => 'About',
    'about-me' => 'About Me',
    'photo' => 'Photo Journal',
];
$title = $page === 'photo'
    ? $rollLabel . ' &middot; ' . $detailFrameLabel
    : ($rollLabel !== '' ? $rollLabel : ($pageTitles[$page] ?? 'Home'));

$digitalAlbumTitle = 'Solar Eclipse August 2026';
$digitalAlbumSlug = 'solar-eclipse-august-2026';
$digitalAlbumDirectory = __DIR__ . '/photos/digital/' . $digitalAlbumTitle;
$showDigitalAlbum = $page === 'digital' && ($_GET['album'] ?? '') === $digitalAlbumSlug;
$eclipseMetadata = [];
$eclipsePosterFile = $digitalAlbumDirectory . '/eclipse a4.png';
$digitalIndividualDirectory = $digitalAlbumDirectory . '/Individual';
$digitalIndividualFiles = [];
if ($showDigitalAlbum) {
    $eclipseMetadataFile = $digitalAlbumDirectory . '/metadata.json';
    if (is_file($eclipseMetadataFile)) {
        $decoded = json_decode((string) file_get_contents($eclipseMetadataFile), true);
        if (is_array($decoded)) {
            $eclipseMetadata = $decoded;
        }
    }
    $digitalIndividualFiles = glob($digitalIndividualDirectory . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [];
    usort($digitalIndividualFiles, static fn(string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));
    $digitalIndividualFiles = array_slice($digitalIndividualFiles, 0, 16);
}
$digitalPhotoUrl = static fn(string $filename): string =>
    'photos/digital/' . rawurlencode($digitalAlbumTitle) . '/' . rawurlencode($filename);
$digitalIndividualPhotoUrl = static fn(string $filename): string =>
    'photos/digital/' . rawurlencode($digitalAlbumTitle) . '/Individual/' . rawurlencode($filename);

$aboutParagraphs = [];
if (in_array($page, ['about', 'about-me'], true)) {
    $aboutFile = $page === 'about-me' ? 'about-me.json' : 'about.json';
    $aboutData = json_decode((string) file_get_contents(__DIR__ . '/' . $aboutFile), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($aboutData) || !is_array($aboutData['paragraphs'] ?? null)) {
        throw new UnexpectedValueException($aboutFile . ' must contain a "paragraphs" array.');
    }

    foreach ($aboutData['paragraphs'] as $paragraph) {
        if (!is_string($paragraph)) {
            throw new UnexpectedValueException('Every About page paragraph must be a string.');
        }
        $paragraph = trim($paragraph);
        if ($paragraph !== '') {
            $aboutParagraphs[] = $paragraph;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f2f4f1">
    <title><?= escape($title) ?> | bea-utiful</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="page-<?= escape($page) ?>">
    <header class="site-header">
        <a class="wordmark" href="?page=home" aria-label="bea-utiful home">bea-utiful</a>
        <span class="header-note"> </span>
    </header>

    <main>
        <?php if (in_array($page, ['home', 'photography', 'journal'], true)): ?>
            <section class="link-page" aria-labelledby="page-title">
                <p class="eyebrow">bea-utiful.me <span>/</span> 2026</p>
                <h1 id="page-title"><?= escape($title) ?></h1>
                <nav class="link-list" aria-label="Explore">
                    <?php if ($page === 'home'): ?>
                        <a class="link-button" href="?page=photography"><span>Photography</span><span aria-hidden="true">&rarr;</span></a>
                        <a class="link-button" href="?page=about-me"><span>About Me</span><span aria-hidden="true">&rarr;</span></a>
                    <?php elseif ($page === 'photography'): ?>
                        <a class="link-button" href="?page=journal"><span>Photo Journal</span><span aria-hidden="true">&rarr;</span></a>
                        <a class="link-button" href="?page=digital"><span>Digital</span><span aria-hidden="true">&rarr;</span></a>
                    <?php else: ?>
                        <a class="link-button" href="?page=about"><span>About</span><span aria-hidden="true">&rarr;</span></a>
                        <?php foreach (array_reverse($rolls, true) as $rollLinkName => $rollInfo): ?>
                            <a class="link-button" href="?page=<?= escape($rollLinkName) ?>"><span><?= escape(sprintf('Roll %02d', $rollInfo['number'])) ?></span><span aria-hidden="true">&rarr;</span></a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </nav>
                <a class="back-link" href="<?= $page === 'home' ? '?page=home' : '?page=photography' ?>">&larr; <?= $page === 'home' ? 'Home' : 'Photography' ?></a>
            </section>
        <?php elseif (array_key_exists($page, $rolls)): ?>
            <section class="roll-page" aria-labelledby="page-title">
                <div class="page-heading">
                    <a class="back-link" href="?page=journal">&larr; Photo Journal</a>
                    <p class="eyebrow">35mm film <span>/</span> Photo gallery</p>
                    <h1 id="page-title"><?= escape($rollLabel) ?></h1>
                </div>
                <dl class="roll-meta">
                    <div><dt>Film Type</dt><dd><?= escape(metaValue($metadata, 'film_type')) ?></dd></div>
                    <div><dt>Start Date</dt><dd><?= escape(displayDate($metadata['start_date'] ?? null)) ?></dd></div>
                    <div><dt>End Date</dt><dd><?= escape(displayDate($metadata['end_date'] ?? null)) ?></dd></div>
                    <div class="roll-notes"><dt>Notes</dt><dd><?= nl2br(escape(metaValue($metadata, 'notes'))) ?></dd></div>
                </dl>
                <?php if ($imageNames): ?>
                    <div class="photo-grid" aria-label="Photographs in <?= escape($rollLabel) ?>">
                        <?php foreach ($imageNames as $index => $filename): ?>
                            <?php
                            $tileData = lookupImageDetails($imageDetails, $filename);
                            $tileLines = haikuLines($tileData);
                            $tileAlt = $tileLines !== []
                                ? implode(' ', $tileLines)
                                : sprintf('Photograph %02d', $index + 1);
                            $tilePath = $imagePathMap[$filename] ?? $filename;
                            ?>
                            <a class="photo-tile" href="<?= $detailPhotoHref($filename) ?>" aria-label="Open <?= escape(sprintf('frame %02d', $index + 1)) ?>">
                                <img src="<?= escape($photoUrl($tilePath)) ?>" alt="<?= escape($tileAlt) ?>" loading="lazy">
                                <span><?= sprintf('%02d', $index + 1) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-state">No photographs have been added to this roll yet.</p>
                <?php endif; ?>
            </section>
        <?php elseif ($page === 'photo' && $detail !== ''): ?>
            <section class="photo-detail" aria-labelledby="page-title">
                <div class="detail-image-wrap">
                    <a class="back-link" href="?page=<?= escape($rollName) ?>">&larr; <?= escape($rollLabel) ?></a>
                    <?php $detailPath = $imagePathMap[$detail] ?? $detail; ?>
                    <img class="detail-image" src="<?= escape($photoUrl($detailPath)) ?>" alt="<?= escape($detailTitle) ?>">
                </div>
                <div class="detail-copy">
                    <p class="eyebrow"><?= escape($rollLabel) ?> <span>/</span> <?= escape($detailFrameLabel) ?></p>
                    <h1 id="page-title"><?= escape($detailTitle) ?></h1>
                    <dl class="detail-meta">
                        <div><dt>Date</dt><dd><?= escape(displayDate($detailData['date'] ?? null)) ?></dd></div>
                        <?php if ($detailLines): ?>
                            <div><dt>Haiku</dt><dd class="haiku"><?php foreach ($detailLines as $line): ?><span class="haiku-line"><?= escape($line) ?></span><?php endforeach; ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Notes</dt><dd><?= nl2br(escape(metaValue($detailData, 'notes'))) ?></dd></div>
                    </dl>
                    <nav class="frame-nav" aria-label="Browse photographs">
                        <?php if ($photoIndex > 0): ?>
                            <a href="<?= $detailPhotoHref($imageNames[$photoIndex - 1]) ?>">&larr; Previous</a>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <span><?= sprintf('%02d', $photoIndex + 1) ?> / <?= sprintf('%02d', count($imageNames)) ?></span>
                        <?php if ($photoIndex < count($imageNames) - 1): ?>
                            <a href="<?= $detailPhotoHref($imageNames[$photoIndex + 1]) ?>">Next &rarr;</a>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                    </nav>
                </div>
            </section>
        <?php elseif ($page === 'digital' && $showDigitalAlbum): ?>
            <section class="roll-page" aria-labelledby="page-title">
                <div class="page-heading">
                    <a class="back-link" href="?page=digital">&larr; Digital</a>
                    <p class="eyebrow">Photography <span>/</span> Digital</p>
                    <h1 id="page-title"><?= escape($digitalAlbumTitle) ?></h1>
                </div>
                <section class="eclipse-notes" aria-labelledby="eclipse-notes-title">
                    <h2 id="eclipse-notes-title">Notes</h2>
                    <p><?= nl2br(escape(metaValue($eclipseMetadata, 'notes'))) ?></p>
                </section>
                <?php if (is_file($eclipsePosterFile)): ?>
                    <img class="eclipse-poster" src="<?= escape($digitalPhotoUrl('eclipse a4.png')) ?>" alt="Solar eclipse A4 artwork">
                <?php endif; ?>
                <?php if ($digitalIndividualFiles): ?>
                    <div class="eclipse-grid" aria-label="Individual solar eclipse photographs">
                        <?php foreach ($digitalIndividualFiles as $index => $imageFile): ?>
                            <div class="eclipse-tile">
                                <img src="<?= escape($digitalIndividualPhotoUrl(basename($imageFile))) ?>" alt="Solar eclipse photograph <?= $index + 1 ?>" loading="lazy">
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (!is_file($eclipsePosterFile)): ?>
                    <p class="empty-state">No photographs have been added to this album yet.</p>
                <?php endif; ?>
            </section>
        <?php elseif ($page === 'digital'): ?>
            <section class="placeholder-page" aria-labelledby="page-title">
                <a class="back-link" href="?page=photography">&larr; Photography</a>
                <p class="eyebrow">Photography <span>/</span> Digital</p>
                <h1 id="page-title"><?= escape($title) ?></h1>
                <nav class="link-list" aria-label="Digital photo albums">
                    <a class="link-button" href="?page=digital&amp;album=<?= escape($digitalAlbumSlug) ?>">
                        <span><?= escape($digitalAlbumTitle) ?></span><span aria-hidden="true">&rarr;</span>
                    </a>
                </nav>
            </section>
        <?php elseif ($page === 'about-me'): ?>
            <section class="about-me-page" aria-labelledby="page-title">
                <header class="about-me-heading">
                    <a class="back-link" href="?page=home">&larr; Home</a>
                    <p class="eyebrow">bea-utiful.me <span>/</span> About Me</p>
                    <h1 id="page-title"><?= escape($title) ?></h1>
                </header>
                <figure class="about-me-photo">
                    <?php if (is_file(__DIR__ . '/about-me.jpeg')): ?>
                        <img src="about-me.jpeg" alt="Portrait">
                    <?php else: ?>
                        <div class="about-me-photo-placeholder" role="img" aria-label="Portrait photo placeholder">
                            <span>Add a portrait as about-me.jpeg</span>
                        </div>
                    <?php endif; ?>
                </figure>
                <section class="about-me-text" aria-label="About me">
                    <?php if ($aboutParagraphs): ?>
                        <?php foreach ($aboutParagraphs as $paragraph): ?>
                            <p class="placeholder-copy"><?= nl2br(escape($paragraph)) ?></p>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="placeholder-copy">Add your introduction to about-me.json.</p>
                    <?php endif; ?>
                </section>
            </section>
        <?php elseif ($page === 'about' || $page === 'photo'): ?>
            <section class="placeholder-page" aria-labelledby="page-title">
                <a class="back-link" href="?page=journal">&larr; Photo Journal</a>
                <p class="eyebrow">bea-utiful.me <span>/</span> <?= escape($title) ?></p>
                <h1 id="page-title"><?= escape($title) ?></h1>
                <?php if ($page === 'about' && $aboutParagraphs): ?>
                    <?php foreach ($aboutParagraphs as $paragraph): ?>
                        <p class="placeholder-copy"><?= nl2br(escape($paragraph)) ?></p>
                    <?php endforeach; ?>
                <?php elseif ($page === 'about'): ?>
                    <p class="placeholder-copy">Add your About page text to the paragraphs list in about.json.</p>
                <?php else: ?>
                    <p class="placeholder-copy">This photograph could not be found. It may have been renamed &mdash; try opening the roll page again.</p>
                <?php endif; ?>
                <a class="link-button" href="?page=journal"><span>Explore Photo Journal</span><span aria-hidden="true">&rarr;</span></a>
            </section>
        <?php endif; ?>
    </main>

    <footer class="site-footer"><span>© <?= date('Y') ?> bea-utiful</span></footer>
</body>
</html>