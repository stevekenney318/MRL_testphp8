<?php
/**
 * MRL TESTPHP8 — LP → RP EDGE-CASE TEST HARNESS
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 11:41:00 am
 *
 * PURPOSE
 * -------
 * End-to-end controlled test of the final Replacement Pick edge case:
 *
 *     normal segment baseline → genuine active LP row → RP eligibility → RP form
 *
 * The harness temporarily converts Be Like Biff's existing 2026 S1 base pick
 * row into a genuine LP row effective R06, with AJ Allmendinger in Group B.
 * It then feeds the shared RD helper synthetic completed-race points where
 * AJ has 0 NET points in R06 and R07 while the other active drivers score
 * positive points. The helper itself must detect AJ as the only RP qualifier
 * for R08 before the harness will install the public pending-RP fixture.
 *
 * TEST CASE
 * ---------
 * Team: Be Like Biff
 * Segment: S1
 * LP effective race: R06
 * LP Group B driver: AJ Allmendinger
 * Synthetic RP trigger: R06 = 0, R07 = 0
 * Expected RP effective race: R08
 * Artificial browser time: 4/12/2026 2:00 pm ET (1 hour before R08)
 *
 * SAFETY
 * ------
 * - TESTPHP8 ONLY.
 * - PHP 7.3 compatible.
 * - Live host is refused.
 * - Requires clean baseline app versions:
 *     team.php v024
 *     submit-team-picks.php v007
 *     team_replacement_driver.php v010
 *     race_results/race_results_rd_helper.php v005
 * - Requires exactly one 2026 R07 race folder.
 * - Refuses an existing pending Be Like Biff RP JSON.
 * - Backs up team.php and submit-team-picks.php before changes.
 * - Saves an exact JSON snapshot of the original S1 database row before
 *   changing it.
 * - If installation fails, attempts automatic rollback of both files and
 *   the changed database row.
 * - Does not change schedule JSON or race-result files.
 *
 * IMPORTANT DATABASE WARNING
 * --------------------------
 * THIS HARNESS TEMPORARILY MODIFIES ONE TESTPHP8 DATABASE ROW:
 * Be Like Biff / 2026 / S1 is converted from the clean baseline pick to an
 * LP fixture. Any later RP submission will also write normal RD audit rows.
 *
 * FINAL RESET REQUIRES restoring the TESTPHP8 database from the user's
 * clean_baseline backup after this edge-case test is complete.
 *
 * Keep the scheduler OFF for the entire test.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$STAMP = '20260823_114100am';
$FIXTURE_ID = 'BE_LIKE_BIFF_LP_AJ_R06_R07';
$TEAM = 'Be Like Biff';
$YEAR = '2026';
$SEGMENT = 'S1';

$root = __DIR__;
$teamPath = $root . '/team.php';
$submitPath = $root . '/submit-team-picks.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$backupDir = $root . '/mrl_lp_rp_edge_case_backup_' . $STAMP;
$teamBackup = $backupDir . '/team.php';
$submitBackup = $backupDir . '/submit-team-picks.php';
$dbSnapshotPath = $backupDir . '/be_like_biff_2026_s1_original_row.json';

function er_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function er_is_testphp8_host(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' && strpos($host, 'testphp8.manliusracingleague.com') !== false;
}

function er_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) {
        throw new RuntimeException('Unable to read: ' . $path);
    }
    return $s;
}

function er_write(string $path, string $content): void {
    $tmp = $path . '.tmp_' . uniqid('', true);
    if (@file_put_contents($tmp, $content) === false) {
        throw new RuntimeException('Unable to write temp file: ' . $tmp);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace: ' . $path);
    }
}

function er_replace_once(string $src, string $old, string $new, string $label): string {
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count);
    }
    return str_replace($old, $new, $src);
}

function er_write_json(string $path, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    return @file_put_contents($path, $json . PHP_EOL) !== false;
}

function er_find_r07(string $root): array {
    $dirs = glob($root . '/race_results/2026/R07_*', GLOB_ONLYDIR);
    if (!is_array($dirs) || count($dirs) !== 1) {
        return ['', '', '', count((array)$dirs)];
    }

    $dir = $dirs[0];
    return [
        $dir,
        $dir . '/_rd_pending_Be_Like_Biff.json',
        $dir . '/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json',
        1,
    ];
}

function er_get_base_row(PDO $dbo): ?array {
    $stmt = $dbo->prepare("
        SELECT *
        FROM user_picks
        WHERE raceYear = '2026'
          AND segment = 'S1'
          AND teamName = 'Be Like Biff'
          AND pick_type IN ('SEG','ADJ')
        ORDER BY pickID ASC
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function er_get_fixture_lp_row(PDO $dbo): ?array {
    $stmt = $dbo->prepare("
        SELECT *
        FROM user_picks
        WHERE raceYear = '2026'
          AND segment = 'S1'
          AND teamName = 'Be Like Biff'
          AND pick_type = 'LP'
          AND submission_id = 'test_lp_rp_edge_20260823_114100am'
        ORDER BY pickID DESC
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function er_update_row_by_pickid(PDO $dbo, array $row): void {
    if (!isset($row['pickID'])) {
        throw new RuntimeException('Database snapshot has no pickID.');
    }

    $pickID = (int)$row['pickID'];
    if ($pickID <= 0) {
        throw new RuntimeException('Database snapshot has invalid pickID.');
    }

    $sets = [];
    $params = [':pickID' => $pickID];
    $i = 0;

    foreach ($row as $col => $value) {
        if ($col === 'pickID') continue;
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$col)) {
            throw new RuntimeException('Unexpected DB column name: ' . (string)$col);
        }

        $ph = ':p' . $i++;
        $sets[] = '`' . $col . '` = ' . $ph;
        $params[$ph] = $value;
    }

    if (empty($sets)) {
        throw new RuntimeException('No DB columns available to restore.');
    }

    $sql = 'UPDATE user_picks SET ' . implode(', ', $sets) . ' WHERE pickID = :pickID';
    $stmt = $dbo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() < 0) {
        throw new RuntimeException('Database restore did not complete.');
    }
}

function er_convert_base_to_lp(PDO $dbo, array $base): array {
    if (!isset($base['pickID'])) {
        throw new RuntimeException('Base pick row has no pickID.');
    }

    $row = $base;

    $row['driverB'] = 'AJ Allmendinger';
    $row['pick_type'] = 'LP';
    $row['effective_race'] = 6;
    $row['supersedes_pickID'] = null;
    $row['entryDate'] = '2026-03-29 12:00:00';
    $row['submission_id'] = 'test_lp_rp_edge_20260823_114100am';

    if (array_key_exists('formID', $row)) {
        $row['formID'] = 'form-team-picks.php';
    }
    if (array_key_exists('form_version', $row)) {
        $row['form_version'] = 'LP-RP-EDGE-v001';
    }

    er_update_row_by_pickid($dbo, $row);
    return $row;
}

function er_synthetic_points(): array {
    $out = [];

    for ($r = 1; $r <= 7; $r++) {
        $out[$r] = [
            'Chase Elliott' => 10,
            'AJ Allmendinger' => 10,
            'Ryan Blaney' => 10,
            'Brad Keselowski' => 10,
        ];
    }

    $out[6]['AJ Allmendinger'] = 0;
    $out[7]['AJ Allmendinger'] = 0;

    return $out;
}

function er_normalize_qualifier(array $detect): ?array {
    $qualifiers = isset($detect['qualifiers']) && is_array($detect['qualifiers'])
        ? array_values($detect['qualifiers'])
        : [];

    if (count($qualifiers) !== 1 || !is_array($qualifiers[0])) {
        return null;
    }

    $q = $qualifiers[0];
    $slot = strtoupper(trim((string)($q['slot'] ?? '')));
    $driver = trim((string)($q['driver'] ?? ''));
    $effective = $q['effective_race'] ?? ($detect['effective_race'] ?? null);

    if (is_string($effective) && preg_match('/^R?(\d+)$/i', $effective, $m)) {
        $effective = (int)$m[1];
    } else {
        $effective = (int)$effective;
    }

    $zr = [];
    if (isset($q['zero_races']) && is_array($q['zero_races'])) {
        foreach ($q['zero_races'] as $v) {
            $n = (int)$v;
            if ($n > 0) $zr[] = $n;
        }
    }
    if (empty($zr) && isset($q['trigger_races']) && is_array($q['trigger_races'])) {
        foreach ($q['trigger_races'] as $v) {
            if (preg_match('/^R?(\d+)$/i', (string)$v, $m)) {
                $zr[] = (int)$m[1];
            }
        }
    }

    return [
        'slot' => $slot,
        'driver' => $driver,
        'effective_race' => $effective,
        'zero_races' => $zr,
    ];
}

function er_fixture_exact(string $path): bool {
    if (!is_file($path)) return false;
    $raw = @file_get_contents($path);
    if ($raw === false) return false;
    $p = json_decode($raw, true);
    if (!is_array($p)) return false;

    return !empty($p['test_fixture'])
        && (string)($p['fixture_id'] ?? '') === 'BE_LIKE_BIFF_LP_AJ_R06_R07'
        && (string)($p['teamName'] ?? '') === 'Be Like Biff'
        && (string)($p['segment'] ?? '') === 'S1'
        && (string)($p['effective_race'] ?? '') === 'R08'
        && (int)($p['qualifier_count'] ?? 0) === 1
        && (string)($p['qualifiers'][0]['slot'] ?? '') === 'B'
        && (string)($p['qualifiers'][0]['driver'] ?? '') === 'AJ Allmendinger';
}

list($raceDir, $fixturePath, $markerPath, $r07Count) = er_find_r07($root);

$errors = [];
$messages = [];
$checks = [];
$detectPreview = null;
$action = (string)($_POST['action'] ?? '');

if (!er_is_testphp8_host()) {
    $errors[] = 'REFUSED: this harness may run only on testphp8.manliusracingleague.com.';
}

foreach ([$teamPath, $submitPath, $wrapperPath, $helperPath] as $p) {
    if (!is_file($p)) {
        $errors[] = 'Missing required file: ' . $p;
    }
}

if ($r07Count !== 1) {
    $errors[] = 'Expected exactly one race_results/2026/R07_* directory; found ' . $r07Count . '.';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

if (!isset($dbo) || !($dbo instanceof PDO)) {
    $errors[] = 'PDO database connection $dbo is unavailable.';
}

if (empty($errors)) {
    require_once $helperPath;
}

$teamSrc = is_file($teamPath) ? er_read($teamPath) : '';
$submitSrc = is_file($submitPath) ? er_read($submitPath) : '';
$wrapperSrc = is_file($wrapperPath) ? er_read($wrapperPath) : '';
$helperSrc = is_file($helperPath) ? er_read($helperPath) : '';

$baselineState =
    strpos($teamSrc, 'VERSION: v024') !== false
    && strpos($submitSrc, 'VERSION: v007') !== false
    && strpos($wrapperSrc, 'VERSION: v010') !== false
    && strpos($helperSrc, 'VERSION: v005') !== false
    && strpos($teamSrc, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') === false
    && strpos($submitSrc, 'mrl_lp_rp_edge_fixture_allowed') === false;

$installedState =
    strpos($teamSrc, 'VERSION: v025') !== false
    && strpos($submitSrc, 'VERSION: v008') !== false
    && strpos($teamSrc, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') !== false
    && strpos($submitSrc, 'mrl_lp_rp_edge_fixture_allowed') !== false;

$baseRow = null;
$lpRow = null;

if (isset($dbo) && $dbo instanceof PDO) {
    $baseRow = er_get_base_row($dbo);
    $lpRow = er_get_fixture_lp_row($dbo);
}

$fixtureExists = ($fixturePath !== '' && is_file($fixturePath));
$markerExists = ($markerPath !== '' && is_file($markerPath));

if ($baselineState) {
    if (!is_array($baseRow)) {
        $errors[] = 'Clean baseline Be Like Biff 2026 S1 SEG/ADJ row was not found.';
    }

    if (is_array($lpRow)) {
        $errors[] = 'Owned LP fixture row already exists while app files appear baseline.';
    }

    if ($fixtureExists) {
        $errors[] = 'A Be Like Biff pending RP JSON already exists. Refusing to overwrite it.';
    }

    if ($markerExists) {
        $errors[] = 'LP→RP edge-case marker already exists.';
    }
}

if (!$baselineState && !$installedState) {
    $errors[] = 'App files are neither clean baseline v024/v007 nor this harness installed state.';
}

$teamRealGate = <<<'PHP'
            if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp) {
                $showRdWrapper = false;
            }
PHP;

$teamTempGate = <<<'PHP'
            // MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE
            // TESTPHP8-only temporary hook for the exact LP→RP edge-case fixture.
            $rdDeadlineNowTimestamp = time();
            $rdTestTimeOverrideActive = false;
            $rdTestTimeOverrideTimestamp = 0;

            $rdFixtureMarker = dirname((string)($rdPendingInfo['jsonPath'] ?? ''))
                . '/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json';

            $rdFixturePayloadIsExact =
                (string)($rdPendingPayload['teamName'] ?? '') === 'Be Like Biff'
                && (string)($rdPendingPayload['segment'] ?? '') === 'S1'
                && (string)($rdPendingPayload['effective_race'] ?? '') === 'R08'
                && !empty($rdPendingPayload['test_fixture'])
                && (string)($rdPendingPayload['fixture_id'] ?? '') === 'BE_LIKE_BIFF_LP_AJ_R06_R07'
                && (int)($rdPendingPayload['qualifier_count'] ?? 0) === 1
                && (string)($rdPendingPayload['qualifiers'][0]['driver'] ?? '') === 'AJ Allmendinger'
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

$teamRealInclude = <<<'PHP'
                    if ($showRdWrapper) {
                        include 'team_replacement_driver.php';
PHP;

$teamTempInclude = <<<'PHP'
                    if ($showRdWrapper) {
                        if (!empty($rdTestTimeOverrideActive) && !empty($rdTestTimeOverrideTimestamp)) {
                            echo "<div style='width:96%;margin:8px auto;padding:10px;background:#5a3100;border:2px solid #ffb13b;color:#fff3d0;text-align:center;font-weight:bold;font-size:15px;'>"
                                . "TEST TIME OVERRIDE ACTIVE — LP→RP edge test is pretending now is "
                                . teampage_h(date('n/j/Y g:i:s a', (int)$rdTestTimeOverrideTimestamp))
                                . " ET (1 hour before R08). REAL CLOCK / SCHEDULE DATA ARE UNCHANGED."
                                . "</div>";
                        }
                        include 'team_replacement_driver.php';
PHP;

$submitHelper = <<<'PHP'
function mrl_lp_rp_edge_fixture_allowed(string $raceYear, string $teamName, string $segment, int $effectiveRace): bool
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
        ? glob($baseDir . '/R07_*/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json')
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
        && (string)($payload['fixture_id'] ?? '') === 'BE_LIKE_BIFF_LP_AJ_R06_R07'
        && (string)($payload['teamName'] ?? '') === 'Be Like Biff'
        && (string)($payload['segment'] ?? '') === 'S1'
        && (string)($payload['effective_race'] ?? '') === 'R08'
        && (int)($payload['qualifier_count'] ?? 0) === 1
        && (string)($payload['qualifiers'][0]['slot'] ?? '') === 'B'
        && (string)($payload['qualifiers'][0]['driver'] ?? '') === 'AJ Allmendinger';
}


PHP;

$submitRealGate = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        mrl_rd_reject();
    }
PHP;

$submitTempGate = <<<'PHP'
    // Deadline protection belongs on the server too, not just on team.php.
    $rdDeadlineOpen = mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace);

    if (
        !$rdDeadlineOpen
        && mrl_lp_rp_edge_fixture_allowed(
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

$preparedTeam = '';
$preparedSubmit = '';

if (empty($errors) && $baselineState) {
    try {
        if (strpos($teamSrc, $teamRealGate) === false) {
            throw new RuntimeException('team.php real RD deadline gate marker not found.');
        }
        if (strpos($teamSrc, $teamRealInclude) === false) {
            throw new RuntimeException('team.php RD wrapper include marker not found.');
        }
        if (strpos($submitSrc, "function mrl_rd_reject(): void\n{") === false) {
            throw new RuntimeException('submit-team-picks.php RD reject marker not found.');
        }
        if (strpos($submitSrc, $submitRealGate) === false) {
            throw new RuntimeException('submit-team-picks.php real RD deadline gate marker not found.');
        }

        $preparedTeam = er_replace_once($teamSrc, 'VERSION: v024', 'VERSION: v025', 'team version');
        $preparedSubmit = er_replace_once($submitSrc, 'VERSION: v007', 'VERSION: v008', 'submit version');

        $preparedTeam = er_replace_once($preparedTeam, $teamRealGate, $teamTempGate, 'team deadline gate');
        $preparedTeam = er_replace_once($preparedTeam, $teamRealInclude, $teamTempInclude, 'team visible banner');

        $preparedSubmit = er_replace_once(
            $preparedSubmit,
            "function mrl_rd_reject(): void\n{",
            $submitHelper . "function mrl_rd_reject(): void\n{",
            'submit fixture helper'
        );
        $preparedSubmit = er_replace_once(
            $preparedSubmit,
            $submitRealGate,
            $submitTempGate,
            'submit deadline gate'
        );
    } catch (Throwable $e) {
        $errors[] = 'Preparation failed: ' . $e->getMessage();
    }
}

if ($action === 'install' && empty($errors) && $baselineState) {
    $dbChanged = false;
    $filesChanged = false;

    try {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Could not create backup directory.');
        }

        if (!@copy($teamPath, $teamBackup)) {
            throw new RuntimeException('Could not back up team.php.');
        }
        if (!@copy($submitPath, $submitBackup)) {
            throw new RuntimeException('Could not back up submit-team-picks.php.');
        }

        if (!is_array($baseRow)) {
            throw new RuntimeException('Base S1 row disappeared before install.');
        }

        if (!er_write_json($dbSnapshotPath, $baseRow)) {
            throw new RuntimeException('Could not save original S1 DB row snapshot.');
        }

        $converted = er_convert_base_to_lp($dbo, $baseRow);
        $dbChanged = true;

        $lpCheck = er_get_fixture_lp_row($dbo);
        if (!is_array($lpCheck)) {
            throw new RuntimeException('Temporary LP row did not verify after DB conversion.');
        }

        $points = er_synthetic_points();

        $detect = mrl_rd_detect_team_segment_eligibility(
            $dbo,
            '2026',
            'S1',
            'Be Like Biff',
            $points
        );

        if (!is_array($detect)) {
            throw new RuntimeException('Shared RD helper returned no result array.');
        }

        $detectPreview = $detect;
        $status = strtoupper(trim((string)($detect['status'] ?? '')));
        $q = er_normalize_qualifier($detect);

        if ($status !== 'RD_AVAILABLE') {
            throw new RuntimeException('Shared helper did not return RD_AVAILABLE; got ' . ($status === '' ? '(blank)' : $status) . '.');
        }
        if (!is_array($q)) {
            throw new RuntimeException('Shared helper did not return exactly one qualifier.');
        }
        if ($q['slot'] !== 'B' || $q['driver'] !== 'AJ Allmendinger' || (int)$q['effective_race'] !== 8) {
            throw new RuntimeException(
                'Shared helper qualifier mismatch: expected Group B AJ Allmendinger effective R08.'
            );
        }

        $triggerRaces = ['R06', 'R07'];

        $payload = [
            'userID' => (int)($lpCheck['userID'] ?? 0),
            'teamName' => 'Be Like Biff',
            'segment' => 'S1',
            'status' => 'RD_AVAILABLE',
            'qualifier_count' => 1,
            'qualifiers' => [
                [
                    'slot' => 'B',
                    'driver' => 'AJ Allmendinger',
                    'trigger_races' => $triggerRaces,
                    'effective_race' => 'R08',
                    'source_pick_type' => 'LP',
                    'source_pick_id' => (int)($lpCheck['pickID'] ?? 0),
                ],
            ],
            'slot' => 'B',
            'driver' => 'AJ Allmendinger',
            'trigger_races' => $triggerRaces,
            'effective_race' => 'R08',
            'detected_at' => date('Y-m-d\TH:i:s'),
            'test_fixture' => true,
            'fixture_id' => 'BE_LIKE_BIFF_LP_AJ_R06_R07',
            'helper_verified' => true,
            'helper_status' => $status,
            'source_pick_type' => 'LP',
            'source_pick_id' => (int)($lpCheck['pickID'] ?? 0),
        ];

        if (!er_write_json($fixturePath, $payload)) {
            throw new RuntimeException('Could not create LP→RP pending fixture JSON.');
        }

        $marker = [
            'fixture_id' => 'BE_LIKE_BIFF_LP_AJ_R06_R07',
            'target' => $fixturePath,
            'created_at' => date('Y-m-d H:i:s'),
            'db_snapshot' => $dbSnapshotPath,
        ];

        if (!er_write_json($markerPath, $marker)) {
            throw new RuntimeException('Could not create LP→RP fixture marker.');
        }

        er_write($teamPath, $preparedTeam);
        er_write($submitPath, $preparedSubmit);
        $filesChanged = true;

        $teamAfter = er_read($teamPath);
        $submitAfter = er_read($submitPath);
        $lpAfter = er_get_fixture_lp_row($dbo);

        $postflight = [
            strpos($teamAfter, 'VERSION: v025') !== false,
            strpos($teamAfter, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') !== false,
            strpos($submitAfter, 'VERSION: v008') !== false,
            strpos($submitAfter, 'mrl_lp_rp_edge_fixture_allowed') !== false,
            er_fixture_exact($fixturePath),
            is_file($markerPath),
            is_array($lpAfter),
            is_array($lpAfter) && (string)($lpAfter['driverB'] ?? '') === 'AJ Allmendinger',
            is_array($lpAfter) && (string)($lpAfter['pick_type'] ?? '') === 'LP',
            is_array($lpAfter) && (int)($lpAfter['effective_race'] ?? 0) === 6,
        ];

        foreach ($postflight as $ok) {
            if (!$ok) {
                throw new RuntimeException('Postflight verification failed.');
            }
        }

        $messages[] = 'LP → RP EDGE-CASE TEST MODE INSTALLED successfully.';
        $messages[] = 'Shared RD helper independently detected Group B — AJ Allmendinger as the single R08 RP qualifier.';
        $messages[] = 'The temporary S1 database row is now a genuine LP row effective R06.';
    } catch (Throwable $e) {
        if ($filesChanged) {
            if (is_file($teamBackup)) @copy($teamBackup, $teamPath);
            if (is_file($submitBackup)) @copy($submitBackup, $submitPath);
        }

        if ($fixturePath !== '' && is_file($fixturePath)) @unlink($fixturePath);
        if ($markerPath !== '' && is_file($markerPath)) @unlink($markerPath);

        if ($dbChanged && is_file($dbSnapshotPath)) {
            $raw = @file_get_contents($dbSnapshotPath);
            $snap = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($snap)) {
                try {
                    er_update_row_by_pickid($dbo, $snap);
                } catch (Throwable $ignored) {
                    $errors[] = 'WARNING: automatic DB rollback also encountered an error.';
                }
            }
        }

        $errors[] = 'INSTALL FAILED: ' . $e->getMessage();
        $errors[] = 'Automatic rollback was attempted.';
    }
}

if ($action === 'remove_files' && empty($errors) && $installedState) {
    try {
        if (!is_file($teamBackup) || !is_file($submitBackup)) {
            throw new RuntimeException('Original app-file backups are missing. Refusing cleanup.');
        }

        if (!@copy($teamBackup, $teamPath)) {
            throw new RuntimeException('Could not restore team.php.');
        }
        if (!@copy($submitBackup, $submitPath)) {
            throw new RuntimeException('Could not restore submit-team-picks.php.');
        }

        if ($fixturePath !== '' && is_file($fixturePath)) @unlink($fixturePath);
        if ($markerPath !== '' && is_file($markerPath)) @unlink($markerPath);

        $teamAfter = er_read($teamPath);
        $submitAfter = er_read($submitPath);

        if (strpos($teamAfter, 'VERSION: v024') === false) {
            throw new RuntimeException('team.php did not restore to v024.');
        }
        if (strpos($submitAfter, 'VERSION: v007') === false) {
            throw new RuntimeException('submit-team-picks.php did not restore to v007.');
        }

        $messages[] = 'LP → RP temporary APP FILES / JSON fixture removed successfully.';
        $messages[] = 'DATABASE HAS NOT BEEN RESTORED by this cleanup action. Restore clean_baseline before returning the scheduler to normal.';
    } catch (Throwable $e) {
        $errors[] = 'CLEANUP FAILED: ' . $e->getMessage();
    }
}

$teamNow = is_file($teamPath) ? er_read($teamPath) : '';
$submitNow = is_file($submitPath) ? er_read($submitPath) : '';
$installedNow =
    strpos($teamNow, 'VERSION: v025') !== false
    && strpos($submitNow, 'VERSION: v008') !== false
    && strpos($teamNow, 'MRL_LP_RP_EDGE_TIME_TRAVEL_FIXTURE') !== false
    && strpos($submitNow, 'mrl_lp_rp_edge_fixture_allowed') !== false;

$lpNow = (isset($dbo) && $dbo instanceof PDO) ? er_get_fixture_lp_row($dbo) : null;

$checks = [
    ['TESTPHP8 host', er_is_testphp8_host(), (string)($_SERVER['HTTP_HOST'] ?? '')],
    ['Exactly one 2026 R07 race folder', $r07Count === 1, $raceDir !== '' ? basename($raceDir) : 'found ' . $r07Count],
    ['wrapper remains v010', strpos($wrapperSrc, 'VERSION: v010') !== false, 'team_replacement_driver.php'],
    ['helper remains v005', strpos($helperSrc, 'VERSION: v005') !== false, 'race_results_rd_helper.php'],
    ['Clean baseline OR installed harness state recognized', $baselineState || $installedNow, $installedNow ? 'INSTALLED' : ($baselineState ? 'CLEAN BASELINE' : 'UNKNOWN')],
    ['Temporary LP fixture row', is_array($lpNow) || $baselineState, is_array($lpNow) ? 'LP / AJ Allmendinger / effective R06' : 'not installed yet'],
    ['Pending LP→RP fixture JSON', ($installedNow && er_fixture_exact($fixturePath)) || $baselineState, ($installedNow && is_file($fixturePath)) ? basename($fixturePath) : 'not installed yet'],
    ['LP→RP fixture marker', ($installedNow && is_file($markerPath)) || $baselineState, ($installedNow && is_file($markerPath)) ? basename($markerPath) : 'not installed yet'],
];

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL LP → RP Edge-Case Test Harness <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:15px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1220px;margin:0 auto;padding:14px}
.header{background:#18301f;border:1px solid #4f8b60;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#6dff9f;font-size:23px}
.sub{margin-top:3px;color:#ddd}
.card{background:#1b1b1b;border:1px solid #444;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#ffc44d;font-size:19px}
table{width:100%;border-collapse:collapse}
td{padding:7px 8px;border-bottom:1px solid #343434;vertical-align:top}
.ok{color:#65ef98;font-weight:bold}
.bad{color:#ff7878;font-weight:bold}
.warn{color:#ffd269;font-weight:bold}
.info{color:#b9dfff}
.msg{background:#173d28;border:1px solid #3d955d;border-radius:8px;padding:9px 11px;margin-top:9px}
.err{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:9px 11px;margin-top:9px}
button{font-size:17px;font-weight:bold;padding:10px 15px;border-radius:7px;cursor:pointer}
.install{background:#27643a;color:#fff;border:1px solid #5ab778}
.remove{background:#7a3c17;color:#fff;border:1px solid #c57938}
.callout{background:#4a2b00;border:2px solid #dc9425;padding:10px 12px;text-align:center;font-weight:bold;margin:9px 0}
code{color:#f2dc9c}
.small{color:#bbb;font-size:13px}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
<h1>MRL LP → RP EDGE-CASE TEST HARNESS v001</h1>
<div class="sub">TESTPHP8 ONLY • Be Like Biff • genuine LP baseline • shared-helper RP detection • R08 controlled test</div>
</div>

<?php foreach ($messages as $m): ?>
<div class="msg"><?=er_h($m)?></div>
<?php endforeach; ?>

<?php foreach ($errors as $e): ?>
<div class="err"><?=er_h($e)?></div>
<?php endforeach; ?>

<div class="card">
<h2>Test Case</h2>
<table>
<tr><td>Team</td><td>Be Like Biff</td></tr>
<tr><td>Segment</td><td>S1</td></tr>
<tr><td>Temporary LP driver</td><td><strong>Group B — AJ Allmendinger</strong></td></tr>
<tr><td>LP effective race</td><td>R06</td></tr>
<tr><td>Synthetic completed-race trigger</td><td>AJ Allmendinger: R06 = 0, R07 = 0</td></tr>
<tr><td>Expected RP effective race</td><td>R08</td></tr>
<tr><td>Artificial browser time</td><td>4/12/2026 2:00 pm ET — 1 hour before R08</td></tr>
</table>
</div>

<div class="card">
<h2>Preflight / Current State</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:53%"><?=er_h($c[0])?></td>
<td style="width:10%" class="<?=$c[1]?'ok':'bad'?>"><?=$c[1]?'PASS':'FAIL'?></td>
<td><?=er_h($c[2])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if ($baselineState && empty($errors)): ?>
<div class="card">
<h2>Ready to Install</h2>
<div class="callout">
DATABASE WARNING — this test temporarily converts the Be Like Biff 2026 S1 test row into an LP row.
You will restore the TESTPHP8 database from <strong>clean_baseline</strong> after the edge-case test.
</div>
<p>This installer will not create the pending RP fixture unless the existing <strong>v005 shared helper itself</strong> first proves that the active LP driver AJ Allmendinger qualifies from R06/R07 and that the next effective race is R08.</p>
<form method="post">
<input type="hidden" name="action" value="install">
<button class="install" type="submit">INSTALL LP → RP EDGE-CASE TEST</button>
</form>
</div>
<?php endif; ?>

<?php if ($installedNow): ?>
<div class="card">
<h2 class="ok">LP → RP TEST MODE IS INSTALLED</h2>
<table>
<tr><td>Temporary DB row</td><td class="ok">LP — AJ Allmendinger — effective R06</td></tr>
<tr><td>RP qualifier fixture</td><td class="ok">Group B — AJ Allmendinger — R06/R07 — effective R08</td></tr>
<tr><td>team.php</td><td>temporary v025</td></tr>
<tr><td>submit-team-picks.php</td><td>temporary v008</td></tr>
<tr><td>wrapper/helper</td><td>v010 / v005 unchanged</td></tr>
</table>

<div class="callout">
NEXT: Ctrl+F5 normal TESTPHP8 /team.php.<br>
Do NOT submit anything yet.<br>
First verify that the page shows AJ Allmendinger as the active LP driver AND that the RP form names AJ Allmendinger as the explicit Group B qualifier.
</div>

<p class="warn">Keep the scheduler OFF.</p>

<form method="post" onsubmit="return confirm('Remove only the temporary app-file / JSON test mode now? The database will still require clean_baseline restore.');">
<input type="hidden" name="action" value="remove_files">
<button class="remove" type="submit">REMOVE LP → RP APP TEST MODE</button>
</form>
</div>
<?php endif; ?>

<div class="card">
<h2>What Counts as the First PASS</h2>
<table>
<tr><td>1</td><td>The normal team chart recognizes the temporary S1 row as <strong>LP</strong> with AJ Allmendinger active from R06.</td></tr>
<tr><td>2</td><td>The public RP form appears under the controlled pre-R08 clock.</td></tr>
<tr><td>3</td><td>The only explicit RP qualifier is <strong>Group B — AJ Allmendinger (R06, R07)</strong>.</td></tr>
<tr><td>4</td><td>No Denny Hamlin RP qualifier appears. Denny is the old baseline driver and must not drive this RP.</td></tr>
</table>
</div>

<div class="card small">
Backup folder: <code><?=er_h($backupDir)?></code><br>
DB snapshot: <code><?=er_h($dbSnapshotPath)?></code><br>
Final reset after the full edge-case test: remove app test mode → restore TESTPHP8 DB from clean_baseline → verify normal pages → scheduler ON → WinSCP server→local → commit/push GitHub when appropriate.
</div>

</div>
</body>
</html>
