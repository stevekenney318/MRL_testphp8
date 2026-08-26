<?php
/**
 * MRL TESTPHP8 LP/RP Final Artifact Quarantine Installer
 *
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 3:55:02 am
 *
 * PURPOSE:
 * Quarantine the 20 remaining LP/RP development / fixture / simulation artifacts
 * confirmed PRESENT by Final Cleanup Inventory v006.
 *
 * IMPORTANT:
 * - TESTPHP8 only.
 * - MOVES files/directories into a new quarantine tree; does NOT delete them.
 * - Preserves relative paths inside quarantine.
 * - Does NOT touch the existing _safe_to_delete_20260824_133231 quarantine.
 * - Does NOT touch finalization/privacy/team visual installers in this pass.
 * - Does NOT touch database or scheduler configuration.
 * - Rollback is available and collision-safe.
 *
 * PHP:
 * Compatible with PHP 7.3.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
$root = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';

$quarantineName = '_safe_to_delete_20260825_035502am_lp_rp_finalization';
$quarantineRoot = $root . '/' . $quarantineName;
$existingQuarantine = $root . '/_safe_to_delete_20260824_133231';

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
    "race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.json",
    "race_results/admin_rd_simulation.php",
    "race_results/_rd_simulation",
    "race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json",
);

$protected = array(
    'team.php',
    'submit-team-picks.php',
    'team_replacement_driver.php',
    'race_results/race_results_rd_helper.php',
    'race_results/race_results_monitor.php',
    'team_chart.php',
    'submitted_teams_count.php'
);

$deferredUntouched = array(
    'mrl_testphp8_cleanup_inventory_v005_20260824_124132000.php',
    'race_results/README_INSTALL_AND_UPDATE.md',
    'mrl_lp_rp_finalization_installer_v001_20260824_021033pm.php',
    'mrl_open_pick_privacy_guard_installer_v001_20260824_094504pm.php',
    'mrl_open_pick_privacy_guard_installer_v002_20260824_095443pm.php',
    'mrl_open_pick_privacy_guard_installer_v003_20260824_095836pm.php',
    'mrl_team_page_section_panels_v033_installer_v001_20260824_114720pm.php',
    'mrl_team_previous_years_toggle_v034_installer_v001_20260825_122616am.php',
    'mrl_team_previous_years_toggle_v034_backup_20260825_122616am'
);

$errors = array();
$messages = array();
$checks = array();

function mrlq_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlq_check(&$checks, $label, $ok, $detail) {
    $checks[] = array(
        'label' => (string)$label,
        'ok' => (bool)$ok,
        'detail' => (string)$detail
    );
    return (bool)$ok;
}

function mrlq_parent_mkdir($path) {
    $parent = dirname($path);
    if (is_dir($parent)) {
        return true;
    }
    return mkdir($parent, 0755, true);
}

function mrlq_move($src, $dst) {
    if (!mrlq_parent_mkdir($dst)) {
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
    $preflightOk = mrlq_check(
        $checks,
        'Existing quarantine protected',
        is_dir($existingQuarantine),
        '_safe_to_delete_20260824_133231 is present and is NOT in this manifest'
    ) && $preflightOk;

    $missingProtected = array();
    foreach ($protected as $rel) {
        if (!file_exists($root . '/' . $rel)) {
            $missingProtected[] = $rel;
        }
    }
    $preflightOk = mrlq_check(
        $checks,
        'Protected permanent files',
        count($missingProtected) === 0,
        count($missingProtected) === 0
            ? 'All 7 protected permanent files are present'
            : ('Missing: ' . implode(', ', $missingProtected))
    ) && $preflightOk;

    $presentCount = 0;
    $missingManifest = array();
    foreach ($manifest as $rel) {
        if (file_exists($root . '/' . $rel)) {
            $presentCount++;
        } else {
            $missingManifest[] = $rel;
        }
    }
    $preflightOk = mrlq_check(
        $checks,
        '20-item quarantine manifest',
        $presentCount === 20 && count($missingManifest) === 0,
        $presentCount . ' / 20 artifacts currently present'
    ) && $preflightOk;

    $collisionCount = 0;
    foreach ($manifest as $rel) {
        if (file_exists($quarantineRoot . '/' . $rel)) {
            $collisionCount++;
        }
    }
    $preflightOk = mrlq_check(
        $checks,
        'Quarantine destination clear',
        $collisionCount === 0 && !is_dir($quarantineRoot),
        $collisionCount === 0 && !is_dir($quarantineRoot)
            ? 'New quarantine path is unused'
            : 'Destination already exists or contains manifest items'
    ) && $preflightOk;

    $deferredMissing = array();
    foreach ($deferredUntouched as $rel) {
        if (!file_exists($root . '/' . $rel)) {
            $deferredMissing[] = $rel;
        }
    }
    mrlq_check(
        $checks,
        'Deferred/final-sweep items',
        true,
        (count($deferredUntouched) - count($deferredMissing))
        . ' / ' . count($deferredUntouched)
        . ' known deferred/final-sweep items currently present; none are in this manifest'
    );
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

if ($action === 'quarantine' && empty($errors)) {
    if (!$preflightOk) {
        $errors[] = 'QUARANTINE REFUSED: preflight did not pass.';
    } else {
        $moved = array();
        $failed = null;

        if (!mkdir($quarantineRoot, 0755, true)) {
            $errors[] = 'QUARANTINE FAILED: could not create quarantine root.';
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
                if (!mrlq_move($src, $dst)) {
                    $failed = 'Move failed: ' . $rel;
                    break;
                }
                $moved[] = $rel;
            }

            if ($failed !== null) {
                /*
                 * Automatic best-effort rollback of items already moved.
                 */
                for ($i = count($moved) - 1; $i >= 0; $i--) {
                    $rel = $moved[$i];
                    $src = $quarantineRoot . '/' . $rel;
                    $dst = $root . '/' . $rel;
                    if (file_exists($src) && !file_exists($dst)) {
                        mrlq_move($src, $dst);
                    }
                }
                $errors[] = 'QUARANTINE FAILED: ' . $failed . ' Automatic rollback attempted.';
            } else {
                $postMissingAtSource = 0;
                $postPresentAtQuarantine = 0;

                foreach ($manifest as $rel) {
                    if (!file_exists($root . '/' . $rel)) {
                        $postMissingAtSource++;
                    }
                    if (file_exists($quarantineRoot . '/' . $rel)) {
                        $postPresentAtQuarantine++;
                    }
                }

                $postOk = true;
                $postOk = mrlq_check(
                    $checks,
                    'POST originals cleared',
                    $postMissingAtSource === 20,
                    $postMissingAtSource . ' / 20 absent from active TESTPHP8 paths'
                ) && $postOk;

                $postOk = mrlq_check(
                    $checks,
                    'POST quarantine complete',
                    $postPresentAtQuarantine === 20,
                    $postPresentAtQuarantine . ' / 20 present in new quarantine'
                ) && $postOk;

                $protectedAfter = 0;
                foreach ($protected as $rel) {
                    if (file_exists($root . '/' . $rel)) {
                        $protectedAfter++;
                    }
                }
                $postOk = mrlq_check(
                    $checks,
                    'POST protected files preserved',
                    $protectedAfter === 7,
                    $protectedAfter . ' / 7 protected files remain present'
                ) && $postOk;

                $postOk = mrlq_check(
                    $checks,
                    'POST old quarantine untouched',
                    is_dir($existingQuarantine),
                    'Existing _safe_to_delete_20260824_133231 remains present'
                ) && $postOk;

                if ($postOk) {
                    $messages[] = 'PASS: all 20 LP/RP finalization artifacts quarantined. Nothing was deleted.';
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
        $collision = array();
        foreach ($manifest as $rel) {
            if (file_exists($root . '/' . $rel) && file_exists($quarantineRoot . '/' . $rel)) {
                $collision[] = $rel;
            }
        }

        if (count($collision) > 0) {
            $errors[] = 'ROLLBACK REFUSED: active-path collision(s): ' . implode(', ', $collision);
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

                if (mrlq_move($src, $dst)) {
                    $restored++;
                } else {
                    $failed[] = 'Restore failed: ' . $rel;
                }
            }

            if (count($failed) === 0 && $restored === 20) {
                $messages[] = 'ROLLBACK PASS: all 20 artifacts restored to original paths.';
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
<title>MRL TESTPHP8 LP/RP Final Quarantine v001</title>
<style>
body{margin:0;background:#111;color:#eee;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1450px;margin:16px auto;padding:0 14px}
.banner{border:1px solid #396d36;background:#173717;border-radius:12px;padding:14px 18px}
.banner h1{margin:0 0 4px;color:#e9ffd9;font-size:28px}.sub{color:#d9efc9}
.notice{margin-top:12px;border:1px solid #b87500;background:#4b2d00;border-radius:9px;padding:11px 14px;color:#ffd985}
.card{margin-top:14px;border:1px solid #444;background:#1b1b1b;border-radius:11px;padding:14px 18px}
.msg-pass{margin-top:12px;border:1px solid #34794c;background:#103d24;border-radius:8px;padding:11px 14px;color:#aef2bf}
.msg-fail{margin-top:12px;border:1px solid #ad3e3e;background:#511818;border-radius:8px;padding:11px 14px;color:#ffb1b1}
h2{margin:2px 0 12px;font-size:24px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:7px 9px;border-bottom:1px solid #353535;text-align:left;vertical-align:top}
.pass{color:#78ef9c;font-weight:bold}.fail{color:#ff9292;font-weight:bold}
.path{font-family:Consolas,monospace;color:#cddcff;word-break:break-all}
button{padding:9px 14px;border-radius:6px;border:1px solid #5e8ab0;background:#2f713e;color:#fff;font-weight:bold;cursor:pointer}
.rollback{background:#7a3636;border-color:#a65353}
.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">
<div class="banner">
<h1>MRL TESTPHP8 LP/RP Final Artifact Quarantine v001</h1>
<div class="sub">TESTPHP8 • exact 20-item manifest • quarantine only • no deletion</div>
</div>

<?php foreach ($messages as $m): ?><div class="msg-pass"><?=mrlq_h($m)?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="msg-fail"><?=mrlq_h($e)?></div><?php endforeach; ?>

<div class="notice">
<strong>Belts and suspenders:</strong>
this pass moves only the 20 LP/RP test/fixture/simulation artifacts confirmed by Inventory v006.
The existing quarantine, permanent application files, README, inventory v005, finalization/privacy installers,
and the new team.php visual installers/backups are deliberately left alone.
</div>

<div class="card">
<h2>Preflight — <?=$preflightOk ? '<span class="pass">PASS</span>' : '<span class="fail">FAIL</span>'?></h2>
<table>
<?php foreach ($checks as $c): ?>
<tr><td style="width:270px"><?=mrlq_h($c['label'])?></td><td style="width:70px" class="<?=$c['ok']?'pass':'fail'?>"><?=$c['ok']?'PASS':'FAIL'?></td><td><?=mrlq_h($c['detail'])?></td></tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>20-item manifest</h2>
<table>
<?php foreach ($manifest as $i => $rel): ?>
<tr><td style="width:50px"><?=($i+1)?></td><td class="path"><?=mrlq_h($rel)?></td><td style="width:90px"><?=file_exists($root.'/'.$rel)?'ACTIVE':(file_exists($quarantineRoot.'/'.$rel)?'QUARANTINED':'MISSING')?></td></tr>
<?php endforeach; ?>
</table>
</div>

<div class="card small">
New quarantine: <span class="path"><?=mrlq_h($quarantineName)?></span><br>
Existing quarantine left alone: <span class="path">_safe_to_delete_20260824_133231</span>
</div>

<?php if ($preflightOk && $action !== 'quarantine'): ?>
<div class="card">
<form method="post" onsubmit="return confirm('Move exactly these 20 LP/RP artifacts into the new quarantine? Nothing will be deleted.');">
<input type="hidden" name="action" value="quarantine">
<button type="submit">Quarantine 20 LP/RP Finalization Artifacts</button>
</form>
</div>
<?php endif; ?>

<?php if (is_dir($quarantineRoot)): ?>
<div class="card">
<form method="post" onsubmit="return confirm('Restore all 20 quarantined artifacts to their original paths?');">
<input type="hidden" name="action" value="rollback">
<button class="rollback" type="submit">Rollback 20 Artifacts</button>
</form>
</div>
<?php endif; ?>

<div class="card small">
After PASS, stop and show the postflight result. We will then do the separate final-sweep inventory
for installers/backups/scanners before touching the scheduler.
</div>
</div>
</body>
</html>
