<?php
declare(strict_types=1);

/**
 * MRL team.php Section Width Alignment Installer
 *
 * VERSION: v001
 * LAST MODIFIED: 8/24/2026 3:32:51 pm
 *
 * PURPOSE
 * -------
 * Normalize the active pick/form section so its actual chart/form content
 * stays aligned with the established ~80% MRL chart width while the visual
 * panel border extends outward without consuming content width.
 *
 * This avoids the nested-percent problem entirely:
 *
 * - Existing team.php 80% content wrapper remains authoritative.
 * - Pick/form content uses the full width of that wrapper.
 * - Panel border is drawn OUTSIDE the content with a pseudo-element.
 * - No 125% width compensation.
 * - No negative width/margin trick affecting chart geometry.
 * - Previous Years behavior from v028/v029 remains unchanged.
 *
 * INSTALL RESULT
 * --------------
 * team.php v029 -> v030
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
 * - Rollback restores v029
 * - PHP 7.3 compatible
 */

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$EXPECTED_HOST = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$teamPath = $root . '/team.php';
$backupDir = $root . '/mrl_team_section_alignment_v030_backup_20260824_033251pm';
$backupPath = $backupDir . '/team.php.v029.before_section_alignment';

$errors = array();
$messages = array();
$checks = array();
$action = isset($_POST['action']) ? (string)$_POST['action'] : 'preflight';

function mrla_h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrla_count($haystack, $needle)
{
    return substr_count((string)$haystack, (string)$needle);
}

function mrla_check(&$checks, $label, $ok, $detail)
{
    $checks[] = array(
        'label' => (string)$label,
        'ok' => (bool)$ok,
        'detail' => (string)$detail
    );
    return (bool)$ok;
}

function mrla_replace_once($content, $old, $new, $label)
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
            width: 100%;
            margin: 12px 0 22px 0;
            padding: 18px 20px;
            border: 1px solid #666666;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.018);
        }
OLDPANEL;

$newPanel = <<<'NEWPANEL'
        /*
         * v030: keep the form/chart geometry untouched at the existing
         * team-page content width.  The panel border is decorative only
         * and is drawn outside the content box so it cannot narrow tables.
         */
        .mrl-pick-panel {
            position: relative;
            box-sizing: border-box;
            width: 100%;
            margin: 28px 0 34px 0;
            padding: 0;
            border: 0;
            background: transparent;
        }

        .mrl-pick-panel::before {
            content: "";
            position: absolute;
            top: -18px;
            right: -22px;
            bottom: -18px;
            left: -22px;
            border: 1px solid #666666;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.018);
            pointer-events: none;
            z-index: 0;
        }

        .mrl-pick-panel > * {
            position: relative;
            z-index: 1;
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
    $preflightOk = mrla_check(
        $checks,
        'team.php version',
        mrla_count($current, 'VERSION: v029') === 1,
        'Expected team.php v029'
    ) && $preflightOk;

    $preflightOk = mrla_check(
        $checks,
        'Current v029 panel rule',
        mrla_count($current, $oldPanel) === 1,
        'Expected exact v029 panel rule once'
    ) && $preflightOk;

    $preflightOk = mrla_check(
        $checks,
        'Existing 80% team page wrapper',
        mrla_count($current, '<div style="width:80%; margin:0 auto; text-align:left;">') >= 2,
        'Legacy 80% page-width containers remain present'
    ) && $preflightOk;

    $preflightOk = mrla_check(
        $checks,
        'Previous Years collapsed by default',
        mrla_count($current, '<details class="mrl-previous-years">') === 1
            && mrla_count($current, '<details class="mrl-previous-years" open') === 0,
        'Expected one collapsed-by-default Previous Years section'
    ) && $preflightOk;

    $preflightOk = mrla_check(
        $checks,
        'Prior-year chart readability retained',
        mrla_count($current, '.mrl-previous-years-content table,') === 1
            && mrla_count($current, 'color: #000000 !important;') === 1,
        'Expected prior-year readability rule'
    ) && $preflightOk;

    $preflightOk = mrla_check(
        $checks,
        'Permanent LP/RP bridge retained',
        mrla_count($current, 'function teampage_get_rd_base_pick_row') === 1,
        'Permanent LP-as-RP base helper must remain present'
    ) && $preflightOk;

    $preflightOk = mrla_check(
        $checks,
        'Temporary time-travel hook absent',
        mrla_count($current, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') === 0,
        'Expected zero temporary edge-test hooks'
    ) && $preflightOk;
}

function mrla_build_v030($content, $oldPanel, $newPanel)
{
    $content = mrla_replace_once($content, 'VERSION: v029', 'VERSION: v030', 'version bump');

    if (preg_match('/LAST MODIFIED:\s*[^\r\n]+/', $content, $m) !== 1) {
        throw new RuntimeException('Could not identify LAST MODIFIED header.');
    }

    $content = mrla_replace_once(
        $content,
        $m[0],
        'LAST MODIFIED: 8/24/2026 3:32:51 pm',
        'LAST MODIFIED header'
    );

    $needle = " * CHANGELOG:\n *\n";
    $entry = " * CHANGELOG:\n *\n"
        . " * v030 (8/24/2026 3:32:51 pm)\n"
        . " * - UI FIX: Uses the established team-page content width as the single width authority.\n"
        . " * - UI FIX: Pick/form content now fills that width with no panel padding reducing table width.\n"
        . " * - UI: Decorative panel border is drawn outside the content using a pseudo-element.\n"
        . " * - PRESERVE: Previous Years collapse, chart width and text readability behavior.\n"
        . " * - PRESERVE: No LP/RP, deadline, submission, database, scheduler or scoring changes.\n"
        . " *\n";

    $content = mrla_replace_once($content, $needle, $entry, 'changelog insertion');
    $content = mrla_replace_once($content, $oldPanel, $newPanel, 'panel alignment correction');

    return $content;
}

if ($action === 'install' && empty($errors)) {
    if (!$preflightOk) {
        $errors[] = 'INSTALL REFUSED: preflight is not 100% clean.';
    } else {
        try {
            $new = mrla_build_v030($current, $oldPanel, $newPanel);

            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
                throw new RuntimeException('Could not create backup directory.');
            }

            if (!copy($teamPath, $backupPath)) {
                throw new RuntimeException('Could not back up team.php v029.');
            }

            if (file_put_contents($teamPath, $new, LOCK_EX) === false) {
                throw new RuntimeException('Could not write team.php v030.');
            }

            $installed = (string)@file_get_contents($teamPath);
            $postOk = true;

            $postOk = mrla_check(
                $checks,
                'POST version v030',
                mrla_count($installed, 'VERSION: v030') === 1,
                'v030 installed'
            ) && $postOk;

            $postOk = mrla_check(
                $checks,
                'POST content width unchanged',
                mrla_count($installed, 'width: 125%;') === 0
                    && mrla_count($installed, 'padding: 0;') >= 1,
                'No width compensation; panel consumes no horizontal content space'
            ) && $postOk;

            $postOk = mrla_check(
                $checks,
                'POST outward border',
                mrla_count($installed, '.mrl-pick-panel::before') === 1
                    && mrla_count($installed, 'right: -22px;') === 1
                    && mrla_count($installed, 'left: -22px;') === 1,
                'Decorative border extends outside content'
            ) && $postOk;

            $postOk = mrla_check(
                $checks,
                'POST Previous Years retained',
                mrla_count($installed, '<details class="mrl-previous-years">') === 1
                    && mrla_count($installed, '.mrl-previous-years-content table,') === 1,
                'Existing Previous Years fixes remain'
            ) && $postOk;

            $postOk = mrla_check(
                $checks,
                'POST LP/RP bridge preserved',
                mrla_count($installed, 'function teampage_get_rd_base_pick_row') === 1,
                'Permanent bridge still present'
            ) && $postOk;

            $postOk = mrla_check(
                $checks,
                'POST no test-time hook',
                mrla_count($installed, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') === 0,
                'Temporary test hook still absent'
            ) && $postOk;

            if ($postOk) {
                $messages[] = 'PASS: team.php v030 installed. Section content width is unchanged; the panel border now grows outward around it.';
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
        $errors[] = 'ROLLBACK REFUSED: v029 backup file is missing.';
    } else {
        if (copy($backupPath, $teamPath)) {
            $messages[] = 'ROLLBACK PASS: restored team.php v029.';
        } else {
            $errors[] = 'ROLLBACK FAILED: team.php v029 could not be restored.';
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
<title>MRL team.php Section Width Alignment <?=$VERSION?></title>
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
<h1>MRL team.php Section Width Alignment Installer v001</h1>
<div class="sub">TESTPHP8 • team.php v029 → v030 • chart-width alignment</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="err"><?=mrla_h($e)?></div>
<?php endforeach; ?>

<?php foreach ($messages as $m): ?>
<div class="ok"><?=mrla_h($m)?></div>
<?php endforeach; ?>

<div class="warn">
<strong>Layout model:</strong> the existing ~80% team-page content width remains authoritative.
This installer makes the panel border decorative so it grows outward without narrowing the form/chart itself.
</div>

<div class="card">
<h2>Preflight — <span class="<?=$preflightOk ? 'pass' : 'fail'?>"><?=$preflightOk ? 'PASS' : 'FAIL'?></span></h2>

<?php if ($preflightOk && $action === 'preflight'): ?>
<form method="post" onsubmit="return confirm('Install team.php v030 section-width alignment? team.php v029 will be backed up first.');">
<input type="hidden" name="action" value="install">
<button class="install" type="submit">Install team.php v030 Alignment Fix</button>
</form>
<?php endif; ?>

<?php if ($backupExists): ?>
<form method="post" style="margin-top:10px;" onsubmit="return confirm('Restore the backed-up team.php v029?');">
<input type="hidden" name="action" value="rollback">
<button class="rollback" type="submit">Rollback team.php to v029</button>
</form>
<?php endif; ?>

<table style="margin-top:12px;">
<tr><th>Check</th><th>Status</th><th>Detail</th></tr>
<?php foreach ($checks as $c): ?>
<tr>
<td><?=mrla_h($c['label'])?></td>
<td class="<?=$c['ok'] ? 'pass' : 'fail'?>"><?=$c['ok'] ? 'PASS' : 'FAIL'?></td>
<td><?=mrla_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Expected Result</h2>
<ul>
<li>Current team chart, submit/form table and previous-year charts line up at roughly the same 80% page width.</li>
<li>The submit/form table is no longer narrowed by panel padding.</li>
<li>The border extends slightly outside that shared content width.</li>
<li>No 125% width compensation or negative geometry affecting the chart itself.</li>
<li>Previous Years remains collapsed by default and readable when expanded.</li>
</ul>
</div>

<div class="card small">
If this structural approach still is not visually worth keeping, the fallback is straightforward:
restore the simple layout and retain only the two obsolete-text removals plus the permanent LP→RP logic.
</div>

</div>
</body>
</html>
