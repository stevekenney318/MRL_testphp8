<?php
declare(strict_types=1);

/**
 * MRL TESTPHP8 Cleanup Quarantine Installer v001
 *
 * LAST MODIFIED: 8/24/2026 1:23 pm ET
 *
 * PURPOSE
 * -------
 * Move the 97 EXACT approved cleanup paths into a reversible quarantine
 * folder under TESTPHP8:
 *
 *   _safe_to_delete_YYYYMMDD_HHMMSS/
 *
 * Original relative paths are preserved inside quarantine.
 *
 * SOURCE MANIFEST
 * ---------------
 * MRL_TESTPHP8_Approved_Cleanup_Manifest_20260824_131957184.txt
 * Selected removals: 97
 *
 * SAFETY
 * ------
 * - TESTPHP8-only host lock
 * - exact-path manifest only; NO wildcard deletion
 * - protected KEEP/DEFER paths explicitly blocked
 * - preflight required and shown before quarantine move
 * - no database writes
 * - no scheduler changes
 * - no permanent deletion
 * - automatic rollback attempt if any move fails mid-operation
 * - manual rollback available after a successful move
 * - quarantine manifest + log written inside quarantine folder
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$EXPECTED_HOST = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$manifest = array(
    array('type' => 'DIR', 'path' => 'mrl_adj_focused_patch_backup_20260820_044600am'),
    array('type' => 'FILE', 'path' => 'mrl_adj_focused_patch_v001_20260820_015900am.php'),
    array('type' => 'FILE', 'path' => 'mrl_adj_focused_patch_v002_20260820_021500am.php'),
    array('type' => 'FILE', 'path' => 'mrl_adj_focused_patch_v003_20260820_021900am.php'),
    array('type' => 'FILE', 'path' => 'mrl_adj_focused_patch_v004_20260820_022800am.php'),
    array('type' => 'FILE', 'path' => 'mrl_adj_focused_patch_v005_20260820_044600am.php'),
    array('type' => 'DIR', 'path' => 'mrl_admin_setup_environment_banner_backup_20260819_033000pm'),
    array('type' => 'FILE', 'path' => 'mrl_admin_setup_environment_banner_patch_v003_20260819_033000pm.php'),
    array('type' => 'DIR', 'path' => 'mrl_auto_pick_window_backup_20260819_045153am'),
    array('type' => 'FILE', 'path' => 'mrl_auto_pick_window_installer_v001_20260819_045153am.php'),
    array('type' => 'FILE', 'path' => 'mrl_auto_pick_window_patch_v002_20260819_023400pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_auto_pick_window_patch_v002_20260819_030100pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_auto_pick_window_patch_v002_20260819_030800pm.php'),
    array('type' => 'DIR', 'path' => 'mrl_lp_admin_adjustment_backup_20260819_073400pm'),
    array('type' => 'FILE', 'path' => 'mrl_lp_admin_adjustment_installer_v001_20260819_071200pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_admin_adjustment_installer_v002_20260819_073400pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_finalization_installer_v001_20260818_030827am.php'),
    array('type' => 'FILE', 'path' => 'mrl_pick_window_messaging_refinement_installer_v001_20260820_023324pm.php'),
    array('type' => 'DIR', 'path' => 'mrl_prior_year_chart_connection_backup_20260821_095000pm'),
    array('type' => 'FILE', 'path' => 'mrl_prior_year_chart_connection_fix_installer_v001_20260821_095000pm.php'),
    array('type' => 'DIR', 'path' => 'mrl_rd_dual_choice_preview_backup_20260822_063000pm'),
    array('type' => 'FILE', 'path' => 'mrl_rd_dual_choice_preview_installer_v001_20260822_063000pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_dual_driver_simulation_installer_v001_20260822_060700pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_fixture_cleanup_v010_installer_v001_20260822_085700pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_phase2_simulation_installer_v001_20260820_102700pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_real_flow_integration_installer_v001_20260822_072600pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_real_flow_integration_installer_v002_20260822_073600pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_simulation_diagnostic_patch_v001_20260820_105000pm.php'),
    array('type' => 'DIR', 'path' => 'mrl_rd_simulation_driver_guard_backup_20260821_120100am'),
    array('type' => 'FILE', 'path' => 'mrl_rd_simulation_driver_required_guard_patch_v001_20260821_120100am.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_simulation_fixture_parser_patch_v001_20260820_110500pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_simulation_race_values_patch_v001_20260820_112700pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_time_travel_emergency_reset_installer_v001_20260822_084900pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_time_travel_test_hook_installer_v001_20260822_075900pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rp_cosmetic_polish_installer_v001_20260822_083500pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rp_single_test_harness_v001_20260822_090600pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_team_identity_repair_v001_20260820_011600am.php'),
    array('type' => 'FILE', 'path' => 'mrl_team_user_menu_native_fix_installer_v001_20260820_073602pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_team_user_menu_native_fix_patch_v002_20260820_082900pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_testphp8_cleanup_inventory_v001_20260823_055800pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_testphp8_cleanup_inventory_v002_20260824_015732000.php'),
    array('type' => 'FILE', 'path' => 'mrl_testphp8_cleanup_inventory_v003_20260824_021500000.php'),
    array('type' => 'FILE', 'path' => 'mrl_testphp8_cleanup_inventory_v004_20260824_025201000.php'),
    array('type' => 'DIR', 'path' => 'mrl_unified_schedule_backup_20260818_041300am'),
    array('type' => 'FILE', 'path' => 'mrl_unified_schedule_installer_v001_20260818_041300am.php'),
    array('type' => 'FILE', 'path' => 'mrl_user_year_audit_installer_v001_20260821_092100pm.php'),
    array('type' => 'DIR', 'path' => 'race_results/_installer_backups'),
    array('type' => 'DIR', 'path' => 'race_results/_race_finish_confirmation_install_backup_20260724_043417pm'),
    array('type' => 'DIR', 'path' => 'race_results/_race_finish_confirmation_install_backup_20260726_051503pm'),
    array('type' => 'DIR', 'path' => 'race_results/_race_finish_confirmation_install_backup_20260726_071639am'),
    array('type' => 'DIR', 'path' => 'race_results/_race_finish_confirmation_install_backup_20260726_092259am'),
    array('type' => 'DIR', 'path' => 'race_results/_race_results_dashboard_install_backup_20260726_104138am'),
    array('type' => 'FILE', 'path' => 'race_results/install_canonical_snapshot_isolation_v001_20260712_012722pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_mrl_at_a_glance_v005_20260706_073625pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_mrl_wp_images_server_v004_20260723_061206am.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_mrl_wp_images_server_v006_20260723_032622pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_mrl_wp_images_v002_20260722_035840pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_mrl_wp_images_v002_20260722_042418pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_mrl_wp_images_v003_20260723_020359am.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_mrl_wp_images_v004_server_fix_20260723_075644am.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_finish_confirmation_v002_20260724_043417pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_finish_confirmation_v003_20260726_071639am.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_finish_confirmation_v004_20260726_092259am.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_finish_confirmation_v005_20260726_050941pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_finish_confirmation_v005_v002_20260726_051503pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_finish_confirmation_v006_20260809_035034pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_finish_confirmation_v006_20260809_044704pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_finish_confirmation_v006_20260809_045620pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_finish_confirmation_v006_20260809_051116pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_results_dashboard_v022_20260726_104138am.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_results_menu_v001_20260727_022317pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_race_results_menu_v002_20260808_092906pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_snapshot_companion_generation_v001_20260719_013518pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_weekly_standings_v063_20260722_120524pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_weekly_standings_v064_20260722_122754pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_weekly_standings_v065_20260722_012458pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/install_weekly_standings_v065_20260722_125621pm.php'),
    array('type' => 'FILE', 'path' => 'race_results/mrl_at_a_glance.php.bak_20260707_050648'),
    array('type' => 'FILE', 'path' => 'race_results/mrl_wp_images.html.bak_20260723_022938'),
    array('type' => 'FILE', 'path' => 'race_results/mrl_wp_images.html.bak_20260723_062242'),
    array('type' => 'FILE', 'path' => 'race_results/mrl_wp_images.html.bak_20260723_080401'),
    array('type' => 'FILE', 'path' => 'race_results/mrl_wp_images.html.bak_20260723_154346'),
    array('type' => 'FILE', 'path' => 'race_results/mrl_wp_images_backup_20260818_121429am.html'),
    array('type' => 'FILE', 'path' => 'race_results/mrl_wp_images_data.php.bak_20260723_022938'),
    array('type' => 'FILE', 'path' => 'race_results/mrl_wp_images_data.php.bak_20260723_062242'),
    array('type' => 'FILE', 'path' => 'race_results/mrl_wp_images_data.php.bak_20260723_080401'),
    array('type' => 'FILE', 'path' => 'race_results/mrl_wp_images_data.php.bak_20260723_154346'),
    array('type' => 'FILE', 'path' => 'race_results/race_results_classify_revisions.php.bak_20260712_133327'),
    array('type' => 'FILE', 'path' => 'race_results/race_results_monitor.php.bak_20260719_142948'),
    array('type' => 'FILE', 'path' => 'race_results/race_results_revision_monitor.php.bak_20260719_142948'),
    array('type' => 'FILE', 'path' => 'race_results/race_results_snapshot_helper.php.bak_20260712_133327'),
    array('type' => 'FILE', 'path' => 'race_results/race_results_weekly_winner_diagnostic.php'),
    array('type' => 'FILE', 'path' => 'race_results/weekly_standings.php.bak_20260722_121415'),
    array('type' => 'FILE', 'path' => 'race_results/weekly_standings.php.bak_20260722_123340'),
    array('type' => 'FILE', 'path' => 'race_results/weekly_standings.php.bak_20260722_130225'),
    array('type' => 'FILE', 'path' => 'race_results/weekly_standings.php.bak_20260722_133355'),
    array('type' => 'FILE', 'path' => 'race_results/weekly_standings_release_history_helper.php.bak_20260712_133327')
);

$protectedPaths = array(
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
    'race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json',
    'race_results/_rd_simulation',
    'race_results/admin_rd_simulation.php',
    'mrl_testphp8_cleanup_inventory_v005_20260824_124132000.php',
    'race_results/README_INSTALL_AND_UPDATE.md'
);

function cq_h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function cq_safe_rel($path)
{
    if ($path === '' || $path[0] === '/' || $path[0] === '\\') {
        return false;
    }
    if (strpos($path, '..') !== false) {
        return false;
    }
    if (strpos($path, "\0") !== false) {
        return false;
    }
    return true;
}

function cq_full($root, $rel)
{
    return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

function cq_status($root, $item)
{
    $full = cq_full($root, $item['path']);
    if ($item['type'] === 'FILE') {
        if (is_file($full)) return 'OK FILE';
        if (is_dir($full)) return 'TYPE MISMATCH: DIR';
        return 'MISSING';
    }
    if ($item['type'] === 'DIR') {
        if (is_dir($full)) return 'OK DIR';
        if (is_file($full)) return 'TYPE MISMATCH: FILE';
        return 'MISSING';
    }
    return 'INVALID TYPE';
}

function cq_mkdir_parent($path)
{
    $dir = dirname($path);
    if (is_dir($dir)) return true;
    return mkdir($dir, 0755, true);
}

function cq_recursive_remove_empty($dir, $stop)
{
    $dir = rtrim($dir, '/\\');
    $stop = rtrim($stop, '/\\');

    while ($dir !== '' && $dir !== $stop && strpos($dir, $stop) === 0) {
        $items = @scandir($dir);
        if (!is_array($items) || count($items) > 2) {
            break;
        }
        @rmdir($dir);
        $dir = dirname($dir);
    }
}

function cq_unique_quarantine_dir($root)
{
    $base = '_safe_to_delete_' . date('Ymd_His');
    $candidate = $root . DIRECTORY_SEPARATOR . $base;
    $n = 1;
    while (file_exists($candidate)) {
        $candidate = $root . DIRECTORY_SEPARATOR . $base . '_' . $n;
        $n++;
    }
    return array(basename($candidate), $candidate);
}

function cq_preflight($root, $manifest, $protectedPaths)
{
    $rows = array();
    $ok = true;
    $seen = array();
    $protectedMap = array_fill_keys($protectedPaths, true);

    foreach ($manifest as $item) {
        $rel = $item['path'];
        $issues = array();

        if (!cq_safe_rel($rel)) {
            $issues[] = 'UNSAFE PATH';
        }

        if (isset($seen[$rel])) {
            $issues[] = 'DUPLICATE MANIFEST PATH';
        }
        $seen[$rel] = true;

        if (isset($protectedMap[$rel])) {
            $issues[] = 'PROTECTED PATH COLLISION';
        }

        $status = cq_status($root, $item);
        if (strpos($status, 'OK ') !== 0) {
            $issues[] = $status;
        }

        if (!empty($issues)) {
            $ok = false;
        }

        $rows[] = array(
            'type' => $item['type'],
            'path' => $rel,
            'status' => empty($issues) ? $status : implode('; ', $issues)
        );
    }

    return array($ok, $rows);
}

function cq_write_metadata($qDir, $qName, $manifest, $moves, $version)
{
    $meta = array(
        'version' => $version,
        'created_at' => date('c'),
        'quarantine_folder' => $qName,
        'item_count' => count($manifest),
        'items' => $moves
    );

    $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @file_put_contents($qDir . DIRECTORY_SEPARATOR . '_quarantine_manifest.json', $json . PHP_EOL);
    }

    $log = "MRL TESTPHP8 CLEANUP QUARANTINE\r\n";
    $log .= "Version: " . $version . "\r\n";
    $log .= "Created: " . date('Y-m-d g:i:s a T') . "\r\n";
    $log .= "Folder: " . $qName . "\r\n";
    $log .= "Items: " . count($moves) . "\r\n";
    $log .= str_repeat('=', 90) . "\r\n";
    foreach ($moves as $move) {
        $log .= $move['type'] . " | " . $move['path'] . "\r\n";
    }
    @file_put_contents($qDir . DIRECTORY_SEPARATOR . '_quarantine_log.txt', $log);
}

$errors = array();
$messages = array();
$operationRows = array();
$quarantineName = '';
$action = isset($_POST['action']) ? (string)$_POST['action'] : 'preflight';

if ($host !== $EXPECTED_HOST) {
    $errors[] = 'REFUSED: TESTPHP8-only. Current host: ' . $host;
}
if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable: ' . $root;
}
if (count($manifest) !== 97) {
    $errors[] = 'Manifest count mismatch. Expected 97, found ' . count($manifest) . '.';
}

list($preflightOk, $preflightRows) = cq_preflight($root, $manifest, $protectedPaths);

if ($action === 'quarantine' && empty($errors)) {
    if (!$preflightOk) {
        $errors[] = 'Quarantine move REFUSED because preflight is not 100% clean.';
    } else {
        list($quarantineName, $qDir) = cq_unique_quarantine_dir($root);

        if (!mkdir($qDir, 0755, true)) {
            $errors[] = 'Could not create quarantine folder: ' . $quarantineName;
        } else {
            $moved = array();
            $moveFailed = false;
            $failReason = '';

            foreach ($manifest as $item) {
                $src = cq_full($root, $item['path']);
                $dst = $qDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $item['path']);

                if (!cq_mkdir_parent($dst)) {
                    $moveFailed = true;
                    $failReason = 'Could not create destination parent for ' . $item['path'];
                    break;
                }

                if (!@rename($src, $dst)) {
                    $moveFailed = true;
                    $failReason = 'Move failed for ' . $item['path'];
                    break;
                }

                $moved[] = array(
                    'type' => $item['type'],
                    'path' => $item['path']
                );
            }

            if ($moveFailed) {
                $rollbackErrors = array();

                for ($i = count($moved) - 1; $i >= 0; $i--) {
                    $item = $moved[$i];
                    $src = $qDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $item['path']);
                    $dst = cq_full($root, $item['path']);

                    if (!cq_mkdir_parent($dst) || !@rename($src, $dst)) {
                        $rollbackErrors[] = $item['path'];
                    } else {
                        cq_recursive_remove_empty(dirname($src), $qDir);
                    }
                }

                if (empty($rollbackErrors)) {
                    @rmdir($qDir);
                    $errors[] = $failReason . '. Automatic rollback succeeded; no approved item remains quarantined.';
                } else {
                    $errors[] = $failReason . '. AUTOMATIC ROLLBACK INCOMPLETE for: ' . implode(', ', $rollbackErrors);
                    cq_write_metadata($qDir, $quarantineName, $manifest, $moved, $VERSION);
                }
            } else {
                cq_write_metadata($qDir, $quarantineName, $manifest, $moved, $VERSION);

                $postOk = true;
                foreach ($manifest as $item) {
                    $original = cq_full($root, $item['path']);
                    $quarantined = $qDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $item['path']);

                    $origExists = file_exists($original);
                    $qExists = file_exists($quarantined);
                    $status = (!$origExists && $qExists) ? 'PASS' : 'FAIL';

                    if ($status !== 'PASS') {
                        $postOk = false;
                    }

                    $operationRows[] = array(
                        'path' => $item['path'],
                        'status' => $status,
                        'detail' => 'original=' . ($origExists ? 'present' : 'absent')
                            . ', quarantine=' . ($qExists ? 'present' : 'absent')
                    );
                }

                if ($postOk) {
                    $messages[] = 'PASS: all 97 approved paths were moved into ' . $quarantineName . '. Nothing was permanently deleted.';
                } else {
                    $errors[] = 'Postflight found one or more path mismatches. Review the table before doing anything else.';
                }
            }
        }
    }
}

if ($action === 'rollback' && empty($errors)) {
    $requested = isset($_POST['quarantine_name']) ? basename((string)$_POST['quarantine_name']) : '';

    if (!preg_match('/^_safe_to_delete_[0-9]{8}_[0-9]{6}(?:_[0-9]+)?$/', $requested)) {
        $errors[] = 'Invalid quarantine folder name for rollback.';
    } else {
        $qDir = $root . DIRECTORY_SEPARATOR . $requested;

        if (!is_dir($qDir)) {
            $errors[] = 'Rollback folder not found: ' . $requested;
        } else {
            $rollbackOk = true;

            foreach ($manifest as $item) {
                $src = $qDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $item['path']);
                $dst = cq_full($root, $item['path']);

                if (file_exists($dst)) {
                    $rollbackOk = false;
                    $operationRows[] = array(
                        'path' => $item['path'],
                        'status' => 'FAIL',
                        'detail' => 'Original path already exists; rollback refused for this item.'
                    );
                    continue;
                }

                if (!file_exists($src)) {
                    $rollbackOk = false;
                    $operationRows[] = array(
                        'path' => $item['path'],
                        'status' => 'FAIL',
                        'detail' => 'Quarantined source missing.'
                    );
                    continue;
                }

                if (!cq_mkdir_parent($dst) || !@rename($src, $dst)) {
                    $rollbackOk = false;
                    $operationRows[] = array(
                        'path' => $item['path'],
                        'status' => 'FAIL',
                        'detail' => 'Move back failed.'
                    );
                    continue;
                }

                cq_recursive_remove_empty(dirname($src), $qDir);

                $operationRows[] = array(
                    'path' => $item['path'],
                    'status' => 'PASS',
                    'detail' => 'Restored to original path.'
                );
            }

            if ($rollbackOk) {
                @unlink($qDir . DIRECTORY_SEPARATOR . '_quarantine_manifest.json');
                @unlink($qDir . DIRECTORY_SEPARATOR . '_quarantine_log.txt');
                @rmdir($qDir);
                $messages[] = 'PASS: all 97 approved paths were restored to their original locations.';
            } else {
                $errors[] = 'Rollback was not fully successful. Review the table; do not run cleanup again until resolved.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL TESTPHP8 Cleanup Quarantine <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1500px;margin:0 auto;padding:14px}
.banner{background:#24351d;border:1px solid #547c3d;border-radius:10px;padding:12px 14px}
.banner h1{margin:0;color:#dfffcf;font-size:22px}
.sub{font-size:12px;color:#bdd4ae;margin-top:4px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px;overflow:auto}
h2{margin:0 0 8px;color:#d5efc9;font-size:18px}
table{width:100%;border-collapse:collapse;min-width:1000px}
th,td{padding:6px 7px;border-bottom:1px solid #333;text-align:left;vertical-align:top}
th{background:#272727}
.path{font-family:Consolas,monospace;color:#d8e8ff;word-break:break-all}
.pass{color:#74ef9b;font-weight:700}
.fail{color:#ff8d8d;font-weight:700}
.err{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:10px 12px;margin-top:11px;color:#ff9b9b;font-weight:700}
.ok{background:#17351f;border:1px solid #3f7d4f;border-radius:8px;padding:10px 12px;margin-top:11px;color:#bff1c9}
.warn{background:#4a2b00;border:1px solid #b97920;border-radius:8px;padding:10px 12px;margin-top:11px;color:#ffd36b}
button{background:#2b5d82;color:#fff;border:1px solid #4e8db7;border-radius:6px;padding:8px 12px;font-weight:700;cursor:pointer}
button.danger{background:#703021;border-color:#b6654d}
button.rollback{background:#5a4b18;border-color:#a78d38}
.small{font-size:12px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL TESTPHP8 Cleanup Quarantine Installer v001</h1>
<div class="sub">Exact 97-path manifest • reversible quarantine • NO permanent deletion</div>
</div>

<?php foreach ($errors as $error): ?>
<div class="err"><?=cq_h($error)?></div>
<?php endforeach; ?>

<?php foreach ($messages as $message): ?>
<div class="ok"><?=cq_h($message)?></div>
<?php endforeach; ?>

<div class="warn">
<strong>Safety model:</strong> approved paths are MOVED, not deleted.
Destination is a new <span class="path">_safe_to_delete_YYYYMMDD_HHMMSS</span> folder under TESTPHP8.
</div>

<div class="card">
<h2>Preflight — <?=$preflightOk ? '<span class="pass">PASS</span>' : '<span class="fail">FAIL</span>'?></h2>
<p>Manifest: <strong><?=count($manifest)?></strong> exact approved paths.</p>
<p>Protected KEEP/DEFER paths: <strong><?=count($protectedPaths)?></strong>.</p>

<?php if ($preflightOk && $action !== 'quarantine' && $action !== 'rollback'): ?>
<form method="post" onsubmit="return confirm('Move all 97 approved paths into a reversible _safe_to_delete quarantine folder? Nothing will be permanently deleted.');">
<input type="hidden" name="action" value="quarantine">
<button class="danger" type="submit">Move 97 Approved Paths to Quarantine</button>
</form>
<?php endif; ?>

<table>
<tr><th>Type</th><th>Exact relative path</th><th>Preflight status</th></tr>
<?php foreach ($preflightRows as $row): ?>
<tr>
<td><?=cq_h($row['type'])?></td>
<td class="path"><?=cq_h($row['path'])?></td>
<td class="<?=strpos($row['status'], 'OK ') === 0 ? 'pass' : 'fail'?>"><?=cq_h($row['status'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if (!empty($operationRows)): ?>
<div class="card">
<h2>Operation Postflight</h2>
<table>
<tr><th>Exact relative path</th><th>Status</th><th>Detail</th></tr>
<?php foreach ($operationRows as $row): ?>
<tr>
<td class="path"><?=cq_h($row['path'])?></td>
<td class="<?=$row['status'] === 'PASS' ? 'pass' : 'fail'?>"><?=cq_h($row['status'])?></td>
<td><?=cq_h($row['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php if ($quarantineName !== '' && empty($errors)): ?>
<div class="card">
<h2>Quarantine Created</h2>
<p class="path"><?=cq_h($quarantineName)?></p>
<p>Keep this folder while you perform TESTPHP8 sanity checks.</p>

<form method="post" onsubmit="return confirm('ROLLBACK will move all 97 quarantined items back to their original locations. Continue?');">
<input type="hidden" name="action" value="rollback">
<input type="hidden" name="quarantine_name" value="<?=cq_h($quarantineName)?>">
<button class="rollback" type="submit">Rollback All 97 Paths</button>
</form>
</div>
<?php endif; ?>

<div class="card small">
<strong>Source approval manifest:</strong>
<span class="path">MRL_TESTPHP8_Approved_Cleanup_Manifest_20260824_131957184.txt</span><br>
<strong>Database:</strong> untouched.<br>
<strong>Scheduler:</strong> untouched.<br>
<strong>Permanent deletion:</strong> none.<br>
<strong>After successful quarantine:</strong> sanity-check TESTPHP8 and race_results before any later purge.
</div>

</div>
</body>
</html>
