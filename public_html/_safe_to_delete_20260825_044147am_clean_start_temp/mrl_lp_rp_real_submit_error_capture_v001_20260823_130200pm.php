<?php
/**
 * MRL TESTPHP8 — LP → RP REAL SUBMIT ERROR CAPTURE
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 1:02:00 pm
 *
 * PURPOSE
 * -------
 * Temporarily instrument the CURRENT submit-team-picks.php test file so the
 * next real LP → RP submission failure reports its exact PHP fatal error and
 * line number instead of only Chrome's generic HTTP 500 page.
 *
 * This is NOT a simulated submit path.  It instruments the actual current
 * submit-team-picks.php v008 that just produced the second HTTP 500.
 *
 * CAPTURED OUTPUT
 * ---------------
 * Dedicated TESTPHP8 log:
 *   _lp_rp_submit_debug_20260823_130200.log
 *
 * The instrumentation:
 * - enables E_ALL;
 * - enables display_errors for this TESTPHP8 submit endpoint;
 * - directs PHP errors to the dedicated log above;
 * - adds a shutdown handler that records the final fatal error, file and line.
 *
 * It DOES NOT dump POST data, session data, credentials, DB values, or config.
 *
 * SAFETY
 * ------
 * - TESTPHP8 ONLY.
 * - PHP 7.3 compatible.
 * - No DB writes by THIS installer.
 * - No schedule changes.
 * - Refuses Live.
 * - Requires current LP→RP controlled state:
 *     team.php v026
 *     submit-team-picks.php v008
 *     team_replacement_driver.php v010
 *     race_results_rd_helper.php v005
 * - Requires the exact LP→RP pending fixture + marker.
 * - Requires the exact temporary LP DB row.
 * - Backs up submit-team-picks.php before instrumentation.
 * - Automatic rollback if postflight fails.
 *
 * IMPORTANT
 * ---------
 * The next actual Submit Picks click still runs the real submit handler.
 * Previous diagnostics showed no partial RD writes from the first two failures.
 * After instrumentation is installed:
 *
 *   1. Return to team.php and Ctrl+F5.
 *   2. Select Group B — AJ Allmendinger.
 *   3. Select a replacement driver.
 *   4. Submit ONCE.
 *   5. Whatever appears, come back to THIS page and click REFRESH ERROR CAPTURE.
 *
 * Keep the scheduler OFF.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$STAMP = '20260823_130200';
$DEBUG_FILE = '_lp_rp_submit_debug_20260823_130200.log';

$root = __DIR__;
$teamPath = $root . '/team.php';
$submitPath = $root . '/submit-team-picks.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';
$debugPath = $root . '/' . $DEBUG_FILE;

$backupDir = $root . '/mrl_lp_rp_real_error_capture_backup_' . $STAMP;
$backupPath = $backupDir . '/submit-team-picks.php';

function ec_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ec_test_host(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' && strpos($host, 'testphp8.manliusracingleague.com') !== false;
}

function ec_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) {
        throw new RuntimeException('Unable to read: ' . $path);
    }
    return $s;
}

function ec_write(string $path, string $content): void {
    $tmp = $path . '.tmp_' . uniqid('', true);
    if (@file_put_contents($tmp, $content) === false) {
        throw new RuntimeException('Unable to write temp file: ' . $tmp);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace: ' . $path);
    }
}

function ec_find_r07(string $root): array {
    $dirs = glob($root . '/race_results/2026/R07_*', GLOB_ONLYDIR);
    if (!is_array($dirs) || count($dirs) !== 1) {
        return ['', '', '', count((array)$dirs)];
    }

    $dir = $dirs[0];
    return [
        $dir,
        $dir . '/_rd_pending_Be_Like_Biff.json',
        $dir . '/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json',
        1,
    ];
}

function ec_fixture_exact(string $path): bool {
    if (!is_file($path)) return false;

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return false;

    $p = json_decode($raw, true);
    if (!is_array($p)) return false;

    return !empty($p['test_fixture'])
        && (string)($p['fixture_id'] ?? '') === 'BE_LIKE_BIFF_LP_AJ_R06_R07'
        && (string)($p['teamName'] ?? '') === 'Be Like Biff'
        && (string)($p['segment'] ?? '') === 'S1'
        && (string)($p['effective_race'] ?? '') === 'R08'
        && (string)($p['source_pick_type'] ?? '') === 'LP'
        && (int)($p['qualifier_count'] ?? 0) === 1
        && (string)($p['qualifiers'][0]['slot'] ?? '') === 'B'
        && (string)($p['qualifiers'][0]['driver'] ?? '') === 'AJ Allmendinger';
}

function ec_lp_row_exact(PDO $dbo): bool {
    $stmt = $dbo->prepare("
        SELECT pickID, driverB, pick_type, effective_race, submission_id
        FROM user_picks
        WHERE raceYear = '2026'
          AND segment = 'S1'
          AND teamName = 'Be Like Biff'
          AND pick_type = 'LP'
          AND submission_id = 'test_lp_rp_edge_20260823_114100am'
        ORDER BY pickID DESC
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        && (string)($row['driverB'] ?? '') === 'AJ Allmendinger'
        && (int)($row['effective_race'] ?? 0) === 6;
}

function ec_read_debug_tail(string $path): string {
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return '';
    }

    $lines = preg_split('/\R/', $raw);
    if (!is_array($lines)) {
        return $raw;
    }

    if (count($lines) > 120) {
        $lines = array_slice($lines, -120);
    }

    return implode("\n", $lines);
}

list($raceDir, $fixturePath, $markerPath, $r07Count) = ec_find_r07($root);

$errors = [];
$messages = [];
$action = (string)($_POST['action'] ?? '');

if (!ec_test_host()) {
    $errors[] = 'REFUSED: this page may run only on testphp8.manliusracingleague.com.';
}

foreach ([$teamPath, $submitPath, $wrapperPath, $helperPath] as $p) {
    if (!is_file($p)) {
        $errors[] = 'Missing required file: ' . $p;
    }
}

if ($r07Count !== 1) {
    $errors[] = 'Expected exactly one 2026 R07 race folder; found ' . $r07Count . '.';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

if (!isset($dbo) || !($dbo instanceof PDO)) {
    $errors[] = 'PDO database connection $dbo is unavailable.';
}

$teamSrc = is_file($teamPath) ? ec_read($teamPath) : '';
$submitSrc = is_file($submitPath) ? ec_read($submitPath) : '';
$wrapperSrc = is_file($wrapperPath) ? ec_read($wrapperPath) : '';
$helperSrc = is_file($helperPath) ? ec_read($helperPath) : '';

$marker = 'MRL_LP_RP_REAL_ERROR_CAPTURE_20260823_130200';

$beforeState =
    strpos($teamSrc, 'VERSION: v026') !== false
    && strpos($submitSrc, 'VERSION: v008') !== false
    && strpos($submitSrc, $marker) === false
    && strpos($wrapperSrc, 'VERSION: v010') !== false
    && strpos($helperSrc, 'VERSION: v005') !== false;

$afterState =
    strpos($teamSrc, 'VERSION: v026') !== false
    && strpos($submitSrc, 'VERSION: v009') !== false
    && strpos($submitSrc, $marker) !== false
    && strpos($wrapperSrc, 'VERSION: v010') !== false
    && strpos($helperSrc, 'VERSION: v005') !== false;

if (!$beforeState && !$afterState) {
    $errors[] = 'submit-team-picks.php is not in the expected v008 pre-capture or v009 instrumented state.';
}

if (!ec_fixture_exact($fixturePath)) {
    $errors[] = 'Exact LP→RP pending fixture JSON is not present.';
}

if ($markerPath === '' || !is_file($markerPath)) {
    $errors[] = 'Exact LP→RP fixture marker is not present.';
}

if (isset($dbo) && $dbo instanceof PDO && !ec_lp_row_exact($dbo)) {
    $errors[] = 'Exact temporary LP DB row is not present.';
}

$instrumentation = <<<'PHP'

// MRL_LP_RP_REAL_ERROR_CAPTURE_20260823_130200
// TESTPHP8 ONLY — temporary real-submit fatal-error instrumentation.
error_reporting(E_ALL);
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/_lp_rp_submit_debug_20260823_130200.log');

register_shutdown_function(function () {
    $mrlFatal = error_get_last();

    if (!is_array($mrlFatal)) {
        return;
    }

    $mrlFatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if (!in_array((int)($mrlFatal['type'] ?? 0), $mrlFatalTypes, true)) {
        return;
    }

    $mrlLine =
        '[MRL LP->RP FATAL] '
        . date('Y-m-d H:i:s T')
        . ' | type=' . (string)($mrlFatal['type'] ?? '')
        . ' | message=' . (string)($mrlFatal['message'] ?? '')
        . ' | file=' . (string)($mrlFatal['file'] ?? '')
        . ' | line=' . (string)($mrlFatal['line'] ?? '');

    @error_log($mrlLine);
});
// END MRL_LP_RP_REAL_ERROR_CAPTURE_20260823_130200

PHP;

$prepared = '';

if (empty($errors) && $beforeState) {
    try {
        $needle = "declare(strict_types=1);\n";

        if (substr_count($submitSrc, $needle) !== 1) {
            throw new RuntimeException(
                'Expected exactly one strict_types marker; found '
                . substr_count($submitSrc, $needle)
            );
        }

        if (substr_count($submitSrc, 'VERSION: v008') !== 1) {
            throw new RuntimeException('Expected exactly one VERSION: v008 marker.');
        }

        $prepared = str_replace('VERSION: v008', 'VERSION: v009', $submitSrc);
        $prepared = str_replace($needle, $needle . $instrumentation, $prepared);

    } catch (Throwable $e) {
        $errors[] = 'Preparation failed: ' . $e->getMessage();
    }
}

if ($action === 'install' && empty($errors)) {
    if ($afterState) {
        $messages[] = 'Real-submit error capture is already installed.';
    } else {
        try {
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
                throw new RuntimeException('Could not create backup directory.');
            }

            if (!@copy($submitPath, $backupPath)) {
                throw new RuntimeException('Could not back up submit-team-picks.php.');
            }

            if (is_file($debugPath)) {
                @unlink($debugPath);
            }

            ec_write($submitPath, $prepared);

            $after = ec_read($submitPath);

            $post = [
                strpos($after, 'VERSION: v009') !== false,
                strpos($after, $marker) !== false,
                strpos($after, "_lp_rp_submit_debug_20260823_130200.log") !== false,
                strpos(ec_read($teamPath), 'VERSION: v026') !== false,
                strpos(ec_read($wrapperPath), 'VERSION: v010') !== false,
                strpos(ec_read($helperPath), 'VERSION: v005') !== false,
                ec_fixture_exact($fixturePath),
                is_file($markerPath),
            ];

            foreach ($post as $ok) {
                if (!$ok) {
                    throw new RuntimeException('Postflight verification failed.');
                }
            }

            $messages[] = 'REAL SUBMIT ERROR CAPTURE INSTALLED successfully.';
            $messages[] = 'submit-team-picks.php is temporary v009.';
            $messages[] = 'Dedicated debug log cleared and ready: ' . $DEBUG_FILE;
        } catch (Throwable $e) {
            if (is_file($backupPath)) {
                @copy($backupPath, $submitPath);
            }

            $errors[] = 'INSTALL FAILED: ' . $e->getMessage();
            $errors[] = 'Automatic submit-team-picks.php rollback was attempted.';
        }
    }
}

if ($action === 'remove_capture' && empty($errors)) {
    if (!$afterState) {
        $errors[] = 'Removal requires the exact instrumented v009 state.';
    } else {
        try {
            if (!is_file($backupPath)) {
                throw new RuntimeException('Backup submit-team-picks.php is missing.');
            }

            if (!@copy($backupPath, $submitPath)) {
                throw new RuntimeException('Could not restore submit-team-picks.php.');
            }

            $restored = ec_read($submitPath);

            if (strpos($restored, 'VERSION: v008') === false || strpos($restored, $marker) !== false) {
                throw new RuntimeException('Restored submit-team-picks.php did not verify as v008.');
            }

            $messages[] = 'Error-capture instrumentation removed; submit-team-picks.php restored to v008.';
        } catch (Throwable $e) {
            $errors[] = 'REMOVE FAILED: ' . $e->getMessage();
        }
    }
}

$submitNow = is_file($submitPath) ? ec_read($submitPath) : '';

$installedNow =
    strpos($submitNow, 'VERSION: v009') !== false
    && strpos($submitNow, $marker) !== false;

$debugText = ec_read_debug_tail($debugPath);

$checks = [
    ['TESTPHP8 host', ec_test_host(), (string)($_SERVER['HTTP_HOST'] ?? '')],
    ['team.php current bridge state', strpos($teamSrc, 'VERSION: v026') !== false, 'v026'],
    ['team_replacement_driver.php', strpos($wrapperSrc, 'VERSION: v010') !== false, 'v010 unchanged'],
    ['race_results_rd_helper.php', strpos($helperSrc, 'VERSION: v005') !== false, 'v005 unchanged'],
    ['Exact LP→RP fixture', ec_fixture_exact($fixturePath), $fixturePath !== '' ? basename($fixturePath) : ''],
    ['Exact LP→RP marker', $markerPath !== '' && is_file($markerPath), $markerPath !== '' ? basename($markerPath) : ''],
    ['Exact temporary LP DB row', isset($dbo) && $dbo instanceof PDO && ec_lp_row_exact($dbo), 'Be Like Biff / S1 / LP / AJ / R06'],
    ['Error capture status', $beforeState || $installedNow, $installedNow ? 'INSTALLED — submit v009' : ($beforeState ? 'READY — submit v008' : 'UNKNOWN')],
];

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL LP → RP Real Submit Error Capture <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:15px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1240px;margin:0 auto;padding:14px}
.header{background:#18301f;border:1px solid #4f8b60;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#6dff9f;font-size:23px}
.sub{margin-top:3px;color:#ddd}
.card{background:#1b1b1b;border:1px solid #444;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#ffc44d;font-size:19px}
table{width:100%;border-collapse:collapse}
td{padding:7px 8px;border-bottom:1px solid #343434;vertical-align:top}
.ok{color:#65ef98;font-weight:bold}
.bad{color:#ff7878;font-weight:bold}
.warn{color:#ffd269;font-weight:bold}
.msg{background:#173d28;border:1px solid #3d955d;border-radius:8px;padding:9px 11px;margin-top:9px}
.err{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:9px 11px;margin-top:9px}
.callout{background:#4a2b00;border:2px solid #dc9425;padding:10px 12px;text-align:center;font-weight:bold;margin:9px 0}
button{font-size:17px;font-weight:bold;padding:10px 15px;border-radius:7px;cursor:pointer;background:#27643a;color:#fff;border:1px solid #5ab778;margin-right:8px}
button.remove{background:#7a3c17;border-color:#c57938}
pre{white-space:pre-wrap;word-break:break-word;background:#0b0b0b;border:1px solid #333;padding:10px;max-height:420px;overflow:auto}
code{color:#f2dc9c}
.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
<h1>MRL LP → RP REAL SUBMIT ERROR CAPTURE v001</h1>
<div class="sub">TESTPHP8 ONLY • instruments the actual submit endpoint • dedicated current error log • no POST/DB write by this page</div>
</div>

<?php foreach ($messages as $m): ?>
<div class="msg"><?=ec_h($m)?></div>
<?php endforeach; ?>

<?php foreach ($errors as $e): ?>
<div class="err"><?=ec_h($e)?></div>
<?php endforeach; ?>

<div class="card">
<h2>Preflight / Current State</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:53%"><?=ec_h($c[0])?></td>
<td style="width:10%" class="<?=$c[1]?'ok':'bad'?>"><?=$c[1]?'PASS':'FAIL'?></td>
<td><?=ec_h($c[2])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if (empty($errors) && !$installedNow): ?>
<div class="card">
<h2>Ready</h2>
<div class="callout">
This changes only submit-team-picks.php to add temporary TESTPHP8 fatal-error reporting.<br>
It does NOT submit anything and does NOT write to the database.
</div>
<form method="post">
<input type="hidden" name="action" value="install">
<button type="submit">INSTALL REAL SUBMIT ERROR CAPTURE</button>
</form>
</div>
<?php endif; ?>

<?php if ($installedNow): ?>
<div class="card">
<h2 class="ok">REAL SUBMIT ERROR CAPTURE IS INSTALLED</h2>
<div class="callout">
NEXT:<br>
1. Return to normal TESTPHP8 /team.php and Ctrl+F5.<br>
2. Choose Group B — AJ Allmendinger.<br>
3. Choose a replacement driver.<br>
4. Submit ONCE.<br>
5. Then return to THIS page and click REFRESH ERROR CAPTURE.
</div>
<p class="warn">The next failure should either display the exact PHP error in the browser or write it to the dedicated log below.</p>
</div>

<div class="card">
<h2>Dedicated Error Capture</h2>
<div>File: <code><?=ec_h($debugPath)?></code></div>
<?php if ($debugText === ''): ?>
<p class="warn">No captured error yet.</p>
<?php else: ?>
<pre><?=ec_h($debugText)?></pre>
<?php endif; ?>

<form method="get" style="display:inline;">
<button type="submit">REFRESH ERROR CAPTURE</button>
</form>

<?php if ($installedNow): ?>
<form method="post" style="display:inline;" onsubmit="return confirm('Remove only the temporary fatal-error instrumentation and restore submit-team-picks.php v008?');">
<input type="hidden" name="action" value="remove_capture">
<button class="remove" type="submit">REMOVE ERROR CAPTURE</button>
</form>
<?php endif; ?>
</div>

<div class="card small">
Backup: <code><?=ec_h($backupPath)?></code><br>
Dedicated log: <code><?=ec_h($debugPath)?></code><br>
Do not remove the capture until we have read the exact current failure. Keep scheduler OFF.
</div>

</div>
</body>
</html>
