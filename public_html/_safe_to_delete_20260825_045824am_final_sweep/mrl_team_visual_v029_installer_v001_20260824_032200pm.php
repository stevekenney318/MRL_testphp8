<?php
declare(strict_types=1);

/**
 * MRL team.php Visual Width Correction Installer
 *
 * VERSION: v001
 * LAST MODIFIED: 8/24/2026 3:22:00 pm
 *
 * PURPOSE
 * -------
 * Narrowly correct the v028 form/pick panel width overcorrection:
 *
 * - Remove the 125% width / negative margin compensation.
 * - Keep the panel at the normal team-page content width.
 * - Preserve the subtle neutral border/panel.
 * - Preserve the Previous Years fixes already in v028.
 *
 * INSTALL RESULT
 * --------------
 * team.php v028 -> v029
 *
 * SAFETY
 * ------
 * - TESTPHP8-only
 * - Exact-marker preflight
 * - Backs up team.php before writing
 * - team.php only
 * - No database writes
 * - No scheduler changes
 * - No submit-team-picks.php changes
 * - Rollback restores v028
 * - PHP 7.3 compatible
 */

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$EXPECTED_HOST = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$teamPath = $root . '/team.php';
$backupDir = $root . '/mrl_team_visual_v029_backup_20260824_032200pm';
$backupPath = $backupDir . '/team.php.v028.before_width_fix';

$errors = array();
$messages = array();
$checks = array();
$action = isset($_POST['action']) ? (string)$_POST['action'] : 'preflight';

function mrlw_h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlw_count($haystack, $needle)
{
    return substr_count((string)$haystack, (string)$needle);
}

function mrlw_check(&$checks, $label, $ok, $detail)
{
    $checks[] = array(
        'label' => (string)$label,
        'ok' => (bool)$ok,
        'detail' => (string)$detail
    );
    return (bool)$ok;
}

function mrlw_replace_once($content, $old, $new, $label)
{
    $count = substr_count($content, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ' expected exactly once; found ' . $count . '.');
    }
    return str_replace($old, $new, $content);
}

$oldPanel = <<<'OLDPANEL'
        .mrl-pick-panel {
            box-sizing: border-box;
            width: 125%;
            margin: 12px 0 22px -12.5%;
            padding: 18px 20px;
            border: 1px solid #666666;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.018);
        }
OLDPANEL;

$newPanel = <<<'NEWPANEL'
        .mrl-pick-panel {
            box-sizing: border-box;
            width: 100%;
            margin: 12px 0 22px 0;
            padding: 18px 20px;
            border: 1px solid #666666;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.018);
        }
NEWPANEL;

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
    $preflightOk = mrlw_check(
        $checks,
        'team.php version',
        mrlw_count($current, 'VERSION: v028') === 1,
        'Expected team.php v028'
    ) && $preflightOk;

    $preflightOk = mrlw_check(
        $checks,
        'Current 125% panel rule',
        mrlw_count($current, $oldPanel) === 1,
        'Expected exact v028 width-compensation rule once'
    ) && $preflightOk;

    $preflightOk = mrlw_check(
        $checks,
        'Previous Years collapse retained',
        mrlw_count($current, '<details class="mrl-previous-years">') === 1
            && mrlw_count($current, '<details class="mrl-previous-years" open') === 0,
        'Expected one collapsed-by-default Previous Years section'
    ) && $preflightOk;

    $preflightOk = mrlw_check(
        $checks,
        'Prior-year dark text rule retained',
        mrlw_count($current, '.mrl-previous-years-content table,') === 1
            && mrlw_count($current, 'color: #000000 !important;') === 1,
        'Expected v028 prior-year readability rule'
    ) && $preflightOk;

    $preflightOk = mrlw_check(
        $checks,
        'Permanent LP/RP bridge retained',
        mrlw_count($current, 'function teampage_get_rd_base_pick_row') === 1,
        'Permanent helper must remain present'
    ) && $preflightOk;

    $preflightOk = mrlw_check(
        $checks,
        'Temporary time-travel hook absent',
        mrlw_count($current, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') === 0,
        'Expected zero temporary edge-test hooks'
    ) && $preflightOk;
}

function mrlw_build_v029($content, $oldPanel, $newPanel)
{
    $content = mrlw_replace_once($content, 'VERSION: v028', 'VERSION: v029', 'version bump');

    if (preg_match('/LAST MODIFIED:\s*[^\r\n]+/', $content, $m) !== 1) {
        throw new RuntimeException('Could not identify LAST MODIFIED header.');
    }

    $content = mrlw_replace_once(
        $content,
        $m[0],
        'LAST MODIFIED: 8/24/2026 3:22:00 pm',
        'LAST MODIFIED header'
    );

    $needle = " * CHANGELOG:\n *\n";
    $entry = " * CHANGELOG:\n *\n"
        . " * v029 (8/24/2026 3:22:00 pm)\n"
        . " * - UI FIX: Removes v028 125% form-panel width compensation and negative margin.\n"
        . " * - UI: Form/pick panel now stays at the normal team-page content width.\n"
        . " * - PRESERVE: v028 Previous Years collapse, chart width and text readability fixes.\n"
        . " * - PRESERVE: No LP/RP, deadline, submission, database, scheduler or scoring changes.\n"
        . " *\n";

    $content = mrlw_replace_once($content, $needle, $entry, 'changelog insertion');
    $content = mrlw_replace_once($content, $oldPanel, $newPanel, 'panel width correction');

    return $content;
}

if ($action === 'install' && empty($errors)) {
    if (!$preflightOk) {
        $errors[] = 'INSTALL REFUSED: preflight is not 100% clean.';
    } else {
        try {
            $new = mrlw_build_v029($current, $oldPanel, $newPanel);

            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
                throw new RuntimeException('Could not create backup directory.');
            }

            if (!copy($teamPath, $backupPath)) {
                throw new RuntimeException('Could not back up team.php v028.');
            }

            if (file_put_contents($teamPath, $new, LOCK_EX) === false) {
                throw new RuntimeException('Could not write team.php v029.');
            }

            $installed = (string)@file_get_contents($teamPath);
            $postOk = true;

            $postOk = mrlw_check(
                $checks,
                'POST version v029',
                mrlw_count($installed, 'VERSION: v029') === 1,
                'v029 installed'
            ) && $postOk;

            $postOk = mrlw_check(
                $checks,
                'POST panel width 100%',
                mrlw_count($installed, 'width: 100%;') >= 1
                    && mrlw_count($installed, 'width: 125%;') === 0
                    && mrlw_count($installed, 'margin: 12px 0 22px -12.5%;') === 0,
                '125% compensation removed'
            ) && $postOk;

            $postOk = mrlw_check(
                $checks,
                'POST Previous Years retained',
                mrlw_count($installed, '<details class="mrl-previous-years">') === 1
                    && mrlw_count($installed, '.mrl-previous-years-content table,') === 1,
                'v028 Previous Years fixes still present'
            ) && $postOk;

            $postOk = mrlw_check(
                $checks,
                'POST LP/RP bridge preserved',
                mrlw_count($installed, 'function teampage_get_rd_base_pick_row') === 1,
                'permanent bridge still present'
            ) && $postOk;

            $postOk = mrlw_check(
                $checks,
                'POST no test-time hook',
                mrlw_count($installed, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') === 0,
                'temporary hook still absent'
            ) && $postOk;

            if ($postOk) {
                $messages[] = 'PASS: team.php v029 installed. Width-only correction complete; functional logic unchanged.';
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
        $errors[] = 'ROLLBACK REFUSED: v028 backup file is missing.';
    } else {
        if (copy($backupPath, $teamPath)) {
            $messages[] = 'ROLLBACK PASS: restored team.php v028.';
        } else {
            $errors[] = 'ROLLBACK FAILED: team.php v028 could not be restored.';
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
<title>MRL team.php Width Correction <?=$VERSION?></title>
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
<h1>MRL team.php Width Correction Installer v001</h1>
<div class="sub">TESTPHP8 • team.php v028 → v029 • width-only correction</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="err"><?=mrlw_h($e)?></div>
<?php endforeach; ?>

<?php foreach ($messages as $m): ?>
<div class="ok"><?=mrlw_h($m)?></div>
<?php endforeach; ?>

<div class="warn">
<strong>Very narrow scope:</strong> this removes only the v028 125% panel-width compensation.
Previous Years fixes and all LP/RP functionality remain untouched.
</div>

<div class="card">
<h2>Preflight — <span class="<?=$preflightOk ? 'pass' : 'fail'?>"><?=$preflightOk ? 'PASS' : 'FAIL'?></span></h2>

<?php if ($preflightOk && $action === 'preflight'): ?>
<form method="post" onsubmit="return confirm('Install team.php v029 width correction? team.php v028 will be backed up first.');">
<input type="hidden" name="action" value="install">
<button class="install" type="submit">Install team.php v029 Width Fix</button>
</form>
<?php endif; ?>

<?php if ($backupExists): ?>
<form method="post" style="margin-top:10px;" onsubmit="return confirm('Restore the backed-up team.php v028?');">
<input type="hidden" name="action" value="rollback">
<button class="rollback" type="submit">Rollback team.php to v028</button>
</form>
<?php endif; ?>

<table style="margin-top:12px;">
<tr><th>Check</th><th>Status</th><th>Detail</th></tr>
<?php foreach ($checks as $c): ?>
<tr>
<td><?=mrlw_h($c['label'])?></td>
<td class="<?=$c['ok'] ? 'pass' : 'fail'?>"><?=$c['ok'] ? 'PASS' : 'FAIL'?></td>
<td><?=mrlw_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Expected Result</h2>
<ul>
<li>Form/pick panel stays within the normal team-page content width.</li>
<li>No 125% expansion and no negative margin.</li>
<li>Soft neutral panel remains.</li>
<li>Previous Years remains collapsed by default.</li>
<li>Previous Years chart width/readability fixes from v028 remain.</li>
</ul>
</div>

<div class="card small">
If this visual result still is not worth keeping, the fallback remains simple:
restore the pre-panel layout and retain only the two text removals plus the permanent LP→RP logic.
</div>

</div>
</body>
</html>
