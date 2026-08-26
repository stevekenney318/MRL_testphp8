<?php
/**
 * MRL TESTPHP8 — RP LATE-SUBMISSION SERVER-GATE TEST
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 3:16:00 am
 *
 * PURPOSE
 * -------
 * One-click confirmation that submit-team-picks.php rejects an RP submission
 * after the effective race has started, even if the normal RP form is bypassed.
 *
 * CONTROLLED TEST CASE
 * --------------------
 * Team: Be Like Biff
 * Segment: S1
 * Original eligible driver: Group B — Denny Hamlin
 * Current RP: AJ Allmendinger
 * Effective race: R08
 * Attempted late edit: Austin Dillon
 *
 * SAFETY
 * ------
 * - TESTPHP8 ONLY.
 * - Requires the existing POST-DEADLINE test phase:
 *     team.php v026
 *     submit-team-picks.php v009
 * - Requires the exact owned single-driver fixture and marker.
 * - This test page itself performs no DB writes.
 * - It snapshots the current RD row + RD history count, then sends one
 *   direct POST to submit-team-picks.php in a hidden iframe.
 * - PASS requires both user_picks and user_picks_history to remain unchanged.
 *
 * IMPORTANT
 * ---------
 * Keep the scheduler OFF.
 * Do not remove the single-driver RP test mode until this test passes.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$FIXTURE_ID = 'BE_LIKE_BIFF_DENNY_SINGLE_R06_R07';
$SNAPSHOT_KEY = 'mrl_rp_late_submit_snapshot_v001';

function lt_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function lt_is_testphp8_host(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' && strpos($host, 'testphp8.manliusracingleague.com') !== false;
}

function lt_read(string $path): string {
    $s = @file_get_contents($path);
    return $s === false ? '' : $s;
}

function lt_add_check(array &$checks, string $name, bool $ok, string $detail = ''): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

function lt_find_fixture(string $root): array {
    $dirs = glob($root . '/race_results/2026/R07_*', GLOB_ONLYDIR);
    if (!is_array($dirs) || count($dirs) !== 1) {
        return ['', '', '', count((array)$dirs)];
    }

    $dir = $dirs[0];

    return [
        $dir,
        $dir . '/_rd_pending_Be_Like_Biff.json',
        $dir . '/_rd_pending_Be_Like_Biff.single_fixture_marker_20260822_090600pm.json',
        1,
    ];
}

function lt_fixture_exact(string $path): bool {
    if (!is_file($path)) return false;

    $raw = @file_get_contents($path);
    if ($raw === false) return false;

    $p = json_decode($raw, true);
    if (!is_array($p)) return false;

    return !empty($p['test_fixture'])
        && (string)($p['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_SINGLE_R06_R07'
        && (string)($p['teamName'] ?? '') === 'Be Like Biff'
        && (string)($p['segment'] ?? '') === 'S1'
        && (string)($p['effective_race'] ?? '') === 'R08'
        && (int)($p['qualifier_count'] ?? 0) === 1
        && (string)($p['qualifiers'][0]['slot'] ?? '') === 'B'
        && (string)($p['qualifiers'][0]['driver'] ?? '') === 'Denny Hamlin';
}

function lt_get_team_name(mysqli $db, int $uid, string $year): string {
    $sql = "SELECT teamName FROM user_teams WHERE userID = ? AND raceYear = ? LIMIT 1";
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) return '';

    mysqli_stmt_bind_param($stmt, 'is', $uid, $year);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? trim((string)($row['teamName'] ?? '')) : '';
}

function lt_get_current_rd(mysqli $db, int $uid): ?array {
    $year = '2026';
    $segment = 'S1';

    $sql = "
        SELECT
            pickID, teamName, driverA, driverB, driverC, driverD,
            entryDate, submission_id, pick_type, effective_race, supersedes_pickID
        FROM user_picks
        WHERE userID = ?
          AND raceYear = ?
          AND segment = ?
          AND pick_type = 'RD'
        ORDER BY pickID DESC
        LIMIT 1
    ";

    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, 'iss', $uid, $year, $segment);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}

function lt_get_rd_history_count(mysqli $db, int $uid): int {
    $year = '2026';
    $segment = 'S1';

    $sql = "
        SELECT COUNT(*) AS cnt
        FROM user_picks_history
        WHERE userID = ?
          AND raceYear = ?
          AND segment = ?
          AND pick_type = 'RD'
    ";

    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) return -1;

    mysqli_stmt_bind_param($stmt, 'iss', $uid, $year, $segment);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? (int)($row['cnt'] ?? -1) : -1;
}

function lt_rd_signature(array $row): string {
    $fields = [
        'pickID','teamName','driverA','driverB','driverC','driverD',
        'entryDate','submission_id','pick_type','effective_race','supersedes_pickID'
    ];

    $parts = [];
    foreach ($fields as $f) {
        $parts[] = $f . '=' . (string)($row[$f] ?? '');
    }

    return implode('|', $parts);
}

$root = __DIR__;
$submitPath = $root . '/submit-team-picks.php';
$teamPath = $root . '/team.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$checks = [];
$errors = [];
$verifyMode = isset($_GET['verify']) && $_GET['verify'] === '1';
$verified = false;
$pass = false;
$verifyDetail = '';

if (!lt_is_testphp8_host()) {
    $errors[] = 'REFUSED: this page may run only on testphp8.manliusracingleague.com.';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

if (!isset($dbconnect) || !($dbconnect instanceof mysqli)) {
    $errors[] = 'Database connection is unavailable.';
}

$uid = isset($_SESSION['userSession']) ? (int)$_SESSION['userSession'] : 0;
if ($uid <= 0) {
    $errors[] = 'Logged-in user ID is unavailable.';
}

list($raceDir, $fixturePath, $markerPath, $raceDirCount) = lt_find_fixture($root);

$submitSrc = lt_read($submitPath);
$teamSrc = lt_read($teamPath);
$wrapperSrc = lt_read($wrapperPath);
$helperSrc = lt_read($helperPath);

$teamName = ($uid > 0 && isset($dbconnect) && $dbconnect instanceof mysqli)
    ? lt_get_team_name($dbconnect, $uid, '2026')
    : '';

$currentRd = ($uid > 0 && isset($dbconnect) && $dbconnect instanceof mysqli)
    ? lt_get_current_rd($dbconnect, $uid)
    : null;

$historyCount = ($uid > 0 && isset($dbconnect) && $dbconnect instanceof mysqli)
    ? lt_get_rd_history_count($dbconnect, $uid)
    : -1;

lt_add_check($checks, 'TESTPHP8 host', lt_is_testphp8_host(), (string)($_SERVER['HTTP_HOST'] ?? ''));
lt_add_check($checks, 'Exactly one R07 race folder found', $raceDirCount === 1, $raceDirCount === 1 ? basename($raceDir) : 'found ' . $raceDirCount);
lt_add_check($checks, 'Exact single-driver fixture JSON exists', $fixturePath !== '' && lt_fixture_exact($fixturePath), $fixturePath !== '' ? basename($fixturePath) : '');
lt_add_check($checks, 'Single-driver fixture marker exists', $markerPath !== '' && is_file($markerPath), $markerPath !== '' ? basename($markerPath) : '');
lt_add_check($checks, 'team.php is post-deadline test v026', strpos($teamSrc, 'VERSION: v026') !== false && strpos($teamSrc, '$rdDeadlineTimestamp + 60') !== false, 'artificial time = R08 start + 60 seconds');
lt_add_check($checks, 'submit-team-picks.php is deadline-test v009', strpos($submitSrc, 'VERSION: v009') !== false && strpos($submitSrc, 'MRL_RP_DEADLINE_TEST_SERVER_GATE') !== false, 'server-side deadline bypass removed');
lt_add_check($checks, 'team_replacement_driver.php remains v010', strpos($wrapperSrc, 'VERSION: v010') !== false, '');
lt_add_check($checks, 'race_results_rd_helper.php remains v005', strpos($helperSrc, 'VERSION: v005') !== false, '');
lt_add_check($checks, 'Logged-in team is Be Like Biff', $teamName === 'Be Like Biff', $teamName);
lt_add_check($checks, 'Current S1 RD row exists', is_array($currentRd), is_array($currentRd) ? 'pickID ' . (string)$currentRd['pickID'] : 'not found');
lt_add_check(
    $checks,
    'Current RP is Group B — AJ Allmendinger',
    is_array($currentRd)
        && (string)($currentRd['driverB'] ?? '') === 'AJ Allmendinger'
        && (string)($currentRd['pick_type'] ?? '') === 'RD'
        && (int)($currentRd['effective_race'] ?? 0) === 8,
    is_array($currentRd)
        ? 'driverB=' . (string)($currentRd['driverB'] ?? '') . ', effective R' . (string)($currentRd['effective_race'] ?? '')
        : ''
);
lt_add_check($checks, 'RD history count readable', $historyCount >= 0, (string)$historyCount);

foreach ($checks as $c) {
    if (!$c['ok']) {
        $errors[] = 'Preflight failed: ' . $c['name'];
    }
}

if ($verifyMode) {
    $verified = true;
    $snapshot = $_SESSION[$SNAPSHOT_KEY] ?? null;

    if (!is_array($snapshot)) {
        $verifyDetail = 'No saved before-test snapshot was found.';
    } elseif (!is_array($currentRd)) {
        $verifyDetail = 'Current RD row is missing after the test.';
    } else {
        $sameRow = hash_equals(
            (string)($snapshot['rd_signature'] ?? ''),
            lt_rd_signature($currentRd)
        );

        $sameHistory = (int)($snapshot['history_count'] ?? -999) === $historyCount;

        $markerAbsent =
            (string)($currentRd['submission_id'] ?? '') !== (string)($snapshot['attempt_submission_id'] ?? '');

        $pass = $sameRow && $sameHistory && $markerAbsent;

        if ($pass) {
            $verifyDetail =
                'Late RP POST was rejected: user_picks is unchanged and no new RD history row was created.';
        } else {
            $verifyDetail =
                'FAIL: the late POST changed the current RD row and/or RD history. Restore clean_baseline before continuing.';
        }
    }
} elseif (empty($errors) && is_array($currentRd)) {
    $attemptSubmissionId = 'late_rp_deadline_test_' . date('Ymd_His');

    $_SESSION[$SNAPSHOT_KEY] = [
        'created_at' => date('Y-m-d H:i:s'),
        'rd_signature' => lt_rd_signature($currentRd),
        'history_count' => $historyCount,
        'attempt_submission_id' => $attemptSubmissionId,
    ];
}

$snapshot = $_SESSION[$SNAPSHOT_KEY] ?? [];
$attemptSubmissionId = (string)($snapshot['attempt_submission_id'] ?? ('late_rp_deadline_test_' . date('Ymd_His')));
$supersedesId = is_array($currentRd) ? (int)($currentRd['supersedes_pickID'] ?? 0) : 0;

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL RP Late-Submission Server-Gate Test <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:14px}
.banner{background:#21331b;border:1px solid #5e844d;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#caffb8;font-size:22px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333}
.ok{color:#69ef98;font-weight:bold}
.bad{color:#ff7777;font-weight:bold}
.warn{color:#ffd36b;font-weight:bold}
code{color:#f2d996}
button{padding:9px 14px;border-radius:7px;font-weight:bold;cursor:pointer}
.install{background:#315f26;color:#efffe9;border:1px solid #65a954}
iframe{display:none}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL RP Late-Submission Server-Gate Test v001</h1>
<div>TESTPHP8 ONLY • Be Like Biff • R08 deadline already passed • one direct late RP POST • no DB writes expected</div>
</div>

<div class="card">
<h2>Test Case</h2>
<table>
<tr><td>Team</td><td>Be Like Biff</td></tr>
<tr><td>Original eligible driver</td><td>Group B — Denny Hamlin</td></tr>
<tr><td>Current RP</td><td>AJ Allmendinger</td></tr>
<tr><td>Effective race</td><td>R08</td></tr>
<tr><td>Artificial page time</td><td>R08 start + 1 minute</td></tr>
<tr><td>Attempted late edit</td><td>Austin Dillon</td></tr>
<tr><td>Expected result</td><td>submit-team-picks.php rejects the POST; DB and history remain unchanged</td></tr>
</table>
</div>

<div class="card">
<h2>Preflight</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:55%"><?=lt_h($c['name'])?></td>
<td style="width:10%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
<td><?=lt_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
<h2 class="bad">STOPPED SAFELY</h2>
<?php foreach ($errors as $e): ?><div class="bad">• <?=lt_h($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($verified): ?>
<div class="card">
<h2 class="<?=$pass?'ok':'bad'?>"><?=$pass?'PASS — LATE RP SERVER-SIDE REJECTION VERIFIED':'FAIL — LATE RP POST WAS NOT SAFELY REJECTED'?></h2>
<table>
<tr><td>Current RP after test</td><td><?=lt_h(is_array($currentRd) ? ($currentRd['driverB'] ?? '') : '')?></td></tr>
<tr><td>Current RD entryDate after test</td><td><?=lt_h(is_array($currentRd) ? ($currentRd['entryDate'] ?? '') : '')?></td></tr>
<tr><td>RD history rows after test</td><td><?=lt_h((string)$historyCount)?></td></tr>
<tr><td>Result</td><td class="<?=$pass?'ok':'bad'?>"><?=lt_h($verifyDetail)?></td></tr>
</table>
<?php if ($pass): ?>
<p class="warn">Deadline enforcement is now fully proven: UI closed + direct server-side late POST rejected.</p>
<?php else: ?>
<p class="bad">Do not continue until the DB is restored from clean_baseline and the failure is reviewed.</p>
<?php endif; ?>
</div>
<?php elseif (empty($errors)): ?>
<div class="card">
<h2>Ready</h2>
<p>This bypasses the hidden RP form and directly attempts one late change from <strong>AJ Allmendinger</strong> to <strong>Austin Dillon</strong>.</p>
<p class="warn">Expected: nothing changes. The page will automatically return here and report PASS/FAIL.</p>

<form id="lateRpForm" method="post" action="/submit-team-picks.php" target="lateRpTarget">
<input type="hidden" name="group-a-driver" value="<?=lt_h((string)$currentRd['driverA'])?>">
<input type="hidden" name="group-b-driver" value="Austin Dillon">
<input type="hidden" name="group-c-driver" value="<?=lt_h((string)$currentRd['driverC'])?>">
<input type="hidden" name="group-d-driver" value="<?=lt_h((string)$currentRd['driverD'])?>">
<input type="hidden" name="submission_id" value="<?=lt_h($attemptSubmissionId)?>">
<input type="hidden" name="form_id" value="team_replacement_driver.php">
<input type="hidden" name="form_version" value="v010">
<input type="hidden" name="pick_type_override" value="RD">
<input type="hidden" name="rd_segment" value="S1">
<input type="hidden" name="rd_effective_race" value="R08">
<input type="hidden" name="rd_supersedes_pick_id" value="<?=lt_h((string)$supersedesId)?>">
<input type="hidden" name="rd_selected_slot" value="B">
<input type="hidden" name="rd_selected_driver" value="Denny Hamlin">

<button class="install" type="submit">ATTEMPT LATE RP SUBMISSION</button>
</form>

<iframe name="lateRpTarget" id="lateRpTarget"></iframe>
</div>

<script>
(function () {
    var started = false;
    var form = document.getElementById('lateRpForm');
    var frame = document.getElementById('lateRpTarget');

    form.addEventListener('submit', function () {
        started = true;
    });

    frame.addEventListener('load', function () {
        if (!started) return;
        started = false;
        window.setTimeout(function () {
            window.location.href = window.location.pathname + '?verify=1';
        }, 500);
    });
})();
</script>
<?php endif; ?>

<div class="card">
<h2>Before / Current DB Reference</h2>
<table>
<tr><td>RD pickID</td><td><?=lt_h(is_array($currentRd) ? (string)$currentRd['pickID'] : '')?></td></tr>
<tr><td>Current RP</td><td><?=lt_h(is_array($currentRd) ? (string)$currentRd['driverB'] : '')?></td></tr>
<tr><td>Current RD entryDate</td><td><?=lt_h(is_array($currentRd) ? (string)$currentRd['entryDate'] : '')?></td></tr>
<tr><td>Current RD history rows</td><td><?=lt_h((string)$historyCount)?></td></tr>
<tr><td>Test submission marker</td><td><code><?=lt_h($attemptSubmissionId)?></code></td></tr>
</table>
</div>

<div class="card">
<p class="warn"><strong>Keep the scheduler OFF.</strong> Do not remove SINGLE-DRIVER RP TEST MODE until this page reports PASS.</p>
</div>

</div>
</body>
</html>
