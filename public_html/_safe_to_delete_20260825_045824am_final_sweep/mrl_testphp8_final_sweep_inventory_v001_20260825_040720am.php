<?php
/**
 * MRL TESTPHP8 Final Sweep Inventory
 *
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 4:07:20 am
 *
 * PURPOSE:
 * Read-only inventory for the last TESTPHP8 cleanup/finalization sweep after
 * the LP/RP test artifacts have already been quarantined.
 *
 * This file DOES NOT move, delete, rename, modify, or write any project file.
 *
 * FOCUS:
 * - leftover installers
 * - backup directories
 * - cleanup scanners / inventories
 * - quarantine directories
 * - other obvious finalization debris
 * - protected permanent application files
 *
 * TARGET:
 * TESTPHP8 only
 *
 * PHP:
 * Compatible with PHP 7.3.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
$root = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';

$errors = array();
$rows = array();

function mrlfs_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlfs_rel($root, $path) {
    if (strpos($path, $root . '/') === 0) {
        return substr($path, strlen($root) + 1);
    }
    return $path;
}

function mrlfs_add(&$rows, $category, $path, $status, $notes) {
    $rows[] = array(
        'category' => $category,
        'path' => $path,
        'status' => $status,
        'notes' => $notes
    );
}

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: TESTPHP8-only. Current host: ' . $host;
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable.';
}

$protectedFiles = array(
    'team.php' => 'Expected final team page: v034.',
    'submit-team-picks.php' => 'Permanent RP-capable submission path.',
    'team_replacement_driver.php' => 'Permanent RP interface.',
    'race_results/race_results_rd_helper.php' => 'Permanent RD/RP helper.',
    'race_results/race_results_monitor.php' => 'Permanent race results monitor.',
    'team_chart.php' => 'Permanent privacy guard target; expected v018.',
    'submitted_teams_count.php' => 'Permanent safe submission-status link.',
    'race_results/race_schedule_helper.php' => 'Canonical schedule helper.'
);

$knownReview = array(
    'mrl_testphp8_cleanup_inventory_v005_20260824_124132000.php' => 'Old cleanup inventory; likely final-sweep candidate.',
    'mrl_testphp8_final_cleanup_inventory_v006_20260825_033732am.php' => 'Current cleanup inventory; final-sweep candidate after review.',
    'mrl_testphp8_lp_rp_final_quarantine_installer_v001_20260825_035502am.php' => 'Completed quarantine installer; final-sweep candidate after review.',
    'mrl_lp_rp_finalization_installer_v001_20260824_021033pm.php' => 'Completed LP/RP finalization installer.',
    'mrl_open_pick_privacy_guard_installer_v001_20260824_094504pm.php' => 'Failed/obsolete privacy installer.',
    'mrl_open_pick_privacy_guard_installer_v002_20260824_095443pm.php' => 'Superseded privacy installer.',
    'mrl_open_pick_privacy_guard_installer_v003_20260824_095836pm.php' => 'Successful privacy installer; final-sweep candidate.',
    'mrl_team_page_section_panels_v033_installer_v001_20260824_114720pm.php' => 'Successful team.php v033 presentation installer.',
    'mrl_team_previous_years_toggle_v034_installer_v001_20260825_122616am.php' => 'Successful team.php v034 presentation installer.',
    'mrl_team_previous_years_toggle_v034_backup_20260825_122616am' => 'Backup directory from v034 installer.',
    'race_results/README_INSTALL_AND_UPDATE.md' => 'Deferred documentation; preserve unless intentionally retired.'
);

if (empty($errors)) {
    foreach ($protectedFiles as $rel => $note) {
        $full = $root . '/' . $rel;
        mrlfs_add(
            $rows,
            'PROTECTED',
            $rel,
            file_exists($full) ? 'PRESENT' : 'MISSING',
            $note
        );
    }

    foreach ($knownReview as $rel => $note) {
        $full = $root . '/' . $rel;
        mrlfs_add(
            $rows,
            'KNOWN REVIEW',
            $rel,
            file_exists($full) ? 'PRESENT' : 'ABSENT',
            $note
        );
    }

    $seen = array();
    foreach ($rows as $r) {
        $seen[$r['path']] = true;
    }

    $patterns = array(
        'mrl_*installer*.php',
        'mrl_*inventory*.php',
        'mrl_*scanner*.php',
        'mrl_*diagnostic*.php',
        'mrl_*backup*',
        'mrl_*_backup_*',
        '_safe_to_delete_*'
    );

    foreach ($patterns as $pattern) {
        $matches = glob($root . '/' . $pattern);
        if ($matches === false) {
            $matches = array();
        }

        foreach ($matches as $full) {
            $rel = mrlfs_rel($root, $full);
            if (isset($seen[$rel])) {
                continue;
            }

            $seen[$rel] = true;

            $note = is_dir($full)
                ? 'Discovered directory. Review before quarantine/purge.'
                : 'Discovered file. Review before quarantine.';

            mrlfs_add(
                $rows,
                'DISCOVERED REVIEW',
                $rel,
                'PRESENT',
                $note
            );
        }
    }

    /*
     * Explicit quarantine awareness.
     */
    $quarantinePatterns = array(
        $root . '/_safe_to_delete_*'
    );

    foreach ($quarantinePatterns as $pattern) {
        $matches = glob($pattern);
        if ($matches === false) {
            $matches = array();
        }

        foreach ($matches as $full) {
            $rel = mrlfs_rel($root, $full);
            if (isset($seen[$rel])) {
                continue;
            }

            $seen[$rel] = true;
            mrlfs_add(
                $rows,
                'QUARANTINE',
                $rel,
                'PRESENT',
                'Existing quarantine directory. Do not purge during finalization unless explicitly approved.'
            );
        }
    }
}

$countProtectedMissing = 0;
$countKnownPresent = 0;
$countDiscoveredPresent = 0;
$countQuarantinePresent = 0;

foreach ($rows as $r) {
    if ($r['category'] === 'PROTECTED' && $r['status'] === 'MISSING') {
        $countProtectedMissing++;
    } elseif ($r['category'] === 'KNOWN REVIEW' && $r['status'] === 'PRESENT') {
        $countKnownPresent++;
    } elseif ($r['category'] === 'DISCOVERED REVIEW' && $r['status'] === 'PRESENT') {
        $countDiscoveredPresent++;
    } elseif ($r['category'] === 'QUARANTINE' && $r['status'] === 'PRESENT') {
        $countQuarantinePresent++;
    }
}

$overallPass = empty($errors) && $countProtectedMissing === 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL TESTPHP8 Final Sweep Inventory v001</title>
<style>
body{margin:0;background:#111;color:#eee;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1500px;margin:16px auto;padding:0 14px}
.banner{border:1px solid #396d36;background:#173717;border-radius:12px;padding:14px 18px}
.banner h1{margin:0 0 4px;color:#e9ffd9;font-size:28px}
.sub{color:#d9efc9}
.notice{margin-top:12px;border:1px solid #b87500;background:#4b2d00;border-radius:9px;padding:11px 14px;color:#ffd985}
.card{margin-top:14px;border:1px solid #444;background:#1b1b1b;border-radius:11px;padding:14px 18px}
h2{margin:2px 0 12px;font-size:24px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:7px 9px;border-bottom:1px solid #353535;text-align:left;vertical-align:top}
th{background:#242424}
.pass{color:#78ef9c;font-weight:bold}
.warn{color:#ffd36f;font-weight:bold}
.fail{color:#ff9292;font-weight:bold}
.path{font-family:Consolas,monospace;color:#cddcff;word-break:break-all}
.bad{margin-top:12px;border:1px solid #ad3e3e;background:#511818;border-radius:8px;padding:11px 14px;color:#ffb1b1}
.summary{display:flex;gap:18px;flex-wrap:wrap}
.pill{border:1px solid #555;border-radius:999px;padding:7px 11px;background:#222}
.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">
<div class="banner">
<h1>MRL TESTPHP8 Final Sweep Inventory v001</h1>
<div class="sub">READ-ONLY • installers / backups / scanners / quarantines • no files changed</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="bad"><?=mrlfs_h($e)?></div>
<?php endforeach; ?>

<div class="notice">
<strong>Final sweep inventory only.</strong>
Nothing on this page is moved, renamed, deleted, or modified.
This is the last review of cleanup debris before we build the final quarantine action and then deal with the scheduler.
</div>

<div class="card">
<h2>Protected baseline — <?=$overallPass ? '<span class="pass">PASS</span>' : '<span class="fail">FAIL</span>'?></h2>
<div class="summary">
<div class="pill">Protected missing: <strong><?=$countProtectedMissing?></strong></div>
<div class="pill">Known review items present: <strong><?=$countKnownPresent?></strong></div>
<div class="pill">Additional discovered items: <strong><?=$countDiscoveredPresent?></strong></div>
<div class="pill">Quarantine dirs present: <strong><?=$countQuarantinePresent?></strong></div>
</div>
</div>

<div class="card">
<h2>Inventory</h2>
<table>
<thead>
<tr>
<th style="width:210px">Category</th>
<th>Path</th>
<th style="width:100px">Status</th>
<th style="width:460px">Notes</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r): ?>
<?php
$cls = 'warn';
if ($r['category'] === 'PROTECTED') {
    $cls = $r['status'] === 'PRESENT' ? 'pass' : 'fail';
} elseif ($r['status'] === 'ABSENT') {
    $cls = 'pass';
}
?>
<tr>
<td><?=mrlfs_h($r['category'])?></td>
<td class="path"><?=mrlfs_h($r['path'])?></td>
<td class="<?=$cls?>"><?=mrlfs_h($r['status'])?></td>
<td><?=mrlfs_h($r['notes'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="card small">
<strong>Next step:</strong> show this inventory result before moving anything.
We will identify exactly which remaining installers/backups/scanners are safe to quarantine.
Existing quarantine directories will remain untouched unless you separately approve a purge later.
</div>
</div>
</body>
</html>
