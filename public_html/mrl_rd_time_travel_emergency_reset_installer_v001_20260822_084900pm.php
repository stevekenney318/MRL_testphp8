<?php
declare(strict_types=1);

/**
 * MRL RD/RP Time-Travel Emergency Reset Installer
 *
 * VERSION: v001
 * GENERATED: 8/22/2026 8:49:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 * DATABASE CHANGES: NONE
 *
 * PURPOSE:
 * Restore the temporary time-travel test hook back to the real-clock
 * application state even when the original temporary-hook backup folder
 * is missing.
 *
 * IMPORTANT:
 * - This DOES NOT replace team.php or submit-team-picks.php wholesale.
 * - It reverses only the exact temporary test-hook code that was inserted
 *   by mrl_rd_time_travel_test_hook_installer_v001_20260822_075900pm.php.
 * - It creates a NEW backup of the current v025/v008 files before changing them.
 *
 * TARGET:
 *   team.php               v025 -> v024
 *   submit-team-picks.php  v008 -> v007
 *
 * NO CHANGE TO:
 *   team_replacement_driver.php v010 cosmetic polish
 *   database
 *   schedule JSON
 *   RD helper
 *   pending fixture JSON
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$teamPath = $root . '/team.php';
$submitPath = $root . '/submit-team-picks.php';

$backupDir = $root . '/mrl_rd_time_travel_emergency_reset_backup_20260822_084900pm';
$teamBackup = $backupDir . '/team.php.v025.before_emergency_reset';
$submitBackup = $backupDir . '/submit-team-picks.php.v008.before_emergency_reset';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;

function er_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function er_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail];
}

function er_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}

function er_atomic_write(string $path, string $content): bool
{
    $tmp = $path . '.er_' . str_replace('.', '', uniqid('', true));
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

$teamSrc = is_file($teamPath) ? (string)@file_get_contents($teamPath) : '';
$submitSrc = is_file($submitPath) ? (string)@file_get_contents($submitPath) : '';

er_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host);
if ($host !== $expectedHost) $errors[] = 'REFUSED: TESTPHP8 only.';

er_check($checks, 'team.php exists', is_file($teamPath), $teamPath);
er_check($checks, 'submit-team-picks.php exists', is_file($submitPath), $submitPath);

if (!is_file($teamPath) || !is_file($submitPath)) {
    $errors[] = 'Required application files are missing.';
}

$teamIsV025 = strpos($teamSrc, 'VERSION: v025') !== false;
$submitIsV008 = strpos($submitSrc, 'VERSION: v008') !== false;

er_check($checks, 'team.php is temporary v025', $teamIsV025, $teamIsV025 ? 'PASS' : 'unexpected');
er_check($checks, 'submit-team-picks.php is temporary v008', $submitIsV008, $submitIsV008 ? 'PASS' : 'unexpected');

if (!$teamIsV025 || !$submitIsV008) {
    $errors[] = 'REFUSED: expected temporary-hook versions v025 / v008.';
}

$teamMarkers = [
    'MRL_RD_TIME_TRAVEL_FIXTURE',
    'TEST TIME OVERRIDE ACTIVE',
    'rdFixturePayloadIsExact',
    'rdDeadlineNowTimestamp',
];
foreach ($teamMarkers as $marker) {
    $ok = strpos($teamSrc, $marker) !== false;
    er_check($checks, 'team marker: ' . $marker, $ok, $ok ? 'present' : 'missing');
    if (!$ok) $errors[] = 'Missing expected team.php temporary marker: ' . $marker;
}

$submitMarkers = [
    'mrl_rd_time_travel_fixture_allowed',
    'Temporary TESTPHP8 time-travel hook',
    '$rdDeadlineOpen = mrl_lp_effective_race_is_open',
];
foreach ($submitMarkers as $marker) {
    $ok = strpos($submitSrc, $marker) !== false;
    er_check($checks, 'submit marker: ' . $marker, $ok, $ok ? 'present' : 'missing');
    if (!$ok) $errors[] = 'Missing expected submit-team-picks.php temporary marker: ' . $marker;
}

$preparedTeam = '';
$preparedSubmit = '';

if (empty($errors)) {
    try {
        $preparedTeam = $teamSrc;

        $preparedTeam = er_replace_once($preparedTeam, 'VERSION: v025', 'VERSION: v024', 'team version');

        if (strpos($preparedTeam, 'LAST MODIFIED: 8/22/2026 7:59:00 pm') !== false) {
            $preparedTeam = er_replace_once(
                $preparedTeam,
                'LAST MODIFIED: 8/22/2026 7:59:00 pm',
                'LAST MODIFIED: 8/22/2026 7:26:00 pm',
                'team timestamp'
            );
        }

        $teamChangelog = <<<'TXT'
 *
 * v025 (8/22/2026 7:59:00 pm)
 * - TESTPHP8 TEMPORARY: Adds exact Be Like Biff S1 R08 fixture time-travel hook.
 * - TEST: RP deadline evaluation pretends now is one hour before R08 only when the exact fixture marker/payload exists.
 * - TEST: Displays an unmistakable TEST TIME OVERRIDE banner above the RP wrapper.
 * - PRESERVE: Normal pick-window timing, scheduler behavior, LP, charts, and production logic.
TXT;

        $preparedTeam = er_replace_once(
            $preparedTeam,
            $teamChangelog,
            '',
            'team temporary changelog block'
        );

        $temporaryTeamGate = <<<'PHP'
            // MRL_RD_TIME_TRAVEL_FIXTURE
            // TESTPHP8-only temporary hook. It activates only for the exact
            // Be Like Biff fixture owned by the fixture manager.
            $rdDeadlineNowTimestamp = time();
            $rdTestTimeOverrideActive = false;
            $rdTestTimeOverrideTimestamp = 0;

            $rdFixtureMarker = dirname((string)($rdPendingInfo['jsonPath'] ?? ''))
                . '/_rd_pending_Be_Like_Biff.fixture_marker_20260822_074600pm.json';

            $rdFixturePayloadIsExact =
                (string)($rdPendingPayload['teamName'] ?? '') === 'Be Like Biff'
                && (string)($rdPendingPayload['segment'] ?? '') === 'S1'
                && (string)($rdPendingPayload['effective_race'] ?? '') === 'R08'
                && !empty($rdPendingPayload['test_fixture'])
                && (string)($rdPendingPayload['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07'
                && is_file($rdFixtureMarker);

            if ($rdDeadlineTimestamp > 0 && $rdFixturePayloadIsExact) {
                $rdDeadlineNowTimestamp = max(1, $rdDeadlineTimestamp - 3600);
                $rdTestTimeOverrideActive = true;
                $rdTestTimeOverrideTimestamp = $rdDeadlineNowTimestamp;
            }

            if ($rdDeadlineTimestamp > 0 && $rdDeadlineNowTimestamp >= $rdDeadlineTimestamp) {
                $showRdWrapper = false;
            }
PHP;

        $realTeamGate = <<<'PHP'
            if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp) {
                $showRdWrapper = false;
            }
PHP;

        $preparedTeam = er_replace_once(
            $preparedTeam,
            $temporaryTeamGate,
            $realTeamGate,
            'team deadline gate'
        );

        $temporaryInclude = <<<'PHP'
                    if ($showRdWrapper) {
                        if (!empty($rdTestTimeOverrideActive) && !empty($rdTestTimeOverrideTimestamp)) {
                            echo "<div style='width:96%;margin:8px auto;padding:10px;background:#5a3100;border:2px solid #ffb13b;color:#fff3d0;text-align:center;font-weight:bold;font-size:15px;'>"
                                . "TEST TIME OVERRIDE ACTIVE — RP deadline logic is pretending now is "
                                . teampage_h(date('n/j/Y g:i:s a', (int)$rdTestTimeOverrideTimestamp))
                                . " ET (1 hour before R08). REAL CLOCK / SCHEDULE DATA ARE UNCHANGED."
                                . "</div>";
                        }
                        include 'team_replacement_driver.php';
PHP;

        $realInclude = <<<'PHP'
                    if ($showRdWrapper) {
                        include 'team_replacement_driver.php';
PHP;

        $preparedTeam = er_replace_once(
            $preparedTeam,
            $temporaryInclude,
            $realInclude,
            'team visible override banner block'
        );

        $preparedSubmit = $submitSrc;

        $preparedSubmit = er_replace_once($preparedSubmit, 'VERSION: v008', 'VERSION: v007', 'submit version');

        if (strpos($preparedSubmit, 'LAST MODIFIED: 8/22/2026 7:59:00 pm') !== false) {
            $preparedSubmit = er_replace_once(
                $preparedSubmit,
                'LAST MODIFIED: 8/22/2026 7:59:00 pm',
                'LAST MODIFIED: 8/22/2026 7:26:00 pm',
                'submit timestamp'
            );
        }

        $submitChangelog = <<<'TXT'
 *
 * v008 (8/22/2026 7:59:00 pm)
 * - TESTPHP8 TEMPORARY: Allows only the exact owned Be Like Biff S1 R08 fixture through the server deadline gate.
 * - TEST: Does not alter real schedule data or normal SEG/LP timing.
 * - PRESERVE: All v007 RP qualifier, one-per-year, one-group-only, and history validation remains active.
TXT;

        $preparedSubmit = er_replace_once(
            $preparedSubmit,
            $submitChangelog,
            '',
            'submit temporary changelog block'
        );

        $temporaryHelper = <<<'PHP'
function mrl_rd_time_travel_fixture_allowed(string $raceYear, string $teamName, string $segment, int $effectiveRace): bool
{
    // TESTPHP8-only temporary test hook.
    if (
        $raceYear !== '2026'
        || $teamName !== 'Be Like Biff'
        || $segment !== 'S1'
        || $effectiveRace !== 8
    ) {
        return false;
    }

    $baseDir = __DIR__ . '/race_results/2026';
    $markers = is_dir($baseDir)
        ? glob($baseDir . '/R07_*/_rd_pending_Be_Like_Biff.fixture_marker_20260822_074600pm.json')
        : [];

    if (!is_array($markers) || count($markers) !== 1 || !is_file((string)$markers[0])) {
        return false;
    }

    $pendingPath = dirname((string)$markers[0]) . '/_rd_pending_Be_Like_Biff.json';
    if (!is_file($pendingPath)) {
        return false;
    }

    $raw = @file_get_contents($pendingPath);
    if ($raw === false || trim($raw) === '') {
        return false;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return false;
    }

    return !empty($payload['test_fixture'])
        && (string)($payload['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07'
        && (string)($payload['teamName'] ?? '') === 'Be Like Biff'
        && (string)($payload['segment'] ?? '') === 'S1'
        && (string)($payload['effective_race'] ?? '') === 'R08'
        && (int)($payload['qualifier_count'] ?? 0) === 2;
}


PHP;

        $preparedSubmit = er_replace_once(
            $preparedSubmit,
            $temporaryHelper,
            '',
            'submit temporary helper'
        );

        $temporarySubmitGate = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    // Temporary TESTPHP8 time-travel hook: only the exact owned Be Like Biff
    // S1/R08 fixture may bypass the real-calendar deadline during this test.
    $rdDeadlineOpen = mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace);

    if (
        !$rdDeadlineOpen
        && mrl_rd_time_travel_fixture_allowed(
            $raceYearStr,
            $teamName,
            $activeSegment,
            $effectiveRace
        )
    ) {
        $rdDeadlineOpen = true;
    }

    if (!$rdDeadlineOpen) {
        mrl_rd_reject();
    }
PHP;

        $realSubmitGate = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        mrl_rd_reject();
    }
PHP;

        $preparedSubmit = er_replace_once(
            $preparedSubmit,
            $temporarySubmitGate,
            $realSubmitGate,
            'submit deadline gate'
        );

        $semantic = [
            'Prepared team reports v024' => strpos($preparedTeam, 'VERSION: v024') !== false,
            'Prepared team has no time-travel marker' => strpos($preparedTeam, 'MRL_RD_TIME_TRAVEL_FIXTURE') === false,
            'Prepared team has no test override banner' => strpos($preparedTeam, 'TEST TIME OVERRIDE ACTIVE') === false,
            'Prepared team real deadline gate restored' => strpos($preparedTeam, 'if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp)') !== false,
            'Prepared submit reports v007' => strpos($preparedSubmit, 'VERSION: v007') !== false,
            'Prepared submit helper removed' => strpos($preparedSubmit, 'mrl_rd_time_travel_fixture_allowed') === false,
            'Prepared submit real deadline gate restored' => strpos($preparedSubmit, 'if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace))') !== false,
            'No schema statements introduced' =>
                stripos($preparedTeam . $preparedSubmit, 'ALTER TABLE') === false
                && stripos($preparedTeam . $preparedSubmit, 'CREATE TABLE') === false
                && stripos($preparedTeam . $preparedSubmit, 'DROP TABLE') === false,
        ];

        foreach ($semantic as $label => $ok) {
            er_check($checks, $label, $ok, $ok ? 'PASS' : 'FAIL');
            if (!$ok) $errors[] = 'Prepared semantic check failed: ' . $label;
        }

    } catch (Throwable $e) {
        $errors[] = 'Reverse transform failed: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'reset') {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            $errors[] = 'Could not create emergency-reset backup directory.';
        }

        if (empty($errors)) {
            if (!@copy($teamPath, $teamBackup)) $errors[] = 'Could not back up current team.php.';
            if (!@copy($submitPath, $submitBackup)) $errors[] = 'Could not back up current submit-team-picks.php.';
        }

        if (empty($errors)) {
            if (!er_atomic_write($teamPath, $preparedTeam)) $errors[] = 'Could not write restored team.php.';
            if (!er_atomic_write($submitPath, $preparedSubmit)) $errors[] = 'Could not write restored submit-team-picks.php.';
        }

        if (!empty($errors)) {
            if (is_file($teamBackup)) @copy($teamBackup, $teamPath);
            if (is_file($submitBackup)) @copy($submitBackup, $submitPath);
            $errors[] = 'Reset problem triggered rollback to the current temporary-hook files.';
        } else {
            $teamAfter = (string)@file_get_contents($teamPath);
            $submitAfter = (string)@file_get_contents($submitPath);

            $postflight = [
                ['team.php reports v024', strpos($teamAfter, 'VERSION: v024') !== false],
                ['team time-travel marker removed', strpos($teamAfter, 'MRL_RD_TIME_TRAVEL_FIXTURE') === false],
                ['team TEST TIME OVERRIDE banner code removed', strpos($teamAfter, 'TEST TIME OVERRIDE ACTIVE') === false],
                ['team real deadline gate restored', strpos($teamAfter, 'if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp)') !== false],
                ['submit-team-picks.php reports v007', strpos($submitAfter, 'VERSION: v007') !== false],
                ['submit temporary helper removed', strpos($submitAfter, 'mrl_rd_time_travel_fixture_allowed') === false],
                ['submit real deadline gate restored', strpos($submitAfter, 'if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace))') !== false],
            ];

            foreach ($postflight as $pf) {
                if (!$pf[1]) $errors[] = 'Postflight failed: ' . $pf[0];
            }

            if (!empty($errors)) {
                @copy($teamBackup, $teamPath);
                @copy($submitBackup, $submitPath);
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
<title>MRL Time-Travel Emergency Reset</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1150px;margin:0 auto;padding:14px}
.banner{background:#351313;border:1px solid #984646;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#ffb3b3;font-size:22px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333}
.ok{color:#69ef98;font-weight:bold}
.bad{color:#ff7777;font-weight:bold}
.warn{color:#ffd36b;font-weight:bold}
code{color:#f2d996}
button{padding:9px 14px;border-radius:7px;font-weight:bold;cursor:pointer;background:#733434;color:#fff;border:1px solid #b95a5a}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL Time-Travel Emergency Reset v001</h1>
<div>TESTPHP8 ONLY • generated 8/22/2026 8:49:00 pm • no DB changes</div>
</div>

<div class="card">
<h2>Why this exists</h2>
<p>The original time-travel installer can see the temporary hook, but its original restore backups are missing.</p>
<p>This reset does <strong>not</strong> need those backups. It reverses only the exact temporary hook code and makes a <strong>new backup first</strong>.</p>
<p class="warn">It does not touch the cosmetic v010 wrapper, database, fixture JSON, schedule JSON, or scheduler.</p>
</div>

<div class="card">
<h2>Preflight</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:55%"><?=er_h($c['name'])?></td>
<td style="width:10%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
<td><?=er_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
<h2 class="bad">STOPPED SAFELY</h2>
<?php foreach ($errors as $e): ?><div class="bad">• <?=er_h($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card">
<h2 class="ok">REAL CLOCK LOGIC RESTORED</h2>
<table>
<?php foreach ($postflight as $pf): ?>
<tr><td><?=er_h($pf[0])?></td><td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td></tr>
<?php endforeach; ?>
</table>
<p>Emergency backup folder: <code><?=er_h($backupDir)?></code></p>
<p class="warn">Next: Ctrl+F5 team.php, then remove the fake Be Like Biff fixture with the fixture manager.</p>
</div>
<?php elseif (empty($errors)): ?>
<div class="card">
<h2>Ready</h2>
<form method="post">
<button type="submit" name="action" value="reset">RESTORE REAL CLOCK LOGIC WITHOUT ORIGINAL BACKUPS</button>
</form>
</div>
<?php endif; ?>

</div>
</body>
</html>
