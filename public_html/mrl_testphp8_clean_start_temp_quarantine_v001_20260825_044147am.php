<?php
/**
 * MRL TESTPHP8 Clean-Start Temp Quarantine
 *
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 4:41:47 am
 *
 * PURPOSE:
 * Re-quarantine ONLY the 16 top-level LP/RP temporary artifacts that the
 * Clean-Start Audit v001 confirmed are currently active again after WinSCP
 * Keep Up to Date partially reversed cleanup.
 *
 * IMPORTANT:
 * - TESTPHP8 only.
 * - Moves files into a new quarantine; deletes nothing.
 * - Exact 16-item manifest only.
 * - Does NOT touch any permanent application file.
 * - Does NOT touch current race_results fixtures/simulations because the clean
 *   audit showed those are already absent.
 * - Does NOT touch installers/backups/inventories yet.
 * - Does NOT touch the scheduler.
 * - Rollback is available.
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
$root = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';

$quarantineName = '_safe_to_delete_20260825_044147am_clean_start_temp';
$quarantineRoot = $root . '/' . $quarantineName;

$manifest = array(
    "mrl_lp_rp_base_row_bridge_fix_v001_20260823_121700pm.php",
    "mrl_lp_rp_database_verification_v001_20260823_043700pm.php",
    "mrl_lp_rp_edge_case_harness_v001_20260823_114100am.php",
    "mrl_lp_rp_edge_case_harness_v002_20260823_115500am.php",
    "mrl_lp_rp_edge_case_harness_v003_20260823_120200pm.php",
    "mrl_lp_rp_real_submit_error_capture_v001_20260823_130200pm.php",
    "mrl_lp_rp_real_submit_error_capture_v002_20260823_131000pm.php",
    "mrl_lp_rp_submission_diagnostic_v001_20260823_123500pm.php",
    "mrl_lp_rp_submission_diagnostic_v002_20260823_125800pm.php",
    "mrl_lp_rp_submit_bridge_test_fix_v001_20260823_123900pm.php",
    "mrl_lp_rp_submit_rescue_bridge_v001_20260823_135200pm.php",
    "mrl_lp_rp_submit_structural_repair_v001_20260823_020300pm.php",
    "mrl_lp_rp_submit_structure_diagnostic_v003_20260823_012300pm.php",
    "mrl_rd_be_like_biff_fixture_manager_v001_20260822_074600pm.php",
    "mrl_rp_deadline_test_switch_v001_20260823_025800am.php",
    "mrl_rp_late_submission_server_gate_test_v001_20260823_031600am.php",
);

$protected = array(
    'team.php',
    'submit-team-picks.php',
    'team_replacement_driver.php',
    'race_results/race_results_rd_helper.php',
    'race_results/race_results_monitor.php',
    'team_chart.php',
    'submitted_teams_count.php',
    'race_results/race_schedule_helper.php',
    'admin_setup.php',
    'pick_window_helper.php',
    'config_mrl.php',
    'form-team-picks.php'
);

$errors = array();
$messages = array();
$checks = array();

function mrlcs_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlcs_check(&$checks, $label, $ok, $detail) {
    $checks[] = array(
        'label' => (string)$label,
        'ok' => (bool)$ok,
        'detail' => (string)$detail
    );
    return (bool)$ok;
}

function mrlcs_parent_mkdir($path) {
    $parent = dirname($path);
    if (is_dir($parent)) {
        return true;
    }
    return mkdir($parent, 0755, true);
}

function mrlcs_move($src, $dst) {
    if (!mrlcs_parent_mkdir($dst)) {
        return false;
    }
    return @rename($src, $dst);
}

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: TESTPHP8-only. Current host: ' . $host;
}
if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable.';
}

$preflightOk = empty($errors);

if ($preflightOk) {
    $protectedMissing = array();
    foreach ($protected as $rel) {
        if (!file_exists($root . '/' . $rel)) {
            $protectedMissing[] = $rel;
        }
    }

    $preflightOk = mrlcs_check(
        $checks,
        'Protected phase files',
        count($protectedMissing) === 0,
        count($protectedMissing) === 0
            ? 'All 12 phase-protected files are present'
            : ('Missing: ' . implode(', ', $protectedMissing))
    ) && $preflightOk;

    $present = 0;
    $missing = array();
    foreach ($manifest as $rel) {
        if (file_exists($root . '/' . $rel)) {
            $present++;
        } else {
            $missing[] = $rel;
        }
    }

    $preflightOk = mrlcs_check(
        $checks,
        '16-item clean-start manifest',
        $present === 16 && count($missing) === 0,
        $present . ' / 16 currently active'
    ) && $preflightOk;

    $preflightOk = mrlcs_check(
        $checks,
        'Quarantine destination clear',
        !file_exists($quarantineRoot),
        !file_exists($quarantineRoot)
            ? 'New quarantine path is unused'
            : 'Destination already exists'
    ) && $preflightOk;

    $alreadyAbsent = array(
        'race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.json',
        'race_results/admin_rd_simulation.php',
        'race_results/_rd_simulation',
        'race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json'
    );

    $absentCount = 0;
    foreach ($alreadyAbsent as $rel) {
        if (!file_exists($root . '/' . $rel)) {
            $absentCount++;
        }
    }

    $preflightOk = mrlcs_check(
        $checks,
        'Race-results fixture artifacts',
        $absentCount === 4,
        $absentCount . ' / 4 remain absent; this installer will not touch them'
    ) && $preflightOk;
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

if ($action === 'quarantine' && empty($errors)) {
    if (!$preflightOk) {
        $errors[] = 'QUARANTINE REFUSED: preflight did not pass.';
    } else {
        $moved = array();
        $failed = null;

        if (!mkdir($quarantineRoot, 0755, true)) {
            $errors[] = 'QUARANTINE FAILED: could not create quarantine directory.';
        } else {
            foreach ($manifest as $rel) {
                $src = $root . '/' . $rel;
                $dst = $quarantineRoot . '/' . $rel;

                if (!file_exists($src)) {
                    $failed = 'Source disappeared before move: ' . $rel;
                    break;
                }
                if (file_exists($dst)) {
                    $failed = 'Destination collision: ' . $rel;
                    break;
                }
                if (!mrlcs_move($src, $dst)) {
                    $failed = 'Move failed: ' . $rel;
                    break;
                }
                $moved[] = $rel;
            }

            if ($failed !== null) {
                for ($i = count($moved) - 1; $i >= 0; $i--) {
                    $rel = $moved[$i];
                    $src = $quarantineRoot . '/' . $rel;
                    $dst = $root . '/' . $rel;

                    if (file_exists($src) && !file_exists($dst)) {
                        mrlcs_move($src, $dst);
                    }
                }
                $errors[] = 'QUARANTINE FAILED: ' . $failed . ' Automatic rollback attempted.';
            } else {
                $sourceAbsent = 0;
                $quarantinePresent = 0;

                foreach ($manifest as $rel) {
                    if (!file_exists($root . '/' . $rel)) {
                        $sourceAbsent++;
                    }
                    if (file_exists($quarantineRoot . '/' . $rel)) {
                        $quarantinePresent++;
                    }
                }

                $postOk = true;

                $postOk = mrlcs_check(
                    $checks,
                    'POST active paths cleared',
                    $sourceAbsent === 16,
                    $sourceAbsent . ' / 16 absent from active TESTPHP8 paths'
                ) && $postOk;

                $postOk = mrlcs_check(
                    $checks,
                    'POST quarantine complete',
                    $quarantinePresent === 16,
                    $quarantinePresent . ' / 16 present in new quarantine'
                ) && $postOk;

                $protectedAfter = 0;
                foreach ($protected as $rel) {
                    if (file_exists($root . '/' . $rel)) {
                        $protectedAfter++;
                    }
                }

                $postOk = mrlcs_check(
                    $checks,
                    'POST protected files preserved',
                    $protectedAfter === 12,
                    $protectedAfter . ' / 12 phase-protected files remain present'
                ) && $postOk;

                if ($postOk) {
                    $messages[] = 'PASS: 16 clean-start LP/RP temp artifacts quarantined. Nothing deleted.';
                } else {
                    $errors[] = 'POSTFLIGHT FAILED. Use rollback before continuing.';
                }
            }
        }
    }
}

if ($action === 'rollback') {
    if (!is_dir($quarantineRoot)) {
        $errors[] = 'ROLLBACK REFUSED: quarantine directory is missing.';
    } else {
        $collisions = array();
        foreach ($manifest as $rel) {
            if (file_exists($root . '/' . $rel) && file_exists($quarantineRoot . '/' . $rel)) {
                $collisions[] = $rel;
            }
        }

        if (count($collisions) > 0) {
            $errors[] = 'ROLLBACK REFUSED: active-path collisions: ' . implode(', ', $collisions);
        } else {
            $restored = 0;
            $failed = array();

            foreach ($manifest as $rel) {
                $src = $quarantineRoot . '/' . $rel;
                $dst = $root . '/' . $rel;

                if (!file_exists($src)) {
                    $failed[] = 'Missing from quarantine: ' . $rel;
                    continue;
                }

                if (mrlcs_move($src, $dst)) {
                    $restored++;
                } else {
                    $failed[] = 'Restore failed: ' . $rel;
                }
            }

            if ($restored === 16 && count($failed) === 0) {
                $messages[] = 'ROLLBACK PASS: all 16 artifacts restored.';
            } else {
                $errors[] = 'ROLLBACK INCOMPLETE: restored ' . $restored . ' / 16. ' . implode(' | ', $failed);
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL TESTPHP8 Clean-Start Temp Quarantine v001</title>
<style>
body{margin:0;background:#111;color:#eee;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1450px;margin:16px auto;padding:0 14px}
.banner{border:1px solid #396d36;background:#173717;border-radius:12px;padding:14px 18px}
.banner h1{margin:0 0 4px;color:#e9ffd9;font-size:28px}
.sub{color:#d9efc9}
.notice{margin-top:12px;border:1px solid #b87500;background:#4b2d00;border-radius:9px;padding:11px 14px;color:#ffd985}
.card{margin-top:14px;border:1px solid #444;background:#1b1b1b;border-radius:11px;padding:14px 18px}
.msg-pass{margin-top:12px;border:1px solid #34794c;background:#103d24;border-radius:8px;padding:11px 14px;color:#aef2bf}
.msg-fail{margin-top:12px;border:1px solid #ad3e3e;background:#511818;border-radius:8px;padding:11px 14px;color:#ffb1b1}
h2{margin:2px 0 12px;font-size:24px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:7px 9px;border-bottom:1px solid #353535;text-align:left;vertical-align:top}
.pass{color:#78ef9c;font-weight:bold}
.fail{color:#ff9292;font-weight:bold}
.path{font-family:Consolas,monospace;color:#cddcff;word-break:break-all}
button{padding:9px 14px;border-radius:6px;border:1px solid #5e8ab0;background:#2f713e;color:#fff;font-weight:bold;cursor:pointer}
.rollback{background:#7a3636;border-color:#a65353}
.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">
<div class="banner">
<h1>MRL TESTPHP8 Clean-Start Temp Quarantine v001</h1>
<div class="sub">TESTPHP8 • exact 16-item manifest • quarantine only • no deletion</div>
</div>

<?php foreach ($messages as $m): ?>
<div class="msg-pass"><?=mrlcs_h($m)?></div>
<?php endforeach; ?>

<?php foreach ($errors as $e): ?>
<div class="msg-fail"><?=mrlcs_h($e)?></div>
<?php endforeach; ?>

<div class="notice">
<strong>Clean restart:</strong>
this action is based only on the current Clean-Start Audit result.
It re-quarantines the 16 LP/RP top-level temp artifacts that are active again.
It does not rely on the earlier quarantine state.
</div>

<div class="card">
<h2>Preflight — <?=$preflightOk ? '<span class="pass">PASS</span>' : '<span class="fail">FAIL</span>'?></h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:280px"><?=mrlcs_h($c['label'])?></td>
<td style="width:70px" class="<?=$c['ok']?'pass':'fail'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
<td><?=mrlcs_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>16-item manifest</h2>
<table>
<?php foreach ($manifest as $i => $rel): ?>
<tr>
<td style="width:50px"><?=($i+1)?></td>
<td class="path"><?=mrlcs_h($rel)?></td>
<td style="width:110px"><?=file_exists($root.'/'.$rel)?'ACTIVE':(file_exists($quarantineRoot.'/'.$rel)?'QUARANTINED':'MISSING')?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card small">
New quarantine: <span class="path"><?=mrlcs_h($quarantineName)?></span>
</div>

<?php if ($preflightOk && $action !== 'quarantine'): ?>
<div class="card">
<form method="post" onsubmit="return confirm('Move exactly these 16 active LP/RP temp artifacts into the new quarantine? Nothing will be deleted.');">
<input type="hidden" name="action" value="quarantine">
<button type="submit">Quarantine 16 Clean-Start Temp Artifacts</button>
</form>
</div>
<?php endif; ?>

<?php if (is_dir($quarantineRoot)): ?>
<div class="card">
<form method="post" onsubmit="return confirm('Restore all 16 artifacts to their original paths?');">
<input type="hidden" name="action" value="rollback">
<button class="rollback" type="submit">Rollback 16 Artifacts</button>
</form>
</div>
<?php endif; ?>

<div class="card small">
After PASS, stop and show the postflight result.
Then we will perform a fresh final-sweep inventory of installers/backups/inventories before touching the scheduler.
</div>
</div>
</body>
</html>
