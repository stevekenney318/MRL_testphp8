<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';

// disableCaching() defined in functions_mrl.php
disableCaching();

// visual id of a sandbox file - SK & background
require_once $_SERVER['DOCUMENT_ROOT'] . '/sandbox.html';

/**
 * weekly_standings.php
 *
 * VERSION: v040
 * LAST MODIFIED: 3/22/2026 1:16:25 am
 *
 *
 * CHANGELOG:
 *
 * v040 (3/18/2026)
 *   - CHANGE: Added a Live button before the dropdowns that jumps to the latest year + latest race and dims when already on the live view.
 *   - CHANGE: Removed the visible Year and Race labels from the top controls for a cleaner layout.
 *   - CHANGE: Year dropdown now displays earliest → latest while initial page load still defaults to the most current year.
 *   - CHANGE: Race dropdown now displays races in ascending order (R01 → R36) top to bottom.
 *   - CHANGE: Year change now clears the screen and resets Race to a neutral "Select Race" state.
 *   - CHANGE: Race dropdown is now the trigger; selecting a race submits without a separate Show button.
 *   - CHANGE: Added subtle placeholder text when no race is selected: "Select a race to view results".
 *   - CHANGE: Nav arrows, validation button, and historical note now reset cleanly in the no-race-selected state.
 *   - CHANGE: Initial page load still defaults to the most current year and most current race.
 *   - CHANGE: Kept sandbox include line as a commented on/off switch.
 *   - CHANGE: Segment detail rows now list races in ascending order.
 *   - CHANGE: Year table now supports expandable segment-total detail rows through the selected segment.
 *   - CHANGE: Weekly Winners ties now render one winner per row.
 *   - CHANGE: Weekly Winners tied rows now mark the week number with an asterisk and show a footnote when ties exist.
 *
 * v039 (3/16/2026)
 *   - Added navigation arrows (<< >>) for race cycling.
 *   - Added historical disclaimer message for pre-2026 races.
 *   - Updated race naming for consistency and layout stability:
 *       - Circuit of the Americas → COTA
 *       - Indianapolis Road Course → Indianapolis RC
 *       - Charlotte Road Course → Charlotte RC
 *       - World Wide Technology Raceway → World Wide Technology
 *   - Improved dropdown behavior by repopulating Race when Year changes without auto-submitting.
 *   - Replaced the old status indicator with the colored Show Validation / Hide Validation button.
 *   - Synced validation toggle behavior with race navigation and cleaned up related CSS.
 *
 * v038 (3/15/2026)
 *   - CHANGE: Warning logic now reports only zero-point drivers that belong to an actual MRL team pick.
 *   - CHANGE: WARN messages now list the specific zero-point driver and MRL team instead of using a generic count.
 *   - CHANGE: Debug details table Race and Computed Winner columns now left-align for easier scanning.
 *   - CHANGE: Restored light background styling across the full expanded weekly driver detail area.
 *   - CHANGE: Added a small amount of top and bottom padding to the expanded weekly driver detail area.
 *   - CHANGE: Nudged expanded weekly driver detail point values slightly left for better visual balance.
 *   - CHANGE: Added a subtle tinted background behind expanded weekly driver detail point cells.
 *
 * PHP: 7.3 compatible.
 */


// helper files
require_once __DIR__ . '/race_results_team_helper.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';
require_once __DIR__ . '/race_results_engine.php';

function rrsg_h($val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function rrsg_driver_net(array $driverPoints, string $driverName): int
{
    if ($driverName === '') {
        return 0;
    }

    if (!isset($driverPoints[$driverName]) || !is_array($driverPoints[$driverName])) {
        return 0;
    }

    return (int)($driverPoints[$driverName]['net'] ?? 0);
}

function rrsg_sort_weekly_rows(array &$rows): void
{
    usort($rows, function ($a, $b) {
        $aTotal = (int)($a['weeklyTotal'] ?? 0);
        $bTotal = (int)($b['weeklyTotal'] ?? 0);

        if ($aTotal !== $bTotal) {
            return ($bTotal <=> $aTotal);
        }

        return strcasecmp((string)($a['teamName'] ?? ''), (string)($b['teamName'] ?? ''));
    });
}

function rrsg_sort_total_rows(array $totals): array
{
    $rows = [];

    foreach ($totals as $teamName => $total) {
        $rows[] = [
            'teamName' => (string)$teamName,
            'total' => (int)$total,
        ];
    }

    usort($rows, function ($a, $b) {
        $aTotal = (int)$a['total'];
        $bTotal = (int)$b['total'];

        if ($aTotal !== $bTotal) {
            return ($bTotal <=> $aTotal);
        }

        return strcasecmp((string)$a['teamName'], (string)$b['teamName']);
    });

    return $rows;
}

function rrsg_find_snapshot_file(string $raceFolder): string
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

function rrsg_build_weekly_rows(array $teamRows, array $driverPoints): array
{
    $weeklyRows = [];

    foreach ($teamRows as $team) {
        $driverA = (string)($team['driverA'] ?? '');
        $driverB = (string)($team['driverB'] ?? '');
        $driverC = (string)($team['driverC'] ?? '');
        $driverD = (string)($team['driverD'] ?? '');

        $netA = rrsg_driver_net($driverPoints, $driverA);
        $netB = rrsg_driver_net($driverPoints, $driverB);
        $netC = rrsg_driver_net($driverPoints, $driverC);
        $netD = rrsg_driver_net($driverPoints, $driverD);

        $weeklyTotal = $netA + $netB + $netC + $netD;

        $weeklyRows[] = [
            'teamName' => (string)($team['teamName'] ?? ''),
            'userName' => (string)($team['userName'] ?? ''),
            'driverA' => $driverA,
            'driverB' => $driverB,
            'driverC' => $driverC,
            'driverD' => $driverD,
            'netA' => $netA,
            'netB' => $netB,
            'netC' => $netC,
            'netD' => $netD,
            'weeklyTotal' => $weeklyTotal,
        ];
    }

    rrsg_sort_weekly_rows($weeklyRows);
    return $weeklyRows;
}

function rrsg_get_weekly_winner(array $weeklyRows): array
{
    if (empty($weeklyRows)) {
        return [
            'teamName' => '',
            'teamNames' => [],
            'points' => 0,
        ];
    }

    $topPoints = (int)($weeklyRows[0]['weeklyTotal'] ?? 0);
    $winnerNames = [];

    foreach ($weeklyRows as $row) {
        $weeklyTotal = (int)($row['weeklyTotal'] ?? 0);
        if ($weeklyTotal !== $topPoints) {
            break;
        }

        $winnerNames[] = (string)($row['teamName'] ?? '');
    }

    return [
        'teamName' => implode(' / ', $winnerNames),
        'teamNames' => $winnerNames,
        'points' => $topPoints,
    ];
}

function rrsg_segment_from_race_number(int $raceNumber): string
{
    if ($raceNumber >= 1 && $raceNumber <= 8) {
        return 'S1';
    }
    if ($raceNumber >= 9 && $raceNumber <= 17) {
        return 'S2';
    }
    if ($raceNumber >= 18 && $raceNumber <= 26) {
        return 'S3';
    }
    if ($raceNumber >= 27 && $raceNumber <= 36) {
        return 'S4';
    }

    return 'S1';
}

function rrsg_segment_bounds(string $segment): array
{
    if ($segment === 'S1') return ['start' => 1, 'end' => 8];
    if ($segment === 'S2') return ['start' => 9, 'end' => 17];
    if ($segment === 'S3') return ['start' => 18, 'end' => 26];
    if ($segment === 'S4') return ['start' => 27, 'end' => 36];

    return ['start' => 1, 'end' => 8];
}

function rrsg_available_years(string $baseDir): array
{
    $years = [];
    $items = scandir($baseDir);

    if (!is_array($items)) {
        return [];
    }

    foreach ($items as $name) {
        if (!preg_match('/^\d{4}$/', (string)$name)) {
            continue;
        }

        $yearFolder = $baseDir . '/' . $name;
        if (!is_dir($yearFolder)) {
            continue;
        }

        if (is_file($yearFolder . '/_year_index.json')) {
            $years[] = (string)$name;
        }
    }

    rsort($years, SORT_STRING);
    return $years;
}

function rrsg_load_year_index_file(string $path): array
{
    $idx = rr_load_json($path);
    if (!is_array($idx)) return [];
    if (!isset($idx['races']) || !is_array($idx['races'])) return [];
    return $idx;
}

function rrsg_points_races_from_index(array $yearIndex, string $yearBaseFolder): array
{
    $rows = [];

    foreach ($yearIndex['races'] as $raceId => $row) {
        if (!is_array($row)) continue;

        $kind = (string)($row['kind'] ?? '');
        if ($kind !== 'R') continue;

        $number = (int)($row['number'] ?? 0);
        $folder = (string)($row['folder'] ?? '');
        $raceName = (string)($row['race_name'] ?? '');
        $raceUrl = (string)($row['race_url'] ?? '');

        if ($number <= 0 || $folder === '') continue;

        $rows[] = [
            'raceId' => (string)$raceId,
            'kind' => $kind,
            'number' => $number,
            'raceCode' => 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'raceName' => $raceName,
            'raceUrl' => $raceUrl,
            'folder' => $folder,
            'raceFolder' => $yearBaseFolder . '/' . $folder,
        ];
    }

    usort($rows, function ($a, $b) {
        $an = (int)$a['number'];
        $bn = (int)$b['number'];

        if ($an !== $bn) {
            return ($bn <=> $an);
        }

        return strcmp((string)$a['raceId'], (string)$b['raceId']);
    });

    return $rows;
}

function rrsg_sort_races_ascending(array $races): array
{
    usort($races, function ($a, $b) {
        $an = (int)($a['number'] ?? 0);
        $bn = (int)($b['number'] ?? 0);

        if ($an !== $bn) {
            return ($an <=> $bn);
        }

        return strcmp((string)($a['raceCode'] ?? ''), (string)($b['raceCode'] ?? ''));
    });

    return $races;
}

function rrsg_short_race_label(string $raceName): string
{
    $slug = rr_sanitize_for_folder($raceName);

    $map = [
        'EchoPark_Automotive_Grand_Prix' => 'COTA',
        'NASCAR_Cup_Series_at_Circuit_of_the_Americas' => 'COTA',
        'NASCAR_CUP_SERIES_AT_CIRCUIT_OF_THE_AMERICAS' => 'COTA',

        'World_Wide_Technology_Raceway' => 'World Wide Tech',
        'NASCAR_Cup_Series_at_World_Wide_Technology_Raceway' => 'World Wide Tech',
        'NASCAR_CUP_SERIES_AT_WORLD_WIDE_TECHNOLOGY_RACEWAY' => 'World Wide Tech',

        'Indianapolis_Road_Course' => 'Indianapolis RC',
        'NASCAR_Cup_Series_at_Indianapolis_Road_Course' => 'Indianapolis RC',
        'NASCAR_CUP_SERIES_AT_INDIANAPOLIS_ROAD_COURSE' => 'Indianapolis RC',

        'Charlotte_Road_Course' => 'Charlotte RC',
        'NASCAR_Cup_Series_at_Charlotte_Road_Course' => 'Charlotte RC',
        'NASCAR_CUP_SERIES_AT_CHARLOTTE_ROAD_COURSE' => 'Charlotte RC',
    ];

    if (isset($map[$slug])) {
        return $map[$slug];
    }

    $slug = preg_replace('/^MONSTER_ENERGY_NASCAR_CUP_SERIES_AT_/i', '', $slug);
    $slug = preg_replace('/^NASCAR_CUP_SERIES_AT_/i', '', $slug);
    $slug = preg_replace('/^NASCAR_Cup_Series_at_/i', '', $slug);

    $slug = str_replace('World_Wide_Technology_Raceway', 'World Wide Tech', $slug);
    $slug = str_replace('Indianapolis_Road_Course', 'Indianapolis_RC', $slug);
    $slug = str_replace('Charlotte_Road_Course', 'Charlotte_RC', $slug);
    $slug = str_replace('Road_Course', 'RC', $slug);

    $slug = trim((string)$slug, '_');

    if ($slug === '') {
        $slug = 'Race';
    }

    return str_replace('_', ' ', $slug);
}

function rrsg_add_validation(array &$validation, string $level, string $message): void
{
    if (!isset($validation[$level]) || !is_array($validation[$level])) {
        $validation[$level] = [];
    }
    $validation[$level][] = $message;
}

function rrsg_is_sorted_weekly_desc(array $weeklyRows): bool
{
    if (count($weeklyRows) <= 1) {
        return true;
    }

    for ($i = 0; $i < count($weeklyRows) - 1; $i++) {
        $curTotal = (int)($weeklyRows[$i]['weeklyTotal'] ?? 0);
        $nextTotal = (int)($weeklyRows[$i + 1]['weeklyTotal'] ?? 0);

        if ($curTotal < $nextTotal) {
            return false;
        }

        if ($curTotal === $nextTotal) {
            $curName = (string)($weeklyRows[$i]['teamName'] ?? '');
            $nextName = (string)($weeklyRows[$i + 1]['teamName'] ?? '');

            if (strcasecmp($curName, $nextName) > 0) {
                return false;
            }
        }
    }

    return true;
}

function rrsg_validation_status(array $validation): string
{
    if (!empty($validation['fail'])) {
        return 'FAIL';
    }
    if (!empty($validation['warn'])) {
        return 'WARN';
    }
    return 'PASS';
}

function rrsg_build_year_race_options(array $availableYears, string $baseDir): array
{
    $result = [];

    foreach ($availableYears as $yearOpt) {
        $yearFolder = $baseDir . '/' . $yearOpt;
        $yearIndexFile = $yearFolder . '/_year_index.json';
        $yearIndex = rrsg_load_year_index_file($yearIndexFile);
        $pointRaces = rrsg_points_races_from_index($yearIndex, $yearFolder);
        $pointRacesAsc = rrsg_sort_races_ascending($pointRaces);

        $result[$yearOpt] = [];

        foreach ($pointRacesAsc as $race) {
            $result[$yearOpt][] = [
                'raceCode' => (string)$race['raceCode'],
                'label' => (string)$race['raceCode'] . ' ' . rrsg_short_race_label((string)$race['raceName']),
                'number' => (int)$race['number'],
            ];
        }
    }

    return $result;
}

function rrsg_segment_breakdown_rows(
    string $selectedYear,
    string $scoreSegment,
    int $selectedRaceNumber,
    array $pointRaces,
    $dbo,
    $dbconnect
): array {
    $rows = [];
    $racesAscending = $pointRaces;

    usort($racesAscending, function ($a, $b) {
        return ((int)$a['number']) <=> ((int)$b['number']);
    });

    foreach ($racesAscending as $race) {
        $raceNumber = (int)($race['number'] ?? 0);

        if ($raceNumber > $selectedRaceNumber) {
            continue;
        }

        if (rrsg_segment_from_race_number($raceNumber) !== $scoreSegment) {
            continue;
        }

        $raceTeamRows = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $selectedYear, $scoreSegment);
        $snapshotFile = rrsg_find_snapshot_file((string)$race['raceFolder']);
        if ($snapshotFile === '') {
            continue;
        }

        $driverPoints = rrs_load_snapshot_driver_points($snapshotFile);
        $weeklyRows = rrsg_build_weekly_rows($raceTeamRows, $driverPoints);

        $rows[] = [
            'raceCode' => (string)$race['raceCode'],
            'raceLabel' => rrsg_short_race_label((string)$race['raceName']),
            'weeklyRows' => $weeklyRows,
        ];
    }

    return $rows;
}

function rrsg_visible_segments(string $scoreSegment): array
{
    $segments = ['S1', 'S2', 'S3', 'S4'];
    $result = [];

    foreach ($segments as $segment) {
        $result[] = $segment;
        if ($segment === $scoreSegment) {
            break;
        }
    }

    return $result;
}

/* ------------------------------------------------------------------
   INPUTS
   ------------------------------------------------------------------ */

$baseDir = __DIR__;
$availableYears = rrsg_available_years($baseDir);
$availableYearsAsc = $availableYears;
sort($availableYearsAsc, SORT_STRING);

$selectedYear = isset($_GET['year']) ? trim((string)$_GET['year']) : '';
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = !empty($availableYears) ? $availableYears[0] : '2026';
}

$yearFolder = $baseDir . '/' . $selectedYear;
$yearIndexFile = $yearFolder . '/_year_index.json';
$yearIndex = rrsg_load_year_index_file($yearIndexFile);
$pointRaces = rrsg_points_races_from_index($yearIndex, $yearFolder);
$pointRacesAsc = rrsg_sort_races_ascending($pointRaces);

$latestYear = !empty($availableYears) ? (string)$availableYears[0] : $selectedYear;
$latestYearFolder = $baseDir . '/' . $latestYear;
$latestYearIndexFile = $latestYearFolder . '/_year_index.json';
$latestYearIndex = rrsg_load_year_index_file($latestYearIndexFile);
$latestPointRaces = rrsg_points_races_from_index($latestYearIndex, $latestYearFolder);
$latestRaceCode = !empty($latestPointRaces) ? (string)$latestPointRaces[0]['raceCode'] : '';

$selectedRaceCode = isset($_GET['race']) ? trim((string)$_GET['race']) : '';
if ($selectedRaceCode === '' && !empty($pointRaces)) {
    $selectedRaceCode = (string)$pointRaces[0]['raceCode'];
}

$selectedRaceIndex = -1;
for ($i = 0; $i < count($pointRaces); $i++) {
    if ((string)$pointRaces[$i]['raceCode'] === $selectedRaceCode) {
        $selectedRaceIndex = $i;
        break;
    }
}

if ($selectedRaceIndex < 0 && !empty($pointRaces)) {
    $selectedRaceIndex = 0;
    $selectedRaceCode = (string)$pointRaces[$selectedRaceIndex]['raceCode'];
}

$selectedRace = ($selectedRaceIndex >= 0 && isset($pointRaces[$selectedRaceIndex]))
    ? $pointRaces[$selectedRaceIndex]
    : null;

$liveUrl = '?year=' . rawurlencode($latestYear);
if ($latestRaceCode !== '') {
    $liveUrl .= '&race=' . rawurlencode($latestRaceCode);
}

$isLiveView = ($selectedYear === $latestYear && $selectedRaceCode === $latestRaceCode);

$scoreYear = $selectedYear;
$scoreSegment = 'S1';
$segmentBounds = ['start' => 1, 'end' => 8];
$selectedRaceNumber = 0;
$selectedRaceDisplay = '';

if ($selectedRace !== null) {
    $selectedRaceNumber = (int)$selectedRace['number'];
    $scoreSegment = rrsg_segment_from_race_number($selectedRaceNumber);
    $segmentBounds = rrsg_segment_bounds($scoreSegment);
    $selectedRaceDisplay = (string)$selectedRace['raceCode'] . ' ' . rrsg_short_race_label((string)$selectedRace['raceName']);
}

$teamRows = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $scoreYear, $scoreSegment);

$segmentTotals = [];
$seasonTotals = [];
$segmentHistory = [];
$weeklyWinners = [];
$selectedRaceWeeklyRows = [];
$selectedRaceMeta = [
    'raceCode' => '',
    'raceLabel' => '',
    'snapshotFile' => '',
    'driverCount' => 0,
];

$debugRows = [];

$validation = [
    'pass' => [],
    'warn' => [],
    'fail' => [],
];

foreach ($teamRows as $team) {
    $teamName = (string)($team['teamName'] ?? '');
    if ($teamName === '') {
        continue;
    }

    $segmentTotals[$teamName] = 0;
    $seasonTotals[$teamName] = 0;
}

if ($selectedRace !== null) {
    $racesAscending = $pointRaces;
    usort($racesAscending, function ($a, $b) {
        return ((int)$a['number']) <=> ((int)$b['number']);
    });

    foreach ($racesAscending as $race) {
        $raceCode = (string)$race['raceCode'];
        $raceLabel = rrsg_short_race_label((string)$race['raceName']);
        $raceFolder = (string)$race['raceFolder'];
        $raceNumber = (int)$race['number'];

        if ($raceNumber > $selectedRaceNumber) {
            continue;
        }

        $raceSegment = rrsg_segment_from_race_number($raceNumber);
        $raceTeamRows = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $selectedYear, $raceSegment);

        $snapshotFile = rrsg_find_snapshot_file($raceFolder);
        $driverPoints = [];
        $weeklyRows = [];
        $winner = [
            'teamName' => '',
            'teamNames' => [],
            'points' => 0,
        ];

        if ($snapshotFile !== '') {
            $driverPoints = rrs_load_snapshot_driver_points($snapshotFile);
            $weeklyRows = rrsg_build_weekly_rows($raceTeamRows, $driverPoints);
            $winner = rrsg_get_weekly_winner($weeklyRows);

            foreach ($weeklyRows as $row) {
                $teamName = (string)$row['teamName'];
                $weeklyTotal = (int)$row['weeklyTotal'];

                if (!isset($seasonTotals[$teamName])) {
                    $seasonTotals[$teamName] = 0;
                }
                $seasonTotals[$teamName] += $weeklyTotal;

                if ($raceNumber >= $segmentBounds['start'] && $raceNumber <= $segmentBounds['end']) {
                    if (!isset($segmentTotals[$teamName])) {
                        $segmentTotals[$teamName] = 0;
                    }
                    $segmentTotals[$teamName] += $weeklyTotal;
                }

                if (!isset($segmentHistory[$teamName])) {
                    $segmentHistory[$teamName] = [
                        'S1' => 0,
                        'S2' => 0,
                        'S3' => 0,
                        'S4' => 0,
                    ];
                }

                if (!isset($segmentHistory[$teamName][$raceSegment])) {
                    $segmentHistory[$teamName][$raceSegment] = 0;
                }

                $segmentHistory[$teamName][$raceSegment] += $weeklyTotal;
            }

            $weeklyWinners[$raceCode] = $winner;
        } else {
            $weeklyWinners[$raceCode] = [
                'teamName' => '',
                'teamNames' => [],
                'points' => 0,
            ];
        }

        $debugRows[] = [
            'raceCode' => $raceCode,
            'raceLabel' => $raceLabel,
            'raceNumber' => $raceNumber,
            'raceSegment' => $raceSegment,
            'teamsLoaded' => count($raceTeamRows),
            'snapshotBase' => ($snapshotFile !== '' ? basename($snapshotFile) : 'NOT FOUND'),
            'winnerTeam' => (string)$winner['teamName'],
            'winnerPoints' => (int)$winner['points'],
        ];

        if ($raceCode === $selectedRaceCode) {
            $selectedRaceWeeklyRows = $weeklyRows;
            $selectedRaceMeta = [
                'raceCode' => $raceCode,
                'raceLabel' => $raceLabel,
                'snapshotFile' => $snapshotFile,
                'driverCount' => count($driverPoints),
            ];
        }
    }
}

/* ------------------------------------------------------------------
   VALIDATION
   ------------------------------------------------------------------ */

if ($selectedRace === null) {
    rrsg_add_validation($validation, 'pass', 'Select a race to view validation.');
} else {
    rrsg_add_validation($validation, 'pass', 'Selected race found: ' . $selectedRaceCode);

    if ($selectedRaceMeta['snapshotFile'] !== '') {
        rrsg_add_validation($validation, 'pass', 'Snapshot found for selected race.');
    } else {
        rrsg_add_validation($validation, 'fail', 'Selected race snapshot not found.');
    }

    if (count($teamRows) > 0) {
        rrsg_add_validation($validation, 'pass', 'Teams loaded: ' . count($teamRows));
    } else {
        rrsg_add_validation($validation, 'fail', 'No teams loaded for selected segment.');
    }

    if (!empty($selectedRaceWeeklyRows)) {
        rrsg_add_validation($validation, 'pass', 'Weekly rows generated: ' . count($selectedRaceWeeklyRows));
    } else {
        rrsg_add_validation($validation, 'fail', 'No weekly rows generated for selected race.');
    }

    $duplicateTeams = [];
    $teamSeen = [];
    $badTotals = 0;
    $zeroDrivers = [];

    foreach ($selectedRaceWeeklyRows as $row) {
        $teamName = (string)($row['teamName'] ?? '');

        if ($teamName !== '') {
            if (isset($teamSeen[$teamName])) {
                $duplicateTeams[] = $teamName;
            }
            $teamSeen[$teamName] = true;
        }

        $sumDrivers =
            (int)($row['netA'] ?? 0) +
            (int)($row['netB'] ?? 0) +
            (int)($row['netC'] ?? 0) +
            (int)($row['netD'] ?? 0);

        $weeklyTotal = (int)($row['weeklyTotal'] ?? 0);

        if ($sumDrivers !== $weeklyTotal) {
            $badTotals++;
        }

        $drivers = [
            ['name' => (string)($row['driverA'] ?? ''), 'net' => (int)($row['netA'] ?? 0)],
            ['name' => (string)($row['driverB'] ?? ''), 'net' => (int)($row['netB'] ?? 0)],
            ['name' => (string)($row['driverC'] ?? ''), 'net' => (int)($row['netC'] ?? 0)],
            ['name' => (string)($row['driverD'] ?? ''), 'net' => (int)($row['netD'] ?? 0)],
        ];

        foreach ($drivers as $driverRow) {
            if ($driverRow['name'] !== '' && $driverRow['net'] === 0) {
                $zeroDrivers[] = [
                    'driver' => $driverRow['name'],
                    'team' => $teamName,
                ];
            }
        }
    }

    if ($badTotals === 0) {
        rrsg_add_validation($validation, 'pass', 'Weekly totals match sum of driver values.');
    } else {
        rrsg_add_validation($validation, 'fail', 'Weekly total mismatch count: ' . $badTotals);
    }

    if (empty($duplicateTeams)) {
        rrsg_add_validation($validation, 'pass', 'No duplicate teams found in weekly results.');
    } else {
        rrsg_add_validation($validation, 'fail', 'Duplicate teams found: ' . implode(', ', array_unique($duplicateTeams)));
    }

    if (!empty($zeroDrivers)) {
        foreach ($zeroDrivers as $zeroDriver) {
            rrsg_add_validation(
                $validation,
                'warn',
                'Unexpected zero score — ' . $zeroDriver['driver'] . ' (Team: ' . $zeroDriver['team'] . ')'
            );
        }
    } else {
        rrsg_add_validation($validation, 'pass', 'No unexpected zero scores detected.');
    }

    if (rrsg_is_sorted_weekly_desc($selectedRaceWeeklyRows)) {
        rrsg_add_validation($validation, 'pass', 'Weekly standings are sorted correctly.');
    } else {
        rrsg_add_validation($validation, 'fail', 'Weekly standings are not sorted correctly.');
    }

    if (!empty($selectedRaceWeeklyRows)) {
        $winner = rrsg_get_weekly_winner($selectedRaceWeeklyRows);
        $topPoints = (int)($selectedRaceWeeklyRows[0]['weeklyTotal'] ?? 0);

        $expectedWinnerNames = [];
        foreach ($selectedRaceWeeklyRows as $row) {
            $weeklyTotal = (int)($row['weeklyTotal'] ?? 0);
            if ($weeklyTotal !== $topPoints) {
                break;
            }
            $expectedWinnerNames[] = (string)$row['teamName'];
        }

        $expectedWinnerString = implode(' / ', $expectedWinnerNames);

        if ($winner['teamName'] === $expectedWinnerString && (int)$winner['points'] === $topPoints) {
            rrsg_add_validation($validation, 'pass', 'Weekly winner matches top weekly score.');
        } else {
            rrsg_add_validation($validation, 'fail', 'Weekly winner does not match top weekly score.');
        }
    }
}

$validationStatus = rrsg_validation_status($validation);
$segmentStandings = rrsg_sort_total_rows($segmentTotals);
$seasonStandings = rrsg_sort_total_rows($seasonTotals);

$segmentBreakdownRows = [];
if ($selectedRace !== null) {
    $segmentBreakdownRows = rrsg_segment_breakdown_rows(
        $selectedYear,
        $scoreSegment,
        $selectedRaceNumber,
        $pointRaces,
        $dbo ?? null,
        $dbconnect ?? null
    );
}

$visibleSegments = rrsg_visible_segments($scoreSegment);

$weeklyWinnersHasTie = false;
foreach ($weeklyWinners as $winnerData) {
    $winnerNames = isset($winnerData['teamNames']) && is_array($winnerData['teamNames'])
        ? $winnerData['teamNames']
        : [];

    if (count($winnerNames) > 1) {
        $weeklyWinnersHasTie = true;
        break;
    }
}

$statusClass = 'status-pass';
if ($validationStatus === 'WARN') {
    $statusClass = 'status-warn';
} elseif ($validationStatus === 'FAIL') {
    $statusClass = 'status-fail';
}

$validationButtonClass = ($selectedRace === null) ? 'status-neutral' : $statusClass;

$historicalNote = '';
if ((int)$selectedYear < 2026 && $selectedRace !== null) {
    $historicalNote = $selectedYear . ' ' . $selectedRaceCode . ' ' . $selectedRaceMeta['raceLabel']
        . ' may contain minor historical scoring differences due to late picks, replacement drivers, and other league adjustments.';
}

$yearRaceOptions = rrsg_build_year_race_options($availableYears, $baseDir);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Weekly Standings</title>
    <style>
        html {
            scrollbar-gutter: stable;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            line-height: 1.3;
            margin: 12px;
            color: #111;
        }

        .page-wrap {
            /* max-width: 1750px; */
            max-width: 1400px;
            margin: 0 auto;
        }

        .top-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
            gap: 8px 12px;
            margin-bottom: 6px;
        }

        .top-controls-left,
        .top-controls-right {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px 10px;
        }

        .top-controls select,
        .top-controls button {
            font: inherit;
            padding: 1px 8px;
        }

        .top-controls button {
            cursor: pointer;
        }

        .live-btn {
            min-width: 66px;
            font-weight: bold;
            border-radius: 18px;
            background: #d9ecff;
            color: #084298;
            border: 3px solid #7db7ff;
        }

        .live-btn:hover {
            filter: brightness(0.97);
        }

        .live-btn.disabled,
        .live-btn[disabled] {
            cursor: default;
            opacity: 0.5;
            color: #5f6f82;
            background: #eef5fb;
            border-color: #c5d7e7;
            filter: none;
        }

        .live-btn.disabled:hover,
        .live-btn[disabled]:hover {
            filter: none;
        }

        .nav-button {
            min-width: 34px;
            text-align: center;
            padding-left: 6px;
            padding-right: 6px;
        }

        .nav-button[disabled] {
            cursor: default;
            opacity: 0.5;
            color: #666;
            background: #f3f3f3;
        }

        .details-toggle {
            font: inherit;
            padding: 2px 8px;
            cursor: pointer;
        }

        .historical-note-slot {
            display: inline-block;
            width: 620px;
            min-height: 1.2em;
            margin-left: 6px;
            font-size: 11px;
            font-style: italic;
            color: #666;
            white-space: normal;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: top;
        }

        .details-content {
            display: none;
            padding: 6px 0 8px 0;
            margin: 0 0 8px 0;
            background: transparent;
            border: none;
        }

        .validation-btn {
            font-weight: bold;
            min-width: 125px;
            border-radius: 25px;
        }

        .validation-btn.status-pass {
            background: #2e8b57;
            color: #fff;
            border: 3px solid #1f5f3b;
        }

        .validation-btn.status-warn {
            background: #f1c232;
            color: #000;
            border: 3px solid #b8961c;
        }

        .validation-btn.status-fail {
            background: #c00000;
            color: #fff;
            border: 3px solid #7a0000;
        }

        .validation-btn.status-neutral {
            background: #e6e6e6;
            color: #666;
            border: 3px solid #c8c8c8;
        }

        .validation-btn:hover {
            filter: brightness(0.95);
        }

        .validation-btn[disabled] {
            cursor: default;
            filter: none;
        }

        .validation-btn[disabled]:hover {
            filter: none;
        }

        .race-placeholder {
            padding: 14px 0 10px 0;
            font-size: 12px;
            color: #666;
        }

        .details-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 14px;
            font-size: 12px;
            margin: 0 0 10px 0;
        }

        .details-meta .chunk {
            white-space: nowrap;
        }

        .details-meta strong {
            font-weight: bold;
        }

        .validation-columns {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 10px;
        }

        .validation-column {
            min-width: 240px;
            flex: 1 1 240px;
        }

        .validation-column h3 {
            margin: 0 0 4px 0;
            font-size: 14px;
        }

        .validation-column ul {
            margin: 0;
            padding-left: 18px;
        }

        .validation-column li {
            margin-bottom: 2px;
        }

        .debug-title {
            margin: 8px 0 4px 0;
            font-size: 14px;
            font-weight: bold;
        }

        .report-grid {
            display: grid;
            grid-template-columns: minmax(200px, 0.75fr) minmax(200px, 0.75fr) minmax(200px, 0.75fr) minmax(250px, 1.25fr);
            gap: 10px;
            align-items: start;
        }

        .report-panel {
            min-width: 0;
        }

        .panel-title {
            font-size: 15px;
            margin: 10px 0 4px 0;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            table-layout: auto;
            font-size: 14px;
        }

        th, td {
            border: 2px solid #151313;
            padding: 0px 7px;
            text-align: center;
            vertical-align: top;
            white-space: nowrap;
        }

        th {
            background: #fbff00;
            font-weight: bold;
        }

        td.num {
            text-align: center;
            white-space: nowrap;
        }

        tr:nth-child(even) td {
            background: #dce6f1;
        }

        th.team-col,
        td.team-col {
            text-align: left;
        }

        th.debug-text-col,
        td.debug-text-col {
            text-align: left;
        }

        .stripe-a td {
            background: #ffffff;
        }

        .stripe-b td {
            background: #dce6f1;
        }

        .col-rank {
            width: 16px;
        }

        .col-score {
            width: 20px;
        }

        .col-week {
            width: 46px;
        }

        .weekly-click-row td {
            transition: none;
        }

        /* ==========================================================
           SHARED DETAIL SYSTEM (FOUNDATION FOR LATER TABLES)
           ========================================================== */

        .team-detail-row > td {
            background: #f4f4f4 !important;
            padding: 4px 8px 4px 8px;
        }

        .team-detail-wrap {
            width: 100%;
        }

        .team-detail-line {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 62px;
            align-items: center;
            gap: 8px;
            min-height: 20px;
            width: 100%;
        }

        .team-detail-line + .team-detail-line {
            margin-top: 1px;
        }

        .team-detail-driver {
            text-align: left;
            padding-left: 18px;
            white-space: nowrap;
            min-width: 0;
        }

        .team-detail-label-wrap {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .team-detail-points {
            text-align: center;
            white-space: nowrap;
            background: #e9edf2;
        }

        .team-detail-total .team-detail-driver,
        .team-detail-total .team-detail-points {
            font-weight: bold;
        }

        .winner-footnote {
            margin-top: 4px;
            margin-left: 10px;
            font-size: 14px;
            color: #666;
            font-style: italic;
        }

        @media (max-width: 1500px) {
            .report-grid {
                grid-template-columns: minmax(280px, 1fr) minmax(280px, 1fr);
            }

            .historical-note-slot {
                width: 480px;
            }
        }

        @media (max-width: 760px) {
            body {
                margin: 8px;
                font-size: 13px;
            }

            .top-controls {
                gap: 4px 8px;
            }

            .top-controls select,
            .top-controls button {
                font-size: 12px;
                padding: 2px 6px;
            }

            .live-btn {
                min-width: 58px;
            }

            .historical-note-slot {
                width: 100%;
                white-space: normal;
                min-height: 1.2em;
                margin-left: 0;
            }

            .details-meta {
                display: block;
                font-size: 11px;
                margin-bottom: 8px;
            }

            .details-meta .chunk {
                display: block;
                white-space: normal;
                margin-bottom: 3px;
            }

            .report-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 4px 6px;
            }

            .team-detail-driver {
                padding-left: 10px;
            }
        }
    </style>
</head>
<body>

<div class="page-wrap">

    <form id="weeklyStandingsForm" method="get" action=""></form>

    <div class="top-controls">
        <div class="top-controls-left">
            <button type="button"
                    class="live-btn<?php echo ($isLiveView ? ' disabled' : ''); ?>"
                    id="liveBtn"
                    onclick="goLiveView()"
                    title="Go to live view"
                    <?php echo ($isLiveView ? 'disabled' : ''); ?>>
                Live
            </button>

            <select name="year" id="year" form="weeklyStandingsForm" aria-label="Year">
                <?php foreach ($availableYearsAsc as $yearOpt): ?>
                    <option value="<?php echo rrsg_h($yearOpt); ?>" <?php echo ($yearOpt === $selectedYear ? 'selected' : ''); ?>>
                        <?php echo rrsg_h($yearOpt); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="race" id="race" form="weeklyStandingsForm" aria-label="Race">
                <option value="">Select Race</option>
                <?php foreach ($pointRacesAsc as $raceOpt): ?>
                    <option value="<?php echo rrsg_h($raceOpt['raceCode']); ?>" <?php echo ($raceOpt['raceCode'] === $selectedRaceCode ? 'selected' : ''); ?>>
                        <?php echo rrsg_h($raceOpt['raceCode'] . ' ' . rrsg_short_race_label((string)$raceOpt['raceName'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="nav-button" id="navPrevBtn" onclick="navigateRace(-1)" title="Previous Race">&lt;&lt;</button>
            <button type="button" class="nav-button" id="navNextBtn" onclick="navigateRace(1)" title="Next Race">&gt;&gt;</button>
        </div>

        <div class="top-controls-right">
            <button type="button"
                    class="details-toggle validation-btn <?php echo rrsg_h($validationButtonClass); ?>"
                    id="detailsToggle"
                    onclick="toggleDetails()"
                    <?php echo ($selectedRace === null ? 'disabled' : ''); ?>>
                Show Validation
            </button>

            <span class="historical-note-slot" id="historicalNoteSlot"><?php echo ($historicalNote !== '' ? rrsg_h($historicalNote) : '&nbsp;'); ?></span>
        </div>
    </div>

    <div class="race-placeholder" id="racePlaceholder" <?php echo ($selectedRace !== null ? 'style="display:none;"' : ''); ?>>
        Select a race to view results
    </div>

    <div id="resultsArea" <?php echo ($selectedRace === null ? 'style="display:none;"' : ''); ?>>
        <div class="details-content" id="detailsContent">
            <div class="details-meta">
                <span class="chunk"><strong>Scoring:</strong> <?php echo rrsg_h($scoreYear . ' / ' . $scoreSegment . ' / ' . $selectedRaceDisplay); ?></span>
                <span class="chunk"><strong>Teams:</strong> <?php echo count($teamRows); ?></span>
                <span class="chunk"><strong>Drivers:</strong> <?php echo rrsg_h($selectedRaceMeta['driverCount']); ?></span>
                <span class="chunk"><strong>Snapshot:</strong> <?php echo rrsg_h($selectedRaceMeta['snapshotFile'] !== '' ? basename($selectedRaceMeta['snapshotFile']) : 'NOT FOUND'); ?></span>
            </div>

            <div class="validation-columns">
                <div class="validation-column">
                    <h3>PASS</h3>
                    <ul>
                        <?php if (empty($validation['pass'])): ?>
                            <li>None</li>
                        <?php else: ?>
                            <?php foreach ($validation['pass'] as $msg): ?>
                                <li><?php echo rrsg_h($msg); ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="validation-column">
                    <h3>WARN</h3>
                    <ul>
                        <?php if (empty($validation['warn'])): ?>
                            <li>None</li>
                        <?php else: ?>
                            <?php foreach ($validation['warn'] as $msg): ?>
                                <li><?php echo rrsg_h($msg); ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="validation-column">
                    <h3>FAIL</h3>
                    <ul>
                        <?php if (empty($validation['fail'])): ?>
                            <li>None</li>
                        <?php else: ?>
                            <?php foreach ($validation['fail'] as $msg): ?>
                                <li><?php echo rrsg_h($msg); ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="debug-title"><?php echo rrsg_h($selectedYear); ?> Debug Race Build Through <?php echo rrsg_h($selectedRaceCode); ?></div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="debug-text-col">Race</th>
                            <th class="col-rank">#</th>
                            <th>Segment</th>
                            <th>Teams</th>
                            <th>Snapshot Used</th>
                            <th class="debug-text-col">Computed Winner</th>
                            <th class="col-score">Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($debugRows)): ?>
                            <tr>
                                <td colspan="7">No debug rows generated.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($debugRows as $row): ?>
                                <tr>
                                    <td class="debug-text-col"><?php echo rrsg_h($row['raceCode'] . ' ' . $row['raceLabel']); ?></td>
                                    <td class="num"><?php echo rrsg_h($row['raceNumber']); ?></td>
                                    <td><?php echo rrsg_h($row['raceSegment']); ?></td>
                                    <td class="num"><?php echo rrsg_h($row['teamsLoaded']); ?></td>
                                    <td><?php echo rrsg_h($row['snapshotBase']); ?></td>
                                    <td class="debug-text-col"><?php echo rrsg_h($row['winnerTeam']); ?></td>
                                    <td class="num"><?php echo rrsg_h($row['winnerPoints']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="report-grid">
            <div class="report-panel">
                <div class="panel-title"><?php echo rrsg_h($selectedYear . ' ' . $selectedRaceCode . ' ' . $selectedRaceMeta['raceLabel']); ?></div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-rank">#</th>
                                <th class="team-col">Team</th>
                                <th class="col-score">Week <?php echo rrsg_h((string)$selectedRaceNumber); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($selectedRaceWeeklyRows)): ?>
                                <tr>
                                    <td colspan="3">No weekly rows generated.</td>
                                </tr>
                            <?php else: ?>
                                <?php $rank = 1; ?>
                                <?php foreach ($selectedRaceWeeklyRows as $row): ?>
                                    <?php
                                    $detailId = 'weekly-detail-' . $rank;
                                    $stripeClass = ($rank % 2 === 1) ? 'stripe-a' : 'stripe-b';
                                    ?>
                                    <tr
                                        class="team-row weekly-click-row <?php echo $stripeClass; ?>"
                                        onclick="toggleWeeklyDetail('<?php echo rrsg_h($detailId); ?>', this)"
                                    >
                                        <td class="num"><?php echo $rank; ?></td>
                                        <td class="team-col"><?php echo rrsg_h($row['teamName']); ?></td>
                                        <td class="num"><?php echo rrsg_h($row['weeklyTotal']); ?></td>
                                    </tr>
                                    <tr class="team-detail-row" id="<?php echo rrsg_h($detailId); ?>" style="display:none;">
                                        <td></td>
                                        <td colspan="2">
                                            <div class="team-detail-wrap">
                                                <?php if ($row['driverA'] !== ''): ?>
                                                    <div class="team-detail-line">
                                                        <div class="team-detail-driver"><?php echo rrsg_h($row['driverA']); ?></div>
                                                        <div class="team-detail-points"><?php echo rrsg_h($row['netA']); ?></div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($row['driverB'] !== ''): ?>
                                                    <div class="team-detail-line">
                                                        <div class="team-detail-driver"><?php echo rrsg_h($row['driverB']); ?></div>
                                                        <div class="team-detail-points"><?php echo rrsg_h($row['netB']); ?></div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($row['driverC'] !== ''): ?>
                                                    <div class="team-detail-line">
                                                        <div class="team-detail-driver"><?php echo rrsg_h($row['driverC']); ?></div>
                                                        <div class="team-detail-points"><?php echo rrsg_h($row['netC']); ?></div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($row['driverD'] !== ''): ?>
                                                    <div class="team-detail-line">
                                                        <div class="team-detail-driver"><?php echo rrsg_h($row['driverD']); ?></div>
                                                        <div class="team-detail-points"><?php echo rrsg_h($row['netD']); ?></div>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="team-detail-line team-detail-total">
                                                    <div class="team-detail-driver">Total</div>
                                                    <div class="team-detail-points"><?php echo rrsg_h($row['weeklyTotal']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php $rank++; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="report-panel">
                <div class="panel-title"><?php echo rrsg_h($selectedYear . ' ' . $scoreSegment); ?></div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-rank">#</th>
                                <th class="team-col">Team</th>
                                <th class="col-score"><?php echo rrsg_h($scoreSegment); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($segmentStandings)): ?>
                                <tr>
                                    <td colspan="3">No segment standings generated.</td>
                                </tr>
                            <?php else: ?>
                                <?php $rank = 1; ?>
                                <?php foreach ($segmentStandings as $row): ?>
                                    <?php $detailId = 'segment-detail-' . $rank; ?>
                                    <tr
                                        class="team-row weekly-click-row"
                                        onclick="toggleWeeklyDetail('<?php echo rrsg_h($detailId); ?>', this)"
                                    >
                                        <td class="num"><?php echo $rank; ?></td>
                                        <td class="team-col"><?php echo rrsg_h($row['teamName']); ?></td>
                                        <td class="num"><?php echo rrsg_h($row['total']); ?></td>
                                    </tr>
                                    <tr class="team-detail-row" id="<?php echo rrsg_h($detailId); ?>" style="display:none;">
                                        <td></td>
                                        <td colspan="2">
                                            <div class="team-detail-wrap">
                                                <?php foreach ($segmentBreakdownRows as $segmentRaceRow): ?>
                                                    <?php
                                                    $teamRacePoints = 0;
                                                    foreach ($segmentRaceRow['weeklyRows'] as $weeklyRow) {
                                                        if ((string)$weeklyRow['teamName'] === (string)$row['teamName']) {
                                                            $teamRacePoints = (int)$weeklyRow['weeklyTotal'];
                                                            break;
                                                        }
                                                    }
                                                    ?>
                                                    <div class="team-detail-line">
                                                        <div class="team-detail-driver team-detail-label-wrap">
                                                            <?php echo rrsg_h($segmentRaceRow['raceCode'] . ' ' . $segmentRaceRow['raceLabel']); ?>
                                                        </div>
                                                        <div class="team-detail-points"><?php echo rrsg_h($teamRacePoints); ?></div>
                                                    </div>
                                                <?php endforeach; ?>

                                                <div class="team-detail-line team-detail-total">
                                                    <div class="team-detail-driver">Total</div>
                                                    <div class="team-detail-points"><?php echo rrsg_h($row['total']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php $rank++; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="report-panel">
                <div class="panel-title"><?php echo rrsg_h($selectedYear); ?></div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-rank">#</th>
                                <th class="team-col">Team</th>
                                <th class="col-score"><?php echo rrsg_h($selectedYear); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($seasonStandings)): ?>
                                <tr>
                                    <td colspan="3">No season standings generated.</td>
                                </tr>
                            <?php else: ?>
                                <?php $rank = 1; ?>
                                <?php foreach ($seasonStandings as $row): ?>
                                    <?php $detailId = 'year-detail-' . $rank; ?>
                                    <tr
                                        class="team-row weekly-click-row"
                                        onclick="toggleWeeklyDetail('<?php echo rrsg_h($detailId); ?>', this)"
                                    >
                                        <td class="num"><?php echo $rank; ?></td>
                                        <td class="team-col"><?php echo rrsg_h($row['teamName']); ?></td>
                                        <td class="num"><?php echo rrsg_h($row['total']); ?></td>
                                    </tr>
                                    <tr class="team-detail-row" id="<?php echo rrsg_h($detailId); ?>" style="display:none;">
                                        <td></td>
                                        <td colspan="2">
                                            <div class="team-detail-wrap">
                                                <?php foreach ($visibleSegments as $segmentLabel): ?>
                                                    <?php
                                                    $segmentValue = 0;
                                                    if (isset($segmentHistory[$row['teamName']][$segmentLabel])) {
                                                        $segmentValue = (int)$segmentHistory[$row['teamName']][$segmentLabel];
                                                    }
                                                    ?>
                                                    <div class="team-detail-line">
                                                        <div class="team-detail-driver"><?php echo rrsg_h($segmentLabel); ?></div>
                                                        <div class="team-detail-points"><?php echo rrsg_h($segmentValue); ?></div>
                                                    </div>
                                                <?php endforeach; ?>

                                                <div class="team-detail-line team-detail-total">
                                                    <div class="team-detail-driver">Total</div>
                                                    <div class="team-detail-points"><?php echo rrsg_h($row['total']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php $rank++; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="report-panel">
                <div class="panel-title"><?php echo rrsg_h($selectedYear . ' Weekly Winners'); ?></div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-week">Week</th>
                                <th class="team-col">Winner</th>
                                <th class="col-score">Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($selectedRace === null): ?>
                                <tr>
                                    <td colspan="3">No race selected.</td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $winnerRows = $pointRaces;
                                usort($winnerRows, function ($a, $b) {
                                    return ((int)$a['number']) <=> ((int)$b['number']);
                                });
                                ?>
                                <?php foreach ($winnerRows as $race): ?>
                                    <?php if ((int)$race['number'] > $selectedRaceNumber) continue; ?>
                                    <?php $raceCode = (string)$race['raceCode']; ?>
                                    <?php
                                    $winnerNames = $weeklyWinners[$raceCode]['teamNames'] ?? [];
                                    $winnerPoints = (int)($weeklyWinners[$raceCode]['points'] ?? 0);
                                    $winnerWeekDisplay = (string)$race['number'];

                                    if (count($winnerNames) > 1) {
                                        $winnerWeekDisplay .= '*';
                                    }

                                    if (empty($winnerNames)) {
                                        $winnerNames = [];
                                        $fallbackWinner = (string)($weeklyWinners[$raceCode]['teamName'] ?? '');
                                        if ($fallbackWinner !== '') {
                                            $winnerNames[] = $fallbackWinner;
                                        }
                                    }
                                    ?>

                                    <?php if (empty($winnerNames)): ?>
                                        <tr>
                                            <td class="num"><?php echo rrsg_h($winnerWeekDisplay); ?></td>
                                            <td class="team-col"></td>
                                            <td class="num">0</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($winnerNames as $winnerName): ?>
                                            <tr>
                                                <td class="num"><?php echo rrsg_h($winnerWeekDisplay); ?></td>
                                                <td class="team-col"><?php echo rrsg_h($winnerName); ?></td>
                                                <td class="num"><?php echo rrsg_h($winnerPoints); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($selectedRace !== null && $weeklyWinnersHasTie): ?>
                    <div class="winner-footnote">* Tie</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
var rrsgYearRaceOptions = <?php echo json_encode($yearRaceOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var rrsgLiveUrl = <?php echo json_encode($liveUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var rrsgInitialLoad = true;

function rrsgPadRaceCode(num) {
    var n = parseInt(num, 10);
    if (isNaN(n)) {
        return '';
    }
    return 'R' + ('0' + n).slice(-2);
}

function rrsgRaceNumberFromCode(code) {
    var match = String(code || '').match(/^R(\d+)$/);
    return match ? parseInt(match[1], 10) : null;
}

function goLiveView() {
    if (!rrsgLiveUrl) {
        return;
    }

    window.location.href = rrsgLiveUrl;
}

function repopulateRaceOptions() {
    var yearEl = document.getElementById('year');
    var raceEl = document.getElementById('race');
    var yearVal = yearEl ? yearEl.value : '';
    var raceList = rrsgYearRaceOptions[yearVal] || [];
    var i;
    var opt;

    if (!raceEl) {
        return;
    }

    raceEl.innerHTML = '';

    opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'Select Race';
    raceEl.appendChild(opt);

    for (i = 0; i < raceList.length; i++) {
        opt = document.createElement('option');
        opt.value = raceList[i].raceCode;
        opt.textContent = raceList[i].label;
        raceEl.appendChild(opt);
    }

    raceEl.value = '';
    updateNavButtons();
}

function setNoRaceSelectedState() {
    var detailsEl = document.getElementById('detailsContent');
    var detailsBtn = document.getElementById('detailsToggle');
    var resultsArea = document.getElementById('resultsArea');
    var placeholderEl = document.getElementById('racePlaceholder');
    var noteEl = document.getElementById('historicalNoteSlot');

    if (detailsEl) {
        detailsEl.style.display = 'none';
    }

    if (detailsBtn) {
        detailsBtn.textContent = 'Show Validation';
        detailsBtn.disabled = true;
        detailsBtn.classList.remove('status-pass', 'status-warn', 'status-fail');
        detailsBtn.classList.add('status-neutral');
    }

    if (resultsArea) {
        resultsArea.style.display = 'none';
    }

    if (placeholderEl) {
        placeholderEl.style.display = 'block';
    }

    if (noteEl) {
        noteEl.innerHTML = '&nbsp;';
    }
}

function updateNavButtons() {
    var raceEl = document.getElementById('race');
    var prevBtn = document.getElementById('navPrevBtn');
    var nextBtn = document.getElementById('navNextBtn');
    var optionNumbers = [];
    var currentNumber = rrsgRaceNumberFromCode(raceEl ? raceEl.value : '');
    var i;
    var idx = -1;

    if (!raceEl || !prevBtn || !nextBtn) {
        return;
    }

    for (i = 0; i < raceEl.options.length; i++) {
        var raceNum = rrsgRaceNumberFromCode(raceEl.options[i].value);
        if (raceNum !== null) {
            optionNumbers.push(raceNum);
        }
    }

    optionNumbers.sort(function (a, b) {
        return a - b;
    });

    for (i = 0; i < optionNumbers.length; i++) {
        if (optionNumbers[i] === currentNumber) {
            idx = i;
            break;
        }
    }

    prevBtn.disabled = (idx <= 0);
    nextBtn.disabled = (idx < 0 || idx >= optionNumbers.length - 1);
}

function navigateRace(direction) {
    var raceEl = document.getElementById('race');
    var formEl = document.getElementById('weeklyStandingsForm');
    var optionNumbers = [];
    var currentNumber = rrsgRaceNumberFromCode(raceEl ? raceEl.value : '');
    var i;
    var idx = -1;
    var targetNumber;

    if (!raceEl || !formEl) {
        return;
    }

    for (i = 0; i < raceEl.options.length; i++) {
        var raceNum = rrsgRaceNumberFromCode(raceEl.options[i].value);
        if (raceNum !== null) {
            optionNumbers.push(raceNum);
        }
    }

    optionNumbers.sort(function (a, b) {
        return a - b;
    });

    for (i = 0; i < optionNumbers.length; i++) {
        if (optionNumbers[i] === currentNumber) {
            idx = i;
            break;
        }
    }

    if (idx < 0) {
        return;
    }

    targetNumber = optionNumbers[idx + direction];
    if (typeof targetNumber === 'undefined') {
        return;
    }

    raceEl.value = rrsgPadRaceCode(targetNumber);
    formEl.submit();
}

function toggleDetails() {
    var details = document.getElementById('detailsContent');
    var button = document.getElementById('detailsToggle');

    if (!details || !button) {
        return;
    }

    if (details.style.display === 'none' || details.style.display === '') {
        details.style.display = 'block';
        button.textContent = 'Hide Validation';
    } else {
        details.style.display = 'none';
        button.textContent = 'Show Validation';
    }
}

function toggleWeeklyDetail(detailId, rowEl) {
    var rows = document.getElementsByClassName('team-detail-row');
    var target = document.getElementById(detailId);
    var isOpen = false;
    var i;

    if (!target || !rowEl) {
        return;
    }

    isOpen = (target.style.display !== 'none' && target.style.display !== '');

    for (i = 0; i < rows.length; i++) {
        rows[i].style.display = 'none';
    }

    if (!isOpen) {
        target.style.display = 'table-row';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var yearEl = document.getElementById('year');
    var raceEl = document.getElementById('race');
    var detailsEl = document.getElementById('detailsContent');
    var detailsBtn = document.getElementById('detailsToggle');

    if (yearEl) {
        yearEl.addEventListener('change', function () {
            repopulateRaceOptions();
            setNoRaceSelectedState();
            rrsgInitialLoad = false;
        });
    }

    if (raceEl) {
        raceEl.addEventListener('change', function () {
            updateNavButtons();

            if (raceEl.value === '') {
                if (!rrsgInitialLoad) {
                    setNoRaceSelectedState();
                }
                return;
            }

            if (detailsEl) {
                detailsEl.style.display = 'none';
            }

            if (detailsBtn) {
                detailsBtn.textContent = 'Show Validation';
            }

            rrsgInitialLoad = false;
            document.getElementById('weeklyStandingsForm').submit();
        });
    }

    if (detailsEl) {
        detailsEl.style.display = 'none';
    }

    if (detailsBtn) {
        detailsBtn.textContent = 'Show Validation';
    }

    updateNavButtons();
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/footer-light.php'; ?>
</body>
</html>
