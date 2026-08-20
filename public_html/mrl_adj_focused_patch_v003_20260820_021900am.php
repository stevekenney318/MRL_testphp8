<?php
declare(strict_types=1);

/**
 * mrl_adj_focused_patch.php
 * VERSION: v003
 * LAST MODIFIED: 8/20/2026 2:19:00 am
 *
 * Focused TestPHP8-only ADJ patch.
 *
 * - Preserves the original user_picks.entryDate in the SAME UPDATE that
 *   changes LP -> SEG, preventing automatic timestamp refresh.
 * - Changes adjusted-pick note wording to "Approved Exception".
 * - NO database schema changes.
 * - NO install-time database data changes.
 *
 * TARGETS:
 * admin_pick_adjustment.php v001 -> v002
 * current_user_team_chart.php v004 -> v005
 * team_chart.php v016 -> v017
 * current_segment_chart_by_entry_time.php v003 -> v004
 */

date_default_timezone_set('America/New_York');

const REQUIRED_HOST = 'testphp8.manliusracingleague.com';
const PATCH_TS = '20260820_021900am';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function replace_once($src, $old, $new, $label) {
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match; found ' . $count);
    }
    return str_replace($old, $new, $src);
}

function atomic_write($path, $content) {
    $tmp = dirname($path) . '/.' . basename($path) . '.adjpatch_' . mt_rand(100000,999999) . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temp file for ' . basename($path));
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace ' . basename($path));
    }
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$files = [
    'admin_pick_adjustment.php' => $root . '/admin_pick_adjustment.php',
    'current_user_team_chart.php' => $root . '/current_user_team_chart.php',
    'team_chart.php' => $root . '/team_chart.php',
    'current_segment_chart_by_entry_time.php' => $root . '/current_segment_chart_by_entry_time.php',
];

$expected = [
    'admin_pick_adjustment.php' => 'VERSION: v001',
    'current_user_team_chart.php' => 'VERSION: v004',
    'team_chart.php' => 'VERSION: v016',
    'current_segment_chart_by_entry_time.php' => 'VERSION: v003',
];

$checks = [];
$errors = [];
$current = [];
$prepared = [];
$backupDir = '';
$complete = false;

$checks[] = ['Host is TestPHP8', $host === REQUIRED_HOST ? 'PASS' : 'FAIL'];
if ($host !== REQUIRED_HOST) {
    $errors[] = 'Refusing to run outside ' . REQUIRED_HOST;
}

foreach ($expected as $name => $marker) {
    $path = $files[$name];
    if (!is_file($path)) {
        $checks[] = [$name . ' exists', 'FAIL'];
        $errors[] = $name . ' not found.';
        continue;
    }

    $src = (string)file_get_contents($path);
    $current[$name] = $src;
    $ok = strpos($src, $marker) !== false;
    $checks[] = [$name . ' expected current version', $ok ? 'PASS' : 'FAIL'];

    if (!$ok) {
        $errors[] = $name . ' version does not match expected installed state.';
    }
}

if (!$errors) {
    try {
        // admin_pick_adjustment.php
        $s = $current['admin_pick_adjustment.php'];
        $s = replace_once(
            $s,
            " * VERSION: v001\n * LAST MODIFIED: 8/19/2026 7:12:00 pm\n",
            " * VERSION: v002\n * LAST MODIFIED: 8/20/2026 1:59:00 am\n",
            'admin header'
        );

        $old = <<<'OLD'
        $seg = 'SEG';
        $stmtU = mysqli_prepare($dbconnect, "UPDATE user_picks
                                             SET pick_type=?, effective_race=?
                                             WHERE pickID=? AND pick_type='LP'");
        if (!$stmtU) throw new RuntimeException('Unable to prepare current-pick update.');
        mysqli_stmt_bind_param($stmtU, 'sii', $seg, $startRace, $pickID);
OLD;

        $new = <<<'NEW'
        $seg = 'SEG';
        $originalEntryDate = (string)$row['entryDate'];
        $stmtU = mysqli_prepare($dbconnect, "UPDATE user_picks
                                             SET pick_type=?, effective_race=?, entryDate=?
                                             WHERE pickID=? AND pick_type='LP'");
        if (!$stmtU) throw new RuntimeException('Unable to prepare current-pick update.');

        // Explicitly preserve the true submission timestamp in the SAME UPDATE
        // that changes LP -> SEG so automatic ON UPDATE behavior cannot replace it.
        mysqli_stmt_bind_param($stmtU, 'sisi', $seg, $startRace, $originalEntryDate, $pickID);
NEW;

        $s = replace_once($s, $old, $new, 'entryDate preservation');
        $prepared['admin_pick_adjustment.php'] = $s;

        // current_user_team_chart.php
        $s = $current['current_user_team_chart.php'];
        $s = replace_once(
            $s,
            " * VERSION: v004\n * LAST MODIFIED: 8/19/2026 7:12:00 pm\n",
            " * VERSION: v005\n * LAST MODIFIED: 8/20/2026 1:59:00 am\n",
            'current user chart header'
        );
        $s = replace_once(
            $s,
            '$notes[] = [\'marker\' => $marker, \'text\' => $segmentLabel . \' — Admin-approved regular pick\'];',
            '$notes[] = [\'marker\' => $marker, \'text\' => $segmentLabel . \' — Approved Exception\'];',
            'current user chart wording'
        );
        $prepared['current_user_team_chart.php'] = $s;

        // team_chart.php
        $s = $current['team_chart.php'];
        $s = replace_once(
            $s,
            " * VERSION: v016\n * LAST MODIFIED: 8/19/2026 7:12:00 pm\n",
            " * VERSION: v017\n * LAST MODIFIED: 8/20/2026 1:59:00 am\n",
            'team chart header'
        );
        $s = replace_once(
            $s,
            '$notes[] = [\'marker\' => $marker, \'text\' => $teamName . \' — Admin-approved regular pick\'];',
            '$notes[] = [\'marker\' => $marker, \'text\' => $teamName . \' — Approved Exception\'];',
            'team chart wording'
        );
        $prepared['team_chart.php'] = $s;

        // current_segment_chart_by_entry_time.php
        $s = $current['current_segment_chart_by_entry_time.php'];
        $s = replace_once(
            $s,
            " * VERSION: v003\n * LAST MODIFIED: 8/19/2026 7:12:00 pm\n",
            " * VERSION: v004\n * LAST MODIFIED: 8/20/2026 1:59:00 am\n",
            'entry-time chart header'
        );
        $s = replace_once(
            $s,
            '$noteText = $teamName . \' — Admin-approved regular pick\';',
            '$noteText = $teamName . \' — Approved Exception\';',
            'entry-time chart wording'
        );
        $prepared['current_segment_chart_by_entry_time.php'] = $s;

        $requiredMarkers = [
            'admin_pick_adjustment.php' => ['VERSION: v002', 'entryDate=?', "'sisi'"],
            'current_user_team_chart.php' => ['VERSION: v005', 'Approved Exception'],
            'team_chart.php' => ['VERSION: v017', 'Approved Exception'],
            'current_segment_chart_by_entry_time.php' => ['VERSION: v004', 'Approved Exception'],
        ];

        foreach ($requiredMarkers as $name => $markers) {
            $ok = true;
            foreach ($markers as $m) {
                if (strpos($prepared[$name], $m) === false) {
                    $ok = false;
                    break;
                }
            }
            $checks[] = [$name . ' replacement prepared', $ok ? 'PASS' : 'FAIL'];
            if (!$ok) {
                $errors[] = $name . ' replacement failed structural validation.';
            }
        }

        $semanticsOk =
            strpos($prepared['admin_pick_adjustment.php'], "$adjType = 'ADJ';") !== false &&
            strpos($prepared['admin_pick_adjustment.php'], "$segType = 'SEG';") !== false &&
            strpos($prepared['admin_pick_adjustment.php'], '$supersedesPickID = $pickID;') !== false;

        $checks[] = ['SEG current row / ADJ history semantics preserved', $semanticsOk ? 'PASS' : 'FAIL'];
        if (!$semanticsOk) {
            $errors[] = 'ADJ semantics guard failed.';
        }

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'install') {
    try {
        $backupDir = $root . '/mrl_adj_focused_patch_backup_' . PATCH_TS;

        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            throw new RuntimeException('Could not create patch backup folder.');
        }

        foreach ($files as $name => $path) {
            if (!copy($path, $backupDir . '/' . $name)) {
                throw new RuntimeException('Could not back up ' . $name);
            }
        }

        try {
            foreach ($prepared as $name => $content) {
                atomic_write($files[$name], $content);
            }
        } catch (Throwable $writeError) {
            foreach ($files as $name => $path) {
                $backup = $backupDir . '/' . $name;
                if (is_file($backup)) {
                    @copy($backup, $path);
                }
            }
            throw $writeError;
        }

        $post = [
            'admin_pick_adjustment.php' => 'VERSION: v002',
            'current_user_team_chart.php' => 'VERSION: v005',
            'team_chart.php' => 'VERSION: v017',
            'current_segment_chart_by_entry_time.php' => 'VERSION: v004',
        ];

        foreach ($post as $name => $marker) {
            $installed = (string)file_get_contents($files[$name]);
            if (strpos($installed, $marker) === false) {
                throw new RuntimeException('Post-install validation failed for ' . $name);
            }
        }

        $complete = true;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL Focused ADJ Patch v003</title>
<style>
body{margin:0;background:#151515;color:#eee;font:16px Arial,sans-serif}
.wrap{width:min(1180px,94%);margin:26px auto}
h1,h2{color:#ffd08a}
.card{background:#222;border:1px solid #4d473f;border-radius:14px;padding:18px 22px;margin:14px 0}
table{width:100%;border-collapse:collapse}
td{padding:8px 10px;border-bottom:1px solid #3a3a3a}
.pass{color:#62e89b}.fail{color:#ff7d7d}.ok{color:#62e89b;font-weight:bold}.bad{color:#ff7d7d}
.btn{font:16px Arial;background:#3a2f1b;color:#ffd08a;border:1px solid #b18745;border-radius:9px;padding:10px 15px;cursor:pointer}
.note{border-left:4px solid #ffd166;padding:8px 12px;background:#2a2519}
</style>
</head>
<body>
<div class="wrap">

<h1>MRL Focused ADJ Patch v003</h1>

<div class="card">
<b>Target:</b> <?php echo h($host); ?><br>
<b>Root:</b> <?php echo h($root); ?><br>
<b>Build:</b> 8/20/2026 2:19:00 am ET
</div>

<div class="card">
<h2>Scope</h2>
<p><b>File-only patch.</b> No database schema changes and no install-time database data changes.</p>
<ul>
<li>Preserve original submission timestamp during LP → SEG approval.</li>
<li>Display adjusted pick as <b>Approved Exception</b>.</li>
<li>Keep SEG current-row / ADJ history behavior unchanged.</li>
</ul>
</div>

<div class="card">
<h2>Preflight</h2>
<table>
<?php foreach ($checks as $row): ?>
<tr>
<td><?php echo h($row[0]); ?></td>
<td class="<?php echo strpos($row[1], 'PASS') === 0 ? 'pass' : 'fail'; ?>"><?php echo h($row[1]); ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if ($errors): ?>

<div class="card">
<h2 class="bad">STOPPED</h2>
<ul><?php foreach ($errors as $err): ?><li><?php echo h($err); ?></li><?php endforeach; ?></ul>
<p>No patch changes should be made from this state.</p>
</div>

<?php elseif ($complete): ?>

<div class="card">
<h2 class="ok">PATCH COMPLETE.</h2>
<p><b>Backup folder:</b><br><?php echo h($backupDir); ?></p>
<ul>
<li>admin_pick_adjustment.php v002</li>
<li>current_user_team_chart.php v005</li>
<li>team_chart.php v017</li>
<li>current_segment_chart_by_entry_time.php v004</li>
</ul>

<div class="note">
<b>Retest:</b> With the known-good LP database state, approve the 2026 S3 LP and verify:
<ol>
<li>Current row = SEG / Effective R18.</li>
<li>Original entryDate remains 2026-06-28 15:40:33.</li>
<li>Original LP history remains and new ADJ history is added.</li>
<li>Charts show <b>Approved Exception</b>.</li>
<li>Scoring is effective from R18.</li>
</ol>
</div>

<p><b>Post-install reminder:</b> After testing, sync TestPHP8 back to your local folder with WinSCP, then commit/push to GitHub.</p>
</div>

<?php else: ?>

<div class="card">
<h2>Ready</h2>
<p>Preflight passed. This focused ADJ patch is ready to install.</p>
<form method="post">
<button class="btn" name="action" value="install">INSTALL FOCUSED ADJ PATCH v003</button>
</form>
</div>

<?php endif; ?>

</div>
</body>
</html>
