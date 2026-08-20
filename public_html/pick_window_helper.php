<?php
declare(strict_types=1);

/**
 * pick_window_helper.php
 *
 * VERSION: v003
 * LAST MODIFIED: 8/20/2026 2:33:24 pm
 *
 * DESCRIPTION:
 * Shared automatic normal-pick window resolver.
 *
 * Sources:
 * - /race_results/_race_results_schedule.json for canonical race start times.
 * - segment_race_ranges for segment start/end race numbers.
 * - admin_setup pickWindowDefaultDays for the normal global lead-time rule.
 * - admin_setup pickLeadAdjust* for an optional one-segment lead-time adjustment.
 *
 * Rules:
 * - Default normal-pick lead time is configurable in admin_setup (15 days initially).
 * - A segment-specific adjustment applies ONLY to its matching year + segment.
 * - When the system moves to another segment, the unmatched adjustment is ignored and
 *   the global default automatically resumes.
 * - The temporary exact-date override remains a separate, higher-priority override.
 * - Normal picks close at the scheduled start of the first race in the segment.
 * - scoringSegment is the latest segment whose first race has started.
 * - pickSegment becomes the next segment during its calculated normal-pick window;
 *   otherwise it remains the scoring segment so LP/current-segment behavior works.
 * - Temporary admin override changes PICK state only; scoringSegment remains automatic.
 *
 * CHANGELOG:
 * v003 (8/20/2026 2:33:24 pm)
 * - NEW: Optional exact one-segment opening timestamp via adjust_open_at.
 * - NEW: Reports next chronological segment/open/deadline for UI messaging.
 * - NEW: Reports lead mode/display so exact openings are not misrepresented as whole days.
 * - PRESERVE: Integer lead-days still subtract exact whole days from first-race start time.
 * - PRESERVE: Temporary exact-date override remains higher priority.
 *
 * v002 (8/19/2026 3:08:00 pm)
 * - NEW: Configurable global default lead time.
 * - NEW: One-segment lead-time adjustment keyed by year + segment.
 * - NEW: Window state reports effective lead-time source/default/adjustment details.
 * - CHANGE: Replaced hardcoded 15-day calculation with supplied settings.
 *
 * v001 (8/19/2026 4:51:53 am)
 * - Initial automatic pick-window helper.
 */

const MRL_PICK_WINDOW_DEFAULT_DAYS = 15;
const MRL_PICK_WINDOW_TIMEZONE = 'America/New_York';

require_once __DIR__ . '/race_results/race_schedule_helper.php';

if (!function_exists('mrl_pick_window_timezone')) {
    function mrl_pick_window_timezone(): DateTimeZone
    {
        return new DateTimeZone(MRL_PICK_WINDOW_TIMEZONE);
    }
}

if (!function_exists('mrl_pick_window_normalize_days')) {
    function mrl_pick_window_normalize_days($value, int $fallback = MRL_PICK_WINDOW_DEFAULT_DAYS): int
    {
        $days = filter_var($value, FILTER_VALIDATE_INT);
        if ($days === false || $days < 0 || $days > 90) {
            return $fallback;
        }
        return (int)$days;
    }
}

if (!function_exists('mrl_pick_window_segment_rows')) {
    function mrl_pick_window_segment_rows(int $year, array $settings = []): array
    {
        global $dbo, $dbconnect;
        $rows = [];

        $defaultDays = mrl_pick_window_normalize_days(
            $settings['default_days'] ?? MRL_PICK_WINDOW_DEFAULT_DAYS,
            MRL_PICK_WINDOW_DEFAULT_DAYS
        );
        $adjustYear = (int)($settings['adjust_year'] ?? 0);
        $adjustSegment = strtoupper(trim((string)($settings['adjust_segment'] ?? '')));
        $adjustDaysRaw = $settings['adjust_days'] ?? null;
        $adjustDays = ($adjustDaysRaw === null || $adjustDaysRaw === '')
            ? null
            : mrl_pick_window_normalize_days($adjustDaysRaw, $defaultDays);
        $adjustOpenRaw = trim((string)($settings['adjust_open_at'] ?? ''));
        $adjustOpenDt = $adjustOpenRaw !== ''
            ? mrl_pick_window_parse_db_datetime($adjustOpenRaw)
            : null;

        if (isset($dbo) && $dbo instanceof PDO) {
            $stmt = $dbo->prepare(
                "SELECT segment, startRace, endRace
                 FROM segment_race_ranges
                 WHERE raceYear = :raceYear
                 ORDER BY startRace ASC"
            );
            $stmt->execute([':raceYear' => $year]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (isset($dbconnect) && $dbconnect instanceof mysqli) {
            $stmt = mysqli_prepare(
                $dbconnect,
                "SELECT segment, startRace, endRace
                 FROM segment_race_ranges
                 WHERE raceYear = ?
                 ORDER BY startRace ASC"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $year);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($row = mysqli_fetch_assoc($res))) {
                    $rows[] = $row;
                }
                mysqli_stmt_close($stmt);
            }
        }

        if (empty($rows)) {
            throw new RuntimeException('No segment_race_ranges rows found for ' . $year . '.');
        }

        $out = [];
        foreach ($rows as $row) {
            $segment = strtoupper(trim((string)($row['segment'] ?? '')));
            $startRace = (int)($row['startRace'] ?? 0);
            $endRace = (int)($row['endRace'] ?? 0);
            if ($segment === '' || $startRace <= 0 || $endRace < $startRace) {
                continue;
            }

            $race = mrl_schedule_helper_race_by_number($year, $startRace);
            if (!is_array($race)) {
                throw new RuntimeException('Segment ' . $segment . ' start race R' . $startRace . ' is missing from canonical schedule.');
            }

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
                'segment' => $segment,
                'start_race' => $startRace,
                'end_race' => $endRace,
                'start_dt' => $startDt,
                'open_dt' => $openDt,
                'deadline_dt' => $startDt,
                'lead_days' => $leadDays,
                'lead_source' => $leadSource,
                'lead_mode' => $leadMode,
                'lead_display' => $leadDisplay,
                'default_days' => $defaultDays,
                'race' => $race,
            ];
        }

        if (empty($out)) {
            throw new RuntimeException('No usable segment window rows found for ' . $year . '.');
        }

        return $out;
    }
}

if (!function_exists('mrl_pick_window_segment_label')) {
    function mrl_pick_window_segment_label(int $year, string $segment): string
    {
        $segment = strtoupper(trim($segment));
        if ($segment === 'S1') return 'Segment #1';
        if ($segment === 'S2') return 'Segment #2';
        if ($segment === 'S3') return 'Segment #3';
        if ($segment === 'S4') return $year >= 2026 ? 'The Chase' : 'Playoffs';
        return $segment;
    }
}

if (!function_exists('mrl_pick_window_parse_db_datetime')) {
    function mrl_pick_window_parse_db_datetime(?string $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, mrl_pick_window_timezone());
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }
}

if (!function_exists('mrl_pick_window_format_display')) {
    function mrl_pick_window_format_display(DateTimeImmutable $dt): string
    {
        return $dt->format('n/j/Y g:i a') . ' ET';
    }
}

if (!function_exists('mrl_pick_window_state')) {
    function mrl_pick_window_state(
        int $year,
        array $override = [],
        ?DateTimeImmutable $now = null,
        array $settings = []
    ): array {
        $now = $now ?? new DateTimeImmutable('now', mrl_pick_window_timezone());
        $segments = mrl_pick_window_segment_rows($year, $settings);

        $scoringRow = $segments[0];
        foreach ($segments as $row) {
            if ($row['start_dt'] <= $now) {
                $scoringRow = $row;
            } else {
                break;
            }
        }

        $scoringIndex = 0;
        foreach ($segments as $idx => $row) {
            if ($row['segment'] === $scoringRow['segment']) {
                $scoringIndex = (int)$idx;
                break;
            }
        }
        $nextRow = isset($segments[$scoringIndex + 1]) ? $segments[$scoringIndex + 1] : null;

        $pickRow = $scoringRow;
        foreach ($segments as $row) {
            if ($now >= $row['open_dt'] && $now < $row['deadline_dt']) {
                $pickRow = $row;
                break;
            }
        }

        $source = 'AUTO';
        $overrideError = '';
        $openDt = $pickRow['open_dt'];
        $deadlineDt = $pickRow['deadline_dt'];
        $effectiveLeadDays = (int)$pickRow['lead_days'];
        $leadSource = (string)$pickRow['lead_source'];
        $leadMode = (string)($pickRow['lead_mode'] ?? 'DAYS');
        $leadDisplay = (string)($pickRow['lead_display'] ?? ($effectiveLeadDays . ' days'));

        $overrideEnabled = strtolower(trim((string)($override['enabled'] ?? 'no'))) === 'yes';
        if ($overrideEnabled) {
            $requestedSegment = strtoupper(trim((string)($override['segment'] ?? '')));
            $requestedOpen = mrl_pick_window_parse_db_datetime($override['open_at'] ?? null);
            $requestedDeadline = mrl_pick_window_parse_db_datetime($override['deadline_at'] ?? null);
            $requestedRow = null;

            foreach ($segments as $row) {
                if ($row['segment'] === $requestedSegment) {
                    $requestedRow = $row;
                    break;
                }
            }

            if ($requestedRow === null) {
                $overrideError = 'Override segment is not valid for ' . $year . '.';
            } elseif (!$requestedOpen || !$requestedDeadline) {
                $overrideError = 'Override requires both opening and deadline date/time.';
            } elseif ($requestedOpen >= $requestedDeadline) {
                $overrideError = 'Override opening must be earlier than override deadline.';
            } else {
                $pickRow = $requestedRow;
                $openDt = $requestedOpen;
                $deadlineDt = $requestedDeadline;
                $effectiveLeadDays = (int)$requestedRow['lead_days'];
                $leadSource = (string)$requestedRow['lead_source'];
                $leadMode = (string)($requestedRow['lead_mode'] ?? 'DAYS');
                $leadDisplay = (string)($requestedRow['lead_display'] ?? ($effectiveLeadDays . ' days'));
                $source = 'OVERRIDE';
            }
        }

        $isOpen = ($now >= $openDt && $now < $deadlineDt);
        $status = $isOpen ? 'OPEN' : ($now < $openDt ? 'CLOSED_BEFORE_OPEN' : 'CLOSED_AFTER_DEADLINE');

        return [
            'year' => $year,
            'now' => $now,
            'source' => $source,
            'override_error' => $overrideError,
            'scoring_segment' => (string)$scoringRow['segment'],
            'scoring_segment_label' => mrl_pick_window_segment_label($year, (string)$scoringRow['segment']),
            'pick_segment' => (string)$pickRow['segment'],
            'pick_segment_label' => mrl_pick_window_segment_label($year, (string)$pickRow['segment']),
            'pick_start_race' => (int)$pickRow['start_race'],
            'pick_end_race' => (int)$pickRow['end_race'],
            'window_open_dt' => $openDt,
            'deadline_dt' => $deadlineDt,
            'window_open_display' => mrl_pick_window_format_display($openDt),
            'deadline_display' => mrl_pick_window_format_display($deadlineDt),
            'window_is_open' => $isOpen,
            'status' => $status,
            'default_lead_days' => (int)$pickRow['default_days'],
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
        ];
    }
}