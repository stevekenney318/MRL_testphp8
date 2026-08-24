<?php
declare(strict_types=1);

/**
 * MRL TESTPHP8 — CLEANUP INVENTORY SCANNER
 *
 * VERSION: v002
 * LAST MODIFIED: 8/24/2026 1:57:32 am
 *
 * CHANGES FROM v001
 * -----------------
 * - Completely excludes WordPress trees:
 *     wp-admin/
 *     wp-content/
 *     wp-includes/
 * - Adds CSV export.
 * - Adds TXT export.
 * - Export filenames use a meaningful timestamp with milliseconds:
 *     MRL_TESTPHP8_Cleanup_Inventory_YYYYMMDD_HHMMSSmmm.csv
 *     MRL_TESTPHP8_Cleanup_Inventory_YYYYMMDD_HHMMSSmmm.txt
 * - Exports are streamed directly to the browser; no export file is written
 *   onto the TESTPHP8 server.
 * - Still READ ONLY:
 *     no file writes
 *     no deletes
 *     no renames
 *     no DB writes
 *     no scheduler changes
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

$VERSION = 'v002';
$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$errors = array();
$items = array();
$stats = array(
    'scanned_files' => 0,
    'scanned_dirs' => 0,
    'candidates' => 0,
    'keep' => 0,
    'cleanup' => 0,
    'review' => 0,
);

function ci_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ci_rel($path, $root)
{
    $path = str_replace('\\', '/', (string)$path);
    $root = rtrim(str_replace('\\', '/', (string)$root), '/');

    if (strpos($path, $root) === 0) {
        return ltrim((string)substr($path, strlen($root)), '/');
    }

    return $path;
}

function ci_contains_any($haystack, array $needles)
{
    $haystack = strtolower((string)$haystack);

    foreach ($needles as $needle) {
        $needle = strtolower((string)$needle);

        if ($needle !== '' && strpos($haystack, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function ci_regex_any($value, array $patterns)
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, (string)$value)) {
            return true;
        }
    }

    return false;
}

function ci_human_bytes($bytes)
{
    $bytes = (float)$bytes;
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    if ($i === 0) {
        return number_format($bytes, 0) . ' ' . $units[$i];
    }

    return number_format($bytes, 2) . ' ' . $units[$i];
}

function ci_timestamp_ms()
{
    $now = microtime(true);
    $seconds = (int)floor($now);
    $milliseconds = (int)floor(($now - $seconds) * 1000);

    return date('Ymd_His', $seconds) . str_pad((string)$milliseconds, 3, '0', STR_PAD_LEFT);
}

function ci_dir_summary($dir)
{
    $files = 0;
    $dirs = 0;
    $bytes = 0;
    $seen = 0;
    $truncated = false;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $dir,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $info) {
            $seen++;

            if ($seen > 5000) {
                $truncated = true;
                break;
            }

            if ($info->isDir()) {
                $dirs++;
            } elseif ($info->isFile()) {
                $files++;
                $bytes += (int)$info->getSize();
            }
        }
    } catch (Throwable $e) {
        return array(
            'files' => 0,
            'dirs' => 0,
            'bytes' => 0,
            'truncated' => false,
            'error' => $e->getMessage(),
        );
    }

    return array(
        'files' => $files,
        'dirs' => $dirs,
        'bytes' => $bytes,
        'truncated' => $truncated,
        'error' => '',
    );
}

function ci_classify($fullPath, $relativePath, $isDir)
{
    $name = strtolower(basename((string)$fullPath));
    $relLower = strtolower((string)$relativePath);

    /*
     * Current LP → RP test/finalization artifacts.
     * Conservative classification: keep these until the actual reset/finalization.
     */
    $currentTestMarkers = array(
        'lp_rp',
        'lp-rp',
        'be_like_biff',
        'rd_pending',
        'edge_case',
        'edge-case',
        'submit_runtime',
        'runtime_v011',
        'structural_repair',
        'base_row_bridge',
        'submit_bridge',
        'late_submission',
        'deadline_test'
    );

    if (ci_contains_any($relLower, $currentTestMarkers)) {
        return array(
            'class' => 'KEEP UNTIL FINALIZATION',
            'reason' => 'Appears tied to the current LP → RP / Replacement Pick test state.',
            'rank' => 1
        );
    }

    /*
     * Strong development/test utility naming.
     */
    if (ci_regex_any($name, array(
        '/installer/i',
        '/diagnostic/i',
        '/test[_-]?switch/i',
        '/test[_-]?fixture/i',
        '/time[_-]?travel/i',
        '/debug/i',
        '/preflight/i',
        '/postflight/i',
        '/harness/i',
        '/fixture[_-]?installer/i'
    ))) {
        return array(
            'class' => 'LIKELY CLEANUP',
            'reason' => 'Strong development/test utility filename.',
            'rank' => 2
        );
    }

    /*
     * Strong backup-directory naming.
     */
    if ($isDir && ci_regex_any($name, array(
        '/^mrl_.*backup/i',
        '/backup[_-]?[0-9]{8}/i',
        '/_backup$/i',
        '/backup$/i'
    ))) {
        return array(
            'class' => 'LIKELY CLEANUP',
            'reason' => 'Timestamped/development backup directory.',
            'rank' => 2
        );
    }

    /*
     * Generic temporary/log/old-copy naming.
     */
    if (ci_regex_any($name, array(
        '/\.bak$/i',
        '/\.old$/i',
        '/\.orig$/i',
        '/\.tmp$/i',
        '/\.temp$/i',
        '/\.log$/i',
        '/~$/'
    ))) {
        return array(
            'class' => 'REVIEW',
            'reason' => 'Backup/temp/log-like filename; inspect before deletion.',
            'rank' => 3
        );
    }

    /*
     * Conservative review bucket.
     */
    if (ci_contains_any($name, array(
        'mrl_',
        'rd_',
        'rp_',
        'snapshot',
        'restore',
        'backup',
        'migration'
    ))) {
        return array(
            'class' => 'REVIEW',
            'reason' => 'MRL utility/backup-like name; not automatically safe to remove.',
            'rank' => 3
        );
    }

    return null;
}

/*
 * TESTPHP8 host lock.
 */
if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: This scanner is TESTPHP8-only. Current host: ' . $host;
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root is unavailable or invalid: ' . $root;
}

/*
 * Perform read-only recursive scan.
 */
if (empty($errors)) {
    try {
        $directory = new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        );

        /*
         * Completely excluded trees.
         * WordPress is not part of this MRL cleanup exercise.
         */
        $skipDirNames = array(
            '.git',
            '.svn',
            'node_modules',
            'wp-admin',
            'wp-content',
            'wp-includes'
        );

        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            function ($current) use ($skipDirNames) {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), $skipDirNames, true);
                }

                return true;
            }
        );

        $iterator = new RecursiveIteratorIterator(
            $filter,
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $info) {
            $full = $info->getPathname();
            $rel = ci_rel($full, $root);
            $isDir = $info->isDir();

            if ($isDir) {
                $stats['scanned_dirs']++;
            } elseif ($info->isFile()) {
                $stats['scanned_files']++;
            } else {
                continue;
            }

            $classification = ci_classify($full, $rel, $isDir);

            if (!is_array($classification)) {
                continue;
            }

            if ($isDir) {
                $summary = ci_dir_summary($full);

                $detail =
                    (int)$summary['files'] . ' files'
                    . ' • ' . (int)$summary['dirs'] . ' subdirs'
                    . ' • ' . ci_human_bytes($summary['bytes']);

                if (!empty($summary['truncated'])) {
                    $detail .= ' • summary truncated after 5000 entries';
                }

                if (!empty($summary['error'])) {
                    $detail .= ' • summary error: ' . $summary['error'];
                }
            } else {
                $detail = ci_human_bytes((int)$info->getSize());
            }

            $modified = '';
            $mtime = $info->getMTime();

            if ($mtime > 0) {
                $modified = date('Y-m-d g:i:s a', $mtime);
            }

            $items[] = array(
                'class' => $classification['class'],
                'rank' => $classification['rank'],
                'reason' => $classification['reason'],
                'type' => $isDir ? 'DIR' : 'FILE',
                'relative' => $rel,
                'modified' => $modified,
                'detail' => $detail
            );

            $stats['candidates']++;

            if ($classification['class'] === 'KEEP UNTIL FINALIZATION') {
                $stats['keep']++;
            } elseif ($classification['class'] === 'LIKELY CLEANUP') {
                $stats['cleanup']++;
            } else {
                $stats['review']++;
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Scan failed: ' . $e->getMessage();
    }
}

usort($items, function ($a, $b) {
    if ($a['rank'] !== $b['rank']) {
        return $a['rank'] < $b['rank'] ? -1 : 1;
    }

    return strcasecmp((string)$a['relative'], (string)$b['relative']);
});

/*
 * Export mode.
 * The export is streamed directly to the browser.
 * No export file is written onto the server.
 */
$export = strtolower((string)($_GET['export'] ?? ''));

if (empty($errors) && ($export === 'csv' || $export === 'txt')) {
    $timestamp = ci_timestamp_ms();
    $baseName = 'MRL_TESTPHP8_Cleanup_Inventory_' . $timestamp;

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $out = fopen('php://output', 'w');

        /*
         * UTF-8 BOM helps Excel open the file cleanly.
         */
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array(
            'Classification',
            'Type',
            'Relative Path',
            'Modified',
            'Size / Contents',
            'Reason'
        ));

        foreach ($items as $item) {
            fputcsv($out, array(
                $item['class'],
                $item['type'],
                $item['relative'],
                $item['modified'],
                $item['detail'],
                $item['reason']
            ));
        }

        fclose($out);
        exit;
    }

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo "MRL TESTPHP8 CLEANUP INVENTORY " . $VERSION . "\r\n";
    echo "Export timestamp: " . $timestamp . "\r\n";
    echo "Generated: " . date('Y-m-d g:i:s a T') . "\r\n";
    echo "Root: " . $root . "\r\n";
    echo "WordPress trees excluded: wp-admin, wp-content, wp-includes\r\n";
    echo "READ ONLY scan\r\n";
    echo str_repeat('=', 100) . "\r\n\r\n";

    echo "SUMMARY\r\n";
    echo "Files scanned: " . $stats['scanned_files'] . "\r\n";
    echo "Directories scanned: " . $stats['scanned_dirs'] . "\r\n";
    echo "Candidate artifacts: " . $stats['candidates'] . "\r\n";
    echo "Keep until finalization: " . $stats['keep'] . "\r\n";
    echo "Likely cleanup: " . $stats['cleanup'] . "\r\n";
    echo "Review: " . $stats['review'] . "\r\n\r\n";

    $groupNames = array(
        'KEEP UNTIL FINALIZATION',
        'LIKELY CLEANUP',
        'REVIEW'
    );

    foreach ($groupNames as $groupName) {
        echo str_repeat('-', 100) . "\r\n";
        echo $groupName . "\r\n";
        echo str_repeat('-', 100) . "\r\n";

        $found = false;

        foreach ($items as $item) {
            if ($item['class'] !== $groupName) {
                continue;
            }

            $found = true;

            echo '[' . $item['type'] . '] ' . $item['relative'] . "\r\n";
            echo 'Modified: ' . $item['modified'] . "\r\n";
            echo 'Size/Contents: ' . $item['detail'] . "\r\n";
            echo 'Reason: ' . $item['reason'] . "\r\n\r\n";
        }

        if (!$found) {
            echo "(none)\r\n\r\n";
        }
    }

    exit;
}

$groups = array(
    'KEEP UNTIL FINALIZATION' => array(),
    'LIKELY CLEANUP' => array(),
    'REVIEW' => array()
);

foreach ($items as $item) {
    $groups[$item['class']][] = $item;
}

function ci_css($class)
{
    if ($class === 'KEEP UNTIL FINALIZATION') {
        return 'keep';
    }

    if ($class === 'LIKELY CLEANUP') {
        return 'cleanup';
    }

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
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1600px;margin:0 auto;padding:14px}
.banner{background:#24351d;border:1px solid #547c3d;border-radius:10px;padding:12px 14px}
.banner h1{margin:0;color:#dfffcf;font-size:22px}
.sub{font-size:12px;color:#bdd4ae;margin-top:4px}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.btn{display:inline-block;background:#2b5d82;color:#fff;text-decoration:none;border:1px solid #4e8db7;border-radius:6px;padding:7px 11px;font-weight:700}
.btn:hover{background:#36749f}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px;overflow:auto}
h2{margin:0 0 8px;color:#d5efc9;font-size:18px}
table{width:100%;border-collapse:collapse;min-width:1100px}
th,td{padding:6px 7px;border-bottom:1px solid #333;text-align:left;vertical-align:top}
th{background:#272727}
.keep{color:#ffd36b;font-weight:700}
.cleanup{color:#69ef98;font-weight:700}
.review{color:#ffb866;font-weight:700}
.err{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:10px 12px;margin-top:11px;color:#ff9b9b;font-weight:700}
.warn{background:#4a2b00;border:1px solid #b97920;border-radius:8px;padding:10px 12px;margin-top:11px;color:#ffd36b;font-weight:700}
.ok{background:#17351f;border:1px solid #3f7d4f;border-radius:8px;padding:10px 12px;margin-top:11px;color:#bff1c9}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px}
.metric{background:#161616;border:1px solid #393939;border-radius:7px;padding:9px 10px}
.metric .n{font-size:21px;font-weight:700}
.path{font-family:Consolas,monospace;color:#d8e8ff;word-break:break-all}
.note{font-size:12px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL TESTPHP8 CLEANUP INVENTORY SCANNER v002</h1>
<div class="sub">READ ONLY • generated 8/24/2026 1:57:32 am ET • recursively scans custom TESTPHP8 material including race_results</div>

<div class="toolbar">
<a class="btn" href="?export=csv">Export CSV</a>
<a class="btn" href="?export=txt">Export TXT</a>
</div>
</div>

<?php foreach ($errors as $error): ?>
<div class="err"><?=ci_h($error)?></div>
<?php endforeach; ?>

<div class="warn">
NO FILES ARE DELETED BY THIS PAGE. Inventory only.
</div>

<div class="ok">
WordPress trees are completely excluded from this scan:
<strong>wp-admin/</strong>,
<strong>wp-content/</strong>,
<strong>wp-includes/</strong>.
</div>

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

<?php foreach (array('KEEP UNTIL FINALIZATION','LIKELY CLEANUP','REVIEW') as $groupName): ?>
<div class="card">
<h2 class="<?=ci_css($groupName)?>">
<?=ci_h($groupName)?> — <?=count($groups[$groupName])?> item(s)
</h2>

<?php if (empty($groups[$groupName])): ?>
<div class="note">No items in this category.</div>

<?php else: ?>
<table>
<tr>
<th>Type</th>
<th>Relative path</th>
<th>Modified</th>
<th>Size / contents</th>
<th>Reason</th>
</tr>

<?php foreach ($groups[$groupName] as $item): ?>
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
Use <strong>Export CSV</strong> or <strong>Export TXT</strong> and send that file to ChatGPT.<br>
Export filenames use:
<span class="path">MRL_TESTPHP8_Cleanup_Inventory_YYYYMMDD_HHMMSSmmm</span><br><br>

Example:
<span class="path">MRL_TESTPHP8_Cleanup_Inventory_20260824_015616786.csv</span><br><br>

We will review the exact removal list before creating any cleanup installer.<br><br>

Root scanned:
<span class="path"><?=ci_h($root)?></span><br>

READ ONLY:
no deletes,
no server-side export files,
no DB changes,
no scheduler changes.
</div>

</div>
</body>
</html>
