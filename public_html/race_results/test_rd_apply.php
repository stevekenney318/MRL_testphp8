<?php
declare(strict_types=1);

/**
 * test_rd_apply.php
 *
 * VERSION: v001
 * LAST MODIFIED: 4/3/2026 12:40:00 am
 *
 * DESCRIPTION:
 * Browser test page for race_results_rd_apply.php.
 * DRY RUN ONLY — does not insert or update anything.
 *
 * PURPOSE:
 * - Verifies RD eligibility for a team/segment
 * - Verifies selected slot is eligible
 * - Verifies replacement driver passes validation
 * - Shows the source row that would be superseded
 * - Shows the new RD row that would be created
 *
 * QUERY STRING EXAMPLE:
 * ?year=2026&segment=S1&team=I%20am%20speed&slot=D&replacement=Ty%20Gibbs
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';

// disableCaching() defined in functions_mrl.php
disableCaching();

// visual id of a sandbox file - SK & background
require_once $_SERVER['DOCUMENT_ROOT'] . '/sandbox.html';

require_once __DIR__ . '/race_results_rd_helper.php';
require_once __DIR__ . '/race_results_rd_apply.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';

date_default_timezone_set('America/New_York');

function test_rd_apply_find_snapshot_file(string $raceFolder): string
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

function test_rd_apply_load_year_index(string $yearFolder): array
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

function test_rd_apply_point_races_from_index(array $racesIndex, string $yearFolder): array
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

function test_rd_apply_build_race_driver_points(string $year, array $raceNumbers, string $yearFolder): array
{
    $raceDriverPoints = [];
    $yearIndex = test_rd_apply_load_year_index($yearFolder);
    $pointRaces = test_rd_apply_point_races_from_index($yearIndex, $yearFolder);

    foreach ($raceNumbers as $raceNumber) {
        $raceNumber = (int)$raceNumber;
        $raceDriverPoints[$raceNumber] = [];

        if (!isset($pointRaces[$raceNumber])) {
            continue;
        }

        $snapshotFile = test_rd_apply_find_snapshot_file((string)$pointRaces[$raceNumber]['raceFolder']);
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

    $teamName = isset($_GET['team']) ? trim((string)$_GET['team']) : 'I am speed';
    $slot = isset($_GET['slot']) ? strtoupper(trim((string)$_GET['slot'])) : 'D';
    $replacement = isset($_GET['replacement']) ? trim((string)$_GET['replacement']) : 'Ty Gibbs';

    echo 'FILE: ' . basename(__FILE__) . "\n";
    echo 'VERSION: v001' . "\n";
    echo 'MODE: DRY RUN ONLY' . "\n";
    echo 'RACE YEAR: ' . $activeRaceYear . "\n";
    echo 'SEGMENT: ' . $segment . "\n";
    echo 'TEAM: ' . $teamName . "\n";
    echo 'SELECTED SLOT: ' . $slot . "\n";
    echo 'REPLACEMENT DRIVER: ' . $replacement . "\n\n";

    $yearFolder = __DIR__ . '/' . $activeRaceYear;
    if (!is_dir($yearFolder)) {
        throw new RuntimeException('Year folder not found: ' . $yearFolder);
    }

    $bounds = mrl_rd_try_get_segment_bounds($dbo, (int)$activeRaceYear, $segment);
    if ($bounds === null) {
        echo "RD APPLY DRY RUN RESULT\n";
        echo "-----------------------\n";
        echo "STATUS: NO_SEGMENT_CONFIG\n";
        echo 'MESSAGE: No segment_race_ranges setup found for ' . $activeRaceYear . ' ' . $segment . ".\n\n";
        echo 'DONE' . "\n";
        echo '</pre>';
        exit;
    }

    $raceNumbers = mrl_rd_segment_race_numbers($dbo, (int)$activeRaceYear, $segment);
    $raceDriverPoints = test_rd_apply_build_race_driver_points($activeRaceYear, $raceNumbers, $yearFolder);

    $eligibility = mrl_rd_detect_team_segment_eligibility(
        $dbo,
        $activeRaceYear,
        $segment,
        $teamName,
        $raceDriverPoints
    );

    echo "ELIGIBILITY\n";
    echo "-----------\n";
    echo 'STATUS: ' . (string)($eligibility['status'] ?? '') . "\n";
    echo 'QUALIFIER COUNT: ' . (int)($eligibility['qualifier_count'] ?? 0) . "\n";
    echo 'AUTO SELECT ALLOWED: ' . (!empty($eligibility['auto_select_allowed']) ? 'YES' : 'NO') . "\n\n";

    $qualifiers = isset($eligibility['qualifiers']) && is_array($eligibility['qualifiers'])
        ? $eligibility['qualifiers']
        : [];

    $selectedQualifier = mrl_rd_apply_find_qualifier_for_slot($qualifiers, $slot);

    if (!is_array($selectedQualifier)) {
        echo "RD APPLY DRY RUN RESULT\n";
        echo "-----------------------\n";
        echo "STATUS: SLOT_NOT_ELIGIBLE\n";
        echo 'MESSAGE: Selected slot ' . $slot . " is not RD-eligible.\n\n";
        echo 'DONE' . "\n";
        echo '</pre>';
        exit;
    }

    $effectiveRace = (int)($selectedQualifier['effective_race'] ?? 0);
    $sourceRow = mrl_rd_apply_find_source_row($dbo, $activeRaceYear, $segment, $teamName, $effectiveRace);

    if (!is_array($sourceRow)) {
        echo "RD APPLY DRY RUN RESULT\n";
        echo "-----------------------\n";
        echo "STATUS: NO_SOURCE_ROW\n";
        echo "MESSAGE: No source row found to supersede.\n\n";
        echo 'DONE' . "\n";
        echo '</pre>';
        exit;
    }

    $driverCheck = mrl_rd_apply_validate_replacement_driver($sourceRow, $slot, $replacement);

    echo "SELECTED QUALIFIER\n";
    echo "------------------\n";
    echo 'SLOT: ' . (string)($selectedQualifier['slot'] ?? '') . "\n";
    echo 'DRIVER: ' . (string)($selectedQualifier['driver'] ?? '') . "\n";
    echo 'ZERO RACES: ' . implode(', ', (array)($selectedQualifier['zero_races'] ?? [])) . "\n";
    echo 'EFFECTIVE RACE: ' . $effectiveRace . "\n\n";

    echo "SOURCE ROW TO SUPERSEDE\n";
    echo "-----------------------\n";
    echo 'pickID: ' . (string)($sourceRow['pickID'] ?? '') . "\n";
    echo 'pick_type: ' . (string)($sourceRow['pick_type'] ?? '') . "\n";
    echo 'effective_race: ' . (string)($sourceRow['effective_race'] ?? '') . "\n";
    echo 'driverA: ' . (string)($sourceRow['driverA'] ?? '') . "\n";
    echo 'driverB: ' . (string)($sourceRow['driverB'] ?? '') . "\n";
    echo 'driverC: ' . (string)($sourceRow['driverC'] ?? '') . "\n";
    echo 'driverD: ' . (string)($sourceRow['driverD'] ?? '') . "\n\n";

    echo "REPLACEMENT DRIVER VALIDATION\n";
    echo "-----------------------------\n";
    echo 'OK: ' . (!empty($driverCheck['ok']) ? 'YES' : 'NO') . "\n";
    echo 'MESSAGE: ' . (string)($driverCheck['message'] ?? '') . "\n\n";

    if (empty($driverCheck['ok'])) {
        echo "RD APPLY DRY RUN RESULT\n";
        echo "-----------------------\n";
        echo "STATUS: INVALID_REPLACEMENT_DRIVER\n";
        echo "MESSAGE: Replacement driver did not pass validation.\n\n";
        echo 'DONE' . "\n";
        echo '</pre>';
        exit;
    }

    $newRow = mrl_rd_apply_build_new_row($sourceRow, $slot, $replacement, $effectiveRace);

    echo "NEW RD ROW (DRY RUN)\n";
    echo "--------------------\n";
    echo 'teamName: ' . (string)($newRow['teamName'] ?? '') . "\n";
    echo 'raceYear: ' . (string)($newRow['raceYear'] ?? '') . "\n";
    echo 'segment: ' . (string)($newRow['segment'] ?? '') . "\n";
    echo 'pick_type: ' . (string)($newRow['pick_type'] ?? '') . "\n";
    echo 'effective_race: ' . (string)($newRow['effective_race'] ?? '') . "\n";
    echo 'supersedes_pickID: ' . (string)($newRow['supersedes_pickID'] ?? '') . "\n";
    echo 'driverA: ' . (string)($newRow['driverA'] ?? '') . "\n";
    echo 'driverB: ' . (string)($newRow['driverB'] ?? '') . "\n";
    echo 'driverC: ' . (string)($newRow['driverC'] ?? '') . "\n";
    echo 'driverD: ' . (string)($newRow['driverD'] ?? '') . "\n\n";

    echo "RD APPLY DRY RUN RESULT\n";
    echo "-----------------------\n";
    echo "STATUS: READY_TO_APPLY\n";
    echo "MESSAGE: Dry run passed. No database changes were made.\n\n";

    echo 'DONE' . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    echo 'FILE: ' . $e->getFile() . "\n";
    echo 'LINE: ' . $e->getLine() . "\n";
}

echo '</pre>';
