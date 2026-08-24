<?php
declare(strict_types=1);

/**
 * mrl_rd_simulation_diagnostic_patch.php
 *
 * VERSION: v001
 * GENERATED: 8/20/2026 10:50:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 *
 * PURPOSE:
 * Focused simulator-only refinement after the first RD Phase 2 test showed that
 * historical RD usage in the current TestPHP8 database masks the underlying
 * R04/R05 Alex Bowman eligibility.
 *
 * INSTALLS:
 * - race_results/admin_rd_simulation.php v001 -> v002
 *
 * DOES NOT CHANGE:
 * - race_results_rd_helper.php v005
 * - database schema or data
 * - normal race snapshots
 * - team.php
 * - submit-team-picks.php
 * - Live MRL
 *
 * BEHAVIOR:
 * - Real RD season-used protection remains visible as RD_ALREADY_USED.
 * - Simulator additionally calculates "Underlying Eligibility" from the active
 *   team drivers and trailing completed pair, ignoring ONLY the historical
 *   season-used gate for diagnostic display.
 * - This lets us prove historical cases without deleting/restoring DB records.
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const MRL_RD_SIM_PATCH_VERSION = 'v001';
const MRL_RD_SIM_PATCH_HOST = 'testphp8.manliusracingleague.com';

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$simPath = $root . '/race_results/admin_rd_simulation.php';
$backupDir = $root . '/mrl_rd_simulation_diagnostic_backup_20260820_105000pm';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;

function rsp_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rsp_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

function rsp_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);

    if ($count !== 1) {
        throw new RuntimeException(
            $label . ': expected exactly 1 match, found ' . $count . '.'
        );
    }

    return str_replace($old, $new, $src);
}

function rsp_atomic_write(string $path, string $content): bool
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

rsp_check($checks, 'Host is TESTPHP8', $host === MRL_RD_SIM_PATCH_HOST, $host);
rsp_check($checks, 'Document root available', $root !== '' && is_dir($root), $root);
rsp_check($checks, 'Simulator file exists', is_file($simPath), $simPath);

if ($host !== MRL_RD_SIM_PATCH_HOST) {
    $errors[] = 'REFUSED: TestPHP8-only patch.';
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable.';
}

if (!is_file($simPath)) {
    $errors[] = 'admin_rd_simulation.php not found.';
}

$current = is_file($simPath) ? (string)@file_get_contents($simPath) : '';
$isV001 = strpos($current, 'VERSION: v001') !== false;
$isV002 = strpos($current, 'VERSION: v002') !== false;

rsp_check(
    $checks,
    'Simulator expected source version',
    $isV001 || $isV002,
    $isV002 ? 'VERSION: v002 (already installed)' : 'VERSION: v001'
);

if (!$isV001 && !$isV002) {
    $errors[] = 'REFUSED: simulator is not expected v001/v002 source.';
}

$prepared = $current;

if (empty($errors) && $isV001) {
    try {
        $prepared = rsp_replace_once(
            $prepared,
            ' * VERSION: v001',
            ' * VERSION: v002',
            'version marker'
        );

        $prepared = rsp_replace_once(
            $prepared,
            ' * LAST MODIFIED: 8/20/2026 10:27:00 pm',
            ' * LAST MODIFIED: 8/20/2026 10:50:00 pm',
            'timestamp marker'
        );

        $oldPurposeTail = <<<'OLD'
 * - Runs the shared RD v005 eligibility engine.
 *
 * It NEVER:
OLD;

        $newPurposeTail = <<<'NEW'
 * - Runs the shared RD v005 eligibility engine.
 * - ALSO shows underlying trailing-pair eligibility when the real season-used
 *   guard reports RD_ALREADY_USED. This diagnostic display does not weaken or
 *   bypass the real RD protection.
 *
 * CHANGELOG:
 * v002 (8/20/2026 10:50:00 pm)
 * - NEW: Underlying Eligibility is calculated/displayed even when current DB
 *   history correctly reports RD_ALREADY_USED.
 * - NEW: Real Guard and Underlying Eligibility are shown separately.
 * - PRESERVE: No DB writes, no normal snapshot changes, no RD submission.
 *
 * v001 (8/20/2026 10:27:00 pm)
 * - Initial TestPHP8 RD simulation harness.
 *
 * It NEVER:
NEW;

        $prepared = rsp_replace_once(
            $prepared,
            $oldPurposeTail,
            $newPurposeTail,
            'header/changelog insertion'
        );

        $oldResultsBlock = <<<'OLD'
        $results[] = mrl_rd_detect_team_segment_eligibility(
            $dbo,
            $selectedYear,
            $selectedSegment,
            $teamName,
            $evaluationPoints
        );
OLD;

        $newResultsBlock = <<<'NEW'
        $result = mrl_rd_detect_team_segment_eligibility(
            $dbo,
            $selectedYear,
            $selectedSegment,
            $teamName,
            $evaluationPoints
        );

        /*
         * Diagnostic-only underlying eligibility:
         * If the real helper stops at RD_ALREADY_USED, evaluate each active
         * driver against the same trailing completed pair anyway so we can
         * inspect historical eligibility without altering the DB.
         */
        $underlyingQualifiers = [];

        $activeRow = isset($result['base_pick_row']) && is_array($result['base_pick_row'])
            ? $result['base_pick_row']
            : mrl_rd_active_pick_row(
                $dbo,
                $selectedYear,
                $selectedSegment,
                $teamName,
                $evaluationPoints
            );

        if (is_array($activeRow)) {
            foreach (['A', 'B', 'C', 'D'] as $slot) {
                $field = 'driver' . $slot;

                $slotResult = mrl_rd_detect_slot_current_eligibility(
                    $dbo,
                    (int)$selectedYear,
                    $slot,
                    $selectedSegment,
                    (string)($activeRow[$field] ?? ''),
                    $evaluationPoints
                );

                if (!empty($slotResult['qualified'])) {
                    $underlyingQualifiers[] = $slotResult;
                }
            }
        }

        $result['underlying_qualifiers'] = $underlyingQualifiers;
        $result['underlying_qualifier_count'] = count($underlyingQualifiers);

        if (count($underlyingQualifiers) === 1) {
            $result['underlying_status'] = 'RD_AVAILABLE';
        } elseif (count($underlyingQualifiers) > 1) {
            $result['underlying_status'] = 'MULTIPLE_RD_AVAILABLE';
        } else {
            $result['underlying_status'] = 'NO_RD';
        }

        $results[] = $result;
NEW;

        $prepared = rsp_replace_once(
            $prepared,
            $oldResultsBlock,
            $newResultsBlock,
            'diagnostic eligibility calculation'
        );

        $oldHeaders = <<<'OLD'
                <th>Status</th>
                <th>Qualifier(s)</th>
                <th>Effective</th>
OLD;

        $newHeaders = <<<'NEW'
                <th>Real Guard</th>
                <th>Underlying Eligibility</th>
                <th>Qualifier(s)</th>
                <th>Effective</th>
NEW;

        $prepared = rsp_replace_once(
            $prepared,
            $oldHeaders,
            $newHeaders,
            'table header'
        );

        $prepared = rsp_replace_once(
            $prepared,
            '<tr><td colspan="9">No evaluable teams.</td></tr>',
            '<tr><td colspan="10">No evaluable teams.</td></tr>',
            'empty table colspan'
        );

        $oldRenderPrep = <<<'OLD'
                    $status = (string)($res['status'] ?? '');
                    $qualifiers = isset($res['qualifiers']) && is_array($res['qualifiers'])
                        ? $res['qualifiers']
                        : [];
OLD;

        $newRenderPrep = <<<'NEW'
                    $status = (string)($res['status'] ?? '');
                    $underlyingStatus = (string)($res['underlying_status'] ?? 'NO_RD');

                    /*
                     * For diagnostic display, show underlying qualifiers rather
                     * than the season-gated helper list. When RD has not already
                     * been used these should match the normal helper result.
                     */
                    $qualifiers = isset($res['underlying_qualifiers']) && is_array($res['underlying_qualifiers'])
                        ? $res['underlying_qualifiers']
                        : [];
NEW;

        $prepared = rsp_replace_once(
            $prepared,
            $oldRenderPrep,
            $newRenderPrep,
            'render preparation'
        );

        $oldStatusCells = <<<'OLD'
                        <td class="<?=rds_h(rds_status_class($status))?>"><?=rds_h($status)?></td>
                        <td><?=rds_h(implode(' | ', $qText))?></td>
                        <td><?=rds_h(implode(' / ', array_unique($effectiveText)))?></td>
OLD;

        $newStatusCells = <<<'NEW'
                        <td class="<?=rds_h(rds_status_class($status))?>"><?=rds_h($status)?></td>
                        <td class="<?=rds_h(rds_status_class($underlyingStatus))?>"><?=rds_h($underlyingStatus)?></td>
                        <td><?=rds_h(implode(' | ', $qText))?></td>
                        <td><?=rds_h(implode(' / ', array_unique($effectiveText)))?></td>
NEW;

        $prepared = rsp_replace_once(
            $prepared,
            $oldStatusCells,
            $newStatusCells,
            'status cells'
        );

        $oldEvaluationLabel = <<<'OLD'
        <strong>Evaluation:</strong>
OLD;

        $newEvaluationLabel = <<<'NEW'
        <strong>Evaluation:</strong>
        <span class="small">
            Real Guard honors current DB history. Underlying Eligibility ignores only
            that already-used gate for diagnostics; it never authorizes or submits an RD.
        </span><br>
NEW;

        $prepared = rsp_replace_once(
            $prepared,
            $oldEvaluationLabel,
            $newEvaluationLabel,
            'evaluation explanation'
        );

        rsp_check($checks, 'Diagnostic transform prepared', true, 'admin_rd_simulation.php v001 -> v002');
    } catch (Throwable $e) {
        $errors[] = 'Transform failed: ' . $e->getMessage();
        rsp_check($checks, 'Diagnostic transform prepared', false, $e->getMessage());
    }
}

// ---------------- INSTALL ----------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['install']) &&
    empty($errors)
) {
    if ($isV002) {
        $installed = true;
    } else {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            $errors[] = 'Could not create backup directory: ' . $backupDir;
        }

        if (empty($errors) && !@copy($simPath, $backupDir . '/admin_rd_simulation.php')) {
            $errors[] = 'Could not back up admin_rd_simulation.php.';
        }

        if (empty($errors)) {
            if (!rsp_atomic_write($simPath, $prepared)) {
                @copy($backupDir . '/admin_rd_simulation.php', $simPath);
                $errors[] = 'Write failed; rollback attempted.';
            }
        }

        if (empty($errors)) {
            $after = (string)@file_get_contents($simPath);

            $postflight = [
                ['Simulator v002 installed', strpos($after, 'VERSION: v002') !== false],
                ['Underlying eligibility calculation installed', strpos($after, "underlying_qualifiers") !== false],
                ['Real Guard column installed', strpos($after, '<th>Real Guard</th>') !== false],
                ['Underlying Eligibility column installed', strpos($after, '<th>Underlying Eligibility</th>') !== false],
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
<title>MRL RD Simulation Diagnostic Patch</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1150px;margin:0 auto;padding:15px}
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
.note{font-size:12px;color:#bbb}
a{color:#8fc7ff}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
    <h1>MRL RD Simulation Diagnostic Patch v001</h1>
    <div class="sub">TESTPHP8 ONLY • simulator v001 → v002 • DB changes: NONE</div>
</div>

<div class="card">
    <h2>Preflight</h2>
    <table>
        <?php foreach ($checks as $c): ?>
        <tr>
            <td style="width:42%"><?=rsp_h($c['name'])?></td>
            <td style="width:8%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
            <td><?=rsp_h($c['detail'])?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2>What This Changes</h2>
    <div>
        Real <code>RD_ALREADY_USED</code> protection stays exactly as-is.
        The simulator additionally shows what the trailing-pair eligibility would
        be underneath that guard.
    </div>
    <div class="note" style="margin-top:7px">
        No database changes. No helper changes. No normal snapshot changes.
        No team.php or submit-team-picks.php changes.
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
    <h2 class="bad">STOPPED SAFELY</h2>
    <?php foreach ($errors as $e): ?><div class="bad">• <?=rsp_h($e)?></div><?php endforeach; ?>
</div>
<?php elseif (!$installed): ?>
<div class="card">
    <h2>Ready to Install</h2>
    <form method="post">
        <button type="submit" name="install" value="1">INSTALL SIMULATOR DIAGNOSTIC PATCH</button>
    </form>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card success">
    <h2 class="ok">INSTALL COMPLETE</h2>
    <?php if (is_dir($backupDir)): ?>
    <div><strong>Backup folder:</strong><br><code><?=rsp_h($backupDir)?></code></div>
    <?php endif; ?>

    <table style="margin-top:8px">
        <?php foreach ($postflight as $pf): ?>
        <tr>
            <td><?=rsp_h($pf[0])?></td>
            <td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div style="margin-top:10px">
        <a href="/race_results/admin_rd_simulation.php?year=2026&segment=S1&through=5">Open Bowman R04/R05 Diagnostic</a>
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
