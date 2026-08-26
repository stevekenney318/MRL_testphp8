<?php
/**
 * MRL TESTPHP8 Final Three Decision Audit
 *
 * VERSION: v002
 * LAST MODIFIED: 8/26/2026 6:41:15 am
 *
 * CHANGELOG:
 * v002 (8/26/2026 6:41:15 am)
 * - Simplified v001 after HTTP 500 on TESTPHP8.
 * - Reuses only the proven root-level scanning approach from shutdown audit v003.
 * - Removes full-file diff generation and arrow-function callbacks.
 * - Reads exactly three small target files only.
 * - Scans only LIVE root-level files for references.
 * - Produces concise recommendations and JSON/TXT exports.
 * - Makes NO changes.
 *
 * v001 (8/26/2026 6:35:42 am)
 * - Initial three-file decision audit.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
date_default_timezone_set('America/New_York');

const MRL_F3_VERSION = 'v002';
const MRL_F3_TITLE = 'MRL TESTPHP8 Final Three Decision Audit';

function f3v2_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function f3v2_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function f3v2_read(string $p): ?string {
    if (!is_file($p) || !is_readable($p)) {
        return null;
    }
    $d = @file_get_contents($p);
    return is_string($d) ? $d : null;
}

function f3v2_sha(string $p): ?string {
    if (!is_file($p) || !is_readable($p)) {
        return null;
    }
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : null;
}

function f3v2_info(string $p): array {
    if (!is_file($p)) {
        return array(
            'exists' => false,
            'path' => f3v2_norm($p),
            'sha256' => null,
            'size' => null,
            'mtime' => null,
            'content' => null
        );
    }

    $m = @filemtime($p);
    $s = @filesize($p);

    return array(
        'exists' => true,
        'path' => f3v2_norm($p),
        'sha256' => f3v2_sha($p),
        'size' => is_int($s) ? $s : null,
        'mtime' => is_int($m) ? date('Y-m-d H:i:s T', $m) : null,
        'content' => f3v2_read($p)
    );
}

function f3v2_root_files(string $root): array {
    $out = array();
    $items = @scandir($root);

    if (!is_array($items)) {
        return $out;
    }

    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $full = $root . '/' . $name;

        if (!is_file($full)) {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, array('php','html','htm','js','css','txt','md'), true)) {
            continue;
        }

        $out[$name] = f3v2_norm($full);
    }

    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function f3v2_refs(string $liveRoot, string $needle): array {
    $hits = array();
    $files = f3v2_root_files($liveRoot);

    foreach ($files as $name => $full) {
        $text = f3v2_read($full);

        if ($text === null || $text === '') {
            continue;
        }

        $lines = preg_split('/\R/', $text);

        if (!is_array($lines)) {
            continue;
        }

        foreach ($lines as $i => $line) {
            if (stripos($line, $needle) === false) {
                continue;
            }

            $trim = trim($line);
            $commentOnly = preg_match('#^\s*(//|/\*|\*|<!--)#', $trim) ? true : false;

            $hits[] = array(
                'file' => $name,
                'line' => $i + 1,
                'text' => $trim,
                'comment_only' => $commentOnly
            );
        }
    }

    return $hits;
}

function f3v2_active_refs(array $hits): array {
    $out = array();

    foreach ($hits as $hit) {
        if (empty($hit['comment_only'])) {
            $out[] = $hit;
        }
    }

    return $out;
}

function f3v2_trim_content(?string $text): string {
    if ($text === null) {
        return '[MISSING]';
    }

    if (strlen($text) <= 12000) {
        return $text;
    }

    return substr($text, 0, 12000) . "\n\n[TRUNCATED BY AUDIT]";
}

$testRoot = rtrim(f3v2_norm(__DIR__), '/');
$liveRoot = dirname($testRoot);
$expectedLocation = preg_match('#/public_html/testphp8$#', $testRoot) ? true : false;

$generatedAt = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$targetNames = array(
    'clean_race_finish_confirmation_data_v001_20260726_073602am.php',
    'logout.php',
    'sandbox.php'
);

$items = array();
$reviewRemaining = array();
$doNotMigrateCount = 0;

foreach ($targetNames as $name) {
    $testPath = $testRoot . '/' . $name;
    $livePath = $liveRoot . '/' . $name;

    $testInfo = f3v2_info($testPath);
    $liveInfo = f3v2_info($livePath);

    $allRefs = f3v2_refs($liveRoot, $name);
    $activeRefs = f3v2_active_refs($allRefs);

    $sameHash = false;

    if (
        $testInfo['sha256'] !== null &&
        $liveInfo['sha256'] !== null &&
        $testInfo['sha256'] === $liveInfo['sha256']
    ) {
        $sameHash = true;
    }

    $recommendation = 'REVIEW';
    $reason = '';

    if ($name === 'clean_race_finish_confirmation_data_v001_20260726_073602am.php') {
        if (count($activeRefs) === 0) {
            $recommendation = 'DO_NOT_MIGRATE';
            $reason = 'Old race-finish-confirmation cleanup utility; TEST-only; no active root-level LIVE references.';
        } else {
            $reason = 'Unexpected active LIVE reference exists.';
        }
    } elseif ($name === 'sandbox.php') {
        if (count($activeRefs) === 0) {
            $recommendation = 'DO_NOT_MIGRATE';
            $reason = 'Sandbox utility; differing TEST copy has no active root-level LIVE references. Preserve LIVE.';
        } else {
            $reason = 'Active LIVE reference exists; inspect before deciding.';
        }
    } elseif ($name === 'logout.php') {
        $reason = 'Active production file with differing TEST/LIVE contents. Deliberate comparison required.';
    }

    if ($recommendation === 'DO_NOT_MIGRATE') {
        $doNotMigrateCount++;
    } else {
        $reviewRemaining[] = $name;
    }

    $items[$name] = array(
        'test' => $testInfo,
        'live' => $liveInfo,
        'same_hash' => $sameHash,
        'active_root_live_references' => $activeRefs,
        'active_root_live_reference_count' => count($activeRefs),
        'recommendation' => $recommendation,
        'recommendation_reason' => $reason
    );
}

$report = array(
    'report' => MRL_F3_TITLE,
    'report_version' => MRL_F3_VERSION,
    'generated_at' => $generatedAt,
    'read_only' => true,
    'expected_location' => $expectedLocation,
    'scope' => 'Exactly three remaining shutdown blockers; root-level LIVE reference scan only.',
    'summary' => array(
        'files_reviewed' => count($items),
        'do_not_migrate' => $doNotMigrateCount,
        'review_remaining' => count($reviewRemaining),
        'review_remaining_files' => $reviewRemaining
    ),
    'items' => $items,
    'safety' => array(
        'files_changed' => false,
        'database_writes' => false,
        'scheduler_changes' => false,
        'subdirectories_entered' => false
    )
);

function f3v2_txt(array $report): string {
    $out = array();

    $out[] = MRL_F3_TITLE;
    $out[] = 'Version: ' . MRL_F3_VERSION;
    $out[] = 'Generated: ' . $report['generated_at'];
    $out[] = '';

    foreach ($report['items'] as $name => $item) {
        $out[] = $name;
        $out[] = 'Recommendation: ' . $item['recommendation'];
        $out[] = 'Reason: ' . $item['recommendation_reason'];
        $out[] = 'Active root LIVE references: ' . $item['active_root_live_reference_count'];
        $out[] = 'Same hash: ' . ($item['same_hash'] ? 'YES' : 'NO');
        $out[] = '';
    }

    return implode("\r\n", $out) . "\r\n";
}

$format = strtolower((string)($_GET['format'] ?? ''));

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_final_three_decision_v002_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_final_three_decision_v002_' . $stamp . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo f3v2_txt($report);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= f3v2_h(MRL_F3_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1450px;margin:auto}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px}.small{color:var(--muted)}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:10px 8px 0 0}
code,pre{background:#111318;border-radius:5px}code{padding:2px 5px;color:#bddcff}
pre{padding:13px;border:1px solid #303540;white-space:pre-wrap;word-break:break-word;max-height:500px;overflow:auto}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1><?= f3v2_h(MRL_F3_TITLE) ?></h1>
<div class="small">v002 · <?= f3v2_h($generatedAt) ?> · READ ONLY</div>

<div class="summary">
<div class="pill">Files reviewed: <strong><?= count($items) ?></strong></div>
<div class="pill">DO NOT MIGRATE: <span class="pass"><?= $doNotMigrateCount ?></span></div>
<div class="pill">Review remaining: <span class="<?= count($reviewRemaining) === 0 ? 'pass' : 'warn' ?>"><?= count($reviewRemaining) ?></span></div>
</div>

<a class="button" href="?format=json">Download JSON Results</a>
<a class="button" href="?format=txt">Download TXT Results</a>
</div>

<?php foreach ($items as $name => $item): ?>
<div class="panel">
<h2><code><?= f3v2_h($name) ?></code></h2>

<p>
Recommendation:
<strong class="<?= $item['recommendation'] === 'DO_NOT_MIGRATE' ? 'pass' : 'warn' ?>">
<?= f3v2_h($item['recommendation']) ?>
</strong>
</p>

<p><?= f3v2_h($item['recommendation_reason']) ?></p>

<p>Active root-level LIVE references: <strong><?= (int)$item['active_root_live_reference_count'] ?></strong></p>
<p>TEST/LIVE same hash: <strong><?= $item['same_hash'] ? 'YES' : 'NO' ?></strong></p>

<h3>TEST content</h3>
<pre><?= f3v2_h(f3v2_trim_content($item['test']['content'])) ?></pre>

<h3>LIVE content</h3>
<pre><?= f3v2_h(f3v2_trim_content($item['live']['content'])) ?></pre>
</div>
<?php endforeach; ?>

</div>
</body>
</html>
