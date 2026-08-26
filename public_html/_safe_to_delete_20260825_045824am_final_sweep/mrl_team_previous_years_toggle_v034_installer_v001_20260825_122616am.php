<?php
/**
 * MRL team.php Previous Years Toggle Installer
 *
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 12:26:16 am
 *
 * TARGET:
 * TESTPHP8 only
 * team.php v033 -> v034
 *
 * SCOPE:
 * Presentation only:
 * - Previous Years uses + when collapsed and − when expanded.
 * - Previous Years summary uses the same font size/style as Admin Menu.
 *
 * NO CHANGES TO:
 * DB, scheduler, pick logic, LP, RP, scoring, privacy, chart data.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
$root = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
$teamPath = $root . '/team.php';
$backupDir = $root . '/mrl_team_previous_years_toggle_v034_backup_20260825_122616am';
$backupPath = $backupDir . '/team.php.v033.before_previous_years_toggle';

$errors = array();
$messages = array();
$checks = array();

function mrlpy_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function mrlpy_count($haystack, $needle) {
    return substr_count((string)$haystack, (string)$needle);
}
function mrlpy_check(&$checks, $label, $ok, $detail) {
    $checks[] = array('label'=>$label, 'ok'=>(bool)$ok, 'detail'=>$detail);
    return (bool)$ok;
}
function mrlpy_replace_once($content, $old, $new, $label) {
    $count = substr_count($content, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ' expected once; found ' . $count . '.');
    }
    return str_replace($old, $new, $content);
}

$css = <<<'CSS'

        /*
         * v034 Previous Years toggle alignment:
         * Match the Admin Menu summary typography and +/- behavior.
         */
        .mrl-previous-years summary {
            list-style: none;
            font-size: 20.0pt;
            font-weight: normal;
            line-height: 120%;
        }

        .mrl-previous-years summary::-webkit-details-marker {
            display: none;
        }

        .mrl-previous-years summary::before {
            content: "+ ";
            font-weight: normal;
        }

        .mrl-previous-years[open] summary::before {
            content: "− ";
        }
CSS;

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: TESTPHP8-only. Current host: ' . $host;
}
if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable.';
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

if ($current !== '') {
    $preflightOk = mrlpy_check(
        $checks,
        'team.php version',
        mrlpy_count($current, 'VERSION: v033') === 1,
        'Expected v033'
    ) && $preflightOk;

    $preflightOk = mrlpy_check(
        $checks,
        'Previous Years details',
        mrlpy_count($current, '<details class="mrl-previous-years">') === 1,
        'Single Previous Years collapsible section found'
    ) && $preflightOk;

    $preflightOk = mrlpy_check(
        $checks,
        'Previous Years collapsed default',
        mrlpy_count($current, '<details class="mrl-previous-years" open') === 0,
        'No open attribute present'
    ) && $preflightOk;

    $preflightOk = mrlpy_check(
        $checks,
        'Admin Menu typography reference',
        mrlpy_count($current, '.mrl-section-panel-title,.mrl-admin-menu-panel>summary{font-size:20pt;color:#dfcca8;line-height:120%}') === 1
            || (
                mrlpy_count($current, 'font-size:20pt') >= 1
                && mrlpy_count($current, 'mrl-admin-menu-panel') >= 1
            ),
        'Admin Menu 20pt / normal-weight reference present'
    ) && $preflightOk;

    $preflightOk = mrlpy_check(
        $checks,
        'Admin +/- CSS present',
        mrlpy_count($current, '.mrl-admin-menu-panel>summary::before{content:"+ ";font-weight:normal}') === 1
            && mrlpy_count($current, '.mrl-admin-menu-panel[open]>summary::before{content:"− "}') === 1,
        'Existing Admin +/- behavior confirmed'
    ) && $preflightOk;

    $preflightOk = mrlpy_check(
        $checks,
        'v034 CSS absent',
        mrlpy_count($current, 'v034 Previous Years toggle alignment') === 0,
        'Not already installed'
    ) && $preflightOk;

    $preflightOk = mrlpy_check(
        $checks,
        'style insertion point',
        mrlpy_count($current, '    </style>') === 1,
        'Single closing style tag found'
    ) && $preflightOk;
}

function mrlpy_build_v034($content, $css) {
    $content = mrlpy_replace_once($content, 'VERSION: v033', 'VERSION: v034', 'version bump');

    if (preg_match('/LAST MODIFIED:\s*[^\r\n]+/', $content, $m) !== 1) {
        throw new RuntimeException('Could not identify LAST MODIFIED header.');
    }
    $content = mrlpy_replace_once(
        $content,
        $m[0],
        'LAST MODIFIED: 8/25/2026 12:26:16 am',
        'LAST MODIFIED header'
    );

    $needle = " * CHANGELOG:\n *\n";
    $entry = " * CHANGELOG:\n *\n"
        . " * v034 (8/25/2026 12:26:16 am)\n"
        . " * - UI: Previous Years now uses + / − instead of the native caret.\n"
        . " * - UI: Previous Years summary typography matches Admin Menu (20pt, normal weight).\n"
        . " * - PRESERVE: All v033 layout, pick, LP, RP, scoring, privacy, and scheduler behavior.\n"
        . " *\n";

    $content = mrlpy_replace_once($content, $needle, $entry, 'changelog insertion');
    $content = mrlpy_replace_once($content, '    </style>', $css . "\n    </style>", 'CSS insertion');
    return $content;
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

if ($action === 'install' && empty($errors)) {
    if (!$preflightOk) {
        $errors[] = 'INSTALL REFUSED: preflight did not pass.';
    } else {
        try {
            $new = mrlpy_build_v034($current, $css);

            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
                throw new RuntimeException('Could not create backup directory.');
            }
            if (!copy($teamPath, $backupPath)) {
                throw new RuntimeException('Could not back up team.php v033.');
            }
            if (file_put_contents($teamPath, $new, LOCK_EX) === false) {
                throw new RuntimeException('Could not write team.php v034.');
            }

            $installed = @file_get_contents($teamPath);
            if ($installed === false) {
                throw new RuntimeException('Could not re-read installed team.php.');
            }

            $postOk = true;
            $postOk = mrlpy_check(
                $checks,
                'POST version v034',
                mrlpy_count($installed, 'VERSION: v034') === 1,
                'Installed'
            ) && $postOk;

            $postOk = mrlpy_check(
                $checks,
                'POST Previous Years +/-',
                mrlpy_count($installed, '.mrl-previous-years summary::before') === 1
                    && mrlpy_count($installed, '.mrl-previous-years[open] summary::before') === 1,
                'Present'
            ) && $postOk;

            $postOk = mrlpy_check(
                $checks,
                'POST native marker hidden',
                mrlpy_count($installed, '.mrl-previous-years summary::-webkit-details-marker') === 1,
                'Present'
            ) && $postOk;

            $postOk = mrlpy_check(
                $checks,
                'POST Admin Menu preserved',
                mrlpy_count($installed, '<summary>Admin Menu</summary>') === 1
                    && mrlpy_count($installed, '.mrl-admin-menu-panel>summary::before') === 1,
                'Preserved'
            ) && $postOk;

            if ($postOk) {
                $messages[] = 'PASS: team.php v034 installed. Previous Years now matches Admin Menu +/- styling.';
            } else {
                $errors[] = 'POSTFLIGHT FAILED. Use rollback before continuing.';
            }
        } catch (Throwable $e) {
            $errors[] = 'INSTALL FAILED: ' . $e->getMessage();
        }
    }
}

if ($action === 'rollback') {
    if (!is_file($backupPath)) {
        $errors[] = 'ROLLBACK REFUSED: v033 backup file is missing.';
    } elseif (copy($backupPath, $teamPath)) {
        $messages[] = 'ROLLBACK PASS: restored team.php v033.';
    } else {
        $errors[] = 'ROLLBACK FAILED.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL Previous Years Toggle Installer v001</title>
<style>
body{margin:0;background:#111;color:#eee;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1100px;margin:16px auto;padding:0 14px}
.banner{border:1px solid #396d36;background:#173717;border-radius:12px;padding:14px 18px}
.banner h1{margin:0 0 4px;color:#e9ffd9;font-size:28px}
.sub{color:#d9efc9}
.card{margin-top:14px;border:1px solid #444;background:#1b1b1b;border-radius:11px;padding:14px 18px}
.msg-pass{margin-top:12px;border:1px solid #34794c;background:#103d24;border-radius:8px;padding:11px 14px;color:#aef2bf}
.msg-fail{margin-top:12px;border:1px solid #ad3e3e;background:#511818;border-radius:8px;padding:11px 14px;color:#ffb1b1}
h2{margin:2px 0 12px;font-size:24px}
table{width:100%;border-collapse:collapse}
td{padding:7px 9px;border-bottom:1px solid #353535}
.pass{color:#78ef9c;font-weight:bold}.fail{color:#ff9292;font-weight:bold}
button{padding:9px 14px;border-radius:6px;border:1px solid #5e8ab0;background:#2f713e;color:#fff;font-weight:bold;cursor:pointer}
.rollback{background:#7a3636;border-color:#a65353}
.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">
<div class="banner">
<h1>MRL Previous Years +/- Installer v001</h1>
<div class="sub">TESTPHP8 • team.php v033 → v034 • presentation only</div>
</div>

<?php foreach ($messages as $m): ?><div class="msg-pass"><?=mrlpy_h($m)?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="msg-fail"><?=mrlpy_h($e)?></div><?php endforeach; ?>

<div class="card">
<h2>Preflight — <?=$preflightOk ? '<span class="pass">PASS</span>' : '<span class="fail">FAIL</span>'?></h2>
<table>
<?php foreach ($checks as $c): ?>
<tr><td><?=mrlpy_h($c['label'])?></td><td class="<?=$c['ok']?'pass':'fail'?>"><?=$c['ok']?'PASS':'FAIL'?></td><td><?=mrlpy_h($c['detail'])?></td></tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<strong>Scope:</strong> only the Previous Years summary control and typography. No other team.php behavior is changed.
</div>

<?php if ($preflightOk && $action !== 'install'): ?>
<div class="card">
<form method="post" onsubmit="return confirm('Install team.php v034 Previous Years +/- styling?');">
<input type="hidden" name="action" value="install">
<button type="submit">Install team.php v034 Previous Years +/-</button>
</form>
</div>
<?php endif; ?>

<?php if (is_file($backupPath)): ?>
<div class="card">
<form method="post" onsubmit="return confirm('Restore team.php v033?');">
<input type="hidden" name="action" value="rollback">
<button class="rollback" type="submit">Rollback team.php to v033</button>
</form>
</div>
<?php endif; ?>

<div class="card small">
After PASS: reload team.php and verify Previous Years shows <strong>+</strong> while collapsed and <strong>−</strong> while open, with the same size/weight as Admin Menu.
</div>
</div>
</body>
</html>
