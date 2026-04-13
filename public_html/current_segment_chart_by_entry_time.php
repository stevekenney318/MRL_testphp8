<?php
/*
filename: current_segment_chart_by_entry_time.php
2026-04-07 23:28:51 Rebuilt from user_picks + users with render-time (LP) / (RD) markers plus legend.
*/
session_start();
date_default_timezone_set("America/New_York");
include "config.php"; // setup variables for database connection
include "config_mrl.php"; // setup variables for current MRL season & segment

$sql = "
    SELECT
        up.pickID,
        up.pick_type,
        up.supersedes_pickID,
        up.userID,
        up.teamName,
        COALESCE(u.userName, '') AS userName,
        up.driverA,
        up.driverB,
        up.driverC,
        up.driverD,
        up.entryDate
    FROM `user_picks` up
    LEFT JOIN `users` u
      ON u.userID = up.userID
    WHERE up.`raceYear` = '$raceYear'
      AND up.`segment` = '$segment'
      AND COALESCE(u.userName, '') != 'MRL'
    ORDER BY up.`entryDate` ASC, up.`pickID` ASC
";

echo "<style type='text/css'>
      table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
        padding: 3px;
        color: black !important;
        font-size: 13pt !important;
        line-height: 140%;
        font-family: 'Arial';
      }
   </style>";

echo "<table align=center style=width:100%>";
echo "<tr style=background-color:#fabf8f>";
echo "<th colspan=7> $raceYear $segmentName Team Chart</th>";
echo "<tr style=background-color:#fabf8f>";
echo "<th>Team</th><th>Owner</th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th><th>Submission Time</th></tr>";

$rows = [];
$result = $dbo->query($sql);
if ($result) {
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
}

$rowsByPickId = [];
$baseRowsByTeam = [];

foreach ($rows as $sourceRow) {
    $pickId = (int)($sourceRow['pickID'] ?? 0);
    if ($pickId > 0) {
        $rowsByPickId[$pickId] = $sourceRow;
    }

    $teamName = trim((string)($sourceRow['teamName'] ?? ''));
    $pickType = strtoupper(trim((string)($sourceRow['pick_type'] ?? 'SEG')));

    if ($teamName !== '' && !isset($baseRowsByTeam[$teamName]) && ($pickType === 'SEG' || $pickType === 'ADJ' || $pickType === '')) {
        $baseRowsByTeam[$teamName] = $sourceRow;
    }

    if ($teamName !== '' && !isset($baseRowsByTeam[$teamName])) {
        $baseRowsByTeam[$teamName] = $sourceRow;
    }
}

foreach ($rows as $row) {
    $teamName = trim((string)($row['teamName'] ?? ''));
    $referenceRow = cscet_get_reference_pick_row($row, $rowsByPickId, $baseRowsByTeam[$teamName] ?? null);

    $driverA = cscet_format_driver_display($row, $referenceRow, 'driverA');
    $driverB = cscet_format_driver_display($row, $referenceRow, 'driverB');
    $driverC = cscet_format_driver_display($row, $referenceRow, 'driverC');
    $driverD = cscet_format_driver_display($row, $referenceRow, 'driverD');

    echo "<tr><td style=background-color:#b7dee8>" . cscet_h($row['teamName'] ?? '') . "</td><td style=background-color:#b7dee8>" . cscet_h($row['userName'] ?? '') . "</td><td style=background-color:#d9d9d9>" . cscet_h($driverA) . "</td><td style=background-color:#c4bd97>" . cscet_h($driverB) . "</td><td style=background-color:#b8cce4>" . cscet_h($driverC) . "</td><td style=background-color:#d8e4bc>" . cscet_h($driverD) . "</td><td style=background-color:#b7dee8>" . cscet_h($row['entryDate'] ?? '') . "</td></tr>";
}

echo "</table>";
echo "<div style='width:100%; margin:6px auto 0 auto; color:#777; font-size:13px; font-family:Arial, sans-serif; text-align:left;'>(LP) - Late Pick &nbsp;&nbsp; (RD) - Replacement Driver</div>";

function cscet_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cscet_get_reference_pick_row(array $row, array $rowsByPickId, ?array $baseRow): ?array {
    $supersedesPickID = (int)($row['supersedes_pickID'] ?? 0);
    if ($supersedesPickID > 0 && isset($rowsByPickId[$supersedesPickID])) {
        return $rowsByPickId[$supersedesPickID];
    }
    return $baseRow;
}

function cscet_get_special_pick_suffix(array $row, ?array $referenceRow, string $field): string {
    $pickType = strtoupper(trim((string)($row['pick_type'] ?? 'SEG')));

    if ($pickType === 'LP') {
        return '(LP)';
    }

    if ($pickType === 'RD') {
        $driverName = trim((string)($row[$field] ?? ''));
        $referenceDriver = trim((string)($referenceRow[$field] ?? ''));
        if ($driverName !== '' && strcasecmp($driverName, $referenceDriver) !== 0) {
            return '(RD)';
        }
    }

    return '';
}

function cscet_format_driver_display(array $row, ?array $referenceRow, string $field): string {
    $driverName = trim((string)($row[$field] ?? ''));
    if ($driverName === '') {
        return '';
    }

    $special = cscet_get_special_pick_suffix($row, $referenceRow, $field);
    return ($special !== '') ? ($driverName . ' ' . $special) : $driverName;
}
?>
