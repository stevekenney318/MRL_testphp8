<?php
/**
 * MRL TESTPHP8 — LP → RP SUBMIT STRUCTURAL REPAIR
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 2:03:00 pm
 *
 * PURPOSE
 * -------
 * Repair the CURRENT temporary submit-team-picks.php v009 in-place using the
 * exact structure confirmed from:
 *
 * - the uploaded current server v009;
 * - the known-good GitHub v006 baseline;
 * - the original v007 real-flow integration logic.
 *
 * This is the ONE installer to use now. Older LP→RP submit installers and
 * diagnostics are obsolete unless explicitly requested later.
 *
 * ROOT CAUSE CONFIRMED
 * --------------------
 * The current v009 is missing the main request/variable setup and opening
 *     if ($pickTypeOverride === 'RD') {
 * block before the RD deadline/validation body. The file therefore cannot
 * parse correctly and returns HTTP 500 before runtime logging can start.
 *
 * THIS REPAIR
 * -----------
 * 1. Removes the temporary real-error-capture instrumentation from v009.
 * 2. Restores the missing v007 request/variable setup in the correct order:
 *      - RD POST fields are read BEFORE activeSegment is derived.
 * 3. Restores mrl_rd_reject().
 * 4. Restores the opening RD branch and initial validation.
 * 5. Makes the ACTUAL RD four-driver base-row lookup accept LP:
 *      ('SEG','ADJ') -> ('SEG','ADJ','LP')
 * 6. Keeps the exact historical R08 test-fixture deadline bypass, but now
 *    inside the proper RD branch after all required variables exist.
 * 7. Leaves all later v007 RD validation/write logic intact.
 * 8. Writes temporary submit-team-picks.php v010.
 *
 * SAFETY
 * ------
 * TESTPHP8 ONLY.
 * PHP 7.3 compatible.
 * NO database writes by this installer.
 * NO scheduler changes.
 * Requires exact current LP→RP fixture and exact temporary LP row.
 * Requires RD current/history counts to remain zero before install.
 * Backs up current v009.
 * Exact-count transformations only.
 * Postflight structural verification.
 * Automatic rollback if postflight fails.
 *
 * Keep scheduler OFF.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$STAMP = '20260823_140300';

$root = __DIR__;
$submitPath = $root . '/submit-team-picks.php';
$teamPath = $root . '/team.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$backupDir = $root . '/mrl_lp_rp_submit_structural_repair_backup_' . $STAMP;
$backupPath = $backupDir . '/submit-team-picks_v009_before_repair.php';

function rrh($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rr_test_host(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host === 'testphp8.manliusracingleague.com';
}

function rr_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) {
        throw new RuntimeException('Unable to read: ' . $path);
    }
    return $s;
}

function rr_atomic_write(string $path, string $content): void {
    $tmp = $path . '.mrl_tmp_' . str_replace('.', '', uniqid('', true));

    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write temporary file.');
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace target file.');
    }
}

function rr_find_r07(string $root): array {
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

function rr_fixture_exact(string $path): bool {
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

function rr_lp_row_exact(PDO $dbo): bool {
    $stmt = $dbo->prepare("
        SELECT pickID, driverA, driverB, driverC, driverD,
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

    return is_array($row)
        && (string)($row['driverA'] ?? '') === 'Chase Elliott'
        && (string)($row['driverB'] ?? '') === 'AJ Allmendinger'
        && (string)($row['driverC'] ?? '') === 'Ryan Blaney'
        && (string)($row['driverD'] ?? '') === 'Brad Keselowski'
        && (int)($row['effective_race'] ?? 0) === 6;
}

function rr_rd_count(PDO $dbo, string $table): int {
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

list($raceDir, $fixturePath, $markerPath, $r07Count) = rr_find_r07($root);

$errors = [];
$messages = [];
$checks = [];
$action = (string)($_POST['action'] ?? '');

if (!rr_test_host()) {
    $errors[] = 'REFUSED: TESTPHP8 only.';
}

foreach ([$submitPath, $teamPath, $wrapperPath, $helperPath] as $p) {
    if (!is_file($p)) {
        $errors[] = 'Missing required file: ' . $p;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

if (!isset($dbo) || !($dbo instanceof PDO)) {
    $errors[] = 'PDO database connection $dbo is unavailable.';
}

$submitSrc = is_file($submitPath) ? rr_read($submitPath) : '';
$teamSrc = is_file($teamPath) ? rr_read($teamPath) : '';
$wrapperSrc = is_file($wrapperPath) ? rr_read($wrapperPath) : '';
$helperSrc = is_file($helperPath) ? rr_read($helperPath) : '';

$debugStart = "// MRL_LP_RP_REAL_ERROR_CAPTURE_20260823_130200\n";
$debugEnd = "// END MRL_LP_RP_REAL_ERROR_CAPTURE_20260823_130200\n\n";

$debugStartPos = strpos($submitSrc, $debugStart);
$debugEndPos = strpos($submitSrc, $debugEnd);

$currentV009 =
    strpos($submitSrc, 'VERSION: v009') !== false
    && $debugStartPos !== false
    && $debugEndPos !== false;

$repairedV010 =
    strpos($submitSrc, 'VERSION: v010') !== false
    && strpos($submitSrc, 'MRL_LP_RP_STRUCTURAL_REPAIR_20260823_140300') !== false;

$changedSlotEnd = <<<'PHP'
function mrl_rd_changed_slot(array $baseRow, array $rdRow): string
{
    $changed = [];
    foreach (['A','B','C','D'] as $slot) {
        $key = 'driver' . $slot;
        if (trim((string)($baseRow[$key] ?? '')) !== trim((string)($rdRow[$key] ?? ''))) {
            $changed[] = $slot;
        }
    }
    return count($changed) === 1 ? $changed[0] : '';
}

PHP;

$brokenDeadlineNeedle = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    // TESTPHP8 LP→RP edge-case fixture may use the controlled historical R08 window.
    $rdDeadlineOpen = mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace);

PHP;

$actualBaseOld = "AND pick_type IN ('SEG','ADJ')";
$actualBaseNew = "AND pick_type IN ('SEG','ADJ','LP')";

$rdRows = (isset($dbo) && $dbo instanceof PDO) ? rr_rd_count($dbo, 'user_picks') : -1;
$rdHistory = (isset($dbo) && $dbo instanceof PDO) ? rr_rd_count($dbo, 'user_picks_history') : -1;

$checks = [
    ['TESTPHP8 host', rr_test_host(), (string)($_SERVER['HTTP_HOST'] ?? '')],
    ['Current submit state recognized', $currentV009 || $repairedV010, $repairedV010 ? 'repaired v010' : ($currentV009 ? 'damaged temporary v009' : 'unexpected')],
    ['team.php temporary LP base bridge', strpos($teamSrc, 'VERSION: v026') !== false, 'v026'],
    ['Replacement Pick wrapper', strpos($wrapperSrc, 'VERSION: v010') !== false, 'v010'],
    ['Shared RD helper', strpos($helperSrc, 'VERSION: v005') !== false, 'v005'],
    ['Exact LP→RP pending fixture', rr_fixture_exact($fixturePath), $fixturePath !== '' ? basename($fixturePath) : ''],
    ['Exact LP→RP marker', $markerPath !== '' && is_file($markerPath), $markerPath !== '' ? basename($markerPath) : ''],
    ['Exact temporary four-driver LP row', isset($dbo) && $dbo instanceof PDO && rr_lp_row_exact($dbo), 'Chase / AJ / Blaney / Keselowski • effective R06'],
    ['Current RD row count', $rdRows === 0, (string)$rdRows],
    ['Current RD history count', $rdHistory === 0, (string)$rdHistory],
];

foreach ($checks as $c) {
    if (!$c[1]) {
        $errors[] = 'Preflight failed: ' . $c[0] . '.';
    }
}

$prepared = '';

if (empty($errors) && $currentV009) {
    try {
        $prepared = $submitSrc;

        // 1) Remove the temporary error-capture block.
        $start = strpos($prepared, $debugStart);
        $end = strpos($prepared, $debugEnd);

        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('Could not locate exact error-capture block.');
        }

        $end += strlen($debugEnd);
        $prepared = substr($prepared, 0, $start) . substr($prepared, $end);

        // 2) Version + repair changelog marker.
        if (substr_count($prepared, 'VERSION: v009') !== 1) {
            throw new RuntimeException('Expected exactly one VERSION: v009 marker.');
        }

        $prepared = str_replace('VERSION: v009', 'VERSION: v010', $prepared, $nVersion);
        if ($nVersion !== 1) {
            throw new RuntimeException('Version replacement count was not 1.');
        }

        $changelogNeedle = " * CHANGELOG:\n *\n";
        if (substr_count($prepared, $changelogNeedle) !== 1) {
            throw new RuntimeException('Could not find unique changelog insertion point.');
        }

        $repairLog =
            " * CHANGELOG:\n"
            . " *\n"
            . " * v010 (8/23/2026 2:03:00 pm)\n"
            . " * - TESTPHP8 TEMPORARY: Structural repair of the LP→RP submit test path.\n"
            . " * - FIX: Restores missing v007 request/RD branch setup in correct POST order.\n"
            . " * - FIX: Actual RD base-row lookup now accepts LP source rows.\n"
            . " * - TEST: Preserves exact owned historical R08 fixture deadline bypass.\n"
            . " * - NOTE: MRL_LP_RP_STRUCTURAL_REPAIR_20260823_140300.\n"
            . " *\n";

        $prepared = str_replace($changelogNeedle, $repairLog, $prepared, $nLog);
        if ($nLog !== 1) {
            throw new RuntimeException('Changelog insertion count was not 1.');
        }

        // 3) Actual RD base-row lookup: exactly one compact SEG/ADJ occurrence.
        if (substr_count($prepared, $actualBaseOld) !== 1) {
            throw new RuntimeException(
                'Expected exactly one actual RD SEG/ADJ base lookup; found '
                . substr_count($prepared, $actualBaseOld)
                . '.'
            );
        }

        $prepared = str_replace($actualBaseOld, $actualBaseNew, $prepared, $nBase);
        if ($nBase !== 1) {
            throw new RuntimeException('Actual RD base-row replacement count was not 1.');
        }

        // 4) Replace the exact broken transition with the complete missing setup.
        $brokenTransition = $changedSlotEnd . $brokenDeadlineNeedle;

        if (substr_count($prepared, $brokenTransition) !== 1) {
            throw new RuntimeException(
                'Exact damaged transition marker was not found once; found '
                . substr_count($prepared, $brokenTransition)
                . '.'
            );
        }

        $restoredSetup = <<<'PHP'
function mrl_rd_changed_slot(array $baseRow, array $rdRow): string
{
    $changed = [];
    foreach (['A','B','C','D'] as $slot) {
        $key = 'driver' . $slot;
        if (trim((string)($baseRow[$key] ?? '')) !== trim((string)($rdRow[$key] ?? ''))) {
            $changed[] = $slot;
        }
    }
    return count($changed) === 1 ? $changed[0] : '';
}

function mrl_rd_reject(): void
{
    header('Location: /team.php#current_user_team_chart');
    exit;
}

// MRL_LP_RP_STRUCTURAL_REPAIR_20260823_140300
// Restore the v007 request/POST ordering before RD branch evaluation.
$uid = isset($_SESSION['userSession']) ? (int)$_SESSION['userSession'] : 0;
if ($uid <= 0) {
    exit;
}

$raceYearStr = (string)$raceYear;
$raceYearInt = (int)$raceYear;

$driverA = mrl_post_value('group-a-driver');
$driverB = mrl_post_value('group-b-driver');
$driverC = mrl_post_value('group-c-driver');
$driverD = mrl_post_value('group-d-driver');
$submissionId = mrl_post_value('submission_id');
$formID = mrl_post_value('form_id');
$formVersion = mrl_post_value('form_version');

$pickTypeOverride = strtoupper(mrl_post_value('pick_type_override'));
$rdSegmentPost = mrl_post_value('rd_segment');
$rdEffectiveRacePost = mrl_post_value('rd_effective_race');
$rdSupersedesPickIdPost = mrl_post_value('rd_supersedes_pick_id');
$rdSelectedSlotPost = strtoupper(mrl_post_value('rd_selected_slot'));
$rdSelectedDriverPost = mrl_post_value('rd_selected_driver');

$activeSegment = ($pickTypeOverride === 'RD' && $rdSegmentPost !== '')
    ? $rdSegmentPost
    : (string)$segment;

if ($submissionId === '') {
    $submissionId = 'sub_' . date('Ymd_His');
}

if ($formID === '') {
    $formID = 'form-team-picks.php';
}

if ($formVersion === '') {
    $formVersion = $scriptVersion;
}

if ($driverA === '' || $driverB === '' || $driverC === '' || $driverD === '') {
    header('Location: /team.php#current_user_team_chart');
    exit;
}

$teamName = mrl_get_team_name($dbconnect, $uid, $raceYearStr);
if ($teamName === '') {
    header('Location: /team.php#current_user_team_chart');
    exit;
}

$currentTime = date('Y-m-d H:i:s');
$ip = mrl_get_client_ip();

$pickType = 'SEG';
$effectiveRace = 0;
$supersedesPickID = null;
$existingPickID = null;
$exists = false;

if ($pickTypeOverride === 'RD') {
    $pickType = 'RD';
    $effectiveRace = mrl_parse_effective_race_value($rdEffectiveRacePost);

    if (
        $effectiveRace <= 0
        || !in_array($rdSelectedSlotPost, ['A','B','C','D'], true)
        || $rdSelectedDriverPost === ''
        || $activeSegment === ''
    ) {
        mrl_rd_reject();
    }

    // Deadline protection belongs on the server too, not just on team.php.
    // TESTPHP8 LP→RP edge-case fixture may use the controlled historical R08 window.
    $rdDeadlineOpen = mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace);

PHP;

        $prepared = str_replace($brokenTransition, $restoredSetup, $prepared, $nTransition);
        if ($nTransition !== 1) {
            throw new RuntimeException('Structural transition replacement count was not 1.');
        }

        // Prepared structural assertions.
        $assertions = [
            substr_count($prepared, 'function mrl_rd_reject(): void') === 1,
            substr_count($prepared, '$rdSelectedSlotPost = strtoupper(mrl_post_value(\'rd_selected_slot\'));') === 1,
            substr_count($prepared, '$rdSelectedDriverPost = mrl_post_value(\'rd_selected_driver\');') === 1,
            substr_count($prepared, "if (\$pickTypeOverride === 'RD') {") === 1,
            substr_count($prepared, $actualBaseNew) === 1,
            strpos($prepared, 'MRL_LP_RP_REAL_ERROR_CAPTURE_20260823_130200') === false,
            strpos($prepared, 'MRL_LP_RP_STRUCTURAL_REPAIR_20260823_140300') !== false,
            strpos($prepared, 'VERSION: v010') !== false,
            strpos($prepared, '$activeSegment =') > strpos($prepared, '$rdSelectedSlotPost ='),
        ];

        foreach ($assertions as $ok) {
            if (!$ok) {
                throw new RuntimeException('One or more prepared structural assertions failed.');
            }
        }

    } catch (Throwable $e) {
        $errors[] = 'Preparation failed: ' . $e->getMessage();
    }
}

if ($action === 'install' && empty($errors)) {
    try {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Could not create backup directory.');
        }

        if (!@copy($submitPath, $backupPath)) {
            throw new RuntimeException('Could not back up current submit-team-picks.php.');
        }

        rr_atomic_write($submitPath, $prepared);

        $after = rr_read($submitPath);

        $post = [
            ['submit reports temporary v010', strpos($after, 'VERSION: v010') !== false],
            ['old error-capture instrumentation removed', strpos($after, 'MRL_LP_RP_REAL_ERROR_CAPTURE_20260823_130200') === false],
            ['repair marker present', strpos($after, 'MRL_LP_RP_STRUCTURAL_REPAIR_20260823_140300') !== false],
            ['mrl_rd_reject restored exactly once', substr_count($after, 'function mrl_rd_reject(): void') === 1],
            ['RD selected slot POST read exactly once', substr_count($after, '$rdSelectedSlotPost = strtoupper(mrl_post_value(\'rd_selected_slot\'));') === 1],
            ['RD selected driver POST read exactly once', substr_count($after, '$rdSelectedDriverPost = mrl_post_value(\'rd_selected_driver\');') === 1],
            ['activeSegment derived after RD POST fields', strpos($after, '$activeSegment =') > strpos($after, '$rdSelectedSlotPost =')],
            ['opening RD branch restored exactly once', substr_count($after, "if (\$pickTypeOverride === 'RD') {") === 1],
            ['actual RD base lookup accepts LP exactly once', substr_count($after, $actualBaseNew) === 1],
            ['exact edge fixture bypass still present', strpos($after, 'BE_LIKE_BIFF_LP_AJ_R06_R07') !== false],
            ['team.php remains v026', strpos(rr_read($teamPath), 'VERSION: v026') !== false],
            ['wrapper remains v010', strpos(rr_read($wrapperPath), 'VERSION: v010') !== false],
            ['helper remains v005', strpos(rr_read($helperPath), 'VERSION: v005') !== false],
        ];

        foreach ($post as $pf) {
            if (!$pf[1]) {
                throw new RuntimeException('Postflight failed: ' . $pf[0]);
            }
        }

        $messages[] = 'STRUCTURAL REPAIR INSTALLED successfully.';
        $messages[] = 'submit-team-picks.php is now temporary v010.';
        $messages[] = 'No database writes were made by this installer.';
        $messages[] = 'All older LP→RP submit installers/diagnostics remain obsolete.';

    } catch (Throwable $e) {
        if (is_file($backupPath)) {
            @copy($backupPath, $submitPath);
        }

        $errors[] = 'INSTALL FAILED: ' . $e->getMessage();
        $errors[] = 'Automatic rollback to the pre-repair v009 was attempted.';
    }
}

$now = is_file($submitPath) ? rr_read($submitPath) : '';
$installedNow =
    strpos($now, 'VERSION: v010') !== false
    && strpos($now, 'MRL_LP_RP_STRUCTURAL_REPAIR_20260823_140300') !== false
    && substr_count($now, "if (\$pickTypeOverride === 'RD') {") === 1
    && substr_count($now, $actualBaseNew) === 1;

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL LP → RP Submit Structural Repair <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1200px;margin:0 auto;padding:14px}
.banner{background:#24351d;border:1px solid #547c3d;border-radius:10px;padding:12px 14px}
.banner h1{margin:0;color:#dfffcf;font-size:22px}
.sub{font-size:12px;color:#bdd4ae;margin-top:4px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#d5efc9;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333;vertical-align:top}
.ok{color:#69ef98;font-weight:700}
.bad{color:#ff7777;font-weight:700}
.warn{color:#ffd36b;font-weight:700}
.note{font-size:12px;color:#bbb}
.msg{background:#143725;border:1px solid #2f6c48;border-radius:8px;padding:9px 11px;margin-top:9px}
.err{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:9px 11px;margin-top:9px}
.callout{background:#4a2b00;border:2px solid #dc9425;padding:10px 12px;text-align:center;font-weight:bold;margin:9px 0}
button{background:#285c32;color:#eaffee;border:1px solid #4b9658;border-radius:7px;padding:10px 15px;font-weight:700;font-size:16px;cursor:pointer}
code{color:#f2d996}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
    <h1>MRL LP → RP SUBMIT STRUCTURAL REPAIR v001</h1>
    <div class="sub">TESTPHP8 ONLY • one controlled path • repair current v009 → temporary v010 • no DB writes</div>
</div>

<?php foreach ($messages as $m): ?>
<div class="msg"><?=rrh($m)?></div>
<?php endforeach; ?>

<?php foreach ($errors as $e): ?>
<div class="err"><?=rrh($e)?></div>
<?php endforeach; ?>

<div class="card">
<h2>Preflight / Current State</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:53%"><?=rrh($c[0])?></td>
<td style="width:10%" class="<?=$c[1]?'ok':'bad'?>"><?=$c[1]?'PASS':'FAIL'?></td>
<td><?=rrh($c[2])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>What This Repairs</h2>
<table>
<tr><td>Current v009</td><td>Missing the request/POST setup and opening RD branch before the RD validation body.</td></tr>
<tr><td>POST ordering</td><td>Restores v007 rule: read RD POST fields first, then derive activeSegment.</td></tr>
<tr><td>LP source</td><td>Actual RD four-driver base-row query accepts SEG, ADJ, or LP.</td></tr>
<tr><td>Historical test time</td><td>Exact owned Be Like Biff / AJ / S1 / R08 fixture bypass remains test-only.</td></tr>
<tr><td>Error capture</td><td>Temporary logging instrumentation is removed.</td></tr>
<tr><td>Database</td><td>No DB write by this installer; preflight requires RD rows/history = 0.</td></tr>
</table>
</div>

<?php if (empty($errors) && !$installedNow): ?>
<div class="card">
<h2>Ready</h2>
<div class="callout">
THIS IS THE ONLY INSTALLER TO USE NOW.<br>
All older LP→RP submit installers and diagnostics are obsolete.
</div>
<form method="post">
<input type="hidden" name="action" value="install">
<button type="submit">INSTALL STRUCTURAL REPAIR</button>
</form>
</div>
<?php endif; ?>

<?php if ($installedNow): ?>
<div class="card">
<h2 class="ok">STRUCTURAL REPAIR IS INSTALLED</h2>
<div class="callout">
NEXT: open normal TESTPHP8 /team.php and Ctrl+F5.<br>
Do NOT submit yet. First confirm the LP row and Replacement Pick form still display normally.
</div>
<div class="note">
Backup: <code><?=rrh($backupPath)?></code><br>
Scheduler stays OFF.
</div>
</div>
<?php endif; ?>

</div>
</body>
</html>
