<?php
declare(strict_types=1);

/**
 * rebuild_year_index.php
 *
 * VERSION: v1.00.00
 * LAST MODIFIED: 2026-03-12
 * BUILD TS: 20260312_073906
 *
 * CHANGELOG:
 * v1.00.00 (2026-03-12)
 *   - One-time repair / rebuild tool for /race_results/<year>/_year_index.json
 *   - Scans existing race folders already on disk.
 *   - Rebuilds index entries from folder names and each folder's _meta.json.
 *   - Does NOT modify snapshot files, hashes, or race _meta.json files.
 *   - Safe for repairing missing historical entries like 2026 R03.
 *
 * Usage (browser):
 *   /race_results/rebuild_year_index.php?year=2026
 *
 * Usage (CLI):
 *   php rebuild_year_index.php 2026
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_rebuild_year_index_php_errors.log');
error_reporting(E_ALL);

require_once __DIR__ . '/race_results_engine.php';

function rryi_out(string $line): void
{
    if (PHP_SAPI === 'cli') {
        echo $line . PHP_EOL;
        return;
    }

    echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

function rryi_parse_folder(string $folderName): ?array
{
    if (!preg_match('/^(R|E|Z)(\d{2})_(.+)_(\d+)$/', $folderName, $m)) {
        return null;
    }

    return [
        'kind' => (string)$m[1],
        'number' => (int)$m[2],
        'slug' => (string)$m[3],
        'race_id' => (string)$m[4],
    ];
}

function rryi_load_meta(string $folderPath): array
{
    $metaPath = $folderPath . '/_meta.json';
    if (!is_file($metaPath)) {
        return [];
    }

    $meta = rr_load_json($metaPath);
    return is_array($meta) ? $meta : [];
}

$year = 2026;

if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && count($argv) >= 2) {
    $cliYear = (int)$argv[1];
    if ($cliYear >= 2000 && $cliYear <= 2100) {
        $year = $cliYear;
    }
} elseif (isset($_GET['year'])) {
    $qYear = (int)$_GET['year'];
    if ($qYear >= 2000 && $qYear <= 2100) {
        $year = $qYear;
    }
}

$yearFolder = __DIR__ . '/' . (string)$year;

if (!is_dir($yearFolder)) {
    rryi_out("ERROR: year folder not found: " . $yearFolder);
    exit(0);
}

$items = scandir($yearFolder);
if (!is_array($items)) {
    rryi_out("ERROR: unable to read year folder.");
    exit(0);
}

$index = [
    'year' => $year,
    'generated_at' => date('c'),
    'races' => [],
];

$added = 0;

foreach ($items as $name) {
    if ($name === '.' || $name === '..') continue;

    $full = $yearFolder . '/' . $name;
    if (!is_dir($full)) continue;

    $parsed = rryi_parse_folder($name);
    if ($parsed === null) continue;

    $meta = rryi_load_meta($full);

    $raceId = (string)$parsed['race_id'];
    $raceUrl = (string)($meta['race_url'] ?? '');
    $raceName = (string)($meta['race_name'] ?? '');

    if ($raceName === '') {
        $raceName = str_replace('_', ' ', (string)$parsed['slug']);
    }

    $index['races'][$raceId] = [
        'folder' => $name,
        'kind' => (string)$parsed['kind'],
        'number' => (int)$parsed['number'],
        'race_url' => $raceUrl,
        'race_name' => $raceName,
    ];

    $added++;
}

if (!empty($index['races'])) {
    uksort($index['races'], function ($a, $b) use ($index) {
        $ra = $index['races'][$a];
        $rb = $index['races'][$b];

        $kindOrder = ['R' => 1, 'E' => 2, 'Z' => 3];

        $ka = $kindOrder[(string)($ra['kind'] ?? '')] ?? 99;
        $kb = $kindOrder[(string)($rb['kind'] ?? '')] ?? 99;

        if ($ka !== $kb) {
            return $ka <=> $kb;
        }

        $na = (int)($ra['number'] ?? 0);
        $nb = (int)($rb['number'] ?? 0);

        if ($na !== $nb) {
            return $na <=> $nb;
        }

        return strcmp((string)$a, (string)$b);
    });
}

$path = $yearFolder . '/_year_index.json';
rr_save_json($path, $index);

rryi_out("DONE: rebuilt _year_index.json for year {$year}");
rryi_out("Year folder: {$yearFolder}");
rryi_out("Entries written: {$added}");
rryi_out("Output file: {$path}");

exit(0);