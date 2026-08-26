<?php
/**
 * MRL TESTPHP8 Final Sweep Inventory
 *
 * VERSION: v002
 * LAST MODIFIED: 8/25/2026 4:49:41 am
 *
 * PURPOSE:
 * Read-only final-sweep inventory after the clean-start 16-item LP/RP
 * quarantine completed successfully.
 *
 * This file DOES NOT move, delete, rename, modify, or write any project file.
 *
 * FOCUS:
 * - verify the current permanent phase baseline still exists
 * - verify the 16 clean-start temp artifacts remain absent from active paths
 * - list currently present installers / inventories / backup directories
 * - list current quarantine directories
 * - identify any leftover RD/RP pending, marker, or simulation artifacts
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

function mrlfsi_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlfsi_rel($root, $path) {
    if (strpos($path, $root . '/') === 0) {
        return substr($path, strlen($root) + 1);
    }
    return $path;
}

function mrlfsi_add(&$rows, $category, $path, $status, $notes) {
    $rows[] = array(
        'category' => $category,
        'path' => $path,
        'status' => $status,
        'notes' => $notes
    );
}

function mrlfsi_read_version($path) {
    if (!is_file($path)) {
        return '';
    }

    $data = @file_get_contents($path, false, null, 0, 12000);
    if ($data === false) {
        return '';
    }

    if (preg_match('/VERSION:\s*(v[0-9]+)/i', $data, $m) === 1) {
        return $m[1];
    }

    return '';
}

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: TESTPHP8-only. Current host: ' . $host;
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable.';
}

/*
 * Phase-protected permanent files.
 */
$protected = array(
    'team.php' => 'v034',
    'submit-team-picks.php' => 'v011',
    'team_replacement_driver.php' => 'v010',
    'race_results/race_results_rd_helper.php' => 'v005',
    'race_results/race_results_monitor.php' => 'v139',
    'team_chart.php' => 'v018',
    'submitted_teams_count.php' => 'v001',
    'race_results/race_schedule_helper.php' => 'v003',
    'admin_setup.php' => 'v004',
    'pick_window_helper.php' => 'v003',
    'config_mrl.php' => 'v003',
    'form-team-picks.php' => 'v007'
);

/*
 * The 16 top-level LP/RP temp artifacts just quarantined.
 * They should now be absent from active TESTPHP8 paths.
 */
$temp16 = array(
    'mrl_lp_rp_base_row_bridge_fix_v001_20260823_121700pm.php',
    'mrl_lp_rp_database_verification_v001_20260823_043700pm.php',
    'mrl_lp_rp_edge_case_harness_v001_20260823_114100am.php',
    'mrl_lp_rp_edge_case_harness_v002_20260823_115500am.php',
    'mrl_lp_rp_edge_case_harness_v003_20260823_120200pm.php',
    'mrl_lp_rp_real_submit_error_capture_v001_20260823_130200pm.php',
    'mrl_lp_rp_real_submit_error_capture_v002_20260823_131000pm.php',
    'mrl_lp_rp_submission_diagnostic_v001_20260823_123500pm.php',
    'mrl_lp_rp_submission_diagnostic_v002_20260823_125800pm.php',
    'mrl_lp_rp_submit_bridge_test_fix_v001_20260823_123900pm.php',
    'mrl_lp_rp_submit_rescue_bridge_v001_20260823_135200pm.php',
    'mrl_lp_rp_submit_structural_repair_v001_20260823_020300pm.php',
    'mrl_lp_rp_submit_structure_diagnostic_v003_20260823_012300pm.php',
    'mrl_rd_be_like_biff_fixture_manager_v001_20260822_074600pm.php',
    'mrl_rp_deadline_test_switch_v001_20260823_025800am.php',
    'mrl_rp_late_submission_server_gate_test_v001_20260823_031600am.php'
);

if (empty($errors)) {
    foreach ($protected as $rel => $expectedVersion) {
        $full = $root . '/' . $rel;
        $exists = file_exists($full);
        $actual = $exists ? mrlfsi_read_version($full) : '';

        $status = $exists ? 'PRESENT' : 'MISSING';
        $notes = 'Expected ' . $expectedVersion . '.';

        if ($exists && $actual !== '') {
            $notes .= ' Found ' . $actual . '.';
            if ($actual !== $expectedVersion) {
                $status = 'VERSION MISMATCH';
            }
        } elseif ($exists) {
            $notes .= ' Version marker not detected.';
        }

        mrlfsi_add($rows, 'PROTECTED', $rel, $status, $notes);
    }

    foreach ($temp16 as $rel) {
        mrlfsi_add(
            $rows,
            'CLEAN-START TEMP',
            $rel,
            file_exists($root . '/' . $rel) ? 'PRESENT' : 'ABSENT',
            'Should remain absent from active paths after clean-start quarantine.'
        );
    }

    /*
     * Explicit race-results temp artifacts that were already absent.
     */
    $rrTemp = array(
        'race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.json',
        'race_results/admin_rd_simulation.php',
        'race_results/_rd_simulation',
        'race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json'
    );

    foreach ($rrTemp as $rel) {
        mrlfsi_add(
            $rows,
            'RACE-RESULTS TEMP',
            $rel,
            file_exists($root . '/' . $rel) ? 'PRESENT' : 'ABSENT',
            'Should remain absent before scheduler is re-enabled.'
        );
    }

    /*
     * Discover current quarantine directories.
     */
    $quarantines = glob($root . '/_safe_to_delete_*');
    if ($quarantines === false) {
        $quarantines = array();
    }

    if (count($quarantines) === 0) {
        mrlfsi_add(
            $rows,
            'QUARANTINE',
            '(none)',
            'ABSENT',
            'No server-side quarantine directory currently exists.'
        );
    } else {
        foreach ($quarantines as $full) {
            mrlfsi_add(
                $rows,
                'QUARANTINE',
                mrlfsi_rel($root, $full),
                'PRESENT',
                'Existing quarantine. Review only; do not purge during this pass.'
            );
        }
    }

    /*
     * Discover final-sweep debris in the TESTPHP8 root.
     * Nothing matched is automatically removed.
     */
    $seen = array();
    $patterns = array(
        'mrl_*installer*.php',
        'mrl_*inventory*.php',
        'mrl_*audit*.php',
        'mrl_*scanner*.php',
        'mrl_*backup*',
        'mrl_*_backup_*'
    );

    foreach ($patterns as $pattern) {
        $matches = glob($root . '/' . $pattern);
        if ($matches === false) {
            $matches = array();
        }

        foreach ($matches as $full) {
            $rel = mrlfsi_rel($root, $full);

            if (isset($seen[$rel])) {
                continue;
            }
            $seen[$rel] = true;

            mrlfsi_add(
                $rows,
                'FINAL-SWEEP REVIEW',
                $rel,
                'PRESENT',
                is_dir($full)
                    ? 'Backup/finalization directory currently present.'
                    : 'Installer/inventory/audit/scanner file currently present.'
            );
        }
    }

    /*
     * Extra RD/RP discovery beyond the exact known temp list.
     */
    $rrPatterns = array(
        $root . '/race_results/2026/*/_rd_pending_*.json',
        $root . '/race_results/*marker*.json',
        $root . '/race_results/_*marker*.json',
        $root . '/race_results/_rd_simulation'
    );

    foreach ($rrPatterns as $pattern) {
        $matches = glob($pattern);
        if ($matches === false) {
            $matches = array();
        }

        foreach ($matches as $full) {
            $rel = mrlfsi_rel($root, $full);

            $alreadyListed = false;
            foreach ($rows as $r) {
                if ($r['path'] === $rel) {
                    $alreadyListed = true;
                    break;
                }
            }

            if ($alreadyListed) {
                continue;
            }

            mrlfsi_add(
                $rows,
                'RD/RP DISCOVERY',
                $rel,
                'PRESENT',
                is_dir($full)
                    ? 'Unexpected RD/RP simulation directory discovered.'
                    : 'Unexpected RD/RP pending/marker file discovered.'
            );
        }
    }
}

$protectedProblems = 0;
$tempActive = 0;
$rrTempActive = 0;
$quarantineCount = 0;
$reviewCount = 0;
$rdDiscoveryCount = 0;

foreach ($rows as $r) {
    if ($r['category'] === 'PROTECTED' && $r['status'] !== 'PRESENT') {
        $protectedProblems++;
    } elseif ($r['category'] === 'CLEAN-START TEMP' && $r['status'] === 'PRESENT') {
        $tempActive++;
    } elseif ($r['category'] === 'RACE-RESULTS TEMP' && $r['status'] === 'PRESENT') {
        $rrTempActive++;
    } elseif ($r['category'] === 'QUARANTINE' && $r['status'] === 'PRESENT') {
        $quarantineCount++;
    } elseif ($r['category'] === 'FINAL-SWEEP REVIEW' && $r['status'] === 'PRESENT') {
        $reviewCount++;
    } elseif ($r['category'] === 'RD/RP DISCOVERY' && $r['status'] === 'PRESENT') {
        $rdDiscoveryCount++;
    }
}

$baselinePass = empty($errors)
    && $protectedProblems === 0
    && $tempActive === 0
    && $rrTempActive === 0
    && $rdDiscoveryCount === 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL TESTPHP8 Final Sweep Inventory v002</title>
<style>
body{margin:0;background:#111;color:#eee;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1550px;margin:16px auto;padding:0 14px}
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
.summary{display:flex;gap:12px;flex-wrap:wrap}
.pill{border:1px solid #555;border-radius:999px;padding:7px 11px;background:#222}
.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">
<div class="banner">
<h1>MRL TESTPHP8 Final Sweep Inventory v002</h1>
<div class="sub">READ-ONLY • clean-start final sweep • no files changed</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="bad"><?=mrlfsi_h($e)?></div>
<?php endforeach; ?>

<div class="notice">
<strong>Fresh server-state review.</strong>
This inventory assumes only the successful clean-start quarantine that just completed.
Nothing on this page is moved, deleted, renamed, restored, or modified.
</div>

<div class="card">
<h2>Finalization baseline — <?=$baselinePass ? '<span class="pass">PASS</span>' : '<span class="fail">REVIEW NEEDED</span>'?></h2>
<div class="summary">
<div class="pill">Protected/version problems: <strong><?=$protectedProblems?></strong></div>
<div class="pill">16 temp artifacts active: <strong><?=$tempActive?></strong></div>
<div class="pill">Race-results temp active: <strong><?=$rrTempActive?></strong></div>
<div class="pill">Unexpected RD/RP discoveries: <strong><?=$rdDiscoveryCount?></strong></div>
<div class="pill">Quarantine dirs present: <strong><?=$quarantineCount?></strong></div>
<div class="pill">Final-sweep review items: <strong><?=$reviewCount?></strong></div>
</div>
</div>

<div class="card">
<h2>Current server inventory</h2>
<table>
<thead>
<tr>
<th style="width:205px">Category</th>
<th>Path</th>
<th style="width:145px">Status</th>
<th style="width:505px">Notes</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r): ?>
<?php
$cls = 'warn';
if ($r['category'] === 'PROTECTED') {
    $cls = ($r['status'] === 'PRESENT') ? 'pass' : 'fail';
} elseif ($r['category'] === 'CLEAN-START TEMP' || $r['category'] === 'RACE-RESULTS TEMP') {
    $cls = ($r['status'] === 'ABSENT') ? 'pass' : 'warn';
} elseif ($r['status'] === 'ABSENT') {
    $cls = 'pass';
}
?>
<tr>
<td><?=mrlfsi_h($r['category'])?></td>
<td class="path"><?=mrlfsi_h($r['path'])?></td>
<td class="<?=$cls?>"><?=mrlfsi_h($r['status'])?></td>
<td><?=mrlfsi_h($r['notes'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="card small">
<strong>Next step:</strong> show this result before moving anything.
We will use only the items actually listed under FINAL-SWEEP REVIEW to build the last cleanup manifest.
Quarantine directories themselves remain untouched during that action.
</div>
</div>
</body>
</html>
