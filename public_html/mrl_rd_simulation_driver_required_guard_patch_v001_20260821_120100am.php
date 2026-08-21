<?php
declare(strict_types=1);

/**
 * mrl_rd_simulation_driver_required_guard_patch.php
 *
 * VERSION: v001
 * GENERATED: 8/21/2026 12:01:00 am America/New_York
 *
 * TESTPHP8 ONLY
 *
 * PURPOSE:
 * Add the "Tom test" guard to the RD simulator:
 *
 *   If one or more simulated race NET values are entered while
 *   "No override — use saved snapshot values" is selected,
 *   refuse to run and tell the user to select a driver.
 *
 * INSTALLS:
 * - race_results/admin_rd_simulation.php v004 -> v005
 *
 * DOES NOT CHANGE:
 * - race_results_rd_helper.php v005
 * - race_results_snapshot_helper.php
 * - database schema or data
 * - normal race snapshots
 * - team.php
 * - submit-team-picks.php
 * - Live MRL
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const MRL_TOM_GUARD_HOST = 'testphp8.manliusracingleague.com';

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$simPath = $root . '/race_results/admin_rd_simulation.php';
$backupDir = $root . '/mrl_rd_simulation_driver_guard_backup_20260821_120100am';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;

function tg_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tg_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

function tg_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);

    if ($count !== 1) {
        throw new RuntimeException(
            $label . ': expected exactly 1 match, found ' . $count . '.'
        );
    }

    return str_replace($old, $new, $src);
}

function tg_atomic_write(string $path, string $content): bool
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

tg_check($checks, 'Host is TESTPHP8', $host === MRL_TOM_GUARD_HOST, $host);
tg_check($checks, 'Document root available', $root !== '' && is_dir($root), $root);
tg_check($checks, 'Simulator file exists', is_file($simPath), $simPath);

if ($host !== MRL_TOM_GUARD_HOST) {
    $errors[] = 'REFUSED: TestPHP8-only patch.';
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable.';
}

if (!is_file($simPath)) {
    $errors[] = 'admin_rd_simulation.php not found.';
}

$current = is_file($simPath) ? (string)@file_get_contents($simPath) : '';
$isV004 = strpos($current, 'VERSION: v004') !== false;
$isV005 = strpos($current, 'VERSION: v005') !== false;

tg_check(
    $checks,
    'Simulator expected source version',
    $isV004 || $isV005,
    $isV005 ? 'VERSION: v005 (already installed)' : 'VERSION: v004'
);

if (!$isV004 && !$isV005) {
    $errors[] = 'REFUSED: simulator is not expected v004/v005 source.';
}

$prepared = $current;

if (empty($errors) && $isV004) {
    try {
        $requiredMarkers = [
            '$overrideDriver = trim((string)($_POST[\'override_driver\'] ?? \'\'));',
            '$raceNetOverrides = [];',
            "if ((string)\$_POST['action'] === 'run') {",
            "if (\$overrideDriver !== '' && !in_array(\$overrideDriver, \$driverNames, true)) {",
            'name="race_net[',
            'Blank = use saved snapshot NET.',
        ];

        foreach ($requiredMarkers as $marker) {
            $found = strpos($current, $marker) !== false;
            tg_check($checks, 'Expected v004 marker', $found, $marker);

            if (!$found) {
                throw new RuntimeException('Expected v004 marker missing: ' . $marker);
            }
        }

        $prepared = tg_replace_once(
            $prepared,
            ' * VERSION: v004',
            ' * VERSION: v005',
            'version marker'
        );

        $prepared = tg_replace_once(
            $prepared,
            ' * LAST MODIFIED: 8/20/2026 11:27:00 pm',
            ' * LAST MODIFIED: 8/21/2026 12:01:00 am',
            'timestamp marker'
        );

        $oldChangelog = <<<'OLD'
 * CHANGELOG:
 * v004 (8/20/2026 11:27:00 pm)
OLD;

        $newChangelog = <<<'NEW'
 * CHANGELOG:
 * v005 (8/21/2026 12:01:00 am)
 * - NEW: "Tom test" guard refuses simulation when one or more race NET values
 *   are entered but no driver is selected.
 * - NEW: Clear error message: "Select a driver for the simulated race values."
 * - PRESERVE: Race-centric per-race NET inputs, fixture audit, normalized driver
 *   matching, underlying-eligibility diagnostics, and all read-only safeguards.
 *
 * v004 (8/20/2026 11:27:00 pm)
NEW;

        $prepared = tg_replace_once(
            $prepared,
            $oldChangelog,
            $newChangelog,
            'changelog insertion'
        );

        $oldRunStart = <<<'OLD'
        if ((string)$_POST['action'] === 'run') {
            if ($bounds === null || $throughRace <= 0 || empty($sourcePoints)) {
                throw new RuntimeException('No completed snapshot state is available for this selection.');
            }

            if ($overrideDriver !== '' && !in_array($overrideDriver, $driverNames, true)) {
                throw new RuntimeException('Override driver is not an active driver for the selected team set.');
            }
OLD;

        $newRunStart = <<<'NEW'
        if ((string)$_POST['action'] === 'run') {
            if ($bounds === null || $throughRace <= 0 || empty($sourcePoints)) {
                throw new RuntimeException('No completed snapshot state is available for this selection.');
            }

            /*
             * "Tom test" UI safety:
             * Do not silently ignore entered simulation values just because
             * no driver was selected.
             */
            if (!empty($raceNetOverrides) && $overrideDriver === '') {
                throw new RuntimeException('Select a driver for the simulated race values.');
            }

            if ($overrideDriver !== '' && !in_array($overrideDriver, $driverNames, true)) {
                throw new RuntimeException('Override driver is not an active driver for the selected team set.');
            }
NEW;

        $prepared = tg_replace_once(
            $prepared,
            $oldRunStart,
            $newRunStart,
            'run guard'
        );

        $oldHint = <<<'OLD'
                Blank = use saved snapshot NET. Enter a value only for the race(s) you want to simulate.
OLD;

        $newHint = <<<'NEW'
                Blank = use saved snapshot NET. Enter a value only for the race(s) you want to simulate.
                If any race has a simulated value, you must select a driver above.
NEW;

        $prepared = tg_replace_once(
            $prepared,
            $oldHint,
            $newHint,
            'UI hint'
        );

        tg_check(
            $checks,
            'Driver-required guard prepared',
            true,
            'v004 -> v005; simulated values require selected driver'
        );
    } catch (Throwable $e) {
        $errors[] = 'Transform failed: ' . $e->getMessage();
        tg_check($checks, 'Driver-required guard prepared', false, $e->getMessage());
    }
}

// ---------------- INSTALL ----------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['install']) &&
    empty($errors)
) {
    if ($isV005) {
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
            !@copy($simPath, $backupDir . '/admin_rd_simulation.php')
        ) {
            $errors[] = 'Could not back up admin_rd_simulation.php.';
        }

        if (empty($errors) && !tg_atomic_write($simPath, $prepared)) {
            @copy($backupDir . '/admin_rd_simulation.php', $simPath);
            $errors[] = 'Write failed; rollback attempted.';
        }

        if (empty($errors)) {
            $after = (string)@file_get_contents($simPath);

            $postflight = [
                [
                    'Simulator v005 installed',
                    strpos($after, 'VERSION: v005') !== false
                ],
                [
                    'Driver-required guard installed',
                    strpos($after, "Select a driver for the simulated race values.") !== false
                ],
                [
                    'Guard checks race NET values',
                    strpos($after, "!empty(\$raceNetOverrides) && \$overrideDriver === ''") !== false
                ],
                [
                    'Race-centric NET inputs preserved',
                    strpos($after, 'name="race_net[') !== false
                ],
                [
                    'Underlying Eligibility diagnostics preserved',
                    strpos($after, 'underlying_qualifiers') !== false
                ],
                [
                    'No RD submission code added',
                    strpos($after, 'INSERT INTO user_picks') === false
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
                @copy($backupDir . '/admin_rd_simulation.php', $simPath);
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
<title>MRL RD Simulation Driver Guard Patch</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:15px}
.banner{background:#3d3212;border:1px solid #9b7200;border-radius:11px;padding:12px 15px}
.banner h1{margin:0;color:#fff0c8;font-size:22px}
.sub{font-size:12px;color:#dac884;margin-top:4px}
.card{background:#1d1d1d;border:1px solid #444;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#ffd08a;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333;vertical-align:top}
.ok{color:#5cf09a;font-weight:700}
.bad{color:#ff7d7d;font-weight:700}
code{color:#f2d78b}
button{background:#443419;color:#ffd08a;border:1px solid #b48636;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer}
.success{background:#143b2b;border-color:#2c7754}
.note{font-size:12px;color:#bbb;line-height:1.45}
a{color:#8fc7ff}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
    <h1>MRL RD Simulation Driver Guard Patch v001</h1>
    <div class="sub">TESTPHP8 ONLY • simulator v004 → v005 • DB changes: NONE</div>
</div>

<div class="card">
    <h2>The "Tom Test"</h2>
    <div>
        If any simulated race NET value is entered while
        <code>No override — use saved snapshot values</code> is selected,
        the simulator will now refuse to run.
    </div>
    <div class="note" style="margin-top:7px">
        Message: <strong>Select a driver for the simulated race values.</strong>
        Nothing is silently ignored anymore.
    </div>
</div>

<div class="card">
    <h2>Preflight</h2>
    <table>
        <?php foreach ($checks as $c): ?>
        <tr>
            <td style="width:43%"><?=tg_h($c['name'])?></td>
            <td style="width:8%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
            <td><?=tg_h($c['detail'])?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
    <h2 class="bad">STOPPED SAFELY</h2>
    <?php foreach ($errors as $e): ?><div class="bad">• <?=tg_h($e)?></div><?php endforeach; ?>
</div>
<?php elseif (!$installed): ?>
<div class="card">
    <h2>Ready to Install</h2>
    <div class="note" style="margin-bottom:9px">
        Changes only <code>race_results/admin_rd_simulation.php</code>.
        No DB, helper, normal snapshot, team.php, submission, or Live changes.
    </div>
    <form method="post">
        <button type="submit" name="install" value="1">INSTALL DRIVER-REQUIRED GUARD</button>
    </form>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card success">
    <h2 class="ok">INSTALL COMPLETE</h2>

    <?php if (is_dir($backupDir)): ?>
        <div><strong>Backup folder:</strong><br><code><?=tg_h($backupDir)?></code></div>
    <?php endif; ?>

    <table style="margin-top:8px">
        <?php foreach ($postflight as $pf): ?>
        <tr>
            <td><?=tg_h($pf[0])?></td>
            <td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div style="margin-top:10px">
        <a href="/race_results/admin_rd_simulation.php?year=2026&segment=S1&through=7">Open RD Simulation</a>
    </div>

    <div class="note" style="margin-top:8px">
        Quick guard test: leave No override selected, type any race NET value,
        and click Generate / Run Simulation. It should refuse with the new message.
        Then clear the value and continue to the multiple-qualifier test.
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
