<?php
/**
 * MRL race_results TESTPHP8 ↔ LIVE Filesystem Comparison
 *
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 6:55:14 pm
 *
 * PURPOSE:
 * Read-only direct filesystem comparison of:
 *
 * TEST:
 * /home/u809830586/domains/manliusracingleague.com/public_html/testphp8/race_results
 *
 * LIVE:
 * /home/u809830586/domains/manliusracingleague.com/public_html/race_results
 *
 * This report is intended to define the migration/reconciliation map.
 *
 * SAFETY:
 * - TESTPHP8 host only.
 * - READ-ONLY.
 * - No file writes, moves, renames, deletes, or database writes.
 * - Does not stop or modify either scheduler.
 * - Existing _safe_to_delete_* quarantine trees are ignored.
 * - PHP 7.3 compatible.
 *
 * OUTPUT:
 * - On-screen summary + difference table
 * - Timestamped JSON export (preferred)
 * - Timestamped TXT export
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';

$testRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
$liveRoot = dirname($testRoot);

$testRR = $testRoot . '/race_results';
$liveRR = $liveRoot . '/race_results';

$generatedAt = date('Y-m-d H:i:s T');
$exportStamp = strtolower(date('Ymd_hisA'));

$errors = array();

function mrlrrc_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlrrc_norm_rel($base, $path) {
    $rel = substr($path, strlen($base));
    return ltrim(str_replace('\\', '/', $rel), '/');
}

function mrlrrc_should_ignore($rel) {
    $parts = explode('/', str_replace('\\', '/', $rel));

    foreach ($parts as $part) {
        if (strpos($part, '_safe_to_delete_') === 0) {
            return true;
        }
    }

    return false;
}

function mrlrrc_read_version($path) {
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $data = @file_get_contents($path, false, null, 0, 20000);

    if ($data === false) {
        return '';
    }

    if (preg_match('/VERSION:\s*(v[0-9]+)/i', $data, $m) === 1) {
        return $m[1];
    }

    if (preg_match('/\bv([0-9]{3})\b/', $data, $m) === 1) {
        return 'v' . $m[1];
    }

    return '';
}

function mrlrrc_tags($rel) {
    $p = strtolower($rel);
    $tags = array();

    if (strpos($p, 'jayski') !== false) {
        $tags[] = 'JAYSKI';
    }

    if (
        strpos($p, 'racing-reference') !== false ||
        strpos($p, 'racing_reference') !== false ||
        strpos($p, 'racingreference') !== false ||
        strpos($p, 'race_reference') !== false
    ) {
        $tags[] = 'RACING_REFERENCE';
    }

    if (
        strpos($p, 'finish_confirmation') !== false ||
        strpos($p, 'finish-confirmation') !== false ||
        strpos($p, 'confirmation_monitor') !== false ||
        strpos($p, 'race_finish') !== false
    ) {
        $tags[] = 'FINISH_CONFIRMATION';
    }

    if (
        strpos($p, '_scheduler/') === 0 ||
        strpos($p, '/_scheduler/') !== false ||
        basename($p) === '_scheduler'
    ) {
        $tags[] = 'SCHEDULER';
    }

    if (preg_match('#^(20[0-9]{2})/#', $p) === 1) {
        $tags[] = 'YEAR_RACE_DATA';
    }

    if (
        strpos($p, 'snapshot') !== false ||
        strpos($p, 'cache') !== false ||
        strpos($p, 'state') !== false ||
        strpos($p, 'history') !== false
    ) {
        $tags[] = 'RUNTIME_OR_SNAPSHOT';
    }

    if (strpos($p, 'monitor') !== false) {
        $tags[] = 'MONITOR';
    }

    if (strpos($p, 'helper') !== false) {
        $tags[] = 'HELPER';
    }

    if (strpos($p, 'readme') !== false) {
        $tags[] = 'DOCUMENTATION';
    }

    return array_values(array_unique($tags));
}

function mrlrrc_inventory($base) {
    $files = array();
    $dirs = array();
    $scanErrors = array();

    if (!is_dir($base) || !is_readable($base)) {
        $scanErrors[] = 'Unreadable directory: ' . $base;
        return array(
            'files' => $files,
            'dirs' => $dirs,
            'errors' => $scanErrors
        );
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $info) {
            $path = $info->getPathname();
            $rel = mrlrrc_norm_rel($base, $path);

            if (mrlrrc_should_ignore($rel)) {
                continue;
            }

            if ($info->isDir()) {
                $dirs[$rel] = array(
                    'path' => $rel,
                    'mtime' => @filemtime($path)
                );
                continue;
            }

            if (!$info->isFile()) {
                continue;
            }

            $size = @filesize($path);
            $mtime = @filemtime($path);
            $hash = is_readable($path) ? @hash_file('sha256', $path) : '';

            $files[$rel] = array(
                'path' => $rel,
                'size' => ($size === false ? null : $size),
                'mtime' => ($mtime === false ? null : $mtime),
                'sha256' => ($hash === false ? '' : $hash),
                'version' => mrlrrc_read_version($path),
                'extension' => strtolower(pathinfo($rel, PATHINFO_EXTENSION)),
                'tags' => mrlrrc_tags($rel)
            );
        }
    } catch (Exception $e) {
        $scanErrors[] = $e->getMessage();
    }

    ksort($files);
    ksort($dirs);

    return array(
        'files' => $files,
        'dirs' => $dirs,
        'errors' => $scanErrors
    );
}

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: TESTPHP8-only. Current host: ' . $host;
}

if ($testRoot === '' || !is_dir($testRoot)) {
    $errors[] = 'TESTPHP8 document root unavailable.';
}

if (!is_dir($testRR) || !is_readable($testRR)) {
    $errors[] = 'TESTPHP8 race_results is unavailable or unreadable.';
}

if (!is_dir($liveRR) || !is_readable($liveRR)) {
    $errors[] = 'LIVE race_results is unavailable or unreadable.';
}

$testInventory = array('files'=>array(), 'dirs'=>array(), 'errors'=>array());
$liveInventory = array('files'=>array(), 'dirs'=>array(), 'errors'=>array());

if (empty($errors)) {
    $testInventory = mrlrrc_inventory($testRR);
    $liveInventory = mrlrrc_inventory($liveRR);

    foreach ($testInventory['errors'] as $e) {
        $errors[] = 'TEST scan: ' . $e;
    }

    foreach ($liveInventory['errors'] as $e) {
        $errors[] = 'LIVE scan: ' . $e;
    }
}

$allPaths = array();

foreach ($testInventory['files'] as $rel => $row) {
    $allPaths[$rel] = true;
}

foreach ($liveInventory['files'] as $rel => $row) {
    $allPaths[$rel] = true;
}

ksort($allPaths);

$rows = array();
$counts = array(
    'identical' => 0,
    'different' => 0,
    'test_only' => 0,
    'live_only' => 0,
    'total_unique_file_paths' => count($allPaths),
    'test_files' => count($testInventory['files']),
    'live_files' => count($liveInventory['files']),
    'test_dirs' => count($testInventory['dirs']),
    'live_dirs' => count($liveInventory['dirs'])
);

foreach ($allPaths as $rel => $dummy) {
    $hasTest = isset($testInventory['files'][$rel]);
    $hasLive = isset($liveInventory['files'][$rel]);

    $test = $hasTest ? $testInventory['files'][$rel] : null;
    $live = $hasLive ? $liveInventory['files'][$rel] : null;

    if ($hasTest && $hasLive) {
        if ($test['sha256'] !== '' && $test['sha256'] === $live['sha256']) {
            $status = 'IDENTICAL';
            $counts['identical']++;
        } else {
            $status = 'DIFFERENT';
            $counts['different']++;
        }
    } elseif ($hasTest) {
        $status = 'TEST_ONLY';
        $counts['test_only']++;
    } else {
        $status = 'LIVE_ONLY';
        $counts['live_only']++;
    }

    $tags = mrlrrc_tags($rel);

    /*
     * Review hint only. This is not the final migration classification.
     */
    if ($status === 'IDENTICAL') {
        $reviewHint = 'NO_ACTION';
    } elseif (
        in_array('JAYSKI', $tags, true) ||
        in_array('RACING_REFERENCE', $tags, true) ||
        in_array('FINISH_CONFIRMATION', $tags, true)
    ) {
        $reviewHint = 'RACE_FINISH_EXPERIMENT_REVIEW';
    } elseif (in_array('YEAR_RACE_DATA', $tags, true)) {
        $reviewHint = 'RUNTIME_DATA_REVIEW';
    } elseif (in_array('SCHEDULER', $tags, true)) {
        $reviewHint = 'SCHEDULER_REVIEW';
    } else {
        $reviewHint = 'MIGRATION_REVIEW';
    }

    $rows[] = array(
        'path' => $rel,
        'status' => $status,
        'review_hint' => $reviewHint,
        'tags' => $tags,
        'test' => $test,
        'live' => $live
    );
}

/*
 * Empty-directory differences are tracked separately.
 */
$dirAll = array();

foreach ($testInventory['dirs'] as $rel => $row) {
    $dirAll[$rel] = true;
}

foreach ($liveInventory['dirs'] as $rel => $row) {
    $dirAll[$rel] = true;
}

ksort($dirAll);

$dirRows = array();
$dirCounts = array(
    'both' => 0,
    'test_only' => 0,
    'live_only' => 0
);

foreach ($dirAll as $rel => $dummy) {
    $hasTest = isset($testInventory['dirs'][$rel]);
    $hasLive = isset($liveInventory['dirs'][$rel]);

    if ($hasTest && $hasLive) {
        $status = 'BOTH';
        $dirCounts['both']++;
    } elseif ($hasTest) {
        $status = 'TEST_ONLY';
        $dirCounts['test_only']++;
    } else {
        $status = 'LIVE_ONLY';
        $dirCounts['live_only']++;
    }

    $dirRows[] = array(
        'path' => $rel,
        'status' => $status
    );
}

$differences = array();
foreach ($rows as $r) {
    if ($r['status'] !== 'IDENTICAL') {
        $differences[] = $r;
    }
}

$comparisonPass = empty($errors);

$resultData = array(
    'report' => 'MRL race_results TESTPHP8 vs LIVE Filesystem Comparison',
    'report_version' => 'v001',
    'generated_at' => $generatedAt,
    'host' => $host,
    'test_root' => $testRR,
    'live_root' => $liveRR,
    'read_only' => true,
    'comparison_completed' => $comparisonPass,
    'errors' => $errors,
    'summary' => $counts,
    'directory_summary' => $dirCounts,
    'differences' => $differences,
    'all_files' => $rows,
    'directory_presence' => $dirRows
);

$export = isset($_GET['export']) ? strtolower((string)$_GET['export']) : '';

if ($export === 'json') {
    $filename = 'mrl_race_results_test_vs_live_comparison_' . $exportStamp . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    echo json_encode($resultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($export === 'txt') {
    $filename = 'mrl_race_results_test_vs_live_comparison_' . $exportStamp . '.txt';

    $lines = array();
    $lines[] = 'MRL race_results TESTPHP8 vs LIVE Filesystem Comparison v001';
    $lines[] = 'Generated: ' . $generatedAt;
    $lines[] = 'TEST: ' . $testRR;
    $lines[] = 'LIVE: ' . $liveRR;
    $lines[] = '';
    $lines[] = 'SUMMARY';
    $lines[] = 'TEST files: ' . $counts['test_files'];
    $lines[] = 'LIVE files: ' . $counts['live_files'];
    $lines[] = 'Identical: ' . $counts['identical'];
    $lines[] = 'Different: ' . $counts['different'];
    $lines[] = 'TEST only: ' . $counts['test_only'];
    $lines[] = 'LIVE only: ' . $counts['live_only'];
    $lines[] = '';
    $lines[] = 'DIFFERENCES';

    foreach ($differences as $r) {
        $lines[] =
            $r['status'] .
            ' | ' . $r['review_hint'] .
            ' | ' . $r['path'] .
            ' | TEST_VERSION=' . ($r['test'] ? $r['test']['version'] : '') .
            ' | LIVE_VERSION=' . ($r['live'] ? $r['live']['version'] : '') .
            ' | TAGS=' . implode(',', $r['tags']);
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    echo implode("\r\n", $lines);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL race_results TESTPHP8 ↔ LIVE Comparison v001</title>
<style>
body{margin:0;background:#111;color:#eee;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1650px;margin:16px auto;padding:0 14px}
.banner{border:1px solid #396d36;background:#173717;border-radius:12px;padding:14px 18px}
.banner h1{margin:0 0 4px;color:#e9ffd9;font-size:28px}
.sub{color:#d9efc9}
.notice{margin-top:12px;border:1px solid #2f6590;background:#102c46;border-radius:9px;padding:11px 14px;color:#b9ddff}
.bad{margin-top:12px;border:1px solid #ad3e3e;background:#511818;border-radius:8px;padding:11px 14px;color:#ffb1b1}
.card{margin-top:14px;border:1px solid #444;background:#1b1b1b;border-radius:11px;padding:14px 18px}
h2{margin:2px 0 12px;font-size:24px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:7px 8px;border-bottom:1px solid #353535;text-align:left;vertical-align:top}
th{background:#242424}
.pass{color:#78ef9c;font-weight:bold}
.diff{color:#ffd36f;font-weight:bold}
.testonly{color:#8bc6ff;font-weight:bold}
.liveonly{color:#ff9cb4;font-weight:bold}
.path{font-family:Consolas,monospace;color:#cddcff;word-break:break-all}
.hash{font-family:Consolas,monospace;font-size:11px;color:#aaa;word-break:break-all}
.summary{display:flex;gap:10px;flex-wrap:wrap}
.pill{border:1px solid #555;border-radius:999px;padding:7px 11px;background:#222}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.btn{display:inline-block;padding:9px 14px;border:1px solid #5e8ab0;border-radius:7px;background:#234c70;color:#fff;text-decoration:none;font-weight:bold}
.btn:hover{background:#2f628f}
.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL race_results TESTPHP8 ↔ LIVE Filesystem Comparison v001</h1>
<div class="sub">READ-ONLY • direct server filesystem comparison • no scheduler changes</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="bad"><?=mrlrrc_h($e)?></div>
<?php endforeach; ?>

<div class="notice">
This compares the actual files under TESTPHP8/race_results and LIVE/race_results.
Existing quarantine trees are ignored. Review hints are only aids — they are not final migration decisions.
</div>

<div class="card">
<h2>Comparison summary</h2>
<div class="summary">
<div class="pill">TEST files: <strong><?=$counts['test_files']?></strong></div>
<div class="pill">LIVE files: <strong><?=$counts['live_files']?></strong></div>
<div class="pill">Identical: <strong><?=$counts['identical']?></strong></div>
<div class="pill">Different: <strong><?=$counts['different']?></strong></div>
<div class="pill">TEST only: <strong><?=$counts['test_only']?></strong></div>
<div class="pill">LIVE only: <strong><?=$counts['live_only']?></strong></div>
</div>

<div class="actions">
<a class="btn" href="?export=json">Download JSON Results</a>
<a class="btn" href="?export=txt">Download TXT Results</a>
</div>

<div class="small" style="margin-top:8px">
JSON is the preferred export for migration analysis. The filename includes the server-time timestamp.
</div>
</div>

<div class="card">
<h2>Differences only</h2>
<?php if (count($differences) === 0): ?>
<div class="pass">No file differences found.</div>
<?php else: ?>
<table>
<thead>
<tr>
<th style="width:110px">Status</th>
<th>Path</th>
<th style="width:180px">Review hint</th>
<th style="width:90px">TEST ver</th>
<th style="width:90px">LIVE ver</th>
<th style="width:250px">Tags</th>
</tr>
</thead>
<tbody>
<?php foreach ($differences as $r): ?>
<?php
$statusClass = 'diff';
if ($r['status'] === 'TEST_ONLY') $statusClass = 'testonly';
if ($r['status'] === 'LIVE_ONLY') $statusClass = 'liveonly';
?>
<tr>
<td class="<?=$statusClass?>"><?=mrlrrc_h($r['status'])?></td>
<td class="path"><?=mrlrrc_h($r['path'])?></td>
<td><?=mrlrrc_h($r['review_hint'])?></td>
<td><?=mrlrrc_h($r['test'] ? $r['test']['version'] : '')?></td>
<td><?=mrlrrc_h($r['live'] ? $r['live']['version'] : '')?></td>
<td><?=mrlrrc_h(implode(', ', $r['tags']))?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="card small">
<strong>Next step:</strong> upload the JSON export here. I’ll use the complete path/hash/version data to classify each meaningful difference as
<strong>MIGRATE / KEEP LIVE / OBSOLETE / TEST-ONLY</strong>,
with special attention to the old Racing-Reference/Jayski race-finish experiments and scheduler-related files.
</div>

</div>
</body>
</html>
