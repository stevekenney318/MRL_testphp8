<?php
declare(strict_types=1);

/**
 * test_rd_helper.php
 *
 * VERSION: v002
 * LAST MODIFIED: 4/2/2026 11:45:30 pm
 *
 * DESCRIPTION:
 * Simple browser test page for race_results_rd_helper.php.
 * Confirms DB-driven segment bounds, race lists, and next-race logic.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';

// disableCaching() defined in functions_mrl.php
disableCaching();

// visual id of a sandbox file - SK & background
require_once $_SERVER['DOCUMENT_ROOT'] . '/sandbox.html';

require_once __DIR__ . '/race_results_rd_helper.php';

date_default_timezone_set('America/New_York');

echo '<pre>';
echo 'RUN TS: ' . date('Y-m-d H:i:s') . "\n\n";

try {
    if (!isset($dbo) || !($dbo instanceof PDO)) {
        throw new RuntimeException('PDO connection $dbo is not available.');
    }

    $activeRaceYear = isset($_GET['year']) && ctype_digit((string)$_GET['year'])
        ? (int)$_GET['year']
        : (isset($raceYear) ? (int)$raceYear : 2026);

    echo 'FILE: ' . basename(__FILE__) . "\n";
    echo 'VERSION: v002' . "\n";
    echo 'RACE YEAR: ' . $activeRaceYear . "\n\n";

    $segments = ['S1', 'S2', 'S3', 'S4'];

    foreach ($segments as $segment) {
        echo '----------------------------------------' . "\n";
        echo 'SEGMENT: ' . $segment . "\n";

        $bounds = mrl_rd_get_segment_bounds($dbo, $activeRaceYear, $segment);
        echo 'BOUNDS: ' . (int)$bounds['start'] . ' to ' . (int)$bounds['end'] . "\n";

        $raceNumbers = mrl_rd_segment_race_numbers($dbo, $activeRaceYear, $segment);
        echo 'RACES: ' . implode(', ', $raceNumbers) . "\n";

        $lastRace = !empty($raceNumbers) ? (int)end($raceNumbers) : 0;
        if ($lastRace > 0) {
            $nextRace = mrl_rd_next_race_in_segment($dbo, $activeRaceYear, $segment, $lastRace);
            echo 'NEXT AFTER ' . $lastRace . ': ' . ($nextRace === null ? 'NULL' : (string)$nextRace) . "\n";
        }

        echo "\n";
    }

    echo 'DONE' . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    echo 'FILE: ' . $e->getFile() . "\n";
    echo 'LINE: ' . $e->getLine() . "\n";
}

echo '</pre>';
