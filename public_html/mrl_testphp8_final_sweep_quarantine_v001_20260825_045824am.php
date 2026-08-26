<?php
/**
 * MRL TESTPHP8 Final Sweep Quarantine
 *
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 4:58:24 am
 *
 * PURPOSE:
 * Quarantine the exact 20 FINAL-SWEEP REVIEW items reported by
 * Final Sweep Inventory v002.
 *
 * SAFETY:
 * - TESTPHP8 only.
 * - Exact 20-item manifest.
 * - Moves only; deletes nothing.
 * - Preserves both existing _safe_to_delete_* quarantine directories.
 * - Preserves all permanent application files.
 * - Does not touch scheduler configuration.
 * - Rollback available.
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
$root = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';

$quarantineName = '_safe_to_delete_20260825_045824am_final_sweep';
$quarantineRoot = $root . '/' . $quarantineName;

$manifest = array(
    "mrl_lp_rp_finalization_installer_v001_20260824_021033pm.php",
    "mrl_open_pick_privacy_guard_installer_v001_20260824_094504pm.php",
    "mrl_open_pick_privacy_guard_installer_v002_20260824_095443pm.php",
    "mrl_open_pick_privacy_guard_installer_v003_20260824_095836pm.php",
    "mrl_team_page_section_panels_v033_installer_v001_20260824_114720pm.php",
    "mrl_team_previous_years_border_v032_installer_v001_20260824_041000pm.php",
    "mrl_team_previous_years_panel_v031_installer_v001_20260824_040100pm.php",
    "mrl_team_previous_years_toggle_v034_installer_v001_20260825_122616am.php",
    "mrl_team_section_alignment_v030_installer_v001_20260824_033251pm.php",
    "mrl_team_visual_v028_installer_v001_20260824_024257pm.php",
    "mrl_team_visual_v028_installer_v002_20260824_024856pm.php",
    "mrl_team_visual_v029_installer_v001_20260824_032200pm.php",
    "mrl_testphp8_cleanup_quarantine_installer_v001_20260824_012300pm.php",
    "mrl_testphp8_lp_rp_final_quarantine_installer_v001_20260825_035502am.php",
    "mrl_testphp8_cleanup_inventory_v005_20260824_124132000.php",
    "mrl_testphp8_final_cleanup_inventory_v006_20260825_033732am.php",
    "mrl_testphp8_final_sweep_inventory_v001_20260825_040720am.php",
    "mrl_testphp8_final_sweep_inventory_v002_20260825_044941am.php",
    "mrl_testphp8_clean_start_audit_v001_20260825_042603am.php",
    "mrl_team_previous_years_toggle_v034_backup_20260825_122616am",
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
    'form-team-picks.php',
    'race_results/README_INSTALL_AND_UPDATE.md'
);

$existingQuarantines = array(
    '_safe_to_delete_20260824_133231',
    '_safe_to_delete_20260825_044147am_clean_start_temp'
);

$errors = array();
$messages = array();
$checks = array();

function mrlfsq_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlfsq_check(&$checks, $label, $ok, $detail) {
    $checks[] = array(
        'label' => (string)$label,
        'ok' => (bool)$ok,
        'detail' => (string)$detail
    );
    return (bool)$ok;
}

function mrlfsq_parent_mkdir($path) {
    $parent = dirname($path);
    if (is_dir($parent)) {
        return true;
    }
    return mkdir($parent, 0755, true);
}

function mrlfsq_move($src, $dst) {
    if (!mrlfsq_parent_mkdir($dst)) {
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
    $missingProtected = array();
    foreach ($protected as $rel) {
        if (!file_exists($root . '/' . $rel)) {
            $missingProtected[] = $rel;
        }
    }

    $preflightOk = mrlfsq_check(
        $checks,
        'Protected application files',
        count($missingProtected) === 0,
        count($missingProtected) === 0
            ? 'All 13 protected/reference files are present'
            : ('Missing: ' . implode(', ', $missingProtected))
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

    $preflightOk = mrlfsq_check(
        $checks,
        '20-item final-sweep manifest',
        $present === 20 && count($missing) === 0,
        $present . ' / 20 currently present'
    ) && $preflightOk;

    $preflightOk = mrlfsq_check(
        $checks,
        'New quarantine destination',
        !file_exists($quarantineRoot),
        !file_exists($quarantineRoot)
            ? 'Destination is unused'
            : 'Destination already exists'
    ) && $preflightOk;

    $qPresent = 0;
    foreach ($existingQuarantines as $rel) {
        if (is_dir($root . '/' . $rel)) {
            $qPresent++;
        }
    }

    $preflightOk = mrlfsq_check(
        $checks,
        'Existing quarantines protected',
        $qPresent === 2,
        $qPresent . ' / 2 existing quarantine directories present; neither is in this manifest'
    ) && $preflightOk;

    $tempStillActive = 0;
    $tempPatterns = array(
        $root . '/mrl_lp_rp_*',
        $root . '/mrl_rd_be_like_biff_fixture_manager_*',
        $root . '/mrl_rp_deadline_test_switch_*',
        $root . '/mrl_rp_late_submission_server_gate_test_*'
    );

    foreach ($tempPatterns as $pattern) {
        $matches = glob($pattern);
        if ($matches !== false) {
            foreach ($matches as $full) {
                $base = basename($full);
                if (!in_array($base, $manifest, true)) {
                    $tempStillActive++;
                }
            }
        }
    }

    $preflightOk = mrlfsq_check(
        $checks,
        'No active LP/RP temp leftovers',
        $tempStillActive === 0,
        $tempStillActive . ' unexpected top-level LP/RP temp files found'
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
                if (!mrlfsq_move($src, $dst)) {
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
                        mrlfsq_move($src, $dst);
                    }
                }

                $errors[] = 'QUARANTINE FAILED: ' . $failed . ' Automatic rollback attempted.';
            } else {
                $activeAbsent = 0;
                $quarantinePresent = 0;

                foreach ($manifest as $rel) {
                    if (!file_exists($root . '/' . $rel)) {
                        $activeAbsent++;
                    }
                    if (file_exists($quarantineRoot . '/' . $rel)) {
                        $quarantinePresent++;
                    }
                }

                $protectedAfter = 0;
                foreach ($protected as $rel) {
                    if (file_exists($root . '/' . $rel)) {
                        $protectedAfter++;
                    }
                }

                $existingAfter = 0;
                foreach ($existingQuarantines as $rel) {
                    if (is_dir($root . '/' . $rel)) {
                        $existingAfter++;
                    }
                }

                $postOk = true;

                $postOk = mrlfsq_check(
                    $checks,
                    'POST active review items cleared',
                    $activeAbsent === 20,
                    $activeAbsent . ' / 20 absent from active TESTPHP8 root'
                ) && $postOk;

                $postOk = mrlfsq_check(
                    $checks,
                    'POST new quarantine complete',
                    $quarantinePresent === 20,
                    $quarantinePresent . ' / 20 present in final-sweep quarantine'
                ) && $postOk;

                $postOk = mrlfsq_check(
                    $checks,
                    'POST protected files preserved',
                    $protectedAfter === 13,
                    $protectedAfter . ' / 13 protected/reference files remain present'
                ) && $postOk;

                $postOk = mrlfsq_check(
                    $checks,
                    'POST prior quarantines preserved',
                    $existingAfter === 2,
                    $existingAfter . ' / 2 prior quarantines remain present'
                ) && $postOk;

                if ($postOk) {
                    $messages[] = 'PASS: 20 final-sweep review items quarantined. Nothing deleted.';
                } else {
                    $errors[] = 'POSTFLIGHT FAILED. Use rollback before continuing.';
                }
            }
        }
    }
}

if ($action === 'rollback') {
    if (!is_dir($quarantineRoot)) {
        $errors[] = 'ROLLBACK REFUSED: final-sweep quarantine directory is missing.';
    } else {
        $collisions = array();

        foreach ($manifest as $rel) {
            if (file_exists($root . '/' . $rel) && file_exists($quarantineRoot . '/' . $rel)) {
                $collisions[] = $rel;
            }
        }

        if (count($collisions) > 0) {
            $errors[] = 'ROLLBACK REFUSED: active-path collision(s): ' . implode(', ', $collisions);
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

                if (mrlfsq_move($src, $dst)) {
                    $restored++;
                } else {
                    $failed[] = 'Restore failed: ' . $rel;
                }
            }

            if ($restored === 20 && count($failed) === 0) {
                $messages[] = 'ROLLBACK PASS: all 20 final-sweep items restored.';
            } else {
                $errors[] = 'ROLLBACK INCOMPLETE: restored ' . $restored . ' / 20. ' . implode(' | ', $failed);
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL TESTPHP8 Final Sweep Quarantine v001</title>
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
<h1>MRL TESTPHP8 Final Sweep Quarantine v001</h1>
<div class="sub">TESTPHP8 • exact 20-item manifest • quarantine only • no deletion</div>
</div>

<?php foreach ($messages as $m): ?>
<div class="msg-pass"><?=mrlfsq_h($m)?></div>
<?php endforeach; ?>

<?php foreach ($errors as $e): ?>
<div class="msg-fail"><?=mrlfsq_h($e)?></div>
<?php endforeach; ?>

<div class="notice">
<strong>Final sweep:</strong>
moves only the exact 20 FINAL-SWEEP REVIEW items reported by Inventory v002.
Permanent application files, README documentation, scheduler files, and both existing quarantine directories are left alone.
</div>

<div class="card">
<h2>Preflight — <?=$preflightOk ? '<span class="pass">PASS</span>' : '<span class="fail">FAIL</span>'?></h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:280px"><?=mrlfsq_h($c['label'])?></td>
<td style="width:70px" class="<?=$c['ok']?'pass':'fail'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
<td><?=mrlfsq_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>20-item final-sweep manifest</h2>
<table>
<?php foreach ($manifest as $i => $rel): ?>
<tr>
<td style="width:50px"><?=($i + 1)?></td>
<td class="path"><?=mrlfsq_h($rel)?></td>
<td style="width:110px"><?=file_exists($root.'/'.$rel)?'ACTIVE':(file_exists($quarantineRoot.'/'.$rel)?'QUARANTINED':'MISSING')?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card small">
New quarantine: <span class="path"><?=mrlfsq_h($quarantineName)?></span><br>
Existing quarantines left untouched:
<span class="path">_safe_to_delete_20260824_133231</span> and
<span class="path">_safe_to_delete_20260825_044147am_clean_start_temp</span>.
</div>

<?php if ($preflightOk && $action !== 'quarantine'): ?>
<div class="card">
<form method="post" onsubmit="return confirm('Move exactly these 20 final-sweep review items into the new quarantine? Nothing will be deleted.');">
<input type="hidden" name="action" value="quarantine">
<button type="submit">Quarantine 20 Final-Sweep Review Items</button>
</form>
</div>
<?php endif; ?>

<?php if (is_dir($quarantineRoot)): ?>
<div class="card">
<form method="post" onsubmit="return confirm('Restore all 20 final-sweep items to their original paths?');">
<input type="hidden" name="action" value="rollback">
<button class="rollback" type="submit">Rollback 20 Final-Sweep Items</button>
</form>
</div>
<?php endif; ?>

<div class="card small">
After PASS, stop and show the postflight result.
Then we will do one final application/scheduler readiness check before turning the scheduler back on.
</div>

</div>
</body>
</html>
