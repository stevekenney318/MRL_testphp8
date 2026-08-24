<?php
declare(strict_types=1);

/**
 * MRL Replacement Pick Real-Flow Integration Installer
 *
 * VERSION: v001
 * GENERATED: 8/22/2026 7:26:00 pm America/New_York
 *
 * TARGET: TESTPHP8 ONLY
 *
 * FILES:
 *   /public_html/race_results/race_results_monitor.php   v138 -> v139
 *   /public_html/team.php                               v023 -> v024
 *   /public_html/team_replacement_driver.php            v008 -> v009
 *   /public_html/submit-team-picks.php                  v006 -> v007
 *
 * PURPOSE:
 *   Connect the already-proven multi-qualifier RD helper behavior to the
 *   real TESTPHP8 user flow:
 *
 *     monitor -> pending JSON with ALL qualifiers -> team.php ->
 *     explicit required user choice -> replacement driver -> server validation
 *
 * USER-FACING WORDING:
 *   "Replacement Pick"
 *
 * INTERNAL COMPATIBILITY:
 *   Existing RD identifiers and pick_type='RD' remain unchanged.
 *
 * SAFETY:
 *   - TESTPHP8 host only
 *   - PHP 7.3 compatible
 *   - NO database schema changes
 *   - NO database writes by this installer
 *   - race_results_rd_helper.php is checked but NOT modified
 *   - scheduler should remain OFF during install/test
 *   - full backup of all four changed files
 *   - semantic postflight
 *   - all-file rollback on any postflight failure
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$files = [
    'monitor' => $root . '/race_results/race_results_monitor.php',
    'team'    => $root . '/team.php',
    'wrapper' => $root . '/team_replacement_driver.php',
    'submit'  => $root . '/submit-team-picks.php',
    'helper'  => $root . '/race_results/race_results_rd_helper.php',
];

$backupDir = $root . '/mrl_rd_real_flow_integration_backup_20260822_072600pm';

$checks = [];
$errors = [];
$postflight = [];
$prepared = [];
$installed = false;

function rfi_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rfi_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail];
}

function rfi_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 source marker, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}

function rfi_regex_replace_once(string $src, string $pattern, string $replacement, string $label): string
{
    $result = preg_replace($pattern, $replacement, $src, -1, $count);
    if ($result === null || $count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 regex replacement, got ' . (int)$count . '.');
    }
    return $result;
}

function rfi_atomic_write(string $path, string $content): bool
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

function rfi_restore_all(array $files, string $backupDir): void
{
    $map = [
        'monitor' => 'race_results_monitor.php',
        'team' => 'team.php',
        'wrapper' => 'team_replacement_driver.php',
        'submit' => 'submit-team-picks.php',
    ];

    foreach ($map as $key => $base) {
        if (isset($files[$key]) && is_file($backupDir . '/' . $base)) {
            @copy($backupDir . '/' . $base, $files[$key]);
        }
    }
}

// -----------------------------------------------------------------------------
// PREFLIGHT
// -----------------------------------------------------------------------------

rfi_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host);
rfi_check($checks, 'PHP runtime is 7.3+', PHP_VERSION_ID >= 70300, PHP_VERSION);

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: This installer is TESTPHP8-only.';
}
if (PHP_VERSION_ID < 70300) {
    $errors[] = 'PHP 7.3 or newer is required.';
}

$current = [];
foreach ($files as $key => $path) {
    $exists = is_file($path);
    rfi_check($checks, $key . ' file exists', $exists, $path);
    if (!$exists) {
        $errors[] = 'Missing required file: ' . $path;
        continue;
    }
    $current[$key] = (string)@file_get_contents($path);
}

if (empty($errors)) {
    $baseline = [
        'Monitor baseline v138' => strpos($current['monitor'], 'VERSION: v138') !== false,
        'Monitor still has legacy MANUAL_SELECTION_REQUIRED gate' =>
            strpos($current['monitor'], "status === 'MANUAL_SELECTION_REQUIRED'") !== false,
        'Monitor pending payload still singular-first-qualifier' =>
            strpos($current['monitor'], "\$qualifier = \$eligibility['qualifiers'][0];") !== false,
        'Team baseline v023' => strpos($current['team'], 'VERSION: v023') !== false,
        'Team still has singular rdPendingGroup' => strpos($current['team'], '$rdPendingGroup =') !== false,
        'Wrapper baseline v008' => strpos($current['wrapper'], 'VERSION: v008') !== false,
        'Wrapper still says Replacement Driver available for Group' =>
            strpos($current['wrapper'], 'Replacement Driver available for Group') !== false,
        'Submit baseline v006' => strpos($current['submit'], 'VERSION: v006') !== false,
        'Submit has known RD POST-order baseline' =>
            strpos($current['submit'], "\$activeSegment = (\$pickTypeOverride === 'RD'") !== false,
        'Shared helper baseline v005' => strpos($current['helper'], 'VERSION: v005') !== false,
        'Shared helper supports MULTIPLE_RD_AVAILABLE' =>
            strpos($current['helper'], 'MULTIPLE_RD_AVAILABLE') !== false,
        'Shared helper supports user_selection_required' =>
            strpos($current['helper'], 'user_selection_required') !== false,
    ];

    $baselineOk = true;
    foreach ($baseline as $label => $ok) {
        rfi_check($checks, $label, $ok, $ok ? 'PASS' : 'FAIL');
        if (!$ok) $baselineOk = false;
    }

    if (!$baselineOk) {
        $errors[] = 'REFUSED: One or more files do not match the expected current TESTPHP8 baseline.';
    }
}

// -----------------------------------------------------------------------------
// PREPARE MONITOR v139
// -----------------------------------------------------------------------------

if (empty($errors)) {
    try {
        $s = $current['monitor'];

        $s = rfi_replace_once($s, 'VERSION: v138', 'VERSION: v139', 'Monitor version');
        $s = rfi_replace_once(
            $s,
            'LAST MODIFIED: 7/19/2026 1:35:18 pm',
            'LAST MODIFIED: 8/22/2026 7:26:00 pm',
            'Monitor modified time'
        );

        $s = rfi_replace_once(
            $s,
            " * CHANGELOG:\n",
            " * CHANGELOG:\n"
            . " *\n"
            . " * v139 (8/22/2026 7:26:00 pm)\n"
            . " *   - CHANGE: Real RD detection now uses shared race_results_rd_helper.php v005 eligibility.\n"
            . " *   - NEW: MULTIPLE_RD_AVAILABLE is treated as an automatic user-choice opportunity.\n"
            . " *   - NEW: Pending JSON stores every qualifying slot/driver plus legacy singular fields.\n"
            . " *   - CHANGE: RD email lists every qualifier when more than one driver qualifies.\n"
            . " *   - PRESERVE: Existing scheduler ownership, snapshots, scoring, and notification cadence.\n",
            'Monitor changelog'
        );

        $oldCall = <<<'PHP'
        $eligibility = rr_monitor_detect_team_rd_eligibility_completed_only(
            $dbo,
            $year,
            $segment,
            $teamRow,
            $raceDriverPoints
        );
PHP;
        $newCall = <<<'PHP'
        // v139: use the shared, already-proven trailing-pair/multi-qualifier engine.
        $eligibility = mrl_rd_detect_team_segment_eligibility(
            $dbo,
            (string)$year,
            $segment,
            $teamName,
            $raceDriverPoints
        );
PHP;
        $s = rfi_replace_once($s, $oldCall, $newCall, 'Monitor shared-helper call');

        $oldGate = <<<'PHP'
        $status = (string)($eligibility['status'] ?? '');
        if ($status !== 'RD_AVAILABLE') {
            if ($status === 'MANUAL_SELECTION_REQUIRED') {
                $manualRequiredCount++;
                $manualTeams[] = $teamName;
                rr_log_line($rdLogFile, 'RD MANUAL SELECTION REQUIRED team=' . $teamName . ' segment=' . $segment);
            }
            continue;
        }

        $eligibleCount++;
PHP;
        $newGate = <<<'PHP'
        $status = (string)($eligibility['status'] ?? '');
        if ($status !== 'RD_AVAILABLE' && $status !== 'MULTIPLE_RD_AVAILABLE') {
            continue;
        }

        $eligibleCount++;
        if ($status === 'MULTIPLE_RD_AVAILABLE') {
            rr_log_line(
                $rdLogFile,
                'RD MULTIPLE USER CHOICE team=' . $teamName
                . ' segment=' . $segment
                . ' qualifiers=' . (int)($eligibility['qualifier_count'] ?? 0)
            );
        }
PHP;
        $s = rfi_replace_once($s, $oldGate, $newGate, 'Monitor multi-status gate');

        $payloadFn = <<<'PHP'
function rr_monitor_rd_payload(array $eligibility): array
{
    $base = isset($eligibility['base_pick_row']) && is_array($eligibility['base_pick_row'])
        ? $eligibility['base_pick_row']
        : [];

    $rawQualifiers = isset($eligibility['qualifiers']) && is_array($eligibility['qualifiers'])
        ? $eligibility['qualifiers']
        : [];

    $qualifiers = [];

    foreach ($rawQualifiers as $qualifier) {
        if (!is_array($qualifier)) {
            continue;
        }

        $slot = strtoupper(trim((string)($qualifier['slot'] ?? '')));
        $driver = trim((string)($qualifier['driver'] ?? ''));
        if (!in_array($slot, ['A', 'B', 'C', 'D'], true) || $driver === '') {
            continue;
        }

        $triggerCodes = [];
        if (isset($qualifier['zero_races']) && is_array($qualifier['zero_races'])) {
            foreach ($qualifier['zero_races'] as $zeroRace) {
                $triggerCodes[] = 'R' . str_pad((string)((int)$zeroRace), 2, '0', STR_PAD_LEFT);
            }
        }

        $effectiveRace = (int)($qualifier['effective_race'] ?? 0);
        $effectiveRaceCode = $effectiveRace > 0
            ? 'R' . str_pad((string)$effectiveRace, 2, '0', STR_PAD_LEFT)
            : '';

        $qualifiers[] = [
            'slot' => $slot,
            'driver' => $driver,
            'trigger_races' => $triggerCodes,
            'effective_race' => $effectiveRaceCode,
        ];
    }

    $first = !empty($qualifiers) ? $qualifiers[0] : [
        'slot' => '',
        'driver' => '',
        'trigger_races' => [],
        'effective_race' => '',
    ];

    return [
        'userID' => (int)($base['userID'] ?? 0),
        'teamName' => (string)($eligibility['teamName'] ?? ''),
        'segment' => (string)($eligibility['segment'] ?? ''),
        'status' => (string)($eligibility['status'] ?? ''),
        'qualifier_count' => count($qualifiers),
        'qualifiers' => $qualifiers,

        // Backward-compatible singular fields for older readers/tools.
        'slot' => (string)($first['slot'] ?? ''),
        'driver' => (string)($first['driver'] ?? ''),
        'trigger_races' => isset($first['trigger_races']) && is_array($first['trigger_races'])
            ? $first['trigger_races']
            : [],
        'effective_race' => (string)($first['effective_race'] ?? ''),

        'detected_at' => date('Y-m-d\TH:i:s'),
    ];
}

PHP;

        $s = rfi_regex_replace_once(
            $s,
            '~function rr_monitor_rd_payload\(array \$eligibility\): array\s*\{.*?\n\}\n\n(?=function rr_monitor_write_rd_pending_json)~s',
            $payloadFn,
            'Monitor pending payload function'
        );

        $emailFn = <<<'PHP'
function rr_monitor_send_rd_email($user_home, string $notifyEmail, string $subjectPrefix, array $payload, string $jsonPath, string $publicHost, string $raceFolderName, int $year): bool
{
    $teamName = (string)($payload['teamName'] ?? '');
    $segment = (string)($payload['segment'] ?? '');
    $effectiveRace = (string)($payload['effective_race'] ?? '');
    $qualifiers = isset($payload['qualifiers']) && is_array($payload['qualifiers'])
        ? $payload['qualifiers']
        : [];

    if (empty($qualifiers)) {
        $qualifiers[] = [
            'slot' => (string)($payload['slot'] ?? ''),
            'driver' => (string)($payload['driver'] ?? ''),
            'trigger_races' => isset($payload['trigger_races']) && is_array($payload['trigger_races'])
                ? $payload['trigger_races']
                : [],
            'effective_race' => $effectiveRace,
        ];
    }

    $jsonBase = basename($jsonPath);
    $jsonLink = 'https://' . $publicHost
        . '/race_results/' . rawurlencode((string)$year)
        . '/' . rawurlencode($raceFolderName)
        . '/' . rawurlencode($jsonBase);

    $currentRaceCode = '';
    if (preg_match('/^(R\d{2})_/', $raceFolderName, $m)) {
        $currentRaceCode = (string)$m[1];
    }

    $isReminder = ($currentRaceCode !== '' && $effectiveRace !== '' && strcmp($currentRaceCode, $effectiveRace) > 0);

    $subject = $subjectPrefix
        . ($isReminder ? 'Reminder: ' : '')
        . $year . '_' . $segment . '_' . rr_sanitize_for_folder($teamName);

    $message =
        'Replacement Pick eligible.<br>'
        . 'Team: ' . htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') . '<br>'
        . 'Segment: ' . htmlspecialchars($segment, ENT_QUOTES, 'UTF-8') . '<br>'
        . 'Eligible driver choice(s):<br>';

    foreach ($qualifiers as $q) {
        if (!is_array($q)) continue;

        $qSlot = (string)($q['slot'] ?? '');
        $qDriver = (string)($q['driver'] ?? '');
        $qTriggers = isset($q['trigger_races']) && is_array($q['trigger_races'])
            ? implode(', ', $q['trigger_races'])
            : '';
        $qEffective = (string)($q['effective_race'] ?? '');

        $message .= '&nbsp;&nbsp;Group '
            . htmlspecialchars($qSlot, ENT_QUOTES, 'UTF-8')
            . ' — '
            . htmlspecialchars($qDriver, ENT_QUOTES, 'UTF-8')
            . ' | Trigger: '
            . htmlspecialchars($qTriggers, ENT_QUOTES, 'UTF-8')
            . ' | Effective: '
            . htmlspecialchars($qEffective, ENT_QUOTES, 'UTF-8')
            . '<br>';
    }

    $message .= '<a href="' . htmlspecialchars($jsonLink, ENT_QUOTES, 'UTF-8') . '">RD Pending JSON</a>';

    try {
        return (bool)$user_home->send_mail($notifyEmail, $message, $subject);
    } catch (Throwable $e) {
        return false;
    }
}


PHP;

        $s = rfi_regex_replace_once(
            $s,
            '~function rr_monitor_send_rd_email\(\$user_home,.*?\n\}\n\n\n(?=function rr_monitor_write_rd_status)~s',
            $emailFn,
            'Monitor RD email function'
        );

        // With v139, multi-qualifier teams are no longer "manual review".
        $s = rfi_replace_once(
            $s,
            "    if (\$manualRequiredCount > 0) {\n"
            . "        \$finalStatus = 'MANUAL_REVIEW';\n"
            . "        \$message = (string)\$manualRequiredCount . ' team(s) require manual RD selection review.';\n"
            . "    } elseif (\$eligibleCount > 0) {\n"
            . "        \$finalStatus = 'ACTION';\n"
            . "        \$message = (string)\$eligibleCount . ' RD eligible team(s) found.';\n"
            . "    }\n",
            "    if (\$eligibleCount > 0) {\n"
            . "        \$finalStatus = 'ACTION';\n"
            . "        \$message = (string)\$eligibleCount . ' Replacement Pick eligible team(s) found.';\n"
            . "    }\n",
            'Monitor final status'
        );

        $prepared['monitor'] = $s;
        rfi_check($checks, 'Prepared monitor v139', true, 'shared helper + all qualifiers + user-choice status');
    } catch (Throwable $e) {
        $errors[] = 'Monitor transform failed: ' . $e->getMessage();
        rfi_check($checks, 'Prepared monitor v139', false, $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// PREPARE TEAM.PHP v024
// -----------------------------------------------------------------------------

if (empty($errors)) {
    try {
        $s = $current['team'];

        $s = rfi_replace_once($s, 'VERSION: v023', 'VERSION: v024', 'Team version');
        $s = rfi_replace_once(
            $s,
            'LAST MODIFIED: 8/20/2026 8:29:00 pm',
            'LAST MODIFIED: 8/22/2026 7:26:00 pm',
            'Team modified time'
        );

        $s = rfi_replace_once(
            $s,
            " * CHANGELOG:\n",
            " * CHANGELOG:\n"
            . " *\n"
            . " * v024 (8/22/2026 7:26:00 pm)\n"
            . " * - NEW: RD pending JSON may contain one or multiple qualifying drivers.\n"
            . " * - NEW: Builds replacement option maps independently for every eligible group.\n"
            . " * - NEW: Once an RD row exists, editing is locked to the originally replaced group.\n"
            . " * - CHANGE: User-facing form can use the same explicit choice UI for single or dual eligibility.\n"
            . " * - PRESERVE: Existing LP, normal picks, SPECIAL_AUTH, charts, menu, and deadline behavior.\n",
            'Team changelog'
        );

        $helperInsert = <<<'PHP'
function teampage_rd_normalize_qualifiers(array $payload): array
{
    $out = [];

    $raw = isset($payload['qualifiers']) && is_array($payload['qualifiers'])
        ? $payload['qualifiers']
        : [];

    foreach ($raw as $q) {
        if (!is_array($q)) {
            continue;
        }

        $slot = strtoupper(trim((string)($q['slot'] ?? '')));
        $driver = trim((string)($q['driver'] ?? ''));

        if (!in_array($slot, ['A', 'B', 'C', 'D'], true) || $driver === '') {
            continue;
        }

        $triggers = isset($q['trigger_races']) && is_array($q['trigger_races'])
            ? array_values($q['trigger_races'])
            : [];

        $out[] = [
            'slot' => $slot,
            'driver' => $driver,
            'trigger_races' => $triggers,
            'effective_race' => trim((string)($q['effective_race'] ?? '')),
        ];
    }

    // Backward compatibility with old single-qualifier pending JSON.
    if (empty($out)) {
        $slot = strtoupper(trim((string)($payload['slot'] ?? '')));
        $driver = trim((string)($payload['driver'] ?? ''));

        if (in_array($slot, ['A', 'B', 'C', 'D'], true) && $driver !== '') {
            $out[] = [
                'slot' => $slot,
                'driver' => $driver,
                'trigger_races' => isset($payload['trigger_races']) && is_array($payload['trigger_races'])
                    ? array_values($payload['trigger_races'])
                    : [],
                'effective_race' => trim((string)($payload['effective_race'] ?? '')),
            ];
        }
    }

    return $out;
}

function teampage_rd_changed_group(array $baseRow, array $rdRow): string
{
    $changed = [];

    foreach (['A', 'B', 'C', 'D'] as $group) {
        $key = 'driver' . $group;
        $base = trim((string)($baseRow[$key] ?? ''));
        $rd = trim((string)($rdRow[$key] ?? ''));

        if ($base !== $rd) {
            $changed[] = $group;
        }
    }

    return count($changed) === 1 ? $changed[0] : '';
}


PHP;

        $s = rfi_replace_once(
            $s,
            "function teampage_user_has_rd_for_segment(PDO \$dbo, int \$uid, string \$raceYear, string \$segment): bool\n{",
            $helperInsert . "function teampage_user_has_rd_for_segment(PDO \$dbo, int \$uid, string \$raceYear, string \$segment): bool\n{",
            'Team RD qualifier helpers'
        );

        $oldVars = <<<'PHP'
$rdPendingGroup = '';
$rdPendingCurrentDriver = '';
$rdPendingTriggerRaces = '';
$rdPendingEffectiveRace = '';
$rdBasePickRow = null;
$rdLatestPickRow = null;
$rdActivePickRow = null;
$rdReplacementOptions = [];
PHP;
        $newVars = <<<'PHP'
$rdPendingGroup = '';
$rdPendingCurrentDriver = '';
$rdPendingTriggerRaces = '';
$rdPendingEffectiveRace = '';
$rdPendingQualifiers = [];
$rdBasePickRow = null;
$rdLatestPickRow = null;
$rdActivePickRow = null;
$rdReplacementOptions = [];
$rdReplacementOptionsByGroup = [];
$rdSelectedDriversByGroup = [];
$rdLockedSelectedGroup = '';
PHP;
        $s = rfi_replace_once($s, $oldVars, $newVars, 'Team RD variable block');

        $newRdBlock = <<<'PHP'
        $rdPendingSegmentLabel = teampage_rd_segment_label($rdPendingSegment);
        $rdPendingQualifiers = teampage_rd_normalize_qualifiers($rdPendingPayload);

        if (empty($rdPendingQualifiers)) {
            $showRdWrapper = false;
        } else {
            $rdBasePickRow = teampage_get_segment_base_pick_row(
                $dbo,
                $uid,
                (string)$raceYear,
                $rdPendingSegment
            );
            $rdLatestPickRow = teampage_get_latest_rd_pick_row(
                $dbo,
                $uid,
                (string)$raceYear,
                $rdPendingSegment
            );

            $rdActivePickRow = is_array($rdLatestPickRow)
                ? $rdLatestPickRow
                : $rdBasePickRow;

            // After the first RD submission, edits remain on that one replaced
            // group. This prevents an edit from becoming a second replacement.
            if (is_array($rdBasePickRow) && is_array($rdLatestPickRow)) {
                $rdLockedSelectedGroup = teampage_rd_changed_group(
                    $rdBasePickRow,
                    $rdLatestPickRow
                );

                if ($rdLockedSelectedGroup !== '') {
                    $rdPendingQualifiers = array_values(array_filter(
                        $rdPendingQualifiers,
                        function (array $q) use ($rdLockedSelectedGroup): bool {
                            return strtoupper((string)($q['slot'] ?? '')) === $rdLockedSelectedGroup;
                        }
                    ));
                }
            }

            if (empty($rdPendingQualifiers)) {
                $showRdWrapper = false;
            } else {
                // Keep legacy singular variables populated from the first
                // remaining choice for diagnostics/backward compatibility.
                $firstQualifier = $rdPendingQualifiers[0];
                $rdPendingGroup = strtoupper(trim((string)($firstQualifier['slot'] ?? '')));
                $rdPendingCurrentDriver = trim((string)($firstQualifier['driver'] ?? ''));
                $rdPendingEffectiveRace = trim((string)($firstQualifier['effective_race'] ?? ''));
                $firstTriggers = isset($firstQualifier['trigger_races']) && is_array($firstQualifier['trigger_races'])
                    ? $firstQualifier['trigger_races']
                    : [];
                $rdPendingTriggerRaces = implode(', ', $firstTriggers);

                if (is_array($rdActivePickRow) && isset($dbconnect) && $dbconnect instanceof mysqli) {
                    foreach ($rdPendingQualifiers as $qualifier) {
                        $group = strtoupper(trim((string)($qualifier['slot'] ?? '')));
                        $originalDriver = trim((string)($qualifier['driver'] ?? ''));

                        if (!in_array($group, ['A', 'B', 'C', 'D'], true) || $originalDriver === '') {
                            continue;
                        }

                        $selectedDriver = '';
                        if (is_array($rdLatestPickRow)) {
                            $selectedDriver = trim((string)($rdLatestPickRow['driver' . $group] ?? ''));
                        }
                        $rdSelectedDriversByGroup[$group] = $selectedDriver;

                        $excludeDrivers = [];
                        foreach (['A', 'B', 'C', 'D'] as $groupCode) {
                            $driverKey = 'driver' . $groupCode;
                            $driverValue = trim((string)($rdActivePickRow[$driverKey] ?? ''));

                            if ($groupCode !== $group && $driverValue !== '') {
                                $excludeDrivers[] = $driverValue;
                            }
                        }

                        if ($originalDriver !== '' && $originalDriver !== $selectedDriver) {
                            $excludeDrivers[] = $originalDriver;
                        }

                        $rdReplacementOptionsByGroup[$group] = teampage_rd_driver_options(
                            $dbconnect,
                            $group,
                            (int)$raceYear,
                            $uid,
                            $rdPendingSegment,
                            $excludeDrivers
                        );
                    }

                    // Backward-compatible singular option list.
                    $rdReplacementOptions = $rdReplacementOptionsByGroup[$rdPendingGroup] ?? [];
                    $rdSelectedDriver = $rdSelectedDriversByGroup[$rdPendingGroup] ?? '';
                }
            }
        }

PHP;

        $s = rfi_regex_replace_once(
            $s,
            '~        \$rdPendingSegmentLabel = teampage_rd_segment_label\(\$rdPendingSegment\);.*?(?=        \$deadlineInfo = teampage_schedule_deadline_info)~s',
            $newRdBlock,
            'Team RD pending processing block'
        );

        $prepared['team'] = $s;
        rfi_check($checks, 'Prepared team.php v024', true, 'multi qualifier maps + edit lock');
    } catch (Throwable $e) {
        $errors[] = 'team.php transform failed: ' . $e->getMessage();
        rfi_check($checks, 'Prepared team.php v024', false, $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// PREPARE COMPLETE WRAPPER v009
// -----------------------------------------------------------------------------

if (empty($errors)) {
    try {
        $wrapper = <<<'PHP'
<?php
declare(strict_types=1);

/**
 * team_replacement_driver.php
 *
 * VERSION: v009
 * LAST MODIFIED: 8/22/2026 7:26:00 pm
 *
 * DESCRIPTION:
 * Replacement Pick wrapper for the real MRL team page.
 *
 * USER FLOW:
 * - One or more eligible drivers are displayed as explicit radio choices.
 * - Even a single eligible driver must be explicitly selected.
 * - After the choice, only that group's replacement dropdown is shown.
 * - The other three groups remain read-only.
 * - Once an RD already exists, team.php limits the choices to the originally
 *   replaced group so an edit cannot become a second replacement.
 *
 * INTERNAL:
 * - pick_type remains RD for compatibility.
 * - submit-team-picks.php performs independent server-side validation.
 *
 * CHANGELOG:
 *
 * v009 (8/22/2026 7:26:00 pm)
 * - NEW: One required radio choice per eligible driver, including single-driver cases.
 * - NEW: Supports MULTIPLE_RD_AVAILABLE without admin selection.
 * - NEW: Replacement dropdown appears only for the explicitly selected group.
 * - CHANGE: User-facing wording standardized to Replacement Pick.
 * - PRESERVE: Existing MRL group colors, deadline display and RD submission endpoint.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');

$rdWrapperVersion = 'v009';

if (!isset($rdBasePickRow) || !is_array($rdBasePickRow)) {
    echo "<div style='color:#b00000;background:#ffd7d7;text-align:center;font-weight:bold;padding:10px;'>Replacement Pick base row is not available.</div>";
    return;
}

function rd_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function rd_cell_style(string $group): string
{
    if ($group === 'A') return '#d9d9d9';
    if ($group === 'B') return '#c4bd97';
    if ($group === 'C') return '#b8cce4';
    if ($group === 'D') return '#d8e4bc';
    return '#ffffff';
}

function rd_readonly(string $value, string $group): string
{
    return "<div style='width:98%;box-sizing:border-box;min-height:38px;display:flex;align-items:center;"
        . "padding:0 8px;font-size:18px;color:black;background-color:" . rd_cell_style($group) . ";"
        . "border-radius:4px;opacity:.65;margin:0 auto;'>"
        . rd_h($value)
        . "</div>";
}

$rdDisplayYear = isset($raceYear) ? (string)$raceYear : '';
$rdDisplaySegment = isset($rdPendingSegmentLabel) && trim((string)$rdPendingSegmentLabel) !== ''
    ? trim((string)$rdPendingSegmentLabel)
    : trim((string)($rdPendingSegment ?? ''));

$rdChoices = isset($rdPendingQualifiers) && is_array($rdPendingQualifiers)
    ? array_values($rdPendingQualifiers)
    : [];

if (empty($rdChoices)) {
    $legacyGroup = isset($rdPendingGroup) ? strtoupper(trim((string)$rdPendingGroup)) : '';
    $legacyDriver = isset($rdPendingCurrentDriver) ? trim((string)$rdPendingCurrentDriver) : '';

    if (in_array($legacyGroup, ['A','B','C','D'], true) && $legacyDriver !== '') {
        $rdChoices[] = [
            'slot' => $legacyGroup,
            'driver' => $legacyDriver,
            'trigger_races' => isset($rdPendingPayload['trigger_races']) && is_array($rdPendingPayload['trigger_races'])
                ? $rdPendingPayload['trigger_races']
                : [],
            'effective_race' => isset($rdPendingEffectiveRace) ? (string)$rdPendingEffectiveRace : '',
        ];
    }
}

if (empty($rdChoices)) {
    echo "<div style='color:#b00000;background:#ffd7d7;text-align:center;font-weight:bold;padding:10px;'>No eligible Replacement Pick driver is available.</div>";
    return;
}

$rdDisplayPickRow = isset($rdActivePickRow) && is_array($rdActivePickRow)
    ? $rdActivePickRow
    : $rdBasePickRow;

$drivers = [];
foreach (['A','B','C','D'] as $g) {
    $drivers[$g] = trim((string)($rdDisplayPickRow['driver' . $g] ?? ''));
}

$baseDrivers = [];
foreach (['A','B','C','D'] as $g) {
    $baseDrivers[$g] = trim((string)($rdBasePickRow['driver' . $g] ?? ''));
}

$effectiveRace = '';
foreach ($rdChoices as $q) {
    $candidate = trim((string)($q['effective_race'] ?? ''));
    if ($candidate !== '') {
        $effectiveRace = $candidate;
        break;
    }
}

$deadlineRace = isset($rdDeadlineRaceCode) ? trim((string)$rdDeadlineRaceCode) : '';
$deadlineTime = isset($rdDeadlineDisplay) ? trim((string)$rdDeadlineDisplay) : '';
if ($deadlineRace !== '') {
    $effectiveRace = $deadlineRace;
}

$optionsByGroup = isset($rdReplacementOptionsByGroup) && is_array($rdReplacementOptionsByGroup)
    ? $rdReplacementOptionsByGroup
    : [];

$selectedByGroup = isset($rdSelectedDriversByGroup) && is_array($rdSelectedDriversByGroup)
    ? $rdSelectedDriversByGroup
    : [];

$clockId = 'rdClockCell';
?>

<br>
<div style="color:#8a1b00;font-size:20px;background-color:#fabf8f;text-align:center;font-weight:bold;padding:8px 10px;font-family:Century Gothic,sans-serif;">
    You are eligible, but not required, to make a Replacement Pick under league rule 3
    <br>(2 successive races with 0 points scored by a driver)
</div>

<form id="rdReplacementForm" action="/submit-team-picks.php" method="post" onsubmit="return rdPrepareSubmit(this);">
    <input type="hidden" name="submission_id" value="">
    <input type="hidden" name="form_id" value="team_replacement_driver.php">
    <input type="hidden" name="form_version" value="<?php echo rd_h($rdWrapperVersion); ?>">
    <input type="hidden" name="pick_type_override" value="RD">
    <input type="hidden" name="rd_segment" value="<?php echo rd_h((string)$rdPendingSegment); ?>">
    <input type="hidden" name="rd_effective_race" value="<?php echo rd_h($effectiveRace); ?>">
    <input type="hidden" name="rd_supersedes_pick_id" value="<?php echo rd_h((string)($rdBasePickRow['pickID'] ?? '')); ?>">
    <input type="hidden" name="rd_selected_driver" id="rdSelectedDriver" value="">

    <?php foreach (['A','B','C','D'] as $g): ?>
        <input type="hidden" name="group-<?php echo strtolower($g); ?>-driver" id="rdCanonical<?php echo $g; ?>" value="<?php echo rd_h($drivers[$g]); ?>">
    <?php endforeach; ?>

    <div style="margin:8px auto;padding:10px;max-width:900px;background:#2a2412;border:1px solid #8d721d;border-radius:7px;color:#fff;">
        <div style="font-weight:bold;color:#ffe08a;margin-bottom:7px;">
            Replacement Pick — choose the driver to replace:
        </div>

        <?php foreach ($rdChoices as $index => $q): ?>
            <?php
            $group = strtoupper(trim((string)($q['slot'] ?? '')));
            $driver = trim((string)($q['driver'] ?? ''));
            $triggers = isset($q['trigger_races']) && is_array($q['trigger_races'])
                ? implode(', ', $q['trigger_races'])
                : '';
            ?>
            <label style="display:block;margin:7px 0;padding:8px 10px;background:#171717;border:1px solid #555;border-radius:6px;cursor:pointer;">
                <input
                    type="radio"
                    name="rd_selected_slot"
                    value="<?php echo rd_h($group); ?>"
                    data-driver="<?php echo rd_h($driver); ?>"
                    onchange="rdChooseGroup(this)"
                    required
                    style="width:auto;margin-right:8px;"
                >
                <strong>Group <?php echo rd_h($group); ?> — <?php echo rd_h($driver); ?></strong>
                <?php if ($triggers !== ''): ?>
                    <span style="color:#aaa;margin-left:8px;">(<?php echo rd_h($triggers); ?>)</span>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>

        <div id="rdChoiceMessage" style="display:none;margin-top:8px;padding:8px;background:#173b20;border:1px solid #2b7740;border-radius:6px;"></div>
    </div>

    <table align="center" style="width:100%;">
        <tr style="background-color:#fabf8f;">
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;">
                Select the eligible driver above, then choose that group's replacement driver.
            </th>
        </tr>
        <tr style="background-color:#b7dee8;">
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;">
                Effective race: <?php echo rd_h($effectiveRace); ?>
                <?php if ($deadlineRace !== '' || $deadlineTime !== ''): ?>
                    &nbsp; | &nbsp; Deadline: start of <?php echo rd_h($deadlineRace); ?>
                    <?php if ($deadlineTime !== ''): ?>(<?php echo rd_h($deadlineTime); ?>)<?php endif; ?>
                <?php endif; ?>
            </th>
        </tr>
    </table>

    <table align="center" style="width:100%;">
        <tr style="background-color:#fabf8f;">
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:14%;"><?php echo rd_h($rdDisplayYear); ?></th>
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:18%;">A Driver</th>
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:18%;">B Driver</th>
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:18%;">C Driver</th>
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:18%;">D Driver</th>
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:14%;" id="<?php echo $clockId; ?>"></th>
        </tr>

        <tr style="background-color:#b7dee8;">
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;vertical-align:middle;">
                <?php echo rd_h($rdDisplaySegment); ?>
            </th>

            <?php foreach (['A','B','C','D'] as $g): ?>
                <td style="background-color:<?php echo rd_h(rd_cell_style($g)); ?>;width:18%;padding:2px;vertical-align:middle;">
                    <div id="rdReadonly<?php echo $g; ?>">
                        <?php echo rd_readonly($drivers[$g], $g); ?>
                    </div>

                    <div id="rdEditor<?php echo $g; ?>" style="display:none;">
                        <select
                            id="rdSelect<?php echo $g; ?>"
                            data-group="<?php echo rd_h($g); ?>"
                            onchange="rdReplacementChanged(this)"
                            style="width:100%;height:auto;border:1px solid black;font-size:18px;color:black;background-color:<?php echo rd_h(rd_cell_style($g)); ?>;border-radius:4px;"
                        >
                            <option value="">Select Replacement Driver</option>

                            <?php
                            $currentSelected = trim((string)($selectedByGroup[$g] ?? ''));
                            if ($currentSelected !== '' && $currentSelected !== $baseDrivers[$g]):
                            ?>
                                <option value="<?php echo rd_h($currentSelected); ?>" selected><?php echo rd_h($currentSelected); ?></option>
                            <?php endif; ?>

                            <?php foreach (($optionsByGroup[$g] ?? []) as $driverRow): ?>
                                <?php
                                $driverName = trim((string)($driverRow['driverName'] ?? ''));
                                $driverTag = trim((string)($driverRow['tag'] ?? ''));
                                if ($driverName === '' || $driverName === $currentSelected) continue;
                                $displayText = trim($driverName . ' ' . $driverTag);
                                ?>
                                <option value="<?php echo rd_h($driverName); ?>"><?php echo rd_h($displayText); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </td>
            <?php endforeach; ?>

            <td style="text-align:center;background-color:#b7dee8;width:14%;vertical-align:middle;">
                <input type="reset" value="Reset" onclick="setTimeout(rdResetChoice,0)">
                <input type="submit" value="Submit Picks">
            </td>
        </tr>
    </table>

    <div style="font-size:10px;color:#999;text-align:right;margin:0;padding:0;">
        team_replacement_driver.php <?php echo rd_h($rdWrapperVersion); ?>
    </div>
</form>

<div style="font:11px/1.2 monospace;color:#999;text-align:right;margin:0;padding:8px 0 0 0;">
    ELIGIBLE CHOICES: <?php echo rd_h((string)count($rdChoices)); ?>
    | EFFECTIVE RACE: <?php echo rd_h($effectiveRace); ?>
    <?php if ($deadlineRace !== '' || $deadlineTime !== ''): ?>
        | DEADLINE: start of <?php echo rd_h($deadlineRace); ?>
        <?php if ($deadlineTime !== ''): ?>(<?php echo rd_h($deadlineTime); ?>)<?php endif; ?>
    <?php endif; ?>
</div>

<script>
function rdHideAllEditors() {
    ['A','B','C','D'].forEach(function(group) {
        var editor = document.getElementById('rdEditor' + group);
        var readonly = document.getElementById('rdReadonly' + group);
        if (editor) editor.style.display = 'none';
        if (readonly) readonly.style.display = 'block';
    });
}

function rdChooseGroup(radio) {
    rdHideAllEditors();

    var group = radio.value || '';
    var originalDriver = radio.getAttribute('data-driver') || '';
    var editor = document.getElementById('rdEditor' + group);
    var readonly = document.getElementById('rdReadonly' + group);
    var selectedDriverField = document.getElementById('rdSelectedDriver');
    var msg = document.getElementById('rdChoiceMessage');

    if (editor) editor.style.display = 'block';
    if (readonly) readonly.style.display = 'none';
    if (selectedDriverField) selectedDriverField.value = originalDriver;

    if (msg) {
        msg.textContent = 'Selected: Group ' + group + ' — ' + originalDriver;
        msg.style.display = 'block';
    }

    var select = document.getElementById('rdSelect' + group);
    if (select && select.value !== '') {
        var canonical = document.getElementById('rdCanonical' + group);
        if (canonical) canonical.value = select.value;
    }
}

function rdReplacementChanged(select) {
    var group = select.getAttribute('data-group') || '';
    var canonical = document.getElementById('rdCanonical' + group);
    if (canonical) canonical.value = select.value;
}

function rdPrepareSubmit(form) {
    var selected = form.querySelector('input[name="rd_selected_slot"]:checked');
    if (!selected) return false;

    var group = selected.value || '';
    var select = document.getElementById('rdSelect' + group);

    if (!select || select.value === '') {
        if (select && typeof select.reportValidity === 'function') {
            select.setCustomValidity('Select a replacement driver for Group ' + group + '.');
            select.reportValidity();
            select.setCustomValidity('');
        } else {
            alert('Select a replacement driver for Group ' + group + '.');
        }
        return false;
    }

    var canonical = document.getElementById('rdCanonical' + group);
    if (canonical) canonical.value = select.value;

    return true;
}

function rdResetChoice() {
    rdHideAllEditors();
    var msg = document.getElementById('rdChoiceMessage');
    var selectedDriverField = document.getElementById('rdSelectedDriver');
    if (msg) msg.style.display = 'none';
    if (selectedDriverField) selectedDriverField.value = '';
}

function updateRdClock() {
    var now = new Date();
    var cell = document.getElementById('<?php echo rd_h($clockId); ?>');
    if (!cell) return;

    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    var year = now.getFullYear();
    var hours = now.getHours();
    var minutes = String(now.getMinutes()).padStart(2, '0');
    var seconds = String(now.getSeconds()).padStart(2, '0');
    var ampm = hours >= 12 ? 'PM' : 'AM';
    var h12 = hours % 12;
    if (h12 === 0) h12 = 12;

    cell.textContent = month + '/' + day + '/' + year + ' ' + h12 + ':' + minutes + ':' + seconds + ' ' + ampm;
}

rdHideAllEditors();
updateRdClock();
setInterval(updateRdClock, 1000);
</script>
PHP;

        $prepared['wrapper'] = $wrapper;
        rfi_check($checks, 'Prepared team_replacement_driver.php v009', true, 'required radio choice for single or multiple');
    } catch (Throwable $e) {
        $errors[] = 'Wrapper preparation failed: ' . $e->getMessage();
        rfi_check($checks, 'Prepared team_replacement_driver.php v009', false, $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// PREPARE SUBMIT v007
// -----------------------------------------------------------------------------

if (empty($errors)) {
    try {
        $s = $current['submit'];

        $s = rfi_replace_once($s, 'VERSION: v006', 'VERSION: v007', 'Submit version');
        $s = rfi_replace_once(
            $s,
            'LAST MODIFIED: 8/19/2026 4:51:53 am',
            'LAST MODIFIED: 8/22/2026 7:26:00 pm',
            'Submit modified time'
        );

        $s = rfi_replace_once(
            $s,
            " * CHANGELOG:\n",
            " * CHANGELOG:\n"
            . " *\n"
            . " * v007 (8/22/2026 7:26:00 pm)\n"
            . " * - NEW: Server validates explicit Replacement Pick slot/driver choice against pending JSON.\n"
            . " * - NEW: Enforces one RD per year using current rows plus history before a new RD is accepted.\n"
            . " * - NEW: Existing RD edits are locked to the originally replaced group.\n"
            . " * - NEW: Rejects tampering that changes any non-selected group in an RD submission.\n"
            . " * - FIX: RD POST values are now read before activeSegment is derived.\n"
            . " * - PRESERVE: Existing SEG/ADJ/LP behavior and existing RD update/history write behavior.\n",
            'Submit changelog'
        );

        $validationHelpers = <<<'PHP'
function mrl_rd_slug(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = trim($value);
    $value = preg_replace('/[^A-Za-z0-9 _-]+/', '', $value);
    $value = preg_replace('/\s+/', ' ', (string)$value);
    $value = str_replace([' ', '-'], '_', (string)$value);
    $value = preg_replace('/_+/', '_', (string)$value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : 'Team';
}

function mrl_rd_find_latest_pending(string $raceYear, string $teamName): ?array
{
    $baseDir = __DIR__ . '/race_results/' . $raceYear;
    if (!is_dir($baseDir)) return null;

    $matches = glob($baseDir . '/R??_*/_rd_pending_' . mrl_rd_slug($teamName) . '.json');
    if (!is_array($matches) || empty($matches)) return null;

    rsort($matches, SORT_STRING);
    $path = (string)$matches[0];
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;

    $payload = json_decode($raw, true);
    if (!is_array($payload)) return null;

    return ['path'=>$path, 'payload'=>$payload];
}

function mrl_rd_normalize_pending_qualifiers(array $payload): array
{
    $out = [];
    $raw = isset($payload['qualifiers']) && is_array($payload['qualifiers'])
        ? $payload['qualifiers']
        : [];

    foreach ($raw as $q) {
        if (!is_array($q)) continue;
        $slot = strtoupper(trim((string)($q['slot'] ?? '')));
        $driver = trim((string)($q['driver'] ?? ''));
        if (!in_array($slot, ['A','B','C','D'], true) || $driver === '') continue;

        $out[] = [
            'slot'=>$slot,
            'driver'=>$driver,
            'effective_race'=>trim((string)($q['effective_race'] ?? '')),
        ];
    }

    if (empty($out)) {
        $slot = strtoupper(trim((string)($payload['slot'] ?? '')));
        $driver = trim((string)($payload['driver'] ?? ''));
        if (in_array($slot, ['A','B','C','D'], true) && $driver !== '') {
            $out[] = [
                'slot'=>$slot,
                'driver'=>$driver,
                'effective_race'=>trim((string)($payload['effective_race'] ?? '')),
            ];
        }
    }

    return $out;
}

function mrl_rd_get_base_row(mysqli $dbconnect, int $uid, string $raceYear, string $segment): ?array
{
    $sql = "SELECT pickID, driverA, driverB, driverC, driverD
            FROM user_picks
            WHERE userID = ?
              AND raceYear = ?
              AND segment = ?
              AND pick_type IN ('SEG','ADJ')
            ORDER BY entryDate ASC, pickID ASC
            LIMIT 1";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, 'iss', $uid, $raceYear, $segment);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}

function mrl_rd_get_current_row(mysqli $dbconnect, int $uid, string $raceYear, string $segment): ?array
{
    $sql = "SELECT pickID, driverA, driverB, driverC, driverD, effective_race
            FROM user_picks
            WHERE userID = ?
              AND raceYear = ?
              AND segment = ?
              AND pick_type = 'RD'
            ORDER BY pickID DESC
            LIMIT 1";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, 'iss', $uid, $raceYear, $segment);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}

function mrl_rd_history_segments(mysqli $dbconnect, int $uid, string $raceYear): array
{
    $sql = "SELECT DISTINCT segment
            FROM user_picks_history
            WHERE userID = ?
              AND raceYear = ?
              AND pick_type = 'RD'";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) return [];

    mysqli_stmt_bind_param($stmt, 'is', $uid, $raceYear);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $seg = trim((string)($row['segment'] ?? ''));
            if ($seg !== '') $out[$seg] = true;
        }
    }

    mysqli_stmt_close($stmt);
    return array_keys($out);
}

function mrl_rd_changed_slot(array $baseRow, array $rdRow): string
{
    $changed = [];
    foreach (['A','B','C','D'] as $slot) {
        $key = 'driver' . $slot;
        if (trim((string)($baseRow[$key] ?? '')) !== trim((string)($rdRow[$key] ?? ''))) {
            $changed[] = $slot;
        }
    }
    return count($changed) === 1 ? $changed[0] : '';
}

function mrl_rd_reject(): void
{
    header('Location: /team.php#current_user_team_chart');
    exit;
}


PHP;

        $s = rfi_replace_once(
            $s,
            "function mrl_original_pick_deadline_passed(string \$formLockDate, string \$formLockTime): bool\n{",
            $validationHelpers . "function mrl_original_pick_deadline_passed(string \$formLockDate, string \$formLockTime): bool\n{",
            'Submit RD validation helpers'
        );

        $oldPostOrder = <<<'PHP'
$raceYearStr = (string)$raceYear;
$raceYearInt = (int)$raceYear;
$activeSegment = ($pickTypeOverride === 'RD' && $rdSegmentPost !== '') ? $rdSegmentPost : (string)$segment;

$driverA = mrl_post_value('group-a-driver');
$driverB = mrl_post_value('group-b-driver');
$driverC = mrl_post_value('group-c-driver');
$driverD = mrl_post_value('group-d-driver');
$submissionId = mrl_post_value('submission_id');
$formID = mrl_post_value('form_id');
$formVersion = mrl_post_value('form_version');
$pickTypeOverride = strtoupper(mrl_post_value('pick_type_override'));
$rdSegmentPost = mrl_post_value('rd_segment');
$rdEffectiveRacePost = mrl_post_value('rd_effective_race');
$rdSupersedesPickIdPost = mrl_post_value('rd_supersedes_pick_id');
PHP;

        $newPostOrder = <<<'PHP'
$raceYearStr = (string)$raceYear;
$raceYearInt = (int)$raceYear;

$driverA = mrl_post_value('group-a-driver');
$driverB = mrl_post_value('group-b-driver');
$driverC = mrl_post_value('group-c-driver');
$driverD = mrl_post_value('group-d-driver');
$submissionId = mrl_post_value('submission_id');
$formID = mrl_post_value('form_id');
$formVersion = mrl_post_value('form_version');
$pickTypeOverride = strtoupper(mrl_post_value('pick_type_override'));
$rdSegmentPost = mrl_post_value('rd_segment');
$rdEffectiveRacePost = mrl_post_value('rd_effective_race');
$rdSupersedesPickIdPost = mrl_post_value('rd_supersedes_pick_id');
$rdSelectedSlotPost = strtoupper(mrl_post_value('rd_selected_slot'));
$rdSelectedDriverPost = mrl_post_value('rd_selected_driver');

$activeSegment = ($pickTypeOverride === 'RD' && $rdSegmentPost !== '')
    ? $rdSegmentPost
    : (string)$segment;
PHP;

        $s = rfi_replace_once($s, $oldPostOrder, $newPostOrder, 'Submit POST ordering');

        $oldRdBranch = <<<'PHP'
if ($pickTypeOverride === 'RD') {
    $pickType = 'RD';
    $effectiveRace = mrl_parse_effective_race_value($rdEffectiveRacePost);

    if ($effectiveRace <= 0) {
        header('Location: /team.php#current_user_team_chart');
        exit;
    }

    if ($rdSupersedesPickIdPost !== '' && ctype_digit($rdSupersedesPickIdPost)) {
        $supersedesPickID = (int)$rdSupersedesPickIdPost;
    } else {
        $supersedesPickID = mrl_get_segment_base_pick_id($dbconnect, $uid, $raceYearStr, $activeSegment);
    }

    $existingPickID = mrl_get_existing_pick_id_by_type($dbconnect, $uid, $raceYearStr, $activeSegment, 'RD');
    $exists = ($existingPickID !== null);

    if ($formID === '' || $formID === 'form-team-picks.php') {
        $formID = 'team_replacement_driver.php';
    }
} else {
PHP;

        $newRdBranch = <<<'PHP'
if ($pickTypeOverride === 'RD') {
    $pickType = 'RD';
    $effectiveRace = mrl_parse_effective_race_value($rdEffectiveRacePost);

    if (
        $effectiveRace <= 0
        || !in_array($rdSelectedSlotPost, ['A','B','C','D'], true)
        || $rdSelectedDriverPost === ''
        || $activeSegment === ''
    ) {
        mrl_rd_reject();
    }

    // Deadline protection belongs on the server too, not just on team.php.
    if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        mrl_rd_reject();
    }

    $pendingInfo = mrl_rd_find_latest_pending($raceYearStr, $teamName);
    if (!is_array($pendingInfo)) {
        mrl_rd_reject();
    }

    $pendingPayload = isset($pendingInfo['payload']) && is_array($pendingInfo['payload'])
        ? $pendingInfo['payload']
        : [];

    if (trim((string)($pendingPayload['segment'] ?? '')) !== $activeSegment) {
        mrl_rd_reject();
    }

    $qualifiers = mrl_rd_normalize_pending_qualifiers($pendingPayload);
    $selectedQualifier = null;

    foreach ($qualifiers as $q) {
        if (
            (string)($q['slot'] ?? '') === $rdSelectedSlotPost
            && trim((string)($q['driver'] ?? '')) === $rdSelectedDriverPost
        ) {
            $selectedQualifier = $q;
            break;
        }
    }

    if (!is_array($selectedQualifier)) {
        mrl_rd_reject();
    }

    $qualifierEffective = mrl_parse_effective_race_value(
        (string)($selectedQualifier['effective_race'] ?? '')
    );
    if ($qualifierEffective !== $effectiveRace) {
        mrl_rd_reject();
    }

    $baseRdRow = mrl_rd_get_base_row($dbconnect, $uid, $raceYearStr, $activeSegment);
    if (!is_array($baseRdRow)) {
        mrl_rd_reject();
    }

    // The selected original driver must still match the base row for that slot.
    $selectedKey = 'driver' . $rdSelectedSlotPost;
    if (trim((string)($baseRdRow[$selectedKey] ?? '')) !== $rdSelectedDriverPost) {
        mrl_rd_reject();
    }

    $currentRdRow = mrl_rd_get_current_row($dbconnect, $uid, $raceYearStr, $activeSegment);
    $historySegments = mrl_rd_history_segments($dbconnect, $uid, $raceYearStr);

    if (!is_array($currentRdRow)) {
        // A prior RD in history means the one-per-year allowance has already been used.
        if (!empty($historySegments)) {
            mrl_rd_reject();
        }
    } else {
        // Existing edits are allowed only for this same RD segment.
        foreach ($historySegments as $historySegment) {
            if ($historySegment !== $activeSegment) {
                mrl_rd_reject();
            }
        }

        $lockedSlot = mrl_rd_changed_slot($baseRdRow, $currentRdRow);
        if ($lockedSlot === '' || $lockedSlot !== $rdSelectedSlotPost) {
            mrl_rd_reject();
        }
    }

    $sourceRow = is_array($currentRdRow) ? $currentRdRow : $baseRdRow;
    $submittedDrivers = [
        'A' => $driverA,
        'B' => $driverB,
        'C' => $driverC,
        'D' => $driverD,
    ];

    foreach (['A','B','C','D'] as $slot) {
        $key = 'driver' . $slot;
        $expected = trim((string)($sourceRow[$key] ?? ''));
        $submitted = trim((string)($submittedDrivers[$slot] ?? ''));

        if ($slot === $rdSelectedSlotPost) {
            if ($submitted === '' || $submitted === $rdSelectedDriverPost) {
                mrl_rd_reject();
            }
        } elseif ($submitted !== $expected) {
            // A Replacement Pick may alter exactly one group.
            mrl_rd_reject();
        }
    }

    if ($rdSupersedesPickIdPost !== '' && ctype_digit($rdSupersedesPickIdPost)) {
        $supersedesPickID = (int)$rdSupersedesPickIdPost;
    } else {
        $supersedesPickID = (int)($baseRdRow['pickID'] ?? 0);
    }

    if ($supersedesPickID <= 0 || $supersedesPickID !== (int)($baseRdRow['pickID'] ?? 0)) {
        mrl_rd_reject();
    }

    $existingPickID = is_array($currentRdRow)
        ? (int)($currentRdRow['pickID'] ?? 0)
        : null;
    $exists = ($existingPickID !== null && $existingPickID > 0);

    if ($formID === '' || $formID === 'form-team-picks.php') {
        $formID = 'team_replacement_driver.php';
    }
} else {
PHP;

        $s = rfi_replace_once($s, $oldRdBranch, $newRdBranch, 'Submit RD branch');

        $prepared['submit'] = $s;
        rfi_check($checks, 'Prepared submit-team-picks.php v007', true, 'server validation + one-per-year + POST order fix');
    } catch (Throwable $e) {
        $errors[] = 'submit-team-picks.php transform failed: ' . $e->getMessage();
        rfi_check($checks, 'Prepared submit-team-picks.php v007', false, $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// PREPARED SEMANTIC REVIEW
// -----------------------------------------------------------------------------

if (empty($errors)) {
    $semantic = [
        'Monitor uses shared v005 eligibility call' =>
            strpos($prepared['monitor'], 'mrl_rd_detect_team_segment_eligibility(') !== false,
        'Monitor accepts MULTIPLE_RD_AVAILABLE' =>
            strpos($prepared['monitor'], "\$status !== 'MULTIPLE_RD_AVAILABLE'") !== false,
        'Monitor pending JSON contains qualifiers array' =>
            strpos($prepared['monitor'], "'qualifiers' => \$qualifiers") !== false,
        'Team normalizes qualifier list' =>
            strpos($prepared['team'], 'function teampage_rd_normalize_qualifiers') !== false,
        'Team locks edits to original replaced group' =>
            strpos($prepared['team'], 'teampage_rd_changed_group') !== false,
        'Wrapper requires rd_selected_slot radio' =>
            strpos($prepared['wrapper'], 'name="rd_selected_slot"') !== false
            && strpos($prepared['wrapper'], 'required') !== false,
        'Wrapper supports one-or-many same path' =>
            strpos($prepared['wrapper'], 'foreach ($rdChoices as $index => $q)') !== false,
        'Submit reads RD fields before activeSegment' =>
            strpos($prepared['submit'], '$rdSelectedSlotPost =') !== false
            && strpos($prepared['submit'], '$activeSegment =') > strpos($prepared['submit'], '$rdSelectedSlotPost ='),
        'Submit validates pending qualifier choice' =>
            strpos($prepared['submit'], 'mrl_rd_normalize_pending_qualifiers') !== false,
        'Submit checks RD history for one-per-year' =>
            strpos($prepared['submit'], 'mrl_rd_history_segments') !== false,
        'Submit locks existing edit to one group' =>
            strpos($prepared['submit'], 'mrl_rd_changed_slot') !== false,
        'No DB schema SQL introduced' =>
            stripos(implode("\n", $prepared), 'ALTER TABLE') === false
            && stripos(implode("\n", $prepared), 'CREATE TABLE') === false
            && stripos(implode("\n", $prepared), 'DROP TABLE') === false,
        'Internal pick_type RD preserved' =>
            strpos($prepared['wrapper'], 'value="RD"') !== false
            && strpos($prepared['submit'], "\$pickType = 'RD';") !== false,
    ];

    $okAll = true;
    foreach ($semantic as $label => $ok) {
        rfi_check($checks, 'Prepared: ' . $label, $ok, $ok ? 'PASS' : 'FAIL');
        if (!$ok) $okAll = false;
    }
    if (!$okAll) {
        $errors[] = 'Prepared integration failed one or more semantic review checks.';
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

    $backupMap = [
        'monitor' => 'race_results_monitor.php',
        'team' => 'team.php',
        'wrapper' => 'team_replacement_driver.php',
        'submit' => 'submit-team-picks.php',
    ];

    if (empty($errors)) {
        foreach ($backupMap as $key => $base) {
            if (!@copy($files[$key], $backupDir . '/' . $base)) {
                $errors[] = 'Could not back up ' . $base . '.';
                break;
            }
        }
    }

    if (empty($errors)) {
        foreach (['monitor','team','wrapper','submit'] as $key) {
            if (!rfi_atomic_write($files[$key], $prepared[$key])) {
                $errors[] = 'Write failed for ' . basename($files[$key]) . '.';
                break;
            }
        }
    }

    if (!empty($errors)) {
        rfi_restore_all($files, $backupDir);
        $errors[] = 'Install write failure triggered all-file rollback.';
    } else {
        $after = [
            'monitor' => (string)@file_get_contents($files['monitor']),
            'team' => (string)@file_get_contents($files['team']),
            'wrapper' => (string)@file_get_contents($files['wrapper']),
            'submit' => (string)@file_get_contents($files['submit']),
            'helper' => (string)@file_get_contents($files['helper']),
        ];

        $postflight = [
            ['Monitor reports v139', strpos($after['monitor'], 'VERSION: v139') !== false],
            ['Monitor uses shared RD helper eligibility', strpos($after['monitor'], 'mrl_rd_detect_team_segment_eligibility(') !== false],
            ['Monitor writes qualifier arrays', strpos($after['monitor'], "'qualifiers' => \$qualifiers") !== false],
            ['Old monitor MANUAL selection gate removed from run path', strpos($after['monitor'], "status === 'MANUAL_SELECTION_REQUIRED'") === false],
            ['Team reports v024', strpos($after['team'], 'VERSION: v024') !== false],
            ['Team qualifier normalizer installed', strpos($after['team'], 'function teampage_rd_normalize_qualifiers') !== false],
            ['Team RD edit-lock helper installed', strpos($after['team'], 'function teampage_rd_changed_group') !== false],
            ['Wrapper reports v009', strpos($after['wrapper'], 'VERSION: v009') !== false],
            ['Wrapper has required radio choice', strpos($after['wrapper'], 'name="rd_selected_slot"') !== false],
            ['Wrapper user wording says Replacement Pick', strpos($after['wrapper'], 'Replacement Pick') !== false],
            ['Submit reports v007', strpos($after['submit'], 'VERSION: v007') !== false],
            ['Submit pending qualifier validation installed', strpos($after['submit'], 'mrl_rd_normalize_pending_qualifiers') !== false],
            ['Submit year-history guard installed', strpos($after['submit'], 'mrl_rd_history_segments') !== false],
            ['Submit POST-order bug removed', strpos($after['submit'], "\$activeSegment = (\$pickTypeOverride === 'RD'") === false],
            ['Shared RD helper remains v005', strpos($after['helper'], 'VERSION: v005') !== false],
            ['No schema migration statements in changed files',
                stripos($after['monitor'] . $after['team'] . $after['wrapper'] . $after['submit'], 'ALTER TABLE') === false
                && stripos($after['monitor'] . $after['team'] . $after['wrapper'] . $after['submit'], 'CREATE TABLE') === false
                && stripos($after['monitor'] . $after['team'] . $after['wrapper'] . $after['submit'], 'DROP TABLE') === false
            ],
        ];

        foreach ($postflight as $pf) {
            if (!$pf[1]) {
                $errors[] = 'Postflight failed: ' . $pf[0];
            }
        }

        if (!empty($errors)) {
            rfi_restore_all($files, $backupDir);
            $errors[] = 'Postflight failure triggered all-file rollback.';
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
<title>MRL Replacement Pick Real-Flow Integration</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1220px;margin:0 auto;padding:14px}
.banner{background:#24351d;border:1px solid #547c3d;border-radius:10px;padding:12px 14px}
.banner h1{margin:0;color:#dfffcf;font-size:22px}
.sub{font-size:12px;color:#bdd4ae;margin-top:4px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#d5efc9;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333;vertical-align:top}
.ok{color:#69ef98;font-weight:700}
.bad{color:#ff7777;font-weight:700}
.warn{color:#ffd36b;font-weight:700}
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
    <h1>MRL Replacement Pick Real-Flow Integration Installer v001</h1>
    <div class="sub">TESTPHP8 ONLY • generated 8/22/2026 7:26:00 pm • database schema changes: NONE</div>
</div>

<div class="card">
    <h2>Review — Exact Scope</h2>
    <ul>
        <li><code>race_results_monitor.php</code> v138 → v139: shared RD v005 helper + ALL qualifiers in pending JSON.</li>
        <li><code>team.php</code> v023 → v024: reads one-or-many qualifiers and builds per-group replacement options.</li>
        <li><code>team_replacement_driver.php</code> v008 → v009: explicit required radio choice for single or dual cases.</li>
        <li><code>submit-team-picks.php</code> v006 → v007: independent server validation, one-per-year guard, one-group-only validation.</li>
        <li><code>race_results_rd_helper.php</code> remains v005 and is <strong>not modified</strong>.</li>
    </ul>
    <div class="note" style="margin-top:8px">
        Internal RD names and <code>pick_type='RD'</code> remain unchanged. User-facing wording is Replacement Pick.
    </div>
</div>

<div class="card">
    <h2 class="warn">Safety State</h2>
    <div>Scheduler should remain <strong>OFF</strong> during this install and first verification.</div>
    <div>No installer DB writes. No schema changes. All four changed files are backed up together and roll back together.</div>
</div>

<div class="card">
    <h2>Preflight / Prepared Integration Review</h2>
    <table>
    <?php foreach ($checks as $c): ?>
        <tr>
            <td style="width:52%"><?=rfi_h($c['name'])?></td>
            <td style="width:8%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
            <td><?=rfi_h($c['detail'])?></td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
    <h2 class="bad">STOPPED SAFELY</h2>
    <?php foreach ($errors as $e): ?>
        <div class="bad">• <?=rfi_h($e)?></div>
    <?php endforeach; ?>
</div>
<?php elseif (!$installed): ?>
<div class="card">
    <h2>Ready to Install on TESTPHP8</h2>
    <form method="post">
        <button type="submit" name="install" value="1">INSTALL REAL REPLACEMENT PICK FLOW</button>
    </form>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card success">
    <h2 class="ok">INSTALL COMPLETE</h2>

    <div><strong>Backup folder:</strong><br><code><?=rfi_h($backupDir)?></code></div>

    <table style="margin-top:9px">
    <?php foreach ($postflight as $pf): ?>
        <tr>
            <td><?=rfi_h($pf[0])?></td>
            <td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td>
        </tr>
    <?php endforeach; ?>
    </table>

    <div class="note" style="margin-top:10px">
        Keep the scheduler OFF for the first inspection. Next we will verify the installed files/page behavior,
        then decide the safest way to manufacture one real pending JSON test without touching the DB.
    </div>

    <div class="note" style="margin-top:7px">
        After the entire RP flow is verified, sync TestPHP8 server → local with WinSCP, then commit/push GitHub.
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
