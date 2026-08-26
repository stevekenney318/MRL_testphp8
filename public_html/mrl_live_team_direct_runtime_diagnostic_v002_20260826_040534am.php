<?php
/**
 * MRL LIVE team.php Direct Runtime Diagnostic
 *
 * VERSION: v002
 * LAST MODIFIED: 8/26/2026 4:05:34 am
 *
 * CHANGELOG:
 * v002 (8/26/2026 4:05:34 am)
 * - Corrected v001 diagnostic bug caused by variable-name collision with variables
 *   defined inside included team.php (specifically a PDOStatement overwrote the
 *   diagnostic's $result array).
 * - Executes team.php inside a dedicated closure with collision-resistant variable names.
 * - Captures output length, Throwable details, and shutdown fatal details without
 *   reusing common variable names such as $result.
 * - Preserves LIVE request context and makes NO file/database/scheduler changes.
 *
 * v001 (8/26/2026 3:58:50 am)
 * - Initial direct LIVE-host runtime diagnostic.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_LDRD_VERSION = 'v002';
const EXPECTED_TEAM_SHA256 = 'ea4542182638143549230d4b47ec51e6148ff018d0a799c1a8c7af0971cfcdfc';

function ldrd2_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ldrd2_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function ldrd2_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function ldrd2_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function ldrd2_version(string $p): string {
    $text = ldrd2_read($p);
    foreach ([
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/'
    ] as $rx) {
        if (preg_match($rx, $text, $m)) return strtolower((string)$m[1]);
    }
    return '';
}

function ldrd2_execute_team(string $__mrl_diag_team_path): array {
    $__mrl_diag_payload = [
        'caught' => false,
        'throwable_type' => '',
        'message' => '',
        'file' => '',
        'line' => null,
        'output_length' => 0,
        'output_started' => false,
    ];

    ob_start();

    try {
        include $__mrl_diag_team_path;
    } catch (Throwable $__mrl_diag_throwable) {
        $__mrl_diag_payload['caught'] = true;
        $__mrl_diag_payload['throwable_type'] = get_class($__mrl_diag_throwable);
        $__mrl_diag_payload['message'] = $__mrl_diag_throwable->getMessage();
        $__mrl_diag_payload['file'] = ldrd2_norm($__mrl_diag_throwable->getFile());
        $__mrl_diag_payload['line'] = $__mrl_diag_throwable->getLine();
    }

    $__mrl_diag_output = '';
    if (ob_get_level() > 0) {
        $__mrl_diag_output = (string)ob_get_clean();
    }

    $__mrl_diag_payload['output_length'] = strlen($__mrl_diag_output);
    $__mrl_diag_payload['output_started'] = $__mrl_diag_payload['output_length'] > 0;

    return $__mrl_diag_payload;
}

$__mrl_diag_root = rtrim(ldrd2_norm(__DIR__), '/');
$__mrl_diag_expected_location = (bool)preg_match('#/public_html$#', $__mrl_diag_root)
    && stripos((string)($_SERVER['HTTP_HOST'] ?? ''), 'manliusracingleague.com') !== false
    && stripos((string)($_SERVER['HTTP_HOST'] ?? ''), 'testphp8.') === false;

$__mrl_diag_team_path = $__mrl_diag_root . '/team.php';

$__mrl_diag_now = date('Y-m-d H:i:s T');
$__mrl_diag_stamp = strtolower(date('Ymd_hisA'));

$__mrl_diag_team_sha = ldrd2_sha($__mrl_diag_team_path);
$__mrl_diag_team_version = ldrd2_version($__mrl_diag_team_path);
$__mrl_diag_hash_gate = $__mrl_diag_team_sha === EXPECTED_TEAM_SHA256;
$__mrl_diag_version_gate = $__mrl_diag_team_version === 'v034';

$__mrl_diag_shutdown_error = null;

register_shutdown_function(static function () use (&$__mrl_diag_shutdown_error) {
    $__mrl_diag_last = error_get_last();
    if (!is_array($__mrl_diag_last)) {
        return;
    }

    $__mrl_diag_fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (in_array($__mrl_diag_last['type'], $__mrl_diag_fatal_types, true)) {
        $__mrl_diag_shutdown_error = $__mrl_diag_last;
    }
});

$__mrl_diag_run_requested = isset($_GET['run']) && $_GET['run'] === '1';

$__mrl_diag_runtime = [
    'attempted' => false,
    'caught' => false,
    'throwable_type' => '',
    'message' => '',
    'file' => '',
    'line' => null,
    'output_length' => 0,
    'output_started' => false,
    'http_host' => $_SERVER['HTTP_HOST'] ?? '',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'script_name' => $_SERVER['SCRIPT_NAME'] ?? '',
    'php_self' => $_SERVER['PHP_SELF'] ?? '',
];

if ($__mrl_diag_run_requested) {
    $__mrl_diag_runtime['attempted'] = true;
    $__mrl_diag_exec = ldrd2_execute_team($__mrl_diag_team_path);

    foreach ($__mrl_diag_exec as $__mrl_diag_key => $__mrl_diag_value) {
        $__mrl_diag_runtime[$__mrl_diag_key] = $__mrl_diag_value;
    }
}

$__mrl_diag_report = [
    'report' => 'MRL LIVE team.php Direct Runtime Diagnostic',
    'report_version' => MRL_LDRD_VERSION,
    'generated_at' => $__mrl_diag_now,
    'read_only' => true,
    'expected_live_location' => $__mrl_diag_expected_location,
    'environment' => [
        'http_host' => $_SERVER['HTTP_HOST'] ?? '',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? '',
    ],
    'team_php' => [
        'path' => ldrd2_norm($__mrl_diag_team_path),
        'version' => $__mrl_diag_team_version,
        'sha256' => $__mrl_diag_team_sha,
        'hash_match' => $__mrl_diag_hash_gate,
        'version_match' => $__mrl_diag_version_gate,
    ],
    'runtime' => $__mrl_diag_runtime,
    'shutdown_error' => $__mrl_diag_shutdown_error,
    'interpretation_note' => 'If team.php renders output here without a Throwable, but direct /team.php still returns HTTP 500, compare Hostinger PHP error log for the direct /team.php request because request-script context differs.',
    'safety' => [
        'file_changes' => false,
        'database_writes_intended' => false,
        'scheduler_changes' => false,
        'team_output_discarded' => true,
    ],
];

if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_live_team_direct_runtime_diagnostic_' . $__mrl_diag_stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($__mrl_diag_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
<title>MRL LIVE team.php Direct Runtime Diagnostic v002</title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1200px;margin:0 auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px;font-size:31px}h2{margin:0 0 13px}.small{color:var(--muted);font-size:13px}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;border:1px solid #4c7ba8;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:8px 8px 0 0}
code,pre{background:#111318;border-radius:5px}code{padding:2px 5px;color:#bddcff}pre{padding:14px;border:1px solid #303540;white-space:pre-wrap;word-break:break-word}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1>MRL LIVE team.php Direct Runtime Diagnostic</h1>
<div class="small">v002 · <?= ldrd2_h($__mrl_diag_now) ?> · READ ONLY</div>

<div class="summary">
<div class="pill">LIVE location: <span class="<?= $__mrl_diag_expected_location ? 'pass' : 'fail' ?>"><?= $__mrl_diag_expected_location ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">team.php hash: <span class="<?= $__mrl_diag_hash_gate ? 'pass' : 'fail' ?>"><?= $__mrl_diag_hash_gate ? 'MATCH' : 'FAIL' ?></span></div>
<div class="pill">team.php version: <span class="<?= $__mrl_diag_version_gate ? 'pass' : 'fail' ?>"><?= ldrd2_h($__mrl_diag_team_version ?: 'UNKNOWN') ?></span></div>
</div>
</div>

<div class="panel">
<h2>Runtime check</h2>

<?php if (!$__mrl_diag_run_requested): ?>
<p>This corrected v002 isolates diagnostic variables so team.php cannot overwrite them.</p>
<a class="button" href="?run=1">RUN DIRECT LIVE RUNTIME CHECK</a>
<?php else: ?>

<?php if ($__mrl_diag_runtime['caught']): ?>
<p class="fail">CAUGHT <?= ldrd2_h($__mrl_diag_runtime['throwable_type']) ?></p>
<pre><?= ldrd2_h($__mrl_diag_runtime['message']) . "\nFile: " . ldrd2_h($__mrl_diag_runtime['file']) . "\nLine: " . ldrd2_h($__mrl_diag_runtime['line']) ?></pre>
<?php elseif ($__mrl_diag_shutdown_error): ?>
<p class="fail">FATAL ERROR CAPTURED</p>
<pre><?= ldrd2_h(json_encode($__mrl_diag_shutdown_error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
<?php else: ?>
<p class="pass">No catchable Throwable or shutdown fatal was captured.</p>
<p>team.php produced <strong><?= (int)$__mrl_diag_runtime['output_length'] ?> bytes</strong> of page output inside the LIVE wrapper.</p>
<?php endif; ?>

<a class="button" href="?run=1&format=json">Download JSON Results</a>
<?php endif; ?>
</div>

<div class="panel">
<h2>Safety</h2>
<ul>
<li>No files changed.</li>
<li>No DB writes intended.</li>
<li>No scheduler changes.</li>
<li>team.php output discarded.</li>
</ul>
</div>

</div>
</body>
</html>
