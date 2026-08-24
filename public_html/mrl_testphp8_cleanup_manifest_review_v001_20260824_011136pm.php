<?php
declare(strict_types=1);
/**
 * MRL TESTPHP8 Cleanup Manifest Review v001
 * LAST MODIFIED: 8/24/2026 1:11:36 pm ET
 *
 * Exact-path review of the Narrow Cleanup Inventory v005 export.
 * READ ONLY: no deletes, renames, file writes, DB writes, or scheduler changes.
 * PHP 7.3 compatible.
 */
date_default_timezone_set('America/New_York');
$VERSION='v001';
$EXPECTED_HOST='testphp8.manliusracingleague.com';
$host=strtolower((string)($_SERVER['HTTP_HOST']??''));
$root=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/\\');
$errors=array();
if($host!==$EXPECTED_HOST){$errors[]='REFUSED: TESTPHP8-only. Current host: '.$host;}
if($root===''||!is_dir($root)){$errors[]='Document root unavailable: '.$root;}
$keepItems=array(
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_base_row_bridge_fix_v001_20260823_121700pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_database_verification_v001_20260823_043700pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_edge_case_harness_v001_20260823_114100am.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_edge_case_harness_v002_20260823_115500am.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_edge_case_harness_v003_20260823_120200pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_real_submit_error_capture_v001_20260823_130200pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_real_submit_error_capture_v002_20260823_131000pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_submission_diagnostic_v001_20260823_123500pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_submission_diagnostic_v002_20260823_125800pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_submit_bridge_test_fix_v001_20260823_123900pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_submit_rescue_bridge_v001_20260823_135200pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_submit_structural_repair_v001_20260823_020300pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_lp_rp_submit_structure_diagnostic_v003_20260823_012300pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rd_be_like_biff_fixture_manager_v001_20260822_074600pm.php'),
    array('type' => 'FILE', 'path' => 'mrl_rp_deadline_test_switch_v001_20260823_025800am.php'),
    array('type' => 'FILE', 'path' => 'mrl_rp_late_submission_server_gate_test_v001_20260823_031600am.php'),
    array('type' => 'FILE', 'path' => 'race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.json'),
    array('type' => 'FILE', 'path' => 'race_results/2026/R07_NASCAR_Cup_Series_at_Martinsville_202603290015/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json'),
    array('type' => 'DIR', 'path' => 'race_results/_rd_simulation'),
    array('type' => 'FILE', 'path' => 'race_results/admin_rd_simulation.php'),
);
$removeItems=array(
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
    array('type' => 'FILE', 'path' => 'race_results/weekly_standings_release_history_helper.php.bak_20260712_133327'),
);
$deferItems=array(
    array('type' => 'FILE', 'path' => 'mrl_testphp8_cleanup_inventory_v005_20260824_124132000.php'),
    array('type' => 'FILE', 'path' => 'race_results/README_INSTALL_AND_UPDATE.md'),
);
$deferReasons=array(
    'mrl_testphp8_cleanup_inventory_v005_20260824_124132000.php' => 'Current inventory scanner; keep until the cleanup process is complete.',
    'race_results/README_INSTALL_AND_UPDATE.md' => 'Documentation rather than an installer executable; review separately before removal.',
);
function cmr_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function cmr_stamp(){$n=microtime(true);$s=(int)floor($n);$ms=(int)floor(($n-$s)*1000);return date('Ymd_His',$s).str_pad((string)$ms,3,'0',STR_PAD_LEFT);}
function cmr_status($root,$rel){$full=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);if(is_dir($full))return 'PRESENT DIR';if(is_file($full))return 'PRESENT FILE';return 'MISSING';}
function cmr_selected($removeItems){$sel=(isset($_POST['remove'])&&is_array($_POST['remove']))?$_POST['remove']:array();$allowed=array();foreach($removeItems as $i){$allowed[$i['path']]=$i['type'];}$out=array();foreach($sel as $p){$p=(string)$p;if(isset($allowed[$p])){$out[]=array('type'=>$allowed[$p],'path'=>$p);}}return $out;}
$selected=cmr_selected($removeItems);$action=isset($_POST['action'])?(string)$_POST['action']:'';
if(empty($errors)&&($action==='export_txt'||$action==='export_csv')){$stamp=cmr_stamp();$base='MRL_TESTPHP8_Approved_Cleanup_Manifest_'.$stamp;if($action==='export_csv'){header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$base.'.csv"');$o=fopen('php://output','w');fwrite($o,"\xEF\xBB\xBF");fputcsv($o,array('Action','Type','Relative Path','Current Status'));foreach($selected as $i){fputcsv($o,array('REMOVE',$i['type'],$i['path'],cmr_status($root,$i['path'])));}fclose($o);exit;}header('Content-Type: text/plain; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$base.'.txt"');echo "MRL TESTPHP8 APPROVED CLEANUP MANIFEST\r\n";echo "Review tool: Cleanup Manifest Review ".$VERSION."\r\n";echo "Generated: ".date('Y-m-d g:i:s a T')."\r\n";echo "Root: ".$root."\r\n";echo "Selected removals: ".count($selected)."\r\n";echo "READ ONLY EXPORT — this file itself performs no deletion.\r\n";echo str_repeat('=',100)."\r\n\r\n";foreach($selected as $i){echo "[REMOVE] ".$i['type']." | ".$i['path']." | ".cmr_status($root,$i['path'])."\r\n";}exit;}
function cmr_checked($path){if($_SERVER['REQUEST_METHOD']!=='POST')return true;$sel=(isset($_POST['remove'])&&is_array($_POST['remove']))?$_POST['remove']:array();return in_array($path,$sel,true);}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MRL TESTPHP8 Cleanup Manifest Review <?=$VERSION?></title>
<style>:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}.wrap{max-width:1550px;margin:auto;padding:14px}.banner{background:#24351d;border:1px solid #547c3d;border-radius:10px;padding:12px 14px}.banner h1{margin:0;color:#dfffcf;font-size:22px}.sub,.small{font-size:12px;color:#bbb}.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px;overflow:auto}h2{margin:0 0 8px;color:#d5efc9;font-size:18px}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{padding:6px 7px;border-bottom:1px solid #333;text-align:left;vertical-align:top}th{background:#272727}.path{font-family:Consolas,monospace;color:#d8e8ff;word-break:break-all}.keep{color:#ffd36b;font-weight:700}.remove{color:#77ef9c;font-weight:700}.defer{color:#ffb866;font-weight:700}.missing{color:#ff8f8f;font-weight:700}.present{color:#a9e5b7}.err{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:10px 12px;margin-top:11px;color:#ff9b9b;font-weight:700}.warn{background:#4a2b00;border:1px solid #b97920;border-radius:8px;padding:10px 12px;margin-top:11px;color:#ffd36b;font-weight:700}.ok{background:#17351f;border:1px solid #3f7d4f;border-radius:8px;padding:10px 12px;margin-top:11px;color:#bff1c9}.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}button{background:#2b5d82;color:#fff;border:1px solid #4e8db7;border-radius:6px;padding:7px 11px;font-weight:700;cursor:pointer}input[type=checkbox]{transform:scale(1.2)}.count{font-weight:700;font-size:17px}</style>
<script>function setAll(s){var b=document.querySelectorAll('input[name="remove[]"]');for(var i=0;i<b.length;i++)b[i].checked=s;updateCount();}function updateCount(){var b=document.querySelectorAll('input[name="remove[]"]'),c=0;for(var i=0;i<b.length;i++)if(b[i].checked)c++;var n=document.getElementById('selectedCount');if(n)n.textContent=c;}</script></head><body onload="updateCount()"><div class="wrap">
<div class="banner"><h1>MRL TESTPHP8 Cleanup Manifest Review v001</h1><div class="sub">READ ONLY • exact-path approval review • source: Narrow Cleanup Inventory v005</div></div>
<?php foreach($errors as $e):?><div class="err"><?=cmr_h($e)?></div><?php endforeach;?>
<div class="warn">This page DOES NOT delete anything. It only reviews exact paths and exports an approved-removal manifest.</div>
<div class="ok">Recommended REMOVE rows are checked by default. Uncheck anything you want to keep. KEEP UNTIL FINALIZATION and DEFER rows cannot enter the approved removal export.</div>
<form method="post"><div class="card"><h2 class="remove">Recommended Removal — <?=count($removeItems)?> item(s)</h2><div class="toolbar"><button type="button" onclick="setAll(true)">Check All Recommended</button><button type="button" onclick="setAll(false)">Uncheck All</button><button type="submit" name="action" value="export_txt">Export Selected TXT</button><button type="submit" name="action" value="export_csv">Export Selected CSV</button></div><p>Selected for removal: <span id="selectedCount" class="count">0</span></p><table><tr><th>Remove?</th><th>Type</th><th>Relative path</th><th>Current status</th></tr>
<?php foreach($removeItems as $i):$s=cmr_status($root,$i['path']);?><tr><td><input type="checkbox" name="remove[]" value="<?=cmr_h($i['path'])?>" <?=cmr_checked($i['path'])?'checked':''?> onchange="updateCount()"></td><td><?=cmr_h($i['type'])?></td><td class="path"><?=cmr_h($i['path'])?></td><td class="<?=$s==='MISSING'?'missing':'present'?>"><?=cmr_h($s)?></td></tr><?php endforeach;?></table></div>
<div class="card"><h2 class="defer">Defer / Review Separately — <?=count($deferItems)?> item(s)</h2><table><tr><th>Type</th><th>Relative path</th><th>Status</th><th>Why deferred</th></tr><?php foreach($deferItems as $i):$s=cmr_status($root,$i['path']);?><tr><td><?=cmr_h($i['type'])?></td><td class="path"><?=cmr_h($i['path'])?></td><td class="<?=$s==='MISSING'?'missing':'present'?>"><?=cmr_h($s)?></td><td><?=cmr_h(isset($deferReasons[$i['path']])?$deferReasons[$i['path']]:'Review separately.')?></td></tr><?php endforeach;?></table></div>
<div class="card"><h2 class="keep">Keep Until Finalization — <?=count($keepItems)?> item(s)</h2><table><tr><th>Type</th><th>Relative path</th><th>Status</th></tr><?php foreach($keepItems as $i):$s=cmr_status($root,$i['path']);?><tr><td><?=cmr_h($i['type'])?></td><td class="path"><?=cmr_h($i['path'])?></td><td class="<?=$s==='MISSING'?'missing':'present'?>"><?=cmr_h($s)?></td></tr><?php endforeach;?></table></div></form>
<div class="card small"><strong>Source:</strong> MRL_TESTPHP8_Cleanup_Inventory_20260824_130822785.txt<br><strong>Deletion behavior:</strong> none.<br><strong>Next:</strong> export the selected TXT manifest and return it for final review before any cleanup installer is generated.</div></div></body></html>