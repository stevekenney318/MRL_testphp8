<?php
declare(strict_types=1);

/**
 * mrl_nascar_live_cache.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/21/2026 5:44:47 pm
 *
 * DESCRIPTION:
 * Standalone updater for MRL's optional NASCAR At a Glance cache.
 * The scheduler patch calls this helper only after race_results_monitor runs.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

$baseDir = __DIR__;
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;

if ($year <= 0 && PHP_SAPI === 'cli' && isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        if (preg_match('/^\d{4}$/', (string)$arg)) {
            $year = (int)$arg;
            break;
        }
    }
}

if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

require_once $baseDir . '/mrl_nascar_live_helper.php';

$status = mrl_nascar_live_update_cache($baseDir, $year, 6);

$isCli = (PHP_SAPI === 'cli');
if (!$isCli && !headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

echo 'MRL NASCAR Live Cache' . PHP_EOL;
echo 'Generated: ' . date('n/j/Y g:i:s a') . PHP_EOL;
echo 'Year: ' . (string)$year . PHP_EOL;
echo 'OK: ' . (!empty($status['ok']) ? 'YES' : 'NO') . PHP_EOL;
echo 'Message: ' . (string)($status['message'] ?? '') . PHP_EOL;
echo 'Race: ' . (string)($status['race_name'] ?? '') . PHP_EOL;
echo 'Flag: ' . (string)($status['flag_label'] ?? '') . PHP_EOL;
echo 'Stage: ' . (string)($status['stage_label'] ?? '') . PHP_EOL;
echo 'Laps: ' . (string)($status['lap_label'] ?? '') . PHP_EOL;
echo 'Elapsed: ' . (string)($status['elapsed_label'] ?? '') . PHP_EOL;
echo 'Cache: ' . (string)($status['cache_path'] ?? '') . PHP_EOL;
echo 'Raw Cache: ' . (string)($status['raw_cache_path'] ?? '') . PHP_EOL;
