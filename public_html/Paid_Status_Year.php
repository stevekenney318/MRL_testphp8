<?php
// Paid_Status_Year.php

// ------------------------------------------------------------
// Session + admin enforcement (standard, consistent)
// ------------------------------------------------------------
session_start();

// Store the current page URL in the session
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

// Include necessary files after session start
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

// Create a new USER object
$user_home = new USER();

// Redirect to login if not logged in
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

// Check if the user is an admin
$isAdmin = isAdmin($_SESSION['userSession'] ?? null);

// Admin status line (uses global CSS in /mrl-styles.css)
$adminStatusLine = $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

// Preserve prior console logging behavior (optional)
$adminConsoleLog = $isAdmin
    ? "<script>console.log('Validated admin');</script>"
    : "<script>console.log('Validated non-admin');</script>";

// Set the time zone and get the current time
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

// Fetch years from the database
$yearOptions = '';
$sql = "SELECT year FROM `years` WHERE `year` > '0'";
foreach ($dbo->query($sql) as $row) {
    $year = $row['year'];
    $selected = ($year == $raceYear) ? 'selected' : '';
    $yearOptions .= "<option value='$year' $selected>$year</option>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>MRL Paid Status</title>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="/mrl-styles.css">
    <style>
        body {
            padding-top: 0px;
        }
    </style>
</head>
<body>

<?php
// ------------------------------------------------------------
// Admin status (first visible output)
// ------------------------------------------------------------
echo $adminStatusLine;
echo $adminConsoleLog;

if (!$isAdmin) {
    exit;
}
?>

<!-- Year Selection Dropdown -->
<form method="GET" action="">
    <label for="year">Select Year:</label>
    <select name="year" id="year">
        <?php echo $yearOptions; ?>
    </select>
    <input type="submit" value="Submit">
</form>

<?php
// Get the selected year from the dropdown or use the default $raceYear if not set
$selectedYear = isset($_GET['year']) ? $_GET['year'] : $raceYear;

// Paid Status
// $sql = "SELECT * FROM `Financial` WHERE `raceYear` = '$select...D `userActive` = 'Y' AND `userID`!= 0 ORDER BY `raceYear` DESC";
$sql = "SELECT * FROM `Financial` WHERE `raceYear` = '$selectedYear' AND `userID`!= 0 ORDER BY `raceYear` DESC";

echo "<style type='text/css'>table, th, td {border: 1px solid black;border-collapse: collapse;padding: 3px;}</style>";

echo "<table align=center>";
echo "<tr style='background-color:#fabf8f; color:#222222;'>";
echo "<th colspan=6>$selectedYear Paid Status</th>";
echo "</tr>";

echo "<tr style='background-color:#fabf8f; color:#222222;'>";
echo "<th>Team</th><th>Owner</th><th>Status</th><th>Amount</th><th>How</th><th>Comments</th>";
echo "</tr>";

foreach($dbo->query($sql) as $row){

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

$sql = "SELECT SUM(paidAmount) AS Total FROM Financial WHERE raceYear = $selectedYear";

echo "<table align=center>";
echo "<tr style='background-color:#fabf8f; color:#222222; font-size:13pt; line-height:140%; font-family:Helvetica Neue,Helvetica,Arial,sans-serif;'>";
echo "<th>$selectedYear Total</th>";
echo "</tr>";

foreach($dbo->query($sql) as $row){

    echo "<tr>";

    echo "<td style='background-color:#d8e4bc; color:#222222; font-size:13pt; line-height:140%; font-family:Helvetica Neue,Helvetica,Arial,sans-serif;'>\${$row['Total']}</td>";

    echo "</tr>";
}

echo "</table>";

?>

</body>
</html>
