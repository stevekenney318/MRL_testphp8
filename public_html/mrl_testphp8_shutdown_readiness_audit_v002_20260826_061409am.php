<?php
/**
 * MRL TESTPHP8 Shutdown Readiness Audit
 *
 * VERSION: v002
 * LAST MODIFIED: 8/26/2026 6:14:09 am
 *
 * CHANGELOG:
 * v002 (8/26/2026 6:14:09 am)
 * - Refined shutdown audit to focus ONLY on MRL custom/root material.
 * - Completely excludes WordPress trees (wp-admin, wp-includes, wp-content).
 * - Excludes formtools and other third-party/vendor trees from shutdown decisions.
 * - Excludes TESTPHP8/race_results from detailed comparison because that migration is complete
 *   and all future race_results work now belongs on LIVE.
 * - Applies already-decided outcomes instead of reopening them:
 *     default.php            -> DO NOT MIGRATE / legacy TEST-only page
 *     rebuild_year_index.php -> DO NOT MIGRATE / legacy TEST-only utility
 *     races.html             -> DO NOT MIGRATE / legacy, replaced by race_countdown.html
 *     yearly team charts     -> FUTURE RELOCATION to /team_charts/
 *     yearly Rules/Fees/etc. -> FUTURE RELOCATION to /league_info/
 *     audit/install/test tools and race_results documentation -> NON-PRODUCTION / no migration
 * - Leaves logout.php as the only mandatory held file from the original four because LIVE
 *   actively references logout.php and TEST/LIVE copies differ.
 * - Root-level custom files are the only files allowed to create a new shutdown blocker.
 * - Read only; JSON/TXT export.
 *
 * v001 (8/26/2026 6:05:40 am)
 * - Initial broad recursive shutdown-readiness audit.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
date_default_timezone_set('America/New_York');

const MRL_TSRA_VERSION = 'v002';
const MRL_TSRA_TITLE = 'MRL TESTPHP8 Focused Shutdown Readiness Audit';

function a_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function a_norm(string $p): string { return str_replace('\\','/',$p); }

function a_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function a_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256',$p);
    return is_string($h) ? $h : '';
}

function a_version(string $p): string {
    $t = a_read($p);
    if ($t === '') return '';
    foreach ([
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/'
    ] as $rx) {
        if (preg_match($rx,$t,$m)) return strtolower((string)$m[1]);
    }
    return '';
}

function a_info(string $p): ?array {
    if (!is_file($p)) return null;
    $m=@filemtime($p); $s=@filesize($p);
    return [
        'path'=>a_norm($p),
        'version'=>a_version($p),
        'sha256'=>a_sha($p),
        'size'=>is_int($s)?$s:null,
        'mtime'=>is_int($m)?date('Y-m-d H:i:s T',$m):null
    ];
}

function a_is_tool(string $name): bool {
    $s=strtolower($name);
    foreach ([
        'audit','installer','install_','preflight','diagnostic','debug','probe',
        'cleanup','quarantine','migration','compare','restore','backup','preview',
        'test_','_test','sweep'
    ] as $p) {
        if (strpos($s,$p)!==false) return true;
    }
    return false;
}

function a_is_year_team_chart(string $name): bool {
    return (bool)(
        preg_match('/^(20\d{2})_S\d+_Team_chart\.php$/i',$name) ||
        preg_match('/^(20\d{2}).*team.*chart.*\.php$/i',$name) ||
        preg_match('/team.*chart.*20\d{2}.*\.php$/i',$name) ||
        preg_match('/^team_chart.*20\d{2}.*\.php$/i',$name)
    );
}

function a_is_league_info(string $name): bool {
    return (bool)(preg_match('/20\d{2}/',$name) && preg_match('/rules|fees|schedule/i',$name));
}

function a_root_files(string $root): array {
    $out=[];
    foreach (scandir($root) ?: [] as $name) {
        if ($name==='.' || $name==='..') continue;
        $p=$root.'/'.$name;
        if (!is_file($p)) continue;
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if (!in_array($ext,['php','html','htm','md','txt','json','css','js'],true)) continue;
        $out[$name]=a_norm($p);
    }
    ksort($out,SORT_NATURAL|SORT_FLAG_CASE);
    return $out;
}

function a_live_refs(string $liveRoot,string $needle): array {
    $hits=[];
    $skipDirs=['testphp8','wp-admin','wp-includes','wp-content','formtools','race_results/_migration_backups'];
    $it=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($liveRoot,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($it as $fi) {
        if (!$fi->isFile()) continue;
        $full=a_norm($fi->getPathname());
        $rel=ltrim(substr($full,strlen(rtrim(a_norm($liveRoot),'/'))),'/');

        $skip=false;
        foreach ($skipDirs as $sd) {
            if ($rel===$sd || strpos($rel,$sd.'/')===0) { $skip=true; break; }
        }
        if ($skip) continue;

        $ext=strtolower(pathinfo($rel,PATHINFO_EXTENSION));
        if (!in_array($ext,['php','html','htm','js'],true)) continue;

        $lines=preg_split('/\R/',a_read($full));
        if (!is_array($lines)) continue;

        foreach ($lines as $i=>$line) {
            if (stripos($line,$needle)===false) continue;
            $trim=trim($line);
            $comment=(bool)preg_match('#^\s*(//|/\*|\*|<!--)#',$trim);
            $hits[]=['file'=>$rel,'line'=>$i+1,'text'=>$trim,'comment_only'=>$comment];
        }
    }
    return $hits;
}

$testRoot=rtrim(a_norm(__DIR__),'/');
$liveRoot=dirname($testRoot);
$expectedLocation=(bool)preg_match('#/public_html/testphp8$#',$testRoot);

$now=date('Y-m-d H:i:s T');
$stamp=strtolower(date('Ymd_hisA'));

$migrated15=[
    'admin_pick_adjustment.php','admin_setup.php','config_mrl.php',
    'current_segment_chart.php','current_segment_chart_by_entry_time.php',
    'current_user_team_chart.php','form-team-picks.php','pick_window_helper.php',
    'prior_year_user_team_chart.php','submit-team-picks.php','submitted_teams_count.php',
    'team-late-pick.php','team.php','team_chart.php','team_replacement_driver.php'
];

$environment=['conf.inc.php','config.php','dbconfig.php','wp-config.php','email.php'];

$knownOutcomes=[
    'default.php'=>[
        'classification'=>'KNOWN_DO_NOT_MIGRATE',
        'reason'=>'Previously reviewed: no active LIVE references. Legacy TEST-only page.'
    ],
    'rebuild_year_index.php'=>[
        'classification'=>'KNOWN_DO_NOT_MIGRATE',
        'reason'=>'Previously reviewed: no active LIVE references. Root TEST utility is not needed for production migration.'
    ],
    'races.html'=>[
        'classification'=>'KNOWN_DO_NOT_MIGRATE',
        'reason'=>'Confirmed legacy. Active race_countdown.html replaced its purpose; only known mention is a comment.'
    ],
    'README_Race_Results_System.md'=>[
        'classification'=>'KNOWN_DOCUMENTATION',
        'reason'=>'race_results documentation; race_results migration is complete and LIVE is authoritative.'
    ],
    'README2.html'=>[
        'classification'=>'KNOWN_DOCUMENTATION',
        'reason'=>'Documentation/readme material; not production migration code.'
    ]
];

$files=a_root_files($testRoot);
$rows=[];
$blockers=[];

$counts=[
    'root_files_considered'=>0,
    'verified_migrated'=>0,
    'identical'=>0,
    'environment_specific'=>0,
    'known_do_not_migrate'=>0,
    'known_documentation'=>0,
    'tooling_or_test_debris'=>0,
    'future_team_chart_relocation'=>0,
    'future_league_info_relocation'=>0,
    'logout_review'=>0,
    'unexplained_blockers'=>0
];

foreach ($files as $name=>$testPath) {
    $counts['root_files_considered']++;
    $livePath=$liveRoot.'/'.$name;
    $ti=a_info($testPath);
    $li=a_info($livePath);

    $class=''; $reason=''; $block=false;

    if ($name==='logout.php') {
        $class='LOGOUT_REVIEW_REQUIRED';
        $reason='LIVE actively uses logout.php and TEST/LIVE copies differ; deliberate final decision required.';
        $block=true; $counts['logout_review']++;

    } elseif (isset($knownOutcomes[$name])) {
        $class=$knownOutcomes[$name]['classification'];
        $reason=$knownOutcomes[$name]['reason'];
        if ($class==='KNOWN_DO_NOT_MIGRATE') $counts['known_do_not_migrate']++;
        else $counts['known_documentation']++;

    } elseif (in_array($name,$migrated15,true) && $li && $ti && $li['sha256']===$ti['sha256']) {
        $class='VERIFIED_MIGRATED';
        $reason='Completed migration; TEST and LIVE hashes match.';
        $counts['verified_migrated']++;

    } elseif (in_array($name,$environment,true)) {
        $class='ENVIRONMENT_SPECIFIC';
        $reason='Intentional environment difference; preserve LIVE.';
        $counts['environment_specific']++;

    } elseif (a_is_tool($name)) {
        $class='TOOLING_OR_TEST_DEBRIS';
        $reason='Audit/install/test/migration helper; not production migration code.';
        $counts['tooling_or_test_debris']++;

    } elseif (a_is_year_team_chart($name)) {
        $class='FUTURE_TEAM_CHART_RELOCATION';
        $reason='Already-decided future organization item for /team_charts/. Not a shutdown blocker.';
        $counts['future_team_chart_relocation']++;

    } elseif (a_is_league_info($name)) {
        $class='FUTURE_LEAGUE_INFO_RELOCATION';
        $reason='Already-decided future organization item for /league_info/. Not a shutdown blocker.';
        $counts['future_league_info_relocation']++;

    } elseif ($li && $ti && $li['sha256']===$ti['sha256']) {
        $class='IDENTICAL';
        $reason='TEST and LIVE are byte-for-byte identical.';
        $counts['identical']++;

    } else {
        $class='UNEXPLAINED_ROOT_DIFFERENCE';
        $reason=$li ? 'Root-level TEST and LIVE files differ and no prior decision explains it.'
                    : 'Root-level TEST-only file has no prior classification.';
        $block=true;
        $counts['unexplained_blockers']++;
    }

    $row=[
        'path'=>$name,
        'classification'=>$class,
        'reason'=>$reason,
        'blocks_shutdown'=>$block,
        'test'=>$ti,
        'live'=>$li
    ];
    $rows[]=$row;
    if ($block) $blockers[]=$row;
}

$logoutRefs=a_live_refs($liveRoot,'logout.php');
$logoutActive=array_values(array_filter($logoutRefs,static fn($x)=>!$x['comment_only']));

$knownOutcomeChecks=[
    'default.php'=>[
        'decision'=>'DO_NOT_MIGRATE',
        'active_live_reference_count'=>count(array_filter(a_live_refs($liveRoot,'default.php'),static fn($x)=>!$x['comment_only']))
    ],
    'rebuild_year_index.php'=>[
        'decision'=>'DO_NOT_MIGRATE',
        'active_live_reference_count'=>count(array_filter(a_live_refs($liveRoot,'rebuild_year_index.php'),static fn($x)=>!$x['comment_only']))
    ],
    'races.html'=>[
        'decision'=>'DO_NOT_MIGRATE',
        'active_live_reference_count'=>count(array_filter(a_live_refs($liveRoot,'races.html'),static fn($x)=>!$x['comment_only']))
    ],
];

$nonLogoutBlockers=array_values(array_filter($blockers,static fn($r)=>$r['path']!=='logout.php'));
$readyExceptLogout=$expectedLocation && count($nonLogoutBlockers)===0;

$report=[
    'report'=>MRL_TSRA_TITLE,
    'report_version'=>MRL_TSRA_VERSION,
    'generated_at'=>$now,
    'read_only'=>true,
    'expected_location'=>$expectedLocation,
    'scope'=>[
        'detailed_scan'=>'TESTPHP8 root-level custom files only',
        'excluded_from_decision_scan'=>[
            'wp-admin/','wp-includes/','wp-content/','formtools/','race_results/'
        ],
        'why'=>'These trees are WordPress/third-party or already-completed race_results territory and must not create shutdown noise.'
    ],
    'summary'=>$counts,
    'shutdown_status'=>[
        'ready_except_logout'=>$readyExceptLogout,
        'status'=>$readyExceptLogout
            ? 'PASS - ONLY logout.php DECISION REMAINS'
            : 'HOLD - logout.php PLUS OTHER ROOT DECISIONS REMAIN',
        'remaining_blockers'=>count($blockers),
        'non_logout_blockers'=>count($nonLogoutBlockers)
    ],
    'known_outcomes'=>$knownOutcomeChecks,
    'logout_review'=>[
        'test'=>a_info($testRoot.'/logout.php'),
        'live'=>a_info($liveRoot.'/logout.php'),
        'same_hash'=>a_sha($testRoot.'/logout.php')===a_sha($liveRoot.'/logout.php'),
        'active_live_references'=>$logoutActive,
        'active_live_reference_count'=>count($logoutActive)
    ],
    'blockers'=>$blockers,
    'all_root_rows'=>$rows,
    'known_future_work'=>[
        'yearly_team_charts'=>'Future relocation to /team_charts/; not part of shutdown migration.',
        'yearly_league_info'=>'Future relocation to /league_info/; not part of shutdown migration.',
        'race_results'=>'Migration complete; future work happens on LIVE.'
    ],
    'safety'=>[
        'files_changed'=>false,
        'database_writes'=>false,
        'scheduler_changes'=>false,
        'wordpress_scanned_for_migration'=>false,
        'race_results_changed'=>false
    ]
];

function a_txt(array $r): string {
    $o=[];
    $o[]=MRL_TSRA_TITLE;
    $o[]='Version: '.MRL_TSRA_VERSION;
    $o[]='Generated: '.$r['generated_at'];
    $o[]='';
    $o[]='STATUS: '.$r['shutdown_status']['status'];
    $o[]='Remaining blockers: '.$r['shutdown_status']['remaining_blockers'];
    $o[]='Non-logout blockers: '.$r['shutdown_status']['non_logout_blockers'];
    $o[]='';
    $o[]='BLOCKERS';
    foreach ($r['blockers'] as $b) $o[]='- '.$b['path'].' | '.$b['classification'];
    return implode("\r\n",$o)."\r\n";
}

$format=strtolower((string)($_GET['format']??''));

if ($format==='json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_shutdown_readiness_v002_'.$stamp.'.json"');
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit;
}
if ($format==='txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_shutdown_readiness_v002_'.$stamp.'.txt"');
    echo a_txt($report);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= a_h(MRL_TSRA_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1500px;margin:auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px}.small{color:var(--muted)}.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:10px 8px 0 0}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}
code{color:#bddcff;background:#111318;padding:2px 5px;border-radius:4px}
</style>
</head>
<body><div class="wrap">
<div class="panel">
<h1><?= a_h(MRL_TSRA_TITLE) ?></h1>
<div class="small">v002 · <?= a_h($now) ?> · READ ONLY · root custom files only</div>
<div class="summary">
<div class="pill">Status: <span class="<?= $readyExceptLogout?'pass':'warn' ?>"><?= a_h($report['shutdown_status']['status']) ?></span></div>
<div class="pill">Remaining blockers: <span class="warn"><?= count($blockers) ?></span></div>
<div class="pill">Non-logout blockers: <span class="<?= count($nonLogoutBlockers)===0?'pass':'warn' ?>"><?= count($nonLogoutBlockers) ?></span></div>
<div class="pill">Verified migrated: <span class="pass"><?= $counts['verified_migrated'] ?></span></div>
<div class="pill">Identical: <span class="pass"><?= $counts['identical'] ?></span></div>
</div>
<a class="button" href="?format=json">Download JSON Results</a>
<a class="button" href="?format=txt">Download TXT Results</a>
</div>

<div class="panel">
<h2>Already-decided outcomes</h2>
<table><thead><tr><th>File</th><th>Decision</th><th>Active LIVE references</th></tr></thead><tbody>
<?php foreach ($knownOutcomeChecks as $name=>$r): ?>
<tr><td><code><?= a_h($name) ?></code></td><td class="pass"><?= a_h($r['decision']) ?></td><td><?= (int)$r['active_live_reference_count'] ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>

<div class="panel">
<h2>logout.php — deliberate final decision</h2>
<p>Active LIVE references: <strong><?= count($logoutActive) ?></strong></p>
<p>TEST/LIVE hash: <strong class="<?= $report['logout_review']['same_hash']?'pass':'warn' ?>"><?= $report['logout_review']['same_hash']?'MATCH':'DIFFERENT' ?></strong></p>
</div>

<div class="panel">
<h2>Other root-level blockers</h2>
<?php if (count($nonLogoutBlockers)===0): ?>
<p class="pass">PASS — no other unexplained root-level MRL files remain.</p>
<?php else: ?>
<table><thead><tr><th>File</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($nonLogoutBlockers as $r): ?>
<tr><td><code><?= a_h($r['path']) ?></code></td><td><?= a_h($r['reason']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>

<div class="panel">
<h2>Excluded on purpose</h2>
<p><code>wp-admin/</code>, <code>wp-includes/</code>, <code>wp-content/</code>, <code>formtools/</code>, and <code>race_results/</code> do not participate in this shutdown decision.</p>
<p>Yearly team charts and yearly Rules/Fees/Schedule files remain known future organization work, not migration blockers.</p>
</div>
</div></body></html>
