<?php
declare(strict_types=1);

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page = $_GET['page'] ?? 'home';
$validPages = ['home', 'photography', 'journal', 'about', 'roll-1', 'roll-2', 'photo'];
if (!in_array($page, $validPages, true)) {
    $page = 'home';
}

$photoDirectory = __DIR__ . '/photos/roll-1';
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
    'home' => 'A place for photographs.',
    'photography' => 'Photography',
    'journal' => 'Photo Journal',
    'about' => 'About',
    'roll-1' => 'Roll 01',
    'roll-2' => 'Roll 02',
    'photo' => 'Photo Journal',
];
$title = $rollTitles[$page];
$photoUrl = static fn(string $filename): string => 'photos/roll-1/' . rawurlencode($filename);
$detail = $imageNames[$imageIndex] ?? '';
$detailData = is_array($imageDetails[$detail] ?? null) ? $imageDetails[$detail] : [];

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
        <a class="wordmark" href="?page=home" aria-label="bea-utiful home">bea-utiful<span>.</span></a>
        <span class="header-note">A photographic journal</span>
    </header>

    <main>
        <?php if (in_array($page, ['home', 'photography', 'journal'], true)): ?>
            <section class="link-page" aria-labelledby="page-title">
                <p class="eyebrow">bea-utiful.co.uk <span>/</span> 2026</p>
                <h1 id="page-title"><?= escape($title) ?></h1>
                <nav class="link-list" aria-label="Explore">
                    <?php if ($page === 'home'): ?>
                        <a class="link-button" href="?page=photography"><span>Photography</span><span aria-hidden="true">&rarr;</span></a>
                    <?php elseif ($page === 'photography'): ?>
                        <a class="link-button" href="?page=journal"><span>Photo Journal</span><span aria-hidden="true">&rarr;</span></a>
                    <?php else: ?>
                        <a class="link-button" href="?page=about"><span>About</span><span aria-hidden="true">&rarr;</span></a>
                        <a class="link-button" href="?page=roll-1"><span>Roll 1</span><span aria-hidden="true">&rarr;</span></a>
                        <a class="link-button" href="?page=roll-2"><span>Roll 2</span><span aria-hidden="true">&rarr;</span></a>
                    <?php endif; ?>
                </nav>
                <a class="back-link" href="<?= $page === 'home' ? '?page=home' : ($page === 'photography' ? '?page=home' : '?page=photography') ?>">&larr; <?= $page === 'home' ? 'Home' : ($page === 'photography' ? 'Home' : 'Photography') ?></a>
            </section>
        <?php elseif ($page === 'roll-1'): ?>
            <section class="roll-page" aria-labelledby="page-title">
                <div class="page-heading">
                    <a class="back-link" href="?page=journal">&larr; Photo Journal</a>
                    <p class="eyebrow">35mm film <span>/</span> Contact sheet</p>
                    <h1 id="page-title">Roll 01</h1>
                </div>
                <dl class="roll-meta">
                    <div><dt>Film Type</dt><dd><?= escape(metadataValue($metadata, 'film_type')) ?></dd></div>
                    <div><dt>Start Date</dt><dd><?= escape(metadataValue($metadata, 'start_date')) ?></dd></div>
                    <div><dt>End Date</dt><dd><?= escape(metadataValue($metadata, 'end_date')) ?></dd></div>
                    <div class="roll-notes"><dt>Notes</dt><dd><?= nl2br(escape(metadataValue($metadata, 'notes'))) ?></dd></div>
                </dl>
                <?php if ($imageFiles): ?>
                    <div class="photo-grid" aria-label="Photographs in Roll 1">
                        <?php foreach ($imageNames as $index => $filename): ?>
                            <a class="photo-tile" href="?page=photo&amp;image=<?= $index ?>" aria-label="Open photograph <?= $index + 1 ?>">
                                <img src="<?= escape($photoUrl($filename)) ?>" alt="Photograph <?= $index + 1 ?>" loading="lazy">
                                <span><?= sprintf('%02d', $index + 1) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-state">Photographs will appear here when the roll is added.</p>
                <?php endif; ?>
            </section>
        <?php elseif ($page === 'photo' && $detail !== ''): ?>
            <section class="photo-detail" aria-labelledby="page-title">
                <div class="detail-image-wrap">
                    <a class="back-link" href="?page=roll-1">&larr; Roll 01</a>
                    <img class="detail-image" src="<?= escape($photoUrl($detail)) ?>" alt="Photograph <?= $imageIndex + 1 ?>">
                </div>
                <div class="detail-copy">
                    <p class="eyebrow">Roll 01 <span>/</span> Frame <?= sprintf('%02d', $imageIndex + 1) ?></p>
                    <h1 id="page-title"><?= escape($detailData['title'] ?? 'Photograph') ?></h1>
                    <dl class="detail-meta">
                        <div><dt>Date</dt><dd><?= escape(metadataValue($detailData, 'date')) ?></dd></div>
                        <div><dt>Description</dt><dd><?= nl2br(escape(metadataValue($detailData, 'description'))) ?></dd></div>
                        <div><dt>Notes</dt><dd><?= nl2br(escape(metadataValue($detailData, 'notes'))) ?></dd></div>
                    </dl>
                    <nav class="frame-nav" aria-label="Browse photographs">
                        <?php if ($imageIndex > 0): ?>
                            <a href="?page=photo&amp;image=<?= $imageIndex - 1 ?>">&larr; Previous</a>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <span><?= sprintf('%02d', $imageIndex + 1) ?> / <?= sprintf('%02d', count($imageFiles)) ?></span>
                        <?php if ($imageIndex < count($imageFiles) - 1): ?>
                            <a href="?page=photo&amp;image=<?= $imageIndex + 1 ?>">Next &rarr;</a>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                    </nav>
                </div>
            </section>
        <?php elseif ($page === 'about' || $page === 'roll-2' || $page === 'photo'): ?>
            <section class="placeholder-page" aria-labelledby="page-title">
                <a class="back-link" href="?page=journal">&larr; Photo Journal</a>
                <p class="eyebrow">bea-utiful.co.uk <span>/</span> Photo Journal</p>
                <h1 id="page-title"><?= escape($title) ?></h1>
                <p class="placeholder-copy"><?= $page === 'about' ? 'A growing collection of photographs and the stories around them.' : 'This roll is still waiting to be developed.' ?></p>
                <a class="link-button" href="?page=journal"><span>Back to Photo Journal</span><span aria-hidden="true">&rarr;</span></a>
            </section>
        <?php endif; ?>
    </main>

    <footer class="site-footer"><span>© <?= date('Y') ?> bea-utiful</span><span>Made to be looked at slowly.</span></footer>
</body>
</html>