<?php
// duplicate_teams.php 2025-01-26 01:48:56

// Start the session and include necessary files
session_start();
// Store the current page URL in the session
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];
// Include necessary files after session start
require_once 'class.user.php';
require 'config.php';
require 'config_mrl.php';

// Create a new USER object
$user_home = new USER();

// Redirect to login if not logged in
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
}

// Include header for MRL styling, etc.
// include 'header.php';


// ***** Admin ************************************************
// ************************************************************
// Check if the user is an admin
$isAdmin = isAdmin($_SESSION['userSession']);
// Display admin status
if ($isAdmin) {
    echo '<div style="color: green;">You are authorized to view/use this page</div>';
    echo "<script>console.log('Validated admin');</script>";
} else {
    echo '<div style="color: red;">You are NOT authorized to view/use this page</div>';
    echo "<script>console.log('Validated non-admin');</script>";
    die();
}
// ************************************************************

// ***** Set date EST/EDT for webpage and database ************
// ************************************************************
// Get the current time in America/New_York timezone
$nyTime = time();
$nyZone = new DateTimeZone("America/New_York");
$nyOffset = $nyZone->getOffset(new DateTime("@$nyTime")) / 3600;
echo "<div>America/New_York offset: $nyOffset</div>"; // Display America/New_York offset with line break

// Get the current time in UTC timezone
$utcTime = time();
$utcZone = new DateTimeZone("UTC");
$utcOffset = $utcZone->getOffset(new DateTime("@$utcTime")) / 3600;
echo "<div>UTC offset: $utcOffset</div>"; // Display UTC offset with line break

// Calculate the difference in hours between UTC and America/New_York
$offset = $nyOffset - $utcOffset;
echo "<div>Calculated offset: $offset</div>"; // Display calculated offset with line break

// Set the timezone for the current session to America/New_York with the calculated offset
$sql = "SET time_zone = '$offset:00';";
if (mysqli_query($dbconnect, $sql)) {
    // Get the current time from the database
    $query = "SELECT NOW()";
    $result = mysqli_query($dbconnect, $query);
    if ($result) {
        $row = mysqli_fetch_row($result);
        $currentTimeFromDB = $row[0];
        
        echo "<div>Timezone set successfully to $currentTimeFromDB (America/New_York)</div>"; // Display timezone set message with line break
    } else {
        echo "<div>Error retrieving current time: " . mysqli_error($dbconnect) . "</div>"; // Display error message with line break
    }
} else {
    echo "<div>Error setting timezone: " . mysqli_error($dbconnect) . "</div>"; // Display error message with line break
}
// ************************************************************

// ***** Begin ************************************************

try {
    // Begin a transaction
    mysqli_begin_transaction($dbconnect);

    // Retrieve all entries for raceYear 2024
    $stmt = mysqli_prepare($dbconnect, "SELECT userID, teamName FROM user_teams WHERE raceYear = ?");
    $raceYear2024 = "2024";
    mysqli_stmt_bind_param($stmt, "s", $raceYear2024);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    // Loop through all 2024 entries
    while ($row = mysqli_fetch_assoc($result)) {
        $userID = $row['userID'];
        $teamName = $row['teamName'];

        // Check if the team already exists for 2025
        $checkStmt = mysqli_prepare($dbconnect, "SELECT COUNT(*) FROM user_teams WHERE userID = ? AND teamName = ? AND raceYear = ?");
        $raceYear2025 = "2025";
        mysqli_stmt_bind_param($checkStmt, "iss", $userID, $teamName, $raceYear2025);
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        $exists = mysqli_fetch_array($checkResult)[0];

        // If the entry doesn't exist, insert it
        if ($exists == 0) {
            $insertStmt = mysqli_prepare($dbconnect, "INSERT INTO user_teams (userID, teamName, raceYear, paidStatus, paidAmount, paidHow, paidComment, entryDate) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $paidStatus = "";
            $paidAmount = "0.00";
            $paidHow = "";
            $paidComment = "";
            mysqli_stmt_bind_param($insertStmt, "issssss", $userID, $teamName, $raceYear2025, $paidStatus, $paidAmount, $paidHow, $paidComment);
            mysqli_stmt_execute($insertStmt);
        }
    }

    // Commit the transaction
    mysqli_commit($dbconnect);

    echo "<p style='color: #FFD700; font-weight: bold;'>Duplication for raceYear 2025 completed successfully!</p>";

} catch (Exception $e) {
    // Roll back the transaction on error
    mysqli_rollback($dbconnect);
    echo "<p style='color: red; font-weight: bold;'>An error occurred: " . $e->getMessage() . "</p>";
}

// Close the database connection
mysqli_close($dbconnect);
?>
