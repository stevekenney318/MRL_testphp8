<?php
declare(strict_types=1);

require_once __DIR__ . '/race_results_snapshot_helper.php';

$file = __DIR__ . '/2026/R04_NASCAR_Cup_Series_at_Phoenix_202603080023/snapshot_20260308_205601920.html';

$rows = rrs_load_snapshot_driver_points($file);

echo "<pre>";
echo "Loaded drivers: " . count($rows) . "\n\n";

$show = [
    'Ryan Blaney',
    'Christopher Bell',
    'Chris Buescher',
    'Ross Chastain',
    'Chase Briscoe',
    'Alex Bowman',
    'Austin Hill',
];

foreach ($show as $name) {
    if (isset($rows[$name])) {
        echo $name . "\n";
        echo "  pts: " . $rows[$name]['pts'] . "\n";
        echo "  bonus: " . $rows[$name]['bonus'] . "\n";
        echo "  penalty: " . $rows[$name]['penalty'] . "\n";
        echo "  net: " . $rows[$name]['net'] . "\n\n";
    } else {
        echo $name . "\n";
        echo "  NOT FOUND\n\n";
    }
}

echo "</pre>";