<?php
declare(strict_types=1);
date_default_timezone_set('America/New_York');
/**
 * MRL team.php Section Panels Installer
 * VERSION: v001
 * LAST MODIFIED: 8/24/2026 11:47:20 pm
 * TESTPHP8 ONLY. team.php v032 -> v033.
 * Presentation only: Admin collapse/+ control, remove stars and dated League Info,
 * border Team Menu and current user info/picks. No DB/scheduler/scoring/LP/RP/privacy changes.
 */
$HOST='testphp8.manliusracingleague.com';
$host=strtolower((string)($_SERVER['HTTP_HOST']??''));
$root=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/');
$path=$root.'/team.php';
$bdir=$root.'/mrl_team_page_section_panels_v033_backup_20260824_114720pm';
$bpath=$bdir.'/team.php.v032.before_section_panels';
$errors=[];$messages=[];$checks=[];
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function ck(&$a,$l,$ok,$d){$a[]=[$l,(bool)$ok,$d];return (bool)$ok;}
function ro($s,$o,$n,$l){$c=substr_count($s,$o);if($c!==1)throw new RuntimeException($l.' expected once; found '.$c);return str_replace($o,$n,$s);}
$oldAdmin=<<<'X'
        <?php if ($isAdmin): ?>
            <br>
            *********************** Admin Menu ****************************
            <br>
            *******************************************************************
            <br>
            <a href="/race_results/weekly_standings.php" target="_blank">- Weekly Standings / scoring - Beta</a>
            <br>
            <a href="/admin_setup.php" target="_blank">- Setup Year/Segment & Submission Date</a>
            <br>
            <a href="/Paid_Status_Year.php" target="_blank">- See Paid Status for selectable year</a>
            <br>
            <a href="/team_view_as.php" target="_blank">- View Team page as alternate user</a>
            <br>
            <a href="/email.php" target="_blank">- List all email addresses - active & inactive</a>
            <br>
            <a href="/change_user_auth.php" target="_blank">- Toggle user status to make late picks or change driver</a>
            <br>
            <a href="/admin_pick_adjustment.php" target="_blank">- Approve LP as regular segment pick</a>
            <br>
            <a href="/addDrivers.php" target="_blank">- Add drivers for a new year.</a>
            <br>
            <a href="/current_segment_chart_by_entry_time.php" target="_blank">- Show current segment team chart sorted by Entry Time.</a>
            <br>
            *******************************************************************
            <br>
            <a href="<?php echo teampage_h($phpMyAdminUrl); ?>" target="_blank">- phpMyAdmin (Hostinger)</a>
            <br>
            <a href="<?php echo teampage_h($wpAdminUrl); ?>" target="_blank">- WP Admin (Hostinger)</a>
            <br>
            <a href="<?php echo teampage_h($hostingerBackupsUrl); ?>" target="_blank">- Backups (Hostinger)</a>
            <br>
            <a href="<?php echo teampage_h($hostingerPanelUrl); ?>" target="_blank">- hPanel (Hostinger)</a>
            <br>
            *******************************************************************
            <br>
        <?php endif; ?>
X;
$newAdmin=<<<'X'
        <?php if ($isAdmin): ?>
            <br>
            <details class="mrl-section-panel mrl-admin-menu-panel">
                <summary>Admin Menu</summary>
                <div class="mrl-section-panel-content">
                    <a href="/race_results/weekly_standings.php" target="_blank">- Weekly Standings / scoring - Beta</a><br>
                    <a href="/admin_setup.php" target="_blank">- Setup Year/Segment & Submission Date</a><br>
                    <a href="/Paid_Status_Year.php" target="_blank">- See Paid Status for selectable year</a><br>
                    <a href="/team_view_as.php" target="_blank">- View Team page as alternate user</a><br>
                    <a href="/email.php" target="_blank">- List all email addresses - active & inactive</a><br>
                    <a href="/change_user_auth.php" target="_blank">- Toggle user status to make late picks or change driver</a><br>
                    <a href="/admin_pick_adjustment.php" target="_blank">- Approve LP as regular segment pick</a><br>
                    <a href="/addDrivers.php" target="_blank">- Add drivers for a new year.</a><br>
                    <a href="/current_segment_chart_by_entry_time.php" target="_blank">- Show current segment team chart sorted by Entry Time.</a><br><br>
                    <a href="<?php echo teampage_h($phpMyAdminUrl); ?>" target="_blank">- phpMyAdmin (Hostinger)</a><br>
                    <a href="<?php echo teampage_h($wpAdminUrl); ?>" target="_blank">- WP Admin (Hostinger)</a><br>
                    <a href="<?php echo teampage_h($hostingerBackupsUrl); ?>" target="_blank">- Backups (Hostinger)</a><br>
                    <a href="<?php echo teampage_h($hostingerPanelUrl); ?>" target="_blank">- hPanel (Hostinger)</a>
                </div>
            </details>
            <br>
        <?php endif; ?>
X;
$oldTeam=<<<'X'
        ************************ Team Menu ******************************
        *******************************************************************
        <br>
        <a href="/showDrivers.php" target="_blank" rel="noopener noreferrer">- Driver Chart(s) - view, print for any year.</a><br>
        <a href="/team_chart.php" target="_blank" rel="noopener noreferrer">- Team Chart(s) - view, pdf, spreadsheet for any year/segment.</a><br>
        <a href="/submitted_teams.php" target="_blank" rel="noopener noreferrer">- Submitted Teams for Current Segment</a><br>
        <a href="/profile.php" target="_blank" rel="noopener noreferrer">- Your Profile page (change your email addresses, etc)</a> - Or use dropdown menu - upper left at your name.<br>
        <br>
        *******************************************************************
        <br>
X;
$newTeam=<<<'X'
        <div class="mrl-section-panel mrl-team-menu-panel">
            <div class="mrl-section-panel-title">Team Menu</div>
            <div class="mrl-section-panel-content">
                <a href="/showDrivers.php" target="_blank" rel="noopener noreferrer">- Driver Chart(s) - view, print for any year.</a><br>
                <a href="/team_chart.php" target="_blank" rel="noopener noreferrer">- Team Chart(s) - view, pdf, spreadsheet for any year/segment.</a><br>
                <a href="/submitted_teams.php" target="_blank" rel="noopener noreferrer">- Submitted Teams for Current Segment</a><br>
                <a href="/profile.php" target="_blank" rel="noopener noreferrer">- Your Profile page (change your email addresses, etc)</a> - Or use dropdown menu - upper left at your name.<br>
            </div>
        </div>
        <br>
X;
$oldLeague='        <u style="color:red;">League Info as of 2026-02-03 11:09:24</u><br><br>'."\n";
$oldChart="<a name=\"current_user_team_chart\"></a>\n<?php include 'current_user_team_chart.php'; ?>";
$newChart="<a name=\"current_user_team_chart\"></a>\n<div class=\"mrl-user-info-panel\">\n    <?php include 'current_user_team_chart.php'; ?>\n</div>";
$css=<<<'CSS'

        /* v033 presentation-only section framing */
        .mrl-section-panel{position:relative;box-sizing:border-box;width:100%;margin:12px 0 18px;padding:0;border:0;background:transparent}
        .mrl-section-panel::before{content:"";position:absolute;top:-10px;right:-22px;bottom:-10px;left:-22px;border:1px solid #666;border-radius:12px;background:rgba(255,255,255,.018);pointer-events:none;z-index:0}
        .mrl-section-panel>*{position:relative;z-index:1}
        .mrl-section-panel-title,.mrl-admin-menu-panel>summary{font-size:20pt;color:#dfcca8;line-height:120%}
        .mrl-section-panel-title{margin-bottom:8px}.mrl-section-panel-content{padding:4px 0 2px}
        .mrl-admin-menu-panel>summary{cursor:pointer;list-style:none;outline:none}
        .mrl-admin-menu-panel>summary::-webkit-details-marker{display:none}
        .mrl-admin-menu-panel>summary::before{content:"+ ";font-weight:normal}
        .mrl-admin-menu-panel[open]>summary::before{content:"− "}
        .mrl-admin-menu-panel[open]>summary{margin-bottom:8px}
        .mrl-user-info-panel{position:relative;box-sizing:border-box;width:100%;margin:18px 0 28px;padding:0;border:0;background:transparent}
        .mrl-user-info-panel::before{content:"";position:absolute;top:-10px;right:calc(10% - 22px);bottom:-10px;left:calc(10% - 22px);border:1px solid #666;border-radius:12px;background:rgba(255,255,255,.018);pointer-events:none;z-index:0}
        .mrl-user-info-panel>*{position:relative;z-index:1}
CSS;
if($host!==$HOST)$errors[]='REFUSED: TESTPHP8-only. Current host: '.$host;
if($root===''||!is_dir($root))$errors[]='Document root unavailable.';
$cur='';
if(!$errors){$cur=@file_get_contents($path);if($cur===false){$errors[]='Unable to read team.php.';$cur='';}}
$pre=!$errors;
if($cur!==''){
 $pre=ck($checks,'team.php version',substr_count($cur,'VERSION: v032')===1,'Expected v032')&&$pre;
 $pre=ck($checks,'v032 visual baseline',substr_count($cur,'.mrl-pick-panel::before')===1&&substr_count($cur,'.mrl-previous-years::before')===1,'Existing v032 panels present')&&$pre;
 $pre=ck($checks,'Admin Menu block',substr_count($cur,$oldAdmin)===1,'Exact admin block found once')&&$pre;
 $pre=ck($checks,'Team Menu block',substr_count($cur,$oldTeam)===1,'Exact team menu block found once')&&$pre;
 $pre=ck($checks,'League Info line',substr_count($cur,$oldLeague)===1,'Exact dated line found once')&&$pre;
 $pre=ck($checks,'User chart include',substr_count($cur,$oldChart)===1,'Exact include found once')&&$pre;
 $pre=ck($checks,'v033 not installed',substr_count($cur,'v033 presentation-only section framing')===0,'New CSS absent')&&$pre;
 $pre=ck($checks,'Style insertion',substr_count($cur,'    </style>')===1,'Single style close found')&&$pre;
}
function build33($s,$oa,$na,$ot,$nt,$ol,$oc,$nc,$css){
 $s=ro($s,'VERSION: v032','VERSION: v033','version');
 if(preg_match('/LAST MODIFIED:\s*[^\r\n]+/',$s,$m)!==1)throw new RuntimeException('LAST MODIFIED not found');
 $s=ro($s,$m[0],'LAST MODIFIED: 8/24/2026 11:47:20 pm','timestamp');
 $needle=" * CHANGELOG:\n *\n";
 $entry=$needle." * v033 (8/24/2026 11:47:20 pm)\n * - UI: Admin Menu collapsed by default with + / − control; admin-only gate preserved.\n * - UI: Removed decorative asterisks from Admin Menu and Team Menu.\n * - UI: Removed dated red League Info timestamp line.\n * - UI: Added matching borders to Team Menu and current user info/picks.\n * - PRESERVE: Pick, LP, RP, scoring, privacy and chart logic unchanged.\n *\n";
 $s=ro($s,$needle,$entry,'changelog');
 $s=ro($s,$oa,$na,'admin');$s=ro($s,$ot,$nt,'team menu');$s=ro($s,$ol,'','league line');$s=ro($s,$oc,$nc,'user chart');
 return ro($s,'    </style>',$css."\n    </style>",'css');
}
$action=(string)($_POST['action']??'');
if($action==='install'&&!$errors){
 if(!$pre)$errors[]='INSTALL REFUSED: preflight did not pass.';
 else try{
  $new=build33($cur,$oldAdmin,$newAdmin,$oldTeam,$newTeam,$oldLeague,$oldChart,$newChart,$css);
  if(!is_dir($bdir)&&!mkdir($bdir,0755,true))throw new RuntimeException('Could not create backup directory.');
  if(!copy($path,$bpath))throw new RuntimeException('Could not back up team.php v032.');
  if(file_put_contents($path,$new,LOCK_EX)===false)throw new RuntimeException('Could not write team.php v033.');
  $i=@file_get_contents($path);if($i===false)throw new RuntimeException('Could not re-read installed team.php.');
  $post=true;
  $post=ck($checks,'POST v033',substr_count($i,'VERSION: v033')===1,'Installed')&&$post;
  $post=ck($checks,'POST Admin collapse',substr_count($i,'<summary>Admin Menu</summary>')===1&&substr_count($i,'mrl-admin-menu-panel')>=1,'Present')&&$post;
  $post=ck($checks,'POST Team Menu border',substr_count($i,'mrl-team-menu-panel')===1,'Present')&&$post;
  $post=ck($checks,'POST League Info removed',substr_count($i,'League Info as of 2026-02-03 11:09:24')===0,'Removed')&&$post;
  $post=ck($checks,'POST user picks border',substr_count($i,'class="mrl-user-info-panel"')===1&&substr_count($i,"include 'current_user_team_chart.php';")===1,'Include preserved')&&$post;
  $post=ck($checks,'POST v032 panels preserved',substr_count($i,'.mrl-pick-panel::before')===1&&substr_count($i,'.mrl-previous-years::before')===1,'Preserved')&&$post;
  if($post)$messages[]='PASS: team.php v033 installed. Presentation-only section cleanup complete.';else $errors[]='POSTFLIGHT FAILED. Use rollback.';
 }catch(Throwable $e){$errors[]='INSTALL FAILED: '.$e->getMessage();}
}
if($action==='rollback'){
 if(!is_file($bpath))$errors[]='ROLLBACK REFUSED: v032 backup missing.';
 elseif(copy($bpath,$path))$messages[]='ROLLBACK PASS: restored team.php v032.';
 else $errors[]='ROLLBACK FAILED.';
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>MRL team.php v033 Installer</title><style>
body{margin:0;background:#111;color:#eee;font-family:Arial,sans-serif}.w{max-width:1220px;margin:16px auto;padding:0 14px}.b,.c{border:1px solid #444;border-radius:11px;padding:14px 18px;margin-top:14px;background:#1b1b1b}.b{border-color:#396d36;background:#173717}.b h1{margin:0;color:#e9ffd9}.ok{color:#78ef9c;font-weight:bold}.bad{color:#ff9292;font-weight:bold}.m{padding:10px 14px;border-radius:8px;margin-top:12px}.mp{background:#103d24;color:#aef2bf}.mf{background:#511818;color:#ffb1b1}table{width:100%;border-collapse:collapse}td{padding:7px 9px;border-bottom:1px solid #353535}button{padding:9px 14px;border-radius:6px;border:1px solid #5e8ab0;background:#2f713e;color:#fff;font-weight:bold;cursor:pointer}.rb{background:#7a3636}
</style></head><body><div class="w"><div class="b"><h1>MRL team.php Section Panels Installer v001</h1><div>TESTPHP8 • team.php v032 → v033 • presentation only</div></div>
<?php foreach($messages as $m):?><div class="m mp"><?=h($m)?></div><?php endforeach;?>
<?php foreach($errors as $e):?><div class="m mf"><?=h($e)?></div><?php endforeach;?>
<div class="c"><h2>Preflight — <?=$pre?'<span class="ok">PASS</span>':'<span class="bad">FAIL</span>'?></h2><table><?php foreach($checks as $c):?><tr><td><?=h($c[0])?></td><td class="<?=$c[1]?'ok':'bad'?>"><?=$c[1]?'PASS':'FAIL'?></td><td><?=h($c[2])?></td></tr><?php endforeach;?></table></div>
<div class="c"><b>Scope:</b> Admin collapse/+ control, remove stars, remove dated League Info line, border Team Menu and current user info/picks. No DB, scheduler, scoring, LP, RP or privacy changes.<br><br>Backup: <?=h($bpath)?></div>
<?php if($pre&&$action!=='install'):?><div class="c"><form method="post" onsubmit="return confirm('Install team.php v033? v032 will be backed up first.');"><input type="hidden" name="action" value="install"><button>Install team.php v033 Section Panels</button></form></div><?php endif;?>
<?php if(is_file($bpath)):?><div class="c"><form method="post" onsubmit="return confirm('Restore team.php v032?');"><input type="hidden" name="action" value="rollback"><button class="rb">Rollback team.php to v032</button></form></div><?php endif;?>
<div class="c">After PASS: inspect the Admin +/− panel, Team Menu border, user info/picks border, and confirm the dated League Info line is gone. View once as a non-admin user to confirm the Admin Menu remains absent.</div>
</div></body></html>
