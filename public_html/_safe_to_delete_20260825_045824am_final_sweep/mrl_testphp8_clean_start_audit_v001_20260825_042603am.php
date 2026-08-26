<?php
/**
 * MRL TESTPHP8 Clean-Start Audit
 *
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 4:26:03 am
 *
 * PURPOSE:
 * Re-establish the actual current TESTPHP8 server state from scratch after a
 * WinSCP Keep Up to Date session may have removed server-only items.
 *
 * READ-ONLY:
 * This audit does not move, delete, rename, modify, or write any project file.
 *
 * WHAT IT CHECKS:
 * 1) Permanent application files that should exist.
 * 2) Expected current versions/markers for the recent finalized work.
 * 3) Presence/absence of known LP/RP temporary artifacts.
 * 4) Quarantine directories currently present.
 * 5) Installer / inventory / backup debris currently present.
 * 6) RD/RP simulation, pending, and marker artifacts.
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

function mrla_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrla_rel($root, $path) {
    if (strpos($path, $root . '/') === 0) {
        return substr($path, strlen($root) + 1);
    }
    return $path;
}

function mrla_add(&$rows, $category, $path, $status, $notes) {
    $rows[] = array(
        'category' => $category,
        'path' => $path,
        'status' => $status,
        'notes' => $notes
    );
}

function mrla_read_version($path) {
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

function mrla_contains($path, $needle) {
    if (!is_file($path)) {
        return false;
    }

    $data = @file_get_contents($path);
    if ($data === false) {
        return false;
    }

    return strpos($data, $needle) !== false;
}

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: This audit is TESTPHP8-only. Current host: ' . $host;
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable: ' . $root;
}

/*
 * Permanent baseline.
 * Version expectations are reported but do not cause the script to modify anything.
 */
$protected = array(
    'team.php' => array('expected' => 'v034', 'note' => 'Final team page after visual cleanup.'),
    'submit-team-picks.php' => array('expected' => 'v011', 'note' => 'Permanent LP/RP-capable submission path.'),
    'team_replacement_driver.php' => array('expected' => 'v010', 'note' => 'Permanent replacement-driver UI.'),
    'race_results/race_results_rd_helper.php' => array('expected' => 'v005', 'note' => 'Permanent RD/RP helper.'),
    'race_results/race_results_monitor.php' => array('expected' => 'v139', 'note' => 'Permanent race results monitor.'),
    'team_chart.php' => array('expected' => 'v018', 'note' => 'Open-pick privacy guard target.'),
    'submitted_teams_count.php' => array('expected' => 'v001', 'note' => 'Safe submission-status link target.'),
    'race_results/race_schedule_helper.php' => array('expected' => 'v003', 'note' => 'Canonical race schedule helper.'),
    'admin_setup.php' => array('expected' => 'v004', 'note' => 'LP-era admin setup baseline.'),
    'pick_window_helper.php' => array('expected' => 'v003', 'note' => 'Automatic pick-window helper.'),
    'config_mrl.php' => array('expected' => 'v003', 'note' => 'Automatic pick-window config baseline.'),
    'form-team-picks.php' => array('expected' => 'v007', 'note' => 'LP-era pick form baseline.')
);

/*
 * Known LP/RP development artifacts that should no longer be active
 * after finalization. Presence is reported for review.
 */
$tempExact = array(
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
    'mrl_rp_late_submission_server_gate_test_v001_20260823_031600am.php',
    'race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.json',
    'race_results/admin_rd_simulation.php',
    'race_results/_rd_simulation',
    'race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json'
);

if (empty($errors)) {
    foreach ($protected as $rel => $meta) {
        $full = $root . '/' . $rel;
        $exists = file_exists($full);
        $actualVersion = $exists ? mrla_read_version($full) : '';

        $status = $exists ? 'PRESENT' : 'MISSING';
        $note = $meta['note'];

        if ($exists) {
            if ($actualVersion !== '') {
                $note .= ' Version found: ' . $actualVersion . '; expected: ' . $meta['expected'] . '.';
                if ($actualVersion !== $meta['expected']) {
                    $status = 'VERSION MISMATCH';
                }
            } else {
                $note .= ' Version marker not detected; expected: ' . $meta['expected'] . '.';
            }
        }

        mrla_add($rows, 'PROTECTED', $rel, $status, $note);
    }

    /*
     * Additional behavior markers for the two most recent finalized changes.
     */
    $teamPath = $root . '/team.php';
    mrla_add(
        $rows,
        'BEHAVIOR MARKER',
        'team.php',
        mrla_contains($teamPath, 'mrl-previous-years summary::before') ? 'PRESENT' : 'MISSING',
        'Expected Previous Years +/- CSS from v034.'
    );
    mrla_add(
        $rows,
        'BEHAVIOR MARKER',
        'team.php',
        mrla_contains($teamPath, '<summary>Admin Menu</summary>') ? 'PRESENT' : 'MISSING',
        'Expected collapsible Admin Menu from v033.'
    );

    $teamChartPath = $root . '/team_chart.php';
    mrla_add(
        $rows,
        'BEHAVIOR MARKER',
        'team_chart.php',
        mrla_contains($teamChartPath, 'tc_segment_pick_deadline_timestamp') ? 'PRESENT' : 'MISSING',
        'Expected canonical open-pick privacy guard function.'
    );

    $countPath = $root . '/submitted_teams_count.php';
    mrla_add(
        $rows,
        'BEHAVIOR MARKER',
        'submitted_teams_count.php',
        mrla_contains($countPath, 'href="submitted_teams.php"') ? 'PRESENT' : 'MISSING',
        'Expected safe submission-status link.'
    );

    foreach ($tempExact as $rel) {
        $full = $root . '/' . $rel;
        mrla_add(
            $rows,
            'TEMP ARTIFACT',
            $rel,
            file_exists($full) ? 'PRESENT' : 'ABSENT',
            'Known LP/RP test/finalization artifact. Presence means it is active again or was never removed.'
        );
    }

    /*
     * Discover current quarantines.
     */
    $quarantines = glob($root . '/_safe_to_delete_*');
    if ($quarantines === false) {
        $quarantines = array();
    }

    if (count($quarantines) === 0) {
        mrla_add(
            $rows,
            'QUARANTINE',
            '(none)',
            'ABSENT',
            'No _safe_to_delete_* directories are currently present on the server.'
        );
    } else {
        foreach ($quarantines as $full) {
            mrla_add(
                $rows,
                'QUARANTINE',
                mrla_rel($root, $full),
                'PRESENT',
                'Current server-side quarantine directory.'
            );
        }
    }

    /*
     * Discover current installer / inventory / backup debris.
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
            $rel = mrla_rel($root, $full);
            if (isset($seen[$rel])) {
                continue;
            }
            $seen[$rel] = true;

            mrla_add(
                $rows,
                'FINAL-SWEEP REVIEW',
                $rel,
                'PRESENT',
                is_dir($full)
                    ? 'Backup/finalization directory currently present.'
                    : 'Installer/inventory/audit file currently present.'
            );
        }
    }

    /*
     * Extra RD/RP discovery beyond exact known paths.
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
            $rel = mrla_rel($root, $full);

            $already = false;
            foreach ($rows as $r) {
                if ($r['path'] === $rel) {
                    $already = true;
                    break;
                }
            }

            if ($already) {
                continue;
            }

            mrla_add(
                $rows,
                'RD/RP DISCOVERY',
                $rel,
                'PRESENT',
                is_dir($full)
                    ? 'RD/RP simulation directory discovered.'
                    : 'RD/RP pending/marker file discovered.'
            );
        }
    }
}

/*
 * Summary counts.
 */
$protectedProblems = 0;
$tempPresent = 0;
$behaviorMissing = 0;
$quarantineCount = 0;
$reviewCount = 0;
$rdDiscoveryCount = 0;

foreach ($rows as $r) {
    if ($r['category'] === 'PROTECTED' && $r['status'] !== 'PRESENT') {
        $protectedProblems++;
    } elseif ($r['category'] === 'TEMP ARTIFACT' && $r['status'] === 'PRESENT') {
        $tempPresent++;
    } elseif ($r['category'] === 'BEHAVIOR MARKER' && $r['status'] !== 'PRESENT') {
        $behaviorMissing++;
    } elseif ($r['category'] === 'QUARANTINE' && $r['status'] === 'PRESENT') {
        $quarantineCount++;
    } elseif ($r['category'] === 'FINAL-SWEEP REVIEW' && $r['status'] === 'PRESENT') {
        $reviewCount++;
    } elseif ($r['category'] === 'RD/RP DISCOVERY' && $r['status'] === 'PRESENT') {
        $rdDiscoveryCount++;
    }
}

$baselinePass = empty($errors) && $protectedProblems === 0 && $behaviorMissing === 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL TESTPHP8 Clean-Start Audit v001</title>
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
<h1>MRL TESTPHP8 Clean-Start Audit v001</h1>
<div class="sub">READ-ONLY • rebuild assumptions from current server state • no files changed</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="bad"><?=mrla_h($e)?></div>
<?php endforeach; ?>

<div class="notice">
<strong>Clean start.</strong>
This audit assumes nothing about what WinSCP did or did not preserve.
It reads the current server state only. Nothing is moved, deleted, restored, or modified.
</div>

<div class="card">
<h2>Permanent baseline — <?=$baselinePass ? '<span class="pass">PASS</span>' : '<span class="fail">REVIEW NEEDED</span>'?></h2>
<div class="summary">
<div class="pill">Protected/version problems: <strong><?=$protectedProblems?></strong></div>
<div class="pill">Behavior markers missing: <strong><?=$behaviorMissing?></strong></div>
<div class="pill">Known temp artifacts active: <strong><?=$tempPresent?></strong></div>
<div class="pill">Quarantine dirs present: <strong><?=$quarantineCount?></strong></div>
<div class="pill">Final-sweep review items: <strong><?=$reviewCount?></strong></div>
<div class="pill">Extra RD/RP discoveries: <strong><?=$rdDiscoveryCount?></strong></div>
</div>
</div>

<div class="card">
<h2>Current server inventory</h2>
<table>
<thead>
<tr>
<th style="width:200px">Category</th>
<th>Path</th>
<th style="width:140px">Status</th>
<th style="width:520px">Notes</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r): ?>
<?php
$cls = 'warn';
if ($r['category'] === 'PROTECTED' || $r['category'] === 'BEHAVIOR MARKER') {
    $cls = ($r['status'] === 'PRESENT') ? 'pass' : 'fail';
} elseif ($r['category'] === 'TEMP ARTIFACT') {
    $cls = ($r['status'] === 'ABSENT') ? 'pass' : 'warn';
} elseif ($r['status'] === 'ABSENT') {
    $cls = 'pass';
}
?>
<tr>
<td><?=mrla_h($r['category'])?></td>
<td class="path"><?=mrla_h($r['path'])?></td>
<td class="<?=$cls?>"><?=mrla_h($r['status'])?></td>
<td><?=mrla_h($r['notes'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="card small">
<strong>Next step:</strong> show this audit result before restoring, quarantining, or deleting anything.
We will use this page as the new source of truth for the remainder of TESTPHP8 finalization.
</div>
</div>
</body>
</html>
