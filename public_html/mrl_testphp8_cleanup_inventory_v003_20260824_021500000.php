<?php
/**
 * MRL TESTPHP8 Cleanup Inventory v003
 *
 * Purpose:
 * - Inventory likely test/installer/backup clutter under TESTPHP8
 * - Classify files/folders as KEEP / LIKELY REMOVE / REVIEW / IGNORE
 * - Recognize MRL tool files, patch/simulation/install files, backup folders,
 *   and operational snapshot files so they do not get mixed together.
 * - Export CSV and TXT with meaningful timestamped filenames.
 *
 * Host usage:
 * - Place in /public_html/testphp8/
 * - Open in browser.
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

$scriptVersion = 'v003';
$generatedAt = date('Y-m-d H:i:s');
$nowStamp = date('Ymd_His') . substr((string) microtime(true), -3);
$baseDir = __DIR__;
$baseName = basename($baseDir);
$projectLabel = 'MRL TESTPHP8 Cleanup Inventory';

$view = isset($_GET['view']) ? trim((string) $_GET['view']) : 'all';
$includeFiles = isset($_GET['include_files']) ? (string) $_GET['include_files'] === '1' : true;
$includeDirs = isset($_GET['include_dirs']) ? (string) $_GET['include_dirs'] === '1' : true;
$sortBy = isset($_GET['sort']) ? trim((string) $_GET['sort']) : 'category';
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$export = isset($_GET['export']) ? trim((string) $_GET['export']) : '';

$categoryOrder = [
    'LIKELY_REMOVE' => 1,
    'REVIEW' => 2,
    'KEEP_OPERATIONAL' => 3,
    'IGNORE_SUPPORT' => 4,
];

$protectedExact = [
    'index.php',
    'team.php',
    'submit-team-picks.php',
    'team_replacement_driver.php',
    'race_results_rd_helper.php',
    'race_results',
    'js',
    'mailer',
    '.htaccess',
    'error_log',
];

$protectedStartsWith = [
    'wp-',
    'class-',
    'vendor',
];

$protectedContains = [
    'snapshot_', // operational MRL release snapshots handled more specifically below
];

$likelyContains = [
    'mrl_',
    'patch',
    'simulation',
    'installer',
    'diagnostic',
    'harness',
    'fixture',
    'cleanup_inventory',
    'inventory',
    'preview',
    'rescue',
    'bridge',
    'deadline_test',
    'time_travel',
    'edge_case',
    'real_submit_error_capture',
    'database_verification',
    'finalization',
    'focused',
    'structural_repair',
    'submit_structure',
];

$likelyFolderContains = [
    '_backup_',
    'backup_',
    'mrl_',
    'simulation',
    'patch',
    'fixture',
];

$ignoreExt = [
    'png','jpg','jpeg','gif','webp','svg','css','js','map','woff','woff2','ttf','eot','ico','pdf','zip'
];

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function humanBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return number_format($value, $i === 0 ? 0 : 2) . ' ' . $units[$i];
}

function relPath(string $baseDir, string $fullPath): string {
    $base = rtrim(str_replace('\\', '/', $baseDir), '/');
    $full = str_replace('\\', '/', $fullPath);
    if (strpos($full, $base . '/') === 0) {
        return substr($full, strlen($base) + 1);
    }
    return basename($full);
}

function pathDepth(string $relative): int {
    if ($relative === '' || $relative === '.') {
        return 0;
    }
    return substr_count(trim($relative, '/'), '/');
}

function classifyPath(string $relative, bool $isDir, array $protectedExact, array $protectedStartsWith, array $protectedContains, array $likelyContains, array $likelyFolderContains, array $ignoreExt): array {
    $name = basename($relative);
    $lowerRel = strtolower($relative);
    $lowerName = strtolower($name);
    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

    // Explicit operational snapshot awareness.
    if (preg_match('~(^|/)(snapshot_\d{8}_\d+\.html)$~i', $lowerRel)) {
        return ['KEEP_OPERATIONAL', 'Operational release snapshot HTML'];
    }
    if (preg_match('~(^|/)race_results(/|$)~i', $lowerRel) && preg_match('~snapshot~i', $lowerRel)) {
        return ['KEEP_OPERATIONAL', 'Race-results snapshot asset; keep unless separately approved'];
    }

    if (in_array($lowerName, $protectedExact, true)) {
        return ['KEEP_OPERATIONAL', 'Known live application file/folder'];
    }

    foreach ($protectedStartsWith as $prefix) {
        if (strpos($lowerName, strtolower($prefix)) === 0) {
            return ['IGNORE_SUPPORT', 'Common support/framework asset'];
        }
    }

    if (!$isDir && in_array($ext, $ignoreExt, true) && strpos($lowerName, 'mrl_') !== 0) {
        return ['IGNORE_SUPPORT', 'Support/static asset'];
    }

    foreach ($protectedContains as $needle) {
        if (strpos($lowerRel, strtolower($needle)) !== false && !preg_match('~(^|/)mrl_~i', $lowerRel)) {
            return ['KEEP_OPERATIONAL', 'Likely operational snapshot/reference file'];
        }
    }

    if ($isDir) {
        foreach ($likelyFolderContains as $needle) {
            if (strpos($lowerName, strtolower($needle)) !== false || strpos($lowerRel, strtolower($needle)) !== false) {
                return ['LIKELY_REMOVE', 'Likely temporary backup/test folder'];
            }
        }
    }

    foreach ($likelyContains as $needle) {
        if (strpos($lowerName, strtolower($needle)) !== false || strpos($lowerRel, strtolower($needle)) !== false) {
            return ['LIKELY_REMOVE', 'Likely test/install/patch/simulation artifact'];
        }
    }

    if (preg_match('~(^|/)mrl_.*\.(php|html?)$~i', $lowerRel)) {
        return ['LIKELY_REMOVE', 'MRL utility/test file candidate'];
    }

    if (preg_match('~backup~i', $lowerRel)) {
        return ['REVIEW', 'Contains backup wording; review before removing'];
    }

    if ($isDir) {
        return ['REVIEW', 'Unclassified folder; manual review'];
    }

    return ['REVIEW', 'Unclassified file; manual review'];
}

$items = [];
$summary = [
    'LIKELY_REMOVE' => ['count' => 0, 'bytes' => 0],
    'REVIEW' => ['count' => 0, 'bytes' => 0],
    'KEEP_OPERATIONAL' => ['count' => 0, 'bytes' => 0],
    'IGNORE_SUPPORT' => ['count' => 0, 'bytes' => 0],
];

$directory = new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);

foreach ($iterator as $fileInfo) {
    $fullPath = $fileInfo->getPathname();
    $relative = relPath($baseDir, $fullPath);

    if ($relative === basename(__FILE__)) {
        continue;
    }

    $isDir = $fileInfo->isDir();
    if (($isDir && !$includeDirs) || (!$isDir && !$includeFiles)) {
        continue;
    }

    [$category, $reason] = classifyPath(
        $relative,
        $isDir,
        $protectedExact,
        $protectedStartsWith,
        $protectedContains,
        $likelyContains,
        $likelyFolderContains,
        $ignoreExt
    );

    if ($view !== 'all' && $category !== $view) {
        continue;
    }

    if ($search !== '') {
        $haystack = strtolower($relative . ' ' . $reason . ' ' . $category);
        if (strpos($haystack, strtolower($search)) === false) {
            continue;
        }
    }

    $size = $isDir ? 0 : (int) $fileInfo->getSize();
    $mtime = (int) $fileInfo->getMTime();
    $items[] = [
        'category' => $category,
        'reason' => $reason,
        'type' => $isDir ? 'DIR' : 'FILE',
        'relative' => $relative,
        'name' => basename($relative),
        'depth' => pathDepth($relative),
        'size' => $size,
        'size_human' => $isDir ? '' : humanBytes($size),
        'mtime' => $mtime,
        'mtime_human' => date('Y-m-d H:i:s', $mtime),
    ];

    $summary[$category]['count']++;
    $summary[$category]['bytes'] += $size;
}

usort($items, function (array $a, array $b) use ($sortBy, $categoryOrder): int {
    switch ($sortBy) {
        case 'path':
            return strcasecmp($a['relative'], $b['relative']);
        case 'mtime_desc':
            return $b['mtime'] <=> $a['mtime'];
        case 'size_desc':
            return $b['size'] <=> $a['size'];
        case 'category':
        default:
            $cmp = ($categoryOrder[$a['category']] ?? 99) <=> ($categoryOrder[$b['category']] ?? 99);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp($a['relative'], $b['relative']);
    }
});

if ($export === 'csv' || $export === 'txt') {
    $exportStamp = date('Ymd_His') . substr((string) microtime(true), -3);
    $baseFile = 'MRL_TESTPHP8_Cleanup_Inventory_' . $exportStamp;

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $baseFile . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Category', 'Type', 'Relative Path', 'Name', 'Depth', 'Size Bytes', 'Modified', 'Reason']);
        foreach ($items as $row) {
            fputcsv($out, [
                $row['category'],
                $row['type'],
                $row['relative'],
                $row['name'],
                $row['depth'],
                $row['size'],
                $row['mtime_human'],
                $row['reason'],
            ]);
        }
        fclose($out);
        exit;
    }

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $baseFile . '.txt"');
    echo $projectLabel . ' ' . $scriptVersion . PHP_EOL;
    echo 'Generated: ' . $generatedAt . PHP_EOL;
    echo 'Base dir: ' . $baseDir . PHP_EOL;
    echo 'View: ' . $view . PHP_EOL;
    echo 'Sort: ' . $sortBy . PHP_EOL;
    echo 'Search: ' . ($search === '' ? '[none]' : $search) . PHP_EOL;
    echo str_repeat('=', 110) . PHP_EOL;
    foreach ($items as $row) {
        echo '[' . $row['category'] . '] '
            . $row['type'] . ' | '
            . $row['relative'] . ' | '
            . ($row['size_human'] === '' ? '-' : $row['size_human']) . ' | '
            . $row['mtime_human'] . PHP_EOL;
        echo '  Reason: ' . $row['reason'] . PHP_EOL;
    }
    exit;
}

function buildQuery(array $override = []): string {
    $params = array_merge($_GET, $override);
    foreach ($params as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        }
    }
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($projectLabel . ' ' . $scriptVersion); ?></title>
<style>
    body {
        margin: 0;
        padding: 20px;
        background: #11151b;
        color: #e8edf2;
        font-family: Arial, Helvetica, sans-serif;
    }
    .wrap { max-width: 1600px; margin: 0 auto; }
    .hero {
        background: linear-gradient(135deg, #113c17, #173d19);
        border: 1px solid #4fa36d;
        border-radius: 14px;
        padding: 18px 20px;
        margin-bottom: 18px;
    }
    .hero h1 { margin: 0 0 6px 0; font-size: 38px; }
    .hero .sub { color: #d8f4de; font-size: 16px; }
    .panel {
        background: #171c22;
        border: 1px solid #38424d;
        border-radius: 14px;
        padding: 16px;
        margin-bottom: 18px;
    }
    .panel h2 { margin: 0 0 14px 0; color: #ffd76c; font-size: 20px; }
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 12px;
    }
    .summary-card {
        border-radius: 12px;
        padding: 14px;
        border: 1px solid #46515d;
        background: #11161c;
    }
    .summary-card h3 { margin: 0 0 8px; font-size: 18px; }
    .LIKELY_REMOVE { border-color: #be6d4a; }
    .REVIEW { border-color: #d7b64b; }
    .KEEP_OPERATIONAL { border-color: #4b9c67; }
    .IGNORE_SUPPORT { border-color: #667a92; }
    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        font-weight: bold;
        font-size: 12px;
        letter-spacing: .2px;
    }
    .badge.LIKELY_REMOVE { background: #56251c; color: #ffb598; }
    .badge.REVIEW { background: #5a4a12; color: #ffe082; }
    .badge.KEEP_OPERATIONAL { background: #173822; color: #a8f1c1; }
    .badge.IGNORE_SUPPORT { background: #24303d; color: #c8d4e2; }
    .controls {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
        align-items: end;
    }
    label { display: block; font-weight: bold; margin-bottom: 6px; }
    input[type="text"], select {
        width: 100%;
        box-sizing: border-box;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid #5a6674;
        background: #0f1419;
        color: #eef3f8;
        font-size: 14px;
    }
    .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
    .btn {
        text-decoration: none;
        display: inline-block;
        padding: 10px 14px;
        border-radius: 10px;
        border: 1px solid #607081;
        color: #fff;
        background: #244b7a;
        font-weight: bold;
    }
    .btn.green { background: #25693a; border-color: #4d9c69; }
    .btn.gold { background: #6f581a; border-color: #c4a64f; }
    .btn.red { background: #6d2b25; border-color: #b96b63; }
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    th, td {
        border: 1px solid #3a4653;
        padding: 8px 10px;
        vertical-align: top;
    }
    th { background: #202730; color: #fff1a6; text-align: left; }
    tr:nth-child(even) td { background: #121920; }
    tr:nth-child(odd) td { background: #0f151b; }
    .mono { font-family: Consolas, Menlo, monospace; }
    .foot {
        color: #cfd7df;
        font-size: 13px;
        line-height: 1.5;
    }
    .legend ul { margin: 0; padding-left: 20px; }
    .small { font-size: 12px; color: #c3cbd3; }
</style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <h1><?php echo h($projectLabel . ' ' . $scriptVersion); ?></h1>
        <div class="sub">Host folder: <?php echo h($baseName); ?> &nbsp;•&nbsp; Generated: <?php echo h($generatedAt); ?> &nbsp;•&nbsp; Base dir: <span class="mono"><?php echo h($baseDir); ?></span></div>
    </div>

    <div class="panel">
        <h2>Summary</h2>
        <div class="summary-grid">
            <?php foreach ($summary as $category => $data): ?>
                <div class="summary-card <?php echo h($category); ?>">
                    <h3><span class="badge <?php echo h($category); ?>"><?php echo h($category); ?></span></h3>
                    <div>Count: <strong><?php echo number_format($data['count']); ?></strong></div>
                    <div>File bytes: <strong><?php echo h(humanBytes($data['bytes'])); ?></strong></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel">
        <h2>Filters / Export</h2>
        <form method="get">
            <div class="controls">
                <div>
                    <label for="view">View</label>
                    <select id="view" name="view">
                        <?php foreach (['all','LIKELY_REMOVE','REVIEW','KEEP_OPERATIONAL','IGNORE_SUPPORT'] as $opt): ?>
                            <option value="<?php echo h($opt); ?>" <?php echo $view === $opt ? 'selected' : ''; ?>><?php echo h($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="sort">Sort</label>
                    <select id="sort" name="sort">
                        <option value="category" <?php echo $sortBy === 'category' ? 'selected' : ''; ?>>Category → Path</option>
                        <option value="path" <?php echo $sortBy === 'path' ? 'selected' : ''; ?>>Path</option>
                        <option value="mtime_desc" <?php echo $sortBy === 'mtime_desc' ? 'selected' : ''; ?>>Modified (newest first)</option>
                        <option value="size_desc" <?php echo $sortBy === 'size_desc' ? 'selected' : ''; ?>>Size (largest first)</option>
                    </select>
                </div>
                <div>
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?php echo h($search); ?>" placeholder="path, reason, category">
                </div>
                <div>
                    <label><input type="checkbox" name="include_files" value="1" <?php echo $includeFiles ? 'checked' : ''; ?>> Include files</label>
                    <label><input type="checkbox" name="include_dirs" value="1" <?php echo $includeDirs ? 'checked' : ''; ?>> Include directories</label>
                </div>
            </div>
            <div class="actions">
                <button class="btn green" type="submit">Apply Filters</button>
                <a class="btn" href="<?php echo h(buildQuery(['view' => 'all', 'sort' => 'category', 'search' => '', 'include_files' => '1', 'include_dirs' => '1', 'export' => null])); ?>">Reset</a>
                <a class="btn gold" href="<?php echo h(buildQuery(['export' => 'csv'])); ?>">Export CSV</a>
                <a class="btn gold" href="<?php echo h(buildQuery(['export' => 'txt'])); ?>">Export TXT</a>
            </div>
        </form>
        <div class="small" style="margin-top:10px;">Export names use this style: <span class="mono">MRL_TESTPHP8_Cleanup_Inventory_YYYYMMDD_HHMMSSmmm.csv/txt</span></div>
    </div>

    <div class="panel legend">
        <h2>Classification Notes</h2>
        <ul>
            <li><strong>LIKELY_REMOVE</strong> = strongly looks like a test/install/patch/simulation/helper/backup artifact.</li>
            <li><strong>REVIEW</strong> = not obviously live, but not safe to auto-assume.</li>
            <li><strong>KEEP_OPERATIONAL</strong> = live app files or operational snapshot assets.</li>
            <li><strong>IGNORE_SUPPORT</strong> = common support/static/framework items that usually are not part of cleanup discussion.</li>
            <li>v003 intentionally recognizes release snapshot-style files so they do not get lumped into ordinary cleanup clutter.</li>
            <li>Nothing is deleted by this script. This is inventory/export only.</li>
        </ul>
    </div>

    <div class="panel">
        <h2>Inventory Results (<?php echo number_format(count($items)); ?> items shown)</h2>
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Relative Path</th>
                    <th>Name</th>
                    <th>Depth</th>
                    <th>Size</th>
                    <th>Modified</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$items): ?>
                <tr><td colspan="8">No rows match the current filters.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $row): ?>
                    <tr>
                        <td><span class="badge <?php echo h($row['category']); ?>"><?php echo h($row['category']); ?></span></td>
                        <td><?php echo h($row['type']); ?></td>
                        <td class="mono"><?php echo h($row['relative']); ?></td>
                        <td><?php echo h($row['name']); ?></td>
                        <td><?php echo h((string) $row['depth']); ?></td>
                        <td><?php echo h($row['size_human'] === '' ? '-' : $row['size_human']); ?></td>
                        <td><?php echo h($row['mtime_human']); ?></td>
                        <td><?php echo h($row['reason']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="panel foot">
        <strong>Suggested next step:</strong> review the <span class="mono">LIKELY_REMOVE</span> rows first, then export a TXT or CSV and mark each item as <strong>remove</strong>, <strong>keep</strong>, or <strong>defer</strong>. After that, build a controlled cleanup installer from the approved list only.
    </div>
</div>
</body>
</html>
