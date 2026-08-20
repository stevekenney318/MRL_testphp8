<?php
declare(strict_types=1);

/**
 * mrl_lp_admin_adjustment_installer.php
 * VERSION: v002
 * LAST MODIFIED: 8/19/2026 7:34:00 pm
 *
 * TestPHP8-only installer for admin approval of LP picks as regular picks.
 *
 * Installs:
 * - NEW admin_pick_adjustment.php v001
 * - team.php v019 -> v020
 * - current_user_team_chart.php v003 -> v004
 * - team_chart.php v015 -> v016
 * - current_segment_chart_by_entry_time.php v002 -> v003
 *
 * Approval behavior:
 * - current user_picks: LP -> SEG
 * - current effective_race -> segment start race
 * - original entryDate is preserved
 * - original LP history is untouched
 * - new history row uses pick_type=ADJ
 * - charts show "* Admin-approved regular pick"
 *
 * No DB schema changes.
 */

date_default_timezone_set('America/New_York');

const INSTALLER_VERSION = 'v002';
const INSTALLER_TIMESTAMP = '20260819_073400pm';
const REQUIRED_HOST = 'testphp8.manliusracingleague.com';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function ih(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly once, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}

function atomic_write(string $path, string $content): void
{
    $tmp = dirname($path) . '/.' . basename($path) . '.lp_adj_' . mt_rand(100000, 999999) . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temp file for ' . basename($path));
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace ' . basename($path));
    }
}

function admin_page_source(): string
{
return <<<'ADMIN'
<?php
declare(strict_types=1);

/**
 * admin_pick_adjustment.php
 * VERSION: v001
 * LAST MODIFIED: 8/19/2026 7:12:00 pm
 *
 * Admin-only LP -> approved regular pick tool.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('America/New_York');
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/admin_pick_adjustment.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

$adminUid = (int)($_SESSION['userSession'] ?? 0);
if (!isAdmin($adminUid)) {
    echo '<div style="color:#ff7373;background:#141414;padding:20px;font:18px Arial">You are NOT authorized to view/use this page.</div>';
    exit;
}

function apa_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function apa_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $p = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim((string)$p[0]);
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function apa_segment_start(mysqli $db, int $year, string $segment): int
{
    $stmt = mysqli_prepare($db, "SELECT startRace FROM segment_race_ranges WHERE raceYear=? AND segment=? LIMIT 1");
    if (!$stmt) throw new RuntimeException('Unable to prepare segment start lookup.');
    mysqli_stmt_bind_param($stmt, 'is', $year, $segment);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $startRace);
    if (!mysqli_stmt_fetch($stmt)) {
        mysqli_stmt_close($stmt);
        throw new RuntimeException('No segment range found for ' . $year . ' ' . $segment . '.');
    }
    mysqli_stmt_close($stmt);
    $start = (int)$startRace;
    if ($start <= 0) throw new RuntimeException('Invalid segment start race.');
    return $start;
}

function apa_lp_for_update(mysqli $db, int $pickID): ?array
{
    $sql = "SELECT pickID,userID,teamName,raceYear,segment,driverA,driverB,driverC,driverD,
                   entryDate,submission_id,ip,formID,pick_type,effective_race,supersedes_pickID
            FROM user_picks
            WHERE pickID=? AND pick_type='LP'
            LIMIT 1 FOR UPDATE";
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare LP lookup.');
    mysqli_stmt_bind_param($stmt, 'i', $pickID);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return is_array($row) ? $row : null;
}

function apa_has_adj(mysqli $db, int $userID, string $year, string $segment): bool
{
    $stmt = mysqli_prepare($db, "SELECT pickID FROM user_picks_history
                                 WHERE userID=? AND raceYear=? AND segment=? AND pick_type='ADJ'
                                   AND formID LIKE 'admin_pick_adjustment.php%'
                                 LIMIT 1");
    if (!$stmt) throw new RuntimeException('Unable to prepare ADJ history lookup.');
    mysqli_stmt_bind_param($stmt, 'iss', $userID, $year, $segment);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $found ? true : false;
}

$message = '';
$messageClass = 'good';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'approve_lp') {
    $pickID = (int)($_POST['pickID'] ?? 0);

    try {
        if ($pickID <= 0) throw new RuntimeException('No valid LP pick was selected.');

        mysqli_begin_transaction($dbconnect);

        $row = apa_lp_for_update($dbconnect, $pickID);
        if (!is_array($row)) {
            throw new RuntimeException('Selected current row is no longer an LP. No change made.');
        }

        $userID = (int)$row['userID'];
        $yearStr = (string)$row['raceYear'];
        $yearInt = (int)$row['raceYear'];
        $segment = strtoupper(trim((string)$row['segment']));
        $startRace = apa_segment_start($dbconnect, $yearInt, $segment);

        if (apa_has_adj($dbconnect, $userID, $yearStr, $segment)) {
            throw new RuntimeException('An ADJ history record already exists for this team/year/segment.');
        }

        $teamName = (string)$row['teamName'];
        $driverA = (string)$row['driverA'];
        $driverB = (string)$row['driverB'];
        $driverC = (string)$row['driverC'];
        $driverD = (string)$row['driverD'];
        $entryDate = (string)$row['entryDate'];

        $submissionID = 'adj_' . date('Ymd_His') . '_p' . $pickID;
        $auditIp = apa_ip();
        $formID = 'admin_pick_adjustment.php v001';
        $adj = 'ADJ';
        $supersedes = $pickID;

        $sqlH = "INSERT INTO user_picks_history
                 (userID,teamName,raceYear,segment,driverA,driverB,driverC,driverD,
                  entryDate,submission_id,ip,formID,pick_type,effective_race,supersedes_pickID)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmtH = mysqli_prepare($dbconnect, $sqlH);
        if (!$stmtH) throw new RuntimeException('Unable to prepare ADJ history insert.');

        mysqli_stmt_bind_param(
            $stmtH, 'issssssssssssii',
            $userID,$teamName,$yearStr,$segment,$driverA,$driverB,$driverC,$driverD,
            $entryDate,$submissionID,$auditIp,$formID,$adj,$startRace,$supersedes
        );

        if (!mysqli_stmt_execute($stmtH)) {
            $err = mysqli_stmt_error($stmtH);
            mysqli_stmt_close($stmtH);
            throw new RuntimeException('ADJ history insert failed: ' . $err);
        }
        mysqli_stmt_close($stmtH);

        $seg = 'SEG';
        $stmtU = mysqli_prepare($dbconnect, "UPDATE user_picks
                                             SET pick_type=?, effective_race=?
                                             WHERE pickID=? AND pick_type='LP'");
        if (!$stmtU) throw new RuntimeException('Unable to prepare current-pick update.');
        mysqli_stmt_bind_param($stmtU, 'sii', $seg, $startRace, $pickID);

        if (!mysqli_stmt_execute($stmtU)) {
            $err = mysqli_stmt_error($stmtU);
            mysqli_stmt_close($stmtU);
            throw new RuntimeException('Current-pick update failed: ' . $err);
        }
        if (mysqli_stmt_affected_rows($stmtU) !== 1) {
            mysqli_stmt_close($stmtU);
            throw new RuntimeException('Expected exactly one current LP row to change.');
        }
        mysqli_stmt_close($stmtU);

        mysqli_commit($dbconnect);

        $message = $teamName . ' ' . $yearStr . ' ' . $segment
                 . ' approved. Current row is SEG / Effective R' . $startRace
                 . '. Original submission timestamp ' . $entryDate . ' was preserved.';
    } catch (Throwable $e) {
        @mysqli_rollback($dbconnect);
        $message = $e->getMessage();
        $messageClass = 'bad';
    }
}

$lpRows = [];
$sql = "SELECT up.pickID,up.userID,up.teamName,up.raceYear,up.segment,
               up.driverA,up.driverB,up.driverC,up.driverD,
               up.entryDate,up.effective_race,
               COALESCE(u.userName,'') AS userName,
               s.startRace
        FROM user_picks up
        LEFT JOIN users u ON u.userID=up.userID
        LEFT JOIN segment_race_ranges s
          ON s.raceYear=up.raceYear AND s.segment=up.segment
        WHERE up.pick_type='LP'
        ORDER BY up.raceYear DESC,s.startRace DESC,up.userID ASC";
$res = mysqli_query($dbconnect, $sql);
while ($res && ($r = mysqli_fetch_assoc($res))) $lpRows[] = $r;

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isTest = strpos($host, 'testphp8.') === 0;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL Admin Pick Adjustment</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#151515;color:#eee;font:15px Tahoma,Verdana,Segoe,sans-serif}
.wrap{width:96%;max-width:1500px;margin:12px auto 30px}
.env{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:12px 16px;border-radius:14px;margin-bottom:12px}
.env.test{border:1px solid #9a7014;background:linear-gradient(180deg,#3a3118,#282417)}
.env.live{border:1px solid #326a49;background:linear-gradient(180deg,#193124,#1b211e)}
.env-title{font-size:24px;font-weight:800;letter-spacing:1.2px;color:#fff4df}
.env-domain{font-size:13px;color:#ddd}.env-page{margin-top:5px;color:#ffd08a;font-weight:bold;font-size:13px}
.pill{border:1px solid #826820;background:#4a3c19;color:#ffd166;border-radius:999px;padding:6px 12px;font-weight:bold}
.card{background:#222;border:1px solid #4d473f;border-radius:14px;padding:14px 16px;margin-bottom:12px}
h2{margin:0 0 10px;color:#ffd08a;font-size:19px}
.good{color:#62e89b}.bad{color:#ff8080}.msg{font-weight:bold;padding:9px 12px;border-radius:9px;background:#191919;margin-bottom:12px}
table{width:100%;border-collapse:collapse;background:#1c1c1c}
th,td{padding:8px 9px;border-bottom:1px solid #3c3c3c;text-align:left;vertical-align:top}
th{color:#ffd08a;background:#24211d;font-size:13px}
.small{font-size:12px;color:#bbb}.before{color:#ffd166}.after{color:#62e89b;font-weight:bold}
.btn{background:#3a2f1b;color:#ffd08a;border:1px solid #b18745;border-radius:8px;padding:8px 11px;cursor:pointer;font-weight:bold}
.drivers{white-space:nowrap}.empty{padding:22px;text-align:center;color:#aaa}
</style>
</head>
<body>
<div class="wrap">
<div class="env <?php echo $isTest ? 'test' : 'live'; ?>">
  <div>
    <div class="env-title"><?php echo $isTest ? 'TESTPHP8 / DEMO SITE' : 'LIVE MRL SITE'; ?></div>
    <div class="env-domain"><?php echo $isTest ? 'testphp8.manliusracingleague.com' : 'manliusracingleague.com'; ?></div>
    <div class="env-page">MRL Admin Pick Adjustment</div>
  </div>
  <div class="pill"><?php echo $isTest ? 'Demo / test data' : 'Production site'; ?></div>
</div>

<?php if ($message !== ''): ?>
<div class="msg <?php echo apa_h($messageClass); ?>"><?php echo apa_h($message); ?></div>
<?php endif; ?>

<div class="card">
<h2>LP → Admin-approved regular pick</h2>
<div class="small">Approval keeps the real submission timestamp. Current row becomes SEG and effective from the segment start race. Original LP history stays untouched; a new ADJ history row records the approval.</div>
</div>

<div class="card">
<h2>Current Late Picks</h2>
<?php if (!$lpRows): ?>
<div class="empty">No current LP picks are available for adjustment.</div>
<?php else: ?>
<table>
<thead><tr>
<th>Team / Owner</th><th>Year / Segment</th><th>Drivers</th><th>Original Submission</th>
<th>Current</th><th>After Approval</th><th>Action</th>
</tr></thead>
<tbody>
<?php foreach ($lpRows as $r): ?>
<tr>
<td><b><?php echo apa_h($r['teamName']); ?></b><br><span class="small"><?php echo apa_h($r['userName']); ?> · userID <?php echo (int)$r['userID']; ?> · pickID <?php echo (int)$r['pickID']; ?></span></td>
<td><?php echo apa_h($r['raceYear']); ?> / <?php echo apa_h($r['segment']); ?></td>
<td class="drivers">A: <?php echo apa_h($r['driverA']); ?><br>B: <?php echo apa_h($r['driverB']); ?><br>C: <?php echo apa_h($r['driverC']); ?><br>D: <?php echo apa_h($r['driverD']); ?></td>
<td><?php echo apa_h($r['entryDate']); ?><br><span class="small">will not change</span></td>
<td class="before">LP<br>Effective R<?php echo (int)$r['effective_race']; ?></td>
<td class="after">SEG<br>Effective R<?php echo (int)$r['startRace']; ?><br><span class="small">+ ADJ history</span></td>
<td>
<?php if ((int)$r['startRace'] > 0): ?>
<form method="post" onsubmit="return confirm('Approve this LP as a regular segment pick? Original timestamp will be preserved and an ADJ history row will be created.');">
<input type="hidden" name="action" value="approve_lp">
<input type="hidden" name="pickID" value="<?php echo (int)$r['pickID']; ?>">
<button class="btn" type="submit">Approve as Regular Pick</button>
</form>
<?php else: ?><span class="bad">Missing segment start</span><?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
</body>
</html>
ADMIN;
}

function patch_team(string $src): string
{
    $src = replace_once($src,
        " * VERSION: v019\n * LAST MODIFIED: 8/19/2026 4:51:53 am\n",
        " * VERSION: v020\n * LAST MODIFIED: 8/19/2026 7:12:00 pm\n",
        'team header');

    $src = replace_once($src,
        " * CHANGELOG:\n *\n * v019 (8/19/2026 4:51:53 am)\n",
        " * CHANGELOG:\n *\n * v020 (8/19/2026 7:12:00 pm)\n * - NEW: Admin menu link to admin_pick_adjustment.php.\n * - CHANGE: No routing/scoring/LP/RD logic changes.\n *\n * v019 (8/19/2026 4:51:53 am)\n",
        'team changelog');

    return replace_once($src,
        "            <a href=\"/change_user_auth.php\" target=\"_blank\">- Toggle user status to make late picks or change driver</a>\n            <br>\n",
        "            <a href=\"/change_user_auth.php\" target=\"_blank\">- Toggle user status to make late picks or change driver</a>\n            <br>\n            <a href=\"/admin_pick_adjustment.php\" target=\"_blank\">- Approve LP as regular segment pick</a>\n            <br>\n",
        'team admin link');
}

function patch_cuytc(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v003\n * LAST MODIFIED: 8/19/2026 4:51:53 am\n",
        " * VERSION: v004\n * LAST MODIFIED: 8/19/2026 7:12:00 pm\n",
        'cuytc header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v003 (8/19/2026 4:51:53 am)\n",
        " * CHANGELOG:\n *\n * v004 (8/19/2026 7:12:00 pm)\n * - NEW: SEG rows with ADJ history show * Admin-approved regular pick.\n * - CHANGE: Preserved original timestamp and LP/RD display behavior.\n *\n * v003 (8/19/2026 4:51:53 am)\n",
        'cuytc changelog'
    );

    $oldSelect = <<<'OLD'
    $sql = "SELECT `pickID`, `pick_type`, `supersedes_pickID`, `effective_race`, `segment`, `driverA`, `driverB`, `driverC`, `driverD`, `entryDate`
            FROM `user_picks`
OLD;

    $newSelect = <<<'NEW'
    $sql = "SELECT `pickID`, `pick_type`, `supersedes_pickID`, `effective_race`, `segment`, `driverA`, `driverB`, `driverC`, `driverD`, `entryDate`,
                   EXISTS(SELECT 1 FROM `user_picks_history` h WHERE h.`userID`=$uid AND h.`raceYear`=$raceYear AND h.`segment`='$chartSegment' AND h.`pick_type`='ADJ' AND h.`formID` LIKE 'admin_pick_adjustment.php%') AS `admin_adjusted`
            FROM `user_picks`
NEW;

    $src = replace_once($src, $oldSelect, $newSelect, 'cuytc select');

    $oldFlag = <<<'OLD'
        $referenceRow = cuytc_get_reference_pick_row($row, $rowsByPickId, $baseRowsBySegment[$segment] ?? null);
        $marker = '';
OLD;

    $newFlag = <<<'NEW'
        $referenceRow = cuytc_get_reference_pick_row($row, $rowsByPickId, $baseRowsBySegment[$segment] ?? null);
        $adminAdjusted = !empty($row['admin_adjusted']);
        $marker = '';
NEW;

    $src = replace_once($src, $oldFlag, $newFlag, 'cuytc flag');

    $oldBranch = <<<'OLD'
        if ($pickType === 'LP') {
OLD;

    $newBranch = <<<'NEW'
        if ($adminAdjusted && ($pickType === 'SEG' || $pickType === '')) {
            $markerIndex++;
            $marker = cuytc_marker_symbol($markerIndex);
            $notes[] = ['marker' => $marker, 'text' => $segmentLabel . ' — Admin-approved regular pick'];
        } elseif ($pickType === 'LP') {
NEW;

    return replace_once($src, $oldBranch, $newBranch, 'cuytc note');
}

function patch_team_chart(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v015\n * LAST MODIFIED: 4/12/2026 12:59:28 am\n",
        " * VERSION: v016\n * LAST MODIFIED: 8/19/2026 7:12:00 pm\n",
        'team_chart header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v015 (4/12/2026)\n",
        " * CHANGELOG:\n *\n * v016 (8/19/2026 7:12:00 pm)\n * - NEW: SEG rows with ADJ history show * Admin-approved regular pick.\n * - CHANGE: Preserved LP/RD and spreadsheet behavior.\n *\n * v015 (4/12/2026)\n",
        'team_chart changelog'
    );

    $needle = <<<'OLD'
                    up.entryDate
                FROM user_picks up
OLD;

    $rep = <<<'NEW'
                    up.entryDate,
                    EXISTS(SELECT 1 FROM user_picks_history h WHERE h.userID=up.userID AND h.raceYear=up.raceYear AND h.segment=up.segment AND h.pick_type='ADJ' AND h.formID LIKE 'admin_pick_adjustment.php%') AS admin_adjusted
                FROM user_picks up
NEW;

    if (substr_count($src, $needle) !== 2) {
        throw new RuntimeException('team_chart SELECT marker count mismatch.');
    }
    $src = str_replace($needle, $rep, $src);

    $oldFlag = <<<'OLD'
        $referenceRow = tc_get_reference_pick_row($row, $rowsByPickId, $baseRowsByTeam[$teamName] ?? null);
        $marker = '';
OLD;

    $newFlag = <<<'NEW'
        $referenceRow = tc_get_reference_pick_row($row, $rowsByPickId, $baseRowsByTeam[$teamName] ?? null);
        $adminAdjusted = !empty($row['admin_adjusted']);
        $marker = '';
NEW;

    $src = replace_once($src, $oldFlag, $newFlag, 'team_chart flag');

    $oldBranch = <<<'OLD'
        if ($pickType === 'LP') {
OLD;

    $newBranch = <<<'NEW'
        if ($adminAdjusted && ($pickType === 'SEG' || $pickType === '')) {
            $markerIndex++;
            $marker = tc_marker_symbol($markerIndex);
            $notes[] = ['marker' => $marker, 'text' => $teamName . ' — Admin-approved regular pick'];
        } elseif ($pickType === 'LP') {
NEW;

    return replace_once($src, $oldBranch, $newBranch, 'team_chart note');
}

function patch_entry(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v002\n * LAST MODIFIED: 4/13/2026 3:24:07 pm\n",
        " * VERSION: v003\n * LAST MODIFIED: 8/19/2026 7:12:00 pm\n",
        'entry header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v002 (4/13/2026)\n",
        " * CHANGELOG:\n *\n * v003 (8/19/2026 7:12:00 pm)\n * - NEW: SEG rows with ADJ history show * Admin-approved regular pick.\n * - CHANGE: Preserved chronological LP/RD behavior.\n *\n * v002 (4/13/2026)\n",
        'entry changelog'
    );

    $oldSelect = <<<'OLD'
        up.entryDate
    FROM user_picks up
OLD;

    $newSelect = <<<'NEW'
        up.entryDate,
        EXISTS(SELECT 1 FROM user_picks_history h WHERE h.userID=up.userID AND h.raceYear=up.raceYear AND h.segment=up.segment AND h.pick_type='ADJ' AND h.formID LIKE 'admin_pick_adjustment.php%') AS admin_adjusted
    FROM user_picks up
NEW;

    $src = replace_once($src, $oldSelect, $newSelect, 'entry select');

    $oldFlag = <<<'OLD'
        $changedFields = cscet_get_changed_fields_for_rd($row, $referenceRow);
        $marker = '';
OLD;

    $newFlag = <<<'NEW'
        $changedFields = cscet_get_changed_fields_for_rd($row, $referenceRow);
        $adminAdjusted = !empty($row['admin_adjusted']);
        $marker = '';
NEW;

    $src = replace_once($src, $oldFlag, $newFlag, 'entry flag');

    $oldBranch = <<<'OLD'
        if ($pickType === 'LP') {
OLD;

    $newBranch = <<<'NEW'
        if ($adminAdjusted && ($pickType === 'SEG' || $pickType === '')) {
            $markerIndex++;
            $marker = cscet_marker_symbol($markerIndex);
            $noteText = $teamName . ' — Admin-approved regular pick';
            $notes[] = ['marker' => $marker, 'text' => $noteText];
        } elseif ($pickType === 'LP') {
NEW;

    return replace_once($src, $oldBranch, $newBranch, 'entry note');
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$paths = [
 'team.php'=>$root.'/team.php',
 'current_user_team_chart.php'=>$root.'/current_user_team_chart.php',
 'team_chart.php'=>$root.'/team_chart.php',
 'current_segment_chart_by_entry_time.php'=>$root.'/current_segment_chart_by_entry_time.php',
 'admin_pick_adjustment.php'=>$root.'/admin_pick_adjustment.php'
];

$checks=[]; $errors=[]; $prepared=[]; $current=[]; $installComplete=false; $backupDir='';

$checks[]=['Host is TestPHP8',$host===REQUIRED_HOST?'PASS':'FAIL'];
if ($host!==REQUIRED_HOST) $errors[]='Installer refuses to run outside TestPHP8.';

$expected=[
 'team.php'=>'VERSION: v019',
 'current_user_team_chart.php'=>'VERSION: v003',
 'team_chart.php'=>'VERSION: v015',
 'current_segment_chart_by_entry_time.php'=>'VERSION: v002'
];

foreach($expected as $file=>$marker){
    if(!is_file($paths[$file])){
        $checks[]=[$file.' exists','FAIL']; $errors[]=$file.' missing.'; continue;
    }
    $c=(string)file_get_contents($paths[$file]); $current[$file]=$c;
    $ok=strpos($c,$marker)!==false;
    $checks[]=[$file.' expected current version',$ok?'PASS':'FAIL'];
    if(!$ok) $errors[]=$file.' version mismatch.';
}

$absent=!is_file($paths['admin_pick_adjustment.php']);
$checks[]=['admin_pick_adjustment.php absent before first install',$absent?'PASS':'FAIL'];
if(!$absent) $errors[]='admin_pick_adjustment.php already exists.';

if(!$errors){
    try{
        require_once $root.'/config.php';
        if(!isset($dbconnect) || !($dbconnect instanceof mysqli)) throw new RuntimeException('DB connection unavailable.');

        foreach(['user_picks','user_picks_history'] as $table){
            $res=mysqli_query($dbconnect,"SHOW COLUMNS FROM `$table`");
            $cols=[]; $pickTypeDef='';
            while($res && ($r=mysqli_fetch_assoc($res))){
                $cols[(string)$r['Field']]=true;
                if((string)$r['Field']==='pick_type') $pickTypeDef=strtoupper((string)$r['Type']);
            }
            $need=['pickID','userID','teamName','raceYear','segment','driverA','driverB','driverC','driverD','entryDate','submission_id','ip','formID','pick_type','effective_race','supersedes_pickID'];
            $missing=[];
            foreach($need as $n) if(!isset($cols[$n])) $missing[]=$n;
            $ok=!$missing;
            $checks[]=[$table.' required columns',$ok?'PASS':'FAIL'];
            if(!$ok) $errors[]=$table.' missing: '.implode(', ',$missing);

            $pickTypeDefRaw=strtolower(trim($pickTypeDef));
            $typeOk=false;

            // Flexible character/text fields intentionally allow today's MRL
            // pick codes plus future valid codes without a schema change.
            if(preg_match('/^(?:var)?char\\((\\d+)\\)$/i',$pickTypeDefRaw,$m)){
                $typeOk=((int)$m[1] >= 3);
            }elseif(preg_match('/^(?:tiny|medium|long)?text$/i',$pickTypeDefRaw)){
                $typeOk=true;
            }elseif(preg_match('/^(enum|set)\\((.*)\\)$/i',$pickTypeDefRaw,$m)){
                $values=[];
                if(preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/",$m[2],$vm)){
                    foreach($vm[1] as $v) $values[]=strtoupper(stripslashes($v));
                }
                $typeOk=in_array('LP',$values,true)
                    && in_array('SEG',$values,true)
                    && in_array('ADJ',$values,true);
            }

            $checks[]=[$table.'.pick_type storage compatible',$typeOk?'PASS ('.$pickTypeDefRaw.')':'FAIL ('.$pickTypeDefRaw.')'];
            if(!$typeOk) $errors[]=$table.'.pick_type storage type is not compatible with required MRL pick codes.';
        }

        $res=mysqli_query($dbconnect,"SELECT COUNT(*) c FROM segment_race_ranges WHERE raceYear=2026");
        $r=$res?mysqli_fetch_assoc($res):null; $cnt=is_array($r)?(int)$r['c']:0;
        $checks[]=['segment_race_ranges for 2026',$cnt===4?'PASS (4 rows)':'FAIL ('.$cnt.' rows)'];
        if($cnt!==4) $errors[]='Expected 4 segment rows for 2026.';

        $res=mysqli_query($dbconnect,"SELECT COUNT(*) c FROM user_picks WHERE pick_type='LP'");
        $r=$res?mysqli_fetch_assoc($res):null; $lpCnt=is_array($r)?(int)$r['c']:0;
        $checks[]=['Current LP rows available','PASS ('.$lpCnt.')'];
    }catch(Throwable $e){ $errors[]=$e->getMessage(); }
}

if(!$errors){
    try{
        $prepared['admin_pick_adjustment.php']=admin_page_source();
        $prepared['team.php']=patch_team($current['team.php']);
        $prepared['current_user_team_chart.php']=patch_cuytc($current['current_user_team_chart.php']);
        $prepared['team_chart.php']=patch_team_chart($current['team_chart.php']);
        $prepared['current_segment_chart_by_entry_time.php']=patch_entry($current['current_segment_chart_by_entry_time.php']);

        $markers=[
          'admin_pick_adjustment.php'=>['VERSION: v001',"pick_type='ADJ'",'Approve as Regular Pick'],
          'team.php'=>['VERSION: v020','/admin_pick_adjustment.php'],
          'current_user_team_chart.php'=>['VERSION: v004','admin_adjusted','Admin-approved regular pick'],
          'team_chart.php'=>['VERSION: v016','admin_adjusted','Admin-approved regular pick'],
          'current_segment_chart_by_entry_time.php'=>['VERSION: v003','admin_adjusted','Admin-approved regular pick']
        ];
        foreach($markers as $f=>$ms){
            $ok=true; foreach($ms as $m) if(strpos($prepared[$f],$m)===false){$ok=false;break;}
            $checks[]=[$f.' replacement prepared',$ok?'PASS':'FAIL'];
            if(!$ok) $errors[]=$f.' generated structure failed.';
        }
    }catch(Throwable $e){ $errors[]=$e->getMessage(); }
}

if(!$errors && $_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='install'){
    try{
        $backupDir=$root.'/mrl_lp_admin_adjustment_backup_'.INSTALLER_TIMESTAMP;
        if(!is_dir($backupDir) && !mkdir($backupDir,0755,true)) throw new RuntimeException('Unable to create backup folder.');

        $mods=['team.php','current_user_team_chart.php','team_chart.php','current_segment_chart_by_entry_time.php'];
        foreach($mods as $f) if(!copy($paths[$f],$backupDir.'/'.$f)) throw new RuntimeException('Backup failed for '.$f);

        $written=[];
        try{
            foreach($prepared as $f=>$content){ atomic_write($paths[$f],$content); $written[]=$f; }
        }catch(Throwable $e){
            foreach($mods as $f) if(is_file($backupDir.'/'.$f)) @copy($backupDir.'/'.$f,$paths[$f]);
            if(in_array('admin_pick_adjustment.php',$written,true)) @unlink($paths['admin_pick_adjustment.php']);
            throw $e;
        }

        $post=['team.php'=>'VERSION: v020','current_user_team_chart.php'=>'VERSION: v004','team_chart.php'=>'VERSION: v016','current_segment_chart_by_entry_time.php'=>'VERSION: v003','admin_pick_adjustment.php'=>'VERSION: v001'];
        foreach($post as $f=>$m){
            $c=is_file($paths[$f])?(string)file_get_contents($paths[$f]):'';
            if(strpos($c,$m)===false) throw new RuntimeException('Post-install validation failed for '.$f);
        }
        $installComplete=true;
    }catch(Throwable $e){ $errors[]=$e->getMessage(); }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>MRL LP Admin Adjustment Installer v002</title>
<style>
body{margin:0;background:#151515;color:#eee;font:16px Arial,sans-serif}.wrap{width:min(1180px,94%);margin:26px auto}
h1,h2{color:#ffd08a}.card{background:#222;border:1px solid #4d473f;border-radius:14px;padding:18px 22px;margin:14px 0}
table{width:100%;border-collapse:collapse}td{padding:8px 10px;border-bottom:1px solid #3a3a3a}.pass{color:#62e89b}.fail{color:#ff7d7d}
.btn{font:16px Arial;background:#3a2f1b;color:#ffd08a;border:1px solid #b18745;border-radius:9px;padding:10px 15px;cursor:pointer}
.ok{color:#62e89b;font-weight:bold}.bad{color:#ff7d7d}.reminder{border-left:4px solid #ffd166;padding:8px 12px;background:#2a2519}
</style></head><body><div class="wrap">
<h1>MRL LP Admin Adjustment Installer v002</h1>
<div class="card"><b>Target:</b> <?php echo ih($host); ?><br><b>Root:</b> <?php echo ih($root); ?><br><b>Build:</b> 8/19/2026 7:34:00 pm ET</div>
<div class="card"><h2>Preflight</h2><table>
<?php foreach($checks as $row): ?><tr><td><?php echo ih((string)$row[0]); ?></td><td class="<?php echo strpos((string)$row[1],'PASS')===0?'pass':'fail'; ?>"><?php echo ih((string)$row[1]); ?></td></tr><?php endforeach; ?>
</table></div>

<?php if($errors): ?>
<div class="card"><h2 class="bad">STOPPED</h2><ul><?php foreach($errors as $e): ?><li><?php echo ih($e); ?></li><?php endforeach; ?></ul><p>No installation should proceed from this state.</p></div>

<?php elseif($installComplete): ?>
<div class="card"><h2 class="ok">INSTALL COMPLETE.</h2>
<p><b>Backup folder:</b><br><?php echo ih($backupDir); ?></p>
<p>Installed admin_pick_adjustment.php v001 and chart/menu support for ADJ-history annotation.</p>
<p><b>Suggested immediate test:</b> Open <code>/admin_pick_adjustment.php</code>, locate the prepared 2026 S3 LP, verify its before/after values, approve it, then check the current-user chart, public team chart, entry-time chart, user_picks and user_picks_history.</p>
<div class="reminder"><b>Post-install reminder:</b> After testing, sync TestPHP8 back to your local folder with WinSCP, then commit/push the local changes to GitHub.</div>
</div>

<?php else: ?>
<div class="card"><h2>Ready</h2><p>Preflight passed. INSTALL will:</p><ul>
<li>Add the admin-only LP adjustment page.</li>
<li>Add its link to the team.php admin menu.</li>
<li>Add ADJ-history-aware chart annotation: <b>* Admin-approved regular pick</b>.</li>
<li>Keep original LP history and real submission timestamps intact.</li>
<li>Make no DB schema changes and leave Live untouched.</li>
</ul>
<form method="post"><button class="btn" name="action" value="install">INSTALL LP ADMIN ADJUSTMENT v002</button></form></div>
<?php endif; ?>
</div></body></html>
