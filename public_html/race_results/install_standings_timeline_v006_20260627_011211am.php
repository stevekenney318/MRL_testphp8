<?php

declare(strict_types=1);

/**
 * install_standings_timeline_v006_20260627_011211am.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/27/2026 1:12:11 am
 *
 * CHANGELOG:
 * v001 (6/27/2026 1:12:11 am)
 *   - NEW: Installer for standings_timeline.php v006 visual polish pass.
 *   - NEW: Backs up existing standings_timeline.php before replacement.
 *
 * PHP: 7.3 compatible.
 */

$target = __DIR__ . '/standings_timeline.php';
$backup = __DIR__ . '/standings_timeline.php.bak_' . date('Ymd_His');
$report = [];
$ok = true;

$newContent = <<<'MRL_FILE'
<?php

declare(strict_types=1);

ob_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';

if (function_exists('disableCaching')) {
    disableCaching();
}

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$docRoot = strtolower($_SERVER['DOCUMENT_ROOT'] ?? '');
$isTestSite = (strpos($host, 'testphp8') !== false || strpos($docRoot, 'testphp8') !== false);
if ($isTestSite) {
    $sandboxFile = $_SERVER['DOCUMENT_ROOT'] . '/sandbox.html';
    if (is_file($sandboxFile)) {
        require_once $sandboxFile;
    }
}

/**
 * standings_timeline.php
 *
 * VERSION: v006
 * LAST MODIFIED: 6/27/2026 1:12:11 am
 *
 * CHANGELOG:
 *
 * v006 (6/27/2026 1:12:11 am)
 *   - CHANGE: Refined the audit-style page colors to a light blue background with stronger blue borders.
 *   - CHANGE: Tightened card/table padding and row spacing to better match weekly_standings.php.
 *   - CHANGE: Kept the simplified v005 controls while making the standings tables less card-like and more compact.
 *
 * v005 (6/27/2026 12:46:49 am)
 *   - CHANGE: Restyled the full timeline browser from the purple theme to a lighter blue/white audit-style color scheme.
 *   - CHANGE: Removed the duplicate as-of info pill row and the public demo limitation note.
 *   - CHANGE: Added the selected snapshot ID as a compact pill at the end of the main control row.
 *   - CHANGE: Shortened Weekly Winners race cells to show the race code only, improving fourth-column alignment.
 *
 * v004 (6/23/2026 1:38:33 am)
 *   - FIX: Release-history context now merges all available audit/release-history JSON files instead of stopping after the first file with matches.
 *   - FIX: Added extra field-name fallbacks for MRL Impact and Change labels so MRL-impacting entries such as R04 Phoenix v2 can label correctly in the snapshot dropdown.
 *
 * v004 (6/23/2026 1:28:25 am)
 *   - FIX: Snapshot dropdown change labels now read release-history data from _weekly_standings_release_history.json in addition to audit-index data.
 *   - FIX: Updated impact label lookup to prefer change_status_label/public release-history fields so v2+ entries show labels like MRL Impact - Revision Required and Non-MRL Driver Change.
 *
 * v004 (6/23/2026 1:24:06 am)
 *   - CHANGE: Removed the technical snapshot ID from the as-of snapshot dropdown labels because it already appears in the As-of snapshot pill.
 *   - CHANGE: v1 snapshot dropdown entries now stay plain while v2+ entries show the human-readable change/impact label when audit context is available.
 *   - CHANGE: Shortened the snapshot dropdown width now that labels are more human-readable.
 *   - CHANGE: Updated the demo limitation wording to reflect current official segment-pick sourcing rather than user_picks_history.
 *
 * v004 (6/23/2026 12:49:58 am)
 *   - CHANGE: Added vertical ▲ / ▼ snapshot navigation controls between the snapshot dropdown and race dropdown for easier before/after comparison.
 *   - CHANGE: Race page availability is now based on the selected as-of timestamp, not the selected snapshot's race number.
 *   - CHANGE: Selecting a later revision for an earlier race can now still browse later races if those race snapshots already existed at that time.
 *   - NEW: Added UI-only race version labels such as v1, v2, and v3 based on each race's snapshot/release order.
 *   - NEW: Added optional release-history context to snapshot labels when _weekly_standings_audit_index.json is available.
 *
 * v003 (6/22/2026 11:46:35 pm)
 *   - CHANGE: Removed the redundant Show Timeline submit button because year/snapshot/race controls already submit on change.
 *   - CHANGE: When a new as-of snapshot is selected, the race browser now automatically jumps to that snapshot's race.
 *   - CHANGE: Rearranged race navigation so the << and >> buttons sit next to each other after the race dropdown, matching weekly_standings.php more closely.
 *
 * v002 (6/22/2026 11:21:30 pm)
 *   - CHANGE: Converted the first-pass dashboard into a weekly_standings-like historical browser.
 *   - NEW: Added Race dropdown and << / >> navigation limited to races available at the selected as-of snapshot time.
 *   - NEW: Selected snapshot now acts as a time filter for the whole page; each race page uses only snapshots available at or before that selected time.
 *   - NEW: Four-column report now shows selected week, selected segment, season through selected race, and weekly winners through selected race.
 *   - NEW: Snapshot set used is shown below the report as supporting timeline evidence.
 *   - NOTE: Demo intentionally avoids weekly_standings.php audit/release/validation controls.
 *   - NOTE: Demo uses current DB pick rows plus effective_race LP/RD overlays; full user_picks_history as-of reconstruction is future work.
 *
 * v001 (6/22/2026)
 *   - NEW: First-pass demo page for historical/as-of standings reconstruction from race-result snapshots.
 *   - NEW: Builds an on-the-fly snapshot dropdown from race_results/<year>/<race-folder>/snapshot_*.html files.
 *   - NEW: Selecting a snapshot reconstructs standings using the latest available snapshot for each race at or before that timestamp.
 *   - NEW: Shows four simple panels: selected week, segment totals, season totals, and the snapshot set used.
 *   - NOTE: Demo intentionally avoids weekly_standings.php audit/release/validation controls.
 *   - NOTE: Demo uses current DB pick rows plus effective_race overlays; full user_picks_history as-of reconstruction is future work.
 *
 * PHP: 7.3 compatible.
 */

require_once __DIR__ . '/race_results_team_helper.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';
require_once __DIR__ . '/race_results_engine.php';

function st_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function st_valid_year($year): bool
{
    return preg_match('/^\d{4}$/', (string)$year) === 1;
}

function st_load_json(string $path): array
{
    if (function_exists('rr_load_json')) {
        $data = rr_load_json($path);
        return is_array($data) ? $data : [];
    }

    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function st_available_years(string $baseDir): array
{
    $years = [];
    $items = @scandir($baseDir);
    if (!is_array($items)) {
        return [];
    }

    foreach ($items as $name) {
        if (!preg_match('/^\d{4}$/', (string)$name)) {
            continue;
        }
        if (!is_file($baseDir . '/' . $name . '/_year_index.json')) {
            continue;
        }
        $years[] = (string)$name;
    }

    rsort($years, SORT_STRING);
    return $years;
}

function st_points_races_from_index(array $yearIndex, string $yearBaseFolder): array
{
    $rows = [];
    $races = isset($yearIndex['races']) && is_array($yearIndex['races']) ? $yearIndex['races'] : [];

    foreach ($races as $raceId => $row) {
        if (!is_array($row)) {
            continue;
        }

        $kind = (string)($row['kind'] ?? '');
        if ($kind !== 'R') {
            continue;
        }

        $number = (int)($row['number'] ?? 0);
        $folder = (string)($row['folder'] ?? '');
        if ($number <= 0 || $folder === '') {
            continue;
        }

        $rows[] = [
            'race_id' => (string)$raceId,
            'number' => $number,
            'race_code' => 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'race_name' => (string)($row['race_name'] ?? ''),
            'race_url' => (string)($row['race_url'] ?? ''),
            'folder' => $folder,
            'race_folder' => $yearBaseFolder . '/' . $folder,
        ];
    }

    usort($rows, function ($a, $b): int {
        return ((int)$a['number']) <=> ((int)$b['number']);
    });

    return $rows;
}

function st_segment_from_race_number(int $raceNumber): string
{
    if ($raceNumber >= 1 && $raceNumber <= 8) return 'S1';
    if ($raceNumber >= 9 && $raceNumber <= 17) return 'S2';
    if ($raceNumber >= 18 && $raceNumber <= 26) return 'S3';
    if ($raceNumber >= 27 && $raceNumber <= 36) return 'S4';
    return 'S1';
}

function st_segment_label(string $segment, string $year): string
{
    if (function_exists('rr_segment_label')) {
        return rr_segment_label($segment, $year);
    }
    if ($segment === 'S1') return 'Segment #1';
    if ($segment === 'S2') return 'Segment #2';
    if ($segment === 'S3') return 'Segment #3';
    if ($segment === 'S4') return ((int)$year >= 2026) ? 'The Chase' : 'Playoffs';
    return $segment;
}

function st_short_race_label(string $raceName): string
{
    $raceName = trim($raceName);
    if ($raceName === '') {
        return '';
    }

    $name = preg_replace('/^NASCAR\s+Cup\s+Series\s+at\s+/i', '', $raceName);
    $name = preg_replace('/^NASCAR\s+Cup\s+Series\s+/i', '', (string)$name);
    $name = trim((string)$name);

    $map = [
        'World Wide Technology Raceway' => 'World Wide Tech',
        'Circuit of the Americas' => 'COTA',
        'Indianapolis Road Course' => 'Indianapolis RC',
    ];

    return $map[$name] ?? $name;
}

function st_snapshot_key_from_file(string $path): string
{
    $base = basename($path);
    if (!preg_match('/^snapshot_(\d{8}_\d{6}\d*)\.html$/', $base, $m)) {
        return '';
    }
    return (string)$m[1];
}

function st_snapshot_seconds_key(string $snapshotKey): string
{
    if (!preg_match('/^(\d{8})_(\d{6})/', $snapshotKey, $m)) {
        return '';
    }
    return $m[1] . '_' . $m[2];
}

function st_snapshot_display(string $snapshotKey): string
{
    if (!preg_match('/^(\d{8})_(\d{6})/', $snapshotKey, $m)) {
        return $snapshotKey;
    }

    $dt = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new DateTimeZone('America/New_York'));
    if (!$dt instanceof DateTime) {
        return $snapshotKey;
    }

    return $dt->format('n/j/Y g:i:s a');
}

function st_collect_snapshots(array $races): array
{
    $items = [];

    foreach ($races as $race) {
        $folder = (string)($race['race_folder'] ?? '');
        if ($folder === '' || !is_dir($folder)) {
            continue;
        }

        $files = glob(rtrim($folder, '/\\') . '/snapshot_*.html');
        if (!is_array($files)) {
            continue;
        }

        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $key = st_snapshot_key_from_file((string)$file);
            if ($key === '') {
                continue;
            }

            $raceCode = (string)($race['race_code'] ?? '');
            $value = $key . '_' . $raceCode;
            $items[] = [
                'value' => $value,
                'snapshot_key' => $key,
                'seconds_key' => st_snapshot_seconds_key($key),
                'race_code' => $raceCode,
                'race_number' => (int)($race['number'] ?? 0),
                'race_label' => st_short_race_label((string)($race['race_name'] ?? '')),
                'file' => (string)$file,
                'display_time' => st_snapshot_display($key),
            ];
        }
    }

    usort($items, function ($a, $b): int {
        $cmp = strcmp((string)$b['snapshot_key'], (string)$a['snapshot_key']);
        if ($cmp !== 0) return $cmp;
        return ((int)$b['race_number']) <=> ((int)$a['race_number']);
    });

    return $items;
}


function st_recursive_audit_records(array $node): array
{
    $records = [];

    $hasReleaseId = false;
    foreach (['release_id', 'releaseID', 'releaseId', 'id'] as $key) {
        if (isset($node[$key]) && is_scalar($node[$key])) {
            $value = (string)$node[$key];
            if (preg_match('/^\d{8}_\d{6}\d*_R\d{2}$/', $value) === 1) {
                $hasReleaseId = true;
                break;
            }
        }
    }

    if ($hasReleaseId) {
        $records[] = $node;
    }

    foreach ($node as $child) {
        if (is_array($child)) {
            $records = array_merge($records, st_recursive_audit_records($child));
        }
    }

    return $records;
}

function st_audit_context_by_release_id(string $yearFolder): array
{
    $paths = [
        rtrim($yearFolder, '/\\') . '/_weekly_standings_release_history.json',
        rtrim($yearFolder, '/\\') . '/weekly_standings_release_history.json',
        rtrim($yearFolder, '/\\') . '/_weekly_standings_audit_index.json',
        rtrim($yearFolder, '/\\') . '/weekly_standings_audit_index.json',
        dirname(rtrim($yearFolder, '/\\')) . '/_weekly_standings_audit_index.json',
    ];

    $byId = [];

    foreach ($paths as $path) {
        $data = st_load_json($path);
        if (empty($data)) {
            continue;
        }

        $records = st_recursive_audit_records($data);
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $releaseId = '';
            foreach (['release_id', 'releaseID', 'releaseId', 'id'] as $key) {
                $candidate = isset($record[$key]) && is_scalar($record[$key]) ? (string)$record[$key] : '';
                if (preg_match('/^\d{8}_\d{6}\d*_R\d{2}$/', $candidate) === 1) {
                    $releaseId = $candidate;
                    break;
                }
            }

            if ($releaseId === '') {
                continue;
            }

            $context = [
                'release' => (string)($record['release'] ?? $record['release_type'] ?? $record['releaseType'] ?? $record['type'] ?? ''),
                'status' => (string)($record['public_status'] ?? $record['publicStatus'] ?? $record['status'] ?? ''),
                'mrl_impact' => (string)(
                    $record['mrl_impact']
                    ?? $record['mrlImpact']
                    ?? $record['MRL Impact']
                    ?? $record['mrl impact']
                    ?? $record['impact']
                    ?? ''
                ),
                'change' => (string)(
                    $record['change_status_label']
                    ?? $record['changeStatusLabel']
                    ?? $record['change_label']
                    ?? $record['changeLabel']
                    ?? $record['Change']
                    ?? $record['change']
                    ?? $record['change_summary']
                    ?? $record['changeSummary']
                    ?? $record['reason_public']
                    ?? $record['reasonPublic']
                    ?? $record['reason']
                    ?? ''
                ),
            ];

            if (!isset($byId[$releaseId])) {
                $byId[$releaseId] = $context;
                continue;
            }

            foreach ($context as $field => $value) {
                $value = trim((string)$value);
                $existing = trim((string)($byId[$releaseId][$field] ?? ''));
                if ($existing === '' && $value !== '') {
                    $byId[$releaseId][$field] = $value;
                }
            }
        }
    }

    return $byId;
}

function st_normalize_audit_impact(string $impact): string
{
    $value = strtoupper(trim($impact));
    if ($value === '' || $value === '—' || $value === '-') {
        return '';
    }
    if (in_array($value, ['YES', 'Y', 'TRUE', '1'], true)) {
        return 'MRL YES';
    }
    if (in_array($value, ['NO', 'N', 'FALSE', '0'], true)) {
        return 'MRL NO';
    }
    if (strpos($value, 'YES') !== false) {
        return 'MRL YES';
    }
    if (strpos($value, 'NO') !== false) {
        return 'MRL NO';
    }
    return trim($impact);
}

function st_enrich_snapshot_items(array $items, array $auditContextByReleaseId): array
{
    $ascending = $items;
    usort($ascending, function ($a, $b): int {
        $cmp = ((int)($a['race_number'] ?? 0)) <=> ((int)($b['race_number'] ?? 0));
        if ($cmp !== 0) return $cmp;
        return strcmp((string)($a['snapshot_key'] ?? ''), (string)($b['snapshot_key'] ?? ''));
    });

    $versionByValue = [];
    $countsByRace = [];

    foreach ($ascending as $item) {
        $raceCode = (string)($item['race_code'] ?? '');
        $value = (string)($item['value'] ?? '');
        if ($raceCode === '' || $value === '') {
            continue;
        }
        if (!isset($countsByRace[$raceCode])) {
            $countsByRace[$raceCode] = 0;
        }
        $countsByRace[$raceCode]++;
        $versionByValue[$value] = $countsByRace[$raceCode];
    }

    foreach ($items as $idx => $item) {
        $value = (string)($item['value'] ?? '');
        $versionNumber = (int)($versionByValue[$value] ?? 0);
        $items[$idx]['version_number'] = $versionNumber;
        $items[$idx]['version_label'] = $versionNumber > 0 ? ('v' . $versionNumber) : '';
        $items[$idx]['audit'] = isset($auditContextByReleaseId[$value]) && is_array($auditContextByReleaseId[$value]) ? $auditContextByReleaseId[$value] : [];
    }

    return $items;
}

function st_snapshot_change_label(array $item): string
{
    $versionNumber = (int)($item['version_number'] ?? 0);
    if ($versionNumber <= 1) {
        return '';
    }

    $audit = isset($item['audit']) && is_array($item['audit']) ? $item['audit'] : [];
    $change = trim((string)($audit['change'] ?? ''));
    $status = trim((string)($audit['status'] ?? ''));
    $release = trim((string)($audit['release'] ?? ''));
    $impactRaw = trim((string)($audit['mrl_impact'] ?? ''));
    $impact = st_normalize_audit_impact($impactRaw);

    $dashValues = ['-', '—', 'none', 'n/a', 'not applicable'];
    if ($change !== '' && !in_array(strtolower($change), $dashValues, true)) {
        if (stripos($change, 'MRL Impact') !== false && stripos($change, 'Revision Required') !== false) {
            return 'MRL Impact - Results Changed';
        }
        return $change;
    }

    if ($impact === 'MRL YES') {
        return 'MRL Impact - Results Changed';
    }

    if ($status !== '' && !in_array(strtolower($status), $dashValues, true)) {
        return $status;
    }

    if ($release !== '' && !in_array(strtolower($release), $dashValues, true)) {
        return $release;
    }

    if ($impact === 'MRL NO') {
        return 'No MRL Impact';
    }

    return '';
}

function st_snapshot_label(array $item): string
{
    $race = trim((string)($item['race_code'] ?? '') . ' ' . (string)($item['race_label'] ?? ''));
    $version = (string)($item['version_label'] ?? '');
    $main = $race;

    if ($version !== '') {
        $main .= ' ' . $version;
    }

    $parts = [trim($main), (string)($item['display_time'] ?? '')];
    $changeLabel = st_snapshot_change_label($item);
    if ($changeLabel !== '') {
        $parts[] = '[ ' . $changeLabel . ' ]';
    }

    return implode(' — ', array_filter($parts, function ($part): bool {
        return trim((string)$part) !== '';
    }));
}

function st_snapshot_item_by_key_race(array $items, string $snapshotKey, string $raceCode): array
{
    foreach ($items as $item) {
        if ((string)($item['snapshot_key'] ?? '') === $snapshotKey && (string)($item['race_code'] ?? '') === $raceCode) {
            return $item;
        }
    }
    return [];
}

function st_find_snapshot_item(array $items, string $value): array
{
    foreach ($items as $item) {
        if ((string)($item['value'] ?? '') === $value) {
            return $item;
        }
    }
    return !empty($items) ? $items[0] : [];
}

function st_latest_snapshot_for_race_asof(string $raceFolder, string $selectedSnapshotKey): string
{
    if ($raceFolder === '' || !is_dir($raceFolder)) {
        return '';
    }

    $files = glob(rtrim($raceFolder, '/\\') . '/snapshot_*.html');
    if (!is_array($files) || empty($files)) {
        return '';
    }

    sort($files, SORT_STRING);
    $best = '';

    foreach ($files as $file) {
        $key = st_snapshot_key_from_file((string)$file);
        if ($key === '') {
            continue;
        }
        if (strcmp($key, $selectedSnapshotKey) <= 0) {
            $best = (string)$file;
        }
    }

    return $best;
}

function st_driver_net(array $driverPoints, string $driverName): int
{
    if ($driverName === '' || !isset($driverPoints[$driverName]) || !is_array($driverPoints[$driverName])) {
        return 0;
    }
    return (int)($driverPoints[$driverName]['net'] ?? 0);
}

function st_build_weekly_rows(array $teamRows, array $driverPoints): array
{
    $rows = [];

    foreach ($teamRows as $team) {
        $driverA = (string)($team['driverA'] ?? '');
        $driverB = (string)($team['driverB'] ?? '');
        $driverC = (string)($team['driverC'] ?? '');
        $driverD = (string)($team['driverD'] ?? '');

        $netA = st_driver_net($driverPoints, $driverA);
        $netB = st_driver_net($driverPoints, $driverB);
        $netC = st_driver_net($driverPoints, $driverC);
        $netD = st_driver_net($driverPoints, $driverD);

        $rows[] = [
            'team_name' => (string)($team['teamName'] ?? ''),
            'user_name' => (string)($team['userName'] ?? ''),
            'driverA' => $driverA,
            'driverB' => $driverB,
            'driverC' => $driverC,
            'driverD' => $driverD,
            'weekly_total' => $netA + $netB + $netC + $netD,
            'pick_type' => (string)($team['pick_type'] ?? 'SEG'),
            'effective_race' => (int)($team['effective_race'] ?? 0),
        ];
    }

    usort($rows, function ($a, $b): int {
        $aTotal = (int)($a['weekly_total'] ?? 0);
        $bTotal = (int)($b['weekly_total'] ?? 0);
        if ($aTotal !== $bTotal) return $bTotal <=> $aTotal;
        return strcasecmp((string)($a['team_name'] ?? ''), (string)($b['team_name'] ?? ''));
    });

    return $rows;
}

function st_special_pick_rows(string $raceYear, string $segment, $dbo): array
{
    if (!($dbo instanceof PDO)) {
        return [];
    }

    try {
        $sql = "
            SELECT
                'current' AS src,
                up.userID,
                up.teamName,
                COALESCE(u.userName, '') AS userName,
                up.raceYear,
                up.segment,
                up.driverA,
                up.driverB,
                up.driverC,
                up.driverD,
                up.entryDate,
                up.submission_id,
                up.formID,
                up.pick_type,
                up.effective_race,
                up.supersedes_pickID,
                base.driverA AS original_driverA,
                base.driverB AS original_driverB,
                base.driverC AS original_driverC,
                base.driverD AS original_driverD
            FROM user_picks up
            LEFT JOIN users u ON u.userID = up.userID
            LEFT JOIN user_picks base ON base.pickID = up.supersedes_pickID
            WHERE up.raceYear = :raceYear
              AND up.segment = :segment
              AND up.pick_type IN ('LP', 'RD')
            ORDER BY up.teamName ASC, up.effective_race ASC, up.entryDate ASC, up.pickID ASC
        ";

        $stmt = $dbo->prepare($sql);
        $stmt->execute([
            ':raceYear' => $raceYear,
            ':segment' => $segment,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function st_overlay_special_rows_for_race(array $baseTeamRows, array $specialRows, int $raceNumber, string $segment): array
{
    $rowsByTeam = [];

    foreach ($baseTeamRows as $row) {
        if (!is_array($row)) continue;
        $teamName = (string)($row['teamName'] ?? '');
        if ($teamName === '') continue;
        $row['pick_type'] = (string)($row['pick_type'] ?? 'SEG');
        $row['effective_race'] = (int)($row['effective_race'] ?? 0);
        $rowsByTeam[$teamName] = $row;
    }

    $specialByTeam = [];
    foreach ($specialRows as $row) {
        if (!is_array($row)) continue;
        $teamName = (string)($row['teamName'] ?? '');
        if ($teamName === '') continue;
        if (!isset($specialByTeam[$teamName])) {
            $specialByTeam[$teamName] = [];
        }
        $specialByTeam[$teamName][] = $row;
    }

    foreach ($specialByTeam as $teamName => $teamRows) {
        usort($teamRows, function ($a, $b): int {
            $aRace = (int)($a['effective_race'] ?? 0);
            $bRace = (int)($b['effective_race'] ?? 0);
            if ($aRace !== $bRace) return $aRace <=> $bRace;
            return strcmp((string)($a['entryDate'] ?? ''), (string)($b['entryDate'] ?? ''));
        });

        $applicable = null;
        $firstSpecial = $teamRows[0];

        foreach ($teamRows as $row) {
            $effectiveRace = (int)($row['effective_race'] ?? 0);
            if ($effectiveRace > 0 && $effectiveRace <= $raceNumber) {
                $applicable = $row;
            }
        }

        if ($applicable !== null) {
            $rowsByTeam[$teamName] = [
                'teamName' => $teamName,
                'userName' => (string)($applicable['userName'] ?? ($rowsByTeam[$teamName]['userName'] ?? '')),
                'driverA' => (string)($applicable['driverA'] ?? ''),
                'driverB' => (string)($applicable['driverB'] ?? ''),
                'driverC' => (string)($applicable['driverC'] ?? ''),
                'driverD' => (string)($applicable['driverD'] ?? ''),
                'pick_type' => (string)($applicable['pick_type'] ?? ''),
                'effective_race' => (int)($applicable['effective_race'] ?? 0),
            ];
            continue;
        }

        $firstEffectiveRace = (int)($firstSpecial['effective_race'] ?? 0);
        $firstPickType = strtoupper((string)($firstSpecial['pick_type'] ?? ''));

        if ($firstPickType === 'LP' && $firstEffectiveRace > $raceNumber) {
            $rowsByTeam[$teamName] = [
                'teamName' => $teamName,
                'userName' => (string)($firstSpecial['userName'] ?? ($rowsByTeam[$teamName]['userName'] ?? '')),
                'driverA' => '',
                'driverB' => '',
                'driverC' => '',
                'driverD' => '',
                'pick_type' => 'LP',
                'effective_race' => $firstEffectiveRace,
            ];
        }
    }

    $rows = array_values($rowsByTeam);
    usort($rows, function ($a, $b): int {
        return strcasecmp((string)($a['teamName'] ?? ''), (string)($b['teamName'] ?? ''));
    });

    return $rows;
}

function st_rank_rows(array $totals, string $nameKey, string $scoreKey): array
{
    usort($totals, function ($a, $b) use ($nameKey, $scoreKey): int {
        $aScore = (int)($a[$scoreKey] ?? 0);
        $bScore = (int)($b[$scoreKey] ?? 0);
        if ($aScore !== $bScore) return $bScore <=> $aScore;
        return strcasecmp((string)($a[$nameKey] ?? ''), (string)($b[$nameKey] ?? ''));
    });

    $ranked = [];
    $rank = 1;
    $displayRank = 0;
    $previousScore = null;

    foreach ($totals as $row) {
        $score = (int)($row[$scoreKey] ?? 0);
        if ($previousScore === null || $score !== $previousScore) {
            $displayRank = $rank;
            $previousScore = $score;
        }
        $row['rank'] = $displayRank;
        $ranked[] = $row;
        $rank++;
    }

    return $ranked;
}

function st_total_rows(array $totals): array
{
    $rows = [];
    foreach ($totals as $teamName => $total) {
        $rows[] = [
            'team_name' => (string)$teamName,
            'total' => (int)$total,
        ];
    }
    return st_rank_rows($rows, 'team_name', 'total');
}

function st_render_score_table(array $rows, string $scoreKey, string $scoreLabel): void
{
    echo '<table class="st-table">';
    echo '<thead><tr><th class="st-rank">#</th><th>Team</th><th class="st-score">' . st_h($scoreLabel) . '</th></tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="3" class="st-empty">No rows available.</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td class="st-rank">' . st_h((string)($row['rank'] ?? '')) . '</td>';
            echo '<td>' . st_h((string)($row['team_name'] ?? '')) . '</td>';
            echo '<td class="st-score">' . st_h((string)(int)($row[$scoreKey] ?? 0)) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
}


function st_url(array $params): string
{
    $base = strtok((string)($_SERVER['REQUEST_URI'] ?? 'standings_timeline.php'), '?');
    $query = array_merge($_GET, $params);

    foreach ($query as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        }
    }

    return $base . (empty($query) ? '' : '?' . http_build_query($query));
}

function st_find_race_by_code(array $races, string $raceCode): array
{
    foreach ($races as $race) {
        if ((string)($race['race_code'] ?? '') === $raceCode) {
            return $race;
        }
    }
    return [];
}

function st_weekly_winner_rows(array $winnersByRace): array
{
    $rows = [];

    foreach ($winnersByRace as $raceNumber => $winnerData) {
        if (!is_array($winnerData)) {
            continue;
        }
        $raceCode = (string)($winnerData['race_code'] ?? ('R' . str_pad((string)$raceNumber, 2, '0', STR_PAD_LEFT)));
        $raceLabel = (string)($winnerData['race_label'] ?? '');
        $winners = isset($winnerData['winners']) && is_array($winnerData['winners']) ? $winnerData['winners'] : [];
        $points = (int)($winnerData['points'] ?? 0);

        foreach ($winners as $teamName) {
            $rows[] = [
                'race_code' => $raceCode,
                'race_label' => $raceLabel,
                'team_name' => (string)$teamName,
                'points' => $points,
            ];
        }
    }

    return $rows;
}

function st_render_weekly_winners_table(array $rows): void
{
    echo '<table class="st-table winners-table">';
    echo '<thead><tr><th class="st-racecol">Race</th><th>Winner</th><th class="st-score">Pts</th></tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="3" class="st-empty">No weekly winners available.</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td class="st-racecol"><strong>' . st_h((string)($row['race_code'] ?? '')) . '</strong></td>';
            echo '<td>' . st_h((string)($row['team_name'] ?? '')) . '</td>';
            echo '<td class="st-score">' . st_h((string)(int)($row['points'] ?? 0)) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
}

function st_render_snapshot_set_table(array $snapshotSetRows): void
{
    echo '<table class="snapshot-table">';
    echo '<thead><tr><th>Race</th><th>Version</th><th>Snapshot used for this timeline page</th><th>Drivers</th></tr></thead><tbody>';

    if (empty($snapshotSetRows)) {
        echo '<tr><td colspan="4" class="st-empty">No snapshot set available.</td></tr>';
    } else {
        foreach ($snapshotSetRows as $row) {
            echo '<tr>';
            echo '<td><strong>' . st_h((string)($row['race_code'] ?? '')) . '</strong><br><span class="subtle">' . st_h((string)($row['race_label'] ?? '')) . '</span></td>';
            echo '<td><span class="version-tag">' . st_h((string)($row['version_label'] ?? '')) . '</span></td>';
            echo '<td><span class="mono">' . st_h((string)($row['snapshot_key'] ?? '')) . '</span><br><span class="subtle">' . st_h((string)($row['snapshot_display'] ?? '')) . '</span></td>';
            echo '<td class="st-score">' . st_h((string)(int)($row['drivers'] ?? 0)) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
}

$baseDir = __DIR__;
$availableYears = st_available_years($baseDir);
$selectedYear = isset($_GET['year']) ? trim((string)$_GET['year']) : '';
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = !empty($availableYears) ? (string)$availableYears[0] : date('Y');
}

$yearFolder = $baseDir . '/' . $selectedYear;
$yearIndex = st_load_json($yearFolder . '/_year_index.json');
$pointRaces = st_points_races_from_index($yearIndex, $yearFolder);
$auditContextByReleaseId = st_audit_context_by_release_id($yearFolder);
$snapshotItems = st_enrich_snapshot_items(st_collect_snapshots($pointRaces), $auditContextByReleaseId);

$selectedSnapshotValue = isset($_GET['snapshot']) ? trim((string)$_GET['snapshot']) : '';
$selectedSnapshot = st_find_snapshot_item($snapshotItems, $selectedSnapshotValue);
$selectedSnapshotValue = (string)($selectedSnapshot['value'] ?? '');
$selectedSnapshotKey = (string)($selectedSnapshot['snapshot_key'] ?? '');
$asOfRaceNumber = (int)($selectedSnapshot['race_number'] ?? 0);
$asOfRaceCode = (string)($selectedSnapshot['race_code'] ?? '');
$asOfRaceLabel = (string)($selectedSnapshot['race_label'] ?? '');
$selectedSnapshotDisplay = st_snapshot_display($selectedSnapshotKey);

$previousSnapshotValue = '';
$previousSnapshotRaceCode = '';
$nextSnapshotValue = '';
$nextSnapshotRaceCode = '';
for ($i = 0; $i < count($snapshotItems); $i++) {
    if ((string)($snapshotItems[$i]['value'] ?? '') !== $selectedSnapshotValue) {
        continue;
    }
    if ($i > 0) {
        $previousSnapshotValue = (string)($snapshotItems[$i - 1]['value'] ?? '');
        $previousSnapshotRaceCode = (string)($snapshotItems[$i - 1]['race_code'] ?? '');
    }
    if ($i < count($snapshotItems) - 1) {
        $nextSnapshotValue = (string)($snapshotItems[$i + 1]['value'] ?? '');
        $nextSnapshotRaceCode = (string)($snapshotItems[$i + 1]['race_code'] ?? '');
    }
    break;
}

$availableTimelineRaces = [];
$snapshotByRaceNumber = [];

foreach ($pointRaces as $race) {
    $raceNumber = (int)($race['number'] ?? 0);
    if ($selectedSnapshotKey === '' || $raceNumber <= 0) {
        continue;
    }

    $snapshotFile = st_latest_snapshot_for_race_asof((string)($race['race_folder'] ?? ''), $selectedSnapshotKey);
    if ($snapshotFile === '') {
        continue;
    }

    $snapshotByRaceNumber[$raceNumber] = $snapshotFile;
    $availableTimelineRaces[] = $race;
}

usort($availableTimelineRaces, function ($a, $b): int {
    return ((int)($a['number'] ?? 0)) <=> ((int)($b['number'] ?? 0));
});

$requestedRaceCode = isset($_GET['race']) ? trim((string)$_GET['race']) : '';
$selectedViewRace = st_find_race_by_code($availableTimelineRaces, $requestedRaceCode);
if (empty($selectedViewRace)) {
    $selectedViewRace = st_find_race_by_code($availableTimelineRaces, $asOfRaceCode);
}
if (empty($selectedViewRace)) {
    $selectedViewRace = !empty($availableTimelineRaces) ? $availableTimelineRaces[count($availableTimelineRaces) - 1] : [];
}

$selectedViewRaceNumber = (int)($selectedViewRace['number'] ?? 0);
$selectedViewRaceCode = (string)($selectedViewRace['race_code'] ?? '');
$selectedViewRaceLabel = st_short_race_label((string)($selectedViewRace['race_name'] ?? ''));
$selectedSegment = st_segment_from_race_number($selectedViewRaceNumber);

$previousRaceCode = '';
$nextRaceCode = '';
for ($i = 0; $i < count($availableTimelineRaces); $i++) {
    if ((string)($availableTimelineRaces[$i]['race_code'] ?? '') !== $selectedViewRaceCode) {
        continue;
    }
    if ($i > 0) {
        $previousRaceCode = (string)($availableTimelineRaces[$i - 1]['race_code'] ?? '');
    }
    if ($i < count($availableTimelineRaces) - 1) {
        $nextRaceCode = (string)($availableTimelineRaces[$i + 1]['race_code'] ?? '');
    }
    break;
}

$segmentTotals = [];
$seasonTotals = [];
$selectedWeeklyRows = [];
$snapshotSetRows = [];
$weeklyWinnersByRace = [];

$baseRowsBySegment = [];
$specialRowsBySegment = [];

foreach (['S1', 'S2', 'S3', 'S4'] as $segment) {
    $baseRowsBySegment[$segment] = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $selectedYear, $segment);
    $specialRowsBySegment[$segment] = st_special_pick_rows($selectedYear, $segment, $dbo ?? null);
}

foreach ($availableTimelineRaces as $race) {
    $raceNumber = (int)($race['number'] ?? 0);
    if ($selectedViewRaceNumber <= 0 || $raceNumber <= 0 || $raceNumber > $selectedViewRaceNumber) {
        continue;
    }

    $snapshotFile = (string)($snapshotByRaceNumber[$raceNumber] ?? '');
    if ($snapshotFile === '') {
        continue;
    }

    $raceSegment = st_segment_from_race_number($raceNumber);
    $teamRows = st_overlay_special_rows_for_race(
        $baseRowsBySegment[$raceSegment] ?? [],
        $specialRowsBySegment[$raceSegment] ?? [],
        $raceNumber,
        $raceSegment
    );

    $driverPoints = rrs_load_snapshot_driver_points($snapshotFile);
    $weeklyRows = st_build_weekly_rows($teamRows, $driverPoints);
    $weeklyRankedRows = st_rank_rows($weeklyRows, 'team_name', 'weekly_total');

    if (!empty($weeklyRankedRows)) {
        $topPoints = (int)($weeklyRankedRows[0]['weekly_total'] ?? 0);
        $winners = [];
        foreach ($weeklyRankedRows as $row) {
            if ((int)($row['weekly_total'] ?? 0) !== $topPoints) {
                break;
            }
            $teamName = (string)($row['team_name'] ?? '');
            if ($teamName !== '') {
                $winners[] = $teamName;
            }
        }
        $weeklyWinnersByRace[$raceNumber] = [
            'race_code' => (string)($race['race_code'] ?? ''),
            'race_label' => st_short_race_label((string)($race['race_name'] ?? '')),
            'points' => $topPoints,
            'winners' => $winners,
        ];
    }

    foreach ($weeklyRows as $row) {
        $teamName = (string)($row['team_name'] ?? '');
        if ($teamName === '') {
            continue;
        }
        if (!isset($seasonTotals[$teamName])) {
            $seasonTotals[$teamName] = 0;
        }
        $seasonTotals[$teamName] += (int)($row['weekly_total'] ?? 0);

        if ($raceSegment === $selectedSegment) {
            if (!isset($segmentTotals[$teamName])) {
                $segmentTotals[$teamName] = 0;
            }
            $segmentTotals[$teamName] += (int)($row['weekly_total'] ?? 0);
        }
    }

    $snapshotKeyUsed = st_snapshot_key_from_file($snapshotFile);
    $snapshotRaceCodeUsed = (string)($race['race_code'] ?? '');
    $snapshotItemUsed = st_snapshot_item_by_key_race($snapshotItems, $snapshotKeyUsed, $snapshotRaceCodeUsed);

    $snapshotSetRows[] = [
        'race_code' => $snapshotRaceCodeUsed,
        'race_label' => st_short_race_label((string)($race['race_name'] ?? '')),
        'version_label' => (string)($snapshotItemUsed['version_label'] ?? ''),
        'snapshot_key' => $snapshotKeyUsed,
        'snapshot_display' => st_snapshot_display($snapshotKeyUsed),
        'snapshot_file' => basename($snapshotFile),
        'drivers' => count($driverPoints),
    ];

    if ((string)($race['race_code'] ?? '') === $selectedViewRaceCode) {
        $selectedWeeklyRows = $weeklyRankedRows;
    }
}

$segmentRows = st_total_rows($segmentTotals);
$seasonRows = st_total_rows($seasonTotals);
$weeklyWinnerRows = st_weekly_winner_rows($weeklyWinnersByRace);
$timelineSummary = 'Viewing ' . trim($selectedViewRaceCode . ' ' . $selectedViewRaceLabel) . ' as it would have appeared using data available as of ' . $selectedSnapshotDisplay . '.';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Standings Timeline</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root {
        --bg: #eaf6ff;
        --panel: #f8fcff;
        --panel2: #eef8ff;
        --line: #62aef5;
        --line-dark: #1f78c8;
        --line-soft: #9fcef8;
        --text: #050f1a;
        --muted: #526b80;
        --accent: #004c9d;
        --accent2: #005fb8;
        --good: #177245;
        --warn: #8a6200;
        --shadow: 0 2px 7px rgba(0, 76, 157, 0.16);
        --sans: Arial, Helvetica, sans-serif;
        --mono: Consolas, Monaco, "Courier New", monospace;
    }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 8px; background: var(--bg); color: var(--text); font-family: var(--sans); font-size: 16px; }
    .wrap { max-width: 1860px; margin: 0 auto; }
    .hero { border: 2px solid var(--line); border-radius: 16px; padding: 12px 16px; background: #f8fcff; box-shadow: var(--shadow); margin-bottom: 12px; }
    h1 { margin: 0 0 3px 0; color: var(--accent); font-size: 36px; line-height: 1.08; font-weight: 900; }
    h2 { margin: 0 0 8px 0; color: var(--accent); font-size: 26px; line-height: 1.1; font-weight: 900; }
    .sub, .subtle { color: #3e5368; line-height: 1.3; }
    .controls { display: flex; flex-wrap: wrap; gap: 9px; align-items: center; margin-top: 14px; }
    select, .button, .navbtn { border: 2px solid var(--line); border-radius: 999px; background: #eef8ff; color: var(--accent); padding: 7px 12px; font-size: 16px; min-height: 34px; font-weight: 800; }
    select { font-weight: 800; color: #050f1a; background: #ffffff; }
    select.snapshot-select { min-width: min(620px, 100%); max-width: 100%; }
    .race-select { min-width: 190px; }
    .button, .navbtn { display: inline-flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; min-width: 54px; }
    .button:hover, .navbtn:hover { background: #d9efff; text-decoration: none; }
    .navbtn.disabled { opacity: 0.35; cursor: default; pointer-events: none; background: #f5faff; color: #8aa9c9; }
    .snapshot-stepper { display: inline-flex; flex-direction: column; gap: 3px; align-items: stretch; justify-content: center; }
    .snapshot-stepper .snapbtn { min-width: 42px; min-height: 16px; height: 16px; padding: 0 10px; border-radius: 999px; font-size: 12px; line-height: 1; }
    .timeline-mode { display: inline-block; margin-left: 8px; color: var(--accent); background: #d9efff; border: 2px solid var(--line); border-radius: 999px; padding: 3px 10px; font-size: 14px; font-weight: 900; vertical-align: middle; }
    .version-tag { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; border-radius: 999px; padding: 3px 8px; background: #d9efff; border: 1px solid var(--line); color: var(--accent); font-weight: 900; font-size: 12px; }
    .snapshot-id-pill { display: inline-flex; align-items: center; gap: 6px; border: 2px solid var(--line); border-radius: 999px; padding: 7px 12px; background: #eef8ff; color: #050f1a; line-height: 1.15; white-space: nowrap; }
    .snapshot-id-pill strong { color: var(--accent); }
    .pill-row { display: none; }
    .pill { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--line); border-radius: 999px; padding: 7px 11px; background: #eef8ff; color: var(--text); line-height: 1.15; }
    .pill strong { color: var(--accent2); }
    .note { display: none; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 12px; }
    @media (min-width: 1150px) { .grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    .card, .wide-card { border: 2px solid var(--line-soft); border-radius: 16px; padding: 10px 16px 14px 16px; background: #f8fcff; box-shadow: var(--shadow); overflow: hidden; }
    .wide-card { margin-top: 12px; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; background: #ffffff; border: 2px solid #111; }
    th, td { padding: 4px 8px; border: 1px solid #111; text-align: left; line-height: 1.15; font-size: 18px; }
    th { color: #000; background: #ffff00; font-size: 18px; font-weight: 900; }
    tr:nth-child(even) td { background: #d6e9f9; }
    tr:nth-child(odd) td { background: #ffffff; }
    .st-rank { width: 48px; text-align: center; color: #000; }
    .st-score { width: 70px; text-align: right; font-weight: 800; }
    .st-racecol { width: 68px; white-space: nowrap; }
    .st-empty { color: var(--muted); font-style: italic; text-align: center; }
    .snapshot-table td, .snapshot-table th { font-size: 14px; }
    .mono { font-family: var(--mono); }
    .footer { color: var(--muted); font-size: 12px; margin-top: 12px; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <h1>Standings Timeline <span class="timeline-mode">as-of browser</span></h1>
        <div class="sub">Historical view. Pick an as-of snapshot, then browse each race page using only race-result snapshots that existed at or before that selected moment.</div>
        <form method="get" class="controls" id="timelineControls">
            <select name="year" onchange="this.form.submit();" aria-label="Year">
                <?php foreach ($availableYears as $yearOption): ?>
                    <option value="<?php echo st_h($yearOption); ?>" <?php echo ($selectedYear === $yearOption ? 'selected' : ''); ?>><?php echo st_h($yearOption); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="snapshot" class="snapshot-select" onchange="stSnapshotChanged(this);" aria-label="As-of snapshot">
                <?php if (empty($snapshotItems)): ?>
                    <option value="">No snapshots found</option>
                <?php else: ?>
                    <?php foreach ($snapshotItems as $item): ?>
                        <?php $label = st_snapshot_label($item); ?>
                        <option value="<?php echo st_h((string)$item['value']); ?>" data-race="<?php echo st_h((string)$item['race_code']); ?>" <?php echo ((string)$item['value'] === $selectedSnapshotValue ? 'selected' : ''); ?>><?php echo st_h($label); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>

            <div class="snapshot-stepper" aria-label="Snapshot navigation">
                <a class="navbtn snapbtn <?php echo ($previousSnapshotValue === '' ? 'disabled' : ''); ?>" title="Newer as-of snapshot" href="<?php echo st_h($previousSnapshotValue !== '' ? st_url(['snapshot' => $previousSnapshotValue, 'race' => $previousSnapshotRaceCode]) : '#'); ?>">▲</a>
                <a class="navbtn snapbtn <?php echo ($nextSnapshotValue === '' ? 'disabled' : ''); ?>" title="Older as-of snapshot" href="<?php echo st_h($nextSnapshotValue !== '' ? st_url(['snapshot' => $nextSnapshotValue, 'race' => $nextSnapshotRaceCode]) : '#'); ?>">▼</a>
            </div>

            <select name="race" class="race-select" id="timelineRaceSelect" onchange="this.form.submit();" aria-label="Race page">
                <?php if (empty($availableTimelineRaces)): ?>
                    <option value="">No races available</option>
                <?php else: ?>
                    <?php foreach ($availableTimelineRaces as $raceOption): ?>
                        <?php
                        $raceCodeOpt = (string)($raceOption['race_code'] ?? '');
                        $raceLabelOpt = trim($raceCodeOpt . ' ' . st_short_race_label((string)($raceOption['race_name'] ?? '')));
                        ?>
                        <option value="<?php echo st_h($raceCodeOpt); ?>" <?php echo ($raceCodeOpt === $selectedViewRaceCode ? 'selected' : ''); ?>><?php echo st_h($raceLabelOpt); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <a class="navbtn <?php echo ($previousRaceCode === '' ? 'disabled' : ''); ?>" href="<?php echo st_h($previousRaceCode !== '' ? st_url(['race' => $previousRaceCode]) : '#'); ?>">&lt;&lt;</a>
            <a class="navbtn <?php echo ($nextRaceCode === '' ? 'disabled' : ''); ?>" href="<?php echo st_h($nextRaceCode !== '' ? st_url(['race' => $nextRaceCode]) : '#'); ?>">&gt;&gt;</a>
            <span class="snapshot-id-pill"><strong>Snapshot:</strong> <span class="mono"><?php echo st_h($selectedSnapshotValue !== '' ? $selectedSnapshotValue : 'none'); ?></span></span>
        </form>
    </div>


    <div class="grid">
        <div class="card">
            <h2><?php echo st_h($selectedViewRaceCode . ' Week'); ?></h2>
            <div class="table-wrap"><?php st_render_score_table($selectedWeeklyRows, 'weekly_total', 'Pts'); ?></div>
        </div>

        <div class="card">
            <h2><?php echo st_h(st_segment_label($selectedSegment, $selectedYear)); ?></h2>
            <div class="table-wrap"><?php st_render_score_table($segmentRows, 'total', 'Pts'); ?></div>
        </div>

        <div class="card">
            <h2><?php echo st_h($selectedYear); ?> Season</h2>
            <div class="table-wrap"><?php st_render_score_table($seasonRows, 'total', 'Pts'); ?></div>
        </div>

        <div class="card">
            <h2>Weekly Winners</h2>
            <div class="table-wrap"><?php st_render_weekly_winners_table($weeklyWinnerRows); ?></div>
        </div>
    </div>

    <div class="wide-card">
        <h2>Snapshot Set Used</h2>
        <div class="sub" style="margin-bottom:10px;">These are the exact race snapshots used to build the currently selected timeline page.</div>
        <div class="table-wrap"><?php st_render_snapshot_set_table($snapshotSetRows); ?></div>
    </div>

    <div class="footer">standings_timeline.php v006 · generated <?php echo st_h(date('n/j/Y g:i:s a')); ?></div>
</div>
<script>
function stSnapshotChanged(selectEl) {
    var selected = selectEl && selectEl.options ? selectEl.options[selectEl.selectedIndex] : null;
    var raceCode = selected ? selected.getAttribute('data-race') : '';
    var raceSelect = document.getElementById('timelineRaceSelect');

    if (raceCode && raceSelect) {
        var found = false;
        for (var i = 0; i < raceSelect.options.length; i++) {
            if (raceSelect.options[i].value === raceCode) {
                raceSelect.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) {
            raceSelect.value = raceCode;
        }
    }

    if (selectEl && selectEl.form) {
        selectEl.form.submit();
    }
}
</script>

</body>
</html>

MRL_FILE;

function installer_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (!is_file($target)) {
    $ok = false;
    $report[] = 'ERROR: standings_timeline.php was not found in this folder.';
} else {
    if (!@copy($target, $backup)) {
        $ok = false;
        $report[] = 'ERROR: Could not create backup: ' . basename($backup);
    } else {
        $report[] = 'Backup created: ' . basename($backup);
    }
}

if ($ok) {
    $bytes = @file_put_contents($target, $newContent, LOCK_EX);
    if ($bytes === false) {
        $ok = false;
        $report[] = 'ERROR: Could not write standings_timeline.php.';
    } else {
        $report[] = 'Updated: standings_timeline.php';
        $report[] = 'Installed standings_timeline.php v006 tighter weekly-standings-style visual polish.';
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Standings Timeline v006 Installer</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: Arial, Helvetica, sans-serif; margin: 24px; background: #f5f9ff; color: #111; }
.box { max-width: 900px; border: 2px solid <?php echo $ok ? '#4caf50' : '#b00020'; ?>; border-radius: 12px; padding: 18px; background: #fff; }
h1 { margin-top: 0; color: <?php echo $ok ? '#176b2c' : '#b00020'; ?>; }
pre { white-space: pre-wrap; background: #f3f6fa; border: 1px solid #d5dde8; border-radius: 8px; padding: 12px; }
</style>
</head>
<body>
<div class="box">
<h1>MRL Standings Timeline v006 Installer</h1>
<p><strong><?php echo $ok ? 'SUCCESS — standings_timeline.php v006 was installed.' : 'INSTALL FAILED — no changes completed.'; ?></strong></p>
<h2>Report</h2>
<pre><?php echo installer_h(implode("
", $report)); ?></pre>
<?php if ($ok): ?>
<p>After a successful install, open standings_timeline.php, then delete this installer.</p>
<?php endif; ?>
</div>
</body>
</html>
