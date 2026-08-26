<?php
/**
 * MRL Emergency Restore - LIVE team.php v034
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 3:47:06 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 3:47:06 am)
 * - Emergency one-file rollback after team.php v035 produced HTTP 500.
 * - Verifies current LIVE team.php is the known broken v035 hash.
 * - Verifies the exact pre-change backup exists and matches approved v034 hash.
 * - Restores ONLY LIVE team.php from that backup.
 * - Verifies restored file hash/version after copy.
 * - Makes no database, scheduler, WordPress, or race_results changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_RESTORE_VERSION = 'v001';
const EXPECTED_BROKEN_SHA256 = 'a6a7a6cdba714ad982175a69932b70ebb27626cadd6b4c07e3b09c2384975126';
const EXPECTED_GOOD_SHA256   = 'ea4542182638143549230d4b47ec51e6148ff018d0a799c1a8c7af0971cfcdfc';

function rr_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rr_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function rr_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function rr_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function rr_version(string $p): string {
    $text = rr_read($p);
    foreach ([
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/'
    ] as $rx) {
        if (preg_match($rx, $text, $m)) return strtolower((string)$m[1]);
    }
    return '';
}

$selfDir = rtrim(rr_norm(__DIR__), '/');
$expectedLocation = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$liveRoot = dirname($selfDir);
$teamPath = $liveRoot . '/team.php';
$backupPath = $liveRoot . '/_migration_backups/team_debug_cleanup_20260826_034452/team.php';

$now = date('Y-m-d H:i:s T');

$currentSha = rr_sha($teamPath);
$currentVersion = rr_version($teamPath);
$backupSha = rr_sha($backupPath);
$backupVersion = rr_version($backupPath);

$locationGate = $expectedLocation;
$currentGate = $currentSha === EXPECTED_BROKEN_SHA256 && $currentVersion === 'v035';
$backupGate = is_file($backupPath) && $backupSha === EXPECTED_GOOD_SHA256 && $backupVersion === 'v034';

$preflightOk = $locationGate && $currentGate && $backupGate;

$restoreRequested = isset($_POST['confirm_restore']) && $_POST['confirm_restore'] === 'YES';
$restoreAttempted = false;
$restoreSuccess = false;
$log = [];

if ($restoreRequested) {
    $restoreAttempted = true;

    if (!$preflightOk) {
        $log[] = 'STOP: Restore preflight is not clean. No file changes made.';
    } else {
        try {
            $log[] = 'BEGIN EMERGENCY RESTORE';
            $log[] = 'Timestamp: ' . $now;
            $log[] = 'Backup source: ' . $backupPath;
            $log[] = 'LIVE target: ' . $teamPath;

            if (!@copy($backupPath, $teamPath)) {
                throw new RuntimeException('Could not restore backup over LIVE team.php.');
            }

            $verifySha = rr_sha($teamPath);
            $verifyVersion = rr_version($teamPath);

            if ($verifySha !== EXPECTED_GOOD_SHA256) {
                throw new RuntimeException('Post-restore hash mismatch.');
            }

            if ($verifyVersion !== 'v034') {
                throw new RuntimeException('Post-restore version mismatch; expected v034, got ' . $verifyVersion . '.');
            }

            $log[] = 'RESTORED: team.php v034';
            $log[] = 'VERIFY PASS: restored hash matches approved v034.';
            $log[] = 'SUCCESS';
            $log[] = 'NEXT: Refresh LIVE team.php and confirm the page loads normally.';
            $log[] = 'NEXT: Do not retry the v035 installer.';

            $restoreSuccess = true;

        } catch (Throwable $e) {
            $log[] = 'FAIL: ' . $e->getMessage();
            $log[] = 'STOP and report this output.';
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL Emergency Restore - team.php v034</title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1200px;margin:0 auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px;font-size:31px}.small{color:var(--muted);font-size:13px}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
button{padding:12px 18px;border-radius:8px;border:1px solid #a44141;background:#7a2525;color:white;font-weight:700;font-size:16px;cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
code,pre{background:#111318;border-radius:5px}code{padding:2px 5px;color:#bddcff}pre{padding:14px;border:1px solid #303540;white-space:pre-wrap;word-break:break-word}
.notice{padding:13px 15px;border-radius:9px;background:#24272e;border:1px solid #404651;margin:12px 0}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1>MRL Emergency Restore - LIVE team.php v034</h1>
<div class="small">v001 · <?= rr_h($now) ?></div>

<div class="notice">
<strong>Current mode:</strong>
<?php if ($restoreAttempted): ?>
    <span class="<?= $restoreSuccess ? 'pass' : 'fail' ?>">
        <?= $restoreSuccess ? 'RESTORE COMPLETE' : 'RESTORE ATTEMPTED — REVIEW OUTPUT' ?>
    </span>
<?php else: ?>
    <span class="info">PREVIEW ONLY — NO FILE CHANGES</span>
<?php endif; ?>
</div>

<div class="summary">
<div class="pill">Preflight: <span class="<?= $preflightOk ? 'pass' : 'fail' ?>"><?= $preflightOk ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Current broken v035: <span class="<?= $currentGate ? 'pass' : 'fail' ?>"><?= $currentGate ? 'MATCH' : 'FAIL' ?></span></div>
<div class="pill">Backup v034: <span class="<?= $backupGate ? 'pass' : 'fail' ?>"><?= $backupGate ? 'MATCH' : 'FAIL' ?></span></div>
</div>
</div>

<div class="panel">
<h2>Restore source</h2>
<p><code><?= rr_h(rr_norm($backupPath)) ?></code></p>
<p>Backup version: <strong><?= rr_h($backupVersion) ?></strong></p>
</div>

<?php if (!$restoreAttempted): ?>
<div class="panel">
<h2>RESTORE</h2>
<p>This will restore only LIVE <code>team.php</code> to the exact pre-change v034 backup.</p>
<form method="post" onsubmit="return confirm('Restore LIVE team.php to the known-good v034 backup now?');">
<input type="hidden" name="confirm_restore" value="YES">
<button type="submit" <?= $preflightOk ? '' : 'disabled' ?>>RESTORE LIVE team.php v034</button>
</form>
</div>
<?php endif; ?>

<?php if ($restoreAttempted): ?>
<div class="panel">
<h2>Restore output</h2>
<pre><?= rr_h(implode("\n", $log)) ?></pre>
</div>
<?php endif; ?>

</div>
</body>
</html>
