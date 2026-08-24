<?php
declare(strict_types=1);

/**
 * mrl_admin_setup_environment_banner_patch.php
 *
 * VERSION: v003
 * LAST MODIFIED: 8/19/2026 3:30:00 pm
 *
 * DESCRIPTION:
 * TestPHP8-only visual patch for admin_setup.php.
 *
 * Updates the compact MRL Automatic Pick Window page so its top banner
 * identifies the active environment using the same visual language as
 * race_results_dashboard.php:
 *
 * - Live MRL: green production banner.
 * - TestPHP8: gold/brown demo banner.
 *
 * This patch changes presentation only. No database, pick-window, scoring,
 * LP, RD, schedule, or submission logic is changed.
 *
 * TARGET:
 * - admin_setup.php v002 -> v003
 *
 * SAFETY:
 * - Refuses to install unless host is testphp8.manliusracingleague.com.
 * - Refuses to install unless admin_setup.php is exactly the expected v002.
 * - Verifies each source marker occurs exactly once before enabling INSTALL.
 * - Creates a timestamped backup before replacing admin_setup.php.
 * - No database changes.
 *
 * CHANGELOG:
 * v003 (8/19/2026 3:30:00 pm)
 * - UI: Replaced generic top title card with environment-aware site banner.
 * - UI: TestPHP8 shows TESTPHP8 / DEMO SITE with demo/test-data pill.
 * - UI: Live-compatible rendering shows LIVE MRL SITE with Production site pill.
 * - UI: Preserved pick-window status and year pills inside the same compact banner.
 * - CHANGE: No functional logic changes.
 */

date_default_timezone_set('America/New_York');

const PATCH_VERSION = 'v003';
const PATCH_TIMESTAMP = '20260819_033000pm';
const REQUIRED_HOST = 'testphp8.manliusracingleague.com';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function ph(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function replace_once(string $content, string $old, string $new, string $label): string
{
    $count = substr_count($content, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly one source marker, found ' . $count . '.');
    }
    return str_replace($old, $new, $content);
}

function atomic_write(string $path, string $content): void
{
    $dir = dirname($path);
    $tmp = $dir . '/.' . basename($path) . '.env_banner_' . mt_rand(100000, 999999) . '.tmp';

    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary file: ' . $tmp);
    }

    @chmod($tmp, 0644);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace target file: ' . $path);
    }
}

function patch_admin_setup(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v002\n * LAST MODIFIED: 8/19/2026 3:08:00 pm\n",
        " * VERSION: v003\n * LAST MODIFIED: 8/19/2026 3:30:00 pm\n",
        'admin_setup.php version header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n * v002 (8/19/2026 3:08:00 pm)\n",
        " * CHANGELOG:\n"
        . " * v003 (8/19/2026 3:30:00 pm)\n"
        . " * - UI: Replaced generic top title card with environment-aware site banner matching race_results_dashboard styling.\n"
        . " * - UI: TestPHP8 shows TESTPHP8 / DEMO SITE with demo/test-data status; Live-compatible rendering shows LIVE MRL SITE with Production site status.\n"
        . " * - UI: Pick-window state and year remain visible as compact pills inside the same banner.\n"
        . " * - CHANGE: Presentation only; no pick-window, schedule, database, scoring, LP, RD, or submission logic changes.\n"
        . " *\n"
        . " * v002 (8/19/2026 3:08:00 pm)\n",
        'admin_setup.php changelog'
    );

    $src = replace_once(
        $src,
        ".page-title{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border:1px solid #4b4233;border-radius:14px;background:linear-gradient(180deg,#22211e,#191919);margin-bottom:10px}\n"
        . ".page-title h1{margin:0;color:#ffd08a;font-size:24px;letter-spacing:.2px}.subtitle{color:#aaa;font-size:13px;margin-top:3px}\n",
        ".env-banner{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 16px;border:1px solid #4b4233;border-radius:14px;margin-bottom:10px;background:linear-gradient(180deg,#22211e,#191919)}\n"
        . ".env-banner.live{border-color:#326a49;background:linear-gradient(180deg,#193124,#1b211e)}\n"
        . ".env-banner.test{border-color:#9a7014;background:linear-gradient(180deg,#3a3118,#282417)}\n"
        . ".env-left{min-width:0}.env-title{margin:0;color:#fff4df;font-size:24px;font-weight:800;letter-spacing:1.2px}.env-domain{color:#ddd;font-size:13px;margin-top:1px}.env-page{color:#ffd08a;font-size:13px;font-weight:bold;margin-top:5px}.env-right{display:flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:wrap}.env-site-pill{border:1px solid #4d473f;border-radius:999px;padding:5px 11px;font-size:12px;font-weight:bold;white-space:nowrap}.env-site-pill.live{border-color:#286c48;background:#173526;color:#62e89b}.env-site-pill.test{border-color:#826820;background:#4a3c19;color:#ffd166}\n"
        . ".subtitle{color:#aaa;font-size:13px;margin-top:3px}\n",
        'admin_setup.php banner CSS'
    );

    $oldBanner = '<div class="page-title"><div><h1>MRL Automatic Pick Window</h1><div class="subtitle">Compact control center — schedule-derived by default, exceptions explicit.</div></div><div class="status-line"><span class="pill <?php echo $pickWindowIsOpen?\'good\':\'warn\'; ?>"><?php echo ah($pickWindowStatus); ?></span><span class="pill"><?php echo ah($raceYear); ?></span></div></div>';

    $newBanner = <<<'BANNER'
<?php
$adminHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$adminIsTest = (strpos($adminHost, 'testphp8.') === 0);
$adminEnvClass = $adminIsTest ? 'test' : 'live';
$adminEnvTitle = $adminIsTest ? 'TESTPHP8 / DEMO SITE' : 'LIVE MRL SITE';
$adminEnvDomain = $adminIsTest ? 'testphp8.manliusracingleague.com' : 'manliusracingleague.com';
$adminEnvPill = $adminIsTest ? 'Demo / test data' : 'Production site';
?>
<div class="env-banner <?php echo ah($adminEnvClass); ?>">
    <div class="env-left">
        <div class="env-title"><?php echo ah($adminEnvTitle); ?></div>
        <div class="env-domain"><?php echo ah($adminEnvDomain); ?></div>
        <div class="env-page">MRL Automatic Pick Window</div>
    </div>
    <div class="env-right">
        <span class="env-site-pill <?php echo ah($adminEnvClass); ?>"><?php echo ah($adminEnvPill); ?></span>
        <span class="pill <?php echo $pickWindowIsOpen?'good':'warn'; ?>"><?php echo ah($pickWindowStatus); ?></span>
        <span class="pill"><?php echo ah($raceYear); ?></span>
    </div>
</div>
BANNER;

    $src = replace_once($src, $oldBanner, $newBanner, 'admin_setup.php top banner');

    return $src;
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$target = $root . '/admin_setup.php';

$checks = [];
$errors = [];
$prepared = '';
$installComplete = false;
$backupDir = '';

$checks[] = ['Host is TestPHP8', $host === REQUIRED_HOST ? 'PASS' : 'FAIL'];
if ($host !== REQUIRED_HOST) {
    $errors[] = 'This patch refuses to run anywhere except ' . REQUIRED_HOST . '.';
}

if (!is_file($target)) {
    $checks[] = ['admin_setup.php exists', 'FAIL'];
    $errors[] = 'admin_setup.php was not found.';
} else {
    $checks[] = ['admin_setup.php exists', 'PASS'];
    $current = (string)file_get_contents($target);

    $versionOk = strpos($current, ' * VERSION: v002') !== false
        && strpos($current, ' * LAST MODIFIED: 8/19/2026 3:08:00 pm') !== false;
    $checks[] = ['admin_setup.php current version v002', $versionOk ? 'PASS' : 'FAIL'];

    if (!$versionOk) {
        $errors[] = 'admin_setup.php is not the expected v002 source.';
    } else {
        try {
            $prepared = patch_admin_setup($current);

            $structureOk = strpos($prepared, ' * VERSION: v003') !== false
                && strpos($prepared, 'TESTPHP8 / DEMO SITE') !== false
                && strpos($prepared, 'LIVE MRL SITE') !== false
                && strpos($prepared, 'MRL Automatic Pick Window') !== false
                && strpos($prepared, 'Current Effective State') !== false
                && strpos($prepared, 'Temporary Pick Window Override') !== false
                && strpos($prepared, 'System Settings') !== false;

            $checks[] = ['v003 environment-banner replacement prepared', $structureOk ? 'PASS' : 'FAIL'];
            if (!$structureOk) {
                $errors[] = 'Generated v003 replacement failed structural validation.';
            }
        } catch (Throwable $e) {
            $checks[] = ['All patch markers matched exactly once', 'FAIL'];
            $errors[] = $e->getMessage();
        }
    }
}

if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    try {
        $backupDir = $root . '/mrl_admin_setup_environment_banner_backup_' . PATCH_TIMESTAMP;
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            throw new RuntimeException('Could not create backup folder.');
        }

        $backupFile = $backupDir . '/admin_setup.php';
        if (!copy($target, $backupFile)) {
            throw new RuntimeException('Could not back up admin_setup.php.');
        }

        atomic_write($target, $prepared);
        $installed = (string)file_get_contents($target);

        if (strpos($installed, ' * VERSION: v003') === false
            || strpos($installed, 'TESTPHP8 / DEMO SITE') === false) {
            @copy($backupFile, $target);
            throw new RuntimeException('Post-install validation failed. Original file restored.');
        }

        $installComplete = true;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL Admin Setup Environment Banner Patch v003</title>
<style>
body{margin:0;background:#151515;color:#eee;font:16px Arial,sans-serif}
.wrap{width:min(1120px,94%);margin:28px auto}
h1,h2{color:#ffd08a}.card{background:#222;border:1px solid #4d473f;border-radius:14px;padding:18px 22px;margin:14px 0}
table{width:100%;border-collapse:collapse}td{padding:8px 10px;border-bottom:1px solid #3a3a3a}.pass{color:#62e89b}.fail{color:#ff7d7d}
.btn{font:16px Arial;background:#3a2f1b;color:#ffd08a;border:1px solid #b18745;border-radius:9px;padding:10px 15px;cursor:pointer}
small{color:#aaa}.ok{color:#62e89b;font-weight:bold}.bad{color:#ff7d7d}
</style>
</head>
<body>
<div class="wrap">
<h1>MRL Admin Setup Environment Banner Patch v003</h1>
<div class="card">
<b>Target:</b> <?php echo ph($host); ?><br>
<b>Root:</b> <?php echo ph($root); ?><br>
<b>Build:</b> 8/19/2026 3:30:00 pm ET
</div>

<div class="card">
<h2>Preflight</h2>
<table>
<?php foreach ($checks as $row): ?>
<tr><td><?php echo ph($row[0]); ?></td><td class="<?php echo $row[1] === 'PASS' ? 'pass' : 'fail'; ?>"><?php echo ph($row[1]); ?></td></tr>
<?php endforeach; ?>
</table>
</div>

<?php if ($errors): ?>
<div class="card"><h2 class="bad">STOPPED</h2><ul><?php foreach ($errors as $e): ?><li><?php echo ph($e); ?></li><?php endforeach; ?></ul><p>No installation should be performed from this state.</p></div>
<?php elseif ($installComplete): ?>
<div class="card"><h2 class="ok">INSTALL COMPLETE.</h2>
<p><b>Backup folder:</b><br><?php echo ph($backupDir); ?></p>
<p>admin_setup.php is now v003 with the environment-aware banner. No database or pick-window logic was changed.</p>
<p><b>Suggested check:</b> Open <code>/admin_setup.php</code> and confirm the TestPHP8 banner matches the race-results dashboard visual language while the three compact panels remain unchanged.</p>
</div>
<?php else: ?>
<div class="card"><h2>Ready</h2>
<p>Preflight passed. INSTALL will:</p>
<ul>
<li>Back up the current admin_setup.php v002.</li>
<li>Install admin_setup.php v003.</li>
<li>Add the environment-aware TestPHP8 / Live banner.</li>
<li>Keep the existing pick-window status and year pills in the same top row.</li>
<li>Make no database changes and no functional logic changes.</li>
<li>Leave Live MRL untouched.</li>
</ul>
<form method="post"><button class="btn" name="action" value="install">INSTALL ENVIRONMENT BANNER PATCH v003</button></form>
</div>
<?php endif; ?>
</div>
</body>
</html>
