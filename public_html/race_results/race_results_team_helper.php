<?php
declare(strict_types=1);

/**
 * race_results_team_helper.php
 *
 * VERSION: v1.00.00
 * LAST MODIFIED: 2026-03-11
 * BUILD TS: 20260311_234442887
 *
 * CHANGELOG:
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
            'userID'    => (int)($row['userID'] ?? 0),
            'teamName'  => trim((string)($row['teamName'] ?? '')),
            'userName'  => trim((string)($row['userName'] ?? '')),
            'driverA'   => trim((string)($row['driverA'] ?? '')),
            'driverB'   => trim((string)($row['driverB'] ?? '')),
            'driverC'   => trim((string)($row['driverC'] ?? '')),
            'driverD'   => trim((string)($row['driverD'] ?? '')),
            'entryDate' => trim((string)($row['entryDate'] ?? '')),
        ];
    }
}

if (!function_exists('rr_get_segment_team_picks')) {

    /**
     * Load all team picks for a given year + segment.
     *
     * Supports:
     * - PDO ($dbo)
     * - mysqli ($dbconnect)
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
                userID,
                teamName,
                userName,
                driverA,
                driverB,
                driverC,
                driverD,
                entryDate
            FROM picks
            WHERE raceYear = :year
              AND segment  = :segment
        ";

        if ($excludeMrlUser) {
            $sql .= " AND userName != :excludeName ";
        }

        $sql .= " ORDER BY userID ASC ";

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
                    foreach ($fetched as $row) {
                        $rows[] = rr_normalize_team_pick_row($row);
                    }
                }

                return $rows;
            }

            /* ---------------- mysqli ---------------- */

            if ($dbconnect instanceof mysqli) {

                $sql2 = "
                    SELECT
                        userID,
                        teamName,
                        userName,
                        driverA,
                        driverB,
                        driverC,
                        driverD,
                        entryDate
                    FROM picks
                    WHERE raceYear = ?
                      AND segment  = ?
                ";

                if ($excludeMrlUser) {
                    $sql2 .= " AND userName != ? ";
                }

                $sql2 .= " ORDER BY userID ASC ";

                $stmt = mysqli_prepare($dbconnect, $sql2);

                if (!$stmt) return [];

                if ($excludeMrlUser) {
                    mysqli_stmt_bind_param($stmt, 'sss', $year, $segment, $excludeName);
                } else {
                    mysqli_stmt_bind_param($stmt, 'ss', $year, $segment);
                }

                mysqli_stmt_execute($stmt);

                $res = mysqli_stmt_get_result($stmt);

                while ($res && ($row = mysqli_fetch_assoc($res))) {
                    $rows[] = rr_normalize_team_pick_row($row);
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