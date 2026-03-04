<?php
date_default_timezone_set("America/New_York");
include "config.php"; // setup variables for database connection 
include "config_mrl.php"; // setup variables for the current MRL season & segment

// find unique userID
session_start();

foreach ($_SESSION as $key => $val);

// Debugging: Output the userID
echo "UserID: $val";

// include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
        padding: 3px;
        font-size: 16px;
      }
   </style>";

// Table
echo "<table align=center style=width:80%>"; // start a table tag in the HTML
echo "<tr style=background-color:#fabf8f>";
echo "<th colspan=6>$raceYear $sitename Season</th>";

// Paid Status
$sql = "SELECT * FROM `user_teams` WHERE `userID` = ? AND `raceYear` = ?";
$stmt = mysqli_prepare($dbconnect, $sql);
mysqli_stmt_bind_param($stmt, "ii", $val, $raceYear);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Paid Status' . "</td><td style=background-color:#b7dee8>" . $row['paidStatus'] . "</td><td style=background-color:#b7dee8>" . ' ' . "</td></tr>";
        $DBpaidStatus = $row['paidStatus'];
    }
}

// Paid Amount
$result = mysqli_stmt_get_result($stmt);

if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Amount' . "</td><td style=background-color:#b7dee8>" . "$" . $row['paidAmount'] . "</td><td style=background-color:#b7dee8>" . ' ' . "</td></tr>";
        $DBpaidStatus = $row['paidAmount'];
    }
}

// Team Name
$result = mysqli_stmt_get_result($stmt);

if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr><td style=width:14%;background-color:#f2dcdb>" . 'Team Name' . "</td><td style=background-color:#f2dcdb>" . $row['teamName'] . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td></tr>";
        $DBteamName = $row['teamName'];
    }
}

// Team Owner
$sql = "SELECT * FROM `users` WHERE `userID` = ?";
$stmtOwner = mysqli_prepare($dbconnect, $sql);
mysqli_stmt_bind_param($stmtOwner, "i", $val);
mysqli_stmt_execute($stmtOwner);
$resultOwner = mysqli_stmt_get_result($stmtOwner);

if ($resultOwner && mysqli_num_rows($resultOwner) > 0) {
    while ($row = mysqli_fetch_assoc($resultOwner)) {
        echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Team Owner' . "</td><td style=background-color:#f2dcdb>" . $row['userName'] . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td>";
        $DBuserName = $row['userName'];
    }
}

// Email addresses
$resultEmail = mysqli_stmt_get_result($stmtOwner);

if ($resultEmail && mysqli_num_rows($resultEmail) > 0) {
    while ($row = mysqli_fetch_assoc($resultEmail)) {
        echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Email Address(es)' . "</td><td style=background-color:#f2dcdb>" . $row['userEmail'] . "</td><td style=background-color:#f2dcdb>" . $row['userEmail2'] . "</td></tr>";
        $DBuserEmail = $row['userEmail'];
    }
}

echo "</table>"; //Close the table in HTML

// Table Heading
echo "<table align=center style=width:80%>"; // start a table tag in the HTML
echo "<tr style=background-color:#fabf8f>";
echo "<th style=width:14%>$raceYear</th><th style=width:17%>Group A</th><th style=width:17%>Group B</th><th style=width:17%>Group C</th><th style=width:17%>Group D</th><th style=width:18%>Submission Time</th></tr>";

// Segment 1
$sqlSegments = "SELECT segment FROM segments";
$stmtSegments = mysqli_prepare($dbconnect, $sqlSegments);
mysqli_stmt_execute($stmtSegments);
$resultSegments = mysqli_stmt_get_result($stmtSegments);

if ($resultSegments && mysqli_num_rows($resultSegments) > 0) {
    while ($row = mysqli_fetch_assoc($resultSegments)) {
        $segment = $row['segment'];
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . "Segment #$segment" . "</td>";
        
        // Your existing code for displaying the picks for each segment goes here
        
        echo "</tr>";
    }
}

// Close the statement
mysqli_stmt_close($stmt);
mysqli_stmt_close($stmtOwner);
mysqli_stmt_close($stmtSegments);
?>
