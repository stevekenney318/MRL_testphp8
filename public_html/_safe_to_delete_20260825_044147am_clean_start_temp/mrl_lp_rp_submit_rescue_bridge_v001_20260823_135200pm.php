<?php
/**
 * MRL TESTPHP8 — LP → RP SUBMIT RESCUE + BRIDGE
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 1:52:00 pm
 *
 * PURPOSE
 * -------
 * Recover submit-team-picks.php from the known-good pre-v008 backup created
 * immediately before the temporary LP→RP submit patch, then apply the LP→RP
 * changes at the CORRECT locations.
 *
 * WHY THIS EXISTS
 * ---------------
 * Inspection of the actual current server copy (temporary v009) proved it is
 * structurally damaged: the main RD submission setup/if branch is missing and
 * an unmatched "else" remains.  That is why PHP returns HTTP 500 before our
 * runtime error capture can create a log.
 *
 * This installer DOES NOT patch the damaged v009 in-place.  It starts from:
 *
 *   mrl_lp_rp_submit_bridge_backup_20260823_123900pm/submit-team-picks.php
 *
 * That backup was created before the temporary v008 submit bridge was written.
 * It must report VERSION v007 and contain the intact RD branch.
 *
 * VALIDATED CHANGES APPLIED TO THE v007 BACKUP
 * ---------------------------------------------
 * 1. mrl_rd_get_base_row():
 *      pick_type IN ('SEG','ADJ')
 *    becomes:
 *      pick_type IN ('SEG','ADJ','LP')
 *
 *    This is the ACTUAL RD four-driver base-row lookup used by the public
 *    Replacement Pick submit path.
 *
 * 2. If mrl_rd_reject() is absent, add a small server-side rejection helper
 *    that safely redirects back to team.php and exits.
 *
 * 3. Replace the existing RD server deadline gate IN ITS ORIGINAL RD BRANCH
 *    with the exact TESTPHP8 historical-fixture bypass for:
 *      Be Like Biff / S1 / AJ Allmendinger / R08.
 *
 * 4. Bump submit-team-picks.php to temporary v010 for this final test.
 *
 * SAFETY
 * ------
 * - TESTPHP8 ONLY.
 * - PHP 7.3 compatible.
 * - NO DATABASE WRITES by this installer.
 * - NO schedule/race-result changes.
 * - Refuses Live.
 * - Requires team.php v026, wrapper v010, helper v005.
 * - Requires exact LP→RP pending fixture + marker + temporary LP DB row.
 * - Requires the known v007 backup file.
 * - Refuses install unless the v007 backup has exactly one intact RD branch,
 *   exactly one actual RD base-row query, and exactly one deadline gate.
 * - Backs up the CURRENT v009 before replacing it.
 * - Automatic rollback to current v009 on postflight failure.
 *
 * Keep scheduler OFF.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$STAMP = '20260823_135200';

$root = __DIR__;
$currentSubmit = $root . '/submit-team-picks.php';
$teamPath = $root . '/team.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$sourceBackup = $root . '/mrl_lp_rp_submit_bridge_backup_20260823_123900pm/submit-team-picks.php';
$rescueBackupDir = $root . '/mrl_lp_rp_submit_rescue_backup_' . $STAMP;
$rescueCurrentBackup = $rescueBackupDir . '/submit-team-picks_v009_before_rescue.php';

function sr_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function sr_test_host(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' && strpos($host, 'testphp8.manliusracingleague.com') !== false;
}

function sr_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) {
        throw new RuntimeException('Unable to read: ' . $path);
    }
    return $s;
}

function sr_write(string $path, string $content): void {
    $tmp = $path . '.tmp_' . uniqid('', true);
    if (@file_put_contents($tmp, $content) === false) {
        throw new RuntimeException('Unable to write temp file: ' . $tmp);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace: ' . $path);
    }
}

function sr_find_r07(string $root): array {
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

function sr_fixture_exact(string $path): bool {
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

function sr_lp_row_exact(PDO $dbo): bool {
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

function sr_count_rd_rows(PDO $dbo, string $table): int {
    $sql = "
        SELECT COUNT(*)
        FROM {$table}
        WHERE raceYear = '2026'
          AND segment = 'S1'
          AND teamName = 'Be Like Biff'
          AND pick_type = 'RD'
    ";
    $stmt = $dbo->query($sql);
    return $stmt ? (int)$stmt->fetchColumn() : -1;
}

list($raceDir, $fixturePath, $markerPath, $r07Count) = sr_find_r07($root);

$errors = [];
$messages = [];
$action = (string)($_POST['action'] ?? '');

if (!sr_test_host()) {
    $errors[] = 'REFUSED: TESTPHP8 only.';
}

foreach ([$currentSubmit, $teamPath, $wrapperPath, $helperPath, $sourceBackup] as $p) {
    if (!is_file($p)) {
        $errors[] = 'Missing required file: ' . $p;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

if (!isset($dbo) || !($dbo instanceof PDO)) {
    $errors[] = 'PDO database connection $dbo is unavailable.';
}

$currentSrc = is_file($currentSubmit) ? sr_read($currentSubmit) : '';
$sourceSrc = is_file($sourceBackup) ? sr_read($sourceBackup) : '';
$teamSrc = is_file($teamPath) ? sr_read($teamPath) : '';
$wrapperSrc = is_file($wrapperPath) ? sr_read($wrapperPath) : '';
$helperSrc = is_file($helperPath) ? sr_read($helperPath) : '';

$currentIsV009 =
    strpos($currentSrc, 'VERSION: v009') !== false
    && strpos($currentSrc, 'MRL_LP_RP_REAL_ERROR_CAPTURE_20260823_130200') !== false;

$sourceIsV007 = strpos($sourceSrc, 'VERSION: v007') !== false;

$rdBranchCount = preg_match_all(
    '/if\s*\(\s*\$pickTypeOverride\s*===\s*[\'"]RD[\'"]\s*\)\s*\{/m',
    $sourceSrc,
    $tmp
);

$actualBaseNeedle = "pick_type IN ('SEG','ADJ')";
$actualBaseCount = substr_count($sourceSrc, $actualBaseNeedle);

$deadlinePattern = '/if\s*\(\s*!mrl_lp_effective_race_is_open\s*\(\s*\$raceYearInt\s*,\s*\$effectiveRace\s*\)\s*\)\s*\{\s*mrl_rd_reject\s*\(\s*\)\s*;\s*\}/m';
$deadlineCount = preg_match_all($deadlinePattern, $sourceSrc, $tmp2);

$rejectFunctionCount = preg_match_all(
    '/function\s+mrl_rd_reject\s*\(\s*\)\s*(?::\s*void\s*)?\{/m',
    $sourceSrc,
    $tmp3
);

$requiredAssignments = [
    '$pickTypeOverride' => preg_match('/\$pickTypeOverride\s*=/m', $sourceSrc) === 1,
    '$raceYearInt' => preg_match('/\$raceYearInt\s*=/m', $sourceSrc) === 1,
    '$effectiveRace' => preg_match('/\$effectiveRace\s*=/m', $sourceSrc) === 1,
    '$activeSegment' => preg_match('/\$activeSegment\s*=/m', $sourceSrc) === 1,
    '$teamName' => preg_match('/\$teamName\s*=/m', $sourceSrc) === 1,
    '$driverA' => preg_match('/\$driverA\s*=/m', $sourceSrc) === 1,
];

$rdRows = (isset($dbo) && $dbo instanceof PDO) ? sr_count_rd_rows($dbo, 'user_picks') : -1;
$rdHistory = (isset($dbo) && $dbo instanceof PDO) ? sr_count_rd_rows($dbo, 'user_picks_history') : -1;

if (!$sourceIsV007) {
    $errors[] = 'Known rescue source backup is not VERSION v007.';
}
if ($rdBranchCount !== 1) {
    $errors[] = 'v007 backup does not contain exactly one intact RD branch; found ' . (int)$rdBranchCount . '.';
}
if ($actualBaseCount !== 1) {
    $errors[] = 'v007 backup does not contain exactly one actual RD SEG/ADJ base-row lookup; found ' . $actualBaseCount . '.';
}
if ($deadlineCount !== 1) {
    $errors[] = 'v007 backup does not contain exactly one RD deadline gate; found ' . (int)$deadlineCount . '.';
}
foreach ($requiredAssignments as $name => $ok) {
    if (!$ok) {
        $errors[] = 'v007 backup is missing expected assignment: ' . $name;
    }
}
if (!sr_fixture_exact($fixturePath)) {
    $errors[] = 'Exact LP→RP pending fixture is missing.';
}
if ($markerPath === '' || !is_file($markerPath)) {
    $errors[] = 'Exact LP→RP marker is missing.';
}
if (isset($dbo) && $dbo instanceof PDO && !sr_lp_row_exact($dbo)) {
    $errors[] = 'Exact temporary LP DB row is missing.';
}
if ($rdRows !== 0 || $rdHistory !== 0) {
    $errors[] = 'RD data already exists. Refusing rescue until current DB state is reviewed.';
}

$rejectHelper = <<<'PHP'

function mrl_rd_reject(): void
{
    header('Location: /team.php#current_user_team_chart');
    exit;
}

PHP;

$deadlineReplacement = <<<'PHP'
if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        $rdHistoricalFixtureOpen = false;

        $rdEdgeMarkers = glob(
            __DIR__
            . '/race_results/2026/R07_*/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json'
        );

        if (
            is_array($rdEdgeMarkers)
            && count($rdEdgeMarkers) === 1
            && is_file((string)$rdEdgeMarkers[0])
        ) {
            $rdEdgePendingPath =
                dirname((string)$rdEdgeMarkers[0])
                . '/_rd_pending_Be_Like_Biff.json';

            $rdEdgeRaw = @file_get_contents($rdEdgePendingPath);
            $rdEdgePayload =
                ($rdEdgeRaw !== false)
                ? json_decode($rdEdgeRaw, true)
                : null;

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
                && $teamName === 'Be Like Biff'
                && $activeSegment === 'S1'
                && $effectiveRace === 8
            ) {
                $rdHistoricalFixtureOpen = true;
            }
        }

        if (!$rdHistoricalFixtureOpen) {
            mrl_rd_reject();
        }
    }
PHP;

$prepared = '';

if (empty($errors)) {
    try {
        $prepared = $sourceSrc;

        if (substr_count($prepared, 'VERSION: v007') !== 1) {
            throw new RuntimeException('Expected exactly one v007 version marker.');
        }
        $prepared = str_replace('VERSION: v007', 'VERSION: v010', $prepared, $versionCount);
        if ($versionCount !== 1) {
            throw new RuntimeException('Version replacement count was not 1.');
        }

        $prepared = str_replace(
            $actualBaseNeedle,
            "pick_type IN ('SEG','ADJ','LP')",
            $prepared,
            $baseReplaceCount
        );
        if ($baseReplaceCount !== 1) {
            throw new RuntimeException('Actual RD base-row replacement count was not 1.');
        }

        if ($rejectFunctionCount === 0) {
            $insertNeedle = "function mrl_rd_slug(string \$value): string\n{";
            if (substr_count($prepared, $insertNeedle) !== 1) {
                throw new RuntimeException('Could not find unique reject-helper insertion point.');
            }

            $prepared = str_replace(
                $insertNeedle,
                $rejectHelper . $insertNeedle,
                $prepared,
                $rejectInsertCount
            );
            if ($rejectInsertCount !== 1) {
                throw new RuntimeException('Reject-helper insertion count was not 1.');
            }
        } elseif ($rejectFunctionCount !== 1) {
            throw new RuntimeException('Unexpected mrl_rd_reject function count in v007 backup.');
        }

        $prepared2 = preg_replace(
            $deadlinePattern,
            $deadlineReplacement,
            $prepared,
            1,
            $deadlineReplaceCount
        );

        if ($prepared2 === null || $deadlineReplaceCount !== 1) {
            throw new RuntimeException(
                'Deadline gate replacement count was not 1.'
            );
        }

        $prepared = $prepared2;

        // Structural post-preparation sanity checks.
        if (preg_match_all('/if\s*\(\s*\$pickTypeOverride\s*===\s*[\'"]RD[\'"]\s*\)\s*\{/m', $prepared, $m1) !== 1) {
            throw new RuntimeException('Prepared file lost the RD branch.');
        }
        if (preg_match('/function\s+mrl_rd_reject\s*\(\s*\)\s*(?::\s*void\s*)?\{/m', $prepared) !== 1) {
            throw new RuntimeException('Prepared file does not contain mrl_rd_reject().');
        }
        if (substr_count($prepared, "pick_type IN ('SEG','ADJ','LP')") !== 1) {
            throw new RuntimeException('Prepared file does not contain exactly one LP-capable actual RD base lookup.');
        }
        if (strpos($prepared, 'VERSION: v010') === false) {
            throw new RuntimeException('Prepared file does not report v010.');
        }

    } catch (Throwable $e) {
        $errors[] = 'Preparation failed: ' . $e->getMessage();
    }
}

if ($action === 'install' && empty($errors)) {
    try {
        if (!is_dir($rescueBackupDir) && !@mkdir($rescueBackupDir, 0775, true) && !is_dir($rescueBackupDir)) {
            throw new RuntimeException('Could not create rescue backup directory.');
        }

        if (!@copy($currentSubmit, $rescueCurrentBackup)) {
            throw new RuntimeException('Could not back up current v009.');
        }

        sr_write($currentSubmit, $prepared);

        $after = sr_read($currentSubmit);

        $post = [
            strpos($after, 'VERSION: v010') !== false,
            strpos($after, 'MRL_LP_RP_REAL_ERROR_CAPTURE_20260823_130200') === false,
            preg_match_all('/if\s*\(\s*\$pickTypeOverride\s*===\s*[\'"]RD[\'"]\s*\)\s*\{/m', $after, $x1) === 1,
            preg_match('/function\s+mrl_rd_reject\s*\(\s*\)\s*(?::\s*void\s*)?\{/m', $after) === 1,
            substr_count($after, "pick_type IN ('SEG','ADJ','LP')") === 1,
            strpos($after, 'BE_LIKE_BIFF_LP_AJ_R06_R07') !== false,
            strpos(sr_read($teamPath), 'VERSION: v026') !== false,
            strpos(sr_read($wrapperPath), 'VERSION: v010') !== false,
            strpos(sr_read($helperPath), 'VERSION: v005') !== false,
            sr_fixture_exact($fixturePath),
            is_file($markerPath),
        ];

        foreach ($post as $ok) {
            if (!$ok) {
                throw new RuntimeException('Postflight verification failed.');
            }
        }

        $messages[] = 'SUBMIT RESCUE + LP→RP BRIDGE INSTALLED successfully.';
        $messages[] = 'Damaged temporary v009 was replaced from the intact pre-v008 v007 backup.';
        $messages[] = 'submit-team-picks.php is now temporary v010.';
        $messages[] = 'No database writes were made by this installer.';

    } catch (Throwable $e) {
        if (is_file($rescueCurrentBackup)) {
            @copy($rescueCurrentBackup, $currentSubmit);
        }

        $errors[] = 'INSTALL FAILED: ' . $e->getMessage();
        $errors[] = 'Automatic rollback to the pre-rescue current file was attempted.';
    }
}

$nowSrc = is_file($currentSubmit) ? sr_read($currentSubmit) : '';
$installedNow =
    strpos($nowSrc, 'VERSION: v010') !== false
    && preg_match_all('/if\s*\(\s*\$pickTypeOverride\s*===\s*[\'"]RD[\'"]\s*\)\s*\{/m', $nowSrc, $xx) === 1
    && preg_match('/function\s+mrl_rd_reject\s*\(\s*\)\s*(?::\s*void\s*)?\{/m', $nowSrc) === 1
    && substr_count($nowSrc, "pick_type IN ('SEG','ADJ','LP')") === 1;

$checks = [
    ['TESTPHP8 host', sr_test_host(), (string)($_SERVER['HTTP_HOST'] ?? '')],
    ['Current damaged test file recognized', $currentIsV009 || $installedNow, $installedNow ? 'repaired v010' : ($currentIsV009 ? 'current v009' : 'unexpected')],
    ['Known pre-v008 rescue backup exists', is_file($sourceBackup), $sourceBackup],
    ['Rescue source reports v007', $sourceIsV007, 'expected intact pre-v008 backup'],
    ['Intact RD branch count in v007 backup', $rdBranchCount === 1, (string)$rdBranchCount],
    ['Actual RD SEG/ADJ base-row lookup count', $actualBaseCount === 1, (string)$actualBaseCount],
    ['RD deadline gate count in v007 backup', $deadlineCount === 1, (string)$deadlineCount],
    ['Exact LP→RP pending fixture', sr_fixture_exact($fixturePath), $fixturePath !== '' ? basename($fixturePath) : ''],
    ['Exact LP→RP marker', $markerPath !== '' && is_file($markerPath), $markerPath !== '' ? basename($markerPath) : ''],
    ['Exact temporary LP DB row', isset($dbo) && $dbo instanceof PDO && sr_lp_row_exact($dbo), 'Be Like Biff / S1 / LP / AJ / R06'],
    ['Current RD row count', $rdRows === 0, (string)$rdRows],
    ['Current RD history count', $rdHistory === 0, (string)$rdHistory],
    ['Rescue status', empty($errors) || $installedNow, $installedNow ? 'INSTALLED — v010' : 'READY'],
];

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL LP → RP Submit Rescue + Bridge <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:15px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1260px;margin:0 auto;padding:14px}
.header{background:#18301f;border:1px solid #4f8b60;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#6dff9f;font-size:23px}.sub{color:#ddd}
.card{background:#1b1b1b;border:1px solid #444;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#ffc44d;font-size:19px}
table{width:100%;border-collapse:collapse}
td{padding:7px 8px;border-bottom:1px solid #343434;vertical-align:top}
.ok{color:#65ef98;font-weight:bold}.bad{color:#ff7878;font-weight:bold}.warn{color:#ffd269;font-weight:bold}
.msg{background:#173d28;border:1px solid #3d955d;border-radius:8px;padding:9px 11px;margin-top:9px}
.err{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:9px 11px;margin-top:9px}
.callout{background:#4a2b00;border:2px solid #dc9425;padding:10px 12px;text-align:center;font-weight:bold;margin:9px 0}
button{font-size:17px;font-weight:bold;padding:10px 15px;border-radius:7px;cursor:pointer;background:#27643a;color:#fff;border:1px solid #5ab778}
code{color:#f2dc9c}.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
<h1>MRL LP → RP SUBMIT RESCUE + BRIDGE v001</h1>
<div class="sub">TESTPHP8 ONLY • recover from intact v007 backup • patch actual RD base lookup • restore intact RD branch • controlled R08 test gate</div>
</div>

<?php foreach ($messages as $m): ?>
<div class="msg"><?=sr_h($m)?></div>
<?php endforeach; ?>

<?php foreach ($errors as $e): ?>
<div class="err"><?=sr_h($e)?></div>
<?php endforeach; ?>

<div class="card">
<h2>Preflight / Current State</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:53%"><?=sr_h($c[0])?></td>
<td style="width:10%" class="<?=$c[1]?'ok':'bad'?>"><?=$c[1]?'PASS':'FAIL'?></td>
<td><?=sr_h($c[2])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>What We Found From Your Actual Server File</h2>
<table>
<tr><td>Current temporary v009</td><td>Structurally damaged: RD setup/branch is missing and an unmatched <code>else</code> remains.</td></tr>
<tr><td>Why the error logger never appeared</td><td>PHP cannot parse the file, so execution never reaches the runtime error-capture code.</td></tr>
<tr><td>This rescue</td><td>Does not repair v009 line-by-line. It rebuilds from the intact pre-v008 v007 backup and applies only the correct LP→RP changes.</td></tr>
<tr><td>Database</td><td>No RD rows/history exist, so there is nothing to undo before this rescue.</td></tr>
</table>
</div>

<?php if (empty($errors) && !$installedNow): ?>
<div class="card">
<h2>Ready</h2>
<div class="callout">
NO DATABASE WRITES.<br>
This replaces only submit-team-picks.php, starting from the known pre-v008 v007 backup.
</div>
<form method="post">
<input type="hidden" name="action" value="install">
<button type="submit">INSTALL SUBMIT RESCUE + LP → RP BRIDGE</button>
</form>
</div>
<?php endif; ?>

<?php if ($installedNow): ?>
<div class="card">
<h2 class="ok">SUBMIT RESCUE + LP → RP BRIDGE IS INSTALLED</h2>
<div class="callout">
NEXT: normal TESTPHP8 /team.php → Ctrl+F5 → select Group B — AJ Allmendinger → choose a replacement → submit ONCE.
</div>
<p class="warn">If it does anything except the normal success flow, stop and show me the result. Scheduler stays OFF.</p>
</div>
<?php endif; ?>

<div class="card small">
Rescue source: <code><?=sr_h($sourceBackup)?></code><br>
Current-file backup before rescue: <code><?=sr_h($rescueCurrentBackup)?></code><br>
After the full edge case passes, we will clean all temporary test hooks, restore clean_baseline DB, and create the permanent TESTPHP8 integration from the validated logic only.
</div>

</div>
</body>
</html>
