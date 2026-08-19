<?php
declare(strict_types=1);

/**
 * admin_setup.php
 *
 * VERSION: v002
 * LAST MODIFIED: 8/19/2026 3:08:00 pm
 *
 * DESCRIPTION:
 * Compact dashboard-style MRL automatic pick-window admin page.
 *
 * CHANGELOG:
 * v002 (8/19/2026 3:08:00 pm)
 * - UI: Rebuilt first data row as three compact panels in requested order:
 *       Current Effective State / Temporary Pick Window Override / System Settings.
 * - UI: Adopted race_results_dashboard-style dark cards, gold headings, status pills,
 *       tighter spacing, and vertically grouped label/value rows to reduce eye travel.
 * - NEW: Configurable global default pick-window lead time.
 * - NEW: One-segment lead-time adjustment that automatically stops applying outside
 *        its selected year/segment, returning future segments to the global default.
 * - FIX: updatedAt writes now use explicit PHP America/New_York timestamps instead of MySQL NOW().
 * - PRESERVE: Existing temporary exact-date override, master lock, Add Year, and diagnostics.
 *
 * v001 (8/19/2026 4:51:53 am)
 * - Initial automatic pick-window admin page.
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
if (!isAdmin($uid)) {
    echo '<div style="color:#ff7373;background:#141414;padding:20px;font:18px Arial">You are NOT authorized to view/use this page.</div>';
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
function ny_now_db(): string { return date('Y-m-d H:i:s'); }
function valid_days($value): ?int {
    $v = filter_var($value, FILTER_VALIDATE_INT);
    if ($v === false || $v < 0 || $v > 90) return null;
    return (int)$v;
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
    $updatedAt = ny_now_db();

    if ($action === 'add_year') {
        mysqli_query($dbconnect, "INSERT INTO years (yearID,year) VALUES ($nextYear,$nextYear)");
        header('Location: /admin_setup.php?msg=' . urlencode("Added year $nextYear."));
        exit;
    }

    if ($action === 'save_system') {
        $newYear = (int)($_POST['raceYear'] ?? 0);
        $locked = strtolower((string)($_POST['formLocked'] ?? 'no')) === 'yes' ? 'yes' : 'no';
        $defaultDays = valid_days($_POST['pickWindowDefaultDays'] ?? null);
        if ($defaultDays === null) {
            header('Location: /admin_setup.php?msg=' . urlencode('System settings not saved: default lead time must be 0-90 days.'));
            exit;
        }
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET raceYear=?, formLocked=?, pickWindowDefaultDays=?, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'isiis', $newYear, $locked, $defaultDays, $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('System settings updated.'));
        exit;
    }

    if ($action === 'save_lead_adjustment') {
        $adjustYear = (int)($_POST['pickLeadAdjustYear'] ?? $raceYear);
        $adjustSegment = strtoupper(trim((string)($_POST['pickLeadAdjustSegment'] ?? '')));
        $adjustDays = valid_days($_POST['pickLeadAdjustDays'] ?? null);
        if (!in_array($adjustSegment, $segments, true) || $adjustDays === null) {
            header('Location: /admin_setup.php?msg=' . urlencode('Segment adjustment not saved: choose a valid segment and 0-90 days.'));
            exit;
        }
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickLeadAdjustYear=?, pickLeadAdjustSegment=?, pickLeadAdjustDays=?, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'isiis', $adjustYear, $adjustSegment, $adjustDays, $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('One-segment lead-time adjustment saved.'));
        exit;
    }

    if ($action === 'clear_lead_adjustment') {
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickLeadAdjustYear=NULL, pickLeadAdjustSegment=NULL, pickLeadAdjustDays=NULL, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'is', $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Segment lead-time adjustment cleared; default lead time applies.'));
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
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickOverrideEnabled=?, pickOverrideSegment=?, pickOverrideOpenAt=?, pickOverrideDeadlineAt=?, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'ssssis', $enabled, $ovSeg, $openDb, $deadlineDb, $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Temporary pick-window override updated.'));
        exit;
    }

    if ($action === 'disable_override') {
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickOverrideEnabled='no', updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'is', $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Override disabled; automatic mode restored.'));
        exit;
    }
}

$res = mysqli_query($dbconnect, "SELECT a.*,u.userName FROM admin_setup a LEFT JOIN users u ON a.updatedBy=u.userID WHERE a.id=1");
$current = $res ? mysqli_fetch_assoc($res) : [];
$msg = (string)($_GET['msg'] ?? '');

$settings = [
    'default_days' => (int)($current['pickWindowDefaultDays'] ?? 15),
    'adjust_year' => $current['pickLeadAdjustYear'] ?? null,
    'adjust_segment' => $current['pickLeadAdjustSegment'] ?? '',
    'adjust_days' => $current['pickLeadAdjustDays'] ?? null,
];
$autoState = null;
$autoError = '';
try {
    if (function_exists('mrl_pick_window_state')) {
        $autoState = mrl_pick_window_state((int)$raceYear, ['enabled' => 'no'], null, $settings);
    }
} catch (Throwable $e) {
    $autoError = $e->getMessage();
}

$overrideEnabled = strtolower((string)($current['pickOverrideEnabled'] ?? 'no')) === 'yes';
$defaultOverrideSegment = (string)($current['pickOverrideSegment'] ?? ($autoState['pick_segment'] ?? $pickSegment));
$defaultOverrideOpen = (string)($current['pickOverrideOpenAt'] ?? '');
$defaultOverrideDeadline = (string)($current['pickOverrideDeadlineAt'] ?? '');
if ($defaultOverrideOpen === '' && is_array($autoState) && isset($autoState['window_open_dt'])) $defaultOverrideOpen = $autoState['window_open_dt']->format('Y-m-d H:i:s');
if ($defaultOverrideDeadline === '' && is_array($autoState) && isset($autoState['deadline_dt'])) $defaultOverrideDeadline = $autoState['deadline_dt']->format('Y-m-d H:i:s');

$adjustYearDisplay = isset($current['pickLeadAdjustYear']) && $current['pickLeadAdjustYear'] !== null ? (int)$current['pickLeadAdjustYear'] : (int)$raceYear;
$adjustSegmentDisplay = trim((string)($current['pickLeadAdjustSegment'] ?? '')) !== '' ? (string)$current['pickLeadAdjustSegment'] : (string)($autoState['pick_segment'] ?? $pickSegment);
$adjustDaysDisplay = isset($current['pickLeadAdjustDays']) && $current['pickLeadAdjustDays'] !== null ? (string)$current['pickLeadAdjustDays'] : '';
$adjustActive = isset($current['pickLeadAdjustYear'], $current['pickLeadAdjustSegment'], $current['pickLeadAdjustDays'])
    && $current['pickLeadAdjustYear'] !== null && trim((string)$current['pickLeadAdjustSegment']) !== '' && $current['pickLeadAdjustDays'] !== null;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>MRL Admin Setup - Automatic Pick Window</title>
<link rel="stylesheet" href="/mrl-styles.css">
<style>
*{box-sizing:border-box}
body{margin:0;background:#151515;color:#f2f2f2;font-family:Tahoma,Verdana,Segoe,sans-serif;font-size:15px}
.wrap{width:96%;max-width:1500px;margin:12px auto 24px}
.page-title{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border:1px solid #4b4233;border-radius:14px;background:linear-gradient(180deg,#22211e,#191919);margin-bottom:10px}
.page-title h1{margin:0;color:#ffd08a;font-size:24px;letter-spacing:.2px}.subtitle{color:#aaa;font-size:13px;margin-top:3px}
.pill{display:inline-block;border:1px solid #6b5a3b;border-radius:999px;padding:5px 10px;background:#25231f;color:#eee;white-space:nowrap}.pill.good{border-color:#286c48;background:#173526;color:#62e89b}.pill.warn{border-color:#7a6330;background:#3b311b;color:#ffd166}.pill.bad{border-color:#7f3434;background:#3b1c1c;color:#ff7d7d}
.top-grid{display:grid;grid-template-columns:1.04fr 1.18fr .95fr;gap:10px;align-items:start}.card{border:1px solid #4d473f;border-radius:14px;background:linear-gradient(180deg,#222,#1b1b1b);padding:12px 14px;box-shadow:0 2px 8px rgba(0,0,0,.22)}
.card h2{margin:0 0 9px;color:#ffd08a;font-size:18px}.rows{display:grid;gap:4px}.kv{display:grid;grid-template-columns:minmax(108px,.9fr) minmax(0,1.25fr);align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #333}.kv:last-child{border-bottom:0}.k{color:#cdbd9e;font-size:13px;font-weight:bold}.v{color:#fff;min-width:0;overflow-wrap:anywhere}.v.strong{font-size:16px;font-weight:bold}.muted{color:#9d9d9d}.goodtxt{color:#62e89b}.warntxt{color:#ffd166}.badtxt{color:#ff7d7d}
.control{width:100%;font:14px Tahoma;background:#f4f4f4;color:#111;border:1px solid #777;border-radius:7px;padding:6px 8px;min-height:32px}.inline2{display:grid;grid-template-columns:1fr 1fr;gap:7px}.field{margin:0 0 7px}.field label{display:block;color:#cdbd9e;font-size:12px;font-weight:bold;margin-bottom:3px}.actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.btn{font:14px Tahoma;border:1px solid #7a6641;border-radius:9px;background:#2a2721;color:#ffe0ad;padding:6px 10px;cursor:pointer}.btn.primary{background:#3a2f1b;border-color:#b18745;color:#ffd08a}.btn.good{background:#173526;border-color:#286c48;color:#62e89b}.btn.danger{background:#3b1c1c;border-color:#7f3434;color:#ff8a8a}
.section-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}.note{font-size:12px;line-height:1.35;color:#9f9f9f;margin:7px 0 0}.lead-adjust{margin-top:9px;padding-top:9px;border-top:1px solid #3b3b3b}.lead-adjust h3{margin:0 0 7px;color:#e8c58b;font-size:14px}.status-line{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:7px}.flash{position:fixed;top:8px;left:50%;transform:translateX(-50%);z-index:100;background:#111;border:1px solid #6b5a3b;color:#ffd08a;padding:8px 14px;border-radius:9px;box-shadow:0 3px 14px #000}.compact-table{width:100%;border-collapse:collapse}.compact-table th,.compact-table td{text-align:left;padding:6px 8px;border-bottom:1px solid #333}.compact-table th{color:#cdbd9e;font-size:12px}.compact-table td{color:#eee}.diag{font-size:12px}
@media(max-width:1050px){.top-grid{grid-template-columns:1fr}.section-grid{grid-template-columns:1fr}}@media(max-width:600px){.inline2{grid-template-columns:1fr}.wrap{width:98%}}
</style>
</head>
<body>
<?php if($msg!==''): ?><div class="flash"><?php echo ah($msg); ?></div><?php endif; ?>
<div class="wrap">
<div class="page-title"><div><h1>MRL Automatic Pick Window</h1><div class="subtitle">Compact control center — schedule-derived by default, exceptions explicit.</div></div><div class="status-line"><span class="pill <?php echo $pickWindowIsOpen?'good':'warn'; ?>"><?php echo ah($pickWindowStatus); ?></span><span class="pill"><?php echo ah($raceYear); ?></span></div></div>

<div class="top-grid">
<section class="card">
<h2>Current Effective State</h2>
<div class="rows">
<div class="kv"><div class="k">Source</div><div class="v strong <?php echo $pickWindowSource==='AUTO'?'goodtxt':'warntxt'; ?>"><?php echo ah($pickWindowSource); ?></div></div>
<div class="kv"><div class="k">Scoring</div><div class="v"><?php echo ah($scoringSegment . ' — ' . $scoringSegmentName); ?></div></div>
<div class="kv"><div class="k">Pick Segment</div><div class="v strong"><?php echo ah($pickSegment . ' — ' . $pickSegmentName); ?></div></div>
<div class="kv"><div class="k">Lead Time</div><div class="v"><?php echo ah((string)$pickWindowEffectiveDays); ?> days <span class="muted">(<?php echo ah($pickWindowLeadSource); ?>)</span></div></div>
<div class="kv"><div class="k">Opens</div><div class="v"><?php echo ah($pickWindowOpenAt); ?></div></div>
<div class="kv"><div class="k">Deadline</div><div class="v"><?php echo ah($pickDeadlineAt); ?></div></div>
<div class="kv"><div class="k">Master Lock</div><div class="v <?php echo $formLocked==='yes'?'badtxt':'goodtxt'; ?>"><?php echo ah(strtoupper($formLocked)); ?></div></div>
</div>
<?php if($pickWindowError!==''): ?><p class="note badtxt">Pick-window warning: <?php echo ah($pickWindowError); ?></p><?php endif; ?>
</section>

<section class="card">
<h2>Temporary Pick Window Override</h2>
<form method="post">
<div class="inline2"><div class="field"><label>Enabled</label><select name="pickOverrideEnabled" class="control"><option value="no" <?php echo !$overrideEnabled?'selected':''; ?>>No</option><option value="yes" <?php echo $overrideEnabled?'selected':''; ?>>Yes</option></select></div><div class="field"><label>Pick Segment</label><select name="pickOverrideSegment" class="control"><?php foreach($segments as $s): ?><option value="<?php echo ah($s); ?>" <?php echo $defaultOverrideSegment===$s?'selected':''; ?>><?php echo ah($s); ?></option><?php endforeach; ?></select></div></div>
<div class="field"><label>Override Opens</label><input type="datetime-local" name="pickOverrideOpenAt" class="control" value="<?php echo ah(adtlocal($defaultOverrideOpen)); ?>"></div>
<div class="field"><label>Override Deadline</label><input type="datetime-local" name="pickOverrideDeadlineAt" class="control" value="<?php echo ah(adtlocal($defaultOverrideDeadline)); ?>"></div>
<div class="actions"><button class="btn primary" name="action" value="save_override">Save Override</button><button class="btn danger" name="action" value="disable_override">Disable / Return to AUTO</button></div>
</form>
<p class="note">Exact-date override has highest priority and affects pick state only. Scoring stays schedule-derived.</p>
</section>

<section class="card">
<h2>System Settings</h2>
<form method="post">
<div class="inline2"><div class="field"><label>Race Year</label><select name="raceYear" class="control"><?php foreach($years as $y): ?><option value="<?php echo $y; ?>" <?php echo ((int)$raceYear===$y)?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div><div class="field"><label>Master Form Lock</label><select name="formLocked" class="control"><option value="no" <?php echo $formLocked!=='yes'?'selected':''; ?>>No</option><option value="yes" <?php echo $formLocked==='yes'?'selected':''; ?>>Yes</option></select></div></div>
<div class="field"><label>Default Pick Window Lead Time</label><input type="number" min="0" max="90" name="pickWindowDefaultDays" class="control" value="<?php echo ah((string)($current['pickWindowDefaultDays'] ?? 15)); ?>"></div>
<div class="actions"><button class="btn primary" name="action" value="save_system">Save System Settings</button><button class="btn" name="action" value="add_year">Add <?php echo $nextYear; ?></button></div>
</form>

<div class="lead-adjust">
<h3>One-Segment Lead-Time Adjustment</h3>
<div class="status-line"><?php if($adjustActive): ?><span class="pill warn"><?php echo ah((string)$current['pickLeadAdjustYear'] . ' ' . (string)$current['pickLeadAdjustSegment'] . ': ' . (string)$current['pickLeadAdjustDays'] . ' days'); ?></span><?php else: ?><span class="pill good">None — default applies</span><?php endif; ?></div>
<form method="post">
<input type="hidden" name="pickLeadAdjustYear" value="<?php echo ah((string)$adjustYearDisplay); ?>">
<div class="inline2"><div class="field"><label>Segment</label><select name="pickLeadAdjustSegment" class="control"><?php foreach($segments as $s): ?><option value="<?php echo ah($s); ?>" <?php echo $adjustSegmentDisplay===$s?'selected':''; ?>><?php echo ah($s); ?></option><?php endforeach; ?></select></div><div class="field"><label>Lead Time (days)</label><input type="number" min="0" max="90" name="pickLeadAdjustDays" class="control" value="<?php echo ah($adjustDaysDisplay); ?>" placeholder="e.g. 10"></div></div>
<div class="actions"><button class="btn primary" name="action" value="save_lead_adjustment">Save Segment Adjustment</button><button class="btn" name="action" value="clear_lead_adjustment">Clear Adjustment</button></div>
</form>
<p class="note">Applies only to the selected year/segment. The next segment automatically returns to the global default unless it has its own adjustment.</p>
</div>
</section>
</div>

<div class="section-grid">
<section class="card"><h2>Automatic Reference</h2><?php if(is_array($autoState)): ?><table class="compact-table"><tr><th>Scoring</th><td><?php echo ah($autoState['scoring_segment']); ?></td><th>Pick</th><td><?php echo ah($autoState['pick_segment']); ?></td></tr><tr><th>Lead</th><td><?php echo ah((string)$autoState['effective_lead_days']); ?> days (<?php echo ah((string)$autoState['lead_source']); ?>)</td><th>Default</th><td><?php echo ah((string)$autoState['default_lead_days']); ?> days</td></tr><tr><th>Opens</th><td><?php echo ah($autoState['window_open_display']); ?></td><th>Deadline</th><td><?php echo ah($autoState['deadline_display']); ?></td></tr></table><?php else: ?><p class="note badtxt">Unable to calculate AUTO reference: <?php echo ah($autoError); ?></p><?php endif; ?></section>
<section class="card diag"><h2>Legacy / Audit</h2><table class="compact-table"><tr><th>Stored Segment</th><td><?php echo ah($current['segment'] ?? ''); ?></td><th>Stored Lock</th><td><?php echo ah(adt(($current['formLockDate'] ?? '') . ' ' . ($current['formLockTime'] ?? ''))); ?></td></tr><tr><th>Current Form</th><td><?php echo ah($current['currentForm'] ?? ''); ?></td><th>Last Updated</th><td><?php echo ah(adt($current['updatedAt'] ?? '')); ?><?php if(!empty($current['userName'])) echo ' by ' . ah($current['userName']); ?></td></tr></table><p class="note">Legacy values remain for fallback/diagnostics; normal operation does not require manual maintenance.</p></section>
</div>
</div>
<script>setTimeout(function(){var e=document.querySelector('.flash');if(e)e.style.display='none';},2800);</script>
</body>
</html>