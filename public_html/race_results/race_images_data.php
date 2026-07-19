<?php
/*
MRL RACE IMAGES DATA
VERSION: v001
LAST MODIFIED: 2026-07-10

PURPOSE
- Return only completed MRL races for a year.
- Read the latest stored MRL race snapshot.
- Determine the official race winner from position 1.
- Supply race_images.html with MRL race label + trusted winner name.

PHP COMPATIBILITY
- PHP 7.3 compatible.
*/

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function race_code_from_name(string $name): ?string
{
    if (preg_match('/^R(\d{1,2})(?:_|$)/i', $name, $m)) {
        return 'R' . str_pad((string)((int)$m[1]), 2, '0', STR_PAD_LEFT);
    }
    return null;
}

function latest_snapshot(string $raceDir): ?string
{
    $files = glob($raceDir . DIRECTORY_SEPARATOR . 'snapshot_*.html');
    if (!$files) {
        return null;
    }

    usort($files, function ($a, $b) {
        return strcmp(basename($a), basename($b));
    });

    return end($files) ?: null;
}

function latest_snapshot_fixed(string $raceDir): ?string
{
    $files = glob($raceDir . DIRECTORY_SEPARATOR . 'snapshot_*.html');
    if (!$files) {
        return null;
    }

    rsort($files, SORT_STRING);
    return $files[0] ?? null;
}

function read_meta(string $raceDir): array
{
    $metaPath = $raceDir . DIRECTORY_SEPARATOR . '_meta.json';
    if (!is_file($metaPath)) {
        return [];
    }

    $raw = @file_get_contents($metaPath);
    if ($raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function first_nonempty(array $values): string
{
    foreach ($values as $value) {
        if (is_scalar($value)) {
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }
    }
    return '';
}

function display_name_from_folder(string $folderName, array $meta, string $raceCode): string
{
    $fromMeta = first_nonempty([
        $meta['short_name'] ?? null,
        $meta['race_short_name'] ?? null,
        $meta['display_name'] ?? null,
        $meta['race_display_name'] ?? null,
        $meta['location'] ?? null,
        $meta['track_short_name'] ?? null
    ]);

    if ($fromMeta !== '') {
        return preg_replace('/^R\d+\s*/i', '', $fromMeta) ?: $fromMeta;
    }

    $name = preg_replace('/^R\d+_?/i', '', $folderName);
    $name = preg_replace('/_\d{10,}$/', '', (string)$name);
    $name = preg_replace('/^NASCAR_Cup_Series_at_/i', '', (string)$name);
    $name = str_replace('_', ' ', (string)$name);
    $name = preg_replace('/\s+/', ' ', (string)$name);

    return trim((string)$name) !== '' ? trim((string)$name) : $raceCode;
}

function winner_from_snapshot(string $snapshot): array
{
    $html = @file_get_contents($snapshot);
    if ($html === false || trim($html) === '') {
        return ['winner' => '', 'reason' => 'snapshot unreadable'];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML($html);
    libxml_clear_errors();

    if (!$loaded) {
        return ['winner' => '', 'reason' => 'snapshot HTML could not be parsed'];
    }

    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//tr');

    if ($rows === false) {
        return ['winner' => '', 'reason' => 'no table rows found'];
    }

    foreach ($rows as $row) {
        $cells = $xpath->query('./th|./td', $row);
        if ($cells === false || $cells->length < 2) {
            continue;
        }

        $first = trim(preg_replace('/\s+/', ' ', $cells->item(0)->textContent));
        if (!preg_match('/^1(?:st)?$/i', $first)) {
            continue;
        }

        for ($i = 1; $i < $cells->length; $i++) {
            $text = trim(preg_replace('/\s+/', ' ', $cells->item($i)->textContent));
            if ($text === '') {
                continue;
            }

            if (preg_match('/^(driver|car|manufacturer|laps|start|led|pts|bonus|penalty)$/i', $text)) {
                continue;
            }

            if (preg_match('/^\d+$/', $text)) {
                continue;
            }

            return ['winner' => $text, 'reason' => 'position 1 in latest MRL snapshot'];
        }
    }

    return ['winner' => '', 'reason' => 'position 1 driver not found'];
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > 2100) {
    out_json(['ok' => false, 'error' => 'Invalid year.'], 400);
}

$yearDir = __DIR__ . DIRECTORY_SEPARATOR . $year;
if (!is_dir($yearDir)) {
    out_json([
        'ok' => true,
        'year' => $year,
        'races' => [],
        'message' => 'Year folder not found.'
    ]);
}

$entries = scandir($yearDir);
$races = [];

foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $raceCode = race_code_from_name($entry);
    if ($raceCode === null) {
        continue;
    }

    $raceDir = $yearDir . DIRECTORY_SEPARATOR . $entry;
    if (!is_dir($raceDir)) {
        continue;
    }

    $snapshot = latest_snapshot_fixed($raceDir);
    if ($snapshot === null) {
        continue;
    }

    $winnerInfo = winner_from_snapshot($snapshot);
    $winner = trim((string)$winnerInfo['winner']);
    if ($winner === '') {
        continue;
    }

    $meta = read_meta($raceDir);
    $raceNumber = (int)substr($raceCode, 1);

    $races[] = [
        'race_number' => $raceNumber,
        'race_code' => $raceCode,
        'race_name' => display_name_from_folder($entry, $meta, $raceCode),
        'winner' => $winner,
        'snapshot' => basename($snapshot),
        'source' => 'latest MRL snapshot'
    ];
}

usort($races, function ($a, $b) {
    return $a['race_number'] <=> $b['race_number'];
});

out_json([
    'ok' => true,
    'year' => $year,
    'count' => count($races),
    'races' => $races
]);
