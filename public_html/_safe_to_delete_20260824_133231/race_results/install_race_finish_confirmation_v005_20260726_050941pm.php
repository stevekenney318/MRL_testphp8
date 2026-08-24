<?php
declare(strict_types=1);
/**
 * install_race_finish_confirmation_v005_20260726_050941pm.php
 * VERSION: v001
 * LAST MODIFIED: 7/26/2026 5:09:41 pm
 * PHP: 7.3 compatible.
 */
date_default_timezone_set('America/New_York');
header('Content-Type: text/plain; charset=UTF-8');

$target = __DIR__ . '/race_finish_confirmation_monitor.php';
$backupDir = __DIR__ . '/_race_finish_confirmation_install_backup_20260726_050941pm';
$backupFile = $backupDir . '/race_finish_confirmation_monitor.php';

echo "MRL Race Finish Confirmation installer v005\n";
echo "Base: " . __DIR__ . "\n\n";

if (!is_file($target)) exit("ERROR: monitor file not found. Nothing changed.\n");
$source = @file_get_contents($target);
if (!is_string($source) || $source === '') exit("ERROR: monitor file could not be read. Nothing changed.\n");
if (strpos($source, "const RFCM_VERSION = 'v005';") !== false) exit("ALREADY INSTALLED: monitor is v005.\n");

$required = [
    " * VERSION: v004",
    " * LAST MODIFIED: 7/26/2026 9:22:59 am",
    "const RFCM_VERSION = 'v004';",
    "const RFCM_SIGNATURE = 'MRL_RACE_FINISH_CONFIRMATION_MONITOR v004';",
    "if (!$finishWindow) {"
];
foreach ($required as $pattern) {
    if (strpos($source, $pattern) === false) exit("ERROR: expected v004 pattern missing:\n" . $pattern . "\nNothing changed.\n");
}

if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) exit("ERROR: backup directory could not be created.\n");
if (!@copy($target, $backupFile)) exit("ERROR: backup failed. Nothing changed.\n");
echo "BACKUP: race_finish_confirmation_monitor.php\n";

$source = str_replace(" * VERSION: v004", " * VERSION: v005", $source, $c1);
$source = str_replace(" * LAST MODIFIED: 7/26/2026 9:22:59 am", " * LAST MODIFIED: 7/26/2026 5:09:41 pm", $source, $c2);
$source = str_replace(
    " * CHANGELOG:\n",
    " * CHANGELOG:\n"
    . " * v005 (7/26/2026 5:09:41 pm)\n"
    . " * - Saves current MRL/ESPN race and lap progress below the 90% finish-watch threshold.\n"
    . " * - Keeps NASCAR Flag dependent on NASCAR live-source data.\n"
    . " * - Keeps Finish Watch waiting and does not contact Racing-Reference or Jayski early.\n"
    . " *\n",
    $source,
    $c3
);
$source = str_replace("const RFCM_VERSION = 'v004';", "const RFCM_VERSION = 'v005';", $source, $c4);
$source = str_replace(
    "const RFCM_SIGNATURE = 'MRL_RACE_FINISH_CONFIRMATION_MONITOR v004';",
    "const RFCM_SIGNATURE = 'MRL_RACE_FINISH_CONFIRMATION_MONITOR v005';",
    $source,
    $c5
);

$needle = <<<'OLD'
    $idleState = [
        'signature' => RFCM_SIGNATURE . ' STATE',
        'version' => RFCM_VERSION,
        'updated_at' => $checkedAt,
        'finish_watch_active' => false,
        'idle_reason' => $idleReason,
        'last_progress_percent' => $progress,
        'last_flag_label' => $flagLabel,
        'last_status_source' => (string)($nascar['status_source'] ?? ''),
        'last_status_checked_at' => (string)($nascar['source_generated_at'] ?? ''),
        'last_raw_hashes' => isset($state['last_raw_hashes']) && is_array($state['last_raw_hashes']) ? $state['last_raw_hashes'] : [],
    ];
OLD;

$replacement = <<<'NEW'
    /*
     * v005: publish a lightweight dashboard observation below 90%.
     * No Racing-Reference or Jayski requests are made in this path.
     */
    $idleObservation = [
        'signature' => RFCM_SIGNATURE,
        'version' => RFCM_VERSION,
        'observation_id' => 'idle_' . date('Ymd_His'),
        'checked_at' => $checkedAt,
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        'year' => $year,
        'finish_watch_start_percent' => $activationPercent,
        'finish_watch_active' => false,
        'race' => [
            'race_id' => (int)($nascar['race_id'] ?? 0),
            'series_id' => (int)($nascar['series_id'] ?? 0),
            'race_name' => (string)($raceIdentity['race_name'] ?? $nascar['race_name'] ?? ''),
            'track_name' => (string)($raceIdentity['track_name'] ?? $nascar['track_name'] ?? ''),
            'lap_number' => (int)($nascar['lap_number'] ?? 0),
            'laps_in_race' => (int)($nascar['laps_in_race'] ?? 0),
            'progress_percent' => $progress,
            'flag_label' => $flagLabel,
            'mrl_race_number' => (int)($raceIdentity['race_number'] ?? 0),
            'mrl_race_code' => (string)($raceIdentity['race_code'] ?? ''),
            'status_source' => (string)($nascar['status_source'] ?? ''),
            'source_generated_at' => (string)($nascar['source_generated_at'] ?? ''),
            'source_age_minutes' => $nascar['source_age_minutes'] ?? null,
            'required_nascar_series_id' => $requiredSeriesId,
            'nascar_series_match' => $isRequiredSeries,
        ],
        'sources' => [
            'mrl' => $mrlStatus,
            'nascar' => $nascar,
            'racing_reference' => [
                'status' => 'waiting',
                'message' => 'Finish watch has not reached the 90% activation threshold.',
                'checked_at' => 'Never',
                'race_results_posted' => false,
                'waiting_phrase_present' => false,
                'driver_result_rows' => 0,
                'season_row_completed' => false,
            ],
            'jayski' => [
                'status' => 'waiting',
                'message' => 'Finish watch has not reached the 90% activation threshold.',
                'checked_at' => 'Never',
                'winner' => 'Not populated',
                'results_link_found' => false,
                'results_page_posted' => false,
            ],
        ],
        'observation_only' => true,
        'decision' => 'NONE',
        'idle_reason' => $idleReason,
        'notes' => [
            'Dashboard status only; no secondary-source requests were made.',
        ],
    ];
    rfcm_write_json_atomic($latestFile, $idleObservation);

    $idleState = [
        'signature' => RFCM_SIGNATURE . ' STATE',
        'version' => RFCM_VERSION,
        'updated_at' => $checkedAt,
        'finish_watch_active' => false,
        'idle_reason' => $idleReason,
        'last_progress_percent' => $progress,
        'last_flag_label' => $flagLabel,
        'last_status_source' => (string)($nascar['status_source'] ?? ''),
        'last_status_checked_at' => (string)($nascar['source_generated_at'] ?? ''),
        'last_raw_hashes' => isset($state['last_raw_hashes']) && is_array($state['last_raw_hashes']) ? $state['last_raw_hashes'] : [],
    ];
NEW;

$source = str_replace($needle, $replacement, $source, $c6);

if ($c1 !== 1 || $c2 !== 1 || $c3 !== 1 || $c4 !== 1 || $c5 !== 1 || $c6 !== 1) {
    @copy($backupFile, $target);
    exit("ERROR: unexpected update counts. Original restored.\n"
        . "version=$c1 modified=$c2 changelog=$c3 const=$c4 signature=$c5 idle=$c6\n");
}

$tmp = $target . '.tmp_' . bin2hex(random_bytes(4));
if (@file_put_contents($tmp, $source, LOCK_EX) === false) {
    @unlink($tmp);
    @copy($backupFile, $target);
    exit("ERROR: temporary write failed. Original restored.\n");
}
if (!@rename($tmp, $target)) {
    @unlink($tmp);
    @copy($backupFile, $target);
    exit("ERROR: install failed. Original restored.\n");
}

echo "UPDATED: race_finish_confirmation_monitor.php\n";
echo "VERSION: v005\n";
echo "CHANGE: Race and Lap Progress populate below 90%.\n";
echo "UNCHANGED: NASCAR Flag uses NASCAR live-source data.\n";
echo "UNCHANGED: Finish Watch remains WAITING below 90%.\n";
echo "UNCHANGED: Racing-Reference and Jayski are not contacted early.\n\n";
echo "SUCCESS\n";
echo "Backup: " . $backupDir . "\n";
echo "Run observation now once, then refresh the dashboard.\n";
echo "Delete this installer after verification.\n";
