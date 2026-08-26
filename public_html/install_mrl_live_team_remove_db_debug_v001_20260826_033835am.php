<?php
/**
 * MRL Live team.php DB Debug Removal Installer
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 3:38:35 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 3:38:35 am)
 * - Initial controlled LIVE-only patch for team.php.
 * - Preview-first: opening this page makes NO changes.
 * - Verifies LIVE team.php is still v034 at the exact approved migrated hash.
 * - Locates the visible "Connected DBs:" diagnostic output.
 * - Removes only the diagnostic output statement/block that contains that literal.
 * - Updates team.php version marker from v034 to v035.
 * - Creates a timestamped backup of LIVE team.php before writing.
 * - Verifies the diagnostic literal is gone after install.
 * - Makes no DB, scheduler, WordPress, race_results, or other file changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_TDR_VERSION = 'v001';
const EXPECTED_TEAM_SHA256 = 'ea4542182638143549230d4b47ec51e6148ff018d0a799c1a8c7af0971cfcdfc';

function tdr_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tdr_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function tdr_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function tdr_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function tdr_version_from_text(string $text): string {
    foreach ([
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/'
    ] as $rx) {
        if (preg_match($rx, $text, $m)) return strtolower((string)$m[1]);
    }
    return '';
}

function tdr_find_debug_blocks(string $text): array {
    $hits = [];

    $lines = preg_split('/\R/', $text);
    if (!is_array($lines)) return $hits;

    foreach ($lines as $i => $line) {
        if (stripos($line, 'Connected DBs:') === false) continue;

        $start = $i;
        $end = $i;

        /*
         * Expand upward/downward only when the literal lives inside a multi-line
         * echo/printf statement. Keep this intentionally conservative.
         */
        if (strpos($line, ';') === false) {
            for ($j = $i - 1; $j >= max(0, $i - 5); $j--) {
                $probe = $lines[$j];
                if (preg_match('/\b(echo|printf|sprintf)\b/i', $probe)) {
                    $start = $j;
                    break;
                }
                if (strpos($probe, ';') !== false) break;
            }

            for ($j = $i; $j <= min(count($lines) - 1, $i + 8); $j++) {
                $end = $j;
                if (strpos($lines[$j], ';') !== false) break;
            }
        }

        $block = array_slice($lines, $start, $end - $start + 1);

        $hits[] = [
            'start_line' => $start + 1,
            'end_line' => $end + 1,
            'text' => implode("\n", $block),
        ];
    }

    /* De-duplicate overlapping blocks. */
    $unique = [];
    foreach ($hits as $hit) {
        $key = $hit['start_line'] . ':' . $hit['end_line'];
        $unique[$key] = $hit;
    }

    return array_values($unique);
}

function tdr_apply_patch(string $text, array $blocks): array {
    $lines = preg_split('/\R/', $text);
    if (!is_array($lines)) {
        return ['ok' => false, 'text' => $text, 'error' => 'Could not split team.php into lines.'];
    }

    if (count($blocks) !== 1) {
        return [
            'ok' => false,
            'text' => $text,
            'error' => 'Expected exactly one Connected DBs diagnostic block; found ' . count($blocks) . '.'
        ];
    }

    $block = $blocks[0];
    $start = (int)$block['start_line'] - 1;
    $end = (int)$block['end_line'] - 1;

    array_splice($lines, $start, ($end - $start) + 1);

    $patched = implode("\n", $lines);

    if (stripos($patched, 'Connected DBs:') !== false) {
        return ['ok' => false, 'text' => $text, 'error' => 'Connected DBs literal still present after proposed removal.'];
    }

    $versionReplacements = 0;

    $patched = preg_replace_callback(
        '/(\bVERSION\s*:\s*)v034\b/i',
        static function ($m) use (&$versionReplacements) {
            $versionReplacements++;
            return $m[1] . 'v035';
        },
        $patched
    );

    if (!is_string($patched)) {
        return ['ok' => false, 'text' => $text, 'error' => 'Version-marker replacement failed.'];
    }

    if ($versionReplacements === 0) {
        /*
         * Fall back to the first standalone v034 occurrence only if a labeled
         * VERSION marker is not present.
         */
        $patched2 = preg_replace('/\bv034\b/', 'v035', $patched, 1, $count);
        if (is_string($patched2) && $count === 1) {
            $patched = $patched2;
            $versionReplacements = 1;
        }
    }

    if ($versionReplacements !== 1) {
        return [
            'ok' => false,
            'text' => $text,
            'error' => 'Expected exactly one version-marker update; got ' . $versionReplacements . '.'
        ];
    }

    if (tdr_version_from_text($patched) !== 'v035') {
        return ['ok' => false, 'text' => $text, 'error' => 'Proposed patched file does not report v035.'];
    }

    return [
        'ok' => true,
        'text' => $patched,
        'error' => '',
    ];
}

$selfDir = rtrim(tdr_norm(__DIR__), '/');
$expectedLocation = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$liveRoot = dirname($selfDir);
$teamPath = $liveRoot . '/team.php';

$now = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));
$backupStamp = date('Ymd_His');
$backupDir = $liveRoot . '/_migration_backups/team_debug_cleanup_' . $backupStamp;
$backupPath = $backupDir . '/team.php';

$currentText = tdr_read($teamPath);
$currentSha = tdr_sha($teamPath);
$currentVersion = tdr_version_from_text($currentText);

$debugBlocks = tdr_find_debug_blocks($currentText);
$patch = tdr_apply_patch($currentText, $debugBlocks);

$locationGate = $expectedLocation;
$hashGate = $currentSha === EXPECTED_TEAM_SHA256;
$versionGate = $currentVersion === 'v034';
$debugGate = count($debugBlocks) === 1;
$patchGate = $patch['ok'];

$preflightOk = $locationGate && $hashGate && $versionGate && $debugGate && $patchGate;

$installRequested = isset($_POST['confirm_install']) && $_POST['confirm_install'] === 'YES';
$installAttempted = false;
$installSuccess = false;
$installLog = [];

if ($installRequested) {
    $installAttempted = true;

    if (!$preflightOk) {
        $installLog[] = 'STOP: Preflight is not clean. No file changes made.';
    } else {
        try {
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
                throw new RuntimeException('Could not create backup directory.');
            }

            if (!@copy($teamPath, $backupPath)) {
                throw new RuntimeException('Could not back up LIVE team.php.');
            }

            $installLog[] = 'BACKUP: ' . $backupPath;

            $written = @file_put_contents($teamPath, $patch['text']);
            if ($written === false) {
                throw new RuntimeException('Could not write patched LIVE team.php.');
            }

            $verifyText = tdr_read($teamPath);
            $verifyVersion = tdr_version_from_text($verifyText);

            if (stripos($verifyText, 'Connected DBs:') !== false) {
                throw new RuntimeException('Post-install verification failed: Connected DBs diagnostic still present.');
            }

            if ($verifyVersion !== 'v035') {
                throw new RuntimeException('Post-install verification failed: expected v035, got ' . $verifyVersion . '.');
            }

            $installLog[] = 'UPDATED: team.php v034 -> v035';
            $installLog[] = 'REMOVED: visible Connected DBs diagnostic output.';
            $installLog[] = 'VERIFY PASS: Connected DBs literal is absent.';
            $installLog[] = 'VERIFY PASS: team.php reports v035.';
            $installLog[] = 'SUCCESS';
            $installLog[] = 'NEXT: Refresh LIVE team.php and confirm the diagnostic line is gone.';
            $installLog[] = 'NEXT: Confirm Admin Menu, S4 closed state, and form-team-picks.php v007 still look normal.';

            $installSuccess = true;

        } catch (Throwable $e) {
            $installLog[] = 'FAIL: ' . $e->getMessage();
            $installLog[] = 'STOP and provide this output before doing anything else.';
            $installLog[] = 'Backup, if created: ' . $backupPath;
        }
    }
}

$preview = [
    'report' => 'MRL Live team.php DB Debug Removal Installer Preview',
    'report_version' => MRL_TDR_VERSION,
    'generated_at' => $now,
    'read_only_preview' => !$installRequested,
    'expected_location' => $expectedLocation,
    'preflight_ok' => $preflightOk,
    'gates' => [
        'location' => $locationGate ? 'PASS' : 'FAIL',
        'live_team_hash' => $hashGate ? 'PASS' : 'FAIL',
        'live_team_version' => $versionGate ? 'PASS' : 'FAIL',
        'single_debug_block' => $debugGate ? 'PASS' : 'FAIL',
        'patch_build' => $patchGate ? 'PASS' : 'FAIL',
    ],
    'live_team_before' => [
        'path' => tdr_norm($teamPath),
        'version' => $currentVersion,
        'sha256' => $currentSha,
    ],
    'debug_blocks_found' => $debugBlocks,
    'proposed_action' => [
        'remove_connected_dbs_output' => true,
        'new_team_version' => 'v035',
        'admin_only_display' => false,
        'reason' => 'Diagnostic is no longer needed in normal production UI; safest cleanup is removal rather than adding new admin-role routing logic during migration closeout.',
    ],
    'backup_if_installed' => tdr_norm($backupPath),
    'install_requested' => $installRequested,
    'install_success' => $installSuccess,
    'install_log' => $installLog,
];

if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_live_team_db_debug_removal_preview_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
<title>MRL Live team.php DB Debug Removal Installer</title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1400px;margin:0 auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px;font-size:31px}h2{margin:0 0 13px}.small{color:var(--muted);font-size:13px}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;border:1px solid #4c7ba8;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:8px 8px 0 0}
button{padding:12px 18px;border-radius:8px;border:1px solid #a44141;background:#7a2525;color:white;font-weight:700;font-size:16px;cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}
code,pre{background:#111318;border-radius:5px}code{padding:2px 5px;color:#bddcff}pre{padding:14px;border:1px solid #303540;white-space:pre-wrap;word-break:break-word}
.notice{padding:13px 15px;border-radius:9px;background:#24272e;border:1px solid #404651;margin:12px 0}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1>MRL Live team.php DB Debug Removal Installer</h1>
<div class="small">v001 · <?= tdr_h($now) ?> · Preview-first LIVE-only patch</div>

<div class="notice">
<strong>Current mode:</strong>
<?php if ($installAttempted): ?>
    <span class="<?= $installSuccess ? 'pass' : 'fail' ?>">
        <?= $installSuccess ? 'INSTALL COMPLETE' : 'INSTALL ATTEMPTED — REVIEW OUTPUT' ?>
    </span>
<?php else: ?>
    <span class="info">PREVIEW ONLY — NO FILE CHANGES</span>
<?php endif; ?>
</div>

<div class="summary">
<div class="pill">Preflight: <span class="<?= $preflightOk ? 'pass' : 'fail' ?>"><?= $preflightOk ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">LIVE team hash: <span class="<?= $hashGate ? 'pass' : 'fail' ?>"><?= $hashGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">LIVE team version: <span class="<?= $versionGate ? 'pass' : 'fail' ?>"><?= $versionGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Debug block: <span class="<?= $debugGate ? 'pass' : 'fail' ?>"><?= $debugGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Patch build: <span class="<?= $patchGate ? 'pass' : 'fail' ?>"><?= $patchGate ? 'PASS' : 'FAIL' ?></span></div>
</div>

<a class="button" href="?format=json&x=<?= tdr_h((string)microtime(true)) ?>">Download JSON Preview</a>
</div>

<div class="panel">
<h2>Proposed change</h2>
<p><strong>Remove the visible production diagnostic entirely.</strong></p>
<p>I am choosing removal instead of moving it into the Admin Menu because this is migration/debug instrumentation and adding new role-routing logic now would be a larger behavioral change than necessary.</p>

<?php if ($debugBlocks): ?>
<table>
<thead><tr><th>Lines</th><th>Detected diagnostic block</th></tr></thead>
<tbody>
<?php foreach ($debugBlocks as $hit): ?>
<tr>
<td><?= tdr_h($hit['start_line']) ?>–<?= tdr_h($hit['end_line']) ?></td>
<td><pre><?= tdr_h($hit['text']) ?></pre></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<?php if (!$installAttempted): ?>
<div class="panel">
<h2>INSTALL</h2>
<p>Do not click INSTALL until the JSON preview has been reviewed.</p>
<form method="post" onsubmit="return confirm('Remove the Connected DBs diagnostic from LIVE team.php and update it to v035?');">
<input type="hidden" name="confirm_install" value="YES">
<button type="submit" <?= $preflightOk ? '' : 'disabled' ?>>INSTALL LIVE team.php v035</button>
</form>
</div>
<?php endif; ?>

<?php if ($installAttempted): ?>
<div class="panel">
<h2>Installer output</h2>
<pre><?= tdr_h(implode("\n", $installLog)) ?></pre>
</div>
<?php endif; ?>

<div class="panel">
<h2>Safety</h2>
<ul>
<li>Only LIVE team.php is changed.</li>
<li>Timestamped backup created first.</li>
<li>No database changes.</li>
<li>No scheduler changes.</li>
<li>No race_results changes.</li>
</ul>
</div>

</div>
</body>
</html>
