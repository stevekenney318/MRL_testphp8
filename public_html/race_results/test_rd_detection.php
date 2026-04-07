<?php
declare(strict_types=1);

/**
 * test_rd_detection.php
 *
 * VERSION: v002
 * LAST MODIFIED: 4/3/2026 12:28:00 am
 *
 * DESCRIPTION:
 * Browser test page for race_results_rd_helper.php.
 * Loads one team + segment, builds race-by-race net points for each picked driver,
 * and reports RD eligibility status with friendlier handling for missing segment config.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';

// disableCaching() defined in functions_mrl.php
disableCaching();

// visual id of a sandbox file - SK & background
require_once $_SERVER['DOCUMENT_ROOT'] . '/sandbox.html';

require_once __DIR__ . '/race_results_rd_helper.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';

date_default_timezone_set('America/New_York');

function test_rd_find_snapshot_file(string $raceFolder): string
{
    if (!is_dir($raceFolder)) {
        return '';
    }

    $files = glob($raceFolder . '/snapshot_*.html');
    if (!is_array($files) || empty($files)) {
        return '';
    }

    sort($files, SORT_STRING);
    return (string)end($files);
}

function test_rd_load_year_index(string $yearFolder): array
{
    $path = $yearFolder . '/_year_index.json';
    $json = @file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['races']) || !is_array($data['races'])) {
        return [];
    }

    return $data['races'];
}

function test_rd_point_races_from_index(array $racesIndex, string $yearFolder): array
{
    $rows = [];

    foreach ($racesIndex as $raceId => $row) {
        if (!is_array($row)) {
            continue;
        }

        if ((string)($row['kind'] ?? '') !== 'R') {
            continue;
        }

        $number = (int)($row['number'] ?? 0);
        $folder = (string)($row['folder'] ?? '');
        $raceName = (string)($row['race_name'] ?? '');

        if ($number <= 0 || $folder === '') {
            continue;
        }

        $rows[$number] = [
            'raceId' => (string)$raceId,
            'number' => $number,
            'folder' => $folder,
            'raceName' => $raceName,
            'raceFolder' => $yearFolder . '/' . $folder,
        ];
    }

    ksort($rows, SORT_NUMERIC);
    return $rows;
}

function test_rd_build_race_driver_points(string $year, array $raceNumbers, string $yearFolder): array
{
    $raceDriverPoints = [];
    $yearIndex = test_rd_load_year_index($yearFolder);
    $pointRaces = test_rd_point_races_from_index($yearIndex, $yearFolder);

    foreach ($raceNumbers as $raceNumber) {
        $raceNumber = (int)$raceNumber;
        $raceDriverPoints[$raceNumber] = [];

        if (!isset($pointRaces[$raceNumber])) {
            continue;
        }

        $snapshotFile = test_rd_find_snapshot_file((string)$pointRaces[$raceNumber]['raceFolder']);
        if ($snapshotFile === '') {
            continue;
        }

        $driverRows = rrs_load_snapshot_driver_points($snapshotFile);
        if (!is_array($driverRows)) {
            continue;
        }

        foreach ($driverRows as $driverName => $driverData) {
            if (!is_array($driverData)) {
                continue;
            }

            $raceDriverPoints[$raceNumber][(string)$driverName] = (int)($driverData['net'] ?? 0);
        }
    }

    return $raceDriverPoints;
}

echo '<pre>';
echo 'RUN TS: ' . date('Y-m-d H:i:s') . "\n\n";

try {
    if (!isset($dbo) || !($dbo instanceof PDO)) {
        throw new RuntimeException('PDO connection $dbo is not available.');
    }

    $activeRaceYear = isset($_GET['year']) && ctype_digit((string)$_GET['year'])
        ? (string)$_GET['year']
        : (isset($raceYear) ? (string)$raceYear : '2026');

    $segment = isset($_GET['segment']) ? trim((string)$_GET['segment']) : 'S1';
    if (!in_array($segment, ['S1', 'S2', 'S3', 'S4'], true)) {
        $segment = 'S1';
    }

    $teamName = isset($_GET['team']) ? trim((string)$_GET['team']) : 'Be Like Biff';

    echo 'FILE: ' . basename(__FILE__) . "\n";
    echo 'VERSION: v002' . "\n";
    echo 'RACE YEAR: ' . $activeRaceYear . "\n";
    echo 'SEGMENT: ' . $segment . "\n";
    echo 'TEAM: ' . $teamName . "\n\n";

    $yearFolder = __DIR__ . '/' . $activeRaceYear;
    if (!is_dir($yearFolder)) {
        throw new RuntimeException('Year folder not found: ' . $yearFolder);
    }

    $bounds = mrl_rd_try_get_segment_bounds($dbo, (int)$activeRaceYear, $segment);
    if ($bounds === null) {
        echo "RD ELIGIBILITY RESULT\n";
        echo "---------------------\n";
        echo 'STATUS: NO_SEGMENT_CONFIG' . "\n";
        echo 'MESSAGE: No segment_race_ranges setup found for ' . $activeRaceYear . ' ' . $segment . ".\n\n";
        echo 'DONE' . "\n";
        echo '</pre>';
        exit;
    }

    $raceNumbers = mrl_rd_segment_race_numbers($dbo, (int)$activeRaceYear, $segment);
    $raceDriverPoints = test_rd_build_race_driver_points($activeRaceYear, $raceNumbers, $yearFolder);

    echo 'BOUNDS: ' . (int)$bounds['start'] . ' to ' . (int)$bounds['end'] . "\n";
    echo 'RACES: ' . implode(', ', $raceNumbers) . "\n\n";

    $result = mrl_rd_detect_team_segment_eligibility(
        $dbo,
        $activeRaceYear,
        $segment,
        $teamName,
        $raceDriverPoints
    );

    echo "RD ELIGIBILITY RESULT\n";
    echo "---------------------\n";
    echo 'STATUS: ' . (string)($result['status'] ?? '') . "\n";
    echo 'HAS BASE ROW: ' . (!empty($result['has_base_row']) ? 'YES' : 'NO') . "\n";
    echo 'RD ALREADY USED THIS YEAR: ' . (!empty($result['rd_already_used_this_year']) ? 'YES' : 'NO') . "\n";
    echo 'QUALIFIER COUNT: ' . (int)($result['qualifier_count'] ?? 0) . "\n";
    echo 'AUTO SELECT ALLOWED: ' . (!empty($result['auto_select_allowed']) ? 'YES' : 'NO') . "\n\n";

    if (!empty($result['base_pick_row']) && is_array($result['base_pick_row'])) {
        $base = $result['base_pick_row'];
        echo "BASE PICK ROW\n";
        echo "-------------\n";
        echo 'pickID: ' . (string)($base['pickID'] ?? '') . "\n";
        echo 'pick_type: ' . (string)($base['pick_type'] ?? '') . "\n";
        echo 'effective_race: ' . (string)($base['effective_race'] ?? '') . "\n";
        echo 'driverA: ' . (string)($base['driverA'] ?? '') . "\n";
        echo 'driverB: ' . (string)($base['driverB'] ?? '') . "\n";
        echo 'driverC: ' . (string)($base['driverC'] ?? '') . "\n";
        echo 'driverD: ' . (string)($base['driverD'] ?? '') . "\n\n";
    }

    $qualifiers = isset($result['qualifiers']) && is_array($result['qualifiers'])
        ? $result['qualifiers']
        : [];

    if (!empty($qualifiers)) {
        echo "QUALIFIERS\n";
        echo "----------\n";
        foreach ($qualifiers as $q) {
            echo 'SLOT: ' . (string)($q['slot'] ?? '') . "\n";
            echo 'DRIVER: ' . (string)($q['driver'] ?? '') . "\n";
            echo 'ZERO RACES: ' . implode(', ', (array)($q['zero_races'] ?? [])) . "\n";
            echo 'EFFECTIVE RACE: ' . (($q['effective_race'] ?? null) === null ? 'NULL' : (string)$q['effective_race']) . "\n";
            echo "\n";
        }
    }

    echo "PER-SLOT NET POINTS\n";
    echo "-------------------\n";
    if (!empty($result['base_pick_row']) && is_array($result['base_pick_row'])) {
        $slots = [
            'A' => (string)($result['base_pick_row']['driverA'] ?? ''),
            'B' => (string)($result['base_pick_row']['driverB'] ?? ''),
            'C' => (string)($result['base_pick_row']['driverC'] ?? ''),
            'D' => (string)($result['base_pick_row']['driverD'] ?? ''),
        ];

        foreach ($slots as $slot => $driverName) {
            echo 'Slot ' . $slot . ': ' . $driverName . "\n";
            $rows = mrl_rd_driver_points_for_segment($dbo, (int)$activeRaceYear, $segment, $driverName, $raceDriverPoints);

            foreach ($rows as $row) {
                echo '  R' . str_pad((string)$row['race_number'], 2, '0', STR_PAD_LEFT) . ' = ' . (int)$row['net'] . "\n";
            }
            echo "\n";
        }
    }

    echo 'DONE' . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    echo 'FILE: ' . $e->getFile() . "\n";
    echo 'LINE: ' . $e->getLine() . "\n";
}

echo '</pre>';
