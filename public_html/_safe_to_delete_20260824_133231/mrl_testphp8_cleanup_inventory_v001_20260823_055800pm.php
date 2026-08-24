<?php
declare(strict_types=1);

/**
 * MRL TESTPHP8 — CLEANUP INVENTORY SCANNER
 *
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 5:58:00 pm
 *
 * READ ONLY. NO FILE WRITES. NO DB WRITES. NO SCHEDULER CHANGES.
 */

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$errors = [];
$items = [];
$stats = [
    'scanned_files' => 0,
    'scanned_dirs' => 0,
    'candidates' => 0,
    'keep' => 0,
    'cleanup' => 0,
    'review' => 0,
];

function ci_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ci_rel($path, $root) {
    $path = str_replace('\\', '/', (string)$path);
    $root = rtrim(str_replace('\\', '/', (string)$root), '/');
    return strpos($path, $root) === 0 ? ltrim(substr($path, strlen($root)), '/') : $path;
}
function ci_contains_any($haystack, array $needles) {
    $haystack = strtolower((string)$haystack);
    foreach ($needles as $needle) {
        if ($needle !== '' && strpos($haystack, strtolower((string)$needle)) !== false) return true;
    }
    return false;
}
function ci_regex_any($value, array $patterns) {
    foreach ($patterns as $pattern) if (preg_match($pattern, (string)$value)) return true;
    return false;
}
function ci_human_bytes($bytes) {
    $bytes = (float)$bytes;
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($u)-1) { $bytes /= 1024; $i++; }
    return ($i === 0 ? number_format($bytes,0) : number_format($bytes,2)) . ' ' . $u[$i];
}
function ci_dir_summary($dir) {
    $files=0; $dirs=0; $bytes=0; $seen=0; $truncated=false;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $info) {
            $seen++;
            if ($seen > 5000) { $truncated=true; break; }
            if ($info->isDir()) $dirs++;
            elseif ($info->isFile()) { $files++; $bytes += (int)$info->getSize(); }
        }
    } catch (Throwable $e) {
        return ['files'=>0,'dirs'=>0,'bytes'=>0,'truncated'=>false,'error'=>$e->getMessage()];
    }
    return ['files'=>$files,'dirs'=>$dirs,'bytes'=>$bytes,'truncated'=>$truncated,'error'=>''];
}
function ci_classify($full, $rel, $isDir) {
    $name = strtolower(basename((string)$full));
    $relLower = strtolower((string)$rel);

    $current = [
        'lp_rp','lp-rp','be_like_biff','rd_pending','edge_case','edge-case',
        'submit_runtime','runtime_v011','structural_repair','base_row_bridge',
        'submit_bridge','late_submission','deadline_test'
    ];
    if (ci_contains_any($relLower, $current)) {
        return ['class'=>'KEEP UNTIL FINALIZATION','reason'=>'Appears tied to the current LP → RP / Replacement Pick test state.','rank'=>1];
    }

    if (ci_regex_any($name, [
        '/installer/i','/diagnostic/i','/test[_-]?switch/i','/test[_-]?fixture/i',
        '/time[_-]?travel/i','/debug/i','/preflight/i','/postflight/i',
        '/harness/i','/fixture[_-]?installer/i'
    ])) {
        return ['class'=>'LIKELY CLEANUP','reason'=>'Strong development/test utility filename.','rank'=>2];
    }

    if ($isDir && ci_regex_any($name, [
        '/^mrl_.*backup/i','/backup[_-]?[0-9]{8}/i','/_backup$/i','/backup$/i'
    ])) {
        return ['class'=>'LIKELY CLEANUP','reason'=>'Timestamped/development backup directory.','rank'=>2];
    }

    if (ci_regex_any($name, [
        '/\.bak$/i','/\.old$/i','/\.orig$/i','/\.tmp$/i','/\.temp$/i','/\.log$/i','/~$/'
    ])) {
        return ['class'=>'REVIEW','reason'=>'Backup/temp/log-like filename; inspect before deletion.','rank'=>3];
    }

    if (ci_contains_any($name, ['mrl_','rd_','rp_','snapshot','restore','backup','migration'])) {
        return ['class'=>'REVIEW','reason'=>'MRL utility/backup-like name; not automatically safe to remove.','rank'=>3];
    }

    return null;
}

if ($host !== $expectedHost) $errors[] = 'REFUSED: TESTPHP8-only. Current host: ' . $host;
if ($root === '' || !is_dir($root)) $errors[] = 'Document root is unavailable: ' . $root;

if (empty($errors)) {
    try {
        $dir = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($dir, function($current) {
            if ($current->isDir()) {
                return !in_array($current->getFilename(), ['.git','.svn','node_modules'], true);
            }
            return true;
        });
        $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($it as $info) {
            $full = $info->getPathname();
            $rel = ci_rel($full, $root);
            $isDir = $info->isDir();

            if ($isDir) $stats['scanned_dirs']++;
            elseif ($info->isFile()) $stats['scanned_files']++;
            else continue;

            $c = ci_classify($full, $rel, $isDir);
            if (!is_array($c)) continue;

            if ($isDir) {
                $s = ci_dir_summary($full);
                $detail = $s['files'].' files • '.$s['dirs'].' subdirs • '.ci_human_bytes($s['bytes']);
                if ($s['truncated']) $detail .= ' • summary truncated';
                if ($s['error'] !== '') $detail .= ' • '.$s['error'];
            } else {
                $detail = ci_human_bytes((int)$info->getSize());
            }

            $items[] = [
                'class'=>$c['class'],
                'rank'=>$c['rank'],
                'reason'=>$c['reason'],
                'type'=>$isDir?'DIR':'FILE',
                'relative'=>$rel,
                'modified'=>date('Y-m-d g:i:s a', $info->getMTime()),
                'detail'=>$detail,
            ];

            $stats['candidates']++;
            if ($c['class']==='KEEP UNTIL FINALIZATION') $stats['keep']++;
            elseif ($c['class']==='LIKELY CLEANUP') $stats['cleanup']++;
            else $stats['review']++;
        }
    } catch (Throwable $e) {
        $errors[] = 'Scan failed: ' . $e->getMessage();
    }
}

usort($items, function($a,$b){
    if ($a['rank'] !== $b['rank']) return $a['rank'] < $b['rank'] ? -1 : 1;
    return strcasecmp($a['relative'],$b['relative']);
});

$groups = ['KEEP UNTIL FINALIZATION'=>[],'LIKELY CLEANUP'=>[],'REVIEW'=>[]];
foreach ($items as $item) $groups[$item['class']][] = $item;

function ci_css($class) {
    if ($class==='KEEP UNTIL FINALIZATION') return 'keep';
    if ($class==='LIKELY CLEANUP') return 'cleanup';
    return 'review';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL TESTPHP8 Cleanup Inventory <?=$VERSION?></title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1600px;margin:0 auto;padding:14px}
.banner{background:#24351d;border:1px solid #547c3d;border-radius:10px;padding:12px 14px}
.banner h1{margin:0;color:#dfffcf;font-size:22px}.sub{font-size:12px;color:#bdd4ae;margin-top:4px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px;overflow:auto}
h2{margin:0 0 8px;color:#d5efc9;font-size:18px}
table{width:100%;border-collapse:collapse;min-width:1100px}
th,td{padding:6px 7px;border-bottom:1px solid #333;text-align:left;vertical-align:top}
th{background:#272727}
.keep{color:#ffd36b;font-weight:700}.cleanup{color:#69ef98;font-weight:700}.review{color:#ffb866;font-weight:700}
.err{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:10px 12px;margin-top:11px;color:#ff9b9b;font-weight:700}
.warn{background:#4a2b00;border:1px solid #b97920;border-radius:8px;padding:10px 12px;margin-top:11px;color:#ffd36b;font-weight:700}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px}
.metric{background:#161616;border:1px solid #393939;border-radius:7px;padding:9px 10px}.metric .n{font-size:21px;font-weight:700}
.path{font-family:Consolas,monospace;color:#d8e8ff;word-break:break-all}.note{font-size:12px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">
<div class="banner">
<h1>MRL TESTPHP8 CLEANUP INVENTORY SCANNER v001</h1>
<div class="sub">READ ONLY • generated 8/23/2026 5:58:00 pm ET • recursively scans TESTPHP8 including race_results</div>
</div>

<?php foreach ($errors as $e): ?><div class="err"><?=ci_h($e)?></div><?php endforeach; ?>

<div class="warn">NO FILES ARE DELETED BY THIS PAGE. Inventory only.</div>

<div class="card">
<h2>Scan Summary</h2>
<div class="summary">
<div class="metric"><div class="n"><?=$stats['scanned_files']?></div><div>Files scanned</div></div>
<div class="metric"><div class="n"><?=$stats['scanned_dirs']?></div><div>Directories scanned</div></div>
<div class="metric"><div class="n"><?=$stats['candidates']?></div><div>Candidate artifacts</div></div>
<div class="metric"><div class="n keep"><?=$stats['keep']?></div><div>Keep until finalization</div></div>
<div class="metric"><div class="n cleanup"><?=$stats['cleanup']?></div><div>Likely cleanup</div></div>
<div class="metric"><div class="n review"><?=$stats['review']?></div><div>Review</div></div>
</div>
</div>

<?php foreach (['KEEP UNTIL FINALIZATION','LIKELY CLEANUP','REVIEW'] as $g): ?>
<div class="card">
<h2 class="<?=ci_css($g)?>"><?=ci_h($g)?> — <?=count($groups[$g])?> item(s)</h2>
<?php if (empty($groups[$g])): ?>
<div class="note">No items in this category.</div>
<?php else: ?>
<table>
<tr><th>Type</th><th>Relative path</th><th>Modified</th><th>Size / contents</th><th>Reason</th></tr>
<?php foreach ($groups[$g] as $item): ?>
<tr>
<td><?=ci_h($item['type'])?></td>
<td class="path"><?=ci_h($item['relative'])?></td>
<td><?=ci_h($item['modified'])?></td>
<td><?=ci_h($item['detail'])?></td>
<td><?=ci_h($item['reason'])?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card note">
Next step: save/send this page. We will review the exact removal list before creating any cleanup installer.<br>
Root scanned: <span class="path"><?=ci_h($root)?></span><br>
READ ONLY: no deletes, no writes, no DB changes, no scheduler changes.
</div>
</div>
</body>
</html>
