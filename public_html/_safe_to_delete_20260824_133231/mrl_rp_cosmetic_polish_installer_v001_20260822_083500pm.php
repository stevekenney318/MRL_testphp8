<?php
declare(strict_types=1);

/**
 * MRL RP Cosmetic Polish Installer
 *
 * VERSION: v001
 * GENERATED: 8/22/2026 8:35:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 * DATABASE CHANGES: NONE
 *
 * TARGET:
 *   /public_html/team_replacement_driver.php  v009 -> v010
 *
 * COSMETIC CHANGES ONLY:
 * 1) Dropdown placeholder:
 *      "Select Replacement Driver" -> "Select Replacement"
 * 2) Eligibility notice:
 *      rounded corners
 *      darker outer border
 *      subtle inset perimeter line
 *      slightly narrower centered width
 *      a little more padding
 *
 * NO RP/RD LOGIC CHANGES.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$target = $root . '/team_replacement_driver.php';
$backupDir = $root . '/mrl_rp_cosmetic_polish_backup_20260822_083500pm';
$backup = $backupDir . '/team_replacement_driver.php';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;

function rp_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rp_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail];
}

function rp_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}

function rp_atomic_write(string $path, string $content): bool
{
    $tmp = $path . '.rp_' . str_replace('.', '', uniqid('', true));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

rp_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host);
if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: TESTPHP8 host only.';
}

rp_check($checks, 'Target exists', is_file($target), $target);
if (!is_file($target)) {
    $errors[] = 'team_replacement_driver.php not found.';
}

$src = is_file($target) ? (string)@file_get_contents($target) : '';
$isBaseline = strpos($src, 'VERSION: v009') !== false
    && strpos($src, "\$rdWrapperVersion = 'v009';") !== false;

rp_check($checks, 'Wrapper baseline is v009', $isBaseline, $isBaseline ? 'PASS' : 'unexpected version');
if (!$isBaseline) {
    $errors[] = 'REFUSED: expected team_replacement_driver.php v009.';
}

$placeholderCount = substr_count($src, 'Select Replacement Driver');
rp_check(
    $checks,
    'Replacement dropdown placeholder found',
    $placeholderCount >= 1,
    'matches: ' . $placeholderCount
);
if ($placeholderCount < 1) {
    $errors[] = 'Dropdown placeholder text was not found.';
}

$oldBanner = <<<'HTML'
<div style="color:#8a1b00;font-size:20px;background-color:#fabf8f;text-align:center;font-weight:bold;padding:8px 10px;font-family:Century Gothic,sans-serif;">
    You are eligible, but not required, to make a Replacement Pick under league rule 3
    <br>(2 successive races with 0 points scored by a driver)
</div>
HTML;

$newBanner = <<<'HTML'
<div style="width:94%;box-sizing:border-box;margin:10px auto;color:#8a1b00;font-size:20px;background-color:#fabf8f;text-align:center;font-weight:bold;padding:12px 16px;font-family:Century Gothic,sans-serif;border:1px solid #9b5a36;border-radius:10px;box-shadow:inset 0 0 0 2px rgba(255,255,255,.22);">
    You are eligible, but not required, to make a Replacement Pick under league rule 3
    <br>(2 successive races with 0 points scored by a driver)
</div>
HTML;

$bannerCount = substr_count($src, $oldBanner);
rp_check($checks, 'Eligibility banner baseline found exactly once', $bannerCount === 1, 'matches: ' . $bannerCount);
if ($bannerCount !== 1) {
    $errors[] = 'Eligibility banner did not match the expected v009 markup.';
}

$prepared = '';

if (empty($errors)) {
    try {
        $prepared = $src;

        $prepared = rp_replace_once(
            $prepared,
            'VERSION: v009',
            'VERSION: v010',
            'header version'
        );

        $prepared = rp_replace_once(
            $prepared,
            "\$rdWrapperVersion = 'v009';",
            "\$rdWrapperVersion = 'v010';",
            'runtime version'
        );

        if (strpos($prepared, 'LAST MODIFIED: 8/22/2026 7:26:00 pm') !== false) {
            $prepared = rp_replace_once(
                $prepared,
                'LAST MODIFIED: 8/22/2026 7:26:00 pm',
                'LAST MODIFIED: 8/22/2026 8:35:00 pm',
                'timestamp'
            );
        }

        $prepared = rp_replace_once(
            $prepared,
            " * CHANGELOG:\n",
            " * CHANGELOG:\n"
            . " *\n"
            . " * v010 (8/22/2026 8:35:00 pm)\n"
            . " * - COSMETIC: Shortened replacement dropdown placeholder to \"Select Replacement\".\n"
            . " * - COSMETIC: Modernized the eligibility notice with rounded corners, border, inset line, centered width and added padding.\n"
            . " * - PRESERVE: No Replacement Pick eligibility, selection, validation, deadline, submit, or database logic changed.\n",
            'changelog'
        );

        $prepared = str_replace(
            'Select Replacement Driver',
            'Select Replacement',
            $prepared
        );

        $prepared = rp_replace_once(
            $prepared,
            $oldBanner,
            $newBanner,
            'eligibility banner'
        );

        $semantic = [
            'Prepared wrapper reports v010' =>
                strpos($prepared, 'VERSION: v010') !== false
                && strpos($prepared, "\$rdWrapperVersion = 'v010';") !== false,

            'Old placeholder removed' =>
                strpos($prepared, 'Select Replacement Driver') === false,

            'New placeholder present' =>
                strpos($prepared, 'Select Replacement') !== false,

            'Modernized banner present' =>
                strpos($prepared, 'border-radius:10px') !== false
                && strpos($prepared, 'box-shadow:inset 0 0 0 2px rgba(255,255,255,.22)') !== false
                && strpos($prepared, 'width:94%') !== false,

            'Required radio choice preserved' =>
                strpos($prepared, 'name="rd_selected_slot"') !== false
                && strpos($prepared, 'required') !== false,

            'RP submit endpoint preserved' =>
                strpos($prepared, 'action="/submit-team-picks.php"') !== false,

            'Internal RD type preserved' =>
                strpos($prepared, 'name="pick_type_override" value="RD"') !== false,

            'No DB schema statements introduced' =>
                stripos($prepared, 'ALTER TABLE') === false
                && stripos($prepared, 'CREATE TABLE') === false
                && stripos($prepared, 'DROP TABLE') === false,
        ];

        foreach ($semantic as $label => $ok) {
            rp_check($checks, $label, $ok, $ok ? 'PASS' : 'FAIL');
            if (!$ok) {
                $errors[] = 'Prepared semantic check failed: ' . $label;
            }
        }

    } catch (Throwable $e) {
        $errors[] = 'Transform failed: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'install') {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            $errors[] = 'Could not create backup directory.';
        }

        if (empty($errors) && !@copy($target, $backup)) {
            $errors[] = 'Could not back up team_replacement_driver.php.';
        }

        if (empty($errors) && !rp_atomic_write($target, $prepared)) {
            $errors[] = 'Could not write updated wrapper.';
        }

        if (!empty($errors)) {
            if (is_file($backup)) {
                @copy($backup, $target);
            }
            $errors[] = 'Install problem triggered rollback.';
        } else {
            $after = (string)@file_get_contents($target);

            $postflight = [
                ['Wrapper reports v010',
                    strpos($after, 'VERSION: v010') !== false
                    && strpos($after, "\$rdWrapperVersion = 'v010';") !== false],
                ['Dropdown says Select Replacement',
                    strpos($after, 'Select Replacement Driver') === false
                    && strpos($after, 'Select Replacement') !== false],
                ['Eligibility banner rounded',
                    strpos($after, 'border-radius:10px') !== false],
                ['Eligibility banner inset perimeter line',
                    strpos($after, 'box-shadow:inset 0 0 0 2px rgba(255,255,255,.22)') !== false],
                ['Required qualifier radio logic preserved',
                    strpos($after, 'name="rd_selected_slot"') !== false
                    && strpos($after, 'required') !== false],
                ['Submit endpoint preserved',
                    strpos($after, 'action="/submit-team-picks.php"') !== false],
                ['Internal pick_type remains RD',
                    strpos($after, 'name="pick_type_override" value="RD"') !== false],
            ];

            foreach ($postflight as $pf) {
                if (!$pf[1]) {
                    $errors[] = 'Postflight failed: ' . $pf[0];
                }
            }

            if (!empty($errors)) {
                @copy($backup, $target);
                $errors[] = 'Postflight failure triggered rollback.';
            } else {
                $installed = true;
            }
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL RP Cosmetic Polish</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1100px;margin:0 auto;padding:14px}
.banner{background:#241d0d;border:1px solid #78611d;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#ffe08a;font-size:22px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333}
.ok{color:#69ef98;font-weight:bold}
.bad{color:#ff7777;font-weight:bold}
.warn{color:#ffd36b;font-weight:bold}
code{color:#f2d996}
button{padding:9px 14px;border-radius:7px;font-weight:bold;cursor:pointer;background:#6b5512;color:#fff4ca;border:1px solid #ae8a29}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL RP Cosmetic Polish Installer v001</h1>
<div>TESTPHP8 ONLY • team_replacement_driver.php v009 → v010 • no logic / DB changes</div>
</div>

<div class="card">
<h2>Changes</h2>
<p><strong>1.</strong> Dropdown placeholder becomes <code>Select Replacement</code>.</p>
<p><strong>2.</strong> The peach eligibility notice gets rounded corners, a border, subtle inset perimeter line, slightly narrower centered width, and a little more padding.</p>
<p class="warn">No Replacement Pick behavior changes.</p>
</div>

<div class="card">
<h2>Preflight</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:55%"><?=rp_h($c['name'])?></td>
<td style="width:10%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
<td><?=rp_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
<h2 class="bad">STOPPED SAFELY</h2>
<?php foreach ($errors as $e): ?><div class="bad">• <?=rp_h($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card">
<h2 class="ok">COSMETIC POLISH INSTALLED</h2>
<table>
<?php foreach ($postflight as $pf): ?>
<tr><td><?=rp_h($pf[0])?></td><td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td></tr>
<?php endforeach; ?>
</table>
<p>Backup folder: <code><?=rp_h($backupDir)?></code></p>
<p class="warn">Refresh TESTPHP8 team.php and inspect the two cosmetic items. Do not turn the scheduler back on yet.</p>
</div>
<?php elseif (empty($errors)): ?>
<div class="card">
<h2>Ready</h2>
<form method="post">
<button type="submit" name="action" value="install">INSTALL COSMETIC POLISH</button>
</form>
</div>
<?php endif; ?>

</div>
</body>
</html>
