<?php
// Paid_Status.php

// ------------------------------------------------------------
// Session + auth
// ------------------------------------------------------------
session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

$isAdmin = isAdmin($_SESSION['userSession'] ?? null);

$adminStatusLine = $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

// ------------------------------------------------------------
// Timezone logic (UNCHANGED)
// ------------------------------------------------------------
$nyTime   = time();
$nyZone   = new DateTimeZone("America/New_York");
$nyOffset = $nyZone->getOffset(new DateTime("@$nyTime")) / 3600;

$utcTime   = time();
$utcZone   = new DateTimeZone("UTC");
$utcOffset = $utcZone->getOffset(new DateTime("@$utcTime")) / 3600;

$offset = $nyOffset - $utcOffset;

$sql = "SET time_zone = '$offset:00';";
$currentTimeFromDB = null;
$tzError = '';

if (mysqli_query($dbconnect, $sql)) {
    $result = mysqli_query($dbconnect, "SELECT NOW()");
    if ($result) {
        $row = mysqli_fetch_row($result);
        $currentTimeFromDB = $row[0];
    } else {
        $tzError = mysqli_error($dbconnect);
    }
} else {
    $tzError = mysqli_error($dbconnect);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>MRL Paid Status</title>

    <link rel="stylesheet" href="/mrl-styles.css">

    <style>
        body {
            padding-top: 0;
        }
    </style>
</head>
<body>

<?php
// ------------------------------------------------------------
// Admin status (first visible output)
// ------------------------------------------------------------
echo $adminStatusLine;

if (!$isAdmin) {
    exit;
}

// ------------------------------------------------------------
// Timezone diagnostics (UNCHANGED behavior)
// ------------------------------------------------------------
echo "<div>America/New_York offset: $nyOffset</div>";

if ($currentTimeFromDB) {
    echo "<div>Timezone set successfully to $currentTimeFromDB (America/New_York)</div>";
} elseif ($tzError) {
    echo "<div>Error setting timezone: $tzError</div>";
}
?>

<?php
// ------------------------------------------------------------
// Paid Status (UNCHANGED logic)
// ------------------------------------------------------------
$sql = "SELECT *
        FROM `Financial`
        WHERE `raceYear` = '$raceYear'
          AND `userActive` = 'Y'
          AND `userID` != 0
        ORDER BY `raceYear` DESC";

echo "<style type='text/css'>
        table, th, td {
            border: 1px solid black;
            border-collapse: collapse;
            padding: 3px;
        }
      </style>";

echo "<table align='center'>";
echo "<tr style='background-color:#fabf8f; color:#222222;'>";
echo "<th colspan='6'>$raceYear Paid Status</th>";
echo "</tr>";

echo "<tr style='background-color:#fabf8f; color:#222222;'>";
echo "<th>Team</th><th>Owner</th><th>Status</th><th>Amount</th><th>How</th><th>Comments</th>";
echo "</tr>";

foreach ($dbo->query($sql) as $row) {
    echo "<tr>";
    echo "<td style='background-color:#b7dee8; color:#222222; font-size:13pt; line-height:140%; font-family:Helvetica Neue,Helvetica,Arial,sans-serif;'>{$row['teamName']}</td>";
    echo "<td style='background-color:#b7dee8; color:#222222; font-size:13pt; line-height:140%; font-family:Helvetica Neue,Helvetica,Arial,sans-serif;'>{$row['userName']}</td>";
    echo "<td style='background-color:#b7dee8; color:#222222; font-size:13pt; line-height:140%; font-family:Helvetica Neue,Helvetica,Arial,sans-serif;'>{$row['paidStatus']}</td>";
    echo "<td style='background-color:#b7dee8; color:#222222; font-size:13pt; line-height:140%; font-family:Helvetica Neue,Helvetica,Arial,sans-serif;'>\${$row['paidAmount']}</td>";
    echo "<td style='background-color:#b7dee8; color:#222222; font-size:13pt; line-height:140%; font-family:Helvetica Neue,Helvetica,Arial,sans-serif;'>{$row['paidHow']}</td>";
    echo "<td style='background-color:#b7dee8; color:#222222; font-size:13pt; line-height:140%; font-family:Helvetica Neue,Helvetica,Arial,sans-serif;'>{$row['paidComment']}</td>";
    echo "</tr>";
}
echo "</table>";

// Totals
$sql = "SELECT SUM(paidAmount) AS Total
        FROM Financial
        WHERE raceYear = $raceYear";

echo "<table align='center'>";
echo "<tr style='background-color:#fabf8f; color:#222222; font-size:13pt; line-height:140%; font-family:Helvetica Neue,Helvetica,Arial,sans-serif;'>";
echo "<th>$raceYear Total</th>";
echo "</tr>";

foreach ($dbo->query($sql) as $row) {
    echo "<tr><td style='background-color:#d8e4bc; color:#222222; font-size:13pt; line-height:140%; font-family:Helvetica Neue,Helvetica,Arial,sans-serif;'>\${$row['Total']}</td></tr>";
}
echo "</table>";
?>

</body>
</html>
