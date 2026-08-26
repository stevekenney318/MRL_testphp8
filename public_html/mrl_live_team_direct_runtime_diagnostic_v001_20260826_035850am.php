<?php
/**
 * MRL LIVE team.php Direct Runtime Diagnostic
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 3:58:50 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 3:58:50 am)
 * - Initial direct LIVE-host runtime diagnostic.
 * - Intended to run from /public_html on manliusracingleague.com so
 *   DOCUMENT_ROOT, host, cookies/session context, and relative paths match LIVE.
 * - Verifies team.php is the restored approved v034 hash.
 * - Includes team.php inside an output buffer and captures Throwable/shutdown fatal errors.
 * - Discards team.php output.
 * - Makes NO file, DB, scheduler, or WordPress changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/
 *
 * IMPORTANT:
 * - Upload this file to LIVE public_html, not TESTPHP8.
 * - Open it on https://manliusracingleague.com/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_LDRD_VERSION = 'v001';
const EXPECTED_TEAM_SHA256 = 'ea4542182638143549230d4b47ec51e6148ff018d0a799c1a8c7af0971cfcdfc';

function ldrd_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ldrd_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function ldrd_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function ldrd_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function ldrd_version(string $p): string {
    $text = ldrd_read($p);
    foreach ([
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/'
    ] as $rx) {
        if (preg_match($rx, $text, $m)) return strtolower((string)$m[1]);
    }
    return '';
}

$root = rtrim(ldrd_norm(__DIR__), '/');
$expectedLocation = (bool)preg_match('#/public_html$#', $root)
    && stripos((string)($_SERVER['HTTP_HOST'] ?? ''), 'manliusracingleague.com') !== false
    && stripos((string)($_SERVER['HTTP_HOST'] ?? ''), 'testphp8.') === false;

$teamPath = $root . '/team.php';

$now = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$teamSha = ldrd_sha($teamPath);
$teamVersion = ldrd_version($teamPath);
$hashGate = $teamSha === EXPECTED_TEAM_SHA256;
$versionGate = $teamVersion === 'v034';

$shutdownError = null;

register_shutdown_function(static function () use (&$shutdownError) {
    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (in_array($err['type'], $fatalTypes, true)) {
        $shutdownError = $err;
    }
});

$runRequested = isset($_GET['run']) && $_GET['run'] === '1';

$result = [
    'attempted' => false,
    'caught' => false,
    'throwable_type' => '',
    'message' => '',
    'file' => '',
    'line' => null,
    'headers_before' => [],
    'headers_after' => [],
    'http_host' => $_SERVER['HTTP_HOST'] ?? '',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'session_status_before' => session_status(),
];

if ($runRequested) {
    $result['attempted'] = true;
    $result['headers_before'] = headers_list();

    ob_start();

    try {
        include $teamPath;
    } catch (Throwable $e) {
        $result['caught'] = true;
        $result['throwable_type'] = get_class($e);
        $result['message'] = $e->getMessage();
        $result['file'] = ldrd_norm($e->getFile());
        $result['line'] = $e->getLine();
    }

    $result['headers_after'] = headers_list();

    if (ob_get_level() > 0) {
        ob_end_clean();
    }
}

$result['shutdown_error'] = $shutdownError;

$report = [
    'report' => 'MRL LIVE team.php Direct Runtime Diagnostic',
    'report_version' => MRL_LDRD_VERSION,
    'generated_at' => $now,
    'read_only' => true,
    'expected_live_location' => $expectedLocation,
    'environment' => [
        'http_host' => $_SERVER['HTTP_HOST'] ?? '',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? '',
    ],
    'team_php' => [
        'path' => ldrd_norm($teamPath),
        'version' => $teamVersion,
        'sha256' => $teamSha,
        'hash_match' => $hashGate,
        'version_match' => $versionGate,
    ],
    'runtime' => $result,
    'safety' => [
        'file_changes' => false,
        'database_writes_intended' => false,
        'scheduler_changes' => false,
        'team_output_discarded' => true,
    ],
];

if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_live_team_direct_runtime_diagnostic_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
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
<title>MRL LIVE team.php Direct Runtime Diagnostic</title>
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
<div class="small">v001 · <?= ldrd_h($now) ?> · READ ONLY</div>

<div class="summary">
<div class="pill">LIVE location: <span class="<?= $expectedLocation ? 'pass' : 'fail' ?>"><?= $expectedLocation ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">team.php hash: <span class="<?= $hashGate ? 'pass' : 'fail' ?>"><?= $hashGate ? 'MATCH' : 'FAIL' ?></span></div>
<div class="pill">team.php version: <span class="<?= $versionGate ? 'pass' : 'fail' ?>"><?= ldrd_h($teamVersion ?: 'UNKNOWN') ?></span></div>
</div>
</div>

<div class="panel">
<h2>Environment</h2>
<p>Host: <code><?= ldrd_h($_SERVER['HTTP_HOST'] ?? '') ?></code></p>
<p>DOCUMENT_ROOT: <code><?= ldrd_h($_SERVER['DOCUMENT_ROOT'] ?? '') ?></code></p>
</div>

<div class="panel">
<h2>Runtime check</h2>

<?php if (!$runRequested): ?>
<p>This runs from the real LIVE host context and includes LIVE <code>team.php</code> while discarding page output.</p>
<a class="button" href="?run=1">RUN DIRECT LIVE RUNTIME CHECK</a>
<?php else: ?>

<?php if ($result['caught']): ?>
<p class="fail">CAUGHT <?= ldrd_h($result['throwable_type']) ?></p>
<pre><?= ldrd_h($result['message']) . "\nFile: " . ldrd_h($result['file']) . "\nLine: " . ldrd_h($result['line']) ?></pre>
<?php elseif ($shutdownError): ?>
<p class="fail">FATAL ERROR CAPTURED</p>
<pre><?= ldrd_h(json_encode($shutdownError, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
<?php else: ?>
<p class="pass">No catchable Throwable or shutdown fatal was captured in direct LIVE context.</p>
<p class="warn">If team.php itself still returns HTTP 500 after this, the next step is the Hostinger PHP error log for the exact request.</p>
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
