<?php
declare(strict_types=1);
/**
 * MRL LP→RP Finalization Installer
 * VERSION: v001
 * LAST MODIFIED: 8/24/2026 2:10:33 pm ET
 *
 * TESTPHP8 only. No DB writes. No scheduler changes.
 * Backs up team.php v026 and submit-team-picks.php v010, then installs:
 *   team.php v027
 *   submit-team-picks.php v011
 * Rollback restores both originals.
 */

date_default_timezone_set('America/New_York');

$VERSION='v001';
$EXPECTED_HOST='testphp8.manliusracingleague.com';
$host=strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root=rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$teamPath=$root.'/team.php';
$submitPath=$root.'/submit-team-picks.php';
$backupDir=$root.'/mrl_lp_rp_finalization_backup_20260824_021033pm';
$expectedTeamSha='c80c9157a8b294562ef466dcc507486c0a7d6d348fc4e6bf93c86042bbeffe22';
$errors=[];$messages=[];$checks=[];
$action=isset($_POST['action'])?(string)$_POST['action']:'preflight';

function f_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function f_check(&$checks,$label,$ok,$detail){$checks[]=['label'=>$label,'ok'=>(bool)$ok,'detail'=>$detail];return (bool)$ok;}
function f_replace_once($s,$old,$new,$label){$c=substr_count($s,$old);if($c!==1){throw new RuntimeException($label.' expected once; found '.$c);}return str_replace($old,$new,$s);}
function f_regex_once($s,$pattern,$replacement,$label){$count=0;$out=preg_replace($pattern,$replacement,$s,1,$count);if($out===null||$count!==1){throw new RuntimeException($label.' expected one regex match; found '.$count);}return $out;}

if($host!==$EXPECTED_HOST)$errors[]='REFUSED: TESTPHP8-only. Current host: '.$host;
if($root===''||!is_dir($root))$errors[]='Document root unavailable.';

$team='';$submit='';
if(!$errors){$team=@file_get_contents($teamPath);$submit=@file_get_contents($submitPath);if($team===false){$errors[]='Cannot read team.php';$team='';}if($submit===false){$errors[]='Cannot read submit-team-picks.php';$submit='';}}

$preflight=!$errors;
if($preflight){
    $tests=[
      ['team.php version',substr_count($team,'VERSION: v026')===1,'Expected v026'],
      ['team.php exact supplied SHA-256',hash('sha256',$team)===$expectedTeamSha,'Exact uploaded v026 baseline'],
      ['team permanent LP/RP helper',substr_count($team,'function teampage_get_rd_base_pick_row')===1,'Bridge helper present'],
      ['team RD bridge call',substr_count($team,'$rdBasePickRow = teampage_get_rd_base_pick_row(')===1,'Bridge call present'],
      ['team temporary fixture marker',substr_count($team,'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE')===1,'Temporary hook present'],
      ['team temporary banner',substr_count($team,'TEST TIME OVERRIDE ACTIVE')===1,'Temporary banner present'],
      ['team obsolete update notice',substr_count($team,'Update 2025-12-11 23:18:31 - See note below regarding previous years picks')===1,'Old notice present'],
      ['team obsolete Chad FYI',substr_count($team,'With the help of my friend Chad from ChatGPT')===1,'Old FYI present'],
      ['submit version',substr_count($submit,'VERSION: v010')===1,'Expected v010'],
      ['submit LP base ID support',substr_count($submit,"pick_type IN ('SEG', 'ADJ', 'LP')")===1,'Permanent LP support present'],
      ['submit LP RD base-row support',substr_count($submit,"pick_type IN ('SEG','ADJ','LP')")===1,'Permanent LP support present'],
      ['submit historical edge marker',substr_count($submit,'_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json')===1,'Temporary bypass present'],
      ['submit historical fixture ID',substr_count($submit,'BE_LIKE_BIFF_LP_AJ_R06_R07')===1,'Temporary bypass present'],
    ];
    foreach($tests as $t){$preflight=f_check($checks,$t[0],$t[1],$t[2])&&$preflight;}
}

function build_team($s){
    $s=f_replace_once($s,'VERSION: v026','VERSION: v027','team version');
    $s=f_replace_once($s,'LAST MODIFIED: 8/22/2026 7:26:00 pm','LAST MODIFIED: 8/24/2026 2:10:33 pm','team timestamp');
    $needle=" * CHANGELOG:\n *\n";
    $chg=$needle
      ." * v027 (8/24/2026 2:10:33 pm)\n"
      ." * - FINALIZE: Preserves permanent LP-as-Replacement-Pick base-row support.\n"
      ." * - CLEANUP: Removes LP→RP artificial-time fixture behavior and banner.\n"
      ." * - UI: Removes obsolete 2025 previous-picks notices.\n"
      ." * - UI: Adds subtle neutral panel around active pick/form area.\n"
      ." * - UI: Previous Years Picks is collapsible and collapsed by default.\n"
      ." * - PRESERVE: Existing normal/LP/RD/SPECIAL_AUTH routing and scoring semantics.\n *\n";
    $s=f_replace_once($s,$needle,$chg,'team changelog');

    $pattern='~            // MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE.*?            if \(\$rdDeadlineTimestamp > 0 && \$rdDeadlineNowTimestamp >= \$rdDeadlineTimestamp\) \{\n                \$showRdWrapper = false;\n            \}~s';
    $replacement="            if (\$rdDeadlineTimestamp > 0 && time() >= \$rdDeadlineTimestamp) {\n                \$showRdWrapper = false;\n            }";
    $s=f_regex_once($s,$pattern,$replacement,'team time-travel block');

    $pattern='~                    if \(\$showRdWrapper\) \{\n                        if \(!empty\(\$rdTestTimeOverrideActive\).*?                        include \'team_replacement_driver\.php\';~s';
    $replacement="                    if (\$showRdWrapper) {\n                        include 'team_replacement_driver.php';";
    $s=f_regex_once($s,$pattern,$replacement,'team test banner block');

    $s=f_replace_once($s,"        <a style=\"color:red;\">Update 2025-12-11 23:18:31 - See note below regarding previous years picks</a><br>\n        <br>\n        <br>\n",'', 'old update notice');

    $css="        body {\n            background-color: #222222;\n            padding-top: 60px;\n        }";
    $cssNew=$css."\n\n        .mrl-pick-panel {\n            margin: 12px 0 22px 0;\n            padding: 18px 20px;\n            border: 1px solid #666666;\n            border-radius: 12px;\n            background: rgba(255,255,255,0.018);\n        }\n\n        .mrl-previous-years {\n            margin: 8px 0 22px 0;\n            border: 1px solid #555555;\n            border-radius: 10px;\n            background: rgba(255,255,255,0.012);\n        }\n        .mrl-previous-years summary {\n            padding: 12px 16px;\n            cursor: pointer;\n            font-size: 20.0pt;\n            font-weight: bold;\n            color: #dfcca8;\n            outline: none;\n        }\n        .mrl-previous-years-content {padding:6px 12px 14px 12px;}";
    $s=f_replace_once($s,$css,$cssNew,'team CSS');

    $start="    <div style=\"color:#dfcca8; font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif;\">\n        <br>\n        <?php";
    $startNew="    <div style=\"color:#dfcca8; font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif;\">\n        <br>\n        <div class=\"mrl-pick-panel\">\n        <?php";
    if(substr_count($s,$start)!==1){throw new RuntimeException('form panel insertion point expected once; found '.substr_count($s,$start));}
    $s=str_replace($start,$startNew,$s);

    $oldPrev=<<<'OLDPREV'
        ?>
        <br>
        <br>

        <p style='font-size:18.0pt;line-height:120%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>
            <span style="font-size:20.0pt; text-decoration:underline; display:inline;">Previous Years Picks</span>
            <br><br>
            FYI: Great news — With the help of my friend Chad from ChatGPT, the user picks data has now been fully restored. As of 2025-12-11 23:18:31, all data is now being pulled from the final team picks table instead of the historical backup table. You should not see any gaps in your previous years picks. Please let us know if you see anything that doesn't look right to you. Thanks for your patience through all of this.<br>
        </p>
    </div>
</div>
<br>

<?php
$sqlYears = "SELECT * FROM years WHERE year < :raceYear AND year > 0 ORDER BY year DESC";
$stmtYears = $dbo->prepare($sqlYears);
$stmtYears->execute([':raceYear' => $raceYear]);

while ($yearRow = $stmtYears->fetch(PDO::FETCH_ASSOC)) {
    $prevRaceYear = $yearRow['year'];
    include 'prior_year_user_team_chart.php';
}
?>

<br>
OLDPREV;
    $newPrev=<<<'NEWPREV'
        ?>
        </div>
        <br>

        <details class="mrl-previous-years">
            <summary>Previous Years Picks</summary>
            <div class="mrl-previous-years-content">
                <?php
                $sqlYears = "SELECT * FROM years WHERE year < :raceYear AND year > 0 ORDER BY year DESC";
                $stmtYears = $dbo->prepare($sqlYears);
                $stmtYears->execute([':raceYear' => $raceYear]);

                while ($yearRow = $stmtYears->fetch(PDO::FETCH_ASSOC)) {
                    $prevRaceYear = $yearRow['year'];
                    include 'prior_year_user_team_chart.php';
                }
                ?>
            </div>
        </details>
    </div>
</div>
<br>
NEWPREV;
    $s=f_replace_once($s,$oldPrev,$newPrev,'previous years block');
    return $s;
}

function build_submit($s){
    $s=f_replace_once($s,'VERSION: v010','VERSION: v011','submit version');
    $s=f_replace_once($s,'LAST MODIFIED: 8/23/2026 2:28:00 pm','LAST MODIFIED: 8/24/2026 2:10:33 pm','submit timestamp');
    $s=f_replace_once($s,"\$scriptVersion = 'v006';","\$scriptVersion = 'v011';",'submit internal version');
    $needle=" * CHANGELOG:\n *\n";
    $chg=$needle
      ." * v011 (8/24/2026 2:10:33 pm)\n"
      ." * - FINALIZE: Permanent LP→RP submission bridge after successful TESTPHP8 edge-case validation.\n"
      ." * - PRESERVE: LP may be the base source for a Replacement Pick.\n"
      ." * - CLEANUP: Removes exact Be Like Biff / AJ Allmendinger / R08 historical test bypass.\n"
      ." * - SAFETY: RD deadline enforcement again uses real canonical schedule time only.\n"
      ." * - TRACE: Internal script version updated to v011.\n *\n";
    $s=f_replace_once($s,$needle,$chg,'submit changelog');

    $pattern='~    // Deadline protection belongs on the server too, not just on team\.php\.\n    // TESTPHP8 LP→RP edge-case fixture may use the controlled historical R08 window\..*?    if \(!\$rdDeadlineOpen\) \{\n        mrl_rd_reject\(\);\n    \}\n\n    \$pendingInfo =~s';
    $replacement="    // Deadline protection belongs on the server too, not just on team.php.\n    // Permanent behavior uses the real canonical race schedule only.\n    if (!mrl_lp_effective_race_is_open(\$raceYearInt, \$effectiveRace)) {\n        mrl_rd_reject();\n    }\n\n    \$pendingInfo =";
    $s=f_regex_once($s,$pattern,$replacement,'submit historical deadline bypass');
    return $s;
}

if($action==='install'&&!$errors){
  if(!$preflight){$errors[]='INSTALL REFUSED: preflight is not 100% clean.';}
  else{
    try{
      $teamNew=build_team($team);$submitNew=build_submit($submit);
      if(!is_dir($backupDir)&&!mkdir($backupDir,0755,true))throw new RuntimeException('Could not create backup directory.');
      if(!copy($teamPath,$backupDir.'/team.php.v026.before_finalization'))throw new RuntimeException('Could not back up team.php.');
      if(!copy($submitPath,$backupDir.'/submit-team-picks.php.v010.before_finalization'))throw new RuntimeException('Could not back up submit-team-picks.php.');
      if(file_put_contents($teamPath,$teamNew,LOCK_EX)===false)throw new RuntimeException('Could not write team.php v027.');
      if(file_put_contents($submitPath,$submitNew,LOCK_EX)===false){@copy($backupDir.'/team.php.v026.before_finalization',$teamPath);throw new RuntimeException('Could not write submit-team-picks.php v011; team rollback attempted.');}
      $ti=(string)@file_get_contents($teamPath);$si=(string)@file_get_contents($submitPath);$post=true;
      $post=f_check($checks,'POST team v027',substr_count($ti,'VERSION: v027')===1,'Installed')&&$post;
      $post=f_check($checks,'POST permanent LP/RP helper retained',substr_count($ti,'function teampage_get_rd_base_pick_row')===1,'Retained')&&$post;
      $post=f_check($checks,'POST time fixture removed',substr_count($ti,'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE')===0,'Absent')&&$post;
      $post=f_check($checks,'POST test banner removed',substr_count($ti,'TEST TIME OVERRIDE ACTIVE')===0,'Absent')&&$post;
      $post=f_check($checks,'POST obsolete update removed',substr_count($ti,'Update 2025-12-11 23:18:31')===0,'Absent')&&$post;
      $post=f_check($checks,'POST Chad FYI removed',substr_count($ti,'With the help of my friend Chad from ChatGPT')===0,'Absent')&&$post;
      $post=f_check($checks,'POST form panel',substr_count($ti,'class="mrl-pick-panel"')===1,'Present')&&$post;
      $post=f_check($checks,'POST collapsed previous years',substr_count($ti,'<details class="mrl-previous-years">')===1,'Present, default collapsed')&&$post;
      $post=f_check($checks,'POST submit v011',substr_count($si,'VERSION: v011')===1,'Installed')&&$post;
      $post=f_check($checks,'POST submit internal v011',substr_count($si,"\$scriptVersion = 'v011';")===1,'Trace version')&&$post;
      $post=f_check($checks,'POST LP base ID retained',substr_count($si,"pick_type IN ('SEG', 'ADJ', 'LP')")===1,'Retained')&&$post;
      $post=f_check($checks,'POST LP RD base retained',substr_count($si,"pick_type IN ('SEG','ADJ','LP')")===1,'Retained')&&$post;
      $post=f_check($checks,'POST historical edge marker removed',substr_count($si,'_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json')===0,'Absent')&&$post;
      $post=f_check($checks,'POST fixture ID removed',substr_count($si,'BE_LIKE_BIFF_LP_AJ_R06_R07')===0,'Absent')&&$post;
      if($post)$messages[]='PASS: team.php v027 and submit-team-picks.php v011 installed. Permanent LP→RP bridge retained; temporary test behavior removed.';else $errors[]='POSTFLIGHT FAILED. Use rollback before continuing.';
    }catch(Throwable $e){$errors[]='INSTALL FAILED: '.$e->getMessage();}
  }
}

if($action==='rollback'&&!$errors){
  $tb=$backupDir.'/team.php.v026.before_finalization';$sb=$backupDir.'/submit-team-picks.php.v010.before_finalization';
  if(!is_file($tb)||!is_file($sb))$errors[]='ROLLBACK REFUSED: backup files missing.';
  else if(copy($tb,$teamPath)&&copy($sb,$submitPath))$messages[]='ROLLBACK PASS: restored team.php v026 and submit-team-picks.php v010.';
  else $errors[]='ROLLBACK FAILED.';
}
$backupExists=is_file($backupDir.'/team.php.v026.before_finalization')&&is_file($backupDir.'/submit-team-picks.php.v010.before_finalization');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MRL LP→RP Finalization <?=$VERSION?></title>
<style>:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#111;color:#eee;font:14px Arial,sans-serif}.wrap{max-width:1450px;margin:auto;padding:14px}.banner{background:#20391d;border:1px solid #4d8244;border-radius:10px;padding:13px 15px}.banner h1{margin:0;color:#dcffd2}.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}.ok{background:#153b20;border:1px solid #3f8752;color:#caffd5;border-radius:8px;padding:10px 12px;margin-top:11px}.err{background:#481919;border:1px solid #a04949;color:#ffaaaa;border-radius:8px;padding:10px 12px;margin-top:11px}.warn{background:#4a2d00;border:1px solid #af7628;color:#ffd783;border-radius:8px;padding:10px 12px;margin-top:11px}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #333;padding:7px 8px;text-align:left}.pass{color:#78ef9c;font-weight:bold}.fail{color:#ff9292;font-weight:bold}button{padding:9px 14px;border-radius:6px;border:1px solid #5e8ab0;background:#2d6289;color:#fff;font-weight:bold;cursor:pointer}.install{background:#773724;border-color:#bd6d51}.rollback{background:#66561f;border-color:#ad9340}.path{font-family:Consolas,monospace;color:#d6e9ff}.small{font-size:12px;color:#bbb}</style></head><body><div class="wrap">
<div class="banner"><h1>MRL LP→RP Finalization Installer v001</h1><div>TESTPHP8 • team.php v026 → v027 • submit-team-picks.php v010 → v011</div></div>
<?php foreach($errors as $e):?><div class="err"><?=f_h($e)?></div><?php endforeach;?>
<?php foreach($messages as $m):?><div class="ok"><?=f_h($m)?></div><?php endforeach;?>
<div class="warn"><strong>Database and scheduler are untouched.</strong> The temporary LP→RP DB fixture remains until file finalization is verified.</div>
<div class="card"><h2>Preflight — <span class="<?=$preflight?'pass':'fail'?>"><?=$preflight?'PASS':'FAIL'?></span></h2>
<?php if($preflight&&$action==='preflight'):?><form method="post" onsubmit="return confirm('Install permanent LP→RP finalization files in TESTPHP8? Backups are created first. No database changes.');"><input type="hidden" name="action" value="install"><button class="install">Install LP→RP Finalization</button></form><?php endif;?>
<?php if($backupExists):?><form method="post" style="margin-top:10px" onsubmit="return confirm('Restore backed-up v026/v010 files?');"><input type="hidden" name="action" value="rollback"><button class="rollback">Rollback Both Files</button></form><?php endif;?>
<table style="margin-top:12px"><tr><th>Check</th><th>Status</th><th>Detail</th></tr><?php foreach($checks as $c):?><tr><td><?=f_h($c['label'])?></td><td class="<?=$c['ok']?'pass':'fail'?>"><?=$c['ok']?'PASS':'FAIL'?></td><td><?=f_h($c['detail'])?></td></tr><?php endforeach;?></table></div>
<div class="card"><h2>Scope</h2><ul><li>Keep permanent LP→RP base-row bridge.</li><li>Remove artificial R08 time-travel hook/banner.</li><li>Remove submit-side historical fixture bypass.</li><li>Remove both obsolete previous-years notices.</li><li>Add neutral softened form panel.</li><li>Previous Years Picks collapsed by default.</li><li>Backup folder: <span class="path"><?=f_h($backupDir)?></span></li></ul></div>
<div class="card small">After PASS: inspect team.php visually, expand/collapse Previous Years, confirm no artificial-time banner, and verify normal current-year content. Then we will reset the temporary LP→RP database fixture separately with an explicit DB warning.</div>
</div></body></html>
