<?php
/**
 * MRL TESTPHP8 Final Three Decision Audit
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 6:35:42 am
 *
 * PURPOSE:
 * Read-only final decision audit for the three remaining TESTPHP8 shutdown blockers:
 *   1) clean_race_finish_confirmation_data_v001_20260726_073602am.php
 *   2) logout.php
 *   3) sandbox.php
 *
 * This tool:
 * - Reads TESTPHP8 and LIVE copies where present.
 * - Shows exact file contents for these small files.
 * - Produces line-by-line unified-style comparisons.
 * - Scans ROOT-LEVEL LIVE custom files only for active references to each filename.
 * - Applies the already-established project rule that old race-finish-confirmation
 *   experiment/cleanup tooling is not a migration candidate unless an active dependency exists.
 * - Makes NO file, DB, scheduler, WordPress, or race_results changes.
 * - Provides JSON/TXT exports.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
date_default_timezone_set('America/New_York');

const MRL_F3_VERSION = 'v001';
const MRL_F3_TITLE = 'MRL TESTPHP8 Final Three Decision Audit';

function f3_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function f3_norm(string $p): string {
    return str_replace('\\', '/', $p);
}
function f3_read(string $p): ?string {
    if (!is_file($p) || !is_readable($p)) return null;
    $d = @file_get_contents($p);
    return is_string($d) ? $d : null;
}
function f3_sha(string $p): ?string {
    if (!is_file($p) || !is_readable($p)) return null;
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : null;
}
function f3_info(string $p): array {
    if (!is_file($p)) {
        return [
            'exists' => false,
            'path' => f3_norm($p),
            'sha256' => null,
            'size' => null,
            'mtime' => null,
            'content' => null,
        ];
    }
    $m = @filemtime($p);
    $s = @filesize($p);
    return [
        'exists' => true,
        'path' => f3_norm($p),
        'sha256' => f3_sha($p),
        'size' => is_int($s) ? $s : null,
        'mtime' => is_int($m) ? date('Y-m-d H:i:s T', $m) : null,
        'content' => f3_read($p),
    ];
}
function f3_root_files(string $root): array {
    $out = [];
    $items = @scandir($root);
    if (!is_array($items)) return $out;

    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $root . '/' . $name;
        if (!is_file($full)) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['php','html','htm','js','css','txt','md'], true)) continue;

        $out[$name] = f3_norm($full);
    }

    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}
function f3_refs(string $liveRoot, string $needle): array {
    $hits = [];
    foreach (f3_root_files($liveRoot) as $name => $full) {
        $text = f3_read($full);
        if ($text === null || $text === '') continue;

        $lines = preg_split('/\R/', $text);
        if (!is_array($lines)) continue;

        foreach ($lines as $i => $line) {
            if (stripos($line, $needle) === false) continue;

            $trim = trim($line);
            $commentOnly = (bool)preg_match('#^\s*(//|/\*|\*|<!--)#', $trim);

            $hits[] = [
                'file' => $name,
                'line' => $i + 1,
                'text' => $trim,
                'comment_only' => $commentOnly,
            ];
        }
    }
    return $hits;
}
function f3_active_refs(array $hits): array {
    return array_values(array_filter($hits, static function ($hit) {
        return empty($hit['comment_only']);
    }));
}
function f3_diff(?string $a, ?string $b): array {
    if ($a === null && $b === null) return [];
    if ($a === $b) return ['NO DIFFERENCE'];

    $al = $a === null ? [] : preg_split('/\R/', $a);
    $bl = $b === null ? [] : preg_split('/\R/', $b);
    if (!is_array($al)) $al = [];
    if (!is_array($bl)) $bl = [];

    $max = max(count($al), count($bl));
    $out = [];

    for ($i = 0; $i < $max; $i++) {
        $av = array_key_exists($i, $al) ? $al[$i] : null;
        $bv = array_key_exists($i, $bl) ? $bl[$i] : null;

        if ($av === $bv) {
            if ($av !== null) $out[] = '  ' . str_pad((string)($i + 1), 4, ' ', STR_PAD_LEFT) . ' | ' . $av;
            continue;
        }

        if ($av !== null) {
            $out[] = '- ' . str_pad((string)($i + 1), 4, ' ', STR_PAD_LEFT) . ' | ' . $av;
        }
        if ($bv !== null) {
            $out[] = '+ ' . str_pad((string)($i + 1), 4, ' ', STR_PAD_LEFT) . ' | ' . $bv;
        }
    }

    return $out;
}

$testRoot = rtrim(f3_norm(__DIR__), '/');
$liveRoot = dirname($testRoot);
$expectedLocation = (bool)preg_match('#/public_html/testphp8$#', $testRoot);

$generatedAt = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$targets = [
    'clean_race_finish_confirmation_data_v001_20260726_073602am.php' => [
        'project_rule' => 'Old race-finish-confirmation cleanup/experiment tooling is not a migration candidate unless an active LIVE dependency exists.',
    ],
    'logout.php' => [
        'project_rule' => 'Active production file. TEST and LIVE difference requires deliberate content review before deciding which copy is authoritative.',
    ],
    'sandbox.php' => [
        'project_rule' => 'Sandbox/test utility. A differing copy is not automatically a migration candidate; inspect content and active references first.',
    ],
];

$items = [];

foreach ($targets as $name => $meta) {
    $testPath = $testRoot . '/' . $name;
    $livePath = $liveRoot . '/' . $name;

    $testInfo = f3_info($testPath);
    $liveInfo = f3_info($livePath);
    $refsAll = f3_refs($liveRoot, $name);
    $refsActive = f3_active_refs($refsAll);

    $recommendation = 'REVIEW';
    $reason = '';

    if ($name === 'clean_race_finish_confirmation_data_v001_20260726_073602am.php') {
        if (count($refsActive) === 0) {
            $recommendation = 'DO_NOT_MIGRATE';
            $reason = 'TEST-only race-finish-confirmation cleanup utility with zero active root-level LIVE references; covered by the established obsolete-experiment rule.';
        } else {
            $recommendation = 'REVIEW';
            $reason = 'Unexpected active LIVE reference found; inspect before classifying.';
        }

    } elseif ($name === 'sandbox.php') {
        if (count($refsActive) === 0) {
            $recommendation = 'DO_NOT_MIGRATE';
            $reason = 'Sandbox utility differs but has zero active root-level LIVE references; preserve LIVE and leave TEST sandbox behind.';
        } else {
            $recommendation = 'REVIEW';
            $reason = 'Active LIVE reference exists; inspect exact content difference before deciding.';
        }

    } elseif ($name === 'logout.php') {
        $recommendation = 'REVIEW';
        $reason = 'logout.php is actively referenced in LIVE; exact TEST/LIVE behavior must be compared before any migration decision.';
    }

    $items[$name] = [
        'project_rule' => $meta['project_rule'],
        'test' => $testInfo,
        'live' => $liveInfo,
        'same_hash' => $testInfo['sha256'] !== null
            && $liveInfo['sha256'] !== null
            && $testInfo['sha256'] === $liveInfo['sha256'],
        'active_root_live_references' => $refsActive,
        'active_root_live_reference_count' => count($refsActive),
        'all_root_live_mentions' => $refsAll,
        'comparison_test_minus_live' => f3_diff($liveInfo['content'], $testInfo['content']),
        'recommendation' => $recommendation,
        'recommendation_reason' => $reason,
    ];
}

$remainingReview = [];
foreach ($items as $name => $item) {
    if ($item['recommendation'] === 'REVIEW') {
        $remainingReview[] = $name;
    }
}

$report = [
    'report' => MRL_F3_TITLE,
    'report_version' => MRL_F3_VERSION,
    'generated_at' => $generatedAt,
    'read_only' => true,
    'expected_location' => $expectedLocation,
    'scope' => 'Exactly three remaining shutdown blockers; root-level reference scan only.',
    'items' => $items,
    'summary' => [
        'files_reviewed' => count($items),
        'do_not_migrate' => count(array_filter($items, static fn($x) => $x['recommendation'] === 'DO_NOT_MIGRATE')),
        'review_remaining' => count($remainingReview),
        'review_remaining_files' => $remainingReview,
    ],
    'safety' => [
        'files_changed' => false,
        'database_writes' => false,
        'scheduler_changes' => false,
        'subdirectories_entered' => false,
    ],
];

function f3_txt(array $r): string {
    $out = [];
    $out[] = MRL_F3_TITLE;
    $out[] = 'Version: ' . MRL_F3_VERSION;
    $out[] = 'Generated: ' . $r['generated_at'];
    $out[] = '';

    foreach ($r['items'] as $name => $item) {
        $out[] = $name;
        $out[] = 'Recommendation: ' . $item['recommendation'];
        $out[] = 'Reason: ' . $item['recommendation_reason'];
        $out[] = 'Active LIVE references: ' . $item['active_root_live_reference_count'];
        $out[] = 'TEST/LIVE same hash: ' . ($item['same_hash'] ? 'YES' : 'NO');
        $out[] = '';
    }

    return implode("\r\n", $out) . "\r\n";
}

$format = strtolower((string)($_GET['format'] ?? ''));

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_final_three_decision_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_final_three_decision_' . $stamp . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo f3_txt($report);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= f3_h(MRL_F3_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1500px;margin:auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px}.small{color:var(--muted)}.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:10px 8px 0 0}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}
code,pre{background:#111318;border-radius:5px}code{padding:2px 5px;color:#bddcff}pre{padding:13px;border:1px solid #303540;white-space:pre-wrap;word-break:break-word}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1><?= f3_h(MRL_F3_TITLE) ?></h1>
<div class="small">v001 · <?= f3_h($generatedAt) ?> · READ ONLY</div>

<div class="summary">
<div class="pill">Files reviewed: <strong><?= count($items) ?></strong></div>
<div class="pill">DO NOT MIGRATE: <span class="pass"><?= $report['summary']['do_not_migrate'] ?></span></div>
<div class="pill">Review remaining: <span class="<?= $report['summary']['review_remaining'] === 0 ? 'pass' : 'warn' ?>"><?= $report['summary']['review_remaining'] ?></span></div>
</div>

<a class="button" href="?format=json">Download JSON Results</a>
<a class="button" href="?format=txt">Download TXT Results</a>
</div>

<?php foreach ($items as $name => $item): ?>
<div class="panel">
<h2><code><?= f3_h($name) ?></code></h2>
<p>Recommendation:
<strong class="<?= $item['recommendation'] === 'DO_NOT_MIGRATE' ? 'pass' : 'warn' ?>">
<?= f3_h($item['recommendation']) ?>
</strong></p>
<p><?= f3_h($item['recommendation_reason']) ?></p>
<p>Active root-level LIVE references: <strong><?= (int)$item['active_root_live_reference_count'] ?></strong></p>
<p>TEST/LIVE same hash: <strong><?= $item['same_hash'] ? 'YES' : 'NO' ?></strong></p>

<h3>TEST content</h3>
<pre><?= f3_h($item['test']['content'] ?? '[MISSING]') ?></pre>

<h3>LIVE content</h3>
<pre><?= f3_h($item['live']['content'] ?? '[MISSING]') ?></pre>

<h3>Comparison (LIVE → TEST)</h3>
<pre><?= f3_h(implode("\n", $item['comparison_test_minus_live'])) ?></pre>
</div>
<?php endforeach; ?>

</div>
</body>
</html>
