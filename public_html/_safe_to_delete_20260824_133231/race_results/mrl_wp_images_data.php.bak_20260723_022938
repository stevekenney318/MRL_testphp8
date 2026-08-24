<?php
/*
MRL WP IMAGES DATA
VERSION: v002
LAST MODIFIED: 7/22/2026 3:58:40 pm

CHANGELOG
v002 (7/22/2026 3:58:40 pm)
- ITERATION: Renamed endpoint to mrl_wp_images_data.php.
- FIX: Canonical snapshot discovery accepts only snapshot_YYYYMMDD_HHMMSSmmm.html.
- NEW: Supplies week, year, release ID, race label, winner, and canonical snapshot.
- CHANGE: Supports the consolidated MRL WP Images v002 page.

PHP 7.3 compatible.
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

function canonical_snapshots(string $raceDir): array
{
    $files = glob($raceDir . DIRECTORY_SEPARATOR . 'snapshot_*.html') ?: [];
    $out = [];
    foreach ($files as $file) {
        if (preg_match('/^snapshot_\d{8}_\d{9}\.html$/', basename($file))) {
            $out[] = $file;
        }
    }
    rsort($out, SORT_STRING);
    return $out;
}

function latest_canonical_snapshot(string $raceDir): ?string
{
    $files = canonical_snapshots($raceDir);
    return $files[0] ?? null;
}

function read_meta(string $raceDir): array
{
    $path = $raceDir . DIRECTORY_SEPARATOR . '_meta.json';
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function first_nonempty(array $values): string
{
    foreach ($values as $value) {
        if (is_scalar($value)) {
            $text = trim((string)$value);
            if ($text !== '') return $text;
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
    if ($html === false || trim($html) === '') return ['winner'=>'','reason'=>'snapshot unreadable'];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML($html);
    libxml_clear_errors();
    if (!$loaded) return ['winner'=>'','reason'=>'snapshot HTML could not be parsed'];

    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//tr');
    if ($rows === false) return ['winner'=>'','reason'=>'no table rows found'];

    foreach ($rows as $row) {
        $cells = $xpath->query('./th|./td', $row);
        if ($cells === false || $cells->length < 2) continue;
        $first = trim((string)preg_replace('/\s+/', ' ', $cells->item(0)->textContent));
        if (!preg_match('/^1(?:st)?$/i', $first)) continue;

        for ($i=1; $i<$cells->length; $i++) {
            $text = trim((string)preg_replace('/\s+/', ' ', $cells->item($i)->textContent));
            if ($text === '' || preg_match('/^\d+$/', $text)) continue;
            if (preg_match('/^(driver|car|manufacturer|laps|start|led|pts|bonus|penalty)$/i', $text)) continue;
            return ['winner'=>$text,'reason'=>'position 1 in latest canonical MRL snapshot'];
        }
    }
    return ['winner'=>'','reason'=>'position 1 driver not found'];
}

function release_id_from_snapshot(string $snapshot, string $raceCode): string
{
    if (preg_match('/^snapshot_(\d{8}_\d{9})\.html$/', basename($snapshot), $m)) {
        return $m[1] . '_' . $raceCode;
    }
    return $raceCode;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > 2100) out_json(['ok'=>false,'error'=>'Invalid year.'], 400);

$yearDir = __DIR__ . DIRECTORY_SEPARATOR . $year;
if (!is_dir($yearDir)) {
    out_json(['ok'=>true,'year'=>$year,'races'=>[],'message'=>'Year folder not found.']);
}

$entries = scandir($yearDir) ?: [];
$races = [];

foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $raceCode = race_code_from_name($entry);
    if ($raceCode === null) continue;

    $raceDir = $yearDir . DIRECTORY_SEPARATOR . $entry;
    if (!is_dir($raceDir)) continue;

    $snapshot = latest_canonical_snapshot($raceDir);
    if ($snapshot === null) continue;

    $winnerInfo = winner_from_snapshot($snapshot);
    $winner = trim((string)$winnerInfo['winner']);
    if ($winner === '') continue;

    $meta = read_meta($raceDir);
    $raceNumber = (int)substr($raceCode,1);

    $races[] = [
        'year' => $year,
        'week' => $raceNumber,
        'race_number' => $raceNumber,
        'race_code' => $raceCode,
        'race_name' => display_name_from_folder($entry,$meta,$raceCode),
        'winner' => $winner,
        'snapshot' => basename($snapshot),
        'release_id' => release_id_from_snapshot($snapshot,$raceCode),
        'source' => 'latest canonical MRL snapshot'
    ];
}

usort($races, function($a,$b){ return $a['race_number'] <=> $b['race_number']; });
out_json(['ok'=>true,'year'=>$year,'count'=>count($races),'races'=>$races]);
