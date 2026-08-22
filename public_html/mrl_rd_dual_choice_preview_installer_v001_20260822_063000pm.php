<?php
declare(strict_types=1);

/**
 * MRL RD Dual-Qualifier User-Choice Preview Installer
 *
 * VERSION: v001
 * GENERATED: 8/22/2026 6:30:00 pm America/New_York
 *
 * TARGET:
 *   TESTPHP8 ONLY
 *   /public_html/race_results/admin_rd_simulation.php
 *
 * PURPOSE:
 *   Upgrade RD simulator v006 -> v007 to prove the user-choice behavior
 *   for MULTIPLE_RD_AVAILABLE:
 *
 *       ○ Driver A
 *       ○ Driver B
 *
 *   Exactly one eligible driver must be selected before the simulated
 *   Replacement Pick can "continue".
 *
 * IMPORTANT:
 *   This remains a simulator/UI proof only.
 *   It DOES NOT submit an RD/RP.
 *   It DOES NOT write the DB.
 *   It DOES NOT change normal snapshots.
 *   It DOES NOT modify the shared RD helper.
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$simFile = $root . '/race_results/admin_rd_simulation.php';
$helperFile = $root . '/race_results/race_results_rd_helper.php';
$backupDir = $root . '/mrl_rd_dual_choice_preview_backup_20260822_063000pm';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;
$prepared = '';

function dcp_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function dcp_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail];
}

function dcp_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 marker, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}

function dcp_atomic_write(string $path, string $content): bool
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

// -----------------------------------------------------------------------------
// PREFLIGHT
// -----------------------------------------------------------------------------

dcp_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host);
dcp_check($checks, 'PHP 7.3 compatible runtime', PHP_VERSION_ID >= 70300, PHP_VERSION);
dcp_check($checks, 'Simulator file exists', is_file($simFile), $simFile);
dcp_check($checks, 'RD helper file exists', is_file($helperFile), $helperFile);

if ($host !== $expectedHost) $errors[] = 'REFUSED: TESTPHP8-only installer.';
if (PHP_VERSION_ID < 70300) $errors[] = 'PHP 7.3 or newer required.';
if (!is_file($simFile)) $errors[] = 'admin_rd_simulation.php not found.';
if (!is_file($helperFile)) $errors[] = 'race_results_rd_helper.php not found.';

$current = is_file($simFile) ? (string)@file_get_contents($simFile) : '';
$helper = is_file($helperFile) ? (string)@file_get_contents($helperFile) : '';

$baseline = [
    'Simulator v006 marker' => strpos($current, 'VERSION: v006') !== false,
    'Dual fixture function present' => strpos($current, 'function rds_make_dual_fixture_set') !== false,
    'Driver 1 selector present' => strpos($current, 'name="override_driver_1"') !== false,
    'Driver 2 selector present' => strpos($current, 'name="override_driver_2"') !== false,
    'MULTIPLE_RD_AVAILABLE display support' => strpos($current, 'MULTIPLE_RD_AVAILABLE') !== false,
    'Underlying qualifier array display' => strpos($current, "underlying_qualifiers") !== false,
    'Shared helper still v005' => strpos($helper, 'VERSION: v005') !== false,
    'Helper user-selection flag exists' => strpos($helper, 'user_selection_required') !== false,
];

$baselineOk = true;
foreach ($baseline as $label => $ok) {
    dcp_check($checks, $label, $ok, $ok ? 'PASS' : 'FAIL');
    if (!$ok) $baselineOk = false;
}

if (!$baselineOk) {
    $errors[] = 'REFUSED: Current simulator/helper does not match the expected post-dual-test baseline.';
}

// -----------------------------------------------------------------------------
// TRANSFORM
// -----------------------------------------------------------------------------

if (empty($errors)) {
    try {
        $prepared = $current;

        $prepared = dcp_replace_once(
            $prepared,
            'VERSION: v006',
            'VERSION: v007',
            'Version marker'
        );

        $prepared = dcp_replace_once(
            $prepared,
            'LAST MODIFIED: 8/22/2026 6:07:00 pm',
            'LAST MODIFIED: 8/22/2026 6:30:00 pm',
            'Last-modified marker'
        );

        $changelogMarker = " * CHANGELOG:\n";
        $changelogInsert =
            " * CHANGELOG:\n"
            . " * v007 (8/22/2026 6:30:00 pm)\n"
            . " * - NEW: MULTIPLE_RD_AVAILABLE rows now render an explicit user-choice preview\n"
            . " *   with one radio button for each eligible driver.\n"
            . " * - NEW: Browser-required validation prevents continuing without choosing one.\n"
            . " * - NEW: In-page confirmation shows the exact selected driver/slot and states\n"
            . " *   that this is simulation only; no RD/RP is submitted.\n"
            . " * - PRESERVE: Dual-driver fixture simulation, shared RD v005 helper, DB read-only\n"
            . " *   behavior, isolated fixtures, and all prior guards.\n"
            . " *\n";

        $prepared = dcp_replace_once(
            $prepared,
            $changelogMarker,
            $changelogInsert,
            'Changelog insertion'
        );

        // Add styles for the choice preview.
        $styleMarker = ".nowrap{white-space:nowrap}\n";
        $styleInsert =
            ".nowrap{white-space:nowrap}\n"
            . ".choicebox{margin-top:10px;padding:10px;border:1px solid #775f16;background:#2a2412;border-radius:7px}\n"
            . ".choicebox strong{color:#ffe08a}\n"
            . ".choiceopt{display:block;margin:7px 0;padding:7px 9px;background:#171717;border:1px solid #444;border-radius:6px;cursor:pointer}\n"
            . ".choiceopt input{width:auto;margin-right:8px}\n"
            . ".choiceconfirm{margin-top:8px;padding:8px;background:#173b20;border:1px solid #2b7740;border-radius:6px;display:none}\n";

        $prepared = dcp_replace_once(
            $prepared,
            $styleMarker,
            $styleInsert,
            'Choice styles'
        );

        // Replace the qualifier cell with qualifier text plus dual-choice preview.
        $oldQualifierCell = <<<'HTML'
                        <td><?=rds_h(implode(' | ', $qText))?></td>
HTML;

        $newQualifierCell = <<<'HTML'
                        <td>
                            <?=rds_h(implode(' | ', $qText))?>

                            <?php if ($underlyingStatus === 'MULTIPLE_RD_AVAILABLE' && count($qualifiers) > 1): ?>
                                <?php
                                $choiceFormId = 'rdChoice_' . md5((string)($res['teamName'] ?? '') . '_' . implode('|', $qText));
                                ?>
                                <div class="choicebox">
                                    <strong>Replacement Pick — choose the driver to replace:</strong>

                                    <form
                                        onsubmit="return rdsPreviewChoice(this);"
                                        data-team="<?=rds_h((string)($res['teamName'] ?? ''))?>"
                                        style="margin-top:7px"
                                    >
                                        <?php foreach ($qualifiers as $qIndex => $q): ?>
                                            <?php
                                            $qDriver = trim((string)($q['driver'] ?? ''));
                                            $qSlot = trim((string)($q['slot'] ?? ''));
                                            $radioId = $choiceFormId . '_' . (int)$qIndex;
                                            ?>
                                            <label class="choiceopt" for="<?=rds_h($radioId)?>">
                                                <input
                                                    id="<?=rds_h($radioId)?>"
                                                    type="radio"
                                                    name="replacement_driver_choice"
                                                    value="<?=rds_h($qDriver)?>"
                                                    data-slot="<?=rds_h($qSlot)?>"
                                                    required
                                                >
                                                Group <?=rds_h($qSlot)?> — <?=rds_h($qDriver)?>
                                            </label>
                                        <?php endforeach; ?>

                                        <button type="submit">Continue with Selected Driver — Simulation Only</button>

                                        <div class="choiceconfirm"></div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
HTML;

        $prepared = dcp_replace_once(
            $prepared,
            $oldQualifierCell,
            $newQualifierCell,
            'Qualifier choice UI'
        );

        // Add JS before closing body.
        $bodyMarker = "</div>\n</body>\n</html>";
        $bodyInsert = <<<'HTML'
</div>

<script>
function rdsPreviewChoice(form) {
    var selected = form.querySelector('input[name="replacement_driver_choice"]:checked');
    if (!selected) {
        return false;
    }

    var team = form.getAttribute('data-team') || '';
    var slot = selected.getAttribute('data-slot') || '';
    var driver = selected.value || '';
    var box = form.querySelector('.choiceconfirm');

    if (box) {
        box.textContent =
            'SIMULATION CHOICE CONFIRMED — '
            + team
            + ': replace Group '
            + slot
            + ' driver '
            + driver
            + '. No RD/RP has been submitted.';
        box.style.display = 'block';
    }

    return false;
}
</script>
</body>
</html>
HTML;

        $prepared = dcp_replace_once(
            $prepared,
            $bodyMarker,
            $bodyInsert,
            'Choice JavaScript'
        );

        $semantic = [
            'v007 marker prepared' => strpos($prepared, 'VERSION: v007') !== false,
            'Radio input prepared' => strpos($prepared, 'name="replacement_driver_choice"') !== false,
            'Radio input is required' => strpos($prepared, 'data-slot="<?=rds_h($qSlot)?>"') !== false
                && strpos($prepared, 'required') !== false,
            'Dual status gates choice UI' => strpos($prepared, "\$underlyingStatus === 'MULTIPLE_RD_AVAILABLE'") !== false,
            'Choice confirmation function prepared' => strpos($prepared, 'function rdsPreviewChoice(form)') !== false,
            'Simulation-only warning prepared' => strpos($prepared, 'No RD/RP has been submitted.') !== false,
            'Dual fixture function preserved' => strpos($prepared, 'function rds_make_dual_fixture_set') !== false,
            'Shared helper include preserved' => strpos($prepared, "require_once __DIR__ . '/race_results_rd_helper.php';") !== false,
            'No user_picks INSERT added' => stripos($prepared, 'INSERT INTO user_picks') === false,
            'No user_picks UPDATE added' => stripos($prepared, 'UPDATE user_picks') === false,
            'No user_picks DELETE added' => stripos($prepared, 'DELETE FROM user_picks') === false,
        ];

        $semanticOk = true;
        foreach ($semantic as $label => $ok) {
            dcp_check($checks, 'Prepared: ' . $label, $ok, $ok ? 'PASS' : 'FAIL');
            if (!$ok) $semanticOk = false;
        }

        if (!$semanticOk) {
            $errors[] = 'Prepared transform failed one or more semantic checks.';
        }

    } catch (Throwable $e) {
        $errors[] = 'Transform preparation failed: ' . $e->getMessage();
        dcp_check($checks, 'Choice-preview transform prepared', false, $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// INSTALL
// -----------------------------------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['install'])
    && empty($errors)
) {
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        $errors[] = 'Could not create backup directory.';
    }

    if (empty($errors) && !@copy($simFile, $backupDir . '/admin_rd_simulation.php')) {
        $errors[] = 'Could not back up admin_rd_simulation.php.';
    }

    if (empty($errors) && !dcp_atomic_write($simFile, $prepared)) {
        @copy($backupDir . '/admin_rd_simulation.php', $simFile);
        $errors[] = 'Write failed; rollback attempted.';
    }

    if (empty($errors)) {
        $after = (string)@file_get_contents($simFile);

        $postflight = [
            ['Simulator reports v007', strpos($after, 'VERSION: v007') !== false],
            ['Dual-driver fixture function still present', strpos($after, 'function rds_make_dual_fixture_set') !== false],
            ['Dual-choice radio control installed', strpos($after, 'name="replacement_driver_choice"') !== false],
            ['Choice UI gated by MULTIPLE_RD_AVAILABLE', strpos($after, "\$underlyingStatus === 'MULTIPLE_RD_AVAILABLE'") !== false],
            ['Required selection enforcement installed', strpos($after, 'required') !== false],
            ['In-page choice confirmation installed', strpos($after, 'function rdsPreviewChoice(form)') !== false],
            ['Simulation-only warning installed', strpos($after, 'No RD/RP has been submitted.') !== false],
            ['Shared RD helper remains referenced', strpos($after, "require_once __DIR__ . '/race_results_rd_helper.php';") !== false],
            ['No user_picks INSERT introduced', stripos($after, 'INSERT INTO user_picks') === false],
            ['No user_picks UPDATE introduced', stripos($after, 'UPDATE user_picks') === false],
            ['No user_picks DELETE introduced', stripos($after, 'DELETE FROM user_picks') === false],
        ];

        foreach ($postflight as $pf) {
            if (!$pf[1]) {
                $errors[] = 'Postflight failed: ' . $pf[0];
            }
        }

        if (!empty($errors)) {
            @copy($backupDir . '/admin_rd_simulation.php', $simFile);
            $errors[] = 'Postflight failure triggered rollback.';
        } else {
            $installed = true;
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL RD Dual-Qualifier Choice Preview Installer</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:14px}
.banner{background:#24351d;border:1px solid #547c3d;border-radius:10px;padding:12px 14px}
.banner h1{margin:0;color:#dfffcf;font-size:22px}
.sub{font-size:12px;color:#bdd4ae;margin-top:4px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#d5efc9;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333;vertical-align:top}
.ok{color:#69ef98;font-weight:700}
.bad{color:#ff7777;font-weight:700}
.note{font-size:12px;color:#bbb}
code{color:#f2d996}
button{background:#285c32;color:#eaffee;border:1px solid #4b9658;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer}
.success{background:#143725;border-color:#2f6c48}
ul{margin:6px 0 0 20px;padding:0}
a{color:#8fc9ff}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
    <h1>MRL RD Dual-Qualifier User-Choice Preview Installer v001</h1>
    <div class="sub">TESTPHP8 ONLY • generated 8/22/2026 6:30:00 pm • DB changes: NONE</div>
</div>

<div class="card">
    <h2>Review — This Step Only</h2>
    <ul>
        <li><code>admin_rd_simulation.php</code> v006 → v007 only.</li>
        <li>For a <code>MULTIPLE_RD_AVAILABLE</code> team, shows one radio button per qualifying driver.</li>
        <li>No driver is preselected.</li>
        <li>The browser requires exactly one radio choice before the simulated Continue action can fire.</li>
        <li>The Continue action only displays an in-page confirmation of the chosen slot/driver.</li>
        <li>It does <strong>not</strong> submit a Replacement Pick.</li>
        <li>The shared RD helper is not modified.</li>
    </ul>
    <div class="note" style="margin-top:8px">
        This is deliberately the user-choice proof before we connect that choice to the real
        <code>team_replacement_driver.php</code> / submission path.
    </div>
</div>

<div class="card">
    <h2>Preflight / Prepared Transform</h2>
    <table>
    <?php foreach ($checks as $c): ?>
        <tr>
            <td style="width:48%"><?=dcp_h($c['name'])?></td>
            <td style="width:8%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
            <td><?=dcp_h($c['detail'])?></td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
    <h2 class="bad">STOPPED SAFELY</h2>
    <?php foreach ($errors as $e): ?>
        <div class="bad">• <?=dcp_h($e)?></div>
    <?php endforeach; ?>
</div>
<?php elseif (!$installed): ?>
<div class="card">
    <h2>Ready to Install on TESTPHP8</h2>
    <form method="post">
        <button type="submit" name="install" value="1">INSTALL USER-CHOICE PREVIEW v007</button>
    </form>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card success">
    <h2 class="ok">INSTALL COMPLETE</h2>

    <div><strong>Backup folder:</strong><br><code><?=dcp_h($backupDir)?></code></div>

    <table style="margin-top:9px">
    <?php foreach ($postflight as $pf): ?>
        <tr>
            <td><?=dcp_h($pf[0])?></td>
            <td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td>
        </tr>
    <?php endforeach; ?>
    </table>

    <div style="margin-top:10px">
        <a href="/race_results/admin_rd_simulation.php?year=2026&amp;segment=S1&amp;through=7">Open RD Simulator v007</a>
    </div>

    <div class="note" style="margin-top:9px">
        Re-run the Denny Hamlin / Ryan Blaney R06-R07 = 0/0 dual test.
        On each MULTIPLE_RD_AVAILABLE team, verify two radio choices appear.
        First click Continue with neither selected — the browser should refuse.
        Then select one driver and Continue — the page should confirm exactly that driver and slot,
        while stating that no RD/RP was submitted.
    </div>

    <div class="note" style="margin-top:7px">
        After this checkpoint is proven, we can connect the same explicit choice to the real
        Replacement Pick form/submission workflow.
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
