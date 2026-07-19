<?php
declare(strict_types=1);

/**
 * race_schedule_snapshot.php
 *
 * VERSION: v008
 * LAST MODIFIED: 3/30/2026 10:28:00 pm
 *
 * DESCRIPTION:
 * Minimal known-good baseline for /race_results/race_schedule_snapshot.php.
 * This file is only for restoring a working page and confirming the startup
 * block and environment are still good before adding logic back in.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';

// disableCaching() defined in functions_mrl.php
disableCaching();

// visual id of a sandbox file - SK & background
require_once $_SERVER['DOCUMENT_ROOT'] . '/sandbox.html';

date_default_timezone_set('America/New_York');

echo "<pre>";
echo "FILE: " . basename(__FILE__) . "\n";
echo "VERSION: v008\n";
echo "STATUS: baseline loaded successfully\n";
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? '') . "\n";
echo "ACTIVE YEAR: " . (isset($raceYear) ? (string)$raceYear : '(not set)') . "\n";
echo "DONE\n";
