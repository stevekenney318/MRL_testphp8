<?php
declare(strict_types=1);

/**
 * MRL Single-Driver RP Test Harness
 *
 * VERSION: v001
 * GENERATED: 8/22/2026 9:06:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 * DATABASE CHANGES: NONE
 *
 * PURPOSE:
 * Streamlined real-flow test for the normal single-driver Replacement Pick case.
 *
 * TEST CASE:
 *   Team: Be Like Biff
 *   Segment: S1
 *   Qualifier: Group B — Denny Hamlin
 *   Trigger races: R06 / R07
 *   Effective race: R08
 *
 * TEMPORARY TEST ACTIONS:
 *   1) Creates one owned pending RP fixture JSON in the 2026 R07_* folder.
 *   2) Temporarily adds an exact time-travel hook to:
 *        team.php               v024 -> v025
 *        submit-team-picks.php  v007 -> v008
 *   3) The hook affects ONLY the exact owned single-driver fixture and makes
 *      RP deadline logic behave as though current time is one hour before R08.
 *
 * PRESERVED:
 *   - team_replacement_driver.php v010
 *   - race_results_rd_helper.php v005
 *   - normal pick-window logic
 *   - schedule JSON
 *   - scheduler configuration
 *   - database contents
 *
 * CLEANUP:
 *   The same tool can remove the fixture and reverse only its own time hook,
 *   restoring team.php v024 and submit-team-picks.php v007.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$teamPath = $root . '/team.php';
$submitPath = $root . '/submit-team-picks.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$yearDir = $root . '/race_results/2026';
$r07Matches = is_dir($yearDir) ? glob($yearDir . '/R07_*') : [];
if (!is_array($r07Matches)) $r07Matches = [];
$r07Dirs = [];
foreach ($r07Matches as $m) {
    if (is_dir($m)) $r07Dirs[] = $m;
}

$r07Dir = count($r07Dirs) === 1 ? (string)$r07Dirs[0] : '';
$fixturePath = $r07Dir !== '' ? $r07Dir . '/_rd_pending_Be_Like_Biff.json' : '';
$markerPath = $r07Dir !== '' ? $r07Dir . '/_rd_pending_Be_Like_Biff.single_fixture_marker_20260822_090600pm.json' : '';

$backupDir = $root . '/mrl_rp_single_test_harness_backup_20260822_090600pm';
$teamBackup = $backupDir . '/team.php.v024';
$submitBackup = $backupDir . '/submit-team-picks.php.v007';

$checks = [];
$errors = [];
$postflight = [];
$message = '';
$installed = false;
$cleaned = false;

function st_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function st_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail];
}

function st_read_json(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function st_write_json(string $path, array $payload): bool
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function st_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}

function st_atomic_write(string $path, string $content): bool
{
    $tmp = $path . '.st_' . str_replace('.', '', uniqid('', true));
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

function st_fixture_is_owned(string $path): bool
{
    $p = st_read_json($path);
    return is_array($p)
        && !empty($p['test_fixture'])
        && (string)($p['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_SINGLE_R06_R07'
        && (string)($p['teamName'] ?? '') === 'Be Like Biff'
        && (string)($p['segment'] ?? '') === 'S1'
        && (string)($p['effective_race'] ?? '') === 'R08'
        && (int)($p['qualifier_count'] ?? 0) === 1
        && (string)($p['qualifiers'][0]['slot'] ?? '') === 'B'
        && (string)($p['qualifiers'][0]['driver'] ?? '') === 'Denny Hamlin';
}

// -----------------------------------------------------------------------------
// Current state
// -----------------------------------------------------------------------------

$teamSrc = is_file($teamPath) ? (string)@file_get_contents($teamPath) : '';
$submitSrc = is_file($submitPath) ? (string)@file_get_contents($submitPath) : '';
$wrapperSrc = is_file($wrapperPath) ? (string)@file_get_contents($wrapperPath) : '';
$helperSrc = is_file($helperPath) ? (string)@file_get_contents($helperPath) : '';

$baselineState =
    strpos($teamSrc, 'VERSION: v024') !== false
    && strpos($submitSrc, 'VERSION: v007') !== false;

$installedState =
    strpos($teamSrc, 'VERSION: v025') !== false
    && strpos($teamSrc, 'MRL_RP_SINGLE_TIME_TRAVEL_FIXTURE') !== false
    && strpos($submitSrc, 'VERSION: v008') !== false
    && strpos($submitSrc, 'mrl_rp_single_time_travel_fixture_allowed') !== false;

$fixtureOwned = $fixturePath !== '' && st_fixture_is_owned($fixturePath);
$markerOwned = false;
$markerData = $markerPath !== '' ? st_read_json($markerPath) : null;
if (is_array($markerData)) {
    $markerOwned = (string)($markerData['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_SINGLE_R06_R07';
}

// -----------------------------------------------------------------------------
// PREFLIGHT
// -----------------------------------------------------------------------------

st_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host);
if ($host !== $expectedHost) $errors[] = 'REFUSED: TESTPHP8 only.';

st_check($checks, 'Exactly one 2026 R07 race folder found', count($r07Dirs) === 1, $r07Dir !== '' ? $r07Dir : ('found ' . count($r07Dirs)));
if (count($r07Dirs) !== 1) $errors[] = 'Expected exactly one 2026 R07_* folder.';

if ($r07Dir !== '') {
    st_check($checks, 'R07 folder is writable', is_writable($r07Dir), $r07Dir);
    if (!is_writable($r07Dir)) $errors[] = 'R07 folder is not writable.';
}

st_check($checks, 'team_replacement_driver.php is v010',
    strpos($wrapperSrc, 'VERSION: v010') !== false
    && strpos($wrapperSrc, "\$rdWrapperVersion = 'v010';") !== false,
    'required polished wrapper');

st_check($checks, 'shared RD helper remains v005',
    strpos($helperSrc, 'VERSION: v005') !== false,
    'check only');

if (strpos($wrapperSrc, 'VERSION: v010') === false) {
    $errors[] = 'Expected polished wrapper v010.';
}
if (strpos($helperSrc, 'VERSION: v005') === false) {
    $errors[] = 'Expected shared RD helper v005.';
}

st_check(
    $checks,
    'Application state is recognized',
    $baselineState || $installedState,
    $baselineState ? 'baseline v024/v007' : ($installedState ? 'single-test hook already installed' : 'unexpected')
);
if (!$baselineState && !$installedState) {
    $errors[] = 'team.php / submit-team-picks.php are not in a recognized single-test state.';
}

if ($baselineState) {
    st_check($checks, 'team.php baseline v024', true, 'PASS');
    st_check($checks, 'submit-team-picks.php baseline v007', true, 'PASS');
    st_check($checks, 'team real deadline gate found',
        strpos($teamSrc, 'if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp)') !== false,
        'expected');
    st_check($checks, 'submit real deadline gate found',
        strpos($submitSrc, 'if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace))') !== false,
        'expected');

    if (strpos($teamSrc, 'if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp)') === false) {
        $errors[] = 'team.php real deadline gate marker missing.';
    }
    if (strpos($submitSrc, 'if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace))') === false) {
        $errors[] = 'submit real deadline gate marker missing.';
    }

    if (is_file($fixturePath) && !$fixtureOwned) {
        $errors[] = 'A non-owned Be Like Biff pending JSON already exists. Refusing to overwrite it.';
    }

    if (is_file($markerPath) && !$markerOwned) {
        $errors[] = 'A non-owned single-test marker already exists.';
    }
}

// -----------------------------------------------------------------------------
// Prepare reversible time-travel transform
// -----------------------------------------------------------------------------

$preparedTeam = '';
$preparedSubmit = '';

$teamTempGate = <<<'PHP'
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
                $rdDeadlineNowTimestamp = max(1, $rdDeadlineTimestamp - 3600);
                $rdTestTimeOverrideActive = true;
                $rdTestTimeOverrideTimestamp = $rdDeadlineNowTimestamp;
            }

            if ($rdDeadlineTimestamp > 0 && $rdDeadlineNowTimestamp >= $rdDeadlineTimestamp) {
                $showRdWrapper = false;
            }
PHP;

$teamRealGate = <<<'PHP'
            if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp) {
                $showRdWrapper = false;
            }
PHP;

$teamTempInclude = <<<'PHP'
                    if ($showRdWrapper) {
                        if (!empty($rdTestTimeOverrideActive) && !empty($rdTestTimeOverrideTimestamp)) {
                            echo "<div style='width:96%;margin:8px auto;padding:10px;background:#5a3100;border:2px solid #ffb13b;color:#fff3d0;text-align:center;font-weight:bold;font-size:15px;'>"
                                . "TEST TIME OVERRIDE ACTIVE — SINGLE RP test is pretending now is "
                                . teampage_h(date('n/j/Y g:i:s a', (int)$rdTestTimeOverrideTimestamp))
                                . " ET (1 hour before R08). REAL CLOCK / SCHEDULE DATA ARE UNCHANGED."
                                . "</div>";
                        }
                        include 'team_replacement_driver.php';
PHP;

$teamRealInclude = <<<'PHP'
                    if ($showRdWrapper) {
                        include 'team_replacement_driver.php';
PHP;

$submitHelper = <<<'PHP'
function mrl_rp_single_time_travel_fixture_allowed(string $raceYear, string $teamName, string $segment, int $effectiveRace): bool
{
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
        ? glob($baseDir . '/R07_*/_rd_pending_Be_Like_Biff.single_fixture_marker_20260822_090600pm.json')
        : [];

    if (!is_array($markers) || count($markers) !== 1 || !is_file((string)$markers[0])) {
        return false;
    }

    $pendingPath = dirname((string)$markers[0]) . '/_rd_pending_Be_Like_Biff.json';
    if (!st_single_fixture_payload_ok($pendingPath)) {
        return false;
    }

    return true;
}

function st_single_fixture_payload_ok(string $pendingPath): bool
{
    if (!is_file($pendingPath)) return false;

    $raw = @file_get_contents($pendingPath);
    if ($raw === false || trim($raw) === '') return false;

    $payload = json_decode($raw, true);
    if (!is_array($payload)) return false;

    return !empty($payload['test_fixture'])
        && (string)($payload['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_SINGLE_R06_R07'
        && (string)($payload['teamName'] ?? '') === 'Be Like Biff'
        && (string)($payload['segment'] ?? '') === 'S1'
        && (string)($payload['effective_race'] ?? '') === 'R08'
        && (int)($payload['qualifier_count'] ?? 0) === 1
        && (string)($payload['qualifiers'][0]['slot'] ?? '') === 'B'
        && (string)($payload['qualifiers'][0]['driver'] ?? '') === 'Denny Hamlin';
}


PHP;

$submitTempGate = <<<'PHP'
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

$submitRealGate = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        mrl_rd_reject();
    }
PHP;

if (empty($errors) && $baselineState) {
    try {
        $preparedTeam = $teamSrc;
        $preparedSubmit = $submitSrc;

        $preparedTeam = st_replace_once($preparedTeam, 'VERSION: v024', 'VERSION: v025', 'team version');
        $preparedSubmit = st_replace_once($preparedSubmit, 'VERSION: v007', 'VERSION: v008', 'submit version');

        $preparedTeam = st_replace_once($preparedTeam, $teamRealGate, $teamTempGate, 'team deadline gate');
        $preparedTeam = st_replace_once($preparedTeam, $teamRealInclude, $teamTempInclude, 'team visible banner hook');

        $preparedSubmit = st_replace_once(
            $preparedSubmit,
            "function mrl_rd_reject(): void\n{",
            $submitHelper . "function mrl_rd_reject(): void\n{",
            'submit helper injection'
        );

        $preparedSubmit = st_replace_once($preparedSubmit, $submitRealGate, $submitTempGate, 'submit deadline gate');

        $preparedTeam = st_replace_once(
            $preparedTeam,
            " * CHANGELOG:\n",
            " * CHANGELOG:\n"
            . " *\n"
            . " * v025 (8/22/2026 9:06:00 pm)\n"
            . " * - TESTPHP8 TEMPORARY: Exact single-driver RP time-travel hook for Be Like Biff / Denny Hamlin / S1 / R08.\n"
            . " * - TEST: Does not alter normal pick-window timing or schedule data.\n",
            'team changelog'
        );

        $preparedSubmit = st_replace_once(
            $preparedSubmit,
            " * CHANGELOG:\n",
            " * CHANGELOG:\n"
            . " *\n"
            . " * v008 (8/22/2026 9:06:00 pm)\n"
            . " * - TESTPHP8 TEMPORARY: Allows only the exact owned single-driver RP fixture through the real-calendar deadline gate.\n",
            'submit changelog'
        );

        $semantic = [
            'Prepared team reports v025' => strpos($preparedTeam, 'VERSION: v025') !== false,
            'Prepared team single time hook installed' => strpos($preparedTeam, 'MRL_RP_SINGLE_TIME_TRAVEL_FIXTURE') !== false,
            'Prepared submit reports v008' => strpos($preparedSubmit, 'VERSION: v008') !== false,
            'Prepared submit single fixture helper installed' => strpos($preparedSubmit, 'mrl_rp_single_time_travel_fixture_allowed') !== false,
            'Prepared wrapper remains external v010' => strpos($wrapperSrc, 'VERSION: v010') !== false,
            'No DB schema statements introduced' =>
                stripos($preparedTeam . $preparedSubmit, 'ALTER TABLE') === false
                && stripos($preparedTeam . $preparedSubmit, 'CREATE TABLE') === false
                && stripos($preparedTeam . $preparedSubmit, 'DROP TABLE') === false,
        ];

        foreach ($semantic as $label => $ok) {
            st_check($checks, $label, $ok, $ok ? 'PASS' : 'FAIL');
            if (!$ok) $errors[] = 'Prepared semantic check failed: ' . $label;
        }

    } catch (Throwable $e) {
        $errors[] = 'Transform failed: ' . $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// ACTIONS
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'install' && $baselineState) {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            $errors[] = 'Could not create backup directory.';
        }

        if (empty($errors)) {
            if (!@copy($teamPath, $teamBackup)) $errors[] = 'Could not back up team.php.';
            if (!@copy($submitPath, $submitBackup)) $errors[] = 'Could not back up submit-team-picks.php.';
        }

        if (empty($errors)) {
            $payload = [
                'userID' => 0,
                'teamName' => 'Be Like Biff',
                'segment' => 'S1',
                'status' => 'RD_AVAILABLE',
                'qualifier_count' => 1,
                'qualifiers' => [
                    [
                        'slot' => 'B',
                        'driver' => 'Denny Hamlin',
                        'trigger_races' => ['R06', 'R07'],
                        'effective_race' => 'R08',
                    ],
                ],
                'slot' => 'B',
                'driver' => 'Denny Hamlin',
                'trigger_races' => ['R06', 'R07'],
                'effective_race' => 'R08',
                'detected_at' => date('Y-m-d\TH:i:s'),
                'test_fixture' => true,
                'fixture_id' => 'BE_LIKE_BIFF_DENNY_SINGLE_R06_R07',
            ];

            if (!st_write_json($fixturePath, $payload)) {
                $errors[] = 'Could not create single-driver fixture JSON.';
            } else {
                $markerPayload = [
                    'fixture_id' => 'BE_LIKE_BIFF_DENNY_SINGLE_R06_R07',
                    'target' => $fixturePath,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                if (!st_write_json($markerPath, $markerPayload)) {
                    @unlink($fixturePath);
                    $errors[] = 'Could not create single-driver fixture marker.';
                }
            }
        }

        if (empty($errors)) {
            if (!st_atomic_write($teamPath, $preparedTeam)) $errors[] = 'Could not write temporary team.php.';
            if (!st_atomic_write($submitPath, $preparedSubmit)) $errors[] = 'Could not write temporary submit-team-picks.php.';
        }

        if (!empty($errors)) {
            @unlink($fixturePath);
            @unlink($markerPath);
            if (is_file($teamBackup)) @copy($teamBackup, $teamPath);
            if (is_file($submitBackup)) @copy($submitBackup, $submitPath);
            $errors[] = 'Install problem triggered rollback.';
        } else {
            $teamAfter = (string)@file_get_contents($teamPath);
            $submitAfter = (string)@file_get_contents($submitPath);

            $postflight = [
                ['Single fixture JSON is exact', st_fixture_is_owned($fixturePath)],
                ['Single fixture marker exists', is_file($markerPath)],
                ['team.php reports v025', strpos($teamAfter, 'VERSION: v025') !== false],
                ['team single time-travel marker installed', strpos($teamAfter, 'MRL_RP_SINGLE_TIME_TRAVEL_FIXTURE') !== false],
                ['submit-team-picks.php reports v008', strpos($submitAfter, 'VERSION: v008') !== false],
                ['submit single fixture helper installed', strpos($submitAfter, 'mrl_rp_single_time_travel_fixture_allowed') !== false],
                ['wrapper remains v010', strpos((string)@file_get_contents($wrapperPath), 'VERSION: v010') !== false],
                ['helper remains v005', strpos((string)@file_get_contents($helperPath), 'VERSION: v005') !== false],
            ];

            foreach ($postflight as $pf) {
                if (!$pf[1]) $errors[] = 'Postflight failed: ' . $pf[0];
            }

            if (!empty($errors)) {
                @unlink($fixturePath);
                @unlink($markerPath);
                @copy($teamBackup, $teamPath);
                @copy($submitBackup, $submitPath);
                $errors[] = 'Postflight failure triggered rollback.';
            } else {
                $installed = true;
                $installedState = true;
                $baselineState = false;
                $message = 'SINGLE-DRIVER RP TEST MODE INSTALLED';
            }
        }
    }

    if ($action === 'cleanup' && $installedState) {
        // Reverse only this harness's exact temporary changes.
        try {
            $teamNow = (string)@file_get_contents($teamPath);
            $submitNow = (string)@file_get_contents($submitPath);

            $cleanTeam = st_replace_once($teamNow, 'VERSION: v025', 'VERSION: v024', 'cleanup team version');
            $cleanSubmit = st_replace_once($submitNow, 'VERSION: v008', 'VERSION: v007', 'cleanup submit version');

            $cleanTeam = st_replace_once($cleanTeam, $teamTempGate, $teamRealGate, 'cleanup team deadline gate');
            $cleanTeam = st_replace_once($cleanTeam, $teamTempInclude, $teamRealInclude, 'cleanup team banner hook');

            $cleanSubmit = st_replace_once($cleanSubmit, $submitHelper, '', 'cleanup submit helper');
            $cleanSubmit = st_replace_once($cleanSubmit, $submitTempGate, $submitRealGate, 'cleanup submit deadline gate');

            $teamChangelogBlock = <<<'TXT'
 *
 * v025 (8/22/2026 9:06:00 pm)
 * - TESTPHP8 TEMPORARY: Exact single-driver RP time-travel hook for Be Like Biff / Denny Hamlin / S1 / R08.
 * - TEST: Does not alter normal pick-window timing or schedule data.
TXT;
            $submitChangelogBlock = <<<'TXT'
 *
 * v008 (8/22/2026 9:06:00 pm)
 * - TESTPHP8 TEMPORARY: Allows only the exact owned single-driver RP fixture through the real-calendar deadline gate.
TXT;

            $cleanTeam = st_replace_once($cleanTeam, $teamChangelogBlock, '', 'cleanup team changelog');
            $cleanSubmit = st_replace_once($cleanSubmit, $submitChangelogBlock, '', 'cleanup submit changelog');

            if (!st_atomic_write($teamPath, $cleanTeam)) {
                throw new RuntimeException('Could not write cleaned team.php.');
            }
            if (!st_atomic_write($submitPath, $cleanSubmit)) {
                throw new RuntimeException('Could not write cleaned submit-team-picks.php.');
            }

            if (is_file($fixturePath) && !st_fixture_is_owned($fixturePath)) {
                throw new RuntimeException('Fixture ownership changed; refusing to delete pending JSON.');
            }

            if (is_file($fixturePath)) @unlink($fixturePath);
            if (is_file($markerPath)) @unlink($markerPath);

            $postflight = [
                ['team.php restored to v024', strpos((string)@file_get_contents($teamPath), 'VERSION: v024') !== false],
                ['team single test marker removed', strpos((string)@file_get_contents($teamPath), 'MRL_RP_SINGLE_TIME_TRAVEL_FIXTURE') === false],
                ['submit restored to v007', strpos((string)@file_get_contents($submitPath), 'VERSION: v007') !== false],
                ['submit single fixture helper removed', strpos((string)@file_get_contents($submitPath), 'mrl_rp_single_time_travel_fixture_allowed') === false],
                ['single fixture JSON removed', !is_file($fixturePath)],
                ['single fixture marker removed', !is_file($markerPath)],
                ['wrapper still v010', strpos((string)@file_get_contents($wrapperPath), 'VERSION: v010') !== false],
                ['helper still v005', strpos((string)@file_get_contents($helperPath), 'VERSION: v005') !== false],
            ];

            foreach ($postflight as $pf) {
                if (!$pf[1]) throw new RuntimeException('Postflight failed: ' . $pf[0]);
            }

            $cleaned = true;
            $installedState = false;
            $baselineState = true;
            $message = 'SINGLE-DRIVER RP TEST MODE REMOVED';

        } catch (Throwable $e) {
            $errors[] = 'Cleanup failed: ' . $e->getMessage();
            $errors[] = 'STOP: Leave scheduler OFF and do not make further changes until this is reviewed.';
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL Single RP Test Harness</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:14px}
.banner{background:#21331b;border:1px solid #5e844d;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#caffb8;font-size:22px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333}
.ok{color:#69ef98;font-weight:bold}
.bad{color:#ff7777;font-weight:bold}
.warn{color:#ffd36b;font-weight:bold}
code{color:#f2d996}
button{padding:9px 14px;border-radius:7px;font-weight:bold;cursor:pointer}
.install{background:#315f26;color:#efffe9;border:1px solid #65a954}
.cleanup{background:#704121;color:#fff1df;border:1px solid #b17246}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL Single-Driver Replacement Pick Test Harness v001</h1>
<div>TESTPHP8 ONLY • Be Like Biff • Denny Hamlin only • R06/R07 → R08 • no DB changes by this tool</div>
</div>

<div class="card">
<h2>Before You Start</h2>
<p class="warn"><strong>Turn the scheduler OFF before installing this test mode.</strong></p>
<p>Your clean_baseline DB backup is the restore point after the submission/edit tests.</p>
</div>

<div class="card">
<h2>Test Case</h2>
<table>
<tr><td>Team</td><td>Be Like Biff</td></tr>
<tr><td>Eligible driver</td><td>Group B — Denny Hamlin</td></tr>
<tr><td>Trigger races</td><td>R06 / R07</td></tr>
<tr><td>Effective race</td><td>R08</td></tr>
<tr><td>Expected UI</td><td>Exactly one explicit required qualifier choice</td></tr>
</table>
</div>

<div class="card">
<h2>Preflight</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:55%"><?=st_h($c['name'])?></td>
<td style="width:10%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
<td><?=st_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
<h2 class="bad">STOPPED SAFELY</h2>
<?php foreach ($errors as $e): ?><div class="bad">• <?=st_h($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (($installed || $cleaned) && empty($errors)): ?>
<div class="card">
<h2 class="ok"><?=st_h($message)?></h2>
<table>
<?php foreach ($postflight as $pf): ?>
<tr><td><?=st_h($pf[0])?></td><td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td></tr>
<?php endforeach; ?>
</table>
<?php if ($installed): ?>
<p class="warn">Next: Ctrl+F5 normal TESTPHP8 /team.php. Do not submit yet. First verify that only Denny Hamlin appears as the explicit RP qualifier.</p>
<?php else: ?>
<p class="warn">Cleanup complete. Ctrl+F5 team.php, restore your clean_baseline DB if the test created/edited RD rows, then turn the scheduler back ON.</p>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($errors) && $baselineState): ?>
<div class="card">
<h2>Ready</h2>
<form method="post">
<button class="install" type="submit" name="action" value="install">INSTALL SINGLE-DRIVER RP TEST MODE</button>
</form>
</div>
<?php endif; ?>

<?php if (empty($errors) && $installedState): ?>
<div class="card">
<h2 class="warn">Single-Driver Test Mode Is Installed</h2>
<p>Leave the scheduler OFF until the entire single-driver test is complete.</p>
<form method="post">
<button class="cleanup" type="submit" name="action" value="cleanup">REMOVE SINGLE-DRIVER RP TEST MODE</button>
</form>
</div>
<?php endif; ?>

</div>
</body>
</html>
