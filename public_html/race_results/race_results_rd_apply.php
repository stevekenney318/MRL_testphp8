<?php
declare(strict_types=1);

/**
 * race_results_rd_apply.php
 *
 * VERSION: v001
 * LAST MODIFIED: 4/3/2026 12:55:00 am
 *
 * DESCRIPTION:
 * Helper functions for applying Replacement Driver (RD) rows.
 *
 * PURPOSE:
 * - Takes a validated RD-eligible team/segment situation
 * - Creates a new full-row pick entry in user_picks
 * - Links the new row to the prior effective row using supersedes_pickID
 * - Writes the same row to user_picks_history for audit support
 *
 * CURRENT DESIGN:
 * - RD is not automatic.
 * - RD is a deliberate apply step after eligibility has been detected.
 * - The new RD row stores the full 4-driver lineup.
 * - Only the selected slot changes.
 * - effective_race comes from RD detection logic.
 *
 * CHANGELOG:
 *
 * v001 (4/3/2026)
 * - Initial RD apply helper.
 * - Applies one selected RD slot using the output of race_results_rd_helper.php detection logic.
 * - Inserts a new RD row into user_picks.
 * - Inserts matching audit row into user_picks_history.
 * - Uses supersedes_pickID to link the new row to the prior effective row.
 * - Validates duplicate-driver conflicts and invalid slot selection.
 */

require_once __DIR__ . '/race_results_rd_helper.php';

if (!function_exists('mrl_rd_apply_normalize_slot')) {
    function mrl_rd_apply_normalize_slot(string $slot): string
    {
        $slot = strtoupper(trim($slot));
        return in_array($slot, ['A', 'B', 'C', 'D'], true) ? $slot : '';
    }
}

if (!function_exists('mrl_rd_apply_generate_submission_id')) {
    function mrl_rd_apply_generate_submission_id(): string
    {
        date_default_timezone_set('America/New_York');
        return 'rd_' . date('Ymd_His');
    }
}

if (!function_exists('mrl_rd_apply_load_segment_rows')) {
    function mrl_rd_apply_load_segment_rows(PDO $dbo, string $raceYear, string $segment, string $teamName): array
    {
        $sql = "
            SELECT
                pickID,
                userID,
                teamName,
                raceYear,
                segment,
                driverA,
                driverB,
                driverC,
                driverD,
                entryDate,
                submission_id,
                ip,
                formID,
                pick_type,
                effective_race,
                supersedes_pickID
            FROM user_picks
            WHERE raceYear = :raceYear
              AND segment = :segment
              AND teamName = :teamName
            ORDER BY effective_race ASC, entryDate ASC, pickID ASC
        ";

        $stmt = $dbo->prepare($sql);
        $stmt->execute([
            ':raceYear' => $raceYear,
            ':segment' => $segment,
            ':teamName' => $teamName,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('mrl_rd_apply_find_source_row')) {
    function mrl_rd_apply_find_source_row(PDO $dbo, string $raceYear, string $segment, string $teamName, int $effectiveRace): ?array
    {
        $rows = mrl_rd_apply_load_segment_rows($dbo, $raceYear, $segment, $teamName);
        if (empty($rows)) {
            return null;
        }

        $applicable = [];

        foreach ($rows as $row) {
            $rowEffectiveRace = (int)($row['effective_race'] ?? 0);
            if ($rowEffectiveRace <= $effectiveRace) {
                $applicable[] = $row;
            }
        }

        usort($applicable, function (array $a, array $b): int {
            $aEffective = (int)($a['effective_race'] ?? 0);
            $bEffective = (int)($b['effective_race'] ?? 0);

            if ($aEffective !== $bEffective) {
                return ($bEffective <=> $aEffective);
            }

            $aDate = strtotime((string)($a['entryDate'] ?? ''));
            $bDate = strtotime((string)($b['entryDate'] ?? ''));

            if ($aDate !== $bDate) {
                return ($bDate <=> $aDate);
            }

            return ((int)($b['pickID'] ?? 0) <=> (int)($a['pickID'] ?? 0));
        });

        if (!empty($applicable)) {
            return $applicable[0];
        }

        return $rows[0];
    }
}

if (!function_exists('mrl_rd_apply_find_qualifier_for_slot')) {
    function mrl_rd_apply_find_qualifier_for_slot(array $qualifiers, string $slot): ?array
    {
        foreach ($qualifiers as $qualifier) {
            if ((string)($qualifier['slot'] ?? '') === $slot) {
                return $qualifier;
            }
        }

        return null;
    }
}

if (!function_exists('mrl_rd_apply_validate_replacement_driver')) {
    function mrl_rd_apply_validate_replacement_driver(array $sourceRow, string $slot, string $replacementDriver): array
    {
        $replacementDriver = trim($replacementDriver);

        if ($replacementDriver === '') {
            return ['ok' => false, 'message' => 'Replacement driver is required.'];
        }

        $currentSlotKey = 'driver' . $slot;
        $currentDriver = trim((string)($sourceRow[$currentSlotKey] ?? ''));

        if ($currentDriver === '') {
            return ['ok' => false, 'message' => 'Selected RD slot has no current driver.'];
        }

        if ($replacementDriver === $currentDriver) {
            return ['ok' => false, 'message' => 'Replacement driver matches the current driver in slot ' . $slot . '.'];
        }

        $otherSlots = ['A', 'B', 'C', 'D'];
        foreach ($otherSlots as $otherSlot) {
            if ($otherSlot === $slot) {
                continue;
            }

            $otherKey = 'driver' . $otherSlot;
            $otherDriver = trim((string)($sourceRow[$otherKey] ?? ''));

            if ($otherDriver !== '' && $otherDriver === $replacementDriver) {
                return ['ok' => false, 'message' => 'Replacement driver already exists in slot ' . $otherSlot . '.'];
            }
        }

        return ['ok' => true, 'message' => ''];
    }
}

if (!function_exists('mrl_rd_apply_build_new_row')) {
    function mrl_rd_apply_build_new_row(array $sourceRow, string $slot, string $replacementDriver, int $effectiveRace): array
    {
        $newRow = $sourceRow;
        $slotKey = 'driver' . $slot;

        $newRow[$slotKey] = trim($replacementDriver);
        $newRow['pick_type'] = 'RD';
        $newRow['effective_race'] = $effectiveRace;
        $newRow['supersedes_pickID'] = (int)($sourceRow['pickID'] ?? 0);

        return $newRow;
    }
}

if (!function_exists('mrl_rd_apply_insert_user_pick')) {
    function mrl_rd_apply_insert_user_pick(
        PDO $dbo,
        array $row,
        string $submissionId,
        string $ip,
        string $formID
    ): int {
        $sql = "
            INSERT INTO user_picks
            (
                userID,
                teamName,
                raceYear,
                segment,
                driverA,
                driverB,
                driverC,
                driverD,
                entryDate,
                submission_id,
                ip,
                formID,
                pick_type,
                effective_race,
                supersedes_pickID
            )
            VALUES
            (
                :userID,
                :teamName,
                :raceYear,
                :segment,
                :driverA,
                :driverB,
                :driverC,
                :driverD,
                :entryDate,
                :submission_id,
                :ip,
                :formID,
                :pick_type,
                :effective_race,
                :supersedes_pickID
            )
        ";

        $stmt = $dbo->prepare($sql);
        $stmt->execute([
            ':userID' => (int)($row['userID'] ?? 0),
            ':teamName' => (string)($row['teamName'] ?? ''),
            ':raceYear' => (string)($row['raceYear'] ?? ''),
            ':segment' => (string)($row['segment'] ?? ''),
            ':driverA' => (string)($row['driverA'] ?? ''),
            ':driverB' => (string)($row['driverB'] ?? ''),
            ':driverC' => (string)($row['driverC'] ?? ''),
            ':driverD' => (string)($row['driverD'] ?? ''),
            ':entryDate' => (string)($row['entryDate'] ?? ''),
            ':submission_id' => $submissionId,
            ':ip' => $ip,
            ':formID' => $formID,
            ':pick_type' => (string)($row['pick_type'] ?? 'RD'),
            ':effective_race' => (int)($row['effective_race'] ?? 0),
            ':supersedes_pickID' => (int)($row['supersedes_pickID'] ?? 0),
        ]);

        return (int)$dbo->lastInsertId();
    }
}

if (!function_exists('mrl_rd_apply_insert_history')) {
    function mrl_rd_apply_insert_history(
        PDO $dbo,
        array $row,
        string $submissionId,
        string $ip,
        string $formID
    ): void {
        $sql = "
            INSERT INTO user_picks_history
            (
                userID,
                teamName,
                raceYear,
                segment,
                driverA,
                driverB,
                driverC,
                driverD,
                entryDate,
                submission_id,
                ip,
                formID,
                pick_type,
                effective_race,
                supersedes_pickID
            )
            VALUES
            (
                :userID,
                :teamName,
                :raceYear,
                :segment,
                :driverA,
                :driverB,
                :driverC,
                :driverD,
                :entryDate,
                :submission_id,
                :ip,
                :formID,
                :pick_type,
                :effective_race,
                :supersedes_pickID
            )
        ";

        $stmt = $dbo->prepare($sql);
        $stmt->execute([
            ':userID' => (int)($row['userID'] ?? 0),
            ':teamName' => (string)($row['teamName'] ?? ''),
            ':raceYear' => (string)($row['raceYear'] ?? ''),
            ':segment' => (string)($row['segment'] ?? ''),
            ':driverA' => (string)($row['driverA'] ?? ''),
            ':driverB' => (string)($row['driverB'] ?? ''),
            ':driverC' => (string)($row['driverC'] ?? ''),
            ':driverD' => (string)($row['driverD'] ?? ''),
            ':entryDate' => (string)($row['entryDate'] ?? ''),
            ':submission_id' => $submissionId,
            ':ip' => $ip,
            ':formID' => $formID,
            ':pick_type' => (string)($row['pick_type'] ?? 'RD'),
            ':effective_race' => (int)($row['effective_race'] ?? 0),
            ':supersedes_pickID' => (int)($row['supersedes_pickID'] ?? 0),
        ]);
    }
}

if (!function_exists('mrl_rd_apply')) {
    /**
     * Applies one RD row for the selected slot.
     *
     * Returns:
     * [
     *   'ok' => bool,
     *   'message' => string,
     *   'status' => string,
     *   'inserted_pickID' => int|null,
     *   'effective_race' => int|null,
     *   'selected_slot' => 'A'|'B'|'C'|'D'|'',
     * ]
     */
    function mrl_rd_apply(
        PDO $dbo,
        string $raceYear,
        string $segment,
        string $teamName,
        string $selectedSlot,
        string $replacementDriver,
        array $raceDriverPoints,
        string $ip = '',
        string $formID = 'race_results_rd_apply.php'
    ): array {
        $selectedSlot = mrl_rd_apply_normalize_slot($selectedSlot);

        if ($selectedSlot === '') {
            return [
                'ok' => false,
                'message' => 'Invalid RD slot selection.',
                'status' => 'INVALID_SLOT',
                'inserted_pickID' => null,
                'effective_race' => null,
                'selected_slot' => '',
            ];
        }

        $eligibility = mrl_rd_detect_team_segment_eligibility(
            $dbo,
            $raceYear,
            $segment,
            $teamName,
            $raceDriverPoints
        );

        $status = (string)($eligibility['status'] ?? '');

        if ($status !== 'RD_AVAILABLE' && $status !== 'MANUAL_SELECTION_REQUIRED') {
            return [
                'ok' => false,
                'message' => 'RD cannot be applied. Current status: ' . $status,
                'status' => $status,
                'inserted_pickID' => null,
                'effective_race' => null,
                'selected_slot' => $selectedSlot,
            ];
        }

        $qualifiers = isset($eligibility['qualifiers']) && is_array($eligibility['qualifiers'])
            ? $eligibility['qualifiers']
            : [];

        $selectedQualifier = mrl_rd_apply_find_qualifier_for_slot($qualifiers, $selectedSlot);
        if (!is_array($selectedQualifier)) {
            return [
                'ok' => false,
                'message' => 'Selected slot is not RD-eligible.',
                'status' => 'SLOT_NOT_ELIGIBLE',
                'inserted_pickID' => null,
                'effective_race' => null,
                'selected_slot' => $selectedSlot,
            ];
        }

        $effectiveRace = (int)($selectedQualifier['effective_race'] ?? 0);
        if ($effectiveRace <= 0) {
            return [
                'ok' => false,
                'message' => 'Selected qualifier has no valid effective race.',
                'status' => 'INVALID_EFFECTIVE_RACE',
                'inserted_pickID' => null,
                'effective_race' => null,
                'selected_slot' => $selectedSlot,
            ];
        }

        $sourceRow = mrl_rd_apply_find_source_row($dbo, $raceYear, $segment, $teamName, $effectiveRace);
        if (!is_array($sourceRow)) {
            return [
                'ok' => false,
                'message' => 'No source pick row found to supersede.',
                'status' => 'NO_SOURCE_ROW',
                'inserted_pickID' => null,
                'effective_race' => $effectiveRace,
                'selected_slot' => $selectedSlot,
            ];
        }

        $driverCheck = mrl_rd_apply_validate_replacement_driver($sourceRow, $selectedSlot, $replacementDriver);
        if (empty($driverCheck['ok'])) {
            return [
                'ok' => false,
                'message' => (string)($driverCheck['message'] ?? 'Replacement driver validation failed.'),
                'status' => 'INVALID_REPLACEMENT_DRIVER',
                'inserted_pickID' => null,
                'effective_race' => $effectiveRace,
                'selected_slot' => $selectedSlot,
            ];
        }

        date_default_timezone_set('America/New_York');

        $newRow = mrl_rd_apply_build_new_row($sourceRow, $selectedSlot, $replacementDriver, $effectiveRace);
        $newRow['entryDate'] = date('Y-m-d H:i:s');

        $submissionId = mrl_rd_apply_generate_submission_id();

        try {
            $dbo->beginTransaction();

            $insertedPickID = mrl_rd_apply_insert_user_pick($dbo, $newRow, $submissionId, $ip, $formID);
            mrl_rd_apply_insert_history($dbo, $newRow, $submissionId, $ip, $formID);

            $dbo->commit();

            return [
                'ok' => true,
                'message' => 'RD row applied successfully.',
                'status' => 'RD_APPLIED',
                'inserted_pickID' => $insertedPickID,
                'effective_race' => $effectiveRace,
                'selected_slot' => $selectedSlot,
                'submission_id' => $submissionId,
                'supersedes_pickID' => (int)($newRow['supersedes_pickID'] ?? 0),
            ];
        } catch (Throwable $e) {
            if ($dbo->inTransaction()) {
                $dbo->rollBack();
            }

            return [
                'ok' => false,
                'message' => 'RD apply failed: ' . $e->getMessage(),
                'status' => 'RD_APPLY_FAILED',
                'inserted_pickID' => null,
                'effective_race' => $effectiveRace,
                'selected_slot' => $selectedSlot,
            ];
        }
    }
}
