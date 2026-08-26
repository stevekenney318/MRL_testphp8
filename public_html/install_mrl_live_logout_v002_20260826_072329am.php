<?php
/**
 * MRL Live logout.php Migration Installer
 *
 * VERSION: v002
 * LAST MODIFIED: 8/26/2026 7:23:29 am
 *
 * CHANGELOG:
 * v002 (8/26/2026 7:23:29 am)
 * - Corrected dependency gate using verified LIVE include chain:
 *     logout.php -> class.user.php -> conf.inc.php -> $mrl
 * - Requires LIVE class.user.php to contain require 'conf.inc.php' or equivalent.
 * - Requires LIVE conf.inc.php to assign $mrl.
 * - Preserves exact TEST/LIVE baseline hash checks from v001.
 * - Preview-first with timestamped LIVE backup and post-install verification.
 *
 * v001 (8/26/2026 7:12:10 am)
 * - Initial controlled one-file migration of TESTPHP8 logout.php to LIVE.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
date_default_timezone_set('America/New_York');

const MRL_LOGOUT_INSTALLER_VERSION = 'v002';
const EXPECTED_TEST_SHA256 = 'd6fcfb1b937e417f3480f985e4f1ce9abe0fb1b4c54bbd3c56a0b77c6875e5d3';
const EXPECTED_LIVE_SHA256 = 'b6b406cb824821440b01f02c14e4139a6a2f0fd485c8c911d7234bcab718fb54';

function li2_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function li2_norm(string $p): string {
    return str_replace('\\', '/', $p);
}
function li2_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}
function li2_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

$selfDir = rtrim(li2_norm(__DIR__), '/');
$expectedLocation = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$testRoot = $selfDir;
$liveRoot = dirname($testRoot);

$testLogout = $testRoot . '/logout.php';
$liveLogout = $liveRoot . '/logout.php';
$liveClassUser = $liveRoot . '/class.user.php';
$liveConf = $liveRoot . '/conf.inc.php';

$now = date('Y-m-d H:i:s T');

$testSha = li2_sha($testLogout);
$liveSha = li2_sha($liveLogout);

$testContent = li2_read($testLogout);
$classUserContent = li2_read($liveClassUser);
$confContent = li2_read($liveConf);

$sourceHashGate = $testSha === EXPECTED_TEST_SHA256;
$liveHashGate = $liveSha === EXPECTED_LIVE_SHA256;

$sourceBehaviorGate =
    strpos($testContent, '$user->redirect($mrl);') !== false
    && strpos($testContent, "require_once 'class.user.php';") !== false;

$classUserGate =
    is_file($liveClassUser)
    && is_readable($liveClassUser)
    && preg_match('/\brequire(?:_once)?\s*(?:\(|)\s*[\'"]conf\.inc\.php[\'"]\s*\)?\s*;?/i', $classUserContent);

$confMrlGate =
    is_file($liveConf)
    && is_readable($liveConf)
    && preg_match('/\$mrl\s*=\s*[\'"]https:\/\/manliusracingleague\.com\/[\'"]\s*;?/i', $confContent);

$dependencyGate = (bool)$classUserGate && (bool)$confMrlGate;

$preflightPass =
    $expectedLocation
    && $sourceHashGate
    && $liveHashGate
    && $sourceBehaviorGate
    && $dependencyGate;

$installRequested = isset($_POST['confirm_install']) && $_POST['confirm_install'] === 'YES';
$installAttempted = false;
$installSuccess = false;
$backupPath = '';
$log = array();

if ($installRequested) {
    $installAttempted = true;

    if (!$preflightPass) {
        $log[] = 'STOP: Preflight is not clean. No changes made.';
    } else {
        try {
            $stamp = date('Ymd_His');
            $backupDir = $liveRoot . '/_migration_backups/logout_migration_' . $stamp;
            $backupPath = $backupDir . '/logout.php';

            $log[] = 'BEGIN logout.php MIGRATION';
            $log[] = 'Timestamp: ' . $now;
            $log[] = 'TEST source: ' . li2_norm($testLogout);
            $log[] = 'LIVE target: ' . li2_norm($liveLogout);

            if (!is_dir($backupDir)) {
                if (!@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                    throw new RuntimeException('Could not create backup directory.');
                }
            }

            if (!@copy($liveLogout, $backupPath)) {
                throw new RuntimeException('Could not back up LIVE logout.php.');
            }

            if (li2_sha($backupPath) !== EXPECTED_LIVE_SHA256) {
                throw new RuntimeException('Backup verification failed.');
            }

            $log[] = 'BACKUP: ' . li2_norm($backupPath);

            if (li2_sha($testLogout) !== EXPECTED_TEST_SHA256) {
                throw new RuntimeException('TEST source changed after preview.');
            }

            if (li2_sha($liveLogout) !== EXPECTED_LIVE_SHA256) {
                throw new RuntimeException('LIVE logout.php changed after preview.');
            }

            if (!@copy($testLogout, $liveLogout)) {
                throw new RuntimeException('Could not install TESTPHP8 logout.php to LIVE.');
            }

            $installedSha = li2_sha($liveLogout);
            $installedContent = li2_read($liveLogout);

            if ($installedSha !== EXPECTED_TEST_SHA256) {
                throw new RuntimeException('Installed LIVE logout.php hash does not match approved TEST source.');
            }

            if (strpos($installedContent, '$user->redirect($mrl);') === false) {
                throw new RuntimeException('Installed LIVE logout.php does not contain expected $mrl redirect.');
            }

            $log[] = 'INSTALLED: logout.php from approved TESTPHP8 source.';
            $log[] = 'VERIFY PASS: LIVE logout.php hash matches approved TEST source.';
            $log[] = 'VERIFY PASS: logout.php uses $mrl redirect.';
            $log[] = 'VERIFY PASS: class.user.php loads conf.inc.php.';
            $log[] = 'VERIFY PASS: conf.inc.php defines $mrl.';
            $log[] = 'SUCCESS';
            $log[] = 'NEXT: Open LIVE team.php while logged in.';
            $log[] = 'NEXT: Click Logout and confirm you return to MRL normally.';
            $log[] = 'NEXT: Log back in and confirm team.php loads normally.';
            $log[] = 'NEXT: Report result before final shutdown certification.';

            $installSuccess = true;

        } catch (Throwable $e) {
            $log[] = 'FAIL: ' . $e->getMessage();

            if (
                $backupPath !== ''
                && is_file($backupPath)
                && li2_sha($backupPath) === EXPECTED_LIVE_SHA256
            ) {
                if (@copy($backupPath, $liveLogout) && li2_sha($liveLogout) === EXPECTED_LIVE_SHA256) {
                    $log[] = 'ROLLBACK PASS: original LIVE logout.php restored.';
                } else {
                    $log[] = 'ROLLBACK WARNING: automatic restore could not be verified.';
                }
            }

            $log[] = 'STOP and report this output.';
        }
    }
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
<title>MRL Live logout.php Migration Installer</title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1250px;margin:auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px}.small{color:var(--muted)}.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
button{padding:12px 18px;border-radius:8px;border:1px solid #4c7ba8;background:#205b8c;color:#fff;font-weight:700;font-size:16px;cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
code,pre{background:#111318;border-radius:5px}code{padding:2px 5px;color:#bddcff}pre{padding:13px;border:1px solid #303540;white-space:pre-wrap;word-break:break-word}
.notice{padding:13px 15px;border-radius:9px;background:#24272e;border:1px solid #404651;margin:12px 0}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1>MRL Live logout.php Migration Installer</h1>
<div class="small">v002 · <?= li2_h($now) ?> · Preview-first controlled migration</div>

<div class="notice">
<strong>Current mode:</strong>
<?php if (!$installAttempted): ?>
<span class="info">PREVIEW ONLY — NOTHING HAS BEEN CHANGED</span>
<?php elseif ($installSuccess): ?>
<span class="pass">INSTALL COMPLETE</span>
<?php else: ?>
<span class="fail">INSTALL ATTEMPTED — REVIEW OUTPUT</span>
<?php endif; ?>
</div>

<div class="summary">
<div class="pill">Preflight: <span class="<?= $preflightPass ? 'pass' : 'fail' ?>"><?= $preflightPass ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">TEST source: <span class="<?= $sourceHashGate ? 'pass' : 'fail' ?>"><?= $sourceHashGate ? 'MATCH' : 'FAIL' ?></span></div>
<div class="pill">LIVE baseline: <span class="<?= $liveHashGate ? 'pass' : 'fail' ?>"><?= $liveHashGate ? 'MATCH' : 'FAIL' ?></span></div>
<div class="pill">$mrl redirect: <span class="<?= $sourceBehaviorGate ? 'pass' : 'fail' ?>"><?= $sourceBehaviorGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">class.user → conf.inc: <span class="<?= $classUserGate ? 'pass' : 'fail' ?>"><?= $classUserGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">conf.inc defines $mrl: <span class="<?= $confMrlGate ? 'pass' : 'fail' ?>"><?= $confMrlGate ? 'PASS' : 'FAIL' ?></span></div>
</div>
</div>

<div class="panel">
<h2>Verified dependency path</h2>
<pre>logout.php
  └─ require_once 'class.user.php'
       └─ require 'conf.inc.php'
            └─ $mrl = 'https://manliusracingleague.com/';</pre>
</div>

<?php if (!$installAttempted): ?>
<div class="panel">
<h2>INSTALL</h2>
<p>Only LIVE <code>logout.php</code> will be replaced. The current LIVE copy is backed up first.</p>
<form method="post" onsubmit="return confirm('Migrate TESTPHP8 logout.php to LIVE now?');">
<input type="hidden" name="confirm_install" value="YES">
<button type="submit" <?= $preflightPass ? '' : 'disabled' ?>>INSTALL LIVE logout.php</button>
</form>
</div>
<?php endif; ?>

<?php if ($installAttempted): ?>
<div class="panel">
<h2>Installer output</h2>
<pre><?= li2_h(implode("\n", $log)) ?></pre>
</div>
<?php endif; ?>

<div class="panel">
<h2>Safety</h2>
<ul>
<li>Only LIVE <code>logout.php</code> can be changed.</li>
<li>Timestamped backup first.</li>
<li>Exact TEST and LIVE hashes rechecked immediately before write.</li>
<li>Post-install hash and behavior verification.</li>
<li>Automatic rollback attempted on verification failure.</li>
<li>No DB, scheduler, WordPress, race_results, or other application changes.</li>
</ul>
</div>

</div>
</body>
</html>
