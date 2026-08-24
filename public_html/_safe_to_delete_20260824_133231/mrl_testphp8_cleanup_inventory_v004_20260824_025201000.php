<?php
declare(strict_types=1);

/**
 * MRL TESTPHP8 — NARROW CLEANUP ARTIFACT INVENTORY
 *
 * VERSION: v004
 * LAST MODIFIED: 8/24/2026 2:52:01 am ET
 *
 * PURPOSE
 * -------
 * Inventory only the locations where temporary MRL development artifacts
 * are expected to accumulate.
 *
 * THIS VERSION INTENTIONALLY DOES NOT RECURSIVELY CLASSIFY THE WHOLE SITE.
 *
 * SCAN SCOPE
 * ----------
 * 1) TESTPHP8 document root — TOP LEVEL ONLY
 * 2) TESTPHP8/race_results — TOP LEVEL ONLY
 * 3) Explicit known temporary test area:
 *      race_results/_rd_simulation
 * 4) Explicit current LP→RP pending/marker files under race_results/2026
 *    (searched narrowly by exact filename prefixes only)
 *
 * EXCLUDED
 * --------
 * - wp-admin
 * - wp-content
 * - wp-includes
 * - formtools
 * - db_backups
 * - normal race_results/YYYY/... contents
 * - normal operational application/support trees
 *
 * EXPORTS
 * -------
 * CSV and TXT exports are streamed directly to the browser.
 * Export filenames use:
 *   MRL_TESTPHP8_Cleanup_Inventory_YYYYMMDD_HHMMSSmmm.csv
 *   MRL_TESTPHP8_Cleanup_Inventory_YYYYMMDD_HHMMSSmmm.txt
 *
 * SAFETY
 * ------
 * READ ONLY.
 * - no deletes
 * - no renames
 * - no file writes
 * - no DB writes
 * - no scheduler changes
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

$VERSION = 'v004';
$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$errors = array();
$items = array();

$stats = array(
    'root_top_level_seen' => 0,
    'race_results_top_level_seen' => 0,
    'explicit_temp_seen' => 0,
    'current_lp_rp_seen' => 0,
    'likely_cleanup' => 0,
    'keep_until_finalization' => 0,
    'review' => 0,
);

function ci_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

    return date('Ymd_His', $seconds)
        . str_pad((string)$milliseconds, 3, '0', STR_PAD_LEFT);
}

function ci_add_item(&$items, &$stats, $class, $type, $relative, $modified, $detail, $reason, $scope)
{
    $items[] = array(
        'class' => $class,
        'type' => $type,
        'relative' => $relative,
        'modified' => $modified,
        'detail' => $detail,
        'reason' => $reason,
        'scope' => $scope,
    );

    if ($class === 'LIKELY CLEANUP') {
        $stats['likely_cleanup']++;
    } elseif ($class === 'KEEP UNTIL FINALIZATION') {
        $stats['keep_until_finalization']++;
    } else {
        $stats['review']++;
    }
}

function ci_dir_summary($dir)
{
    $files = 0;
    $dirs = 0;
    $bytes = 0;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $dir,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $info) {
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
            'error' => $e->getMessage()
        );
    }

    return array(
        'files' => $files,
        'dirs' => $dirs,
        'bytes' => $bytes,
        'error' => ''
    );
}

function ci_detail_for_path($path)
{
    if (is_dir($path)) {
        $summary = ci_dir_summary($path);

        $detail = (int)$summary['files'] . ' files'
            . ' • ' . (int)$summary['dirs'] . ' subdirs'
            . ' • ' . ci_human_bytes((int)$summary['bytes']);

        if ($summary['error'] !== '') {
            $detail .= ' • summary error: ' . $summary['error'];
        }

        return $detail;
    }

    if (is_file($path)) {
        return ci_human_bytes((int)filesize($path));
    }

    return '';
}

function ci_modified_for_path($path)
{
    $mtime = @filemtime($path);

    if ($mtime === false) {
        return '';
    }

    return date('Y-m-d g:i:s a', $mtime);
}

function ci_is_current_lp_rp_name($name)
{
    $lower = strtolower((string)$name);

    $markers = array(
        'mrl_lp_rp_',
        'mrl_rd_be_like_biff_fixture_manager_',
        'mrl_rp_deadline_test_switch_',
        'mrl_rp_late_submission_server_gate_test_'
    );

    foreach ($markers as $marker) {
        if (strpos($lower, $marker) === 0) {
            return true;
        }
    }

    return false;
}

function ci_is_likely_root_artifact($name)
{
    $lower = strtolower((string)$name);

    /*
     * Root-level MRL development artifact rules.
     * "mrl_" alone is NOT enough.
     */
    if (strpos($lower, 'mrl_') !== 0) {
        return false;
    }

    $signals = array(
        'installer',
        'patch',
        'simulation',
        'diagnostic',
        'harness',
        'fixture',
        'backup_',
        '_backup_',
        'cleanup_inventory',
        'verification',
        'repair',
        'rescue',
        'test_switch',
        'test_hook',
        'server_gate_test',
        'preview_installer'
    );

    foreach ($signals as $signal) {
        if (strpos($lower, $signal) !== false) {
            return true;
        }
    }

    return false;
}

function ci_is_likely_race_results_artifact($name)
{
    $lower = strtolower((string)$name);

    /*
     * Only obvious top-level installer/backup/test debris.
     * Operational mrl_*.php/html files are NOT caught just because
     * they begin with mrl_.
     */
    $signals = array(
        'install_',
        '_install_backup_',
        '_installer_backups',
        '_rd_simulation',
        'diagnostic'
    );

    foreach ($signals as $signal) {
        if (strpos($lower, $signal) !== false) {
            return true;
        }
    }

    /*
     * Timestamped .bak copies at race_results top level.
     */
    if (strpos($lower, '.bak_') !== false) {
        return true;
    }

    /*
     * Obvious named backup copy, but not active application files.
     */
    if (preg_match('/^mrl_.*backup_[0-9]{8}/i', $name)) {
        return true;
    }

    return false;
}

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: TESTPHP8-only. Current host: ' . $host;
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root is unavailable or invalid: ' . $root;
}

if (empty($errors)) {
    /*
     * ============================================================
     * SCOPE 1 — TESTPHP8 ROOT, TOP LEVEL ONLY
     * ============================================================
     */
    $rootEntries = @scandir($root);

    if (is_array($rootEntries)) {
        foreach ($rootEntries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $stats['root_top_level_seen']++;

            $full = $root . DIRECTORY_SEPARATOR . $name;

            /*
             * Never inventory established application/support trees here.
             */
            $excludedTop = array(
                'wp-admin',
                'wp-content',
                'wp-includes',
                'formtools',
                'db_backups',
                'race_results'
            );

            if (in_array($name, $excludedTop, true)) {
                continue;
            }

            if (ci_is_current_lp_rp_name($name)) {
                ci_add_item(
                    $items,
                    $stats,
                    'KEEP UNTIL FINALIZATION',
                    is_dir($full) ? 'DIR' : 'FILE',
                    $name,
                    ci_modified_for_path($full),
                    ci_detail_for_path($full),
                    'Current LP→RP / Replacement Pick test artifact.',
                    'TESTPHP8 root'
                );
                continue;
            }

            if (ci_is_likely_root_artifact($name)) {
                ci_add_item(
                    $items,
                    $stats,
                    'LIKELY CLEANUP',
                    is_dir($full) ? 'DIR' : 'FILE',
                    $name,
                    ci_modified_for_path($full),
                    ci_detail_for_path($full),
                    'Top-level MRL development artifact: installer/patch/simulation/test/backup-style item.',
                    'TESTPHP8 root'
                );
            }
        }
    }

    /*
     * ============================================================
     * SCOPE 2 — race_results TOP LEVEL ONLY
     * ============================================================
     */
    $rr = $root . DIRECTORY_SEPARATOR . 'race_results';

    if (is_dir($rr)) {
        $rrEntries = @scandir($rr);

        if (is_array($rrEntries)) {
            foreach ($rrEntries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $stats['race_results_top_level_seen']++;

                $full = $rr . DIRECTORY_SEPARATOR . $name;

                /*
                 * Normal year folders are deliberately ignored.
                 */
                if (preg_match('/^20[0-9]{2}$/', $name)) {
                    continue;
                }

                if (ci_is_likely_race_results_artifact($name)) {
                    ci_add_item(
                        $items,
                        $stats,
                        'LIKELY CLEANUP',
                        is_dir($full) ? 'DIR' : 'FILE',
                        'race_results/' . $name,
                        ci_modified_for_path($full),
                        ci_detail_for_path($full),
                        'Top-level race_results installer/backup/test artifact.',
                        'race_results top level'
                    );
                }
            }
        }
    }

    /*
     * ============================================================
     * SCOPE 3 — explicit known temporary tree: _rd_simulation
     * ============================================================
     */
    $rdSimulation = $root . DIRECTORY_SEPARATOR . 'race_results'
        . DIRECTORY_SEPARATOR . '_rd_simulation';

    if (is_dir($rdSimulation)) {
        $stats['explicit_temp_seen']++;

        ci_add_item(
            $items,
            $stats,
            'LIKELY CLEANUP',
            'DIR',
            'race_results/_rd_simulation',
            ci_modified_for_path($rdSimulation),
            ci_detail_for_path($rdSimulation),
            'Known temporary Replacement Driver simulation tree.',
            'explicit temporary area'
        );
    }

    /*
     * ============================================================
     * SCOPE 4 — current LP→RP pending/marker files only.
     *
     * We DO NOT inventory normal race files in year folders.
     * We only search narrowly for these exact filename prefixes.
     * ============================================================
     */
    $year2026 = $root . DIRECTORY_SEPARATOR . 'race_results'
        . DIRECTORY_SEPARATOR . '2026';

    if (is_dir($year2026)) {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $year2026,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $info) {
                if (!$info->isFile()) {
                    continue;
                }

                $name = $info->getFilename();

                if (
                    strpos($name, '_rd_pending_Be_Like_Biff.json') === 0
                    || strpos($name, '_rd_pending_Be_Like_Biff.lp_rp_edge_marker_') === 0
                ) {
                    $stats['current_lp_rp_seen']++;

                    $full = $info->getPathname();
                    $relative = str_replace('\\', '/', substr($full, strlen($root) + 1));

                    ci_add_item(
                        $items,
                        $stats,
                        'KEEP UNTIL FINALIZATION',
                        'FILE',
                        $relative,
                        ci_modified_for_path($full),
                        ci_detail_for_path($full),
                        'Current LP→RP pending/marker file; retain until reset/finalization.',
                        'current LP→RP marker search'
                    );
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Current LP→RP marker scan failed: ' . $e->getMessage();
        }
    }
}

usort($items, function ($a, $b) {
    $order = array(
        'KEEP UNTIL FINALIZATION' => 1,
        'LIKELY CLEANUP' => 2,
        'REVIEW' => 3
    );

    $oa = isset($order[$a['class']]) ? $order[$a['class']] : 99;
    $ob = isset($order[$b['class']]) ? $order[$b['class']] : 99;

    if ($oa !== $ob) {
        return $oa < $ob ? -1 : 1;
    }

    return strcasecmp((string)$a['relative'], (string)$b['relative']);
});

/*
 * Export mode.
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
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array(
            'Classification',
            'Scope',
            'Type',
            'Relative Path',
            'Modified',
            'Size / Contents',
            'Reason'
        ));

        foreach ($items as $item) {
            fputcsv($out, array(
                $item['class'],
                $item['scope'],
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

    echo "MRL TESTPHP8 NARROW CLEANUP INVENTORY " . $VERSION . "\r\n";
    echo "Export timestamp: " . $timestamp . "\r\n";
    echo "Generated: " . date('Y-m-d g:i:s a T') . "\r\n";
    echo "Root: " . $root . "\r\n";
    echo "READ ONLY\r\n";
    echo "\r\n";
    echo "SCAN SCOPE\r\n";
    echo "- TESTPHP8 root, top level only\r\n";
    echo "- race_results top level only\r\n";
    echo "- explicit race_results/_rd_simulation tree\r\n";
    echo "- exact current LP→RP pending/marker filename search under race_results/2026\r\n";
    echo "\r\n";
    echo "INTENTIONALLY EXCLUDED\r\n";
    echo "- WordPress trees\r\n";
    echo "- formtools\r\n";
    echo "- db_backups\r\n";
    echo "- normal race_results/YYYY/... contents\r\n";
    echo str_repeat('=', 105) . "\r\n\r\n";

    foreach ($items as $item) {
        echo '[' . $item['class'] . '] '
            . $item['type'] . ' | '
            . $item['relative'] . "\r\n";
        echo '  Scope: ' . $item['scope'] . "\r\n";
        echo '  Modified: ' . $item['modified'] . "\r\n";
        echo '  Size/Contents: ' . $item['detail'] . "\r\n";
        echo '  Reason: ' . $item['reason'] . "\r\n\r\n";
    }

    exit;
}

$groups = array(
    'KEEP UNTIL FINALIZATION' => array(),
    'LIKELY CLEANUP' => array(),
    'REVIEW' => array()
);

foreach ($items as $item) {
    if (!isset($groups[$item['class']])) {
        $groups[$item['class']] = array();
    }

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
<title>MRL TESTPHP8 Narrow Cleanup Inventory <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1500px;margin:0 auto;padding:14px}
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
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}
.metric{background:#161616;border:1px solid #393939;border-radius:7px;padding:9px 10px}
.metric .n{font-size:21px;font-weight:700}
.path{font-family:Consolas,monospace;color:#d8e8ff;word-break:break-all}
.note{font-size:12px;color:#bbb}
.scope{color:#b7c9da}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL TESTPHP8 NARROW CLEANUP INVENTORY v004</h1>
<div class="sub">
READ ONLY • generated 8/24/2026 2:52:01 am ET • narrow development-artifact scan
</div>

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
<strong>v004 is intentionally narrow.</strong><br>
It does not recursively classify the whole TESTPHP8 site.
Normal WordPress, FormTools, DB backups, and normal race_results/YYYY/... contents are outside this cleanup pass.
</div>

<div class="card">
<h2>Scan Scope</h2>
<ul>
<li>TESTPHP8 root — <strong>top level only</strong></li>
<li>race_results — <strong>top level only</strong></li>
<li>Known temporary tree: <span class="path">race_results/_rd_simulation</span></li>
<li>Exact current LP→RP pending/marker filenames under <span class="path">race_results/2026</span></li>
</ul>
</div>

<div class="card">
<h2>Summary</h2>
<div class="summary">
<div class="metric"><div class="n"><?=$stats['root_top_level_seen']?></div><div>Root entries examined</div></div>
<div class="metric"><div class="n"><?=$stats['race_results_top_level_seen']?></div><div>race_results top-level entries</div></div>
<div class="metric"><div class="n keep"><?=$stats['keep_until_finalization']?></div><div>Keep until finalization</div></div>
<div class="metric"><div class="n cleanup"><?=$stats['likely_cleanup']?></div><div>Likely cleanup</div></div>
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
<th>Scope</th>
<th>Type</th>
<th>Relative path</th>
<th>Modified</th>
<th>Size / contents</th>
<th>Reason</th>
</tr>

<?php foreach ($groups[$groupName] as $item): ?>
<tr>
<td class="scope"><?=ci_h($item['scope'])?></td>
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
Export either TXT or CSV and send it back for review.<br>
Nothing in this page is authorization to delete anything.<br><br>

Export filename format:
<span class="path">MRL_TESTPHP8_Cleanup_Inventory_YYYYMMDD_HHMMSSmmm</span><br><br>

Root:
<span class="path"><?=ci_h($root)?></span>
</div>

</div>
</body>
</html>
