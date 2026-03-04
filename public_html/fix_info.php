<?php 
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