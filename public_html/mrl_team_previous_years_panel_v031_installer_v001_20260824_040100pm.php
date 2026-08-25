<?php
declare(strict_types=1);

/**
 * MRL team.php Previous Years Panel Installer
 *
 * VERSION: v001
 * LAST MODIFIED: 8/24/2026 4:01:00 pm
 *
 * PURPOSE
 * -------
 * Give the Previous Years section the same "border grows outward" treatment
 * that worked for the active pick/form section in team.php v030.
 *
 * IMPORTANT:
 * - The actual prior-year charts keep their existing page-relative width.
 * - The border is decorative only and does NOT become a width parent.
 * - Previous Years remains collapsed by default.
 *
 * INSTALL RESULT
 * --------------
 * team.php v030 -> v031
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
 * - Rollback restores v030
 * - PHP 7.3 compatible
 */

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$EXPECTED_HOST = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$teamPath = $root . '/team.php';
$backupDir = $root . '/mrl_team_previous_years_panel_v031_backup_20260824_040100pm';
$backupPath = $backupDir . '/team.php.v030.before_previous_years_panel';

$errors = array();
$messages = array();
$checks = array();
$action = isset($_POST['action']) ? (string)$_POST['action'] : 'preflight';

function mrlp_h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlp_count($haystack, $needle)
{
    return substr_count((string)$haystack, (string)$needle);
}

function mrlp_check(&$checks, $label, $ok, $detail)
{
    $checks[] = array(
        'label' => (string)$label,
        'ok' => (bool)$ok,
        'detail' => (string)$detail
    );
    return (bool)$ok;
}

function mrlp_replace_once($content, $old, $new, $label)
{
    $count = substr_count($content, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ' expected exactly once; found ' . $count . '.');
    }
    return str_replace($old, $new, $content);
}

$oldPreviousRule = <<<'OLD'
        .mrl-previous-years {
            width: 100%;
            margin: 8px 0 22px 0;
            border: 0;
            background: transparent;
        }
OLD;

$newPreviousRule = <<<'NEW'
        /*
         * v031: Previous Years uses the same geometry model as the v030
         * pick/form panel.  The section stays full-page for layout purposes,
         * while the decorative border is positioned around the established
         * ~80% chart footprint.  The charts are NOT nested inside an 80%
         * width parent, so their existing width is preserved.
         */
        .mrl-previous-years {
            position: relative;
            width: 100%;
            margin: 26px 0 34px 0;
            border: 0;
            background: transparent;
        }

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

        .mrl-previous-years > * {
            position: relative;
            z-index: 1;
        }
NEW;

$oldSummaryRule = <<<'OLD'
        .mrl-previous-years summary {
            box-sizing: border-box;
            width: 80%;
            margin: 0 auto;
            padding: 12px 16px;
            cursor: pointer;
            font-size: 20.0pt;
            font-weight: bold;
            color: #dfcca8;
            outline: none;
            border: 1px solid #555555;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.012);
        }
OLD;

$newSummaryRule = <<<'NEW'
        .mrl-previous-years summary {
            box-sizing: border-box;
            width: 80%;
            margin: 0 auto;
            padding: 12px 16px;
            cursor: pointer;
            font-size: 20.0pt;
            font-weight: bold;
            color: #dfcca8;
            outline: none;
            border: 0;
            background: transparent;
        }
NEW;

$oldContentRule = <<<'OLD'
        .mrl-previous-years-content {
            width: 100%;
            padding: 10px 0 14px 0;
            color: #000000;
        }
OLD;

$newContentRule = <<<'NEW'
        .mrl-previous-years-content {
            width: 100%;
            padding: 10px 0 18px 0;
            color: #000000;
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
    $preflightOk = mrlp_check(
        $checks,
        'team.php version',
        mrlp_count($current, 'VERSION: v030') === 1,
        'Expected team.php v030'
    ) && $preflightOk;

    $preflightOk = mrlp_check(
        $checks,
        'v030 pick/form outward border retained',
        mrlp_count($current, '.mrl-pick-panel::before') === 1,
        'Working v030 panel geometry must remain present'
    ) && $preflightOk;

    $preflightOk = mrlp_check(
        $checks,
        'Current Previous Years base rule',
        mrlp_count($current, $oldPreviousRule) === 1,
        'Expected exact current rule once'
    ) && $preflightOk;

    $preflightOk = mrlp_check(
        $checks,
        'Current Previous Years summary rule',
        mrlp_count($current, $oldSummaryRule) === 1,
        'Expected exact current summary rule once'
    ) && $preflightOk;

    $preflightOk = mrlp_check(
        $checks,
        'Current Previous Years content rule',
        mrlp_count($current, $oldContentRule) === 1,
        'Expected exact current content rule once'
    ) && $preflightOk;

    $preflightOk = mrlp_check(
        $checks,
        'Previous Years collapsed by default',
        mrlp_count($current, '<details class="mrl-previous-years">') === 1
            && mrlp_count($current, '<details class="mrl-previous-years" open') === 0,
        'One details section; no open attribute'
    ) && $preflightOk;

    $preflightOk = mrlp_check(
        $checks,
        'Prior-year dark table text retained',
        mrlp_count($current, '.mrl-previous-years-content table,') === 1
            && mrlp_count($current, 'color: #000000 !important;') === 1,
        'Existing readability fix remains'
    ) && $preflightOk;

    $preflightOk = mrlp_check(
        $checks,
        'Permanent LP/RP bridge retained',
        mrlp_count($current, 'function teampage_get_rd_base_pick_row') === 1,
        'Permanent helper must remain'
    ) && $preflightOk;

    $preflightOk = mrlp_check(
        $checks,
        'Temporary edge-time hook absent',
        mrlp_count($current, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') === 0,
        'Expected zero temporary hooks'
    ) && $preflightOk;
}

function mrlp_build_v031($content, $oldPreviousRule, $newPreviousRule, $oldSummaryRule, $newSummaryRule, $oldContentRule, $newContentRule)
{
    $content = mrlp_replace_once($content, 'VERSION: v030', 'VERSION: v031', 'version bump');

    if (preg_match('/LAST MODIFIED:\s*[^\r\n]+/', $content, $m) !== 1) {
        throw new RuntimeException('Could not identify LAST MODIFIED header.');
    }

    $content = mrlp_replace_once(
        $content,
        $m[0],
        'LAST MODIFIED: 8/24/2026 4:01:00 pm',
        'LAST MODIFIED header'
    );

    $needle = " * CHANGELOG:\n *\n";
    $entry = " * CHANGELOG:\n *\n"
        . " * v031 (8/24/2026 4:01:00 pm)\n"
        . " * - UI: Previous Years now uses the same outward decorative-panel model as the pick/form section.\n"
        . " * - UI: Border encloses the heading and expanded charts without becoming their width parent.\n"
        . " * - PRESERVE: Prior-year charts keep their established ~80% page-relative width.\n"
        . " * - PRESERVE: Previous Years remains collapsed by default with readable dark table text.\n"
        . " * - PRESERVE: No LP/RP, deadline, submission, database, scheduler or scoring changes.\n"
        . " *\n";

    $content = mrlp_replace_once($content, $needle, $entry, 'changelog insertion');
    $content = mrlp_replace_once($content, $oldPreviousRule, $newPreviousRule, 'Previous Years outer panel');
    $content = mrlp_replace_once($content, $oldSummaryRule, $newSummaryRule, 'Previous Years summary');
    $content = mrlp_replace_once($content, $oldContentRule, $newContentRule, 'Previous Years content spacing');

    return $content;
}

if ($action === 'install' && empty($errors)) {
    if (!$preflightOk) {
        $errors[] = 'INSTALL REFUSED: preflight is not 100% clean.';
    } else {
        try {
            $new = mrlp_build_v031(
                $current,
                $oldPreviousRule,
                $newPreviousRule,
                $oldSummaryRule,
                $newSummaryRule,
                $oldContentRule,
                $newContentRule
            );

            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
                throw new RuntimeException('Could not create backup directory.');
            }

            if (!copy($teamPath, $backupPath)) {
                throw new RuntimeException('Could not back up team.php v030.');
            }

            if (file_put_contents($teamPath, $new, LOCK_EX) === false) {
                throw new RuntimeException('Could not write team.php v031.');
            }

            $installed = (string)@file_get_contents($teamPath);
            $postOk = true;

            $postOk = mrlp_check(
                $checks,
                'POST version v031',
                mrlp_count($installed, 'VERSION: v031') === 1,
                'v031 installed'
            ) && $postOk;

            $postOk = mrlp_check(
                $checks,
                'POST Previous Years outward border',
                mrlp_count($installed, '.mrl-previous-years::before') === 1
                    && mrlp_count($installed, 'right: 10%;') === 1
                    && mrlp_count($installed, 'left: 10%;') === 1,
                'Border positioned around ~80% chart footprint'
            ) && $postOk;

            $postOk = mrlp_check(
                $checks,
                'POST summary no nested border',
                mrlp_count($installed, 'border: 0;') >= 2,
                'Summary uses the outer section border'
            ) && $postOk;

            $postOk = mrlp_check(
                $checks,
                'POST Previous Years collapsed',
                mrlp_count($installed, '<details class="mrl-previous-years" open') === 0,
                'No open attribute'
            ) && $postOk;

            $postOk = mrlp_check(
                $checks,
                'POST pick/form panel unchanged',
                mrlp_count($installed, '.mrl-pick-panel::before') === 1,
                'Working v030 form panel retained'
            ) && $postOk;

            $postOk = mrlp_check(
                $checks,
                'POST LP/RP bridge preserved',
                mrlp_count($installed, 'function teampage_get_rd_base_pick_row') === 1,
                'Permanent bridge retained'
            ) && $postOk;

            if ($postOk) {
                $messages[] = 'PASS: team.php v031 installed. Previous Years now uses the same outward-border layout model without changing chart width.';
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
        $errors[] = 'ROLLBACK REFUSED: v030 backup file is missing.';
    } else {
        if (copy($backupPath, $teamPath)) {
            $messages[] = 'ROLLBACK PASS: restored team.php v030.';
        } else {
            $errors[] = 'ROLLBACK FAILED: team.php v030 could not be restored.';
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
<title>MRL Previous Years Panel <?=$VERSION?></title>
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
<h1>MRL Previous Years Panel Installer v001</h1>
<div class="sub">TESTPHP8 • team.php v030 → v031 • presentation only</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="err"><?=mrlp_h($e)?></div>
<?php endforeach; ?>

<?php foreach ($messages as $m): ?>
<div class="ok"><?=mrlp_h($m)?></div>
<?php endforeach; ?>

<div class="warn">
<strong>Same model that worked for the pick/form section:</strong>
the border is decorative and sits around the existing ~80% chart footprint.
It does not become a parent that can shrink the charts.
</div>

<div class="card">
<h2>Preflight — <span class="<?=$preflightOk ? 'pass' : 'fail'?>"><?=$preflightOk ? 'PASS' : 'FAIL'?></span></h2>

<?php if ($preflightOk && $action === 'preflight'): ?>
<form method="post" onsubmit="return confirm('Install team.php v031 Previous Years panel? team.php v030 will be backed up first.');">
<input type="hidden" name="action" value="install">
<button class="install" type="submit">Install team.php v031 Previous Years Panel</button>
</form>
<?php endif; ?>

<?php if ($backupExists): ?>
<form method="post" style="margin-top:10px;" onsubmit="return confirm('Restore the backed-up team.php v030?');">
<input type="hidden" name="action" value="rollback">
<button class="rollback" type="submit">Rollback team.php to v030</button>
</form>
<?php endif; ?>

<table style="margin-top:12px;">
<tr><th>Check</th><th>Status</th><th>Detail</th></tr>
<?php foreach ($checks as $c): ?>
<tr>
<td><?=mrlp_h($c['label'])?></td>
<td class="<?=$c['ok'] ? 'pass' : 'fail'?>"><?=$c['ok'] ? 'PASS' : 'FAIL'?></td>
<td><?=mrlp_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Expected Result</h2>
<ul>
<li>Previous Years remains collapsed by default.</li>
<li>Collapsed heading sits inside one subtle neutral outer border.</li>
<li>When expanded, the same border encloses the previous-year charts.</li>
<li>Charts keep the same width and alignment that already look correct in v030.</li>
<li>The active pick/form panel is untouched.</li>
</ul>
</div>

<div class="card small">
If the visual result is not preferable, rollback restores the known-good v030 immediately.
</div>

</div>
</body>
</html>
