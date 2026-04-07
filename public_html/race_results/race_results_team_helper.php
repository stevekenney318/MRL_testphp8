<?php
declare(strict_types=1);

/**
 * race_results_team_helper.php
 *
 * VERSION: v002
 * LAST MODIFIED: 4/3/2026 1:38:00 am
 *
 * CHANGELOG:
 *
 * v002 (4/3/2026)
 *   - Updated header to current MRL format.
 *   - Fixed base segment pick loader to use user_picks instead of the old non-existent picks table.
 *   - Restricted base segment rows to baseline pick types only so LP / RD overlays are not treated as starting rows.
 *   - Added pick_type and effective_race to normalized output for downstream race-results pages.
 *
 * v001 (4/3/2026)
 *   - Converted header to standard MRL format.
 *   - Removed BUILD TS.
 *   - No functional changes.
 *
 * v1.00.00 (2026-03-11)
 *   - Initial shared helper for loading MRL team picks by year + segment.
 *   - Extracted from team_chart.php logic.
 *   - Designed for reuse by race results / standings pages.
 *
 * PHP: 7.3 compatible.
 */

if (!function_exists('rr_valid_year')) {
    function rr_valid_year($year): bool
    {
        return preg_match('/^\d{4}$/', (string)$year) === 1;
    }
}

if (!function_exists('rr_valid_segment')) {
    function rr_valid_segment($segment): bool
    {
        return preg_match('/^S[1-9]\d*$/', (string)$segment) === 1;
    }
}

if (!function_exists('rr_segment_label')) {
    function rr_segment_label(string $segment, $raceYear = null): string
    {
        $yearInt = (int)$raceYear;

        if ($segment === 'S1') return 'Segment #1';
        if ($segment === 'S2') return 'Segment #2';
        if ($segment === 'S3') return 'Segment #3';

        if ($segment === 'S4') {
            return ($yearInt >= 2026) ? 'The Chase' : 'Playoffs';
        }

        return $segment;
    }
}

if (!function_exists('rr_normalize_team_pick_row')) {
    function rr_normalize_team_pick_row(array $row): array
    {
        return [
            'userID'         => (int)($row['userID'] ?? 0),
            'teamName'       => trim((string)($row['teamName'] ?? '')),
            'userName'       => trim((string)($row['userName'] ?? '')),
            'driverA'        => trim((string)($row['driverA'] ?? '')),
            'driverB'        => trim((string)($row['driverB'] ?? '')),
            'driverC'        => trim((string)($row['driverC'] ?? '')),
            'driverD'        => trim((string)($row['driverD'] ?? '')),
            'entryDate'      => trim((string)($row['entryDate'] ?? '')),
            'pick_type'      => trim((string)($row['pick_type'] ?? 'SEG')),
            'effective_race' => isset($row['effective_race']) ? (int)$row['effective_race'] : 0,
        ];
    }
}

if (!function_exists('rr_get_segment_team_picks')) {

    /**
     * Load baseline team picks for a given year + segment.
     *
     * Supports:
     * - PDO ($dbo)
     * - mysqli ($dbconnect)
     *
     * IMPORTANT:
     * - Reads from user_picks (not the old/non-existent picks table).
     * - Returns only baseline rows (SEG / ADJ) so LP / RD rows can be applied later as overlays.
     *
     * @param mixed $dbo
     * @param mixed $dbconnect
     * @param string $year
     * @param string $segment
     * @param bool $excludeMrlUser
     * @return array
     */

    function rr_get_segment_team_picks($dbo, $dbconnect, string $year, string $segment, bool $excludeMrlUser = true): array
    {
        if (!rr_valid_year($year)) return [];
        if (!rr_valid_segment($segment)) return [];

        $rows = [];
        $excludeName = 'MRL';

        $sql = "
            SELECT
                up.userID,
                up.teamName,
                COALESCE(u.userName, '') AS userName,
                up.driverA,
                up.driverB,
                up.driverC,
                up.driverD,
                up.entryDate,
                up.pick_type,
                up.effective_race
            FROM user_picks up
            LEFT JOIN users u ON u.userID = up.userID
            WHERE up.raceYear = :year
              AND up.segment  = :segment
              AND up.pick_type IN ('SEG', 'ADJ')
        ";

        if ($excludeMrlUser) {
            $sql .= " AND COALESCE(u.userName, '') != :excludeName ";
        }

        $sql .= " ORDER BY up.userID ASC, up.entryDate ASC, up.pickID ASC ";

        try {

            /* ---------------- PDO ---------------- */

            if ($dbo instanceof PDO) {

                $stmt = $dbo->prepare($sql);

                $params = [
                    ':year'    => $year,
                    ':segment' => $segment
                ];

                if ($excludeMrlUser) {
                    $params[':excludeName'] = $excludeName;
                }

                $stmt->execute($params);

                $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (is_array($fetched)) {
                    $seenTeams = [];

                    foreach ($fetched as $row) {
                        $normalized = rr_normalize_team_pick_row($row);
                        $teamName = (string)($normalized['teamName'] ?? '');

                        if ($teamName === '' || isset($seenTeams[$teamName])) {
                            continue;
                        }

                        $seenTeams[$teamName] = true;
                        $rows[] = $normalized;
                    }
                }

                return $rows;
            }

            /* ---------------- mysqli ---------------- */

            if ($dbconnect instanceof mysqli) {

                $sql2 = "
                    SELECT
                        up.userID,
                        up.teamName,
                        COALESCE(u.userName, '') AS userName,
                        up.driverA,
                        up.driverB,
                        up.driverC,
                        up.driverD,
                        up.entryDate,
                        up.pick_type,
                        up.effective_race
                    FROM user_picks up
                    LEFT JOIN users u ON u.userID = up.userID
                    WHERE up.raceYear = ?
                      AND up.segment  = ?
                      AND up.pick_type IN ('SEG', 'ADJ')
                ";

                if ($excludeMrlUser) {
                    $sql2 .= " AND COALESCE(u.userName, '') != ? ";
                }

                $sql2 .= " ORDER BY up.userID ASC, up.entryDate ASC, up.pickID ASC ";

                $stmt = mysqli_prepare($dbconnect, $sql2);

                if (!$stmt) return [];

                if ($excludeMrlUser) {
                    mysqli_stmt_bind_param($stmt, 'sss', $year, $segment, $excludeName);
                } else {
                    mysqli_stmt_bind_param($stmt, 'ss', $year, $segment);
                }

                mysqli_stmt_execute($stmt);

                $res = mysqli_stmt_get_result($stmt);
                $seenTeams = [];

                while ($res && ($row = mysqli_fetch_assoc($res))) {
                    $normalized = rr_normalize_team_pick_row($row);
                    $teamName = (string)($normalized['teamName'] ?? '');

                    if ($teamName === '' || isset($seenTeams[$teamName])) {
                        continue;
                    }

                    $seenTeams[$teamName] = true;
                    $rows[] = $normalized;
                }

                mysqli_stmt_close($stmt);

                return $rows;
            }

        } catch (Throwable $e) {
            return [];
        }

        return [];
    }
}
