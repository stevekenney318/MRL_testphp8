<?php
// Start the session and include necessary files
session_start();
require_once 'class.user.php';
require 'config.php';
require 'config_mrl.php';

// Create a new USER object
$user_home = new USER();

// Redirect to login if not logged in
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
}

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

// Include header after admin check to avoid unnecessary processing
// include 'header.php';

// Set the time zone and get the current time
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

// Your SQL parameters
$selectedYear = 2023;
$selectedUserID = 1;
$selectedSegment = 'S1';

// Define the tables to query
$tables = array('user_picks', 'user_picks_history');

// Loop through each table
foreach ($tables as $table) {
    // Your SQL query for the current table
    $sql = "SELECT driverA, driverB, driverC, driverD, entryDate FROM $table WHERE raceYear = ? AND userID = ? AND segment = ?;";

    // Create a prepared statement for the current table
    $stmt = mysqli_prepare($dbconnect, $sql);

    // Bind parameters to the statement
    mysqli_stmt_bind_param($stmt, 'iss', $selectedYear, $selectedUserID, $selectedSegment);

    // Execute the statement
    mysqli_stmt_execute($stmt);

    // Bind the result variables
    mysqli_stmt_bind_result($stmt, $driverA, $driverB, $driverC, $driverD, $entryDate);

    // Display the table name
    echo "$table: ";

    // Fetch the results and display as a concise comma-separated list with the table name
    $results = array();
    while (mysqli_stmt_fetch($stmt)) {
        $results[] = "$driverA, $driverB, $driverC, $driverD, $entryDate";
    }

    // Output the results with line breaks
    echo implode(" <br>$table: ", $results);

    // Close the statement for the current table
    mysqli_stmt_close($stmt);

    // Output a line break after each table's results, except for the last table
    if ($table !== end($tables)) {
        echo "<br><br>";
    }
}
?>
