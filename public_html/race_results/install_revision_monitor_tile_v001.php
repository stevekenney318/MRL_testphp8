<?php
/*
INSTALLER: Revision Monitor Watching Tile
VERSION: v001
LAST MODIFIED: 6/21/2026 10:15:00 pm
*/

$target = __DIR__ . '/race_results_dashboard.php';
if (!file_exists($target)) die('race_results_dashboard.php not found');

$src = file_get_contents($target);
$orig = $src;

$src = str_replace(
    '<div class="snapshot-label">Last Revision Snapshot</div>',
    '<div class="snapshot-label">Revision Monitor Watching</div>',
    $src
);

$src = preg_replace(
    '#<div class="snapshot-main"><\?php echo sd_html\(\(string\)\$dashLastSnapshot\[\'race\'\]\); \?></div>\s*<div class="snapshot-detail"><\?php echo sd_html\(\(string\)\$dashLastSnapshot\[\'time\'\]\); \?></div>\s*<div class="snapshot-impact-line"><span class="badge <\?php echo sd_html\(\(string\)\$dashLastSnapshot\[\'impact_class\'\]\); \?>"><\?php echo sd_html\(\(string\)\$dashLastSnapshot\[\'impact_text\'\]\); \?></span></div>#s',
    '<div class="snapshot-main">All completed races including</div>
            <div class="snapshot-detail"><?php echo sd_html((string)$dashLatestSnapshot[\'race\']); ?></div>
            <div class="snapshot-impact-line"><span class="badge ok"><?php echo sd_html((string)$dashLatestSnapshot[\'time\']); ?></span></div>',
    $src,
    1
);

if (strpos($src, '$dashLatestSnapshot') === false) {
    $needle = '$dashLastSnapshot = rr_dash_last_revision_snapshot($dashClassificationRows);';
    $insert = $needle . "\n" . '$dashLatestSnapshot = rr_dash_last_snapshot_captured($yearDir);';
    $src = str_replace($needle, $insert, $src);
}

if ($src === $orig) die('Nothing patched');

copy($target, $target . '.bak_revision_tile_' . date('Ymd_His'));
file_put_contents($target, $src);

echo "Patched successfully\n";
?>