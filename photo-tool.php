#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * photo-tool.php — lab import helper for the bea-utiful photo journal.
 *
 * Manages the 4-stage folder workflow and renames lab scans to the
 * site's naming convention:
 *
 *   Roll 04 - Kodak Gold 200 - 07 - Scan Export.jpg
 *   [roll no.] - [film type] - [frame no.] - [stage name]
 *
 * Commands
 * --------
 * Init a new roll (creates the folder structure + roll.json):
 *   php photo-tool.php init 4 "Kodak Gold 200"
 *
 * Rename the files inside one stage folder (dry run by default,
 * add --apply to actually rename):
 *   php photo-tool.php rename "rolls/roll-4/01 - Raw Scan"
 *   php photo-tool.php rename "rolls/roll-4/01 - Raw Scan" --apply
 *
 * Publish finished exports to the website and scaffold metadata.json:
 *   php photo-tool.php publish 4
 *   php photo-tool.php publish 4 --apply
 *
 * The tool finds the frame number either from the lab's numbering
 * (04358207.jpg -> frame 07) or from an already-renamed file coming
 * out of DXO/Affinity (which preserve the input filename), so you can
 * run "rename" in every stage folder after each export.
 */

const STAGES = [
    '01 - Raw Scan',
    '02 - Corrected Scan',
    '03 - Scan Adjusted',
    '04 - Scan Export',
];

// Where the roll working folders live. Adjust if yours differ.
const ROLLS_ROOT = __DIR__ . '/rolls';

// Where index.php expects the web-sized images.
const WEBSITE_PHOTOS_ROOT = __DIR__ . '/photos/photo journal';

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'];

exit(main($argv));

function main(array $argv): int
{
    $command = $argv[1] ?? '';
    $args = array_slice($argv, 2);
    $apply = in_array('--apply', $args, true);
    $args = array_values(array_filter($args, static fn(string $a): bool => $a !== '--apply'));

    switch ($command) {
        case 'init':
            return cmdInit($args);
        case 'rename':
            return cmdRename($args, $apply);
        case 'publish':
            return cmdPublish($args, $apply);
        default:
            fwrite(STDERR, <<<'TXT'
Usage:
  php photo-tool.php init <roll-no> "<film type>"
  php photo-tool.php rename <stage-folder> [--apply]
  php photo-tool.php publish <roll-no> [--apply]

Rename and publish are dry runs unless --apply is passed.

TXT);
            return 1;
    }
}

/* ------------------------------------------------------------------ init */

function cmdInit(array $args): int
{
    if (count($args) < 2 || !ctype_digit($args[0])) {
        fwrite(STDERR, "init needs a roll number and a film type, e.g.:\n  php photo-tool.php init 4 \"Kodak Gold 200\"\n");
        return 1;
    }
    $rollNo = (int) $args[0];
    $filmType = $args[1];
    $rollDir = ROLLS_ROOT . '/roll-' . $rollNo;

    if (is_dir($rollDir)) {
        fwrite(STDOUT, "Note: {$rollDir} already exists.\n");
    } else {
        if (!@mkdir($rollDir, 0777, true) && !is_dir($rollDir)) {
            fwrite(STDERR, "Could not create {$rollDir}\n");
            return 1;
        }
        fwrite(STDOUT, "Created {$rollDir}\n");
    }

    foreach (STAGES as $stage) {
        $stageDir = $rollDir . '/' . $stage;
        if (!is_dir($stageDir)) {
            mkdir($stageDir, 0777);
            fwrite(STDOUT, "Created {$stageDir}\n");
        }
    }

    $manifestFile = $rollDir . '/roll.json';
    if (!is_file($manifestFile)) {
        file_put_contents($manifestFile, json_encode([
            'roll_number' => $rollNo,
            'film_type' => $filmType,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fwrite(STDOUT, "Created {$manifestFile}\n");
    }

    fwrite(STDOUT, "\nNext: unzip your lab download into \"{$rollDir}/01 - Raw Scan\",\n");
    fwrite(STDOUT, "then run:  php photo-tool.php rename \"{$rollDir}/01 - Raw Scan\"\n");
    return 0;
}

/* ---------------------------------------------------------------- rename */

function cmdRename(array $args, bool $apply): int
{
    if ($args === []) {
        fwrite(STDERR, "rename needs a stage folder, e.g.: php photo-tool.php rename \"rolls/roll-4/01 - Raw Scan\"\n");
        return 1;
    }
    $folder = rtrim($args[0], '/\\');
    if (!is_dir($folder)) {
        fwrite(STDERR, "Folder not found: {$folder}\n");
        return 1;
    }

    // roll.json lives two levels up from a stage folder.
    $manifestFile = dirname(rtrim($folder, DIRECTORY_SEPARATOR)) . '/roll.json';
    if (!is_file($manifestFile)) {
        fwrite(STDERR, "Could not find roll.json at {$manifestFile}\n");
        fwrite(STDERR, "Run the init command first so the roll has its manifest.\n");
        return 1;
    }
    $manifest = json_decode((string) file_get_contents($manifestFile), true);
    if (!is_array($manifest) || !isset($manifest['roll_number'], $manifest['film_type'])) {
        fwrite(STDERR, "roll.json is malformed; expected roll_number and film_type.\n");
        return 1;
    }

    $rollNo = (int) $manifest['roll_number'];
    $filmType = (string) $manifest['film_type'];

    // Stage suffix: "01 - Raw Scan" -> "Raw Scan".
    $stageFolderName = basename($folder);
    $stageSuffix = preg_replace('/^\d{2}\s*-\s*/', '', $stageFolderName) ?? $stageFolderName;

    $changed = 0;
    $skipped = 0;
    $failed = 0;

    foreach (stageImages($folder) as $file) {
        $frame = extractFrameNumber($file);
        if ($frame === null) {
            fwrite(STDOUT, "  SKIP (could not find a frame number): " . basename($file) . "\n");
            $skipped++;
            continue;
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $newName = sprintf('Roll %02d - %s - %02d - %s.%s', $rollNo, $filmType, $frame, $stageSuffix, $extension);
        $newPath = dirname($file) . '/' . $newName;

        if (basename($file) === $newName) {
            $skipped++;
            continue;
        }
        if (is_file($newPath)) {
            fwrite(STDERR, "  COLLISION: {$newName} already exists; leaving " . basename($file) . " as-is\n");
            $failed++;
            continue;
        }

        fwrite(STDOUT, '  ' . basename($file) . "  ->  {$newName}" . ($apply ? '' : '   (dry run)') . "\n");
        if ($apply && !@rename($file, $newPath)) {
            fwrite(STDERR, "  FAILED to rename: " . basename($file) . "\n");
            $failed++;
            continue;
        }
        $changed++;
    }

    fwrite(STDOUT, "\n" . ($apply ? 'Renamed' : 'Would rename') . " {$changed} file(s); skipped {$skipped}; failed {$failed}.\n");
    if (!$apply && $changed > 0) {
        fwrite(STDOUT, "Run again with --apply to carry out the renames.\n");
    }
    return $failed > 0 ? 1 : 0;
}

/* --------------------------------------------------------------- publish */

function cmdPublish(array $args, bool $apply): int
{
    if ($args === [] || !ctype_digit($args[0])) {
        fwrite(STDERR, "publish needs a roll number: php photo-tool.php publish 4\n");
        return 1;
    }
    $rollNo = (int) $args[0];
    $rollDir = ROLLS_ROOT . '/roll-' . $rollNo;
    $exportDir = $rollDir . '/' . STAGES[3];

    if (!is_dir($exportDir)) {
        fwrite(STDERR, "Export folder not found: {$exportDir}\n");
        return 1;
    }

    $targetDir = WEBSITE_PHOTOS_ROOT . '/roll-' . $rollNo;
    if ($apply && !is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
        fwrite(STDOUT, "Created {$targetDir}\n");
    }

    $copied = 0;
    $existingMetadata = [];
    $metadataFile = $targetDir . '/metadata.json';
    if ($apply && is_file($metadataFile)) {
        $decoded = json_decode((string) file_get_contents($metadataFile), true);
        if (is_array($decoded)) {
            $existingMetadata = $decoded;
        }
    }

    $images = stageImages($exportDir, ['jpg', 'jpeg', 'png', 'webp']);
    if ($images === []) {
        fwrite(STDERR, "No JPG/PNG/WEBP files in {$exportDir}\n");
        return 1;
    }

    $manifestFile = $rollDir . '/roll.json';
    $manifest = is_file($manifestFile)
        ? (json_decode((string) file_get_contents($manifestFile), true) ?: [])
        : [];

    $metadata = $existingMetadata !== []
        ? $existingMetadata
        : [
            'film_type' => (string) ($manifest['film_type'] ?? ''),
            'start_date' => '',
            'end_date' => '',
            'notes' => '',
            'images' => [],
        ];
    if (!isset($metadata['images']) || !is_array($metadata['images'])) {
        $metadata['images'] = [];
    }

    foreach ($images as $file) {
        $name = basename($file);
        $dest = $targetDir . '/' . $name;
        fwrite(STDOUT, '  ' . $name . "  ->  photos/photo journal/roll-{$rollNo}/" . ($apply ? '' : '   (dry run)') . "\n");
        if ($apply) {
            if (!copy($file, $dest)) {
                fwrite(STDERR, "  FAILED to copy {$name}\n");
                continue;
            }
            // Scaffold metadata for images not yet present; existing
            // entries (dates, haikus) are never overwritten.
            if (!array_key_exists($name, $metadata['images'])) {
                $metadata['images'][$name] = new stdClass();
            }
        }
        $copied++;
    }

    if ($apply) {
        file_put_contents(
            $metadataFile,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
        fwrite(STDOUT, "Wrote {$metadataFile}\n");
    }

    fwrite(STDOUT, "\n" . ($apply ? 'Copied' : 'Would copy') . " {$copied} file(s)" . ($apply ? '' : '; run with --apply to publish.') . "\n");
    fwrite(STDOUT, "Reminder: resize before publishing to the web, or the grid serves lab-sized scans.\n");
    return 0;
}

/* -------------------------------------------------------------- helpers */

/** Image files in a folder, natural-sorted by frame order. */
function stageImages(string $folder, ?array $extensions = null): array
{
    $allowed = $extensions ?? IMAGE_EXTENSIONS;
    $files = [];
    $handle = opendir($folder);
    if ($handle === false) {
        return [];
    }
    while (($entry = readdir($handle)) !== false) {
        $path = $folder . '/' . $entry;
        if (!is_file($path)) {
            continue;
        }
        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($extension, $allowed, true)) {
            $files[] = $path;
        }
    }
    closedir($handle);
    usort($files, static fn(string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));
    return $files;
}

/**
 * Frame number from either naming scheme:
 *   04358207.jpg                                  ->  7   (lab: last two digits)
 *   Roll 02 - Kodak Ektar 100 - 07 - Raw Scan.jpg ->  7   (stage output)
 * Returns null if nothing conclusive can be found.
 */
function extractFrameNumber(string $path): ?int
{
    $stem = pathinfo($path, PATHINFO_FILENAME);

    if (preg_match('/^Roll \d+ - .+ - (\d{2}) - .+$/', $stem, $matches)) {
        return (int) $matches[1];
    }

    // Lab scans: trailing digits of a long numeric-ish stem, e.g. 04358207.
    if (preg_match('/^(?:\d{4,})(\d{2})$/', $stem, $matches)) {
        return (int) $matches[1];
    }

    // Some labs use separators: 043582_07, 043582-7, 043582 07...
    if (preg_match('/(?:^|[_\- ])(\d{1,2})$/', $stem, $matches)) {
        return (int) $matches[1];
    }

    return null;
}