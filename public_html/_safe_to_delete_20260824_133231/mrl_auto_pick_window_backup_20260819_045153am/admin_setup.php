<?php
session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

$isAdmin = isAdmin($_SESSION['userSession'] ?? null);

$adminStatusLine = $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

if (!$isAdmin) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Not Authorized</title>
        <link rel="stylesheet" href="/mrl-styles.css">
    </head>
    <body><?php echo $adminStatusLine; ?></body>
    </html>
    <?php
    exit;
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function usaDate($d){ return $d ? date('n/j/Y', strtotime($d)) : ''; }
function usaTime($t){ return $t ? date('g:i A', strtotime($t)) : ''; }
function usaDT($dt){ return $dt ? date('n/j/Y g:i A', strtotime($dt)) : ''; }

/* years */
$years=[];
$r=mysqli_query($dbconnect,"SELECT year FROM years ORDER BY year DESC");
while($r && $row=mysqli_fetch_assoc($r)) $years[]=(int)$row['year'];
$maxYear=$years?max($years):(int)date('Y');
$nextYear=$maxYear+1;

/* segments */
$segments=[];
$r=mysqli_query($dbconnect,"SELECT segment FROM segments ORDER BY segment");
while($r && $row=mysqli_fetch_assoc($r)) $segments[]=$row['segment'];
if(!$segments) $segments=['S1','S2','S3','S4'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';

    if($action==='add_year'){
        mysqli_query($dbconnect,"INSERT INTO years (yearID,year) VALUES ($nextYear,$nextYear)");
        header("Location: admin_setup.php?msg=".urlencode("Added year $nextYear."));
        exit;
    }

    if($action==='save_changes'){
        $raceYear=(int)($_POST['raceYear']??0);
        $segment=mysqli_real_escape_string($dbconnect,$_POST['segment']??'');
        $locked=strtolower($_POST['formLocked']??'no')==='yes'?'yes':'no';

        $d=$_POST['formLockDate']? "'".date('Y-m-d',strtotime($_POST['formLockDate']))."'" : "NULL";
        $t=$_POST['formLockTime']? "'".date('H:i:00',strtotime($_POST['formLockTime']))."'" : "NULL";

        $uid=(int)$_SESSION['userSession'];

        mysqli_query($dbconnect,"
            UPDATE admin_setup SET
                raceYear=$raceYear,
                segment='$segment',
                formLocked='$locked',
                formLockDate=$d,
                formLockTime=$t,
                updatedBy=$uid,
                updatedAt=NOW()
            WHERE id=1
        ");
        header("Location: admin_setup.php?msg=".urlencode("Configuration updated successfully."));
        exit;
    }
}

$r=mysqli_query($dbconnect,"
    SELECT a.*,u.userName
    FROM admin_setup a
    LEFT JOIN users u ON a.updatedBy=u.userID
    WHERE a.id=1
");
$current=mysqli_fetch_assoc($r);
$lockedLower=strtolower($current['formLocked']);
$msg=$_GET['msg']??'';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>MRL Admin Setup</title>
<link rel="stylesheet" href="/mrl-styles.css">

<style>
/* summary */
.status-table{
    margin:18px auto 26px auto;
    border-collapse:collapse;
    font-size:18px;
}
.status-table th{
    padding:6px 12px;
    border-bottom:1px solid #666;
    color:#bbb;
    text-align:center;
}
.status-table td{
    padding:6px 12px;
    color:#dfcca8;
    text-align:center;
}
.status-table td.status-locked-yes{
    color:#ff4c4c;
    font-weight:bold;
}

/* Controls: force addDrivers-like look */
.status-table .mrl-control,
.status-table .mrl-button{
    font-family: Tahoma, Verdana, Segoe, sans-serif;
    font-size: 13pt;
    line-height: normal;
    color:#000;
    background:#fff;
    border:2px solid #666;
    border-radius:3px;
}

/* Inputs/selects padding & sizing (match addDrivers feel) */
.status-table .mrl-control{
    padding: 2px 8px;
    height: 32px;
    box-sizing: border-box;
    width: 100%;
}

/* Buttons modest/small */
.status-table .mrl-button{
    padding: 2px 12px;
    height: 32px;
    cursor: pointer;
}

/* Keep the controls row tight */
.controls-row td{
    padding-top: 10px;
    padding-bottom: 0px;
}

/* Buttons row spacing */
.actions-row td{
    padding-top: 10px;
    padding-bottom: 6px;
}

/* Success message: fixed at top center, does NOT push layout */
.flash-top{
    position: fixed;
    top: 6px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 9999;
    padding: 6px 14px;
    border-radius: 6px;
    background: rgba(0,0,0,0.35);
    text-align: center;
    white-space: nowrap;
}

/* Reuse your existing success styling */
.flash-top.notice-success{
    margin: 0;
}

.note{
    font-size:12px;
    color:#bbb;
    margin-top:10px;
    text-align:center;
}
</style>
</head>
<body>

<?php echo $adminStatusLine; ?>

<?php if($msg): ?>
<div id="flashMsg" class="flash-top notice-success"><?php echo h($msg); ?></div>
<script>
(function(){
    var el = document.getElementById('flashMsg');
    if(!el) return;
    window.setTimeout(function(){
        el.style.transition = "opacity 0.6s ease";
        el.style.opacity = "0";
        window.setTimeout(function(){ el.style.display = "none"; }, 650);
    }, 2200);
})();
</script>
<?php endif; ?>

<p style="text-align:center; margin-top:10px;">
This is the page to use to set the year/segment and deadline for picks, or add a new year, etc
</p>

<form method="post" action="admin_setup.php" autocomplete="off">

<table class="status-table">
<tr>
<th>Year</th><th>Segment</th><th>Lock Date</th><th>Lock Time</th>
<th>Locked</th><th>By</th><th>When</th><th>Current Form</th>
</tr>

<tr>
<td><?php echo h($current['raceYear']); ?></td>
<td><?php echo h($current['segment']); ?></td>
<td><?php echo h(usaDate($current['formLockDate'])); ?></td>
<td><?php echo h(usaTime($current['formLockTime'])); ?></td>
<td class="<?php echo $lockedLower==='yes'?'status-locked-yes':''; ?>">
<?php echo h($current['formLocked']); ?>
</td>
<td><?php echo h($current['userName']); ?></td>
<td><?php echo h(usaDT($current['updatedAt'])); ?></td>
<td><?php echo h($current['currentForm']); ?></td>
</tr>

<!-- Controls aligned directly under the first 5 columns -->
<tr class="controls-row">
<td>
    <select name="raceYear" class="mrl-control">
    <?php foreach($years as $y): ?>
    <option value="<?php echo $y; ?>" <?php if($y==$current['raceYear']) echo 'selected'; ?>>
    <?php echo $y; ?>
    </option>
    <?php endforeach; ?>
    </select>
</td>

<td>
    <select name="segment" class="mrl-control">
    <?php foreach($segments as $s): ?>
    <option <?php if($s===$current['segment']) echo 'selected'; ?>>
    <?php echo h($s); ?>
    </option>
    <?php endforeach; ?>
    </select>
</td>

<td>
    <input type="text" name="formLockDate" class="mrl-control" value="<?php echo h(usaDate($current['formLockDate'])); ?>">
</td>

<td>
    <input type="text" name="formLockTime" class="mrl-control" value="<?php echo h(usaTime($current['formLockTime'])); ?>">
</td>

<td>
    <select name="formLocked" class="mrl-control">
    <option value="No" <?php if($lockedLower!=='yes') echo 'selected'; ?>>No</option>
    <option value="Yes" <?php if($lockedLower==='yes') echo 'selected'; ?>>Yes</option>
    </select>
</td>

<!-- Empty cells under By / When / Current Form -->
<td></td><td></td><td></td>
</tr>

<tr class="actions-row">
<td colspan="8" style="text-align:center;">
    <button type="submit" name="action" value="add_year" class="mrl-button">Add <?php echo $nextYear; ?></button>
    <span style="display:inline-block; width:28px;"></span>
    <button type="submit" name="action" value="save_changes" class="mrl-button">Save</button>
</td>
</tr>

</table>

</form>

<div class="note">POST → redirect → GET prevents resubmission.</div>

</body>
</html>
