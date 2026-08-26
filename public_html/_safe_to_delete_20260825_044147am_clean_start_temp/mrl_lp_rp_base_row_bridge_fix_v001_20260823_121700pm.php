<?php
/**
 * MRL TESTPHP8 — LP → RP BASE-ROW BRIDGE FIX
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 12:17:00 pm
 *
 * PURPOSE
 * -------
 * Fix the specific LP → RP public-form failure discovered by the edge-case test:
 *
 *   "Replacement Pick base row is not available."
 *
 * Existing RP form routing expects a complete four-driver base row.  The
 * existing helper teampage_get_segment_base_pick_row() intentionally loads
 * only normal SEG/ADJ rows.  A genuine LP team can therefore qualify for RP
 * correctly in the shared RD helper but still fail when the public RP form
 * tries to load its base lineup.
 *
 * This patch adds an RD-specific base-row resolver:
 *
 *   1. Preserve existing SEG/ADJ behavior first.
 *   2. If no SEG/ADJ base row exists, load the team's latest LP row for that
 *      year/segment as the complete four-driver RP base lineup.
 *
 * Only the RD form-preparation path is changed.  Normal form-mode detection
 * continues using the original SEG/ADJ-only helper.
 *
 * CURRENT TEST CONTEXT
 * --------------------
 * This installer is intended to run WHILE the LP→RP v003 edge-case harness
 * remains installed:
 *   team.php temporary v025
 *   submit-team-picks.php v007 unchanged
 *   team_replacement_driver.php v010
 *   race_results/race_results_rd_helper.php v005
 *   Be Like Biff S1 temporary LP row with AJ Allmendinger effective R06
 *   pending RP fixture: AJ Allmendinger, R06/R07, effective R08
 *
 * SAFETY
 * ------
 * - TESTPHP8 ONLY.
 * - PHP 7.3 compatible.
 * - No database writes.
 * - No schedule/race-result changes.
 * - Refuses Live.
 * - Requires the exact LP→RP test fixture state.
 * - Backs up team.php before changing it.
 * - Automatic rollback on postflight failure.
 *
 * IMPORTANT
 * ---------
 * Keep the scheduler OFF.
 * Do NOT remove the LP→RP v003 test harness yet.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$STAMP = '20260823_121700pm';

$root = __DIR__;
$teamPath = $root . '/team.php';
$submitPath = $root . '/submit-team-picks.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$backupDir = $root . '/mrl_lp_rp_base_row_bridge_backup_' . $STAMP;
$backupPath = $backupDir . '/team.php';

function br_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function br_test_host(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' && strpos($host, 'testphp8.manliusracingleague.com') !== false;
}

function br_read(string $path): string
{
    $s = @file_get_contents($path);
    if ($s === false) {
        throw new RuntimeException('Unable to read: ' . $path);
    }
    return $s;
}

function br_write(string $path, string $content): void
{
    $tmp = $path . '.tmp_' . uniqid('', true);
    if (@file_put_contents($tmp, $content) === false) {
        throw new RuntimeException('Unable to write temp file: ' . $tmp);
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace: ' . $path);
    }
}

function br_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count);
    }
    return str_replace($old, $new, $src);
}

function br_find_r07(string $root): array
{
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

function br_fixture_exact(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return false;
    }

    $p = json_decode($raw, true);
    if (!is_array($p)) {
        return false;
    }

    return !empty($p['test_fixture'])
        && (string)($p['fixture_id'] ?? '') === 'BE_LIKE_BIFF_LP_AJ_R06_R07'
        && (string)($p['teamName'] ?? '') === 'Be Like Biff'
        && (string)($p['segment'] ?? '') === 'S1'
        && (string)($p['effective_race'] ?? '') === 'R08'
        && (int)($p['qualifier_count'] ?? 0) === 1
        && (string)($p['qualifiers'][0]['slot'] ?? '') === 'B'
        && (string)($p['qualifiers'][0]['driver'] ?? '') === 'AJ Allmendinger';
}

function br_get_lp_fixture_row(PDO $dbo): ?array
{
    $stmt = $dbo->prepare("
        SELECT pickID, userID, teamName, raceYear, segment,
               driverA, driverB, driverC, driverD,
               pick_type, effective_race, submission_id
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
    return is_array($row) ? $row : null;
}

list($raceDir, $fixturePath, $markerPath, $r07Count) = br_find_r07($root);

$errors = [];
$messages = [];
$action = (string)($_POST['action'] ?? '');

if (!br_test_host()) {
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

$teamSrc = is_file($teamPath) ? br_read($teamPath) : '';
$submitSrc = is_file($submitPath) ? br_read($submitPath) : '';
$wrapperSrc = is_file($wrapperPath) ? br_read($wrapperPath) : '';
$helperSrc = is_file($helperPath) ? br_read($helperPath) : '';

$lpRow = (isset($dbo) && $dbo instanceof PDO) ? br_get_lp_fixture_row($dbo) : null;

$beforeState =
    strpos($teamSrc, 'VERSION: v025') !== false
    && strpos($teamSrc, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') !== false
    && strpos($teamSrc, 'function teampage_get_rd_base_pick_row') === false
    && strpos($submitSrc, 'VERSION: v007') !== false
    && strpos($wrapperSrc, 'VERSION: v010') !== false
    && strpos($helperSrc, 'VERSION: v005') !== false;

$afterState =
    strpos($teamSrc, 'VERSION: v026') !== false
    && strpos($teamSrc, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') !== false
    && strpos($teamSrc, 'function teampage_get_rd_base_pick_row') !== false
    && strpos($submitSrc, 'VERSION: v007') !== false
    && strpos($wrapperSrc, 'VERSION: v010') !== false
    && strpos($helperSrc, 'VERSION: v005') !== false;

if (!$beforeState && !$afterState) {
    $errors[] = 'team.php is not in the expected LP→RP v003 test state or already-fixed bridge state.';
}

if (!br_fixture_exact($fixturePath)) {
    $errors[] = 'Exact LP→RP pending fixture JSON is not present.';
}

if ($markerPath === '' || !is_file($markerPath)) {
    $errors[] = 'LP→RP fixture marker is not present.';
}

if (!is_array($lpRow)) {
    $errors[] = 'Exact temporary Be Like Biff LP fixture row is not present.';
} else {
    if ((string)($lpRow['driverB'] ?? '') !== 'AJ Allmendinger') {
        $errors[] = 'Temporary LP fixture Group B driver is not AJ Allmendinger.';
    }
    if ((int)($lpRow['effective_race'] ?? 0) !== 6) {
        $errors[] = 'Temporary LP fixture is not effective R06.';
    }
}

$functionInsertMarker = "function teampage_get_lp_pick_row(PDO \$dbo, int \$uid, string \$raceYear, string \$segment): ?array\n{";

$newFunction = <<<'PHP'
/**
 * RD-specific complete base-lineup resolver.
 *
 * Normal SEG/ADJ remains first priority.  When no normal base row exists,
 * a genuine LP row may be the team's complete active lineup for this segment,
 * so the RP form must be allowed to use that LP row as its base context.
 *
 * This helper is deliberately RD-specific so normal LP/form-mode decisions
 * continue using teampage_get_segment_base_pick_row() unchanged.
 */
function teampage_get_rd_base_pick_row(PDO $dbo, int $uid, string $raceYear, string $segment): ?array
{
    $normalBase = teampage_get_segment_base_pick_row(
        $dbo,
        $uid,
        $raceYear,
        $segment
    );

    if (is_array($normalBase)) {
        return $normalBase;
    }

    $sql = "SELECT pickID, driverA, driverB, driverC, driverD
            FROM user_picks
            WHERE userID = :uid
              AND raceYear = :raceYear
              AND segment = :segment
              AND pick_type = 'LP'
            ORDER BY effective_race DESC, pickID DESC
            LIMIT 1";

    $stmt = $dbo->prepare($sql);
    $stmt->execute([
        ':uid' => $uid,
        ':raceYear' => $raceYear,
        ':segment' => $segment,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}


PHP;

$oldRdBlock = <<<'PHP'
            $rdBasePickRow = teampage_get_segment_base_pick_row(
                $dbo,
                $uid,
                (string)$raceYear,
                $rdPendingSegment
            );
PHP;

$newRdBlock = <<<'PHP'
            $rdBasePickRow = teampage_get_rd_base_pick_row(
                $dbo,
                $uid,
                (string)$raceYear,
                $rdPendingSegment
            );
PHP;

$prepared = '';

if (empty($errors) && $beforeState) {
    try {
        if (substr_count($teamSrc, $functionInsertMarker) !== 1) {
            throw new RuntimeException(
                'Expected exactly one teampage_get_lp_pick_row insertion marker; found '
                . substr_count($teamSrc, $functionInsertMarker)
            );
        }

        if (substr_count($teamSrc, $oldRdBlock) !== 1) {
            throw new RuntimeException(
                'Expected exactly one RD base-row call site; found '
                . substr_count($teamSrc, $oldRdBlock)
            );
        }

        $prepared = br_replace_once(
            $teamSrc,
            'VERSION: v025',
            'VERSION: v026',
            'team version'
        );

        $prepared = br_replace_once(
            $prepared,
            $functionInsertMarker,
            $newFunction . $functionInsertMarker,
            'RD base-row resolver insertion'
        );

        $prepared = br_replace_once(
            $prepared,
            $oldRdBlock,
            $newRdBlock,
            'RD base-row call'
        );
    } catch (Throwable $e) {
        $errors[] = 'Preparation failed: ' . $e->getMessage();
    }
}

if ($action === 'install' && empty($errors)) {
    if ($afterState) {
        $messages[] = 'LP → RP base-row bridge is already installed.';
    } else {
        try {
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
                throw new RuntimeException('Could not create backup directory.');
            }

            if (!@copy($teamPath, $backupPath)) {
                throw new RuntimeException('Could not back up team.php.');
            }

            br_write($teamPath, $prepared);

            $after = br_read($teamPath);

            $checks = [
                strpos($after, 'VERSION: v026') !== false,
                strpos($after, 'function teampage_get_rd_base_pick_row') !== false,
                substr_count($after, '$rdBasePickRow = teampage_get_rd_base_pick_row(') === 1,
                strpos($after, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') !== false,
                strpos(br_read($submitPath), 'VERSION: v007') !== false,
                strpos(br_read($wrapperPath), 'VERSION: v010') !== false,
                strpos(br_read($helperPath), 'VERSION: v005') !== false,
                br_fixture_exact($fixturePath),
                is_file($markerPath),
            ];

            foreach ($checks as $ok) {
                if (!$ok) {
                    throw new RuntimeException('Postflight verification failed.');
                }
            }

            $messages[] = 'LP → RP BASE-ROW BRIDGE INSTALLED successfully.';
            $messages[] = 'team.php is temporary v026; submit/wrapper/helper remain v007/v010/v005.';
            $messages[] = 'No database writes were made by this bridge installer.';
        } catch (Throwable $e) {
            if (is_file($backupPath)) {
                @copy($backupPath, $teamPath);
            }

            $errors[] = 'INSTALL FAILED: ' . $e->getMessage();
            $errors[] = 'Automatic team.php rollback was attempted.';
        }
    }
}

$teamNow = is_file($teamPath) ? br_read($teamPath) : '';

$bridgeInstalled =
    strpos($teamNow, 'VERSION: v026') !== false
    && strpos($teamNow, 'function teampage_get_rd_base_pick_row') !== false
    && substr_count($teamNow, '$rdBasePickRow = teampage_get_rd_base_pick_row(') === 1;

$checks = [
    ['TESTPHP8 host', br_test_host(), (string)($_SERVER['HTTP_HOST'] ?? '')],
    ['Exactly one 2026 R07 race folder', $r07Count === 1, $raceDir !== '' ? basename($raceDir) : 'found ' . $r07Count],
    ['LP→RP v003 time-travel harness marker remains installed', strpos($teamNow, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') !== false, 'team.php'],
    ['submit-team-picks.php remains v007', strpos($submitSrc, 'VERSION: v007') !== false, 'unchanged'],
    ['team_replacement_driver.php remains v010', strpos($wrapperSrc, 'VERSION: v010') !== false, 'unchanged'],
    ['race_results_rd_helper.php remains v005', strpos($helperSrc, 'VERSION: v005') !== false, 'unchanged'],
    ['Exact LP→RP pending fixture exists', br_fixture_exact($fixturePath), $fixturePath !== '' ? basename($fixturePath) : ''],
    ['LP→RP fixture marker exists', $markerPath !== '' && is_file($markerPath), $markerPath !== '' ? basename($markerPath) : ''],
    ['Temporary S1 row is LP / AJ Allmendinger / effective R06',
        is_array($lpRow)
            && (string)($lpRow['pick_type'] ?? '') === 'LP'
            && (string)($lpRow['driverB'] ?? '') === 'AJ Allmendinger'
            && (int)($lpRow['effective_race'] ?? 0) === 6,
        is_array($lpRow)
            ? 'pickID ' . (string)($lpRow['pickID'] ?? '') . ' — LP — AJ Allmendinger — R06'
            : 'not found'
    ],
    ['RD base-row bridge status', $beforeState || $bridgeInstalled, $bridgeInstalled ? 'INSTALLED — team v026' : ($beforeState ? 'READY — team v025' : 'UNKNOWN')],
];

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL LP → RP Base-Row Bridge Fix <?=$VERSION?></title>
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
<h1>MRL LP → RP BASE-ROW BRIDGE FIX v001</h1>
<div class="sub">TESTPHP8 ONLY • narrow team.php fix • LP four-driver lineup may serve as RP base when no SEG/ADJ base exists</div>
</div>

<?php foreach ($messages as $m): ?>
<div class="msg"><?=br_h($m)?></div>
<?php endforeach; ?>

<?php foreach ($errors as $e): ?>
<div class="err"><?=br_h($e)?></div>
<?php endforeach; ?>

<div class="card">
<h2>Why This Fix Exists</h2>
<table>
<tr><td>Already working</td><td>LP lineup active from R06; shared v005 helper detects AJ Allmendinger as the R08 RP qualifier.</td></tr>
<tr><td>Failure found</td><td>The public RP wrapper needs a complete four-driver base row, but team.php only looked for SEG/ADJ.</td></tr>
<tr><td>This fix</td><td>RD form preparation keeps SEG/ADJ first, then falls back to the complete LP lineup when no SEG/ADJ row exists.</td></tr>
<tr><td>Not changed</td><td>Normal LP eligibility/form routing, submit handler, RP helper, wrapper, database, schedule data.</td></tr>
</table>
</div>

<div class="card">
<h2>Preflight / Current State</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:53%"><?=br_h($c[0])?></td>
<td style="width:10%" class="<?=$c[1]?'ok':'bad'?>"><?=$c[1]?'PASS':'FAIL'?></td>
<td><?=br_h($c[2])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if (empty($errors) && !$bridgeInstalled): ?>
<div class="card">
<h2>Ready</h2>
<div class="callout">
This changes only team.php in the currently installed LP→RP test environment.<br>
No database write. No submit-handler change.
</div>
<form method="post">
<input type="hidden" name="action" value="install">
<button type="submit">INSTALL LP → RP BASE-ROW BRIDGE</button>
</form>
</div>
<?php endif; ?>

<?php if ($bridgeInstalled): ?>
<div class="card">
<h2 class="ok">LP → RP BASE-ROW BRIDGE IS INSTALLED</h2>
<div class="callout">
NEXT: Ctrl+F5 normal TESTPHP8 /team.php.<br>
Do NOT submit yet.<br>
The red “base row is not available” message should be gone, and the RP form should show the complete LP lineup with only Group B — AJ Allmendinger (R06, R07) eligible.
</div>
<p class="warn">Keep the scheduler OFF and keep the LP→RP v003 harness installed.</p>
</div>
<?php endif; ?>

<div class="card small">
Backup: <code><?=br_h($backupPath)?></code><br>
This is a test-phase bridge. The existing LP→RP v003 harness cleanup will later restore its original team.php baseline; after the edge-case is fully proven we will make the validated LP→RP bridge part of the permanent TESTPHP8 code in a controlled final integration step.
</div>

</div>
</body>
</html>
