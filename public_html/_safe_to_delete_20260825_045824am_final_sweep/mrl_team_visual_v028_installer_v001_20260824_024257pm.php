<?php
declare(strict_types=1);

/**
 * MRL team.php Visual Correction Installer
 *
 * VERSION: v001
 * LAST MODIFIED: 8/24/2026 2:42:57 pm
 *
 * PURPOSE
 * -------
 * Correct the visual-only issues introduced with team.php v027:
 *
 * - Preserve the softened form-panel treatment without shrinking nested tables.
 * - Restore prior-year chart/table width behavior.
 * - Keep Previous Years Picks collapsed by default.
 * - Make prior-year table text readable on light table cells.
 *
 * INSTALL RESULT
 * --------------
 * team.php v027 -> v028
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
 * - Rollback button restores v027
 * - PHP 7.3 compatible
 */

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$EXPECTED_HOST = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$teamPath = $root . '/team.php';
$backupDir = $root . '/mrl_team_visual_v028_backup_20260824_024257pm';
$backupPath = $backupDir . '/team.php.v027.before_visual_fix';

$errors = array();
$messages = array();
$checks = array();
$action = isset($_POST['action']) ? (string)$_POST['action'] : 'preflight';

function mrlv_h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlv_count($haystack, $needle)
{
    return substr_count((string)$haystack, (string)$needle);
}

function mrlv_check(&$checks, $label, $ok, $detail)
{
    $checks[] = array(
        'label' => (string)$label,
        'ok' => (bool)$ok,
        'detail' => (string)$detail
    );
    return (bool)$ok;
}

function mrlv_replace_once($content, $old, $new, $label)
{
    $count = substr_count($content, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ' expected exactly once; found ' . $count . '.');
    }
    return str_replace($old, $new, $content);
}

$oldCss = <<<'OLDCSS'
        .mrl-pick-panel {
            margin: 12px 0 22px 0;
            padding: 18px 20px;
            border: 1px solid #666666;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.018);
        }

        .mrl-previous-years {
            margin: 8px 0 22px 0;
            border: 1px solid #555555;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.012);
        }

        .mrl-previous-years summary {
            padding: 12px 16px;
            cursor: pointer;
            font-size: 20.0pt;
            font-weight: bold;
            color: #dfcca8;
            outline: none;
        }

        .mrl-previous-years-content {
            padding: 6px 12px 14px 12px;
        }
OLDCSS;

$newCss = <<<'NEWCSS'
        /*
         * v028 visual correction:
         * The form lives inside the legacy 80% page container, and several
         * included form/chart tables also use percentage widths.  Expanding
         * this wrapper to 125% restores the old effective table width rather
         * than multiplying 80% by another nested percentage.
         */
        .mrl-pick-panel {
            box-sizing: border-box;
            width: 125%;
            margin: 12px 0 22px -12.5%;
            padding: 18px 20px;
            border: 1px solid #666666;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.018);
        }

        /*
         * Keep the collapsible control visually aligned to the normal 80%
         * page content while allowing the actual prior-year chart includes
         * to render against full page width, exactly as they did before the
         * collapsible wrapper was added.
         */
        .mrl-previous-years {
            width: 100%;
            margin: 8px 0 22px 0;
            border: 0;
            background: transparent;
        }

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

        .mrl-previous-years-content {
            width: 100%;
            padding: 10px 0 14px 0;
            color: #000000;
        }

        .mrl-previous-years-content table,
        .mrl-previous-years-content th,
        .mrl-previous-years-content td {
            color: #000000 !important;
        }

        @media (max-width: 900px) {
            .mrl-pick-panel {
                width: 100%;
                margin-left: 0;
                margin-right: 0;
            }

            .mrl-previous-years summary {
                width: 96%;
            }
        }
NEWCSS;

$oldPrevBlock = <<<'OLDPREV'
        </div>
        <br>

        <details class="mrl-previous-years">
            <summary>Previous Years Picks</summary>
            <div class="mrl-previous-years-content">
                <?php
                $sqlYears = "SELECT * FROM years WHERE year < :raceYear AND year > 0 ORDER BY year DESC";
                $stmtYears = $dbo->prepare($sqlYears);
                $stmtYears->execute([':raceYear' => $raceYear]);

                while ($yearRow = $stmtYears->fetch(PDO::FETCH_ASSOC)) {
                    $prevRaceYear = $yearRow['year'];
                    include 'prior_year_user_team_chart.php';
                }
                ?>
            </div>
        </details>
    </div>
</div>
<br>
OLDPREV;

$newPrevBlock = <<<'NEWPREV'
        </div>
    </div>
</div>

<br>

<details class="mrl-previous-years">
    <summary>Previous Years Picks</summary>
    <div class="mrl-previous-years-content">
        <?php
        $sqlYears = "SELECT * FROM years WHERE year < :raceYear AND year > 0 ORDER BY year DESC";
        $stmtYears = $dbo->prepare($sqlYears);
        $stmtYears->execute([':raceYear' => $raceYear]);

        while ($yearRow = $stmtYears->fetch(PDO::FETCH_ASSOC)) {
            $prevRaceYear = $yearRow['year'];
            include 'prior_year_user_team_chart.php';
        }
        ?>
    </div>
</details>

<br>
NEWPREV;

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
    $preflightOk = mrlv_check(
        $checks,
        'team.php version',
        mrlv_count($current, 'VERSION: v027') === 1,
        'Expected team.php v027'
    ) && $preflightOk;

    $preflightOk = mrlv_check(
        $checks,
        'Permanent LP/RP bridge retained',
        mrlv_count($current, 'function teampage_get_rd_base_pick_row') === 1,
        'Permanent helper must remain present'
    ) && $preflightOk;

    $preflightOk = mrlv_check(
        $checks,
        'Temporary time-travel hook absent',
        mrlv_count($current, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') === 0,
        'Expected zero temporary edge-time hooks'
    ) && $preflightOk;

    $preflightOk = mrlv_check(
        $checks,
        'Current v027 visual CSS block',
        mrlv_count($current, $oldCss) === 1,
        'Expected exact v027 CSS block once'
    ) && $preflightOk;

    $preflightOk = mrlv_check(
        $checks,
        'Current v027 Previous Years block',
        mrlv_count($current, $oldPrevBlock) === 1,
        'Expected exact v027 wrapper block once'
    ) && $preflightOk;

    $preflightOk = mrlv_check(
        $checks,
        'Previous Years collapsed by default',
        mrlv_count($current, '<details class="mrl-previous-years" open') === 0,
        'No open attribute in source'
    ) && $preflightOk;
}

function mrlv_build_v028($content, $oldCss, $newCss, $oldPrevBlock, $newPrevBlock)
{
    $content = mrlv_replace_once($content, 'VERSION: v027', 'VERSION: v028', 'version bump');

    if (preg_match('/LAST MODIFIED:\s*[^\r\n]+/', $content, $m) !== 1) {
        throw new RuntimeException('Could not identify LAST MODIFIED header.');
    }
    $content = mrlv_replace_once(
        $content,
        $m[0],
        'LAST MODIFIED: 8/24/2026 2:42:57 pm',
        'LAST MODIFIED header'
    );

    $needle = " * CHANGELOG:\n *\n";
    $entry = " * CHANGELOG:\n *\n"
        . " * v028 (8/24/2026 2:42:57 pm)\n"
        . " * - UI FIX: Restores effective form/table width inside the softened panel.\n"
        . " * - UI FIX: Previous Years charts render at their original page-relative widths.\n"
        . " * - UI FIX: Prior-year table text is forced dark on light chart cells for readability.\n"
        . " * - PRESERVE: Previous Years remains collapsed by default.\n"
        . " * - PRESERVE: No LP/RP, deadline, submission, database, or scoring logic changes.\n"
        . " *\n";
    $content = mrlv_replace_once($content, $needle, $entry, 'changelog insertion');

    $content = mrlv_replace_once($content, $oldCss, $newCss, 'visual CSS correction');
    $content = mrlv_replace_once($content, $oldPrevBlock, $newPrevBlock, 'previous years wrapper relocation');

    return $content;
}

if ($action === 'install' && empty($errors)) {
    if (!$preflightOk) {
        $errors[] = 'INSTALL REFUSED: preflight is not 100% clean.';
    } else {
        try {
            $new = mrlv_build_v028($current, $oldCss, $newCss, $oldPrevBlock, $newPrevBlock);

            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
                throw new RuntimeException('Could not create backup directory.');
            }

            if (!copy($teamPath, $backupPath)) {
                throw new RuntimeException('Could not back up team.php v027.');
            }

            if (file_put_contents($teamPath, $new, LOCK_EX) === false) {
                throw new RuntimeException('Could not write team.php v028.');
            }

            $installed = (string)@file_get_contents($teamPath);
            $postOk = true;

            $postOk = mrlv_check($checks, 'POST version v028', mrlv_count($installed, 'VERSION: v028') === 1, 'v028 installed') && $postOk;
            $postOk = mrlv_check($checks, 'POST form width correction', mrlv_count($installed, 'width: 125%;') === 1, 'form wrapper expanded to compensate for nested percentage tables') && $postOk;
            $postOk = mrlv_check($checks, 'POST previous years outside 80% wrapper', mrlv_count($installed, '<details class="mrl-previous-years">') === 1, 'single collapsible previous-years section') && $postOk;
            $postOk = mrlv_check($checks, 'POST prior-year dark table text', mrlv_count($installed, 'color: #000000 !important;') === 1, 'dark table-cell text rule installed') && $postOk;
            $postOk = mrlv_check($checks, 'POST collapsed default', mrlv_count($installed, '<details class="mrl-previous-years" open') === 0, 'no open attribute') && $postOk;
            $postOk = mrlv_check($checks, 'POST LP/RP bridge preserved', mrlv_count($installed, 'function teampage_get_rd_base_pick_row') === 1, 'permanent bridge still present') && $postOk;
            $postOk = mrlv_check($checks, 'POST no test-time hook', mrlv_count($installed, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') === 0, 'temporary test hook still absent') && $postOk;

            if ($postOk) {
                $messages[] = 'PASS: team.php v028 installed. This was a visual-only correction; LP→RP functionality was not changed.';
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
        $errors[] = 'ROLLBACK REFUSED: v027 backup file is missing.';
    } else {
        if (copy($backupPath, $teamPath)) {
            $messages[] = 'ROLLBACK PASS: restored team.php v027.';
        } else {
            $errors[] = 'ROLLBACK FAILED: team.php v027 could not be restored.';
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
<title>MRL team.php Visual Correction <?=$VERSION?></title>
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
.path{font-family:Consolas,monospace;color:#d6e9ff}
.small{font-size:12px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL team.php Visual Correction Installer v001</h1>
<div class="sub">TESTPHP8 • team.php v027 → v028 • visual-only correction</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="err"><?=mrlv_h($e)?></div>
<?php endforeach; ?>

<?php foreach ($messages as $m): ?>
<div class="ok"><?=mrlv_h($m)?></div>
<?php endforeach; ?>

<div class="warn">
<strong>Scope is deliberately narrow:</strong> this installer changes only team.php presentation.
Database, scheduler, submit-team-picks.php, LP/RP routing, deadlines and scoring are untouched.
</div>

<div class="card">
<h2>Preflight — <span class="<?=$preflightOk ? 'pass' : 'fail'?>"><?=$preflightOk ? 'PASS' : 'FAIL'?></span></h2>

<?php if ($preflightOk && $action === 'preflight'): ?>
<form method="post" onsubmit="return confirm('Install team.php v028 visual corrections? team.php v027 will be backed up first.');">
<input type="hidden" name="action" value="install">
<button class="install" type="submit">Install team.php v028 Visual Fix</button>
</form>
<?php endif; ?>

<?php if ($backupExists): ?>
<form method="post" style="margin-top:10px;" onsubmit="return confirm('Restore the backed-up team.php v027?');">
<input type="hidden" name="action" value="rollback">
<button class="rollback" type="submit">Rollback team.php to v027</button>
</form>
<?php endif; ?>

<table style="margin-top:12px;">
<tr><th>Check</th><th>Status</th><th>Detail</th></tr>
<?php foreach ($checks as $c): ?>
<tr>
<td><?=mrlv_h($c['label'])?></td>
<td class="<?=$c['ok'] ? 'pass' : 'fail'?>"><?=$c['ok'] ? 'PASS' : 'FAIL'?></td>
<td><?=mrlv_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Expected Visual Result</h2>
<ul>
<li>Form/pick panel remains subtle, neutral and much wider.</li>
<li>Nested form tables recover their old practical width.</li>
<li>Previous Years remains collapsed on a fresh load.</li>
<li>The Previous Years heading stays aligned with the normal page content.</li>
<li>Expanded prior-year charts render at their original page-relative width.</li>
<li>Text in light-colored prior-year table cells renders dark and readable.</li>
</ul>
</div>

<div class="card small">
After PASS, test both states again: pick window OPEN and CLOSED, then expand Previous Years in each.<br>
If those look right, we can return immediately to LP→RP reset/finalization work.
</div>

</div>
</body>
</html>
