<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once __DIR__ . '/race_results_team_helper.php';

$year = '2026';
$segment = 'S1';

$rows = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $year, $segment);

echo "<pre>";

echo "Loaded rows: " . count($rows) . "\n\n";

foreach ($rows as $r) {
    echo $r['teamName'] . "\n";
    echo "  A: " . $r['driverA'] . "\n";
    echo "  B: " . $r['driverB'] . "\n";
    echo "  C: " . $r['driverC'] . "\n";
    echo "  D: " . $r['driverD'] . "\n\n";
}

echo "</pre>";