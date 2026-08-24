<?php
declare(strict_types=1);

/**
 * mrl_prior_year_chart_connection_fix_installer.php
 *
 * VERSION: v001
 * GENERATED: 8/21/2026 9:50:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 *
 * PURPOSE:
 * Surgical fix for team.php Previous Years Picks stopping partway through.
 *
 * INSTALLS:
 * - prior_year_user_team_chart.php v003 -> v004
 *
 * CHANGES:
 * 1) Replace repeated include of config.php/config_mrl.php with require_once.
 *    team.php already loads both files, so each prior-year include reuses the
 *    existing DB/config state instead of re-running connection/bootstrap code.
 *
 * 2) Add a visible red MRL error panel if a prior-year user_picks query throws.
 *    The panel includes the affected year/segment and error text so failures
 *    cannot disappear as tiny low-contrast text at the bottom of the page.
 *
 * PRESERVES:
 * - Existing chart colors/layout/labels.
 * - LP/RD markers and merged-row rendering.
 * - Existing user_picks data source.
 * - No DB changes.
 * - No team.php changes.
 *
 * LIVE ADAPTATION:
 * The file transformation itself is environment-neutral.  This installer
 * refuses Live; after TestPHP8 is verified, the same v003->v004 change can be
 * packaged separately for Live with Live-source preflight.
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const PYCF_HOST = 'testphp8.manliusracingleague.com';

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$target = $root . '/prior_year_user_team_chart.php';
$backupDir = $root . '/mrl_prior_year_chart_connection_backup_20260821_095000pm';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;

function pycfi_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function pycfi_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

function pycfi_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}

function pycfi_atomic_write(string $path, string $content): bool
{
    $tmp = $path . '.mrl_tmp_' . str_replace('.', '', uniqid('', true));

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

// ---------------- PREFLIGHT ----------------

pycfi_check($checks, 'Host is TESTPHP8', $host === PYCF_HOST, $host);
pycfi_check($checks, 'Document root available', $root !== '' && is_dir($root), $root);
pycfi_check($checks, 'PHP 7.3 compatible target', PHP_VERSION_ID >= 70300, PHP_VERSION);
pycfi_check($checks, 'prior_year_user_team_chart.php exists', is_file($target), $target);

if ($host !== PYCF_HOST) $errors[] = 'REFUSED: TestPHP8-only installer.';
if ($root === '' || !is_dir($root)) $errors[] = 'Document root unavailable.';
if (PHP_VERSION_ID < 70300) $errors[] = 'PHP 7.3 or newer required.';
if (!is_file($target)) $errors[] = 'prior_year_user_team_chart.php not found.';

$current = is_file($target) ? (string)@file_get_contents($target) : '';
$isV003 = strpos($current, 'VERSION: v003') !== false;
$isV004 = strpos($current, 'VERSION: v004') !== false;

pycfi_check(
    $checks,
    'Prior-year chart expected source version',
    $isV003 || $isV004,
    $isV004 ? 'VERSION: v004 (already installed)' : ($isV003 ? 'VERSION: v003' : 'unexpected')
);

if (!$isV003 && !$isV004) {
    $errors[] = 'REFUSED: prior-year chart is not expected v003/v004 source.';
}

$prepared = $current;

if (empty($errors) && $isV003) {
    try {
        $markers = [
            "include 'config.php';",
            "include 'config_mrl.php';",
            '$result = $dbo->query($sql);',
            '$segmentRows = $result->fetchAll(PDO::FETCH_ASSOC);',
            '$segmentOrder = [\'S1\', \'S2\', \'S3\', \'S4\'];',
        ];

        foreach ($markers as $marker) {
            $found = strpos($current, $marker) !== false;
            pycfi_check($checks, 'Expected v003 marker', $found, $marker);
            if (!$found) {
                throw new RuntimeException('Expected marker missing: ' . $marker);
            }
        }

        $prepared = pycfi_replace_once(
            $prepared,
            ' * VERSION: v003',
            ' * VERSION: v004',
            'version marker'
        );

        $prepared = pycfi_replace_once(
            $prepared,
            ' * LAST MODIFIED: 4/13/2026 5:50:30 pm',
            ' * LAST MODIFIED: 8/21/2026 9:50:00 pm',
            'modified timestamp'
        );

        $oldChangelog = <<<'OLD'
 * CHANGELOG:
 *
 * v003 (4/13/2026)
OLD;

        $newChangelog = <<<'NEW'
 * CHANGELOG:
 *
 * v004 (8/21/2026)
 * - FIX: Reuse config.php/config_mrl.php with require_once so team.php does not
 *   re-run database/bootstrap setup once for every prior year.
 * - NEW: Visible red MRL error panel identifies the prior year/segment if a
 *   user_picks query throws, instead of leaving a tiny low-contrast error line.
 * - PRESERVE: Existing chart colors/layout, LP/RD rendering, and data source.
 *
 * v003 (4/13/2026)
NEW;

        $prepared = pycfi_replace_once(
            $prepared,
            $oldChangelog,
            $newChangelog,
            'changelog insertion'
        );

        $prepared = pycfi_replace_once(
            $prepared,
            "include 'config.php';\ninclude 'config_mrl.php';",
            "require_once 'config.php';\nrequire_once 'config_mrl.php';",
            'config reuse'
        );

        $oldQueryBlock = <<<'OLD'
    $result = $dbo->query($sql);
    if ($result) {
        $segmentRows = $result->fetchAll(PDO::FETCH_ASSOC);
        foreach ($segmentRows as $segmentRow) {
            $rows[] = $segmentRow;
        }
    }
OLD;

        $newQueryBlock = <<<'NEW'
    try {
        $result = $dbo->query($sql);

        if ($result) {
            $segmentRows = $result->fetchAll(PDO::FETCH_ASSOC);

            foreach ($segmentRows as $segmentRow) {
                $rows[] = $segmentRow;
            }
        }
    } catch (Throwable $e) {
        echo "<div style='width:80%;margin:10px auto;padding:12px 14px;"
            . "background:#5b1111;color:#ffffff;border:2px solid #ff5c5c;"
            . "border-radius:6px;font-family:Arial,sans-serif;font-size:15px;"
            . "line-height:1.4;'>"
            . "<strong>MRL ERROR — Previous-year chart could not be loaded.</strong><br>"
            . "Year: " . pyutc_h((string)$prevRaceYear)
            . " &nbsp; Segment: " . pyutc_h((string)$segmentCode)
            . "<br><span style='font-size:13px;'>"
            . pyutc_h($e->getMessage())
            . "</span></div>";

        return;
    }
NEW;

        $prepared = pycfi_replace_once(
            $prepared,
            $oldQueryBlock,
            $newQueryBlock,
            'query error panel'
        );

        pycfi_check(
            $checks,
            'Surgical transform prepared',
            true,
            'prior_year_user_team_chart.php v003 -> v004'
        );
    } catch (Throwable $e) {
        $errors[] = 'Transform failed: ' . $e->getMessage();
        pycfi_check($checks, 'Surgical transform prepared', false, $e->getMessage());
    }
}

// ---------------- INSTALL ----------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['install']) &&
    empty($errors)
) {
    if ($isV004) {
        $installed = true;
    } else {
        if (
            !is_dir($backupDir) &&
            !@mkdir($backupDir, 0775, true) &&
            !is_dir($backupDir)
        ) {
            $errors[] = 'Could not create backup directory: ' . $backupDir;
        }

        if (
            empty($errors) &&
            !@copy($target, $backupDir . '/prior_year_user_team_chart.php')
        ) {
            $errors[] = 'Could not back up prior_year_user_team_chart.php.';
        }

        if (empty($errors) && !pycfi_atomic_write($target, $prepared)) {
            @copy($backupDir . '/prior_year_user_team_chart.php', $target);
            $errors[] = 'Write failed; rollback attempted.';
        }

        if (empty($errors)) {
            $after = (string)@file_get_contents($target);

            $postflight = [
                [
                    'prior_year_user_team_chart.php v004 installed',
                    strpos($after, 'VERSION: v004') !== false
                ],
                [
                    'config.php now reused with require_once',
                    strpos($after, "require_once 'config.php';") !== false
                ],
                [
                    'config_mrl.php now reused with require_once',
                    strpos($after, "require_once 'config_mrl.php';") !== false
                ],
                [
                    'Repeated plain config includes removed',
                    strpos($after, "include 'config.php';") === false
                    && strpos($after, "include 'config_mrl.php';") === false
                ],
                [
                    'Visible prior-year error panel installed',
                    strpos($after, 'MRL ERROR — Previous-year chart could not be loaded.') !== false
                ],
                [
                    'Chart palette preserved',
                    strpos($after, '#fabf8f') !== false
                    && strpos($after, '#b7dee8') !== false
                    && strpos($after, '#d9d9d9') !== false
                    && strpos($after, '#c4bd97') !== false
                    && strpos($after, '#b8cce4') !== false
                    && strpos($after, '#d8e4bc') !== false
                ],
            ];

            foreach ($postflight as $pf) {
                if (!$pf[1]) {
                    $errors[] = 'Postflight failed: ' . $pf[0];
                }
            }

            if (empty($errors)) {
                $installed = true;
            } else {
                @copy($backupDir . '/prior_year_user_team_chart.php', $target);
                $errors[] = 'Postflight failure triggered rollback.';
            }
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL Prior-Year Chart Connection Fix</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1200px;margin:0 auto;padding:15px}
.banner{background:#3d3212;border:1px solid #9b7200;border-radius:11px;padding:12px 15px}
.banner h1{margin:0;color:#fff0c8;font-size:22px}
.sub{font-size:12px;color:#dac884;margin-top:4px}
.card{background:#1d1d1d;border:1px solid #444;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#ffd08a;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333;vertical-align:top}
.ok{color:#5cf09a;font-weight:700}
.bad{color:#ff7777;font-weight:700}
code{color:#f0d98c}
button{background:#443419;color:#ffd08a;border:1px solid #b48636;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer}
.success{background:#143b2b;border-color:#2c7754}
.note{font-size:12px;color:#bbb;line-height:1.45}
a{color:#8fc7ff}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
    <h1>MRL Prior-Year Chart Connection Fix Installer v001</h1>
    <div class="sub">TESTPHP8 ONLY • generated 8/21/2026 9:50:00 pm • DB changes: NONE</div>
</div>

<div class="card">
    <h2>Surgical Scope</h2>
    <div>
        Changes only <code>prior_year_user_team_chart.php</code> v003 → v004.
        It reuses the configuration/database state already loaded by team.php and
        adds a clearly visible red error panel if a prior-year query ever fails.
    </div>
    <div class="note" style="margin-top:7px">
        No team.php change. No database change. Existing MRL chart colors/layout
        and LP/RD rendering are preserved.
    </div>
</div>

<div class="card">
    <h2>Preflight</h2>
    <table>
        <?php foreach ($checks as $c): ?>
        <tr>
            <td style="width:43%"><?=pycfi_h($c['name'])?></td>
            <td style="width:8%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
            <td><?=pycfi_h($c['detail'])?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
    <h2 class="bad">STOPPED SAFELY</h2>
    <?php foreach ($errors as $e): ?><div class="bad">• <?=pycfi_h($e)?></div><?php endforeach; ?>
</div>
<?php elseif (!$installed): ?>
<div class="card">
    <h2>Ready to Install</h2>
    <form method="post">
        <button type="submit" name="install" value="1">INSTALL PRIOR-YEAR CONNECTION FIX</button>
    </form>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card success">
    <h2 class="ok">INSTALL COMPLETE</h2>

    <?php if (is_dir($backupDir)): ?>
    <div><strong>Backup folder:</strong><br><code><?=pycfi_h($backupDir)?></code></div>
    <?php endif; ?>

    <table style="margin-top:8px">
        <?php foreach ($postflight as $pf): ?>
        <tr>
            <td><?=pycfi_h($pf[0])?></td>
            <td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div style="margin-top:10px">
        <a href="/team.php#current_user_team_chart">Open team.php</a>
    </div>

    <div class="note" style="margin-top:8px">
        Primary test: scroll Previous Years Picks all the way through 2017.
        Confirm the existing chart appearance is unchanged and no SQLSTATE error
        appears. If a query does fail, it should now appear in a large red MRL
        error panel with the affected year/segment.
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
