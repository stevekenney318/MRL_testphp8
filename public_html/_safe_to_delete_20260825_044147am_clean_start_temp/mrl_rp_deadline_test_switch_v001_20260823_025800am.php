<?php
/**
 * MRL TESTPHP8 — RP DEADLINE TEST PHASE SWITCH
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 2:58:00 am
 *
 * PURPOSE
 * -------
 * Switch the already-installed single-driver RP test harness from:
 *   PRE-DEADLINE: 4/12/2026 2:00 pm ET (1 hour before R08)
 * to:
 *   POST-DEADLINE: 4/12/2026 3:01 pm ET (1 minute after R08 start)
 *
 * Exact owned fixture:
 *   Team: Be Like Biff
 *   Segment: S1
 *   Eligible driver: Group B — Denny Hamlin
 *   Trigger races: R06 / R07
 *   Effective race: R08
 *
 * SAFETY
 * ------
 * - TESTPHP8 ONLY.
 * - No database writes by this installer.
 * - Does not modify schedule JSON.
 * - Requires the exact single-driver fixture/marker already installed.
 * - Requires team.php v025 and submit-team-picks.php v008.
 * - Changes team.php to v026 and submit-team-picks.php to v009.
 * - Creates backups before changing either file.
 * - Rolls back both files automatically if postflight validation fails.
 * - Includes a cleanup action that removes the entire single-driver RP
 *   test mode from the POST-DEADLINE state and restores baseline
 *   team.php v024 / submit-team-picks.php v007.
 *
 * IMPORTANT
 * ---------
 * Keep the scheduler OFF during this artificial-time test.
 * After the deadline test is complete, use the cleanup button here,
 * then restore the database from the clean_baseline backup separately.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

$SELF_VERSION = 'v001';
$STAMP_ID = '20260823_025800am';
$FIXTURE_ID = 'BE_LIKE_BIFF_DENNY_SINGLE_R06_R07';

$root = __DIR__;
$teamPath = $root . '/team.php';
$submitPath = $root . '/submit-team-picks.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function read_file_strict(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) {
        throw new RuntimeException('Unable to read: ' . $path);
    }
    return $s;
}
function write_file_strict(string $path, string $content): void {
    $tmp = $path . '.tmp_' . uniqid('', true);
    if (@file_put_contents($tmp, $content) === false) {
        throw new RuntimeException('Unable to write temp file: ' . $tmp);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace: ' . $path);
    }
}
function replace_once(string $src, string $old, string $new, string $label): string {
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count);
    }
    return str_replace($old, $new, $src);
}
function version_is(string $src, string $version): bool {
    return strpos($src, 'VERSION: ' . $version) !== false;
}
function is_testphp8_host(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return false;
    return strpos($host, 'testphp8.manliusracingleague.com') !== false;
}
function find_fixture_paths(string $root): array {
    $dirs = glob($root . '/race_results/2026/R07_*', GLOB_ONLYDIR);
    if (!is_array($dirs) || count($dirs) !== 1) {
        return ['', '', '', count((array)$dirs)];
    }
    $dir = $dirs[0];
    return [
        $dir,
        $dir . '/_rd_pending_Be_Like_Biff.json',
        $dir . '/_rd_pending_Be_Like_Biff.single_fixture_marker_20260822_090600pm.json',
        1
    ];
}
function fixture_exact(string $fixturePath): bool {
    if (!is_file($fixturePath)) return false;
    $raw = @file_get_contents($fixturePath);
    if ($raw === false) return false;
    $p = json_decode($raw, true);
    if (!is_array($p)) return false;
    return !empty($p['test_fixture'])
        && (string)($p['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_SINGLE_R06_R07'
        && (string)($p['teamName'] ?? '') === 'Be Like Biff'
        && (string)($p['segment'] ?? '') === 'S1'
        && (string)($p['effective_race'] ?? '') === 'R08'
        && (int)($p['qualifier_count'] ?? 0) === 1
        && (string)($p['qualifiers'][0]['slot'] ?? '') === 'B'
        && (string)($p['qualifiers'][0]['driver'] ?? '') === 'Denny Hamlin';
}

list($raceDir, $fixturePath, $markerPath, $raceDirCount) = find_fixture_paths($root);

$action = (string)($_POST['action'] ?? '');
$messages = [];
$errors = [];
$installed = false;

if (!is_testphp8_host()) {
    $errors[] = 'REFUSED: this installer may run only on testphp8.manliusracingleague.com.';
}

if ($raceDirCount !== 1) {
    $errors[] = 'Expected exactly one race_results/2026/R07_* directory; found ' . $raceDirCount . '.';
}

foreach ([$teamPath, $submitPath, $wrapperPath, $helperPath] as $p) {
    if (!is_file($p)) $errors[] = 'Missing required file: ' . $p;
}

$teamSrc = is_file($teamPath) ? read_file_strict($teamPath) : '';
$submitSrc = is_file($submitPath) ? read_file_strict($submitPath) : '';
$wrapperSrc = is_file($wrapperPath) ? read_file_strict($wrapperPath) : '';
$helperSrc = is_file($helperPath) ? read_file_strict($helperPath) : '';

$fixtureOk = $fixturePath !== '' && fixture_exact($fixturePath);
$markerOk = $markerPath !== '' && is_file($markerPath);

$preState =
    version_is($teamSrc, 'v025')
    && strpos($teamSrc, 'MRL_RP_SINGLE_TIME_TRAVEL_FIXTURE') !== false
    && strpos($teamSrc, '$rdDeadlineTimestamp - 3600') !== false
    && version_is($submitSrc, 'v008')
    && strpos($submitSrc, 'mrl_rp_single_time_travel_fixture_allowed') !== false;

$postState =
    version_is($teamSrc, 'v026')
    && strpos($teamSrc, 'MRL_RP_SINGLE_TIME_TRAVEL_FIXTURE') !== false
    && strpos($teamSrc, '$rdDeadlineTimestamp + 60') !== false
    && version_is($submitSrc, 'v009')
    && strpos($submitSrc, 'MRL_RP_DEADLINE_TEST_SERVER_GATE') !== false;

if (!$fixtureOk) $errors[] = 'Exact owned single-driver fixture JSON is not present.';
if (!$markerOk) $errors[] = 'Single-driver fixture marker is not present.';
if (!version_is($wrapperSrc, 'v010')) $errors[] = 'team_replacement_driver.php is not v010.';
if (!version_is($helperSrc, 'v005')) $errors[] = 'race_results_rd_helper.php is not v005.';
if (!$preState && !$postState) {
    $errors[] = 'Current team.php / submit-team-picks.php state is neither expected pre-deadline nor expected post-deadline test state.';
}

$backupDir = $root . '/mrl_rp_deadline_test_switch_backup_' . $STAMP_ID;

if ($action === 'install' && empty($errors)) {
    if ($postState) {
        $messages[] = 'Deadline test phase is already installed; no changes were needed.';
        $installed = true;
    } else {
        try {
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
                throw new RuntimeException('Unable to create backup directory: ' . $backupDir);
            }
            if (!@copy($teamPath, $backupDir . '/team.php')) {
                throw new RuntimeException('Unable to back up team.php.');
            }
            if (!@copy($submitPath, $backupDir . '/submit-team-picks.php')) {
                throw new RuntimeException('Unable to back up submit-team-picks.php.');
            }

            $newTeam = $teamSrc;
            $newSubmit = $submitSrc;

            $newTeam = replace_once($newTeam, 'VERSION: v025', 'VERSION: v026', 'team version');
            $newSubmit = replace_once($newSubmit, 'VERSION: v008', 'VERSION: v009', 'submit version');

            $newTeam = replace_once(
                $newTeam,
                '$rdDeadlineNowTimestamp = max(1, $rdDeadlineTimestamp - 3600);',
                '$rdDeadlineNowTimestamp = $rdDeadlineTimestamp + 60;',
                'team artificial time'
            );

            $newTeam = replace_once(
                $newTeam,
                '. " ET (1 hour before R08). REAL CLOCK / SCHEDULE DATA ARE UNCHANGED."',
                '. " ET (1 minute AFTER R08 start). REAL CLOCK / SCHEDULE DATA ARE UNCHANGED."',
                'team banner text'
            );

            $oldSubmitGate = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    $rdDeadlineOpen = mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace);

    if (
        !$rdDeadlineOpen
        && mrl_rp_single_time_travel_fixture_allowed(
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

            $newSubmitGate = <<<'PHP'
    // MRL_RP_DEADLINE_TEST_SERVER_GATE
    // POST-DEADLINE test phase: do NOT bypass the real server-side deadline gate.
    // The exact fixture remains installed only so team.php can render the controlled
    // after-R08 state. Any attempted RP submission must now be rejected.
    if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        mrl_rd_reject();
    }
PHP;

            $newSubmit = replace_once(
                $newSubmit,
                $oldSubmitGate,
                $newSubmitGate,
                'submit deadline gate'
            );

            write_file_strict($teamPath, $newTeam);
            write_file_strict($submitPath, $newSubmit);

            $teamAfter = read_file_strict($teamPath);
            $submitAfter = read_file_strict($submitPath);

            $checks = [
                'team.php reports v026' => version_is($teamAfter, 'v026'),
                'team post-deadline +60 second override installed' => strpos($teamAfter, '$rdDeadlineTimestamp + 60') !== false,
                'team single fixture marker retained' => strpos($teamAfter, 'MRL_RP_SINGLE_TIME_TRAVEL_FIXTURE') !== false,
                'submit-team-picks.php reports v009' => version_is($submitAfter, 'v009'),
                'submit post-deadline server gate installed' => strpos($submitAfter, 'MRL_RP_DEADLINE_TEST_SERVER_GATE') !== false,
                'fixture JSON still exact' => fixture_exact($fixturePath),
                'fixture marker still exists' => is_file($markerPath),
                'wrapper remains v010' => version_is(read_file_strict($wrapperPath), 'v010'),
                'helper remains v005' => version_is(read_file_strict($helperPath), 'v005'),
            ];

            foreach ($checks as $label => $ok) {
                if (!$ok) throw new RuntimeException('POSTFLIGHT FAILED: ' . $label);
            }

            $messages[] = 'POST-DEADLINE RP TEST PHASE INSTALLED successfully.';
            $installed = true;
        } catch (Throwable $e) {
            if (is_file($backupDir . '/team.php')) @copy($backupDir . '/team.php', $teamPath);
            if (is_file($backupDir . '/submit-team-picks.php')) @copy($backupDir . '/submit-team-picks.php', $submitPath);
            $errors[] = $e->getMessage();
            $errors[] = 'Automatic rollback attempted from the deadline-switch backup.';
        }
    }
}

if ($action === 'cleanup' && empty($errors)) {
    if (!$postState) {
        $errors[] = 'Cleanup requires the exact POST-DEADLINE state (team v026 / submit v009).';
    } else {
        try {
            $newTeam = $teamSrc;
            $newSubmit = $submitSrc;

            $postTeamGate = <<<'PHP'
            // MRL_RP_SINGLE_TIME_TRAVEL_FIXTURE
            // TESTPHP8-only temporary hook for the exact owned single-driver fixture.
            $rdDeadlineNowTimestamp = time();
            $rdTestTimeOverrideActive = false;
            $rdTestTimeOverrideTimestamp = 0;

            $rdFixtureMarker = dirname((string)($rdPendingInfo['jsonPath'] ?? ''))
                . '/_rd_pending_Be_Like_Biff.single_fixture_marker_20260822_090600pm.json';

            $rdFixturePayloadIsExact =
                (string)($rdPendingPayload['teamName'] ?? '') === 'Be Like Biff'
                && (string)($rdPendingPayload['segment'] ?? '') === 'S1'
                && (string)($rdPendingPayload['effective_race'] ?? '') === 'R08'
                && !empty($rdPendingPayload['test_fixture'])
                && (string)($rdPendingPayload['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_SINGLE_R06_R07'
                && (int)($rdPendingPayload['qualifier_count'] ?? 0) === 1
                && is_file($rdFixtureMarker);

            if ($rdDeadlineTimestamp > 0 && $rdFixturePayloadIsExact) {
                $rdDeadlineNowTimestamp = $rdDeadlineTimestamp + 60;
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

            $postTeamInclude = <<<'PHP'
                    if ($showRdWrapper) {
                        if (!empty($rdTestTimeOverrideActive) && !empty($rdTestTimeOverrideTimestamp)) {
                            echo "<div style='width:96%;margin:8px auto;padding:10px;background:#5a3100;border:2px solid #ffb13b;color:#fff3d0;text-align:center;font-weight:bold;font-size:15px;'>"
                                . "TEST TIME OVERRIDE ACTIVE — SINGLE RP test is pretending now is "
                                . teampage_h(date('n/j/Y g:i:s a', (int)$rdTestTimeOverrideTimestamp))
                                . " ET (1 minute AFTER R08 start). REAL CLOCK / SCHEDULE DATA ARE UNCHANGED."
                                . "</div>";
                        }
                        include 'team_replacement_driver.php';
PHP;

            $realTeamInclude = <<<'PHP'
                    if ($showRdWrapper) {
                        include 'team_replacement_driver.php';
PHP;

            $postSubmitGate = <<<'PHP'
    // MRL_RP_DEADLINE_TEST_SERVER_GATE
    // POST-DEADLINE test phase: do NOT bypass the real server-side deadline gate.
    // The exact fixture remains installed only so team.php can render the controlled
    // after-R08 state. Any attempted RP submission must now be rejected.
    if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        mrl_rd_reject();
    }
PHP;

            $realSubmitGate = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        mrl_rd_reject();
    }
PHP;

            $helperStart = strpos($newSubmit, "function mrl_rp_single_time_travel_fixture_allowed");
            $gateStart = strpos($newSubmit, "    // MRL_RP_DEADLINE_TEST_SERVER_GATE");
            if ($helperStart === false || $gateStart === false || $helperStart >= $gateStart) {
                throw new RuntimeException('Unable to locate temporary submit helper block for cleanup.');
            }
            $newSubmit = substr($newSubmit, 0, $helperStart) . substr($newSubmit, $gateStart);

            $newTeam = replace_once($newTeam, $postTeamGate, $realTeamGate, 'cleanup team gate');
            $newTeam = replace_once($newTeam, $postTeamInclude, $realTeamInclude, 'cleanup team include');
            $newSubmit = replace_once($newSubmit, $postSubmitGate, $realSubmitGate, 'cleanup submit gate');

            $newTeam = replace_once($newTeam, 'VERSION: v026', 'VERSION: v024', 'cleanup team version');
            $newSubmit = replace_once($newSubmit, 'VERSION: v009', 'VERSION: v007', 'cleanup submit version');

            write_file_strict($teamPath, $newTeam);
            write_file_strict($submitPath, $newSubmit);

            if (is_file($fixturePath)) @unlink($fixturePath);
            if (is_file($markerPath)) @unlink($markerPath);

            $teamAfter = read_file_strict($teamPath);
            $submitAfter = read_file_strict($submitPath);

            $checks = [
                'team.php restored to v024' => version_is($teamAfter, 'v024'),
                'team test marker removed' => strpos($teamAfter, 'MRL_RP_SINGLE_TIME_TRAVEL_FIXTURE') === false,
                'submit-team-picks.php restored to v007' => version_is($submitAfter, 'v007'),
                'submit test helper removed' => strpos($submitAfter, 'mrl_rp_single_time_travel_fixture_allowed') === false,
                'submit deadline-test marker removed' => strpos($submitAfter, 'MRL_RP_DEADLINE_TEST_SERVER_GATE') === false,
                'fixture JSON removed' => !is_file($fixturePath),
                'fixture marker removed' => !is_file($markerPath),
                'wrapper remains v010' => version_is(read_file_strict($wrapperPath), 'v010'),
                'helper remains v005' => version_is(read_file_strict($helperPath), 'v005'),
            ];

            foreach ($checks as $label => $ok) {
                if (!$ok) throw new RuntimeException('CLEANUP POSTFLIGHT FAILED: ' . $label);
            }

            $messages[] = 'SINGLE-DRIVER RP TEST MODE REMOVED successfully.';
            $messages[] = 'team.php is back to v024 and submit-team-picks.php is back to v007.';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$teamNow = is_file($teamPath) ? read_file_strict($teamPath) : '';
$submitNow = is_file($submitPath) ? read_file_strict($submitPath) : '';
$postStateNow =
    version_is($teamNow, 'v026')
    && strpos($teamNow, '$rdDeadlineTimestamp + 60') !== false
    && version_is($submitNow, 'v009')
    && strpos($submitNow, 'MRL_RP_DEADLINE_TEST_SERVER_GATE') !== false;

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL RP Deadline Test Switch <?=h($SELF_VERSION)?></title>
<style>
body{font-family:Arial,sans-serif;background:#111;color:#eee;margin:20px}
.card{max-width:1280px;margin:0 auto 14px;background:#1b1b1b;border:1px solid #444;border-radius:12px;padding:16px}
h1{color:#56ff9a;margin:0 0 10px;font-size:24px}
h2{color:#ffc44d;margin:0 0 10px;font-size:21px}
.ok{color:#56ff9a;font-weight:bold}.bad{color:#ff7272;font-weight:bold}
.note{color:#ffc44d;font-weight:bold}.muted{color:#bbb}
table{border-collapse:collapse;width:100%;margin-top:10px}
td{border-bottom:1px solid #333;padding:8px}
td:last-child{text-align:right;font-weight:bold}
button{font-size:18px;font-weight:bold;padding:11px 17px;border-radius:8px;border:1px solid #c77a35;background:#7b3f18;color:white;cursor:pointer}
button.install{background:#176b3b;border-color:#54ce86}
.banner{padding:12px;border:2px solid #ffb13b;background:#5a3100;color:#fff3d0;text-align:center;font-weight:bold;margin:12px 0}
.err{background:#481717;border:1px solid #a94444;padding:10px;border-radius:8px;margin:8px 0}
.msg{background:#173c27;border:1px solid #3f985f;padding:10px;border-radius:8px;margin:8px 0}
</style>
</head>
<body>

<div class="card">
<h1>MRL RP DEADLINE TEST PHASE SWITCH <?=h($SELF_VERSION)?></h1>
<div class="muted">TESTPHP8 ONLY • changes artificial RP time from before R08 to 1 minute after R08 • no DB writes</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="card err"><?=h($e)?></div>
<?php endforeach; ?>

<?php foreach ($messages as $m): ?>
<div class="card msg"><?=h($m)?></div>
<?php endforeach; ?>

<div class="card">
<h2>Preflight / Current State</h2>
<table>
<tr><td>TESTPHP8 host</td><td class="<?=is_testphp8_host()?'ok':'bad'?>"><?=is_testphp8_host()?'PASS':'FAIL'?></td></tr>
<tr><td>Exact single fixture JSON exists</td><td class="<?=$fixtureOk?'ok':'bad'?>"><?=$fixtureOk?'PASS':'FAIL'?></td></tr>
<tr><td>Single fixture marker exists</td><td class="<?=$markerOk?'ok':'bad'?>"><?=$markerOk?'PASS':'FAIL'?></td></tr>
<tr><td>wrapper remains v010</td><td class="<?=version_is($wrapperSrc,'v010')?'ok':'bad'?>"><?=version_is($wrapperSrc,'v010')?'PASS':'FAIL'?></td></tr>
<tr><td>helper remains v005</td><td class="<?=version_is($helperSrc,'v005')?'ok':'bad'?>"><?=version_is($helperSrc,'v005')?'PASS':'FAIL'?></td></tr>
<tr><td>Pre-deadline state detected (team v025 / submit v008)</td><td class="<?=$preState?'ok':'muted'?>"><?=$preState?'YES':'NO'?></td></tr>
<tr><td>Post-deadline state detected (team v026 / submit v009)</td><td class="<?=$postStateNow?'ok':'muted'?>"><?=$postStateNow?'YES':'NO'?></td></tr>
</table>
</div>

<?php if (empty($errors) && !$postStateNow): ?>
<div class="card">
<h2>Ready</h2>
<div class="banner">This will make the single-driver RP test pretend it is 4/12/2026 3:01:00 pm ET — one minute AFTER R08 starts.</div>
<form method="post">
<input type="hidden" name="action" value="install">
<button class="install" type="submit">CHANGE TEST TIME TO AFTER R08</button>
</form>
</div>
<?php endif; ?>

<?php if ($postStateNow): ?>
<div class="card">
<h2>POST-DEADLINE RP TEST MODE INSTALLED</h2>
<div class="banner">Next: Ctrl+F5 normal TESTPHP8 /team.php. The RP edit controls should be gone/closed after R08 starts.</div>
<p class="note">Then we will verify server-side rejection. Do not clean up until that check is complete.</p>
<form method="post" onsubmit="return confirm('Remove the entire single-driver RP test mode now?');">
<input type="hidden" name="action" value="cleanup">
<button type="submit">REMOVE SINGLE-DRIVER RP TEST MODE</button>
</form>
</div>
<?php endif; ?>

<div class="card">
<div class="muted">
Backup folder: <?=h($backupDir)?><br>
After final cleanup: restore the database from clean_baseline, turn the scheduler back ON, sync server→local with WinSCP, then commit/push GitHub when appropriate.
</div>
</div>

</body>
</html>
