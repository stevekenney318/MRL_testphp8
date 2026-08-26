<?php
/**
 * MRL Live race_results Consolidation Installer
 *
 * VERSION: v002
 * LAST MODIFIED: 8/26/2026 1:04:30 am
 *
 * CHANGELOG:
 * v002 (8/26/2026 1:04:30 am)
 * - Added JSON export for the preview/preflight result.
 * - Added visible detail table for every unexpected active race_finish_confirmation reference.
 * - Hardened active-reference scanning so this installer ignores itself by exact basename.
 * - Keeps INSTALL disabled whenever any unexpected reference remains.
 *
 * v001 (8/25/2026 7:48:57 pm)
 * - Initial controlled TESTPHP8 -> LIVE race_results consolidation installer.
 * - Migrates the three verified TESTPHP8 code files as one dependency unit:
 *     race_results_monitor.php v139
 *     race_results_rd_helper.php v005
 *     race_schedule_helper.php v003
 * - Retires the old LIVE race_finish_confirmation experiment from active use:
 *     removes its scheduler task from _scheduler/schedule.json
 *     removes its race_results_menu.php reference
 *     archives the monitor/dashboard PHP files
 * - Preserves LIVE runtime/history/snapshot/state files.
 * - Preserves the full _race_finish_confirmation historical data directory in place.
 * - Preserves scheduler log/state history.
 * - Creates a timestamped backup before changing anything.
 * - Verifies exact TEST source hashes and exact LIVE baseline hashes before applying.
 * - Performs post-install hash/reference verification.
 * - Preview-first: no changes are made unless the explicit INSTALL button is used.
 *
 * IMPORTANT OPERATING PROCEDURE:
 * 1. Upload this file to /public_html/testphp8/race_results/
 * 2. Open it and review PREVIEW.
 * 3. STOP/DISABLE the Hostinger master scheduler cron before clicking INSTALL.
 * 4. Click INSTALL once.
 * 5. Verify SUCCESS.
 * 6. Re-enable the master scheduler cron.
 *
 * SAFETY:
 * - Refuses to run from the wrong directory.
 * - Refuses to install if TEST source hashes changed.
 * - Refuses to install if LIVE target baselines drifted.
 * - Refuses to modify unreadable/invalid scheduler JSON.
 * - Backs up every modified/replaced/archived file first.
 * - Does not touch LIVE race data, snapshots, runtime state/history, standings releases,
 *   revision history, NASCAR live status, scheduler state/logs, or the historical
 *   _race_finish_confirmation data directory.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

const MRL_CONSOLIDATION_VERSION = 'v002';

function mrlc_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlc_norm(string $p): string
{
    return str_replace('\\', '/', $p);
}

function mrlc_read(string $p): string
{
    if (!is_file($p) || !is_readable($p)) {
        return '';
    }
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function mrlc_sha(string $p): string
{
    if (!is_file($p) || !is_readable($p)) {
        return '';
    }
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function mrlc_version(string $p): string
{
    $t = mrlc_read($p);
    if ($t === '') {
        return '';
    }

    foreach ([
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/',
    ] as $rx) {
        if (preg_match($rx, $t, $m)) {
            return strtolower((string)$m[1]);
        }
    }
    return '';
}

function mrlc_info(string $p): array
{
    $exists = is_file($p);
    return [
        'path' => mrlc_norm($p),
        'exists' => $exists,
        'readable' => $exists && is_readable($p),
        'version' => $exists ? mrlc_version($p) : '',
        'size' => $exists ? @filesize($p) : null,
        'sha256' => $exists ? mrlc_sha($p) : '',
        'mtime' => $exists && is_int(@filemtime($p)) ? date('Y-m-d H:i:s T', (int)@filemtime($p)) : null,
    ];
}

function mrlc_mkdir(string $dir): bool
{
    return is_dir($dir) || @mkdir($dir, 0755, true);
}

function mrlc_copy_preserve(string $src, string $dst): bool
{
    if (!is_file($src) || !is_readable($src)) {
        return false;
    }
    if (!mrlc_mkdir(dirname($dst))) {
        return false;
    }
    if (!@copy($src, $dst)) {
        return false;
    }

    $mtime = @filemtime($src);
    if (is_int($mtime)) {
        @touch($dst, $mtime);
    }
    $perms = @fileperms($src);
    if (is_int($perms)) {
        @chmod($dst, $perms & 0777);
    }
    return true;
}

function mrlc_write_atomic(string $path, string $content): bool
{
    $dir = dirname($path);
    if (!mrlc_mkdir($dir)) {
        return false;
    }

    $tmp = $path . '.tmp_' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    $oldPerms = is_file($path) ? @fileperms($path) : false;

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    if (is_int($oldPerms)) {
        @chmod($path, $oldPerms & 0777);
    } else {
        @chmod($path, 0644);
    }

    return true;
}

function mrlc_json_decode_file(string $path, ?string &$error = null): ?array
{
    $error = null;
    $text = mrlc_read($path);
    if ($text === '') {
        $error = 'Missing, unreadable, or empty JSON file.';
        return null;
    }
    $data = json_decode($text, true);
    if (!is_array($data)) {
        $error = 'JSON parse error: ' . json_last_error_msg();
        return null;
    }
    return $data;
}

function mrlc_json_encode_pretty(array $data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json . "\n" : '';
}

function mrlc_remove_menu_reference(string $content, int &$removed): string
{
    $removed = 0;
    $lines = preg_split('/\R/', $content);
    if (!is_array($lines)) {
        return $content;
    }

    $out = [];
    foreach ($lines as $line) {
        if (strpos($line, "'race_finish_confirmation_monitor.php'") !== false
            || strpos($line, '"race_finish_confirmation_monitor.php"') !== false) {
            $removed++;
            continue;
        }
        $out[] = $line;
    }

    return implode("\n", $out) . "\n";
}

function mrlc_scan_unexpected_active_refs(string $root): array
{
    $hits = [];
    if (!is_dir($root)) {
        return $hits;
    }

    $skip = [
        'race_finish_confirmation_monitor.php',
        'race_finish_confirmation_dashboard.php',
    ];

    $entries = @scandir($root);
    if (!is_array($entries)) {
        return $hits;
    }

    $selfBase = basename(__FILE__);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || substr(strtolower($entry), -4) !== '.php') {
            continue;
        }
        if ($entry === $selfBase) {
            continue;
        }
        if (in_array($entry, $skip, true)) {
            continue;
        }
        if (preg_match('/install|backup|\.bak|probe|diagnostic|audit|preflight|cleanup|debug/i', $entry)) {
            continue;
        }

        $p = $root . '/' . $entry;
        if (!is_file($p)) {
            continue;
        }
        $text = mrlc_read($p);
        if ($text === '') {
            continue;
        }

        $lines = preg_split('/\R/', $text);
        if (!is_array($lines)) {
            continue;
        }

        foreach ($lines as $i => $line) {
            if (stripos($line, 'race_finish_confirmation') !== false) {
                $hits[] = [
                    'path' => $entry,
                    'line' => $i + 1,
                    'text' => trim($line),
                ];
            }
        }
    }

    return $hits;
}

function mrlc_result_row(string $label, string $status, string $detail): array
{
    return ['label' => $label, 'status' => $status, 'detail' => $detail];
}

/* -------------------------------------------------------------------------
 * Environment
 * ---------------------------------------------------------------------- */

$selfDir = rtrim(mrlc_norm(__DIR__), '/');
$expectedDir = (bool)preg_match('#/public_html/testphp8/race_results$#', $selfDir);

$testRoot = $selfDir;
$testPublicRoot = dirname($testRoot);
$publicHtmlRoot = dirname($testPublicRoot);
$liveRoot = $publicHtmlRoot . '/race_results';

$now = date('Y-m-d H:i:s T');
$backupStamp = date('Ymd_His');
$backupRoot = $liveRoot . '/_migration_backups/live_consolidation_' . $backupStamp;
$retiredRoot = $liveRoot . '/_retired/race_finish_confirmation_' . $backupStamp;

/* -------------------------------------------------------------------------
 * Audit baseline from 8/25/2026 7:34:31 pm JSON
 * ---------------------------------------------------------------------- */

$baseline = [
    'race_results_monitor.php' => [
        'test_version' => 'v139',
        'test_sha256' => '5b5a11d6dc9257a88465c08ddf3357fad9c7faacf75e6b56b461bf84a482678e',
        'live_exists' => true,
        'live_version' => 'v138',
        'live_sha256' => '57d5f61289e0b21d3760a3059a838e8fc99f9cfca9f22c19550de5489a919d7a',
    ],
    'race_results_rd_helper.php' => [
        'test_version' => 'v005',
        'test_sha256' => 'f82b6f238ca577018d8e2aa2fb8a89bc925f09d2f4f49602dbceba81d77de08d',
        'live_exists' => true,
        'live_version' => 'v003',
        'live_sha256' => 'a41651346006b57cd0b661bbf99e4e7863dbcd0b53506012878248e164b41d66',
    ],
    'race_schedule_helper.php' => [
        'test_version' => 'v003',
        'test_sha256' => '9ed17b411be162140f73173808e974e0468d3c335872dc0ef31487375933ec62',
        'live_exists' => false,
        'live_version' => '',
        'live_sha256' => '',
    ],
];

$preflight = [];
$preflightOk = $expectedDir;

if (!$expectedDir) {
    $preflight[] = mrlc_result_row(
        'Expected TESTPHP8 location',
        'FAIL',
        'Run from /public_html/testphp8/race_results/. Current: ' . $selfDir
    );
} else {
    $preflight[] = mrlc_result_row('Expected TESTPHP8 location', 'PASS', $selfDir);
}

foreach ($baseline as $file => $exp) {
    $testPath = $testRoot . '/' . $file;
    $livePath = $liveRoot . '/' . $file;

    $testInfo = mrlc_info($testPath);
    $liveInfo = mrlc_info($livePath);

    $testOk = $testInfo['exists']
        && strtolower((string)$testInfo['version']) === strtolower($exp['test_version'])
        && $testInfo['sha256'] === $exp['test_sha256'];

    if ($exp['live_exists']) {
        $liveOk = $liveInfo['exists']
            && strtolower((string)$liveInfo['version']) === strtolower($exp['live_version'])
            && $liveInfo['sha256'] === $exp['live_sha256'];
    } else {
        $liveOk = !$liveInfo['exists'];
    }

    $preflight[] = mrlc_result_row(
        'TEST source ' . $file,
        $testOk ? 'PASS' : 'FAIL',
        $testInfo['exists']
            ? (($testInfo['version'] ?: '(no version)') . ' | ' . $testInfo['sha256'])
            : 'MISSING'
    );
    $preflight[] = mrlc_result_row(
        'LIVE baseline ' . $file,
        $liveOk ? 'PASS' : 'FAIL',
        $liveInfo['exists']
            ? (($liveInfo['version'] ?: '(no version)') . ' | ' . $liveInfo['sha256'])
            : 'ABSENT (expected for new helper)'
    );

    $preflightOk = $preflightOk && $testOk && $liveOk;
}

/* dependency markers */
$monitorText = mrlc_read($testRoot . '/race_results_monitor.php');
$rdText = mrlc_read($testRoot . '/race_results_rd_helper.php');
$schedHelperText = mrlc_read($testRoot . '/race_schedule_helper.php');

$depChecks = [
    [
        'label' => 'Monitor references RD helper',
        'ok' => strpos($monitorText, 'race_results_rd_helper.php') !== false,
    ],
    [
        'label' => 'RD helper references schedule helper',
        'ok' => strpos($rdText, 'race_schedule_helper.php') !== false,
    ],
    [
        'label' => 'Schedule helper uses canonical _race_results_schedule.json',
        'ok' => strpos($schedHelperText, '_race_results_schedule.json') !== false,
    ],
    [
        'label' => 'Schedule helper uses mrl_points_races',
        'ok' => strpos($schedHelperText, 'mrl_points_races') !== false,
    ],
];

foreach ($depChecks as $d) {
    $preflight[] = mrlc_result_row(
        $d['label'],
        $d['ok'] ? 'PASS' : 'FAIL',
        $d['ok'] ? 'Expected reference found.' : 'Expected reference NOT found.'
    );
    $preflightOk = $preflightOk && $d['ok'];
}

/* Live canonical schedule */
$liveSchedulePath = $liveRoot . '/_race_results_schedule.json';
$liveScheduleError = null;
$liveSchedule = mrlc_json_decode_file($liveSchedulePath, $liveScheduleError);
$liveScheduleOk = is_array($liveSchedule)
    && isset($liveSchedule['mrl_points_races'])
    && is_array($liveSchedule['mrl_points_races'])
    && count($liveSchedule['mrl_points_races']) === 36;

$preflight[] = mrlc_result_row(
    'LIVE canonical schedule',
    $liveScheduleOk ? 'PASS' : 'FAIL',
    $liveScheduleOk
        ? 'Valid JSON with 36 mrl_points_races rows.'
        : ($liveScheduleError ?: 'mrl_points_races missing or not 36 rows.')
);
$preflightOk = $preflightOk && $liveScheduleOk;

/* Scheduler schedule */
$schedulerSchedulePath = $liveRoot . '/_scheduler/schedule.json';
$schedulerError = null;
$schedulerData = mrlc_json_decode_file($schedulerSchedulePath, $schedulerError);

$schedulerTaskPresent = is_array($schedulerData)
    && isset($schedulerData['tasks'])
    && is_array($schedulerData['tasks'])
    && isset($schedulerData['tasks']['race_finish_confirmation_monitor']);

$schedulerOk = is_array($schedulerData) && $schedulerTaskPresent;

$preflight[] = mrlc_result_row(
    'LIVE race_finish_confirmation scheduler task',
    $schedulerOk ? 'PASS' : 'FAIL',
    $schedulerOk
        ? 'Active task found and ready for controlled retirement.'
        : ($schedulerError ?: 'Expected task not found.')
);
$preflightOk = $preflightOk && $schedulerOk;

/* Menu */
$menuPath = $liveRoot . '/race_results_menu.php';
$menuText = mrlc_read($menuPath);
$menuRefPresent = strpos($menuText, 'race_finish_confirmation_monitor.php') !== false;

$preflight[] = mrlc_result_row(
    'LIVE race_results_menu.php experiment reference',
    $menuRefPresent ? 'PASS' : 'FAIL',
    $menuRefPresent ? 'Expected reference found and ready for controlled removal.' : 'Expected reference not found.'
);
$preflightOk = $preflightOk && $menuRefPresent;

/* Experiment files */
$finishMonitor = $liveRoot . '/race_finish_confirmation_monitor.php';
$finishDashboard = $liveRoot . '/race_finish_confirmation_dashboard.php';

$finishMonitorOk = is_file($finishMonitor);
$finishDashboardOk = is_file($finishDashboard);

$preflight[] = mrlc_result_row(
    'LIVE race_finish_confirmation_monitor.php',
    $finishMonitorOk ? 'PASS' : 'FAIL',
    $finishMonitorOk ? ('Found ' . mrlc_version($finishMonitor)) : 'MISSING'
);
$preflight[] = mrlc_result_row(
    'LIVE race_finish_confirmation_dashboard.php',
    $finishDashboardOk ? 'PASS' : 'FAIL',
    $finishDashboardOk ? ('Found ' . mrlc_version($finishDashboard)) : 'MISSING'
);

$preflightOk = $preflightOk && $finishMonitorOk && $finishDashboardOk;

/* Historical data must be preserved */
$finishDataDir = $liveRoot . '/_race_finish_confirmation';
$finishDataDirOk = is_dir($finishDataDir);
$preflight[] = mrlc_result_row(
    'Historical _race_finish_confirmation data directory',
    $finishDataDirOk ? 'PASS' : 'FAIL',
    $finishDataDirOk ? 'Present and will remain untouched.' : 'Missing unexpectedly.'
);
$preflightOk = $preflightOk && $finishDataDirOk;

/* Unexpected active references should only be the menu before install */
$refsBefore = mrlc_scan_unexpected_active_refs($liveRoot);
$unexpectedBefore = array_values(array_filter($refsBefore, static function (array $hit): bool {
    return $hit['path'] !== 'race_results_menu.php';
}));

$unexpectedOk = count($unexpectedBefore) === 0;
$preflight[] = mrlc_result_row(
    'Unexpected active race_finish_confirmation references',
    $unexpectedOk ? 'PASS' : 'FAIL',
    $unexpectedOk ? 'None found outside the expected menu reference.' : (count($unexpectedBefore) . ' unexpected reference(s) found.')
);
$preflightOk = $preflightOk && $unexpectedOk;

/* -------------------------------------------------------------------------
 * INSTALL
 * ---------------------------------------------------------------------- */

$installRequested = isset($_POST['confirm_install']) && $_POST['confirm_install'] === 'YES';
$installLog = [];
$installSuccess = false;
$installAttempted = false;

if ($installRequested) {
    $installAttempted = true;

    if (!$preflightOk) {
        $installLog[] = 'STOP: Preflight is not clean. No changes made.';
    } else {
        $ok = true;

        $installLog[] = 'BEGIN INSTALL';
        $installLog[] = 'Timestamp: ' . $now;
        $installLog[] = 'TEST source: ' . $testRoot;
        $installLog[] = 'LIVE target: ' . $liveRoot;
        $installLog[] = 'Backup: ' . $backupRoot;
        $installLog[] = 'Retired files: ' . $retiredRoot;

        if (!mrlc_mkdir($backupRoot)) {
            $ok = false;
            $installLog[] = 'FAIL: Could not create backup directory.';
        }

        /* Backup all files that will be modified/replaced/archived */
        $backupFiles = [
            $liveRoot . '/race_results_monitor.php',
            $liveRoot . '/race_results_rd_helper.php',
            $schedulerSchedulePath,
            $menuPath,
            $finishMonitor,
            $finishDashboard,
        ];

        if ($ok) {
            foreach ($backupFiles as $src) {
                if (!is_file($src)) {
                    $ok = false;
                    $installLog[] = 'FAIL: Expected backup source missing: ' . $src;
                    break;
                }
                $dst = $backupRoot . '/' . basename($src);
                if (!mrlc_copy_preserve($src, $dst)) {
                    $ok = false;
                    $installLog[] = 'FAIL: Backup failed: ' . basename($src);
                    break;
                }
                $installLog[] = 'BACKUP: ' . basename($src);
            }
        }

        /* Preserve a manifest */
        if ($ok) {
            $manifest = [
                'created_at' => $now,
                'version' => MRL_CONSOLIDATION_VERSION,
                'test_root' => mrlc_norm($testRoot),
                'live_root' => mrlc_norm($liveRoot),
                'baseline' => $baseline,
                'preflight' => $preflight,
            ];
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($manifestJson) || @file_put_contents($backupRoot . '/manifest.json', $manifestJson . "\n") === false) {
                $ok = false;
                $installLog[] = 'FAIL: Could not write backup manifest.';
            } else {
                $installLog[] = 'BACKUP MANIFEST: manifest.json';
            }
        }

        /* Copy three verified TEST files to LIVE */
        if ($ok) {
            foreach (array_keys($baseline) as $file) {
                $src = $testRoot . '/' . $file;
                $dst = $liveRoot . '/' . $file;

                if (!mrlc_copy_preserve($src, $dst)) {
                    $ok = false;
                    $installLog[] = 'FAIL: Could not install ' . $file;
                    break;
                }

                if (mrlc_sha($dst) !== $baseline[$file]['test_sha256']) {
                    $ok = false;
                    $installLog[] = 'FAIL: Post-copy hash mismatch for ' . $file;
                    break;
                }

                $installLog[] = 'INSTALLED: ' . $file . ' ' . $baseline[$file]['test_version'];
            }
        }

        /* Remove scheduler task */
        if ($ok) {
            $schedulerErr2 = null;
            $sched = mrlc_json_decode_file($schedulerSchedulePath, $schedulerErr2);
            if (!is_array($sched)
                || !isset($sched['tasks'])
                || !is_array($sched['tasks'])
                || !array_key_exists('race_finish_confirmation_monitor', $sched['tasks'])) {
                $ok = false;
                $installLog[] = 'FAIL: Scheduler task disappeared before modification.';
            } else {
                unset($sched['tasks']['race_finish_confirmation_monitor']);
                $encoded = mrlc_json_encode_pretty($sched);
                if ($encoded === '' || !mrlc_write_atomic($schedulerSchedulePath, $encoded)) {
                    $ok = false;
                    $installLog[] = 'FAIL: Could not update _scheduler/schedule.json';
                } else {
                    $installLog[] = 'RETIRED SCHEDULER TASK: race_finish_confirmation_monitor';
                }
            }
        }

        /* Remove menu reference */
        if ($ok) {
            $menuCurrent = mrlc_read($menuPath);
            $removed = 0;
            $menuNew = mrlc_remove_menu_reference($menuCurrent, $removed);

            if ($removed < 1) {
                $ok = false;
                $installLog[] = 'FAIL: No race_finish_confirmation menu reference was removed.';
            } elseif (!mrlc_write_atomic($menuPath, $menuNew)) {
                $ok = false;
                $installLog[] = 'FAIL: Could not update race_results_menu.php';
            } else {
                $installLog[] = 'MENU: removed race_finish_confirmation_monitor.php reference (' . $removed . ' line).';
            }
        }

        /* Archive experiment PHP files; preserve data directory in place */
        if ($ok) {
            if (!mrlc_mkdir($retiredRoot)) {
                $ok = false;
                $installLog[] = 'FAIL: Could not create retirement archive.';
            }
        }

        if ($ok) {
            foreach ([$finishMonitor, $finishDashboard] as $src) {
                $dst = $retiredRoot . '/' . basename($src);

                if (!mrlc_copy_preserve($src, $dst)) {
                    $ok = false;
                    $installLog[] = 'FAIL: Could not archive ' . basename($src);
                    break;
                }
                if (!@unlink($src)) {
                    $ok = false;
                    $installLog[] = 'FAIL: Archived but could not remove active copy of ' . basename($src);
                    break;
                }
                $installLog[] = 'ARCHIVED/RETIRED: ' . basename($src);
            }
        }

        /* Post-install verification */
        if ($ok) {
            foreach ($baseline as $file => $exp) {
                $dst = $liveRoot . '/' . $file;
                if (!is_file($dst)
                    || mrlc_sha($dst) !== $exp['test_sha256']
                    || strtolower(mrlc_version($dst)) !== strtolower($exp['test_version'])) {
                    $ok = false;
                    $installLog[] = 'FAIL VERIFY: ' . $file;
                    break;
                }
                $installLog[] = 'VERIFY PASS: ' . $file . ' ' . $exp['test_version'];
            }
        }

        if ($ok) {
            $schedVerifyErr = null;
            $schedVerify = mrlc_json_decode_file($schedulerSchedulePath, $schedVerifyErr);
            $taskStillThere = is_array($schedVerify)
                && isset($schedVerify['tasks'])
                && is_array($schedVerify['tasks'])
                && array_key_exists('race_finish_confirmation_monitor', $schedVerify['tasks']);

            if ($taskStillThere) {
                $ok = false;
                $installLog[] = 'FAIL VERIFY: scheduler task still present.';
            } else {
                $installLog[] = 'VERIFY PASS: scheduler task removed.';
            }
        }

        if ($ok) {
            $menuVerify = mrlc_read($menuPath);
            if (strpos($menuVerify, 'race_finish_confirmation_monitor.php') !== false) {
                $ok = false;
                $installLog[] = 'FAIL VERIFY: menu reference still present.';
            } else {
                $installLog[] = 'VERIFY PASS: menu reference removed.';
            }
        }

        if ($ok) {
            if (is_file($finishMonitor) || is_file($finishDashboard)) {
                $ok = false;
                $installLog[] = 'FAIL VERIFY: active experiment PHP file still present.';
            } else {
                $installLog[] = 'VERIFY PASS: active experiment PHP files retired.';
            }
        }

        if ($ok) {
            if (!is_dir($finishDataDir)) {
                $ok = false;
                $installLog[] = 'FAIL VERIFY: historical _race_finish_confirmation directory is missing.';
            } else {
                $installLog[] = 'VERIFY PASS: historical _race_finish_confirmation data preserved.';
            }
        }

        if ($ok) {
            $refsAfter = mrlc_scan_unexpected_active_refs($liveRoot);
            if (count($refsAfter) !== 0) {
                $ok = false;
                $installLog[] = 'FAIL VERIFY: active race_finish_confirmation references remain.';
                foreach ($refsAfter as $hit) {
                    $installLog[] = '  ' . $hit['path'] . ':' . $hit['line'] . ' ' . $hit['text'];
                }
            } else {
                $installLog[] = 'VERIFY PASS: zero active root-level race_finish_confirmation references.';
            }
        }

        $installSuccess = $ok;

        if ($ok) {
            $installLog[] = 'SUCCESS';
            $installLog[] = 'NEXT: Re-enable the Hostinger master scheduler cron.';
            $installLog[] = 'NEXT: Open race_results_dashboard.php and race_results_menu.php and verify normal operation.';
            $installLog[] = 'NEXT: Leave the historical _race_finish_confirmation directory untouched for now.';
        } else {
            $installLog[] = 'INSTALL DID NOT COMPLETE CLEANLY.';
            $installLog[] = 'IMPORTANT: Keep the master scheduler STOPPED and provide this installer output before doing anything else.';
            $installLog[] = 'Backup directory: ' . $backupRoot;
        }
    }
}


$previewReport = [
    'report' => 'MRL Live race_results Consolidation Installer Preview',
    'report_version' => MRL_CONSOLIDATION_VERSION,
    'generated_at' => $now,
    'read_only_preview' => !$installRequested,
    'preflight_ok' => $preflightOk,
    'preflight' => $preflight,
    'unexpected_active_references' => $unexpectedBefore,
    'install_requested' => $installRequested,
    'install_success' => $installSuccess,
    'install_log' => $installLog,
];

if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    $downloadStamp = strtolower(date('Ymd_hisA'));
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_live_race_results_consolidation_preview_' . $downloadStamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($previewReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL Live race_results Consolidation Installer</title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252c;--text:#f3f4f6;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--red:#ff7d7d;--yellow:#ffd166;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1500px;margin:0 auto}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin:0 0 18px}
h1{margin:0 0 5px;font-size:31px} h2{margin:0 0 12px}
.small{color:var(--muted);font-size:13px}
.pass{color:var(--green);font-weight:700}.fail{color:var(--red);font-weight:700}.warn{color:var(--yellow);font-weight:700}.info{color:var(--blue);font-weight:700}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}
code,pre{background:#111318;border-radius:5px}code{padding:2px 5px;color:#bddcff}
pre{padding:14px;border:1px solid #303540;white-space:pre-wrap;word-break:break-word}
button{padding:12px 18px;border-radius:8px;border:1px solid #a44141;background:#7a2525;color:white;font-weight:700;font-size:16px;cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
.notice{padding:13px 15px;border-radius:9px;background:#24272e;border:1px solid #404651;margin:12px 0}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
    <h1>MRL Live race_results Consolidation Installer</h1>
    <div class="small">v001 · <?= mrlc_h($now) ?> · Preview-first controlled migration</div>

    <div class="notice">
        <strong>Current mode:</strong>
        <?php if ($installAttempted): ?>
            <span class="<?= $installSuccess ? 'pass' : 'fail' ?>">
                <?= $installSuccess ? 'INSTALL COMPLETE' : 'INSTALL ATTEMPTED — REVIEW OUTPUT' ?>
            </span>
        <?php else: ?>
            <span class="info">PREVIEW ONLY — NOTHING HAS BEEN CHANGED</span>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <h2>Preflight</h2>
    <table>
        <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($preflight as $row): ?>
            <tr>
                <td><?= mrlc_h($row['label']) ?></td>
                <td class="<?= $row['status'] === 'PASS' ? 'pass' : 'fail' ?>"><?= mrlc_h($row['status']) ?></td>
                <td><?= mrlc_h($row['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2>Preview export / unexpected reference detail</h2>
    <p>
        <a href="?format=json&x=<?= mrlc_h((string)microtime(true)) ?>"
           style="display:inline-block;padding:10px 16px;border-radius:7px;border:1px solid #4c7ba8;background:#205b8c;color:white;text-decoration:none;font-weight:700;">
            Download JSON Preview
        </a>
    </p>

    <?php if (count($unexpectedBefore) === 0): ?>
        <p class="pass">PASS — no unexpected active race_finish_confirmation references found.</p>
    <?php else: ?>
        <p class="fail"><?= count($unexpectedBefore) ?> unexpected active reference(s) found. INSTALL remains disabled.</p>
        <table>
            <thead><tr><th>File</th><th>Line</th><th>Text</th></tr></thead>
            <tbody>
            <?php foreach ($unexpectedBefore as $hit): ?>
                <tr>
                    <td><code><?= mrlc_h($hit['path']) ?></code></td>
                    <td><?= mrlc_h($hit['line']) ?></td>
                    <td><?= mrlc_h($hit['text']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>What INSTALL will do</h2>
    <ol>
        <li>Create a timestamped LIVE backup and manifest.</li>
        <li>Install TEST <code>race_results_monitor.php v139</code>.</li>
        <li>Install TEST <code>race_results_rd_helper.php v005</code>.</li>
        <li>Install TEST <code>race_schedule_helper.php v003</code>.</li>
        <li>Remove the active <code>race_finish_confirmation_monitor</code> task from LIVE scheduler configuration.</li>
        <li>Remove the old experiment reference from <code>race_results_menu.php</code>.</li>
        <li>Archive the old v006 finish-confirmation monitor/dashboard under <code>_retired/</code>.</li>
        <li><strong>Leave the 1,598-file historical <code>_race_finish_confirmation</code> data directory untouched.</strong></li>
        <li>Leave LIVE snapshots, runtime state/history, schedule data, standings/revision data, scheduler state, and scheduler log untouched.</li>
        <li>Verify hashes, versions, scheduler retirement, menu retirement, and zero remaining active experiment references.</li>
    </ol>
</div>

<?php if (!$installAttempted): ?>
<div class="panel">
    <h2>Before clicking INSTALL</h2>
    <p class="warn">
        STOP/DISABLE the Hostinger master scheduler cron first.
    </p>
    <p>
        Once the cron is stopped, return to this page and click the button below.
        If the preflight shows anything other than all PASS, do not install.
    </p>

    <form method="post" onsubmit="return confirm('Confirm the Hostinger master scheduler cron is STOPPED. Proceed with LIVE consolidation?');">
        <input type="hidden" name="confirm_install" value="YES">
        <button type="submit" <?= $preflightOk ? '' : 'disabled' ?>>
            INSTALL LIVE CONSOLIDATION
        </button>
    </form>
</div>
<?php endif; ?>

<?php if ($installAttempted): ?>
<div class="panel">
    <h2>Installer output</h2>
    <pre><?= mrlc_h(implode("\n", $installLog)) ?></pre>
</div>
<?php endif; ?>

<div class="panel">
    <h2>Safety boundaries</h2>
    <ul>
        <li>No database changes.</li>
        <li>No snapshot changes.</li>
        <li>No current-season race-data replacement from TEST.</li>
        <li>No scheduler log/state-history cleanup.</li>
        <li>No deletion of the historical finish-confirmation raw/history data directory.</li>
        <li>No automatic re-enabling of Hostinger cron — you control that step.</li>
    </ul>
</div>

</div>
</body>
</html>
