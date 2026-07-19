<?php
/*
MRL SNAPSHOT LITE BACKFILL
VERSION: v001
LAST MODIFIED: 2026-07-10
TIME ZONE: America/New_York

Upload to /race_results/, open in a browser, review dry run,
then click Create Lite Files. Delete this script afterward.
PHP 7.3 compatible.
*/

declare(strict_types=1);
date_default_timezone_set('America/New_York');

$year = 2026;
$yearDir = __DIR__ . DIRECTORY_SEPARATOR . $year;
$execute = isset($_GET['execute']) && $_GET['execute'] === '1';
$overwrite = isset($_GET['overwrite']) && $_GET['overwrite'] === '1';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function ns(string $v): string { return trim((string)preg_replace('/\s+/', ' ', $v)); }

function race_code(string $folder): ?string {
    if (!preg_match('/^R(\d{1,2})(?:_|$)/i', $folder, $m)) return null;
    return 'R' . str_pad((string)((int)$m[1]), 2, '0', STR_PAD_LEFT);
}

function short_name(string $folder): string {
    $name = preg_replace('/^R\d+_?/i', '', $folder);
    $name = preg_replace('/_\d{10,}$/', '', (string)$name);
    $name = preg_replace('/^NASCAR_Cup_Series_at_/i', '', (string)$name);
    $name = ns(str_replace('_', ' ', (string)$name));
    if ($name === 'Circuit of the Americas') return 'COTA';
    return $name;
}

function snapshot_info(string $filename): ?array {
    if (!preg_match('/^snapshot_(\d{8})_(\d{9})\.html$/', $filename, $m)) return null;
    $dt = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . substr($m[2], 0, 6));
    if (!$dt) return null;
    return ['display' => $dt->format('n/j/y g:ia')];
}

function source_snapshots(string $raceDir): array {
    $files = glob($raceDir . DIRECTORY_SEPARATOR . 'snapshot_*.html') ?: [];
    $out = [];
    foreach ($files as $file) {
        $base = basename($file);
        if (substr($base, -10) === '_lite.html') continue;
        if (snapshot_info($base) === null) continue;
        $out[] = $file;
    }
    sort($out, SORT_STRING);
    return $out;
}

function outer_html(DOMDocument $dom, DOMNode $node): string {
    return (string)$dom->saveHTML($node);
}

function create_lite(string $sourcePath, string $title, string $sourceBase): array {
    $raw = @file_get_contents($sourcePath);
    if ($raw === false || trim($raw) === '') return ['ok'=>false,'error'=>'Source unreadable or empty.'];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML($raw);
    libxml_clear_errors();
    if (!$loaded) return ['ok'=>false,'error'=>'Could not parse source HTML.'];

    $xp = new DOMXPath($dom);
    $table = null;
    $nodes = $xp->query('//table[contains(concat(" ", normalize-space(@class), " "), " tablehead ")]');
    if ($nodes !== false && $nodes->length) $table = $nodes->item(0);
    if (!$table) {
        $nodes = $xp->query('//table[.//th[contains(translate(normalize-space(.), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "DRIVER")]]');
        if ($nodes !== false && $nodes->length) $table = $nodes->item(0);
    }
    if (!$table) return ['ok'=>false,'error'=>'Race results table not found.'];

    $links = $xp->query('.//a', $table);
    if ($links !== false) {
        for ($i = $links->length - 1; $i >= 0; $i--) {
            $a = $links->item($i);
            if (!$a || !$a->parentNode) continue;
            while ($a->firstChild) $a->parentNode->insertBefore($a->firstChild, $a);
            $a->parentNode->removeChild($a);
        }
    }

    $rows = $xp->query('.//tr', $table);
    if ($rows !== false && $rows->length) {
        $r = $rows->item(0);
        if ($r && strcasecmp(ns($r->textContent), 'Race Results') === 0 && $r->parentNode) {
            $r->parentNode->removeChild($r);
        }
    }

    $headBits = [];
    $styles = $xp->query('//head/style | //head/link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="stylesheet"]');
    if ($styles !== false) foreach ($styles as $s) $headBits[] = outer_html($dom, $s);

    $bodyClass = '';
    $bodies = $xp->query('//body');
    if ($bodies !== false && $bodies->length && $bodies->item(0) instanceof DOMElement) {
        $bodyClass = $bodies->item(0)->getAttribute('class');
    }

    $tableHtml = outer_html($dom, $table);
    $fallback = '<style>html,body{margin:0;padding:0;background:#fff}body{font-family:Arial,Helvetica,sans-serif}.mrl-lite-wrap{display:inline-block;min-width:675px;margin:8px;border:1px solid #888;background:#fff}.mrl-lite-title{padding:7px 10px;background:#7a430f;color:#fff;font-size:16px;font-weight:700;line-height:1.2}.mrl-lite-table-wrap table{border-collapse:collapse;width:100%}.mrl-lite-table-wrap th,.mrl-lite-table-wrap td{white-space:nowrap}.mrl-lite-table-wrap a{color:inherit;text-decoration:none;pointer-events:none}</style>';

    $html = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<meta name=\"robots\" content=\"noindex,nofollow\">\n<title>" . h($title) . "</title>\n" . implode("\n", $headBits) . "\n" . $fallback . "\n</head>\n<body class=\"" . h($bodyClass) . "\">\n<div class=\"mrl-lite-wrap\">\n<div class=\"mrl-lite-title\">" . h($title) . "</div>\n<div class=\"mrl-lite-table-wrap\">\n" . $tableHtml . "\n</div>\n</div>\n<!-- Source: " . h($sourceBase) . " -->\n</body>\n</html>\n";
    return ['ok'=>true,'html'=>$html];
}

$report = [];
$totals = ['source'=>0,'created'=>0,'skipped'=>0,'would'=>0,'errors'=>0];

if (!is_dir($yearDir)) {
    $report[] = ['status'=>'ERROR','source'=>$yearDir,'target'=>'','message'=>'Year directory not found.'];
    $totals['errors']++;
} else {
    $folders = glob($yearDir . DIRECTORY_SEPARATOR . 'R*', GLOB_ONLYDIR) ?: [];
    sort($folders, SORT_STRING);
    foreach ($folders as $raceDir) {
        $folder = basename($raceDir);
        $code = race_code($folder);
        if ($code === null) continue;
        $name = short_name($folder);
        $snaps = source_snapshots($raceDir);
        foreach ($snaps as $idx => $sourcePath) {
            $totals['source']++;
            $sourceBase = basename($sourcePath);
            $info = snapshot_info($sourceBase);
            if ($info === null) { $totals['errors']++; continue; }
            $version = $idx + 1;
            $title = $year . ' ' . $code . ' ' . $name . ' v' . $version . ' (' . $info['display'] . ')';
            $targetBase = preg_replace('/\.html$/', '_lite.html', $sourceBase);
            $targetPath = $raceDir . DIRECTORY_SEPARATOR . $targetBase;

            if (is_file($targetPath) && !$overwrite) {
                $report[] = ['status'=>'SKIP','source'=>$folder.'/'.$sourceBase,'target'=>$targetBase,'message'=>'Lite file already exists.'];
                $totals['skipped']++;
                continue;
            }

            $result = create_lite($sourcePath, $title, $sourceBase);
            if (!$result['ok']) {
                $report[] = ['status'=>'ERROR','source'=>$folder.'/'.$sourceBase,'target'=>$targetBase,'message'=>$result['error']];
                $totals['errors']++;
                continue;
            }

            if (!$execute) {
                $report[] = ['status'=>'WOULD CREATE','source'=>$folder.'/'.$sourceBase,'target'=>$targetBase,'message'=>$title];
                $totals['would']++;
                continue;
            }

            $bytes = @file_put_contents($targetPath, $result['html']);
            if ($bytes === false) {
                $report[] = ['status'=>'ERROR','source'=>$folder.'/'.$sourceBase,'target'=>$targetBase,'message'=>'Could not write lite file.'];
                $totals['errors']++;
                continue;
            }

            $mtime = @filemtime($sourcePath);
            if ($mtime !== false) @touch($targetPath, $mtime, $mtime);

            $report[] = ['status'=>'CREATED','source'=>$folder.'/'.$sourceBase,'target'=>$targetBase,'message'=>$title.' — '.number_format($bytes).' bytes'];
            $totals['created']++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Snapshot Lite Backfill</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:24px;background:#f3f3f3;color:#111}h1{margin:0 0 8px}.panel{background:#fff;border:1px solid #bbb;border-radius:8px;padding:16px;margin:14px 0}.summary{display:flex;gap:18px;flex-wrap:wrap;font-weight:700}a.button{display:inline-block;padding:10px 14px;border-radius:6px;border:1px solid #555;background:#1769c2;color:#fff;text-decoration:none;font-weight:700}a.secondary{background:#555}table{border-collapse:collapse;width:100%;background:#fff}th,td{border:1px solid #bbb;padding:7px 8px;text-align:left;vertical-align:top}th{background:#ddd}tr:nth-child(even) td{background:#f7f7f7}.ok{color:#087b2e;font-weight:700}.skip{color:#666;font-weight:700}.warn{color:#a15c00;font-weight:700}.error{color:#c00000;font-weight:700}code{font-family:Consolas,monospace}
</style>
</head>
<body>
<h1>MRL Snapshot Lite Backfill</h1>
<div><strong>Mode:</strong> <?= $execute ? 'CREATE FILES' : 'DRY RUN ONLY' ?></div>
<div class="panel">
<div class="summary">
<span>Source snapshots: <?= (int)$totals['source'] ?></span>
<span>Would create: <?= (int)$totals['would'] ?></span>
<span>Created: <?= (int)$totals['created'] ?></span>
<span>Skipped: <?= (int)$totals['skipped'] ?></span>
<span>Errors: <?= (int)$totals['errors'] ?></span>
</div>
<p><?= $execute ? 'Backfill complete. Verify several files, then delete this script.' : 'Review the list below. Nothing has been written yet.' ?></p>
<?php if (!$execute): ?><a class="button" href="?execute=1">Create Lite Files</a><?php else: ?><a class="button secondary" href="?">Run Dry Check Again</a><?php endif; ?>
</div>
<table>
<thead><tr><th>Status</th><th>Source</th><th>Target</th><th>Details</th></tr></thead>
<tbody>
<?php foreach ($report as $row): ?>
<tr>
<td class="<?= $row['status']==='CREATED'?'ok':($row['status']==='SKIP'?'skip':($row['status']==='WOULD CREATE'?'warn':'error')) ?>"><?= h($row['status']) ?></td>
<td><code><?= h($row['source']) ?></code></td>
<td><code><?= h($row['target']) ?></code></td>
<td><?= h($row['message']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
