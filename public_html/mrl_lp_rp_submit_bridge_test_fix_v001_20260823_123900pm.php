<?php
/**
 * MRL TESTPHP8 — LP → RP SUBMIT BRIDGE TEST FIX
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 12:39:00 pm
 *
 * PURPOSE
 * -------
 * Narrow TESTPHP8-only fix for the final LP → RP submission test.
 *
 * The diagnostic proved:
 * - the active S1 lineup is LP-only,
 * - no RD row/history was partially written,
 * - submit-team-picks.php v007 still contains a SEG/ADJ-only RD base lookup.
 *
 * This installer makes TWO temporary test-phase changes to submit-team-picks.php:
 *
 * 1) RD base lookup:
 *      pick_type IN ('SEG', 'ADJ')
 *    becomes:
 *      pick_type IN ('SEG', 'ADJ', 'LP')
 *
 *    This allows an LP lineup to be the source/base lineup for an RP.
 *
 * 2) Exact LP→RP fixture deadline bypass:
 *    Because the real calendar is long past R08, the current controlled
 *    historical test needs the submit handler to accept the exact owned
 *    Be Like Biff / S1 / R08 fixture while team.php is pretending it is
 *    one hour before R08.
 *
 *    The bypass applies ONLY when the exact LP→RP fixture JSON + marker exist.
 *
 * SAFETY
 * ------
 * - TESTPHP8 ONLY.
 * - PHP 7.3 compatible.
 * - No DB writes by this installer.
 * - No schedule changes.
 * - Refuses Live.
 * - Requires current LP→RP test state:
 *     team.php v026
 *     submit-team-picks.php v007
 *     wrapper v010
 *     helper v005
 *     exact LP fixture row
 *     exact pending RP JSON + marker
 * - Requires exactly ONE SEG/ADJ lookup text match.
 * - Requires exactly ONE known server deadline-gate block.
 * - Backs up submit-team-picks.php.
 * - Rolls back on postflight failure.
 *
 * Keep scheduler OFF.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$STAMP = '20260823_123900pm';

$root = __DIR__;
$teamPath = $root . '/team.php';
$submitPath = $root . '/submit-team-picks.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$backupDir = $root . '/mrl_lp_rp_submit_bridge_backup_' . $STAMP;
$backupPath = $backupDir . '/submit-team-picks.php';

function sb_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function sb_test_host(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' && strpos($host, 'testphp8.manliusracingleague.com') !== false;
}

function sb_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) {
        throw new RuntimeException('Unable to read: ' . $path);
    }
    return $s;
}

function sb_write(string $path, string $content): void {
    $tmp = $path . '.tmp_' . uniqid('', true);
    if (@file_put_contents($tmp, $content) === false) {
        throw new RuntimeException('Unable to write temp file: ' . $tmp);
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace: ' . $path);
    }
}

function sb_find_r07(string $root): array {
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

function sb_fixture_exact(string $path): bool {
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
        && (int)($p['qualifier_count'] ?? 0) === 1
        && (string)($p['qualifiers'][0]['slot'] ?? '') === 'B'
        && (string)($p['qualifiers'][0]['driver'] ?? '') === 'AJ Allmendinger'
        && (string)($p['source_pick_type'] ?? '') === 'LP';
}

function sb_lp_row_exact(PDO $dbo): bool {
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

list($raceDir, $fixturePath, $markerPath, $r07Count) = sb_find_r07($root);

$errors = [];
$messages = [];
$action = (string)($_POST['action'] ?? '');

if (!sb_test_host()) {
    $errors[] = 'REFUSED: this installer may run only on testphp8.manliusracingleague.com.';
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

$teamSrc = is_file($teamPath) ? sb_read($teamPath) : '';
$submitSrc = is_file($submitPath) ? sb_read($submitPath) : '';
$wrapperSrc = is_file($wrapperPath) ? sb_read($wrapperPath) : '';
$helperSrc = is_file($helperPath) ? sb_read($helperPath) : '';

$segAdjNeedle = "pick_type IN ('SEG', 'ADJ')";
$segAdjFixed = "pick_type IN ('SEG', 'ADJ', 'LP')";

$deadlineOld = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        mrl_rd_reject();
    }
PHP;

$deadlineNew = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    // TESTPHP8 LP→RP edge-case fixture may use the controlled historical R08 window.
    $rdDeadlineOpen = mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace);

    if (!$rdDeadlineOpen) {
        $rdEdgeMarkers = glob(
            __DIR__
            . '/race_results/2026/R07_*/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json'
        );

        if (is_array($rdEdgeMarkers) && count($rdEdgeMarkers) === 1 && is_file((string)$rdEdgeMarkers[0])) {
            $rdEdgePendingPath = dirname((string)$rdEdgeMarkers[0]) . '/_rd_pending_Be_Like_Biff.json';
            $rdEdgeRaw = @file_get_contents($rdEdgePendingPath);
            $rdEdgePayload = ($rdEdgeRaw !== false) ? json_decode($rdEdgeRaw, true) : null;

            if (
                is_array($rdEdgePayload)
                && !empty($rdEdgePayload['test_fixture'])
                && (string)($rdEdgePayload['fixture_id'] ?? '') === 'BE_LIKE_BIFF_LP_AJ_R06_R07'
                && (string)($rdEdgePayload['teamName'] ?? '') === 'Be Like Biff'
                && (string)($rdEdgePayload['segment'] ?? '') === 'S1'
                && (string)($rdEdgePayload['effective_race'] ?? '') === 'R08'
                && (string)($rdEdgePayload['source_pick_type'] ?? '') === 'LP'
                && (int)($rdEdgePayload['qualifier_count'] ?? 0) === 1
                && (string)($rdEdgePayload['qualifiers'][0]['slot'] ?? '') === 'B'
                && (string)($rdEdgePayload['qualifiers'][0]['driver'] ?? '') === 'AJ Allmendinger'
            ) {
                $rdDeadlineOpen = true;
            }
        }
    }

    if (!$rdDeadlineOpen) {
        mrl_rd_reject();
    }
PHP;

$beforeState =
    strpos($teamSrc, 'VERSION: v026') !== false
    && strpos($teamSrc, 'function teampage_get_rd_base_pick_row') !== false
    && strpos($submitSrc, 'VERSION: v007') !== false
    && strpos($wrapperSrc, 'VERSION: v010') !== false
    && strpos($helperSrc, 'VERSION: v005') !== false
    && substr_count($submitSrc, $segAdjNeedle) === 1
    && substr_count($submitSrc, $segAdjFixed) === 0
    && substr_count($submitSrc, $deadlineOld) === 1;

$afterState =
    strpos($teamSrc, 'VERSION: v026') !== false
    && strpos($submitSrc, 'VERSION: v008') !== false
    && strpos($wrapperSrc, 'VERSION: v010') !== false
    && strpos($helperSrc, 'VERSION: v005') !== false
    && substr_count($submitSrc, $segAdjFixed) === 1
    && strpos($submitSrc, 'BE_LIKE_BIFF_LP_AJ_R06_R07') !== false
    && strpos($submitSrc, '$rdDeadlineOpen = mrl_lp_effective_race_is_open') !== false;

if (!$beforeState && !$afterState) {
    $errors[] = 'submit-team-picks.php is not in the expected v007 pre-fix state or this installer v008 post-fix state.';
}

if (!sb_fixture_exact($fixturePath)) {
    $errors[] = 'Exact LP→RP pending fixture JSON is not present.';
}

if ($markerPath === '' || !is_file($markerPath)) {
    $errors[] = 'Exact LP→RP fixture marker is not present.';
}

if (isset($dbo) && $dbo instanceof PDO && !sb_lp_row_exact($dbo)) {
    $errors[] = 'Exact temporary LP database row is not present.';
}

$prepared = '';

if (empty($errors) && $beforeState) {
    try {
        $prepared = $submitSrc;

        if (substr_count($prepared, 'VERSION: v007') !== 1) {
            throw new RuntimeException('Expected exactly one VERSION: v007 marker.');
        }
        $prepared = str_replace('VERSION: v007', 'VERSION: v008', $prepared);

        if (substr_count($prepared, $segAdjNeedle) !== 1) {
            throw new RuntimeException(
                'Expected exactly one SEG/ADJ-only RD base lookup; found '
                . substr_count($prepared, $segAdjNeedle)
            );
        }
        $prepared = str_replace($segAdjNeedle, $segAdjFixed, $prepared);

        if (substr_count($prepared, $deadlineOld) !== 1) {
            throw new RuntimeException(
                'Expected exactly one known RD deadline gate; found '
                . substr_count($prepared, $deadlineOld)
            );
        }
        $prepared = str_replace($deadlineOld, $deadlineNew, $prepared);

    } catch (Throwable $e) {
        $errors[] = 'Preparation failed: ' . $e->getMessage();
    }
}

if ($action === 'install' && empty($errors)) {
    if ($afterState) {
        $messages[] = 'LP → RP submit bridge is already installed.';
    } else {
        try {
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
                throw new RuntimeException('Could not create backup directory.');
            }

            if (!@copy($submitPath, $backupPath)) {
                throw new RuntimeException('Could not back up submit-team-picks.php.');
            }

            sb_write($submitPath, $prepared);

            $after = sb_read($submitPath);

            $post = [
                strpos($after, 'VERSION: v008') !== false,
                substr_count($after, $segAdjFixed) === 1,
                substr_count($after, $segAdjNeedle) === 0,
                strpos($after, 'BE_LIKE_BIFF_LP_AJ_R06_R07') !== false,
                strpos($after, '$rdDeadlineOpen = mrl_lp_effective_race_is_open') !== false,
                strpos(sb_read($teamPath), 'VERSION: v026') !== false,
                strpos(sb_read($wrapperPath), 'VERSION: v010') !== false,
                strpos(sb_read($helperPath), 'VERSION: v005') !== false,
                sb_fixture_exact($fixturePath),
                is_file($markerPath),
            ];

            foreach ($post as $ok) {
                if (!$ok) {
                    throw new RuntimeException('Postflight verification failed.');
                }
            }

            $messages[] = 'LP → RP SUBMIT BRIDGE TEST FIX INSTALLED successfully.';
            $messages[] = 'submit-team-picks.php is temporary v008.';
            $messages[] = 'No database writes were made by this installer.';
        } catch (Throwable $e) {
            if (is_file($backupPath)) {
                @copy($backupPath, $submitPath);
            }

            $errors[] = 'INSTALL FAILED: ' . $e->getMessage();
            $errors[] = 'Automatic submit-team-picks.php rollback was attempted.';
        }
    }
}

$submitNow = is_file($submitPath) ? sb_read($submitPath) : '';

$installedNow =
    strpos($submitNow, 'VERSION: v008') !== false
    && substr_count($submitNow, $segAdjFixed) === 1
    && strpos($submitNow, 'BE_LIKE_BIFF_LP_AJ_R06_R07') !== false;

$checks = [
    ['TESTPHP8 host', sb_test_host(), (string)($_SERVER['HTTP_HOST'] ?? '')],
    ['team.php bridge state', strpos($teamSrc, 'VERSION: v026') !== false, 'temporary v026'],
    ['team_replacement_driver.php', strpos($wrapperSrc, 'VERSION: v010') !== false, 'v010 unchanged'],
    ['race_results_rd_helper.php', strpos($helperSrc, 'VERSION: v005') !== false, 'v005 unchanged'],
    ['Exact LP→RP pending fixture', sb_fixture_exact($fixturePath), $fixturePath !== '' ? basename($fixturePath) : ''],
    ['Exact LP→RP marker', $markerPath !== '' && is_file($markerPath), $markerPath !== '' ? basename($markerPath) : ''],
    ['Exact temporary LP DB row', isset($dbo) && $dbo instanceof PDO && sb_lp_row_exact($dbo), 'Be Like Biff / S1 / LP / AJ / effective R06'],
    ['SEG/ADJ-only lookup count before fix', $installedNow || substr_count($submitSrc, $segAdjNeedle) === 1, $installedNow ? 'fixed' : (string)substr_count($submitSrc, $segAdjNeedle)],
    ['Known RD deadline gate count before fix', $installedNow || substr_count($submitSrc, $deadlineOld) === 1, $installedNow ? 'fixed' : (string)substr_count($submitSrc, $deadlineOld)],
    ['Submit bridge status', $beforeState || $installedNow, $installedNow ? 'INSTALLED — v008' : ($beforeState ? 'READY — v007' : 'UNKNOWN')],
];

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL LP → RP Submit Bridge Test Fix <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:15px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1220px;margin:0 auto;padding:14px}
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
button{font-size:17px;font-weight:bold;padding:10px 15px;border-radius:7px;cursor:pointer;background:#27643a;color:#fff;border:1px solid #5ab778}
code{color:#f2dc9c}
.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
<h1>MRL LP → RP SUBMIT BRIDGE TEST FIX v001</h1>
<div class="sub">TESTPHP8 ONLY • narrow submit-handler bridge • LP may be RD source/base • exact historical fixture deadline bypass</div>
</div>

<?php foreach ($messages as $m): ?>
<div class="msg"><?=sb_h($m)?></div>
<?php endforeach; ?>

<?php foreach ($errors as $e): ?>
<div class="err"><?=sb_h($e)?></div>
<?php endforeach; ?>

<div class="card">
<h2>Preflight / Current State</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:53%"><?=sb_h($c[0])?></td>
<td style="width:10%" class="<?=$c[1]?'ok':'bad'?>"><?=$c[1]?'PASS':'FAIL'?></td>
<td><?=sb_h($c[2])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>What This Changes</h2>
<table>
<tr><td>RD base lookup</td><td><code>SEG, ADJ</code> → <code>SEG, ADJ, LP</code></td></tr>
<tr><td>Historical deadline</td><td>Allows only the exact owned Be Like Biff LP→RP R08 fixture through the server deadline gate.</td></tr>
<tr><td>Everything else</td><td>Preserved. No DB/schema/schedule/helper/wrapper/team-page changes.</td></tr>
</table>
</div>

<?php if (empty($errors) && !$installedNow): ?>
<div class="card">
<h2>Ready</h2>
<div class="callout">
No database writes.<br>
This changes only submit-team-picks.php in the current controlled LP→RP test.
</div>
<form method="post">
<input type="hidden" name="action" value="install">
<button type="submit">INSTALL LP → RP SUBMIT BRIDGE</button>
</form>
</div>
<?php endif; ?>

<?php if ($installedNow): ?>
<div class="card">
<h2 class="ok">LP → RP SUBMIT BRIDGE IS INSTALLED</h2>
<div class="callout">
NEXT: return to normal TESTPHP8 /team.php, Ctrl+F5, choose Group B — AJ Allmendinger, choose a replacement driver, and submit ONCE.
</div>
<p class="warn">Keep scheduler OFF. If anything other than the normal success flow appears, stop and show me the result before retrying.</p>
</div>
<?php endif; ?>

<div class="card small">
Backup: <code><?=sb_h($backupPath)?></code><br>
This is still test-phase code. After the full LP→RP path is proven, we will clean the harness, restore clean_baseline DB, and create the controlled permanent TESTPHP8 integration containing only the validated production logic.
</div>

</div>
</body>
</html>
