<?php
declare(strict_types=1);

/**
 * MRL RD/RP Time-Travel Test Hook Installer
 *
 * VERSION: v001
 * GENERATED: 8/22/2026 7:59:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 * DATABASE CHANGES: NONE
 *
 * PURPOSE:
 * Allow the already-created Be Like Biff / Denny Hamlin + Ryan Blaney
 * S1 R06/R07 -> R08 fixture to be exercised through the REAL team page
 * even though the real calendar is now in August.
 *
 * TEMPORARY TEST BEHAVIOR:
 * - Applies ONLY when the exact fixture marker exists for:
 *     Be Like Biff
 *     S1
 *     effective R08
 * - team.php pretends "now" is 1 hour before the R08 deadline only for
 *   the RP deadline decision.
 * - submit-team-picks.php allows that same exact fixture through the
 *   server-side effective-race deadline gate.
 * - Normal pick-window logic is NOT changed.
 * - Scheduler behavior is NOT changed.
 * - race_results_rd_helper.php is NOT changed.
 *
 * TARGET FILES:
 *   /public_html/team.php               v024 -> v025
 *   /public_html/submit-team-picks.php  v007 -> v008
 *
 * RESTORE:
 *   This installer also supports RESTORE after the test is complete.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$teamPath = $root . '/team.php';
$submitPath = $root . '/submit-team-picks.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$backupDir = $root . '/mrl_rd_time_travel_test_hook_backup_20260822_075900pm';
$teamBackup = $backupDir . '/team.php';
$submitBackup = $backupDir . '/submit-team-picks.php';

$fixtureMatches = is_dir($root . '/race_results/2026')
    ? glob($root . '/race_results/2026/R07_*/_rd_pending_Be_Like_Biff.fixture_marker_20260822_074600pm.json')
    : [];
if (!is_array($fixtureMatches)) $fixtureMatches = [];

$fixtureMarker = count($fixtureMatches) === 1 ? (string)$fixtureMatches[0] : '';
$fixtureTarget = $fixtureMarker !== ''
    ? dirname($fixtureMarker) . '/_rd_pending_Be_Like_Biff.json'
    : '';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;
$restored = false;

function tt_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tt_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail];
}

function tt_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected 1 source marker, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}

function tt_atomic_write(string $path, string $content): bool
{
    $tmp = $path . '.tt_' . str_replace('.', '', uniqid('', true));
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

function tt_fixture_payload_ok(string $path): bool
{
    if (!is_file($path)) return false;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return false;
    $p = json_decode($raw, true);
    if (!is_array($p)) return false;

    return !empty($p['test_fixture'])
        && (string)($p['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07'
        && (string)($p['teamName'] ?? '') === 'Be Like Biff'
        && (string)($p['segment'] ?? '') === 'S1'
        && (string)($p['effective_race'] ?? '') === 'R08'
        && (int)($p['qualifier_count'] ?? 0) === 2;
}

// -----------------------------------------------------------------------------
// STATE / PREFLIGHT
// -----------------------------------------------------------------------------

$teamSrc = is_file($teamPath) ? (string)@file_get_contents($teamPath) : '';
$submitSrc = is_file($submitPath) ? (string)@file_get_contents($submitPath) : '';
$helperSrc = is_file($helperPath) ? (string)@file_get_contents($helperPath) : '';

$isBaseline = strpos($teamSrc, 'VERSION: v024') !== false
    && strpos($submitSrc, 'VERSION: v007') !== false;

$isHookInstalled = strpos($teamSrc, 'VERSION: v025') !== false
    && strpos($teamSrc, 'MRL_RD_TIME_TRAVEL_FIXTURE') !== false
    && strpos($submitSrc, 'VERSION: v008') !== false
    && strpos($submitSrc, 'mrl_rd_time_travel_fixture_allowed') !== false;

tt_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host);
if ($host !== $expectedHost) $errors[] = 'REFUSED: TESTPHP8 only.';

tt_check($checks, 'team.php exists', is_file($teamPath), $teamPath);
tt_check($checks, 'submit-team-picks.php exists', is_file($submitPath), $submitPath);
tt_check($checks, 'shared RD helper remains v005', strpos($helperSrc, 'VERSION: v005') !== false, 'check only');

if (!is_file($teamPath) || !is_file($submitPath)) {
    $errors[] = 'Required application files are missing.';
}
if (strpos($helperSrc, 'VERSION: v005') === false) {
    $errors[] = 'Shared RD helper is not the expected v005 baseline.';
}

$fixtureMarkerOk = $fixtureMarker !== '' && is_file($fixtureMarker);
$fixturePayloadOk = $fixtureTarget !== '' && tt_fixture_payload_ok($fixtureTarget);

tt_check(
    $checks,
    'Exact fixture marker found',
    $fixtureMarkerOk,
    $fixtureMarkerOk ? $fixtureMarker : ('found ' . count($fixtureMatches))
);
tt_check(
    $checks,
    'Exact Be Like Biff dual fixture is present',
    $fixturePayloadOk,
    $fixtureTarget
);

if (!$fixtureMarkerOk || !$fixturePayloadOk) {
    $errors[] = 'The exact Be Like Biff dual RP fixture must be present before installing the time-travel hook.';
}

tt_check(
    $checks,
    'Application state is recognized',
    $isBaseline || $isHookInstalled,
    $isBaseline ? 'baseline v024/v007' : ($isHookInstalled ? 'time-travel hook already installed' : 'unexpected state')
);

if (!$isBaseline && !$isHookInstalled) {
    $errors[] = 'REFUSED: team.php / submit-team-picks.php do not match either the expected baseline or installed-hook state.';
}

if ($isBaseline) {
    tt_check($checks, 'team.php baseline v024', true, 'PASS');
    tt_check($checks, 'submit-team-picks.php baseline v007', true, 'PASS');
    tt_check(
        $checks,
        'team.php has real RP deadline gate',
        strpos($teamSrc, 'if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp)') !== false,
        'expected v024 gate'
    );
    tt_check(
        $checks,
        'submit has real server deadline gate',
        strpos($submitSrc, 'if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace))') !== false,
        'expected v007 gate'
    );

    if (strpos($teamSrc, 'if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp)') === false) {
        $errors[] = 'team.php deadline marker not found.';
    }
    if (strpos($submitSrc, 'if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace))') === false) {
        $errors[] = 'submit deadline marker not found.';
    }
}

// -----------------------------------------------------------------------------
// PREPARE TRANSFORMS
// -----------------------------------------------------------------------------

$preparedTeam = '';
$preparedSubmit = '';

if (empty($errors) && $isBaseline) {
    try {
        $preparedTeam = $teamSrc;

        $preparedTeam = tt_replace_once(
            $preparedTeam,
            'VERSION: v024',
            'VERSION: v025',
            'team version'
        );

        // v024 came from the real-flow integration at 7:26 pm.
        if (strpos($preparedTeam, 'LAST MODIFIED: 8/22/2026 7:26:00 pm') !== false) {
            $preparedTeam = tt_replace_once(
                $preparedTeam,
                'LAST MODIFIED: 8/22/2026 7:26:00 pm',
                'LAST MODIFIED: 8/22/2026 7:59:00 pm',
                'team timestamp'
            );
        }

        $preparedTeam = tt_replace_once(
            $preparedTeam,
            " * CHANGELOG:\n",
            " * CHANGELOG:\n"
            . " *\n"
            . " * v025 (8/22/2026 7:59:00 pm)\n"
            . " * - TESTPHP8 TEMPORARY: Adds exact Be Like Biff S1 R08 fixture time-travel hook.\n"
            . " * - TEST: RP deadline evaluation pretends now is one hour before R08 only when the exact fixture marker/payload exists.\n"
            . " * - TEST: Displays an unmistakable TEST TIME OVERRIDE banner above the RP wrapper.\n"
            . " * - PRESERVE: Normal pick-window timing, scheduler behavior, LP, charts, and production logic.\n",
            'team changelog'
        );

        $oldGate = <<<'PHP'
            if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp) {
                $showRdWrapper = false;
            }
PHP;

        $newGate = <<<'PHP'
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

        $preparedTeam = tt_replace_once(
            $preparedTeam,
            $oldGate,
            $newGate,
            'team deadline gate'
        );

        $oldInclude = <<<'PHP'
                    if ($showRdWrapper) {
                        include 'team_replacement_driver.php';
PHP;

        $newInclude = <<<'PHP'
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

        $preparedTeam = tt_replace_once(
            $preparedTeam,
            $oldInclude,
            $newInclude,
            'team visible time-travel banner'
        );

        $preparedSubmit = $submitSrc;

        $preparedSubmit = tt_replace_once(
            $preparedSubmit,
            'VERSION: v007',
            'VERSION: v008',
            'submit version'
        );

        if (strpos($preparedSubmit, 'LAST MODIFIED: 8/22/2026 7:26:00 pm') !== false) {
            $preparedSubmit = tt_replace_once(
                $preparedSubmit,
                'LAST MODIFIED: 8/22/2026 7:26:00 pm',
                'LAST MODIFIED: 8/22/2026 7:59:00 pm',
                'submit timestamp'
            );
        }

        $preparedSubmit = tt_replace_once(
            $preparedSubmit,
            " * CHANGELOG:\n",
            " * CHANGELOG:\n"
            . " *\n"
            . " * v008 (8/22/2026 7:59:00 pm)\n"
            . " * - TESTPHP8 TEMPORARY: Allows only the exact owned Be Like Biff S1 R08 fixture through the server deadline gate.\n"
            . " * - TEST: Does not alter real schedule data or normal SEG/LP timing.\n"
            . " * - PRESERVE: All v007 RP qualifier, one-per-year, one-group-only, and history validation remains active.\n",
            'submit changelog'
        );

        $helperFn = <<<'PHP'
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

        $preparedSubmit = tt_replace_once(
            $preparedSubmit,
            "function mrl_rd_reject(): void\n{",
            $helperFn . "function mrl_rd_reject(): void\n{",
            'submit time-travel helper'
        );

        $oldSubmitGate = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        mrl_rd_reject();
    }
PHP;

        $newSubmitGate = <<<'PHP'
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

        $preparedSubmit = tt_replace_once(
            $preparedSubmit,
            $oldSubmitGate,
            $newSubmitGate,
            'submit server deadline gate'
        );

        $semantic = [
            'team reports v025' => strpos($preparedTeam, 'VERSION: v025') !== false,
            'team exact fixture hook installed' => strpos($preparedTeam, 'MRL_RD_TIME_TRAVEL_FIXTURE') !== false,
            'team visible override banner installed' => strpos($preparedTeam, 'TEST TIME OVERRIDE ACTIVE') !== false,
            'team normal window logic untouched' => strpos($preparedTeam, '$normalPickWindowOpen') !== false,
            'submit reports v008' => strpos($preparedSubmit, 'VERSION: v008') !== false,
            'submit exact fixture helper installed' => strpos($preparedSubmit, 'function mrl_rd_time_travel_fixture_allowed') !== false,
            'submit original deadline function still called' => strpos($preparedSubmit, 'mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)') !== false,
            'submit v007 qualifier validation preserved' => strpos($preparedSubmit, 'mrl_rd_normalize_pending_qualifiers') !== false,
            'submit one-per-year history guard preserved' => strpos($preparedSubmit, 'mrl_rd_history_segments') !== false,
            'no DB schema changes' =>
                stripos($preparedTeam . $preparedSubmit, 'ALTER TABLE') === false
                && stripos($preparedTeam . $preparedSubmit, 'CREATE TABLE') === false
                && stripos($preparedTeam . $preparedSubmit, 'DROP TABLE') === false,
        ];

        foreach ($semantic as $label => $ok) {
            tt_check($checks, 'Prepared: ' . $label, $ok, $ok ? 'PASS' : 'FAIL');
            if (!$ok) $errors[] = 'Prepared semantic check failed: ' . $label;
        }

    } catch (Throwable $e) {
        $errors[] = 'Transform failed: ' . $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// INSTALL / RESTORE
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'install' && $isBaseline) {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            $errors[] = 'Could not create backup directory.';
        }

        if (empty($errors)) {
            if (!@copy($teamPath, $teamBackup)) $errors[] = 'Could not back up team.php.';
            if (!@copy($submitPath, $submitBackup)) $errors[] = 'Could not back up submit-team-picks.php.';
        }

        if (empty($errors)) {
            if (!tt_atomic_write($teamPath, $preparedTeam)) $errors[] = 'Could not write team.php.';
            if (!tt_atomic_write($submitPath, $preparedSubmit)) $errors[] = 'Could not write submit-team-picks.php.';
        }

        if (!empty($errors)) {
            if (is_file($teamBackup)) @copy($teamBackup, $teamPath);
            if (is_file($submitBackup)) @copy($submitBackup, $submitPath);
            $errors[] = 'Install problem triggered rollback.';
        } else {
            $teamAfter = (string)@file_get_contents($teamPath);
            $submitAfter = (string)@file_get_contents($submitPath);

            $postflight = [
                ['team.php reports v025', strpos($teamAfter, 'VERSION: v025') !== false],
                ['team exact fixture time hook installed', strpos($teamAfter, 'MRL_RD_TIME_TRAVEL_FIXTURE') !== false],
                ['team visible TEST TIME OVERRIDE banner installed', strpos($teamAfter, 'TEST TIME OVERRIDE ACTIVE') !== false],
                ['submit-team-picks.php reports v008', strpos($submitAfter, 'VERSION: v008') !== false],
                ['submit exact fixture helper installed', strpos($submitAfter, 'mrl_rd_time_travel_fixture_allowed') !== false],
                ['submit still validates pending qualifiers', strpos($submitAfter, 'mrl_rd_normalize_pending_qualifiers') !== false],
                ['submit still enforces RD history guard', strpos($submitAfter, 'mrl_rd_history_segments') !== false],
                ['shared helper still v005', strpos((string)@file_get_contents($helperPath), 'VERSION: v005') !== false],
                ['fixture still present and exact', tt_fixture_payload_ok($fixtureTarget)],
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
                $isHookInstalled = true;
                $isBaseline = false;
            }
        }
    }

    if ($action === 'restore' && $isHookInstalled) {
        if (!is_file($teamBackup) || !is_file($submitBackup)) {
            $errors[] = 'Restore backups are missing.';
        } else {
            $ok1 = @copy($teamBackup, $teamPath);
            $ok2 = @copy($submitBackup, $submitPath);

            if (!$ok1 || !$ok2) {
                $errors[] = 'Restore copy failed.';
            } else {
                $teamAfter = (string)@file_get_contents($teamPath);
                $submitAfter = (string)@file_get_contents($submitPath);

                if (
                    strpos($teamAfter, 'VERSION: v024') === false
                    || strpos($submitAfter, 'VERSION: v007') === false
                ) {
                    $errors[] = 'Restore verification failed.';
                } else {
                    $restored = true;
                    $isBaseline = true;
                    $isHookInstalled = false;
                }
            }
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL RP Time-Travel Test Hook</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1150px;margin:0 auto;padding:14px}
.banner{background:#3b290e;border:1px solid #8f6825;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#ffe3a0;font-size:22px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333}
.ok{color:#69ef98;font-weight:bold}
.bad{color:#ff7777;font-weight:bold}
.warn{color:#ffd36b;font-weight:bold}
code{color:#f2d996}
button{padding:9px 14px;border-radius:7px;font-weight:bold;cursor:pointer}
.install{background:#6b4614;color:#fff3d2;border:1px solid #b98633}
.restore{background:#2d4f6b;color:#eaf6ff;border:1px solid #5b91bb}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL RP Time-Travel Test Hook Installer v001</h1>
<div>TESTPHP8 ONLY • generated 8/22/2026 7:59:00 pm • no DB changes</div>
</div>

<div class="card">
<h2>Purpose</h2>
<p>
Temporarily lets the exact Be Like Biff S1 R06/R07 → R08 fixture behave as though the clock is
<strong>1 hour before R08</strong>. This changes only the RP deadline decision.
</p>
<p class="warn">
Normal pick-window logic is not changed. Keep the S4 window manually closed during this test.
Scheduler should remain OFF.
</p>
</div>

<div class="card">
<h2>Scope</h2>
<table>
<tr><td><code>team.php</code></td><td>v024 → v025 temporary test hook</td></tr>
<tr><td><code>submit-team-picks.php</code></td><td>v007 → v008 temporary test hook</td></tr>
<tr><td><code>race_results_rd_helper.php</code></td><td>v005 — CHECK ONLY, unchanged</td></tr>
<tr><td>Database</td><td>NO schema/data changes by this installer</td></tr>
<tr><td>Schedule JSON</td><td>UNCHANGED</td></tr>
</table>
</div>

<div class="card">
<h2>Preflight</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:55%"><?=tt_h($c['name'])?></td>
<td style="width:10%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
<td><?=tt_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
<h2 class="bad">STOPPED SAFELY</h2>
<?php foreach ($errors as $e): ?><div class="bad">• <?=tt_h($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card">
<h2 class="ok">TIME-TRAVEL TEST HOOK INSTALLED</h2>
<table>
<?php foreach ($postflight as $pf): ?>
<tr><td><?=tt_h($pf[0])?></td><td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td></tr>
<?php endforeach; ?>
</table>
<p>Backup folder: <code><?=tt_h($backupDir)?></code></p>
<p class="warn">Next: refresh normal TESTPHP8 <code>/team.php</code>. Do not submit yet; first verify the RP display and orange TEST TIME OVERRIDE banner.</p>
</div>
<?php elseif ($restored): ?>
<div class="card">
<h2 class="ok">REAL CLOCK LOGIC RESTORED</h2>
<p><code>team.php</code> is back to v024 and <code>submit-team-picks.php</code> is back to v007.</p>
</div>
<?php endif; ?>

<?php if (empty($errors) && $isBaseline): ?>
<div class="card">
<h2>Ready</h2>
<form method="post">
<button class="install" type="submit" name="action" value="install">INSTALL TEMPORARY TIME-TRAVEL TEST HOOK</button>
</form>
</div>
<?php endif; ?>

<?php if (empty($errors) && $isHookInstalled): ?>
<div class="card">
<h2 class="warn">Temporary Hook Is Installed</h2>
<p>After RP testing is complete, restore the real-clock versions before removing the fixture or turning the scheduler back on.</p>
<form method="post">
<button class="restore" type="submit" name="action" value="restore">RESTORE REAL CLOCK LOGIC (v024 / v007)</button>
</form>
</div>
<?php endif; ?>

</div>
</body>
</html>
