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

$page = $_GET['page'] ?? 'home';
$validPages = ['home', 'photography', 'journal', 'digital', 'about', 'about-me', 'roll-0', 'roll-1', 'roll-2', 'roll-3', 'photo'];
if (!in_array($page, $validPages, true)) {
    $page = 'home';
}

$availableRolls = ['roll-0', 'roll-1', 'roll-2', 'roll-3'];
$roll = in_array($page, $availableRolls, true)
    ? $page
    : (in_array($_GET['roll'] ?? '', $availableRolls, true) ? $_GET['roll'] : 'roll-1');
$rollNumber = (int) substr($roll, strlen('roll-'));
$rollLabel = sprintf('Roll %02d', $rollNumber);
$photoDirectory = __DIR__ . '/photos/photo journal/' . $roll;
$metadataFile = $photoDirectory . '/metadata.json';
$metadata = is_file($metadataFile)
    ? json_decode((string) file_get_contents($metadataFile), true)
    : [];
$metadata = is_array($metadata) ? $metadata : [];

$imageFiles = glob($photoDirectory . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [];
usort($imageFiles, static fn(string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));
$imageFiles = array_slice($imageFiles, 0, 36);
$imageNames = array_map('basename', $imageFiles);
$imageIndex = filter_var($_GET['image'] ?? 0, FILTER_VALIDATE_INT);
$imageIndex = max(0, min((int) $imageIndex, max(0, count($imageFiles) - 1)));
$imageDetails = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
$rollTitles = [
    'home' => 'Home',
    'photography' => 'Photography',
    'journal' => 'Photo Journal',
    'digital' => 'Digital',
    'about' => 'About',
    'about-me' => 'About Me',
    'roll-0' => 'Roll 00',
    'roll-1' => 'Roll 01',
    'roll-2' => 'Roll 02',
    'roll-3' => 'Roll 03',
    'photo' => 'Photo Journal',
];
$title = $rollTitles[$page];
$photoUrl = static fn(string $filename): string => 'photos/photo%20journal/' . $roll . '/' . rawurlencode($filename);
$detail = $imageNames[$imageIndex] ?? '';
$detailData = lookupImageDetails($imageDetails, $detail);

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
    $eclipseMetadata = is_file($eclipseMetadataFile)
        ? json_decode((string) file_get_contents($eclipseMetadataFile), true)
        : [];
    $eclipseMetadata = is_array($eclipseMetadata) ? $eclipseMetadata : [];
    $digitalIndividualFiles = glob($digitalIndividualDirectory . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [];
    usort($digitalIndividualFiles, static fn(string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));
    $digitalIndividualFiles = array_slice($digitalIndividualFiles, 0, 16);
}
$digitalPhotoUrl = static fn(string $filename): string => 'photos/digital/' . rawurlencode($digitalAlbumTitle) . '/' . rawurlencode($filename);
$digitalIndividualPhotoUrl = static fn(string $filename): string => 'photos/digital/' . rawurlencode($digitalAlbumTitle) . '/Individual/' . rawurlencode($filename);

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

function metadataValue(array $data, string $key): string
{
    $value = trim((string) ($data[$key] ?? ''));
    return $value !== '' ? $value : 'Not added yet';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f2f0e9">
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
                        <a class="link-button" href="?page=roll-0"><span>Roll 0</span><span aria-hidden="true">&rarr;</span></a>
                        <a class="link-button" href="?page=roll-1"><span>Roll 1</span><span aria-hidden="true">&rarr;</span></a>
                        <a class="link-button" href="?page=roll-2"><span>Roll 2</span><span aria-hidden="true">&rarr;</span></a>
                        <a class="link-button" href="?page=roll-3"><span>Roll 3</span><span aria-hidden="true">&rarr;</span></a>
                    <?php endif; ?>
                </nav>
                <a class="back-link" href="<?= $page === 'home' ? '?page=home' : ($page === 'photography' ? '?page=home' : '?page=photography') ?>">&larr; <?= $page === 'home' ? 'Home' : ($page === 'photography' ? 'Home' : 'Photography') ?></a>
            </section>
        <?php elseif (in_array($page, $availableRolls, true)): ?>
            <section class="roll-page" aria-labelledby="page-title">
                <div class="page-heading">
                    <a class="back-link" href="?page=journal">&larr; Photo Journal</a>
                    <p class="eyebrow">35mm film <span>/</span> Photo gallery</p>
                    <h1 id="page-title"><?= escape($rollLabel) ?></h1>
                </div>
                <dl class="roll-meta">
                    <div><dt>Film Type</dt><dd><?= escape(metadataValue($metadata, 'film_type')) ?></dd></div>
                    <div><dt>Start Date</dt><dd><?= escape(metadataValue($metadata, 'start_date')) ?></dd></div>
                    <div><dt>End Date</dt><dd><?= escape(metadataValue($metadata, 'end_date')) ?></dd></div>
                    <div class="roll-notes"><dt>Notes</dt><dd><?= nl2br(escape(metadataValue($metadata, 'notes'))) ?></dd></div>
                </dl>
                <?php if ($imageFiles): ?>
                    <div class="photo-grid" aria-label="Photographs in <?= escape($rollLabel) ?>">
                        <?php foreach ($imageNames as $index => $filename): ?>
                            <a class="photo-tile" href="?page=photo&amp;roll=<?= escape($roll) ?>&amp;image=<?= $index ?>" aria-label="Open photograph <?= $index + 1 ?>">
                                <img src="<?= escape($photoUrl($filename)) ?>" alt="Photograph <?= $index + 1 ?>" loading="lazy">
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
                    <a class="back-link" href="?page=<?= escape($roll) ?>">&larr; <?= escape($rollLabel) ?></a>
                    <img class="detail-image" src="<?= escape($photoUrl($detail)) ?>" alt="Photograph <?= $imageIndex + 1 ?>">
                </div>
                <div class="detail-copy">
                    <p class="eyebrow"><?= escape($rollLabel) ?> <span>/</span> Frame <?= sprintf('%02d', $imageIndex + 1) ?></p>
                    <h1 id="page-title"><?= escape($detailData['title'] ?? 'Photograph') ?></h1>
                    <dl class="detail-meta">
                        <div><dt>Date</dt><dd><?= escape(metadataValue($detailData, 'date')) ?></dd></div>
                        <div><dt>Description</dt><dd><?= nl2br(escape(metadataValue($detailData, 'description'))) ?></dd></div>
                        <div><dt>Notes</dt><dd><?= nl2br(escape(metadataValue($detailData, 'notes'))) ?></dd></div>
                    </dl>
                    <nav class="frame-nav" aria-label="Browse photographs">
                        <?php if ($imageIndex > 0): ?>
                            <a href="?page=photo&amp;roll=<?= escape($roll) ?>&amp;image=<?= $imageIndex - 1 ?>">&larr; Previous</a>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <span><?= sprintf('%02d', $imageIndex + 1) ?> / <?= sprintf('%02d', count($imageFiles)) ?></span>
                        <?php if ($imageIndex < count($imageFiles) - 1): ?>
                            <a href="?page=photo&amp;roll=<?= escape($roll) ?>&amp;image=<?= $imageIndex + 1 ?>">Next &rarr;</a>
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
                    <p><?= nl2br(escape(metadataValue($eclipseMetadata, 'notes'))) ?></p>
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
                    <p class="placeholder-copy">some other text</p>
                <?php endif; ?>
                <a class="link-button" href="?page=journal"><span>Explore Photo Journal</span><span aria-hidden="true">&rarr;</span></a>
            </section>
        <?php endif; ?>
    </main>

    <footer class="site-footer"><span>© <?= date('Y') ?> bea-utiful</span></footer>
</body>
</html>