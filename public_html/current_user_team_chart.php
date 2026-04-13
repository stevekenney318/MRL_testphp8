<?php
/*
filename: current_user_team_chart.php
2024-01-25 23:51:39 Steve Kenney added Tag for drivers and ChatGPT efficiency update
2026-04-07 22:32:47 Added render-time (LP) / (RD) markers plus legend for team page display.
*/
session_start();

date_default_timezone_set("America/New_York");
include "config.php"; // Setup variables for database connection
include "config_mrl.php"; // Setup variables for current MRL season & segment

// Fetch drivers' tags from the database
$sqlDrivers = [
    'A' => "SELECT `driverName`, `Tag` FROM `A Drivers` WHERE `driverYear` = $raceYear AND `Available` = 'Y'",
    'B' => "SELECT `driverName`, `Tag` FROM `B Drivers` WHERE `driverYear` = $raceYear AND `Available` = 'Y'",
    'C' => "SELECT `driverName`, `Tag` FROM `C Drivers` WHERE `driverYear` = $raceYear AND `Available` = 'Y'",
    'D' => "SELECT `driverName`, `Tag` FROM `D Drivers` WHERE `driverYear` = $raceYear AND `Available` = 'Y'"
];

// Fetch the user ID from the session array
$uid = isset($_SESSION['userSession']) ? $_SESSION['userSession'] : null;

// Include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
        padding: 3px;
        font-size: 16px;
      }
   </style>";

// Table for user information
echo "<table align=center style=width:80%>"; // Start a table tag in the HTML

// Paid Status
$sql = "SELECT * FROM `user_teams` WHERE `userID` = $uid AND `raceYear` = $raceYear";
$result = $dbo->query($sql);

if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Paid Status ' . $raceYear . "</td><td style=background-color:#b7dee8>" . $row['paidStatus'] . "</td><td style=background-color:#b7dee8>" . ' ' . "</td></tr>";
        $DBpaidStatus = $row['paidStatus'];
    }
}

// Paid Amount
$result = $dbo->query($sql);

if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Amount' . "</td><td style=background-color:#b7dee8>" . "$" . $row['paidAmount'] . "</td><td style=background-color:#b7dee8>" . ' ' . "</td></tr>";
        $DBpaidAmount = $row['paidAmount'];
    }
}

// Paid How
$result = $dbo->query($sql);

if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'How' . "</td><td style=background-color:#b7dee8>" . $row['paidHow'] . "</td><td style=background-color:#b7dee8>" . ' ' . "</td></tr>";
        $DBpaidHow = $row['paidHow'];
    }
}

// Paid Comment
$result = $dbo->query($sql);

if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Comment' . "</td><td style=background-color:#b7dee8>" . $row['paidComment'] . "</td><td style=background-color:#b7dee8>" . ' ' . "</td></tr>";
        $DBpaidComment = $row['paidComment'];
    }
}

// Team Name
$sql = "SELECT * FROM `user_teams` WHERE `userID` = $uid AND `raceYear` = $raceYear";
foreach ($dbo->query($sql) as $row) {
    echo "<tr><td style=width:14%;background-color:#f2dcdb>" . 'Team Name' . "</td><td style=background-color:#f2dcdb>" . $row['teamName'] . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td></tr>";
    $DBteamName = $row['teamName'];
}

// Team Owner
$sql = "SELECT * FROM `users` WHERE `userID` = $uid";
foreach ($dbo->query($sql) as $row) {
    echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Team Owner' . "</td><td style=background-color:#f2dcdb>" . $row['userName'] . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td>";
    $DBuserName = $row['userName'];
}

// Email addresses
foreach ($dbo->query($sql) as $row) {
    echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Email Address(es)' . "</td><td style=background-color:#f2dcdb>" . $row['userEmail'] . "</td><td style=background-color:#f2dcdb>" . $row['userEmail2'] . "</td></tr>";
    $DBuserEmail = $row['userEmail'];
}

echo "</table>"; // Close the table for user information

// Table Heading
echo "<table align=center style=width:80%>"; // Start a table tag in the HTML
echo "<tr style=background-color:#fabf8f>";
echo "<th style=width:14%>$raceYear</th><th style=width:18%>Group A</th><th style=width:18%>Group B</th><th style=width:18%>Group C</th><th style=width:18%>Group D</th><th style=width:14%>Submission Time</th></tr>";

// Loop through each segment and display user picks
$segments = ['S1', 'S2', 'S3', 'S4'];
foreach ($segments as $segment) {
    $sql = "SELECT `pickID`, `pick_type`, `supersedes_pickID`, `driverA`, `driverB`, `driverC`, `driverD`, `entryDate`
            FROM `user_picks`
            WHERE `userID` = $uid
              AND `raceYear` = $raceYear
              AND `segment` = '$segment'
            ORDER BY `entryDate` ASC, `pickID` ASC";
    $result = $dbo->query($sql);
    $segmentRows = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];

    if (empty($segmentRows)) {
        continue;
    }

    $rowsByPickId = [];
    $baseRow = null;

    foreach ($segmentRows as $segmentRow) {
        $pickId = (int)($segmentRow['pickID'] ?? 0);
        if ($pickId > 0) {
            $rowsByPickId[$pickId] = $segmentRow;
        }

        $pickType = strtoupper((string)($segmentRow['pick_type'] ?? 'SEG'));
        if ($baseRow === null && ($pickType === 'SEG' || $pickType === 'ADJ' || $pickType === '')) {
            $baseRow = $segmentRow;
        }
    }

    if ($baseRow === null) {
        $baseRow = $segmentRows[0];
    }

    foreach ($segmentRows as $row) {
        $referenceRow = getReferencePickRow($row, $rowsByPickId, $baseRow);

        $driverA = formatDriverDisplay($row, $referenceRow, 'driverA', 'A');
        $driverB = formatDriverDisplay($row, $referenceRow, 'driverB', 'B');
        $driverC = formatDriverDisplay($row, $referenceRow, 'driverC', 'C');
        $driverD = formatDriverDisplay($row, $referenceRow, 'driverD', 'D');
        $segmentName = mapSegmentName($segment);

        echo "<tr><td style=width:175px;background-color:#b7dee8>" . $segmentName . "</td><td style=background-color:#d9d9d9>" . $driverA . "</td><td style=background-color:#c4bd97>" . $driverB . "</td><td style=background-color:#b8cce4>" . $driverC . "</td><td style=background-color:#d8e4bc>" . $driverD . "</td><td style=background-color:#b7dee8>" . $row['entryDate'] . "</td></tr>";
    }
}

echo "</table>"; // Close the table for user picks
echo "<div style='width:80%; margin:6px auto 0 auto; color:#777; font-size:13px; font-family:Arial, sans-serif; text-align:left;'>(LP) - Late Pick &nbsp;&nbsp; (RD) - Replacement Driver</div>";

// Function to fetch driver tag
function getDriverTag($driverName, $group) {
    global $dbo, $raceYear;
    $driverName = str_replace("'", "''", (string)$driverName);
    $sql = "SELECT `Tag` FROM `" . $group . " Drivers` WHERE `driverName` = '$driverName' AND `driverYear` = $raceYear AND `Available` = 'Y'";
    $result = $dbo->query($sql);
    if ($result && $result->rowCount() > 0) {
        $row = $result->fetch(PDO::FETCH_ASSOC);
        return trim((string)($row['Tag'] ?? ''));
    } else {
        return '';
    }
}

function getReferencePickRow(array $row, array $rowsByPickId, ?array $baseRow): ?array {
    $supersedesPickID = (int)($row['supersedes_pickID'] ?? 0);
    if ($supersedesPickID > 0 && isset($rowsByPickId[$supersedesPickID])) {
        return $rowsByPickId[$supersedesPickID];
    }
    return $baseRow;
}

function getSpecialPickSuffix(array $row, ?array $referenceRow, string $field): string {
    $pickType = strtoupper((string)($row['pick_type'] ?? 'SEG'));

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

function formatDriverDisplay(array $row, ?array $referenceRow, string $field, string $group): string {
    $driverName = trim((string)($row[$field] ?? ''));
    if ($driverName === '') {
        return '';
    }

    $parts = [$driverName];

    $tag = getDriverTag($driverName, $group);
    if ($tag !== '') {
        $parts[] = $tag;
    }

    $special = getSpecialPickSuffix($row, $referenceRow, $field);
    if ($special !== '') {
        $parts[] = $special;
    }

    return implode(' ', $parts);
}

// Function to map segment names
function mapSegmentName($segment) {
    switch ($segment) {
        case 'S1':
            return 'Segment #1';
        case 'S2':
            return 'Segment #2';
        case 'S3':
            return 'Segment #3';
        case 'S4':
            return 'Playoffs';
        default:
            return '';
    }
}
?>
