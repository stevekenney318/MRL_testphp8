<?php
/**
 * MRL TESTPHP8 Final Cleanup Inventory
 *
 * VERSION: v006
 * LAST MODIFIED: 8/25/2026 3:37:32 am
 *
 * PURPOSE:
 * Read-only inventory for the final TESTPHP8 cleanup/finalization pass.
 *
 * This file DOES NOT move, delete, rename, modify, or write any project file.
 * It only reports what is currently present so a separate quarantine installer
 * can be built from the actual server state.
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

function mrlci_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlci_add(&$rows, $category, $path, $status, $notes) {
    $rows[] = array(
        'category' => $category,
        'path' => $path,
        'status' => $status,
        'notes' => $notes
    );
}

function mrlci_rel($root, $path) {
    if (strpos($path, $root . '/') === 0) {
        return substr($path, strlen($root) + 1);
    }
    return $path;
}

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: This read-only inventory is TESTPHP8-only. Current host: ' . $host;
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable: ' . $root;
}

/*
 * Known permanent files that must remain.
 * These are included in the inventory as protected reference points.
 */
$protectedFiles = array(
    'team.php' => 'Expected final TESTPHP8 team page: v034.',
    'submit-team-picks.php' => 'Permanent RP-capable submission path.',
    'team_replacement_driver.php' => 'Permanent replacement-driver UI.',
    'race_results/race_results_rd_helper.php' => 'Permanent RD/RP detection helper.',
    'race_results/race_results_monitor.php' => 'Permanent race results monitor.',
    'team_chart.php' => 'Permanent open-pick privacy guard target; expected v018.',
    'submitted_teams_count.php' => 'Permanent safe submission-status link target.'
);

/*
 * Exact temporary LP/RP development artifacts previously held until finalization.
 * These are candidates for the NEXT quarantine step if still present.
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
    'race_results/admin_rd_simulation.php'
);

/*
 * Deferred / keep-for-now references.
 */
$deferredExact = array(
    'mrl_testphp8_cleanup_inventory_v005_20260824_124132000.php',
    'race_results/README_INSTALL_AND_UPDATE.md'
);

if (empty($errors)) {
    foreach ($protectedFiles as $rel => $note) {
        $full = $root . '/' . $rel;
        mrlci_add(
            $rows,
            'PROTECTED',
            $rel,
            file_exists($full) ? 'PRESENT' : 'MISSING',
            $note
        );
    }

    foreach ($tempExact as $rel) {
        $full = $root . '/' . $rel;
        mrlci_add(
            $rows,
            'TEMP / QUARANTINE CANDIDATE',
            $rel,
            file_exists($full) ? 'PRESENT' : 'ABSENT',
            'Previously retained only through LP→RP finalization.'
        );
    }

    foreach ($deferredExact as $rel) {
        $full = $root . '/' . $rel;
        mrlci_add(
            $rows,
            'DEFERRED',
            $rel,
            file_exists($full) ? 'PRESENT' : 'ABSENT',
            'Do not move in the first cleanup action.'
        );
    }

    /*
     * Pattern-based discovery for remaining finalization clutter.
     * Read-only. Nothing matched here is automatically safe to remove.
     */
    $patterns = array(
        'mrl_lp_rp_*',
        'mrl_rp_*',
        'mrl_rd_*',
        'mrl_open_pick_privacy_guard_installer_*',
        'mrl_team_page_section_panels_*',
        'mrl_team_previous_years_toggle_*',
        'mrl_*backup_*',
        'mrl_*_backup_*',
        '_safe_to_delete_*'
    );

    $seen = array();
    foreach ($rows as $r) {
        $seen[$r['path']] = true;
    }

    foreach ($patterns as $pattern) {
        $matches = glob($root . '/' . $pattern);
        if ($matches === false) {
            $matches = array();
        }
        foreach ($matches as $full) {
            $rel = mrlci_rel($root, $full);
            if (isset($seen[$rel])) {
                continue;
            }
            $seen[$rel] = true;

            $note = is_dir($full)
                ? 'Pattern match directory. Review before any quarantine/purge action.'
                : 'Pattern match file. Review before any quarantine action.';

            mrlci_add(
                $rows,
                'DISCOVERED / REVIEW',
                $rel,
                'PRESENT',
                $note
            );
        }
    }

    /*
     * Race-results simulation/pending discovery.
     */
    $rrPatterns = array(
        $root . '/race_results/_rd_simulation',
        $root . '/race_results/*marker*.json',
        $root . '/race_results/_*marker*.json',
        $root . '/race_results/2026/*/_rd_pending_*.json'
    );

    foreach ($rrPatterns as $pattern) {
        $matches = glob($pattern);
        if ($matches === false) {
            $matches = array();
        }
        foreach ($matches as $full) {
            $rel = mrlci_rel($root, $full);
            if (isset($seen[$rel])) {
                continue;
            }
            $seen[$rel] = true;
            mrlci_add(
                $rows,
                'DISCOVERED / REVIEW',
                $rel,
                'PRESENT',
                is_dir($full)
                    ? 'RD simulation directory discovered.'
                    : 'RD marker/pending JSON discovered.'
            );
        }
    }
}

/*
 * Counts.
 */
$countProtectedMissing = 0;
$countTempPresent = 0;
$countDiscovered = 0;
$countDeferredPresent = 0;

foreach ($rows as $r) {
    if ($r['category'] === 'PROTECTED' && $r['status'] === 'MISSING') {
        $countProtectedMissing++;
    } elseif ($r['category'] === 'TEMP / QUARANTINE CANDIDATE' && $r['status'] === 'PRESENT') {
        $countTempPresent++;
    } elseif ($r['category'] === 'DISCOVERED / REVIEW' && $r['status'] === 'PRESENT') {
        $countDiscovered++;
    } elseif ($r['category'] === 'DEFERRED' && $r['status'] === 'PRESENT') {
        $countDeferredPresent++;
    }
}

$overallPass = empty($errors) && $countProtectedMissing === 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL TESTPHP8 Final Cleanup Inventory v006</title>
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
        <h1>MRL TESTPHP8 Final Cleanup Inventory v006</h1>
        <div class="sub">READ-ONLY • finalization inventory • no files changed</div>
    </div>

    <?php foreach ($errors as $e): ?>
        <div class="bad"><?=mrlci_h($e)?></div>
    <?php endforeach; ?>

    <div class="notice">
        <strong>Inventory only.</strong>
        This page does not move, delete, rename, write, or modify anything.
        Its purpose is to show the exact current TESTPHP8 state before we build the final quarantine action.
    </div>

    <div class="card">
        <h2>Protected baseline — <?=$overallPass ? '<span class="pass">PASS</span>' : '<span class="fail">FAIL</span>'?></h2>
        <div class="summary">
            <div class="pill">Protected missing: <strong><?=$countProtectedMissing?></strong></div>
            <div class="pill">Known temp artifacts still present: <strong><?=$countTempPresent?></strong></div>
            <div class="pill">Additional discovered items: <strong><?=$countDiscovered?></strong></div>
            <div class="pill">Deferred items present: <strong><?=$countDeferredPresent?></strong></div>
        </div>
    </div>

    <div class="card">
        <h2>Inventory</h2>
        <table>
            <thead>
                <tr>
                    <th style="width:230px">Category</th>
                    <th>Path</th>
                    <th style="width:100px">Status</th>
                    <th style="width:420px">Notes</th>
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
                    <td><?=mrlci_h($r['category'])?></td>
                    <td class="path"><?=mrlci_h($r['path'])?></td>
                    <td class="<?=$cls?>"><?=mrlci_h($r['status'])?></td>
                    <td><?=mrlci_h($r['notes'])?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card small">
        <strong>Next step:</strong> show this inventory result before moving anything.
        We will build a separate TESTPHP8-only quarantine installer from the paths that are actually present.
        The existing <span class="path">_safe_to_delete_20260824_133231</span> quarantine is review-only and must not be purged during this pass.
    </div>
</div>
</body>
</html>
