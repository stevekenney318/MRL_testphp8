<?php
declare(strict_types=1);

/**
 * MRL team.php Previous Years Border Alignment Installer
 *
 * VERSION: v001
 * LAST MODIFIED: 8/24/2026 4:10:00 pm
 *
 * PURPOSE
 * -------
 * Keep the prior-year charts exactly the same width as in team.php v031,
 * while moving the Previous Years decorative border outward equally on both
 * sides so the charts sit inside the border with balanced spacing.
 *
 * INSTALL RESULT
 * --------------
 * team.php v031 -> v032
 *
 * SAFETY
 * ------
 * - TESTPHP8-only
 * - Border-only visual adjustment
 * - Exact-marker preflight
 * - Backs up team.php before writing
 * - No chart width changes
 * - No database writes
 * - No scheduler changes
 * - No submit-team-picks.php changes
 * - Rollback restores v031
 * - PHP 7.3 compatible
 */

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$EXPECTED_HOST = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$teamPath = $root . '/team.php';
$backupDir = $root . '/mrl_team_previous_years_border_v032_backup_20260824_041000pm';
$backupPath = $backupDir . '/team.php.v031.before_border_alignment';

$errors = array();
$messages = array();
$checks = array();
$action = isset($_POST['action']) ? (string)$_POST['action'] : 'preflight';

function mrlb_h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlb_count($haystack, $needle)
{
    return substr_count((string)$haystack, (string)$needle);
}

function mrlb_check(&$checks, $label, $ok, $detail)
{
    $checks[] = array(
        'label' => (string)$label,
        'ok' => (bool)$ok,
        'detail' => (string)$detail
    );
    return (bool)$ok;
}

function mrlb_replace_once($content, $old, $new, $label)
{
    $count = substr_count($content, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ' expected exactly once; found ' . $count . '.');
    }
    return str_replace($old, $new, $content);
}

$oldBorder = <<<'OLD'
        .mrl-previous-years::before {
            content: "";
            position: absolute;
            top: 0;
            right: 10%;
            bottom: 0;
            left: 10%;
            border: 1px solid #666666;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.018);
            pointer-events: none;
            z-index: 0;
        }
OLD;

$newBorder = <<<'NEW'
        .mrl-previous-years::before {
            content: "";
            position: absolute;
            top: -10px;
            right: calc(10% - 22px);
            bottom: -10px;
            left: calc(10% - 22px);
            border: 1px solid #666666;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.018);
            pointer-events: none;
            z-index: 0;
        }
NEW;

if ($host !== $EXPECTED_HOST) {
    $errors[] = 'REFUSED: TESTPHP8-only. Current host: ' . $host;
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable: ' . $root;
}

$current = '';
if (empty($errors)) {
    $current = @file_get_contents($teamPath);
    if ($current === false) {
        $errors[] = 'Unable to read team.php.';
        $current = '';
    }
}

$preflightOk = empty($errors);

if ($preflightOk) {
    $preflightOk = mrlb_check(
        $checks,
        'team.php version',
        mrlb_count($current, 'VERSION: v031') === 1,
        'Expected team.php v031'
    ) && $preflightOk;

    $preflightOk = mrlb_check(
        $checks,
        'Current Previous Years border rule',
        mrlb_count($current, $oldBorder) === 1,
        'Expected exact v031 decorative border rule once'
    ) && $preflightOk;

    $preflightOk = mrlb_check(
        $checks,
        'Prior-year chart width preserved',
        mrlb_count($current, '.mrl-previous-years-content {') === 1
            && mrlb_count($current, 'width: 100%;') >= 1,
        'Chart content rule remains unchanged'
    ) && $preflightOk;

    $preflightOk = mrlb_check(
        $checks,
        'Previous Years collapsed by default',
        mrlb_count($current, '<details class="mrl-previous-years">') === 1
            && mrlb_count($current, '<details class="mrl-previous-years" open') === 0,
        'No open attribute'
    ) && $preflightOk;

    $preflightOk = mrlb_check(
        $checks,
        'Pick/form panel unchanged',
        mrlb_count($current, '.mrl-pick-panel::before') === 1,
        'Known-good v030/v031 pick panel remains present'
    ) && $preflightOk;

    $preflightOk = mrlb_check(
        $checks,
        'Permanent LP/RP bridge retained',
        mrlb_count($current, 'function teampage_get_rd_base_pick_row') === 1,
        'Permanent helper must remain present'
    ) && $preflightOk;
}

function mrlb_build_v032($content, $oldBorder, $newBorder)
{
    $content = mrlb_replace_once($content, 'VERSION: v031', 'VERSION: v032', 'version bump');

    if (preg_match('/LAST MODIFIED:\s*[^\r\n]+/', $content, $m) !== 1) {
        throw new RuntimeException('Could not identify LAST MODIFIED header.');
    }

    $content = mrlb_replace_once(
        $content,
        $m[0],
        'LAST MODIFIED: 8/24/2026 4:10:00 pm',
        'LAST MODIFIED header'
    );

    $needle = " * CHANGELOG:\n *\n";
    $entry = " * CHANGELOG:\n *\n"
        . " * v032 (8/24/2026 4:10:00 pm)\n"
        . " * - UI: Moves the Previous Years decorative border outward equally on left/right.\n"
        . " * - UI: Adds matching top/bottom breathing room around the expanded charts.\n"
        . " * - PRESERVE: Prior-year chart widths and alignment remain exactly as in v031.\n"
        . " * - PRESERVE: Pick/form panel, collapse behavior and text readability remain unchanged.\n"
        . " * - PRESERVE: No LP/RP, deadline, submission, database, scheduler or scoring changes.\n"
        . " *\n";

    $content = mrlb_replace_once($content, $needle, $entry, 'changelog insertion');
    $content = mrlb_replace_once($content, $oldBorder, $newBorder, 'Previous Years border alignment');

    return $content;
}

if ($action === 'install' && empty($errors)) {
    if (!$preflightOk) {
        $errors[] = 'INSTALL REFUSED: preflight is not 100% clean.';
    } else {
        try {
            $new = mrlb_build_v032($current, $oldBorder, $newBorder);

            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
                throw new RuntimeException('Could not create backup directory.');
            }

            if (!copy($teamPath, $backupPath)) {
                throw new RuntimeException('Could not back up team.php v031.');
            }

            if (file_put_contents($teamPath, $new, LOCK_EX) === false) {
                throw new RuntimeException('Could not write team.php v032.');
            }

            $installed = (string)@file_get_contents($teamPath);
            $postOk = true;

            $postOk = mrlb_check(
                $checks,
                'POST version v032',
                mrlb_count($installed, 'VERSION: v032') === 1,
                'v032 installed'
            ) && $postOk;

            $postOk = mrlb_check(
                $checks,
                'POST equal border offset',
                mrlb_count($installed, 'right: calc(10% - 22px);') === 1
                    && mrlb_count($installed, 'left: calc(10% - 22px);') === 1,
                'Equal 22px outward offset on left/right'
            ) && $postOk;

            $postOk = mrlb_check(
                $checks,
                'POST chart width unchanged',
                mrlb_count($installed, '.mrl-previous-years-content {') === 1,
                'No chart width rule changed'
            ) && $postOk;

            $postOk = mrlb_check(
                $checks,
                'POST pick/form panel unchanged',
                mrlb_count($installed, '.mrl-pick-panel::before') === 1,
                'Existing pick/form panel retained'
            ) && $postOk;

            $postOk = mrlb_check(
                $checks,
                'POST LP/RP bridge preserved',
                mrlb_count($installed, 'function teampage_get_rd_base_pick_row') === 1,
                'Permanent LP/RP bridge retained'
            ) && $postOk;

            if ($postOk) {
                $messages[] = 'PASS: team.php v032 installed. Charts stayed the same width; Previous Years border moved outward equally.';
            } else {
                $errors[] = 'POSTFLIGHT FAILED. Use rollback before continuing.';
            }

        } catch (Throwable $e) {
            $errors[] = 'INSTALL FAILED: ' . $e->getMessage();
        }
    }
}

if ($action === 'rollback' && empty($errors)) {
    if (!is_file($backupPath)) {
        $errors[] = 'ROLLBACK REFUSED: v031 backup file is missing.';
    } else {
        if (copy($backupPath, $teamPath)) {
            $messages[] = 'ROLLBACK PASS: restored team.php v031.';
        } else {
            $errors[] = 'ROLLBACK FAILED: team.php v031 could not be restored.';
        }
    }
}

$backupExists = is_file($backupPath);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL Previous Years Border Alignment <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1450px;margin:0 auto;padding:14px}
.banner{background:#20391d;border:1px solid #4d8244;border-radius:10px;padding:13px 15px}
.banner h1{margin:0;color:#dcffd2;font-size:24px}
.sub{margin-top:4px;color:#bdd9b6}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
.ok{background:#153b20;border:1px solid #3f8752;color:#caffd5;border-radius:8px;padding:10px 12px;margin-top:11px}
.err{background:#481919;border:1px solid #a04949;color:#ffaaaa;border-radius:8px;padding:10px 12px;margin-top:11px}
.warn{background:#4a2d00;border:1px solid #af7628;color:#ffd783;border-radius:8px;padding:10px 12px;margin-top:11px}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid #333;padding:7px 8px;text-align:left;vertical-align:top}
th{background:#282828}
.pass{color:#78ef9c;font-weight:bold}
.fail{color:#ff9292;font-weight:bold}
button{padding:9px 14px;border-radius:6px;border:1px solid #5e8ab0;background:#2d6289;color:#fff;font-weight:bold;cursor:pointer}
button.install{background:#773724;border-color:#bd6d51}
button.rollback{background:#66561f;border-color:#ad9340}
.small{font-size:12px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL Previous Years Border Alignment Installer v001</h1>
<div class="sub">TESTPHP8 • team.php v031 → v032 • border-only adjustment</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="err"><?=mrlb_h($e)?></div>
<?php endforeach; ?>

<?php foreach ($messages as $m): ?>
<div class="ok"><?=mrlb_h($m)?></div>
<?php endforeach; ?>

<div class="warn">
<strong>Border only:</strong> prior-year chart widths are intentionally untouched.
The Previous Years border moves outward by the same amount on both sides.
</div>

<div class="card">
<h2>Preflight — <span class="<?=$preflightOk ? 'pass' : 'fail'?>"><?=$preflightOk ? 'PASS' : 'FAIL'?></span></h2>

<?php if ($preflightOk && $action === 'preflight'): ?>
<form method="post" onsubmit="return confirm('Install team.php v032 border alignment? team.php v031 will be backed up first.');">
<input type="hidden" name="action" value="install">
<button class="install" type="submit">Install team.php v032 Border Alignment</button>
</form>
<?php endif; ?>

<?php if ($backupExists): ?>
<form method="post" style="margin-top:10px;" onsubmit="return confirm('Restore the backed-up team.php v031?');">
<input type="hidden" name="action" value="rollback">
<button class="rollback" type="submit">Rollback team.php to v031</button>
</form>
<?php endif; ?>

<table style="margin-top:12px;">
<tr><th>Check</th><th>Status</th><th>Detail</th></tr>
<?php foreach ($checks as $c): ?>
<tr>
<td><?=mrlb_h($c['label'])?></td>
<td class="<?=$c['ok'] ? 'pass' : 'fail'?>"><?=$c['ok'] ? 'PASS' : 'FAIL'?></td>
<td><?=mrlb_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Expected Result</h2>
<ul>
<li>Previous Years charts remain exactly the same width as v031.</li>
<li>Border sits outside the charts equally on left and right.</li>
<li>Small equal-looking breathing room above and below the section.</li>
<li>Pick/form panel remains unchanged.</li>
<li>Previous Years remains collapsed by default.</li>
</ul>
</div>

</div>
</body>
</html>
