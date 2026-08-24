<?php
declare(strict_types=1);

/**
 * mrl_pick_window_messaging_refinement_installer.php
 *
 * VERSION: v001
 * LAST MODIFIED: 8/20/2026 2:33:24 pm
 *
 * PURPOSE:
 * Controlled TestPHP8 installer for the Pick Window / Form Messaging refinement.
 *
 * INSTALLS:
 * - admin_setup.php       v004
 * - pick_window_helper.php v003
 * - config_mrl.php        v003
 * - form-team-picks.php   v007
 * - team.php              v021
 *
 * DATABASE:
 * - Adds admin_setup.pickLeadAdjustOpenAt DATETIME NULL
 *
 * KEY CHANGES:
 * - Preserves whole-day lead-time behavior: N days means exactly N x 24 hours
 *   before the first race start, retaining the race-start clock time.
 * - Adds optional exact one-segment opening date/time.
 * - Adds NOW button for exact one-segment opening.
 * - Exact one-segment opening and integer lead-days are mutually exclusive.
 * - Temporary exact-date override remains highest priority and still controls
 *   both opening and deadline.
 * - admin_setup first row becomes:
 *      Current + Next Segment State | System Settings | Temporary Override
 * - form/team messaging becomes state-aware so an intentionally early normal
 *   window is not described as a Late Pick.
 * - Closed-between-segments message tells users when the next segment opens.
 * - Existing year-aware S4 naming remains untouched:
 *      2026+ = The Chase; earlier years = Playoffs.
 *
 * SAFETY:
 * - TESTPHP8 ONLY. Refuses Live MRL host.
 * - Requires known current source versions before installing.
 * - Creates file backups and an admin_setup schema/data SQL snapshot.
 * - Uses no shell/exec/php-lint dependency.
 * - Rolls files back and removes the new column if installation fails.
 * - PHP 7.3 compatible.
 */

session_start();
date_default_timezone_set('America/New_York');

$installerVersion = 'v001';
$installerName = 'MRL Pick Window / Messaging Refinement Installer';
$generatedAt = '8/20/2026 2:33:24 pm';

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$expectedHost = 'testphp8.manliusracingleague.com';

$checks = [];
$errors = [];
$installComplete = false;
$backupDir = '';
$postflight = [];

function ih($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function add_check(array &$checks, string $name, bool $ok, string $detail = ''): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}
function replace_once(string $src, string $old, string $new, string $label): string {
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}
function file_has(string $path, string $needle): bool {
    $src = @file_get_contents($path);
    return is_string($src) && strpos($src, $needle) !== false;
}
function atomic_write(string $path, string $content): void {
    $tmp = $path . '.mrl_tmp_' . uniqid('', true);
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary file for ' . basename($path) . '.');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace ' . basename($path) . '.');
    }
}
function backup_file(string $path, string $backupDir): void {
    if (!is_file($path)) {
        throw new RuntimeException('Backup source missing: ' . $path);
    }
    $dest = $backupDir . '/' . basename($path);
    if (!@copy($path, $dest)) {
        throw new RuntimeException('Unable to back up ' . basename($path) . '.');
    }
}
function restore_file(string $path, string $backupDir): void {
    $src = $backupDir . '/' . basename($path);
    if (is_file($src)) {
        @copy($src, $path);
    }
}
function column_exists(mysqli $db, string $table, string $column): bool {
    $stmt = mysqli_prepare($db, "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return is_array($row) && (int)$row['c'] > 0;
}
function table_exists(mysqli $db, string $table): bool {
    $stmt = mysqli_prepare($db, "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE='BASE TABLE'");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 's', $table);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return is_array($row) && (int)$row['c'] > 0;
}
function sql_value($v): string {
    if ($v === null) return 'NULL';
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
}
function backup_admin_setup_sql(mysqli $db, string $backupDir): void {
    $sql = "-- MRL admin_setup pre-install snapshot\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . " America/New_York\n\n";

    $res = mysqli_query($db, "SHOW CREATE TABLE admin_setup");
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $create = isset($row['Create Table']) ? $row['Create Table'] : (isset($row['Create View']) ? $row['Create View'] : '');
        if ($create !== '') {
            $sql .= "DROP TABLE IF EXISTS `admin_setup`;\n";
            $sql .= $create . ";\n\n";
        }
    }

    $res = mysqli_query($db, "SELECT * FROM admin_setup ORDER BY id");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cols = array_keys($row);
            $vals = [];
            foreach ($row as $v) $vals[] = sql_value($v);
            $quotedCols = array_map(function ($c) { return '`' . str_replace('`', '``', $c) . '`'; }, $cols);
            $sql .= "INSERT INTO `admin_setup` (" . implode(',', $quotedCols) . ") VALUES (" . implode(',', $vals) . ");\n";
        }
    }

    if (@file_put_contents($backupDir . '/admin_setup_before.sql', $sql, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write admin_setup_before.sql backup.');
    }
}

function patch_pick_window_helper(string $src): string {
    $src = replace_once($src, 'VERSION: v002', 'VERSION: v003', 'pick_window_helper header version');
    $src = replace_once($src, 'LAST MODIFIED: 8/19/2026 3:08:00 pm', 'LAST MODIFIED: 8/20/2026 2:33:24 pm', 'pick_window_helper timestamp');

    $old = " * CHANGELOG:\n * v002 (8/19/2026 3:08:00 pm)";
    $new = " * CHANGELOG:\n * v003 (8/20/2026 2:33:24 pm)\n"
         . " * - NEW: Optional exact one-segment opening timestamp via adjust_open_at.\n"
         . " * - NEW: Reports next chronological segment/open/deadline for UI messaging.\n"
         . " * - NEW: Reports lead mode/display so exact openings are not misrepresented as whole days.\n"
         . " * - PRESERVE: Integer lead-days still subtract exact whole days from first-race start time.\n"
         . " * - PRESERVE: Temporary exact-date override remains higher priority.\n"
         . " *\n"
         . " * v002 (8/19/2026 3:08:00 pm)";
    $src = replace_once($src, $old, $new, 'pick_window_helper changelog');

    $old = <<<'OLD'
        $adjustDays = ($adjustDaysRaw === null || $adjustDaysRaw === '')
            ? null
            : mrl_pick_window_normalize_days($adjustDaysRaw, $defaultDays);
OLD;
    $new = <<<'NEW'
        $adjustDays = ($adjustDaysRaw === null || $adjustDaysRaw === '')
            ? null
            : mrl_pick_window_normalize_days($adjustDaysRaw, $defaultDays);
        $adjustOpenRaw = trim((string)($settings['adjust_open_at'] ?? ''));
        $adjustOpenDt = $adjustOpenRaw !== ''
            ? mrl_pick_window_parse_db_datetime($adjustOpenRaw)
            : null;
NEW;
    $src = replace_once($src, $old, $new, 'pick_window_helper adjustment input');

    $old = <<<'OLD'
            $leadDays = $defaultDays;
            $leadSource = 'DEFAULT';
            if ($adjustYear === $year && $adjustSegment === $segment && $adjustDays !== null) {
                $leadDays = $adjustDays;
                $leadSource = 'SEGMENT_ADJUSTMENT';
            }

            $startDt = mrl_schedule_helper_race_datetime($race);
            $openDt = $startDt->sub(new DateInterval('P' . $leadDays . 'D'));

            $out[] = [
OLD;
    $new = <<<'NEW'
            $startDt = mrl_schedule_helper_race_datetime($race);
            $leadDays = $defaultDays;
            $leadSource = 'DEFAULT';
            $leadMode = 'DAYS';
            $openDt = $startDt->sub(new DateInterval('P' . $leadDays . 'D'));

            if ($adjustYear === $year && $adjustSegment === $segment) {
                if ($adjustOpenDt instanceof DateTimeImmutable && $adjustOpenDt < $startDt) {
                    $openDt = $adjustOpenDt;
                    $leadSource = 'SEGMENT_EXACT';
                    $leadMode = 'EXACT';
                    $leadDays = (int)round(($startDt->getTimestamp() - $openDt->getTimestamp()) / 86400);
                } elseif ($adjustDays !== null) {
                    $leadDays = $adjustDays;
                    $leadSource = 'SEGMENT_ADJUSTMENT';
                    $leadMode = 'DAYS';
                    $openDt = $startDt->sub(new DateInterval('P' . $leadDays . 'D'));
                }
            }

            $leadDisplay = $leadMode === 'EXACT'
                ? ('Exact: ' . mrl_pick_window_format_display($openDt))
                : ($leadDays . ' days');

            $out[] = [
NEW;
    $src = replace_once($src, $old, $new, 'pick_window_helper row calculation');

    $old = <<<'OLD'
                'lead_days' => $leadDays,
                'lead_source' => $leadSource,
                'default_days' => $defaultDays,
OLD;
    $new = <<<'NEW'
                'lead_days' => $leadDays,
                'lead_source' => $leadSource,
                'lead_mode' => $leadMode,
                'lead_display' => $leadDisplay,
                'default_days' => $defaultDays,
NEW;
    $src = replace_once($src, $old, $new, 'pick_window_helper row output');

    $old = <<<'OLD'
        $pickRow = $scoringRow;
OLD;
    $new = <<<'NEW'
        $scoringIndex = 0;
        foreach ($segments as $idx => $row) {
            if ($row['segment'] === $scoringRow['segment']) {
                $scoringIndex = (int)$idx;
                break;
            }
        }
        $nextRow = isset($segments[$scoringIndex + 1]) ? $segments[$scoringIndex + 1] : null;

        $pickRow = $scoringRow;
NEW;
    $src = replace_once($src, $old, $new, 'pick_window_helper next segment');

    $old = <<<'OLD'
        $effectiveLeadDays = (int)$pickRow['lead_days'];
        $leadSource = (string)$pickRow['lead_source'];
OLD;
    $new = <<<'NEW'
        $effectiveLeadDays = (int)$pickRow['lead_days'];
        $leadSource = (string)$pickRow['lead_source'];
        $leadMode = (string)($pickRow['lead_mode'] ?? 'DAYS');
        $leadDisplay = (string)($pickRow['lead_display'] ?? ($effectiveLeadDays . ' days'));
NEW;
    $src = replace_once($src, $old, $new, 'pick_window_helper state lead setup');

    $old = <<<'OLD'
                $effectiveLeadDays = (int)$requestedRow['lead_days'];
                $leadSource = (string)$requestedRow['lead_source'];
                $source = 'OVERRIDE';
OLD;
    $new = <<<'NEW'
                $effectiveLeadDays = (int)$requestedRow['lead_days'];
                $leadSource = (string)$requestedRow['lead_source'];
                $leadMode = (string)($requestedRow['lead_mode'] ?? 'DAYS');
                $leadDisplay = (string)($requestedRow['lead_display'] ?? ($effectiveLeadDays . ' days'));
                $source = 'OVERRIDE';
NEW;
    $src = replace_once($src, $old, $new, 'pick_window_helper override lead');

    $old = <<<'OLD'
            'effective_lead_days' => $effectiveLeadDays,
            'lead_source' => $leadSource,
            'segments' => $segments,
OLD;
    $new = <<<'NEW'
            'effective_lead_days' => $effectiveLeadDays,
            'lead_source' => $leadSource,
            'effective_lead_mode' => $leadMode,
            'effective_lead_display' => $leadDisplay,
            'next_segment' => is_array($nextRow) ? (string)$nextRow['segment'] : '',
            'next_segment_label' => is_array($nextRow) ? mrl_pick_window_segment_label($year, (string)$nextRow['segment']) : '',
            'next_start_race' => is_array($nextRow) ? (int)$nextRow['start_race'] : 0,
            'next_window_open_dt' => is_array($nextRow) ? $nextRow['open_dt'] : null,
            'next_deadline_dt' => is_array($nextRow) ? $nextRow['deadline_dt'] : null,
            'next_window_open_display' => is_array($nextRow) ? mrl_pick_window_format_display($nextRow['open_dt']) : '',
            'next_deadline_display' => is_array($nextRow) ? mrl_pick_window_format_display($nextRow['deadline_dt']) : '',
            'next_lead_source' => is_array($nextRow) ? (string)$nextRow['lead_source'] : '',
            'next_lead_mode' => is_array($nextRow) ? (string)($nextRow['lead_mode'] ?? 'DAYS') : '',
            'next_lead_display' => is_array($nextRow) ? (string)($nextRow['lead_display'] ?? '') : '',
            'segments' => $segments,
NEW;
    $src = replace_once($src, $old, $new, 'pick_window_helper state output');
    return $src;
}

function patch_config_mrl(string $src): string {
    $src = replace_once($src, 'VERSION: v002', 'VERSION: v003', 'config_mrl header version');
    $src = replace_once($src, 'LAST MODIFIED: 8/19/2026 3:08:00 pm', 'LAST MODIFIED: 8/20/2026 2:33:24 pm', 'config_mrl timestamp');

    $old = " * CHANGELOG:\n * v002 (8/19/2026 3:08:00 pm)";
    $new = " * CHANGELOG:\n * v003 (8/20/2026 2:33:24 pm)\n"
         . " * - NEW: Reads optional exact one-segment opening timestamp.\n"
         . " * - NEW: Exposes next-segment state/open/deadline for team/admin messaging.\n"
         . " * - NEW: Exposes lead mode/display for exact-vs-days UI.\n"
         . " * - PRESERVE: Existing automatic, LP/RD compatibility and year-aware segment naming.\n"
         . " *\n"
         . " * v002 (8/19/2026 3:08:00 pm)";
    $src = replace_once($src, $old, $new, 'config_mrl changelog');

    $src = replace_once(
        $src,
        "\$pickLeadAdjustDays = null;\n",
        "\$pickLeadAdjustDays = null;\n\$pickLeadAdjustOpenAt = '';\n\$pickWindowLeadMode = 'DAYS';\n\$pickWindowLeadDisplay = '15 days';\n\$nextSegment = '';\n\$nextSegmentName = '';\n\$nextSegmentStartRace = 0;\n\$nextPickWindowOpenAt = '';\n\$nextPickDeadlineAt = '';\n\$nextPickLeadSource = '';\n\$nextPickLeadMode = '';\n\$nextPickLeadDisplay = '';\n",
        'config_mrl defaults'
    );

    $src = replace_once(
        $src,
        "            pickLeadAdjustSegment,\n            pickLeadAdjustDays\n",
        "            pickLeadAdjustSegment,\n            pickLeadAdjustDays,\n            pickLeadAdjustOpenAt\n",
        'config_mrl select column'
    );

    $old = <<<'OLD'
    $pickLeadAdjustDays = isset($adminSetupRow['pickLeadAdjustDays']) && $adminSetupRow['pickLeadAdjustDays'] !== null
        ? (int)$adminSetupRow['pickLeadAdjustDays']
        : null;
OLD;
    $new = <<<'NEW'
    $pickLeadAdjustDays = isset($adminSetupRow['pickLeadAdjustDays']) && $adminSetupRow['pickLeadAdjustDays'] !== null
        ? (int)$adminSetupRow['pickLeadAdjustDays']
        : null;
    $pickLeadAdjustOpenAt = trim((string)($adminSetupRow['pickLeadAdjustOpenAt'] ?? ''));
NEW;
    $src = replace_once($src, $old, $new, 'config_mrl adjustment read');

    $src = replace_once(
        $src,
        "            'adjust_days' => \$pickLeadAdjustDays,\n",
        "            'adjust_days' => \$pickLeadAdjustDays,\n            'adjust_open_at' => \$pickLeadAdjustOpenAt,\n",
        'config_mrl helper settings'
    );

    $old = <<<'OLD'
        $pickWindowEffectiveDays = (int)($pickWindowState['effective_lead_days'] ?? $pickWindowDefaultDays);
        $pickWindowLeadSource = (string)($pickWindowState['lead_source'] ?? 'DEFAULT');
        $formLockDate = $pickDeadlineAt;
OLD;
    $new = <<<'NEW'
        $pickWindowEffectiveDays = (int)($pickWindowState['effective_lead_days'] ?? $pickWindowDefaultDays);
        $pickWindowLeadSource = (string)($pickWindowState['lead_source'] ?? 'DEFAULT');
        $pickWindowLeadMode = (string)($pickWindowState['effective_lead_mode'] ?? 'DAYS');
        $pickWindowLeadDisplay = (string)($pickWindowState['effective_lead_display'] ?? ($pickWindowEffectiveDays . ' days'));

        $nextSegment = (string)($pickWindowState['next_segment'] ?? '');
        $nextSegmentName = (string)($pickWindowState['next_segment_label'] ?? '');
        $nextSegmentStartRace = (int)($pickWindowState['next_start_race'] ?? 0);
        $nextPickWindowOpenAt = (string)($pickWindowState['next_window_open_display'] ?? '');
        $nextPickDeadlineAt = (string)($pickWindowState['next_deadline_display'] ?? '');
        $nextPickLeadSource = (string)($pickWindowState['next_lead_source'] ?? '');
        $nextPickLeadMode = (string)($pickWindowState['next_lead_mode'] ?? '');
        $nextPickLeadDisplay = (string)($pickWindowState['next_lead_display'] ?? '');

        $formLockDate = $pickDeadlineAt;
NEW;
    $src = replace_once($src, $old, $new, 'config_mrl state expose');
    return $src;
}

function patch_form_team_picks(string $src): string {
    $src = replace_once($src, 'VERSION: v006', 'VERSION: v007', 'form-team-picks header version');
    $src = replace_once($src, 'LAST MODIFIED: 8/19/2026 4:51:53 am', 'LAST MODIFIED: 8/20/2026 2:33:24 pm', 'form-team-picks timestamp');
    $src = replace_once($src, "\$formVersion = 'v006';", "\$formVersion = 'v007';", 'form-team-picks internal version');

    $old = " * CHANGELOG:\n *\n * v006 (8/19/2026 4:51:53 am)";
    $new = " * CHANGELOG:\n *\n"
         . " * v007 (8/20/2026 2:33:24 pm)\n"
         . " * - FIX: An intentionally early normal pick window no longer displays Late Pick wording.\n"
         . " * - CHANGE: Normal form notes now describe the active normal window and deadline directly.\n"
         . " * - CHANGE: Genuine LP wording remains tied to LP mode only when the normal window is closed.\n"
         . " * - PRESERVE: Driver availability, editing, submission, LP effective-race and dropdown behavior.\n"
         . " *\n"
         . " * v006 (8/19/2026 4:51:53 am)";
    $src = replace_once($src, $old, $new, 'form-team-picks changelog');

    $old = <<<'OLD'
$headerLine1 = "** Dropdown will only show drivers available to add to your team. **";
$headerLine2 = "Picks for " . $activeRaceYear . " " . $activeSegmentLabel . " due by " . $activeLockDate . ". When you click 'Submit Picks', they will be entered into our database, and appear in chart above.";

if (isset($teamFormMode) && $teamFormMode === 'LP' && function_exists('mrl_get_effective_race_for_lp')) {
OLD;
    $new = <<<'NEW'
$headerLine1 = "Only drivers currently available for your team are shown.";
$headerLine2 = $activeRaceYear . " " . $activeSegmentLabel
    . " picks are open. Deadline: " . $activeLockDate
    . ". Submit Picks to save or update your team.";

$normalWindowIsOpen = isset($pickWindowIsOpen) ? (bool)$pickWindowIsOpen : false;
$displayAsLatePick = isset($teamFormMode) && $teamFormMode === 'LP' && !$normalWindowIsOpen;

if ($displayAsLatePick && function_exists('mrl_get_effective_race_for_lp')) {
NEW;
    $src = replace_once($src, $old, $new, 'form-team-picks state-aware header');

    $old = <<<'OLD'
            $headerLine2 = "Late picks for " . $activeRaceYear . " " . $activeSegmentLabel
                . " become effective with " . $lpRaceCode
                . ($lpDeadline !== '' ? ". New deadline: " . $lpDeadline : '')
                . ". You may change these picks until that race starts.";
OLD;
    $new = <<<'NEW'
            $headerLine2 = "Late Pick — " . $activeRaceYear . " " . $activeSegmentLabel
                . ". Effective with " . $lpRaceCode
                . ($lpDeadline !== '' ? ". Deadline: " . $lpDeadline : '')
                . ". You may change these picks until that race starts.";
NEW;
    $src = replace_once($src, $old, $new, 'form-team-picks LP wording');
    return $src;
}

function patch_team(string $src): string {
    $src = replace_once($src, 'VERSION: v020', 'VERSION: v021', 'team header version');
    $src = replace_once($src, 'LAST MODIFIED: 8/19/2026 7:12:00 pm', 'LAST MODIFIED: 8/20/2026 2:33:24 pm', 'team timestamp');

    $old = " * CHANGELOG:\n *\n * v020 (8/19/2026 7:12:00 pm)";
    $new = " * CHANGELOG:\n *\n"
         . " * v021 (8/20/2026 2:33:24 pm)\n"
         . " * - CHANGE: Pick-window closed/open messaging now follows shared automatic state.\n"
         . " * - NEW: Closed-between-segments message tells users when the next segment opens.\n"
         . " * - FIX: Early normal windows are treated/displayed as normal picks, not LP messaging.\n"
         . " * - PRESERVE: LP, SPECIAL_AUTH, RD routing and current-segment chart behavior.\n"
         . " *\n"
         . " * v020 (8/19/2026 7:12:00 pm)";
    $src = replace_once($src, $old, $new, 'team changelog');

    $old = <<<'OLD'
            } else {

                // This only occurs before the first segment's automatic window opens,
                // or when an admin override deliberately schedules a future opening.
                // For an in-progress segment the deadline is already past, so LP/RD
                // routing below remains unchanged.
                if ($end_ts !== false && $user_ts < $end_ts && !$normalPickWindowOpen) {
                    $openText = isset($pickWindowOpenAt) && trim((string)$pickWindowOpenAt) !== ''
                        ? (string)$pickWindowOpenAt
                        : 'the scheduled opening time';
                    echo "Normal picks for " . teampage_h((string)$raceYear) . " " . teampage_h((string)$segmentName)
                        . " open on " . teampage_h($openText) . ".";
                } else {
                    if ($showRdWrapper) {
                        include 'team_replacement_driver.php';
                    } elseif ($teamFormMode === 'LP' || $teamFormMode === 'SPECIAL_AUTH') {
                        include 'team-late-pick.php';
                    } else {
                        echo teampage_h((string)$formLockedMessage) . " - past Lock date of " . teampage_h((string)$formLockDate);
                        echo "<br><br>";
                        include 'current_segment_chart.php';
                    }
                }

            }
OLD;
    $new = <<<'NEW'
            } else {

                // If the active normal pick segment has not opened yet, explain when it opens.
                if (isset($pickWindowStatus) && $pickWindowStatus === 'CLOSED_BEFORE_OPEN') {
                    $openText = isset($pickWindowOpenAt) && trim((string)$pickWindowOpenAt) !== ''
                        ? (string)$pickWindowOpenAt
                        : 'the scheduled opening time';
                    echo teampage_h((string)$raceYear) . " " . teampage_h((string)$segmentName)
                        . " picks open on " . teampage_h($openText) . ".";
                } else {
                    if ($showRdWrapper) {
                        include 'team_replacement_driver.php';
                    } elseif ($teamFormMode === 'LP' || $teamFormMode === 'SPECIAL_AUTH') {
                        include 'team-late-pick.php';
                    } else {
                        $closedSegmentLabel = isset($scoringSegmentName) && trim((string)$scoringSegmentName) !== ''
                            ? (string)$scoringSegmentName
                            : (string)$segmentName;

                        echo teampage_h((string)$raceYear) . " " . teampage_h($closedSegmentLabel) . " picks are closed.";

                        if (isset($nextSegment) && trim((string)$nextSegment) !== ''
                            && isset($nextSegmentName) && trim((string)$nextSegmentName) !== ''
                            && isset($nextPickWindowOpenAt) && trim((string)$nextPickWindowOpenAt) !== '') {
                            echo " " . teampage_h((string)$raceYear) . " " . teampage_h((string)$nextSegmentName)
                                . " picks open on " . teampage_h((string)$nextPickWindowOpenAt) . ".";
                        }

                        echo "<br><br>";
                        include 'current_segment_chart.php';
                    }
                }

            }
NEW;
    $src = replace_once($src, $old, $new, 'team state message routing');
    return $src;
}

function patch_admin_setup(string $src): string {
    $src = replace_once($src, 'VERSION: v003', 'VERSION: v004', 'admin_setup header version');
    $src = replace_once($src, 'LAST MODIFIED: 8/19/2026 3:30:00 pm', 'LAST MODIFIED: 8/20/2026 2:33:24 pm', 'admin_setup timestamp');

    $old = " * CHANGELOG:\n * v003 (8/19/2026 3:30:00 pm)";
    $new = " * CHANGELOG:\n"
         . " * v004 (8/20/2026 2:33:24 pm)\n"
         . " * - UI: Top row reordered to Current + Next Segment State / System Settings / Temporary Override.\n"
         . " * - NEW: Current-state panel also shows the next chronological segment and its opening/deadline.\n"
         . " * - NEW: One-segment adjustment may use whole lead-days OR an exact opening date/time.\n"
         . " * - NEW: NOW button fills exact opening with current browser local time and clears lead-days.\n"
         . " * - PRESERVE: Whole lead-days remain exact day offsets from first-race start time.\n"
         . " * - PRESERVE: Temporary override remains highest-priority opening/deadline control.\n"
         . " *\n"
         . " * v003 (8/19/2026 3:30:00 pm)";
    $src = replace_once($src, $old, $new, 'admin_setup changelog');

    $old = <<<'OLD'
    if ($action === 'save_lead_adjustment') {
        $adjustYear = (int)($_POST['pickLeadAdjustYear'] ?? $raceYear);
        $adjustSegment = strtoupper(trim((string)($_POST['pickLeadAdjustSegment'] ?? '')));
        $adjustDays = valid_days($_POST['pickLeadAdjustDays'] ?? null);
        if (!in_array($adjustSegment, $segments, true) || $adjustDays === null) {
            header('Location: /admin_setup.php?msg=' . urlencode('Segment adjustment not saved: choose a valid segment and 0-90 days.'));
            exit;
        }
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickLeadAdjustYear=?, pickLeadAdjustSegment=?, pickLeadAdjustDays=?, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'isiis', $adjustYear, $adjustSegment, $adjustDays, $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('One-segment lead-time adjustment saved.'));
        exit;
    }

    if ($action === 'clear_lead_adjustment') {
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickLeadAdjustYear=NULL, pickLeadAdjustSegment=NULL, pickLeadAdjustDays=NULL, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'is', $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Segment lead-time adjustment cleared; default lead time applies.'));
        exit;
    }
OLD;
    $new = <<<'NEW'
    if ($action === 'save_lead_adjustment') {
        $adjustYear = (int)($_POST['pickLeadAdjustYear'] ?? $raceYear);
        $adjustSegment = strtoupper(trim((string)($_POST['pickLeadAdjustSegment'] ?? '')));
        $adjustDaysRaw = trim((string)($_POST['pickLeadAdjustDays'] ?? ''));
        $adjustOpenRaw = trim((string)($_POST['pickLeadAdjustOpenAt'] ?? ''));

        $adjustDays = $adjustDaysRaw === '' ? null : valid_days($adjustDaysRaw);
        $adjustOpenDb = null;

        if (!in_array($adjustSegment, $segments, true)) {
            header('Location: /admin_setup.php?msg=' . urlencode('Segment adjustment not saved: choose a valid segment.'));
            exit;
        }
        if ($adjustOpenRaw === '' && $adjustDays === null) {
            header('Location: /admin_setup.php?msg=' . urlencode('Segment adjustment not saved: enter lead days or an exact opening date/time.'));
            exit;
        }
        if ($adjustOpenRaw !== '') {
            $openTs = strtotime($adjustOpenRaw);
            if ($openTs === false) {
                header('Location: /admin_setup.php?msg=' . urlencode('Segment adjustment not saved: exact opening date/time is invalid.'));
                exit;
            }

            $targetStartTs = 0;
            try {
                $validationRows = mrl_pick_window_segment_rows($adjustYear, [
                    'default_days' => (int)($pickWindowDefaultDays ?? 15),
                    'adjust_year' => 0,
                    'adjust_segment' => '',
                    'adjust_days' => null,
                    'adjust_open_at' => '',
                ]);
                foreach ($validationRows as $validationRow) {
                    if ((string)$validationRow['segment'] === $adjustSegment) {
                        $targetStartTs = $validationRow['start_dt']->getTimestamp();
                        break;
                    }
                }
            } catch (Throwable $e) {
                $targetStartTs = 0;
            }

            if ($targetStartTs <= 0 || $openTs >= $targetStartTs) {
                header('Location: /admin_setup.php?msg=' . urlencode('Segment adjustment not saved: exact opening must be before that segment first-race start.'));
                exit;
            }

            $adjustOpenDb = date('Y-m-d H:i:s', $openTs);
            $adjustDays = null;
        }

        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickLeadAdjustYear=?, pickLeadAdjustSegment=?, pickLeadAdjustDays=?, pickLeadAdjustOpenAt=?, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'isisis', $adjustYear, $adjustSegment, $adjustDays, $adjustOpenDb, $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode($adjustOpenDb !== null ? 'Exact one-segment opening saved.' : 'One-segment lead-time adjustment saved.'));
        exit;
    }

    if ($action === 'clear_lead_adjustment') {
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickLeadAdjustYear=NULL, pickLeadAdjustSegment=NULL, pickLeadAdjustDays=NULL, pickLeadAdjustOpenAt=NULL, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'is', $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Segment opening adjustment cleared; default lead time applies.'));
        exit;
    }
NEW;
    $src = replace_once($src, $old, $new, 'admin_setup adjustment actions');

    $src = replace_once(
        $src,
        "    'adjust_days' => \$current['pickLeadAdjustDays'] ?? null,\n",
        "    'adjust_days' => \$current['pickLeadAdjustDays'] ?? null,\n    'adjust_open_at' => \$current['pickLeadAdjustOpenAt'] ?? '',\n",
        'admin_setup settings exact'
    );

    $old = <<<'OLD'
$adjustDaysDisplay = isset($current['pickLeadAdjustDays']) && $current['pickLeadAdjustDays'] !== null ? (string)$current['pickLeadAdjustDays'] : '';
$adjustActive = isset($current['pickLeadAdjustYear'], $current['pickLeadAdjustSegment'], $current['pickLeadAdjustDays'])
    && $current['pickLeadAdjustYear'] !== null && trim((string)$current['pickLeadAdjustSegment']) !== '' && $current['pickLeadAdjustDays'] !== null;
OLD;
    $new = <<<'NEW'
$adjustDaysDisplay = isset($current['pickLeadAdjustDays']) && $current['pickLeadAdjustDays'] !== null ? (string)$current['pickLeadAdjustDays'] : '';
$adjustOpenDisplay = trim((string)($current['pickLeadAdjustOpenAt'] ?? ''));
$adjustActive = isset($current['pickLeadAdjustYear'], $current['pickLeadAdjustSegment'])
    && $current['pickLeadAdjustYear'] !== null
    && trim((string)$current['pickLeadAdjustSegment']) !== ''
    && ($current['pickLeadAdjustDays'] !== null || $adjustOpenDisplay !== '');
NEW;
    $src = replace_once($src, $old, $new, 'admin_setup adjustment display vars');

    $old = <<<'OLD'
.top-grid{display:grid;grid-template-columns:1.04fr 1.18fr .95fr;gap:10px;align-items:start}.card{border:1px solid #4d473f;border-radius:14px;background:linear-gradient(180deg,#222,#1b1b1b);padding:12px 14px;box-shadow:0 2px 8px rgba(0,0,0,.22)}
OLD;
    $new = <<<'NEW'
.top-grid{display:grid;grid-template-columns:1.12fr 1.15fr 1fr;gap:10px;align-items:start}.card{border:1px solid #4d473f;border-radius:14px;background:linear-gradient(180deg,#222,#1b1b1b);padding:12px 14px;box-shadow:0 2px 8px rgba(0,0,0,.22)}
NEW;
    $src = replace_once($src, $old, $new, 'admin_setup grid proportions');

    $start = strpos($src, '<div class="top-grid">');
    $end = strpos($src, '</div>' . "\n\n<div class=\"section-grid\">", $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('admin_setup top-grid block not found.');
    }
    $end += strlen('</div>');
    $oldBlock = substr($src, $start, $end - $start);

    $newBlock = <<<'NEWBLOCK'
<div class="top-grid">
<section class="card">
<h2>Current + Next Segment State</h2>
<div class="rows">
<div class="kv"><div class="k">Source</div><div class="v strong <?php echo $pickWindowSource==='AUTO'?'goodtxt':'warntxt'; ?>"><?php echo ah($pickWindowSource); ?></div></div>
<div class="kv"><div class="k">Scoring</div><div class="v"><?php echo ah($scoringSegment . ' — ' . $scoringSegmentName); ?></div></div>
<div class="kv"><div class="k">Pick Segment</div><div class="v strong"><?php echo ah($pickSegment . ' — ' . $pickSegmentName); ?></div></div>
<div class="kv"><div class="k">Lead</div><div class="v"><?php echo ah((string)$pickWindowLeadDisplay); ?> <span class="muted">(<?php echo ah($pickWindowLeadSource); ?>)</span></div></div>
<div class="kv"><div class="k">Opens</div><div class="v"><?php echo ah($pickWindowOpenAt); ?></div></div>
<div class="kv"><div class="k">Deadline</div><div class="v"><?php echo ah($pickDeadlineAt); ?></div></div>
<div class="kv"><div class="k">Master Lock</div><div class="v <?php echo $formLocked==='yes'?'badtxt':'goodtxt'; ?>"><?php echo ah(strtoupper($formLocked)); ?></div></div>
</div>
<div class="lead-adjust">
<h3>Next Segment</h3>
<?php if(trim((string)$nextSegment)!==''): ?>
<div class="rows">
<div class="kv"><div class="k">Segment</div><div class="v strong"><?php echo ah($nextSegment . ' — ' . $nextSegmentName); ?></div></div>
<div class="kv"><div class="k">First Race</div><div class="v"><?php echo $nextSegmentStartRace>0 ? 'R' . ah((string)$nextSegmentStartRace) : '—'; ?></div></div>
<div class="kv"><div class="k">Lead</div><div class="v"><?php echo ah($nextPickLeadDisplay); ?><?php if($nextPickLeadSource!==''): ?> <span class="muted">(<?php echo ah($nextPickLeadSource); ?>)</span><?php endif; ?></div></div>
<div class="kv"><div class="k">Opens</div><div class="v"><?php echo ah($nextPickWindowOpenAt); ?></div></div>
<div class="kv"><div class="k">Deadline</div><div class="v"><?php echo ah($nextPickDeadlineAt); ?></div></div>
</div>
<?php else: ?><span class="pill good">No later segment in this year</span><?php endif; ?>
</div>
<?php if($pickWindowError!==''): ?><p class="note badtxt">Pick-window warning: <?php echo ah($pickWindowError); ?></p><?php endif; ?>
</section>

<section class="card">
<h2>System Settings</h2>
<form method="post">
<div class="inline2"><div class="field"><label>Race Year</label><select name="raceYear" class="control"><?php foreach($years as $y): ?><option value="<?php echo $y; ?>" <?php echo ((int)$raceYear===$y)?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div><div class="field"><label>Master Form Lock</label><select name="formLocked" class="control"><option value="no" <?php echo $formLocked!=='yes'?'selected':''; ?>>No</option><option value="yes" <?php echo $formLocked==='yes'?'selected':''; ?>>Yes</option></select></div></div>
<div class="field"><label>Default Pick Window Lead Time (whole days)</label><input type="number" min="0" max="90" name="pickWindowDefaultDays" class="control" value="<?php echo ah((string)($current['pickWindowDefaultDays'] ?? 15)); ?>"></div>
<div class="actions"><button class="btn primary" name="action" value="save_system">Save System Settings</button><button class="btn" name="action" value="add_year">Add <?php echo $nextYear; ?></button></div>
</form>

<div class="lead-adjust">
<h3>One-Segment Opening Adjustment</h3>
<div class="status-line">
<?php if($adjustActive): ?>
<?php if($adjustOpenDisplay!==''): ?><span class="pill warn"><?php echo ah((string)$current['pickLeadAdjustYear'] . ' ' . (string)$current['pickLeadAdjustSegment'] . ': exact ' . adt($adjustOpenDisplay)); ?></span>
<?php else: ?><span class="pill warn"><?php echo ah((string)$current['pickLeadAdjustYear'] . ' ' . (string)$current['pickLeadAdjustSegment'] . ': ' . (string)$current['pickLeadAdjustDays'] . ' days'); ?></span><?php endif; ?>
<?php else: ?><span class="pill good">None — default applies</span><?php endif; ?>
</div>
<form method="post" id="leadAdjustForm">
<input type="hidden" name="pickLeadAdjustYear" value="<?php echo ah((string)$adjustYearDisplay); ?>">
<div class="field"><label>Segment</label><select name="pickLeadAdjustSegment" class="control"><?php foreach($segments as $s): ?><option value="<?php echo ah($s); ?>" <?php echo $adjustSegmentDisplay===$s?'selected':''; ?>><?php echo ah($s); ?></option><?php endforeach; ?></select></div>
<div class="inline2">
<div class="field"><label>Lead Time (whole days)</label><input id="pickLeadAdjustDays" type="number" min="0" max="90" name="pickLeadAdjustDays" class="control" value="<?php echo ah($adjustDaysDisplay); ?>" placeholder="e.g. 15"></div>
<div class="field"><label>Exact Opening Date / Time</label><input id="pickLeadAdjustOpenAt" type="datetime-local" name="pickLeadAdjustOpenAt" class="control" value="<?php echo ah(adtlocal($adjustOpenDisplay)); ?>"></div>
</div>
<div class="actions"><button type="button" class="btn good" id="setLeadNow">NOW</button><button class="btn primary" name="action" value="save_lead_adjustment">Save Segment Adjustment</button><button class="btn" name="action" value="clear_lead_adjustment">Clear Adjustment</button></div>
</form>
<p class="note">Use either whole days or an exact opening. Whole days preserve the first-race clock time. Exact opening wins if entered. NOW fills the exact opening with the current time.</p>
</div>
</section>

<section class="card">
<h2>Temporary Pick Window Override</h2>
<form method="post">
<div class="inline2"><div class="field"><label>Enabled</label><select name="pickOverrideEnabled" class="control"><option value="no" <?php echo !$overrideEnabled?'selected':''; ?>>No</option><option value="yes" <?php echo $overrideEnabled?'selected':''; ?>>Yes</option></select></div><div class="field"><label>Pick Segment</label><select name="pickOverrideSegment" class="control"><?php foreach($segments as $s): ?><option value="<?php echo ah($s); ?>" <?php echo $defaultOverrideSegment===$s?'selected':''; ?>><?php echo ah($s); ?></option><?php endforeach; ?></select></div></div>
<div class="field"><label>Override Opens</label><input type="datetime-local" name="pickOverrideOpenAt" class="control" value="<?php echo ah(adtlocal($defaultOverrideOpen)); ?>"></div>
<div class="field"><label>Override Deadline</label><input type="datetime-local" name="pickOverrideDeadlineAt" class="control" value="<?php echo ah(adtlocal($defaultOverrideDeadline)); ?>"></div>
<div class="actions"><button class="btn primary" name="action" value="save_override">Save Override</button><button class="btn danger" name="action" value="disable_override">Disable / Return to AUTO</button></div>
</form>
<p class="note">Emergency/manual control. Exact-date override has highest priority and affects pick state only. Scoring stays schedule-derived.</p>
</section>
</div>
NEWBLOCK;
    $src = substr($src, 0, $start) . $newBlock . substr($src, $end);

    $old = <<<'OLD'
<script>setTimeout(function(){var e=document.querySelector('.flash');if(e)e.style.display='none';},2800);</script>
OLD;
    $new = <<<'NEW'
<script>
setTimeout(function(){var e=document.querySelector('.flash');if(e)e.style.display='none';},2800);

(function(){
    var days = document.getElementById('pickLeadAdjustDays');
    var exact = document.getElementById('pickLeadAdjustOpenAt');
    var nowBtn = document.getElementById('setLeadNow');

    function newYorkDateTimeValue(){
        try {
            var text = new Intl.DateTimeFormat('sv-SE', {
                timeZone: 'America/New_York',
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', hour12: false
            }).format(new Date());
            return text.replace(' ', 'T').slice(0, 16);
        } catch (e) {
            var d = new Date();
            function pad(n){ return String(n).padStart(2,'0'); }
            return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate())
                + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }
    }

    if(days && exact){
        days.addEventListener('input', function(){
            if(days.value !== '') exact.value = '';
        });
        exact.addEventListener('input', function(){
            if(exact.value !== '') days.value = '';
        });
    }
    if(nowBtn && exact && days){
        nowBtn.addEventListener('click', function(){
            exact.value = newYorkDateTimeValue();
            days.value = '';
        });
    }
})();
</script>
NEW;
    $src = replace_once($src, $old, $new, 'admin_setup NOW javascript');
    return $src;
}

$paths = [
    'admin_setup.php' => $root . '/admin_setup.php',
    'pick_window_helper.php' => $root . '/pick_window_helper.php',
    'config_mrl.php' => $root . '/config_mrl.php',
    'form-team-picks.php' => $root . '/form-team-picks.php',
    'team.php' => $root . '/team.php',
    'config.php' => $root . '/config.php',
];

add_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host !== '' ? $host : '(unknown)');
add_check($checks, 'Document root available', $root !== '' && is_dir($root), $root);

foreach ($paths as $label => $path) {
    add_check($checks, $label . ' exists', is_file($path), $path);
}

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: this installer is TestPHP8-only. Current host: ' . ($host !== '' ? $host : '(unknown)');
}
if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root is unavailable.';
}

if (!$errors) {
    require_once $paths['config.php'];
    if (!isset($dbconnect) || !($dbconnect instanceof mysqli)) {
        $errors[] = 'mysqli database connection is unavailable from config.php.';
    }
}

if (!$errors) {
    add_check($checks, 'admin_setup table exists', table_exists($dbconnect, 'admin_setup'));
    add_check($checks, 'New exact-open column absent', !column_exists($dbconnect, 'admin_setup', 'pickLeadAdjustOpenAt'));
    if (!table_exists($dbconnect, 'admin_setup')) $errors[] = 'admin_setup table not found.';
    if (column_exists($dbconnect, 'admin_setup', 'pickLeadAdjustOpenAt')) $errors[] = 'pickLeadAdjustOpenAt already exists; installer may already have been applied.';

    $requiredColumns = [
        'id','raceYear','formLocked','pickWindowDefaultDays',
        'pickLeadAdjustYear','pickLeadAdjustSegment','pickLeadAdjustDays',
        'pickOverrideEnabled','pickOverrideSegment','pickOverrideOpenAt','pickOverrideDeadlineAt'
    ];
    foreach ($requiredColumns as $col) {
        $ok = column_exists($dbconnect, 'admin_setup', $col);
        add_check($checks, 'admin_setup.' . $col, $ok);
        if (!$ok) $errors[] = 'Required admin_setup column missing: ' . $col;
    }
}

$versionExpect = [
    'admin_setup.php' => 'VERSION: v003',
    'pick_window_helper.php' => 'VERSION: v002',
    'config_mrl.php' => 'VERSION: v002',
    'form-team-picks.php' => 'VERSION: v006',
    'team.php' => 'VERSION: v020',
];

if (!$errors) {
    foreach ($versionExpect as $label => $marker) {
        $ok = file_has($paths[$label], $marker);
        add_check($checks, $label . ' expected source', $ok, $marker);
        if (!$ok) $errors[] = $label . ' is not the expected current source (' . $marker . ').';
    }
}

$prepared = [];
if (!$errors) {
    try {
        $prepared['admin_setup.php'] = patch_admin_setup((string)file_get_contents($paths['admin_setup.php']));
        $prepared['pick_window_helper.php'] = patch_pick_window_helper((string)file_get_contents($paths['pick_window_helper.php']));
        $prepared['config_mrl.php'] = patch_config_mrl((string)file_get_contents($paths['config_mrl.php']));
        $prepared['form-team-picks.php'] = patch_form_team_picks((string)file_get_contents($paths['form-team-picks.php']));
        $prepared['team.php'] = patch_team((string)file_get_contents($paths['team.php']));

        add_check($checks, 'All source transformations prepared', true, '5 files');
    } catch (Throwable $e) {
        $errors[] = 'Preflight transform failed: ' . $e->getMessage();
        add_check($checks, 'All source transformations prepared', false, $e->getMessage());
    }
}

$action = (string)($_POST['action'] ?? '');

if (!$errors && $action === 'install') {
    $backupStamp = date('Ymd_His');
    $backupDir = dirname($root) . '/mrl_pick_window_messaging_backup_' . $backupStamp;

    $columnAdded = false;
    $filesWritten = [];

    try {
        if (!@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Unable to create backup directory: ' . $backupDir);
        }

        foreach (['admin_setup.php','pick_window_helper.php','config_mrl.php','form-team-picks.php','team.php'] as $label) {
            backup_file($paths[$label], $backupDir);
        }
        backup_admin_setup_sql($dbconnect, $backupDir);

        if (!mysqli_query($dbconnect, "ALTER TABLE admin_setup ADD COLUMN pickLeadAdjustOpenAt DATETIME NULL AFTER pickLeadAdjustDays")) {
            throw new RuntimeException('ALTER TABLE failed: ' . mysqli_error($dbconnect));
        }
        $columnAdded = true;

        foreach ($prepared as $label => $content) {
            atomic_write($paths[$label], $content);
            $filesWritten[] = $label;
        }

        $postflight[] = ['name' => 'DB column installed', 'ok' => column_exists($dbconnect, 'admin_setup', 'pickLeadAdjustOpenAt')];
        $postflight[] = ['name' => 'admin_setup.php v004', 'ok' => file_has($paths['admin_setup.php'], 'VERSION: v004')];
        $postflight[] = ['name' => 'pick_window_helper.php v003', 'ok' => file_has($paths['pick_window_helper.php'], 'VERSION: v003')];
        $postflight[] = ['name' => 'config_mrl.php v003', 'ok' => file_has($paths['config_mrl.php'], 'VERSION: v003')];
        $postflight[] = ['name' => 'form-team-picks.php v007', 'ok' => file_has($paths['form-team-picks.php'], 'VERSION: v007') && file_has($paths['form-team-picks.php'], "\$formVersion = 'v007';")];
        $postflight[] = ['name' => 'team.php v021', 'ok' => file_has($paths['team.php'], 'VERSION: v021')];

        foreach ($postflight as $pf) {
            if (!$pf['ok']) {
                throw new RuntimeException('Postflight failed: ' . $pf['name']);
            }
        }

        $installComplete = true;
    } catch (Throwable $e) {
        foreach (['admin_setup.php','pick_window_helper.php','config_mrl.php','form-team-picks.php','team.php'] as $label) {
            restore_file($paths[$label], $backupDir);
        }
        if ($columnAdded && column_exists($dbconnect, 'admin_setup', 'pickLeadAdjustOpenAt')) {
            @mysqli_query($dbconnect, "ALTER TABLE admin_setup DROP COLUMN pickLeadAdjustOpenAt");
        }
        $errors[] = 'INSTALL FAILED AND ROLLBACK ATTEMPTED: ' . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?php echo ih($installerName); ?></title>
<style>
*{box-sizing:border-box} body{margin:0;background:#151515;color:#eee;font-family:Tahoma,Verdana,Arial,sans-serif;font-size:14px}
.wrap{width:94%;max-width:1180px;margin:18px auto}.banner{border:1px solid #9a7014;background:linear-gradient(180deg,#3a3118,#282417);border-radius:14px;padding:14px 18px;margin-bottom:12px}
h1{margin:0;color:#fff1cf;font-size:24px} .sub{color:#d7c49a;margin-top:4px}.card{border:1px solid #4d473f;background:#202020;border-radius:12px;padding:12px 14px;margin:10px 0}
h2{color:#ffd08a;font-size:18px;margin:0 0 9px}.ok{color:#62e89b}.bad{color:#ff8181}.warn{color:#ffd166}
table{width:100%;border-collapse:collapse}th,td{padding:6px 8px;border-bottom:1px solid #333;text-align:left}th{color:#cdbd9e}
.btn{font:15px Tahoma;border:1px solid #b18745;border-radius:9px;background:#3a2f1b;color:#ffd08a;padding:9px 14px;cursor:pointer}
code{color:#f6d99f}.small{font-size:12px;color:#aaa;line-height:1.4}.success{border-color:#286c48;background:#173526}
</style>
</head>
<body>
<div class="wrap">
<div class="banner">
<h1><?php echo ih($installerName); ?> <?php echo ih($installerVersion); ?></h1>
<div class="sub">TESTPHP8 ONLY • generated <?php echo ih($generatedAt); ?> • PHP 7.3-compatible installer</div>
</div>

<div class="card">
<h2>Preflight</h2>
<table>
<?php foreach($checks as $c): ?>
<tr><td style="width:34%"><?php echo ih($c['name']); ?></td><td class="<?php echo $c['ok']?'ok':'bad'; ?>"><?php echo $c['ok']?'PASS':'FAIL'; ?></td><td><?php echo ih($c['detail']); ?></td></tr>
<?php endforeach; ?>
</table>
</div>

<?php if($errors): ?>
<div class="card"><h2 class="bad">Stopped Safely</h2>
<?php foreach($errors as $e): ?><div class="bad">• <?php echo ih($e); ?></div><?php endforeach; ?>
<p class="small">No install action is available while preflight/errors exist.</p>
</div>
<?php endif; ?>

<?php if(!$errors && !$installComplete && $action !== 'install'): ?>
<div class="card">
<h2>Ready to Install</h2>
<p>This installer will:</p>
<ul>
<li>Create a timestamped backup folder beside <code>public_html</code>.</li>
<li>Back up the five affected PHP files.</li>
<li>Create <code>admin_setup_before.sql</code> containing the current admin_setup schema/data.</li>
<li>Add nullable <code>admin_setup.pickLeadAdjustOpenAt</code>.</li>
<li>Install the five versioned source updates listed above.</li>
<li>Leave scoring, race-results snapshots, LP/ADJ data, RD files and Live MRL untouched.</li>
</ul>
<form method="post"><button class="btn" name="action" value="install">INSTALL PICK WINDOW / MESSAGING REFINEMENT</button></form>
</div>
<?php endif; ?>

<?php if($installComplete): ?>
<div class="card success">
<h2 class="ok">INSTALL COMPLETE</h2>
<p><strong>Backup folder:</strong><br><code><?php echo ih($backupDir); ?></code></p>
<table>
<?php foreach($postflight as $pf): ?><tr><td><?php echo ih($pf['name']); ?></td><td class="<?php echo $pf['ok']?'ok':'bad'; ?>"><?php echo $pf['ok']?'PASS':'FAIL'; ?></td></tr><?php endforeach; ?>
</table>
<p class="small">
Recommended tests: normal closed-between-segments message; default-day opening; one-segment day adjustment; exact date/time adjustment; NOW; temporary override; genuine LP wording.<br><br>
When satisfied: sync server → local with WinSCP, then commit/push the final files to GitHub.
</p>
</div>
<?php endif; ?>

</div>
</body>
</html>
