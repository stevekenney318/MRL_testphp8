<?php
declare(strict_types=1);

/**
 * test_lp_helper.php
 *
 * VERSION: v001
 * LAST MODIFIED: 3/31/2026 10:28:00 pm
 *
 * DESCRIPTION:
 * Simple browser test page for race_schedule_helper.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';

// disableCaching() defined in functions_mrl.php
disableCaching();

// visual id of a sandbox file - SK & background
require_once $_SERVER['DOCUMENT_ROOT'] . '/sandbox.html';

date_default_timezone_set('America/New_York');

echo '<pre>';
echo 'RUN TS: ' . date('Y-m-d H:i:s') . "\n\n";

try {
    $helperFile = __DIR__ . '/race_schedule_helper.php';

    echo 'HELPER FILE: ' . $helperFile . "\n";

    if (!is_file($helperFile)) {
        throw new RuntimeException('Helper file not found.');
    }

    require_once $helperFile;

    $year = 2026;
    $segment = 'S1';

    echo 'YEAR: ' . $year . "\n";
    echo 'SEGMENT: ' . $segment . "\n\n";

    $race = mrl_get_effective_race_for_lp($year, $segment);

    if ($race === null) {
        echo "No future LP-effective race remains in this segment.\n";
    } else {
        print_r($race);
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    echo 'FILE: ' . $e->getFile() . "\n";
    echo 'LINE: ' . $e->getLine() . "\n";
}
echo '</pre>';
