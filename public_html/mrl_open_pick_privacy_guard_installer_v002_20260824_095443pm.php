<?php
declare(strict_types=1);
/**
 * MRL Open Pick Privacy Guard Installer
 * VERSION: v002
 * LAST MODIFIED: 8/24/2026 9:54:43 pm
 *
 * TESTPHP8-only. File changes only. No DB or scheduler changes.
 * Targets:
 *   submitted_teams_count.php -> v001
 *   team_chart.php v017 -> v018
 */

date_default_timezone_set('America/New_York');

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$countPath = $root . '/submitted_teams_count.php';
$chartPath = $root . '/team_chart.php';
$backupDir = $root . '/mrl_open_pick_privacy_guard_backup_20260824_095443pm';
$countBackup = $backupDir . '/submitted_teams_count.php.before_privacy_guard';
$chartBackup = $backupDir . '/team_chart.php.v017.before_privacy_guard';

$errors = [];
$messages = [];
$checks = [];
$action = (string)($_POST['action'] ?? 'preflight');

function pg_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pg_c($h,$n){ return substr_count((string)$h,(string)$n); }
function pg_check(&$a,$label,$ok,$detail){
    $a[]=['label'=>$label,'ok'=>(bool)$ok,'detail'=>$detail];
    return (bool)$ok;
}
function pg_replace_once($s,$old,$new,$label){
    $n=substr_count($s,$old);
    if($n!==1) throw new RuntimeException($label.' expected once; found '.$n.'.');
    return str_replace($old,$new,$s);
}

$countOld = <<<'OLDCOUNT'
Click <a href="team_chart.php">here</a> to see the submission status of all teams. <br><br>***** The <?php echo "$raceYear $segmentName" ?> team chart (with drivers) will appear here at <?php echo "$formLockDate" ?> (refresh browser if necessary) *****
OLDCOUNT;

$countNew = <<<'NEWCOUNT'
Click <a href="submitted_teams.php" target="_blank" rel="noopener noreferrer">here</a> to see the submission status of all teams. <br><br>***** The <?php echo "$raceYear $segmentName" ?> team chart (with drivers) will appear here at <?php echo "$formLockDate" ?> (refresh browser if necessary) *****
NEWCOUNT;

$requireOld = <<<'OLDREQ'
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';
OLDREQ;

$requireNew = <<<'NEWREQ'
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';
require_once __DIR__ . '/race_results/race_schedule_helper.php';
NEWREQ;

$anchorOld = <<<'OLDANCHOR'
function valid_segment($s): bool {
    return preg_match('/^S[1-9]\d*$/', (string)$s) === 1;
}

OLDANCHOR;

$anchorNew = <<<'NEWANCHOR'
function valid_segment($s): bool {
    return preg_match('/^S[1-9]\d*$/', (string)$s) === 1;
}

/**
 * Canonical normal-pick deadline for a year/segment.
 * Segment picks become public when that segment's first points race starts.
 * Returns 0 if the deadline cannot be resolved.
 */
function tc_segment_pick_deadline_timestamp($dbo, $dbconnect, string $year, string $segment): int
{
    $startRace = 0;

    try {
        if (isset($dbo) && $dbo instanceof PDO) {
            $stmt = $dbo->prepare(
                "SELECT startRace
                   FROM segment_race_ranges
                  WHERE raceYear = :year
                    AND segment = :segment
                  LIMIT 1"
            );
            $stmt->execute([':year'=>$year, ':segment'=>$segment]);
            $value = $stmt->fetchColumn();
            $startRace = ($value === false) ? 0 : (int)$value;
        } elseif (isset($dbconnect) && $dbconnect instanceof mysqli) {
            $yearInt = (int)$year;
            $stmt = mysqli_prepare(
                $dbconnect,
                "SELECT startRace
                   FROM segment_race_ranges
                  WHERE raceYear = ?
                    AND segment = ?
                  LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'is', $yearInt, $segment);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $startRaceDb);
                if (mysqli_stmt_fetch($stmt)) $startRace = (int)$startRaceDb;
                mysqli_stmt_close($stmt);
            }
        }

        if ($startRace <= 0) return 0;

        $races = mrl_schedule_helper_points_races((int)$year);
        foreach ($races as $race) {
            if ((int)($race['race_number'] ?? 0) !== $startRace) continue;
            return (int)mrl_schedule_helper_race_datetime($race)->getTimestamp();
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

NEWANCHOR;

$gateOld = <<<'OLDGATE'
// ---------- submission gating (match team.php behavior) ----------
$currentRaceYear = isset($raceYear) ? (string)$raceYear : '';
$currentSegment  = isset($segment)  ? (string)$segment  : '';

$isCurrentSelection = ($selectedYear === $currentRaceYear && $selectedSegment === $currentSegment);

$formLockDateRaw = trim((string)($formLockDate ?? ''));
$formLockTimeRaw = trim((string)($formLockTime ?? ''));

$lockTs = 0;
if ($formLockDateRaw !== '') {
    $lockStr = ($formLockTimeRaw !== '') ? ($formLockDateRaw . ' ' . $formLockTimeRaw) : $formLockDateRaw;
    $tmp = strtotime($lockStr);
    $lockTs = ($tmp === false) ? 0 : (int)$tmp;
}

$userTs = strtotime($currentTimeIs);
$userTs = ($userTs === false) ? time() : (int)$userTs;

$showSubmittedInsteadOfChart = false;
if (
    $hasSelection
    && $isCurrentSelection
    && isset($formLocked) && $formLocked === 'no'
    && $lockTs > 0
    && $lockTs > $userTs
) {
    $showSubmittedInsteadOfChart = true;
}

$lockTimeDisplay = '';
$lockDateDisplay = '';

if ($lockTs > 0) {
    $lockTimeDisplay = date('g:i A', $lockTs);
    $lockDateDisplay = date('n/j/Y', $lockTs);
} else {
    if ($formLockTimeRaw !== '') {
        $lockTimeDisplay = $formLockTimeRaw;
        $t = strtotime($formLockTimeRaw);
        if ($t !== false) $lockTimeDisplay = date('g:i A', $t);
    }
    if ($formLockDateRaw !== '') {
        $lockDateDisplay = $formLockDateRaw;
        $d = strtotime($formLockDateRaw);
        if ($d !== false) $lockDateDisplay = date('n/j/Y', $d);
    }
}
OLDGATE;

$gateNew = <<<'NEWGATE'
// ---------- open-pick privacy gating ----------
$currentRaceYear = isset($raceYear) ? (string)$raceYear : '';
$userTs = time();
$lockTs = 0;
$showSubmittedInsteadOfChart = false;

/*
 * Privacy rule:
 * For the CURRENT season, a segment's driver selections remain private until
 * that segment's first points race starts. This is independent of the legacy
 * manual formLockDate and of scoring-vs-pick-segment state.
 *
 * Current-season deadline lookup fails CLOSED.
 * Previous seasons remain normally viewable.
 */
if ($hasSelection && $selectedYear === $currentRaceYear) {
    $lockTs = tc_segment_pick_deadline_timestamp(
        $dbo ?? null,
        $dbconnect ?? null,
        $selectedYear,
        $selectedSegment
    );

    if ($lockTs <= 0 || $userTs < $lockTs) {
        $showSubmittedInsteadOfChart = true;
    }
}

$lockTimeDisplay = '';
$lockDateDisplay = '';

if ($lockTs > 0) {
    $lockTimeDisplay = date('g:i A', $lockTs);
    $lockDateDisplay = date('n/j/Y', $lockTs);
} elseif ($showSubmittedInsteadOfChart) {
    $lockTimeDisplay = 'the segment deadline';
    $lockDateDisplay = '(canonical schedule unavailable)';
}
NEWGATE;

if ($host !== 'testphp8.manliusracingleague.com') {
    $errors[]='REFUSED: TESTPHP8-only. Current host: '.$host;
}
if ($root==='' || !is_dir($root)) $errors[]='Document root unavailable.';

$countCurrent='';
$chartCurrent='';
if (!$errors) {
    $countCurrent=(string)@file_get_contents($countPath);
    $chartCurrent=(string)@file_get_contents($chartPath);
    if ($countCurrent==='') $errors[]='Unable to read submitted_teams_count.php.';
    if ($chartCurrent==='') $errors[]='Unable to read team_chart.php.';
}

$preflightOk = !$errors;

if ($preflightOk) {
    $preflightOk = pg_check($checks,'team_chart.php version',pg_c($chartCurrent,'VERSION: v017')===1,'Expected v017') && $preflightOk;
    $preflightOk = pg_check($checks,'legacy privacy block',pg_c($chartCurrent,$gateOld)===1,'Exact legacy gate found') && $preflightOk;
    $preflightOk = pg_check($checks,'schedule helper not already loaded',pg_c($chartCurrent,'race_schedule_helper.php')===0,'Expected zero existing includes') && $preflightOk;
    $preflightOk = pg_check($checks,'privacy function insertion point',pg_c($chartCurrent,$anchorOld)===1,'Exact anchor found') && $preflightOk;
    $privacyConsumers =
        pg_c($chartCurrent, 'if ($showSubmittedInsteadOfChart) {')
        + pg_c($chartCurrent, 'if ($showSubmittedInsteadOfChart):');

    $preflightOk = pg_check(
        $checks,
        'HTML + Excel use shared privacy flag',
        $privacyConsumers >= 2,
        'Excel + HTML both already honor the same privacy flag'
    ) && $preflightOk;
    $preflightOk = pg_check($checks,'current status link',pg_c($countCurrent,$countOld)===1,'Visible here-link currently targets team_chart.php') && $preflightOk;
    $preflightOk = pg_check($checks,'submitted_teams.php present',is_file($root.'/submitted_teams.php'),'Safe status page exists') && $preflightOk;
    $preflightOk = pg_check($checks,'segment ranges available in app',is_file($root.'/race_results/race_schedule_helper.php'),'Canonical helper exists') && $preflightOk;
}

function build_count($s,$countOld,$countNew){
    $header = "<?Php\n/**\n"
        ." * submitted_teams_count.php\n"
        ." * VERSION: v001\n"
        ." * LAST MODIFIED: 8/24/2026 9:45:04 pm\n"
        ." *\n"
        ." * v001 (8/24/2026 9:45:04 pm)\n"
        ." * - SAFETY: Submission-status link points to submitted_teams.php, not team_chart.php.\n"
        ." * - PRESERVE: Count/query/message behavior otherwise unchanged.\n"
        ." */\n";
    $s=pg_replace_once($s,"<?Php\n",$header,'count header');
    return pg_replace_once($s,$countOld,$countNew,'safe here-link');
}

function build_chart($s,$requireOld,$requireNew,$anchorOld,$anchorNew,$gateOld,$gateNew){
    $s=pg_replace_once($s,'VERSION: v017','VERSION: v018','chart version');
    $s=pg_replace_once($s,'LAST MODIFIED: 8/20/2026 1:59:00 am','LAST MODIFIED: 8/24/2026 9:45:04 pm','chart timestamp');

    $needle=" * CHANGELOG:\n *\n";
    $entry=" * CHANGELOG:\n *\n"
        ." * v018 (8/24/2026 9:45:04 pm)\n"
        ." * - SAFETY: Current-season driver picks remain private until the segment's first points race starts.\n"
        ." * - SAFETY: Uses segment_race_ranges + canonical race schedule helper, not legacy formLockDate.\n"
        ." * - SAFETY: Direct chart access and spreadsheet export use the same privacy gate.\n"
        ." * - SAFETY: Current-season deadline lookup fails closed; previous seasons remain viewable.\n"
        ." * - PRESERVE: LP/RD/Approved Exception rendering after deadline.\n"
        ." *\n";
    $s=pg_replace_once($s,$needle,$entry,'chart changelog');
    $s=pg_replace_once($s,$requireOld,$requireNew,'schedule helper include');
    $s=pg_replace_once($s,$anchorOld,$anchorNew,'deadline helper');
    $s=pg_replace_once($s,$gateOld,$gateNew,'privacy gate');
    return $s;
}

if ($action==='install' && !$errors) {
    if (!$preflightOk) {
        $errors[]='INSTALL REFUSED: preflight is not clean.';
    } else {
        try {
            $countNewFile=build_count($countCurrent,$countOld,$countNew);
            $chartNewFile=build_chart($chartCurrent,$requireOld,$requireNew,$anchorOld,$anchorNew,$gateOld,$gateNew);

            if (!is_dir($backupDir) && !mkdir($backupDir,0755,true)) throw new RuntimeException('Could not create backup directory.');
            if (!copy($countPath,$countBackup)) throw new RuntimeException('Could not back up submitted_teams_count.php.');
            if (!copy($chartPath,$chartBackup)) throw new RuntimeException('Could not back up team_chart.php.');

            if (file_put_contents($countPath,$countNewFile,LOCK_EX)===false) throw new RuntimeException('Could not write submitted_teams_count.php.');
            if (file_put_contents($chartPath,$chartNewFile,LOCK_EX)===false) {
                @copy($countBackup,$countPath);
                throw new RuntimeException('Could not write team_chart.php; count rollback attempted.');
            }

            $ci=(string)file_get_contents($countPath);
            $ti=(string)file_get_contents($chartPath);
            $post=true;
            $post=pg_check($checks,'POST count v001',pg_c($ci,'VERSION: v001')===1,'Installed') && $post;
            $post=pg_check($checks,'POST safe here-link',pg_c($ci,'href="submitted_teams.php"')>=1 && pg_c($ci,'Click <a href="team_chart.php">here</a>')===0,'No visible direct chart link') && $post;
            $post=pg_check($checks,'POST chart v018',pg_c($ti,'VERSION: v018')===1,'Installed') && $post;
            $post=pg_check($checks,'POST canonical helper',pg_c($ti,'race_schedule_helper.php')===1,'Loaded once') && $post;
            $post=pg_check($checks,'POST deadline function',pg_c($ti,'function tc_segment_pick_deadline_timestamp')===1,'Installed') && $post;
            $post=pg_check($checks,'POST old gate removed',pg_c($ti,'$formLockDateRaw')===0 && pg_c($ti,'$isCurrentSelection')===0,'Legacy assumption removed') && $post;
            $post=pg_check($checks,'POST fail-closed guard',pg_c($ti,'if ($lockTs <= 0 || $userTs < $lockTs)')===1,'Privacy-first guard installed') && $post;
            $postPrivacyConsumers =
                pg_c($ti, 'if ($showSubmittedInsteadOfChart) {')
                + pg_c($ti, 'if ($showSubmittedInsteadOfChart):');

            $post=pg_check(
                $checks,
                'POST HTML + Excel still gated',
                $postPrivacyConsumers >= 2,
                'HTML and Excel protected by the same privacy flag'
            ) && $post;

            if ($post) $messages[]='PASS: Open-pick privacy guard installed.';
            else $errors[]='POSTFLIGHT FAILED. Use rollback.';
        } catch (Throwable $e) {
            $errors[]='INSTALL FAILED: '.$e->getMessage();
        }
    }
}

if ($action==='rollback' && !$errors) {
    if (!is_file($countBackup) || !is_file($chartBackup)) {
        $errors[]='ROLLBACK REFUSED: backups missing.';
    } else {
        $a=copy($countBackup,$countPath);
        $b=copy($chartBackup,$chartPath);
        if($a && $b) $messages[]='ROLLBACK PASS: both original files restored.';
        else $errors[]='ROLLBACK FAILED.';
    }
}

$backupExists=is_file($countBackup) && is_file($chartBackup);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL Open Pick Privacy Guard v002</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px Arial,sans-serif}
.wrap{max-width:1450px;margin:0 auto;padding:16px}
.head{background:#20391d;border:1px solid #4d8244;border-radius:10px;padding:14px 16px}
h1{margin:0;color:#dcffd2;font-size:25px}.sub{color:#bdd9b6;margin-top:5px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:14px 16px;margin-top:12px}
.ok{background:#153b20;border:1px solid #3f8752;color:#caffd5;border-radius:8px;padding:11px;margin-top:12px}
.err{background:#481919;border:1px solid #a04949;color:#ffaaaa;border-radius:8px;padding:11px;margin-top:12px}
.warn{background:#4a2d00;border:1px solid #af7628;color:#ffd783;border-radius:8px;padding:11px;margin-top:12px}
table{width:100%;border-collapse:collapse}th,td{padding:7px 8px;border-bottom:1px solid #333;text-align:left}
th{background:#282828}.pass{color:#78ef9c;font-weight:bold}.fail{color:#ff9292;font-weight:bold}
button{padding:9px 14px;border-radius:6px;border:1px solid #bd6d51;background:#773724;color:#fff;font-weight:bold;cursor:pointer}
.rb{background:#66561f;border-color:#ad9340}.mono{font-family:Consolas,monospace}
</style>
</head>
<body><div class="wrap">
<div class="head"><h1>MRL Open Pick Privacy Guard Installer v002</h1>
<div class="sub">TESTPHP8 • submitted_teams_count.php + team_chart.php v017 → v018</div></div>

<?php foreach($errors as $e): ?><div class="err"><?=pg_h($e)?></div><?php endforeach; ?>
<?php foreach($messages as $m): ?><div class="ok"><?=pg_h($m)?></div><?php endforeach; ?>

<div class="warn"><b>Privacy-first:</b> current-season drivers remain hidden until the selected segment's first points race starts. If that deadline cannot be resolved, the current-season chart fails CLOSED.</div>

<div class="card">
<h2>Preflight — <span class="<?=$preflightOk?'pass':'fail'?>"><?=$preflightOk?'PASS':'FAIL'?></span></h2>
<?php if($preflightOk && $action==='preflight'): ?>
<form method="post" onsubmit="return confirm('Install TESTPHP8 open-pick privacy guard? Both files will be backed up first.');">
<input type="hidden" name="action" value="install"><button>Install Open Pick Privacy Guard</button></form>
<?php endif; ?>
<?php if($backupExists): ?>
<form method="post" style="margin-top:10px" onsubmit="return confirm('Restore both original files?');">
<input type="hidden" name="action" value="rollback"><button class="rb">Rollback Both Files</button></form>
<?php endif; ?>

<table style="margin-top:12px"><tr><th>Check</th><th>Status</th><th>Detail</th></tr>
<?php foreach($checks as $c): ?><tr>
<td><?=pg_h($c['label'])?></td><td class="<?=$c['ok']?'pass':'fail'?>"><?=$c['ok']?'PASS':'FAIL'?></td><td><?=pg_h($c['detail'])?></td>
</tr><?php endforeach; ?></table>
</div>

<div class="card"><h2>After PASS</h2>
<ol>
<li>From <span class="mono">team.php</span>, click the open-window <b>here</b> link. It should open <span class="mono">submitted_teams.php</span> with names/status only.</li>
<li>Open <span class="mono">team_chart.php</span> directly and select <b>2026 / S4</b>. Drivers must remain hidden before the S4 first-race start.</li>
<li>Select completed 2026 S1/S2/S3. Full charts should still display.</li>
<li>Spreadsheet export for protected S4 must also remain blocked.</li>
<li>Historical years should still display normally.</li>
</ol></div>

<div class="card">No DB changes. No scheduler changes. No team.php changes.</div>
</div></body></html>
