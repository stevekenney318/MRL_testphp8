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