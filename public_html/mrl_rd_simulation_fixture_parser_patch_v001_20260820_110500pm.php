<?php
declare(strict_types=1);

/**
 * mrl_rd_simulation_fixture_parser_patch.php
 *
 * VERSION: v001
 * GENERATED: 8/20/2026 11:05:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 *
 * PURPOSE:
 * Fix the isolated RD simulation fixture HTML so it can be re-parsed by the
 * existing shared race_results_snapshot_helper.php parser.
 *
 * ROOT CAUSE:
 * - The simulator generated the fixture header row using <th>.
 * - The shared parser locates the header successfully, but its data pass uses
 *   XPath ".//tr[td]".
 * - Therefore the <th>-only header row never appears in the second pass,
 *   headerSeen never becomes true, and the parser returns an empty result.
 *
 * FIX:
 * - Generate the simulation fixture header row with <td> instead of <th>.
 * - Header text stays identical: POS / DRIVER / ... / PTS / BONUS / PENALTY.
 * - This keeps the fixture compatible with the REAL shared snapshot parser.
 *
 * INSTALLS:
 * - race_results/admin_rd_simulation.php v002 -> v003
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

const MRL_FIX_PATCH_HOST = 'testphp8.manliusracingleague.com';

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$simPath = $root . '/race_results/admin_rd_simulation.php';
$backupDir = $root . '/mrl_rd_simulation_fixture_parser_backup_20260820_110500pm';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;

function rfp_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rfp_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

function rfp_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);

    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count . '.');
    }

    return str_replace($old, $new, $src);
}

function rfp_atomic_write(string $path, string $content): bool
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

rfp_check($checks, 'Host is TESTPHP8', $host === MRL_FIX_PATCH_HOST, $host);
rfp_check($checks, 'Document root available', $root !== '' && is_dir($root), $root);
rfp_check($checks, 'Simulator file exists', is_file($simPath), $simPath);

if ($host !== MRL_FIX_PATCH_HOST) {
    $errors[] = 'REFUSED: TestPHP8-only patch.';
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable.';
}

if (!is_file($simPath)) {
    $errors[] = 'admin_rd_simulation.php not found.';
}

$current = is_file($simPath) ? (string)@file_get_contents($simPath) : '';
$isV002 = strpos($current, 'VERSION: v002') !== false;
$isV003 = strpos($current, 'VERSION: v003') !== false;

rfp_check(
    $checks,
    'Simulator expected source version',
    $isV002 || $isV003,
    $isV003 ? 'VERSION: v003 (already installed)' : 'VERSION: v002'
);

if (!$isV002 && !$isV003) {
    $errors[] = 'REFUSED: simulator is not expected v002/v003 source.';
}

$oldHeader = '<tr><th>POS</th><th>DRIVER</th><th>START</th><th>LAPS</th><th>STATUS</th><th>LEAD</th><th>PTS</th><th>BONUS</th><th>PENALTY</th></tr>';
$newHeader = '<tr><td>POS</td><td>DRIVER</td><td>START</td><td>LAPS</td><td>STATUS</td><td>LEAD</td><td>PTS</td><td>BONUS</td><td>PENALTY</td></tr>';

if ($isV002) {
    $headerCount = substr_count($current, $oldHeader);

    rfp_check(
        $checks,
        'Expected fixture header markup',
        $headerCount === 1,
        'matches: ' . $headerCount
    );

    if ($headerCount !== 1) {
        $errors[] = 'REFUSED: expected v002 fixture header was not found exactly once.';
    }

    $fixtureFunctionFound = strpos($current, 'function rds_fixture_html') !== false;
    rfp_check(
        $checks,
        'Fixture generator function found',
        $fixtureFunctionFound,
        'function rds_fixture_html'
    );

    if (!$fixtureFunctionFound) {
        $errors[] = 'REFUSED: fixture generator function not found.';
    }
}

$prepared = $current;

if (empty($errors) && $isV002) {
    try {
        $prepared = rfp_replace_once(
            $prepared,
            ' * VERSION: v002',
            ' * VERSION: v003',
            'version marker'
        );

        $prepared = rfp_replace_once(
            $prepared,
            ' * LAST MODIFIED: 8/20/2026 10:50:00 pm',
            ' * LAST MODIFIED: 8/20/2026 11:05:00 pm',
            'timestamp marker'
        );

        $oldChangelog = <<<'OLD'
 * CHANGELOG:
 * v002 (8/20/2026 10:50:00 pm)
OLD;

        $newChangelog = <<<'NEW'
 * CHANGELOG:
 * v003 (8/20/2026 11:05:00 pm)
 * - FIX: Simulation fixture header now uses TD cells so the shared snapshot
 *   parser's ".//tr[td]" pass can see the header row and begin parsing data.
 * - PRESERVE: Same fixture columns/data, same real parser, no DB or snapshot writes.
 *
 * v002 (8/20/2026 10:50:00 pm)
NEW;

        $prepared = rfp_replace_once(
            $prepared,
            $oldChangelog,
            $newChangelog,
            'changelog insertion'
        );

        $prepared = rfp_replace_once(
            $prepared,
            $oldHeader,
            $newHeader,
            'fixture header conversion'
        );

        rfp_check(
            $checks,
            'Fixture parser fix prepared',
            true,
            'TH header -> TD header; simulator v002 -> v003'
        );
    } catch (Throwable $e) {
        $errors[] = 'Transform failed: ' . $e->getMessage();
        rfp_check($checks, 'Fixture parser fix prepared', false, $e->getMessage());
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['install']) &&
    empty($errors)
) {
    if ($isV003) {
        $installed = true;
    } else {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            $errors[] = 'Could not create backup directory: ' . $backupDir;
        }

        if (empty($errors) && !@copy($simPath, $backupDir . '/admin_rd_simulation.php')) {
            $errors[] = 'Could not back up admin_rd_simulation.php.';
        }

        if (empty($errors) && !rfp_atomic_write($simPath, $prepared)) {
            @copy($backupDir . '/admin_rd_simulation.php', $simPath);
            $errors[] = 'Write failed; rollback attempted.';
        }

        if (empty($errors)) {
            $after = (string)@file_get_contents($simPath);

            $postflight = [
                ['Simulator v003 installed', strpos($after, 'VERSION: v003') !== false],
                ['Old TH fixture header removed', strpos($after, $oldHeader) === false],
                ['Parser-compatible TD fixture header installed', strpos($after, $newHeader) !== false],
                ['Underlying Eligibility diagnostics preserved', strpos($after, 'underlying_qualifiers') !== false],
                ['No RD submission code added', strpos($after, 'INSERT INTO user_picks') === false],
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
<title>MRL RD Simulation Fixture Parser Patch</title>
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
td{padding:6px 7px;border-bottom:1px solid #333}
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
    <h1>MRL RD Simulation Fixture Parser Patch v001</h1>
    <div class="sub">TESTPHP8 ONLY • simulator v002 → v003 • DB changes: NONE</div>
</div>

<div class="card">
    <h2>Why the Red Alert Happened</h2>
    <div>
        The simulation fixture used <code>&lt;th&gt;</code> for its column header.
        The real shared snapshot parser finds that header, but its second parsing pass
        intentionally walks <code>.//tr[td]</code>. Because the fixture header had no
        <code>&lt;td&gt;</code> cells, that pass never saw the header and returned no drivers.
    </div>
    <div class="note" style="margin-top:7px">
        The fix is deliberately tiny: only the isolated simulator fixture header changes
        from TH cells to TD cells. We keep testing through the real shared snapshot parser.
    </div>
</div>

<div class="card">
    <h2>Preflight</h2>
    <table>
        <?php foreach ($checks as $c): ?>
        <tr>
            <td style="width:42%"><?=rfp_h($c['name'])?></td>
            <td style="width:8%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
            <td><?=rfp_h($c['detail'])?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
    <h2 class="bad">STOPPED SAFELY</h2>
    <?php foreach ($errors as $e): ?><div class="bad">• <?=rfp_h($e)?></div><?php endforeach; ?>
</div>
<?php elseif (!$installed): ?>
<div class="card">
    <h2>Ready to Install</h2>
    <div class="note" style="margin-bottom:9px">
        Changes only <code>race_results/admin_rd_simulation.php</code>.
        No DB, helper, normal snapshot, team.php, submission, or Live changes.
    </div>
    <form method="post">
        <button type="submit" name="install" value="1">INSTALL FIXTURE PARSER PATCH</button>
    </form>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card success">
    <h2 class="ok">INSTALL COMPLETE</h2>

    <?php if (is_dir($backupDir)): ?>
    <div><strong>Backup folder:</strong><br><code><?=rfp_h($backupDir)?></code></div>
    <?php endif; ?>

    <table style="margin-top:8px">
        <?php foreach ($postflight as $pf): ?>
        <tr>
            <td><?=rfp_h($pf[0])?></td>
            <td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div style="margin-top:10px">
        <a href="/race_results/admin_rd_simulation.php?year=2026&segment=S1&through=6">Retry R06 Simulation</a>
    </div>

    <div class="note" style="margin-top:8px">
        Retry Alex Bowman, NET 0, R06 checked. Expected underlying result:
        RD_AVAILABLE / Bowman [R05/R06] / effective R07.
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
