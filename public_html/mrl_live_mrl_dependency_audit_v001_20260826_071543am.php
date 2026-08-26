<?php
/**
 * MRL LIVE $mrl Dependency Audit
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 7:15:43 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 7:15:43 am)
 * - Read-only dependency audit created after logout migration installer v001
 *   correctly blocked because its $mrl evidence check was too narrow.
 * - Scans only root-level LIVE PHP files.
 * - Finds direct assignments/usages of $mrl.
 * - Shows class.user.php include/require statements.
 * - Shows logout.php and team.php $mrl usage evidence.
 * - Makes NO changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
date_default_timezone_set('America/New_York');

const MRL_DEP_AUDIT_VERSION = 'v001';
const MRL_DEP_AUDIT_TITLE = 'MRL LIVE $mrl Dependency Audit';

function da_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function da_norm(string $p): string {
    return str_replace('\\', '/', $p);
}
function da_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}
function da_root_php_files(string $root): array {
    $out = array();
    $items = @scandir($root);
    if (!is_array($items)) return $out;

    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $root . '/' . $name;
        if (!is_file($full)) continue;
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'php') continue;
        $out[$name] = da_norm($full);
    }

    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}
function da_lines_with(string $text, string $needle): array {
    $hits = array();
    $lines = preg_split('/\R/', $text);
    if (!is_array($lines)) return $hits;

    foreach ($lines as $i => $line) {
        if (strpos($line, $needle) !== false) {
            $hits[] = array(
                'line' => $i + 1,
                'text' => trim($line)
            );
        }
    }
    return $hits;
}
function da_assignment_hits(string $text): array {
    $hits = array();
    $lines = preg_split('/\R/', $text);
    if (!is_array($lines)) return $hits;

    foreach ($lines as $i => $line) {
        if (preg_match('/\$mrl\s*=/', $line)) {
            $hits[] = array(
                'line' => $i + 1,
                'text' => trim($line)
            );
        }
    }
    return $hits;
}
function da_include_hits(string $text): array {
    $hits = array();
    $lines = preg_split('/\R/', $text);
    if (!is_array($lines)) return $hits;

    foreach ($lines as $i => $line) {
        if (preg_match('/\b(require|require_once|include|include_once)\b/i', $line)) {
            $hits[] = array(
                'line' => $i + 1,
                'text' => trim($line)
            );
        }
    }
    return $hits;
}

$testRoot = rtrim(da_norm(__DIR__), '/');
$liveRoot = dirname($testRoot);
$expectedLocation = preg_match('#/public_html/testphp8$#', $testRoot) ? true : false;

$generatedAt = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$rootFiles = da_root_php_files($liveRoot);

$assignmentFiles = array();
$usageFiles = array();

foreach ($rootFiles as $name => $full) {
    $text = da_read($full);
    if ($text === '') continue;

    $assignments = da_assignment_hits($text);
    if (count($assignments) > 0) {
        $assignmentFiles[$name] = $assignments;
    }

    $usages = da_lines_with($text, '$mrl');
    if (count($usages) > 0) {
        $usageFiles[$name] = $usages;
    }
}

$classUserPath = $liveRoot . '/class.user.php';
$teamPath = $liveRoot . '/team.php';
$logoutPath = $liveRoot . '/logout.php';

$classUserIncludes = da_include_hits(da_read($classUserPath));
$teamMrlHits = da_lines_with(da_read($teamPath), '$mrl');
$logoutMrlHits = da_lines_with(da_read($logoutPath), '$mrl');

$assignmentGate = count($assignmentFiles) > 0;
$teamUsageGate = count($teamMrlHits) > 0;
$classUserExistsGate = is_file($classUserPath) && is_readable($classUserPath);

$overallPass = $expectedLocation && $assignmentGate && $teamUsageGate && $classUserExistsGate;

$report = array(
    'report' => MRL_DEP_AUDIT_TITLE,
    'report_version' => MRL_DEP_AUDIT_VERSION,
    'generated_at' => $generatedAt,
    'read_only' => true,
    'expected_location' => $expectedLocation,
    'live_root' => da_norm($liveRoot),
    'gates' => array(
        'mrl_assignment_found_in_live_root' => $assignmentGate,
        'team_php_uses_mrl' => $teamUsageGate,
        'class_user_exists' => $classUserExistsGate,
        'overall_pass' => $overallPass
    ),
    'mrl_assignment_files' => $assignmentFiles,
    'mrl_usage_files' => $usageFiles,
    'class_user_include_require_lines' => $classUserIncludes,
    'team_php_mrl_lines' => $teamMrlHits,
    'logout_php_mrl_lines' => $logoutMrlHits,
    'safety' => array(
        'files_changed' => false,
        'database_writes' => false,
        'scheduler_changes' => false,
        'subdirectories_entered' => false
    )
);

$format = strtolower((string)($_GET['format'] ?? ''));

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_live_mrl_dependency_audit_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
<title><?= da_h(MRL_DEP_AUDIT_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1400px;margin:auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px}.small{color:var(--muted)}.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin-top:10px}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}
code,pre{background:#111318;border-radius:5px}code{padding:2px 5px;color:#bddcff}pre{padding:13px;border:1px solid #303540;white-space:pre-wrap}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1><?= da_h(MRL_DEP_AUDIT_TITLE) ?></h1>
<div class="small">v001 · <?= da_h($generatedAt) ?> · READ ONLY</div>

<div class="summary">
<div class="pill">Overall: <span class="<?= $overallPass ? 'pass' : 'fail' ?>"><?= $overallPass ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">$mrl assignment found: <span class="<?= $assignmentGate ? 'pass' : 'fail' ?>"><?= $assignmentGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">team.php uses $mrl: <span class="<?= $teamUsageGate ? 'pass' : 'fail' ?>"><?= $teamUsageGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">class.user.php: <span class="<?= $classUserExistsGate ? 'pass' : 'fail' ?>"><?= $classUserExistsGate ? 'PASS' : 'FAIL' ?></span></div>
</div>

<a class="button" href="?format=json">Download JSON Results</a>
</div>

<div class="panel">
<h2>$mrl assignment(s) found in LIVE root files</h2>
<?php if (count($assignmentFiles) === 0): ?>
<p class="fail">No direct root-level $mrl assignment found.</p>
<?php else: ?>
<?php foreach ($assignmentFiles as $name => $hits): ?>
<h3><code><?= da_h($name) ?></code></h3>
<pre><?= da_h(json_encode($hits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
<?php endforeach; ?>
<?php endif; ?>
</div>

<div class="panel">
<h2>class.user.php include/require lines</h2>
<pre><?= da_h(json_encode($classUserIncludes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>

</div>
</body>
</html>
