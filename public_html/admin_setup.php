<?php
declare(strict_types=1);

/**
 * admin_setup.php
 *
 * VERSION: v001
 * LAST MODIFIED: 8/19/2026 4:51:53 am
 *
 * DESCRIPTION:
 * MRL automatic pick-window status and temporary override admin page.
 *
 * Normal operation requires no manual segment or deadline maintenance.
 * The pick segment/deadline come from the canonical race schedule and
 * segment_race_ranges. This page retains race-year setup, emergency formLocked,
 * Add Year, and a temporary pick-window override for unusual situations/testing.
 *
 * CHANGELOG:
 * v001 (8/19/2026 4:51:53 am)
 * - Replaced manual normal segment/deadline workflow with automatic status.
 * - Added temporary pick-segment/open/deadline override controls.
 * - Preserved legacy stored admin_setup values for fallback/diagnostics.
 * - Preserved formLocked as emergency/master lock and Add Year function.
 */

session_start();
date_default_timezone_set('America/New_York');
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/admin_setup.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

$uid = (int)($_SESSION['userSession'] ?? 0);
$isAdmin = isAdmin($uid);
if (!$isAdmin) {
    echo '<div style="color:#ff6666;background:#222;padding:20px;font:18px Arial">You are NOT authorized to view/use this page.</div>';
    exit;
}

function ah($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function adt($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    $ts = strtotime($v);
    return $ts === false ? '' : date('n/j/Y g:i A', $ts);
}
function adtlocal($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    $ts = strtotime($v);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

$years = [];
$res = mysqli_query($dbconnect, "SELECT year FROM years ORDER BY year DESC");
while ($res && ($r = mysqli_fetch_assoc($res))) $years[] = (int)$r['year'];
$maxYear = $years ? max($years) : (int)date('Y');
$nextYear = $maxYear + 1;

$segments = [];
$res = mysqli_query($dbconnect, "SELECT segment FROM segment_race_ranges WHERE raceYear=" . (int)$raceYear . " ORDER BY startRace");
while ($res && ($r = mysqli_fetch_assoc($res))) $segments[] = strtoupper((string)$r['segment']);
if (!$segments) $segments = ['S1','S2','S3','S4'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_year') {
        mysqli_query($dbconnect, "INSERT INTO years (yearID,year) VALUES ($nextYear,$nextYear)");
        header('Location: /admin_setup.php?msg=' . urlencode("Added year $nextYear."));
        exit;
    }

    if ($action === 'save_system') {
        $newYear = (int)($_POST['raceYear'] ?? 0);
        $locked = strtolower((string)($_POST['formLocked'] ?? 'no')) === 'yes' ? 'yes' : 'no';
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET raceYear=?, formLocked=?, updatedBy=?, updatedAt=NOW() WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'isi', $newYear, $locked, $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('System settings updated.'));
        exit;
    }

    if ($action === 'save_override') {
        $enabled = strtolower((string)($_POST['pickOverrideEnabled'] ?? 'no')) === 'yes' ? 'yes' : 'no';
        $ovSeg = strtoupper(trim((string)($_POST['pickOverrideSegment'] ?? '')));
        $openRaw = trim((string)($_POST['pickOverrideOpenAt'] ?? ''));
        $deadlineRaw = trim((string)($_POST['pickOverrideDeadlineAt'] ?? ''));

        if (!in_array($ovSeg, $segments, true)) {
            header('Location: /admin_setup.php?msg=' . urlencode('Override not saved: invalid segment.'));
            exit;
        }

        $openTs = $openRaw !== '' ? strtotime($openRaw) : false;
        $deadlineTs = $deadlineRaw !== '' ? strtotime($deadlineRaw) : false;
        if ($openTs === false || $deadlineTs === false || $openTs >= $deadlineTs) {
            header('Location: /admin_setup.php?msg=' . urlencode('Override not saved: opening and deadline are required, and opening must be earlier.'));
            exit;
        }

        $openDb = date('Y-m-d H:i:s', $openTs);
        $deadlineDb = date('Y-m-d H:i:s', $deadlineTs);
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickOverrideEnabled=?, pickOverrideSegment=?, pickOverrideOpenAt=?, pickOverrideDeadlineAt=?, updatedBy=?, updatedAt=NOW() WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'ssssi', $enabled, $ovSeg, $openDb, $deadlineDb, $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Temporary pick-window override updated.'));
        exit;
    }

    if ($action === 'disable_override') {
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickOverrideEnabled='no', updatedBy=?, updatedAt=NOW() WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Override disabled; automatic mode restored.'));
        exit;
    }
}

$res = mysqli_query($dbconnect, "SELECT a.*,u.userName FROM admin_setup a LEFT JOIN users u ON a.updatedBy=u.userID WHERE a.id=1");
$current = $res ? mysqli_fetch_assoc($res) : [];
$msg = (string)($_GET['msg'] ?? '');

$autoState = null;
$autoError = '';
try {
    if (function_exists('mrl_pick_window_state')) {
        $autoState = mrl_pick_window_state((int)$raceYear, ['enabled' => 'no']);
    }
} catch (Throwable $e) {
    $autoError = $e->getMessage();
}

$overrideEnabled = strtolower((string)($current['pickOverrideEnabled'] ?? 'no')) === 'yes';
$defaultOverrideSegment = (string)($current['pickOverrideSegment'] ?? ($autoState['pick_segment'] ?? $pickSegment));
$defaultOverrideOpen = (string)($current['pickOverrideOpenAt'] ?? '');
$defaultOverrideDeadline = (string)($current['pickOverrideDeadlineAt'] ?? '');
if ($defaultOverrideOpen === '' && is_array($autoState) && isset($autoState['window_open_dt'])) {
    $defaultOverrideOpen = $autoState['window_open_dt']->format('Y-m-d H:i:s');
}
if ($defaultOverrideDeadline === '' && is_array($autoState) && isset($autoState['deadline_dt'])) {
    $defaultOverrideDeadline = $autoState['deadline_dt']->format('Y-m-d H:i:s');
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>MRL Admin Setup - Automatic Pick Window</title>
<link rel="stylesheet" href="/mrl-styles.css">
<style>
body{background:#222;color:#dfcca8;font-family:Tahoma,Verdana,Segoe,sans-serif;font-size:16px}
.wrap{width:92%;max-width:1200px;margin:20px auto}
h1,h2{text-align:center}.card{border:1px solid #666;border-radius:8px;padding:16px;margin:16px 0;background:#292929}
table{width:100%;border-collapse:collapse}th,td{padding:8px 10px;border-bottom:1px solid #555;text-align:left}th{color:#bbb}
.good{color:#7CFC8A;font-weight:bold}.warn{color:#ffcc66;font-weight:bold}.bad{color:#ff6666;font-weight:bold}
.control{font:16px Tahoma;background:#fff;color:#000;border:2px solid #666;border-radius:3px;padding:5px 8px;box-sizing:border-box}
button{font:16px Tahoma;background:#fff;color:#000;border:2px solid #666;border-radius:3px;padding:5px 14px;cursor:pointer}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field label{display:block;color:#bbb;margin-bottom:5px}.field .control{width:100%}
.note{font-size:13px;color:#bbb;line-height:1.4}.flash{position:fixed;top:8px;left:50%;transform:translateX(-50%);background:#111;padding:8px 16px;border-radius:6px;z-index:99}
@media(max-width:760px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
<h1>MRL Automatic Pick Window</h1>
<p style="text-align:center">Normal picks open automatically <b>15 days before</b> the first race of a segment and close at that race's scheduled start.</p>
<?php if($msg!==''): ?><div class="flash"><?php echo ah($msg); ?></div><?php endif; ?>

<div class="card">
<h2>Current Effective State</h2>
<table>
<tr><th>Race Year</th><td><?php echo ah($raceYear); ?></td><th>Source</th><td class="<?php echo $pickWindowSource==='AUTO'?'good':'warn'; ?>"><?php echo ah($pickWindowSource); ?></td></tr>
<tr><th>Scoring Segment</th><td><?php echo ah($scoringSegment . ' — ' . $scoringSegmentName); ?></td><th>Pick Segment</th><td><?php echo ah($pickSegment . ' — ' . $pickSegmentName); ?></td></tr>
<tr><th>Normal Picks Open</th><td><?php echo ah($pickWindowOpenAt); ?></td><th>Normal Deadline</th><td><?php echo ah($pickDeadlineAt); ?></td></tr>
<tr><th>Window Status</th><td class="<?php echo $pickWindowIsOpen?'good':'warn'; ?>"><?php echo ah($pickWindowStatus); ?></td><th>Master Lock</th><td class="<?php echo $formLocked==='yes'?'bad':'good'; ?>"><?php echo ah($formLocked); ?></td></tr>
</table>
<?php if($pickWindowError!==''): ?><p class="bad">Pick-window warning: <?php echo ah($pickWindowError); ?></p><?php endif; ?>
</div>

<div class="card">
<h2>System Settings</h2>
<form method="post">
<div class="grid">
<div class="field"><label>Race Year</label><select name="raceYear" class="control"><?php foreach($years as $y): ?><option value="<?php echo $y; ?>" <?php echo ((int)$raceYear===$y)?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Emergency / Master Form Lock</label><select name="formLocked" class="control"><option value="no" <?php echo $formLocked!=='yes'?'selected':''; ?>>No</option><option value="yes" <?php echo $formLocked==='yes'?'selected':''; ?>>Yes</option></select></div>
</div>
<p style="text-align:center"><button name="action" value="save_system">Save System Settings</button> &nbsp; <button name="action" value="add_year">Add <?php echo $nextYear; ?></button></p>
</form>
<p class="note">The master lock still shuts off forms immediately. Normal segment and deadline values no longer need manual maintenance.</p>
</div>

<div class="card">
<h2>Temporary Pick Window Override</h2>
<form method="post">
<div class="grid">
<div class="field"><label>Override Enabled</label><select name="pickOverrideEnabled" class="control"><option value="no" <?php echo !$overrideEnabled?'selected':''; ?>>No</option><option value="yes" <?php echo $overrideEnabled?'selected':''; ?>>Yes</option></select></div>
<div class="field"><label>Pick Segment</label><select name="pickOverrideSegment" class="control"><?php foreach($segments as $s): ?><option value="<?php echo ah($s); ?>" <?php echo $defaultOverrideSegment===$s?'selected':''; ?>><?php echo ah($s); ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Override Opens</label><input type="datetime-local" name="pickOverrideOpenAt" class="control" value="<?php echo ah(adtlocal($defaultOverrideOpen)); ?>"></div>
<div class="field"><label>Override Deadline</label><input type="datetime-local" name="pickOverrideDeadlineAt" class="control" value="<?php echo ah(adtlocal($defaultOverrideDeadline)); ?>"></div>
</div>
<p style="text-align:center"><button name="action" value="save_override">Save Override</button> &nbsp; <button name="action" value="disable_override">Disable Override / Return to AUTO</button></p>
</form>
<p class="note">An override affects the <b>pick segment/window only</b>. The scoring segment remains schedule-derived. Use this for testing, unusual deadline exceptions, postponed setup, etc. Disable it when finished and AUTO immediately resumes.</p>
</div>

<div class="card">
<h2>Automatic Reference</h2>
<?php if(is_array($autoState)): ?>
<table>
<tr><th>Auto Scoring Segment</th><td><?php echo ah($autoState['scoring_segment']); ?></td><th>Auto Pick Segment</th><td><?php echo ah($autoState['pick_segment']); ?></td></tr>
<tr><th>Auto Opens</th><td><?php echo ah($autoState['window_open_display']); ?></td><th>Auto Deadline</th><td><?php echo ah($autoState['deadline_display']); ?></td></tr>
</table>
<?php else: ?><p class="bad">Unable to calculate AUTO reference: <?php echo ah($autoError); ?></p><?php endif; ?>
</div>

<div class="card">
<h2>Legacy Stored Values — Fallback / Diagnostic Only</h2>
<table>
<tr><th>Stored Segment</th><td><?php echo ah($current['segment'] ?? ''); ?></td><th>Stored Lock Date/Time</th><td><?php echo ah(adt(($current['formLockDate'] ?? '') . ' ' . ($current['formLockTime'] ?? ''))); ?></td></tr>
<tr><th>Current Form</th><td><?php echo ah($current['currentForm'] ?? ''); ?></td><th>Last Updated</th><td><?php echo ah(adt($current['updatedAt'] ?? '')); ?><?php if(!empty($current['userName'])) echo ' by ' . ah($current['userName']); ?></td></tr>
</table>
<p class="note">These legacy segment/lock fields are intentionally retained so older/fallback behavior remains recoverable. You do not need to update them during normal operation.</p>
</div>
</div>
<script>setTimeout(function(){var e=document.querySelector('.flash');if(e)e.style.display='none';},2500);</script>
</body>
</html>