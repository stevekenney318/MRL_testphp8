<?php
/**
 * MRL Live team.php Runtime Diagnostic
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 3:51:55 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 3:51:55 am)
 * - Initial read-only runtime diagnostic after LIVE team.php HTTP 500 persisted
 *   even after exact v034 backup restore.
 * - Verifies LIVE team.php hash/version.
 * - Executes LIVE team.php in an isolated output buffer with Throwable capture.
 * - Reports fatal/parse/runtime exception message, file, and line when catchable.
 * - Registers shutdown handler to capture uncaught fatal errors.
 * - Discards generated page output so this tool is diagnostic only.
 * - Makes NO file, database, scheduler, or WordPress changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 *
 * NOTE:
 * - The backup copy under /_migration_backups/ is not expected to run correctly
 *   by URL because its relative includes resolve from the backup directory.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_TRD_VERSION = 'v001';
const EXPECTED_TEAM_SHA256 = 'ea4542182638143549230d4b47ec51e6148ff018d0a799c1a8c7af0971cfcdfc';

function trd_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function trd_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function trd_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function trd_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function trd_version(string $p): string {
    $text = trd_read($p);
    foreach ([
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/'
    ] as $rx) {
        if (preg_match($rx, $text, $m)) return strtolower((string)$m[1]);
    }
    return '';
}

$selfDir = rtrim(trd_norm(__DIR__), '/');
$expectedLocation = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$liveRoot = dirname($selfDir);
$teamPath = $liveRoot . '/team.php';

$now = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$teamSha = trd_sha($teamPath);
$teamVersion = trd_version($teamPath);
$hashGate = $teamSha === EXPECTED_TEAM_SHA256;
$versionGate = $teamVersion === 'v034';

$diagnostic = [
    'attempted' => false,
    'caught' => false,
    'type' => '',
    'message' => '',
    'file' => '',
    'line' => null,
    'shutdown_error' => null,
];

$shutdownCapture = null;

register_shutdown_function(static function () use (&$shutdownCapture) {
    $err = error_get_last();
    if (is_array($err)) {
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (in_array($err['type'], $fatalTypes, true)) {
            $shutdownCapture = $err;
        }
    }
});

$runRequested = isset($_GET['run']) && $_GET['run'] === '1';

if ($runRequested) {
    $diagnostic['attempted'] = true;

    $oldCwd = getcwd();
    @chdir($liveRoot);

    ob_start();

    try {
        include $teamPath;
    } catch (Throwable $e) {
        $diagnostic['caught'] = true;
        $diagnostic['type'] = get_class($e);
        $diagnostic['message'] = $e->getMessage();
        $diagnostic['file'] = trd_norm($e->getFile());
        $diagnostic['line'] = $e->getLine();
    }

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($oldCwd !== false) {
        @chdir($oldCwd);
    }
}

$report = [
    'report' => 'MRL Live team.php Runtime Diagnostic',
    'report_version' => MRL_TRD_VERSION,
    'generated_at' => $now,
    'read_only' => true,
    'expected_location' => $expectedLocation,
    'live_team' => [
        'path' => trd_norm($teamPath),
        'version' => $teamVersion,
        'sha256' => $teamSha,
        'expected_hash_match' => $hashGate,
        'expected_version_match' => $versionGate,
    ],
    'runtime_diagnostic' => $diagnostic,
    'backup_url_note' => 'A team.php copy inside /_migration_backups/ is not expected to run correctly by URL because relative include paths resolve from the backup directory.',
    'safety' => [
        'file_changes' => false,
        'database_writes_intended' => false,
        'scheduler_changes' => false,
        'output_discarded' => true,
    ],
];

if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_live_team_runtime_diagnostic_' . $stamp . '.json"');
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
<title>MRL Live team.php Runtime Diagnostic</title>
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
<h1>MRL Live team.php Runtime Diagnostic</h1>
<div class="small">v001 · <?= trd_h($now) ?> · READ ONLY</div>

<div class="summary">
<div class="pill">team.php hash: <span class="<?= $hashGate ? 'pass' : 'fail' ?>"><?= $hashGate ? 'MATCH v034' : 'MISMATCH' ?></span></div>
<div class="pill">team.php version: <span class="<?= $versionGate ? 'pass' : 'fail' ?>"><?= trd_h($teamVersion ?: 'UNKNOWN') ?></span></div>
</div>
</div>

<div class="panel">
<h2>Important note about the backup URL</h2>
<p>
The backup copy under <code>/_migration_backups/.../team.php</code> is <strong>not expected to work as a web page</strong>.
Its relative includes such as <code>config.php</code>, <code>class.user.php</code>, etc. resolve from the backup directory instead of the LIVE root.
So that backup URL returning an error does not mean the backup itself is bad.
</p>
</div>

<div class="panel">
<h2>Runtime check</h2>

<?php if (!$runRequested): ?>
<p>This will execute LIVE <code>team.php</code> inside a diagnostic wrapper, discard its page output, and capture a PHP Throwable if one occurs.</p>
<a class="button" href="?run=1">RUN READ-ONLY RUNTIME CHECK</a>
<?php else: ?>
<?php if ($diagnostic['caught']): ?>
<p class="fail">CAUGHT <?= trd_h($diagnostic['type']) ?></p>
<pre><?= trd_h($diagnostic['message']) . "\nFile: " . trd_h($diagnostic['file']) . "\nLine: " . trd_h($diagnostic['line']) ?></pre>
<?php else: ?>
<p class="pass">No catchable Throwable was raised while including team.php.</p>
<p class="warn">If the public page still returns HTTP 500, use the JSON result plus the Hostinger PHP error log for the next diagnosis.</p>
<?php endif; ?>

<a class="button" href="?run=1&format=json">Download JSON Results</a>
<?php endif; ?>
</div>

<div class="panel">
<h2>Safety</h2>
<ul>
<li>No files are modified.</li>
<li>No scheduler changes.</li>
<li>Generated team page output is discarded.</li>
<li>This is diagnostic only.</li>
</ul>
</div>

</div>
</body>
</html>
