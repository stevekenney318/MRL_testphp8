<?php
// fetch_user_picks.php
require 'config.php';  // Adjust the path as needed

// Get the selected segment, year, and userID from the POST request
$selectedSegment = $_POST['segment'];
$selectedYear = $_POST['year'];
$selectedUserID = $_POST['userID'];

// Fetch and display data from user_picks
$stmtUserPicks = mysqli_prepare($dbconnect, "SELECT driverA, driverB, driverC, driverD, entryDate FROM user_picks WHERE segment = ? AND raceYear = ? AND userID = ?");
mysqli_stmt_bind_param($stmtUserPicks, "sss", $selectedSegment, $selectedYear, $selectedUserID);
mysqli_stmt_execute($stmtUserPicks);
mysqli_stmt_store_result($stmtUserPicks);

if (mysqli_stmt_num_rows($stmtUserPicks) > 0) {
    // Fetch and display the data from user_picks
    echo "<br>User Picks Data:<br>";
    mysqli_stmt_bind_result($stmtUserPicks, $driverA, $driverB, $driverC, $driverD, $entryDate);

    while (mysqli_stmt_fetch($stmtUserPicks)) {
        echo "$driverA , $driverB , $driverC , $driverD, $entryDate<br>";
    }
} else {
    // No data found for user_picks
    echo "<br>User Picks Data:<br>";
    echo 'No data found for the selected segment, year, and userID in user_picks<br>';
}

// Close the statement
mysqli_stmt_close($stmtUserPicks);
?>
