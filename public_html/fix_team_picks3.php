<?php
// Combined Script
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

// Initialize variables to store user input from dropdowns
$selectedYear = '';
$selectedUserID = '';
$selectedSegment = '';

// Get years
$stmtYears = mysqli_prepare($dbconnect, "SELECT year FROM years WHERE year != '0000'");
mysqli_stmt_execute($stmtYears);
mysqli_stmt_bind_result($stmtYears, $selectedYear);

// Fetch years and store them in an array
$years = array();
while (mysqli_stmt_fetch($stmtYears)) {
    $years[] = $selectedYear;
}

// Close the statement
mysqli_stmt_close($stmtYears);

// Get all teams for all years with error handling
$stmtTeams = mysqli_prepare($dbconnect, "SELECT t.userID, t.teamName, t.raceYear, u.userName FROM user_teams t
                                         LEFT JOIN users u ON t.userID = u.userID");

if ($stmtTeams === false) {
    die("Error in the prepared statement: " . mysqli_error($dbconnect));
}

mysqli_stmt_execute($stmtTeams);

// error handling using mysqli_stmt_errno
if (mysqli_stmt_errno($stmtTeams) != 0) {
    die("Error in the execution of the prepared statement: " . mysqli_stmt_error($stmtTeams));
}

mysqli_stmt_bind_result($stmtTeams, $userID, $teamName, $raceYear, $userName);

// Fetch teams and store unique team names in an associative array by year
$uniqueTeamsByYear = array();
while (mysqli_stmt_fetch($stmtTeams)) {
    // Exclude teams with userID of 0
    if ($userID != 0) {
        $uniqueTeamsByYear[$raceYear][$userID] = array('teamName' => $teamName, 'userName' => $userName);
    }
}

// Close the statement
mysqli_stmt_close($stmtTeams);

// Get segments
$stmtSegments = mysqli_prepare($dbconnect, "SELECT segment FROM segments");
mysqli_stmt_execute($stmtSegments);
mysqli_stmt_bind_result($stmtSegments, $segment);

// Fetch segments and store them in an array
$segments = array();
while (mysqli_stmt_fetch($stmtSegments)) {
    $segments[] = $segment;
}

// Close the statement
mysqli_stmt_close($stmtSegments);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MRL</title>
</head>
<body>

<!-- Dropdown for selecting year -->
<form method="post" action="">
    <select id="yearDropdown" name="selectedYear">
        <option value="" selected disabled>Select Year</option>
        <?php foreach ($years as $year) : ?>
            <option value="<?php echo $year; ?>" <?php echo ($year === $selectedYear) ? 'selected' : ''; ?>><?php echo $year; ?></option>
        <?php endforeach; ?>
    </select>

    <!-- Dropdown for selecting team (hidden initially) -->
    <select id="teamDropdown" name="selectedUserID" style="display: none;">
        <option value="" selected disabled>Select Team</option>
    </select>

    <!-- Dropdown for selecting segment (hidden initially) -->
    <select id="segmentDropdown" name="selectedSegment" style="display: none;">
        <option value="" selected disabled>Select Segment</option>
        <?php foreach ($segments as $segment) : ?>
            <option value="<?php echo $segment; ?>" <?php echo ($segment === $selectedSegment) ? 'selected' : ''; ?>><?php echo $segment; ?></option>
        <?php endforeach; ?>
    </select>

    <input type="submit" value="Submit" style="display: none;">
</form>

<!-- Display the selected variables on the screen -->
<div id="selectedVariablesDisplay"></div>

<!-- JavaScript to update the variable and display the selected year, team, and segment -->
<script>
    document.getElementById("yearDropdown").addEventListener("change", function () {
        // Reset the selected variables display
        resetSelectedVariablesDisplay();

        var selectedYear = this.value;
        // Show the team dropdown
        document.getElementById("teamDropdown").style.display = "block";

        // Reset the team dropdown to its initial state
        document.getElementById("teamDropdown").selectedIndex = 0;

        // Reset the segment dropdown to its initial state
        document.getElementById("segmentDropdown").style.display = "none";
        document.getElementById("segmentDropdown").selectedIndex = 0;

        // Load teams based on the selected year
        loadTeams(selectedYear);
    });

    function resetSelectedVariablesDisplay() {
        // Reset the display of selected variables
        document.getElementById("selectedVariablesDisplay").innerHTML = "";
    }

    function loadTeams(selectedYear) {
        var teamDropdown = document.getElementById("teamDropdown");
        var uniqueTeams = <?php echo json_encode($uniqueTeamsByYear); ?>;

        // Clear existing options, excluding the "Select Team" option
        teamDropdown.options.length = 1;

        // Add options to the team dropdown
        var teamsInSelectedYear = uniqueTeams[selectedYear] || {};
        Object.keys(teamsInSelectedYear).forEach(function (userID) {
            var option = document.createElement("option");
            option.value = userID;
            option.text = teamsInSelectedYear[userID]['teamName'];
            teamDropdown.add(option);
        });
    }

    document.getElementById("teamDropdown").addEventListener("change", function () {
        var selectedUserID = this.value;
        var selectedYear = document.getElementById("yearDropdown").value;

        // Reset the selected variables display
        resetSelectedVariablesDisplay();

        // Show the segment dropdown
        document.getElementById("segmentDropdown").style.display = "block";

        // Reset the segment dropdown to its initial state
        document.getElementById("segmentDropdown").selectedIndex = 0;

        // Display the selected variables on the screen
        document.getElementById("selectedVariablesDisplay").innerText =
            "Selected Year: " + selectedYear + "\n" +
            "Selected UserID: " + selectedUserID;

        // Load segments based on the selected year and team
        loadSegments(selectedYear, selectedUserID);
    });

    function loadSegments(selectedYear, selectedUserID) {
        var segmentDropdown = document.getElementById("segmentDropdown");
        var uniqueSegments = <?php echo json_encode($segments); ?>;

        // Clear existing options, excluding the "Select Segment" option
        segmentDropdown.options.length = 1;

        // Add options to the segment dropdown
        uniqueSegments.forEach(function (segment) {
            var option = document.createElement("option");
            option.value = segment;
            option.text = segment;
            segmentDropdown.add(option);
        });
    }

    document.getElementById("segmentDropdown").addEventListener("change", function () {
        // Display the selected variables on the screen
        document.getElementById("selectedVariablesDisplay").innerText =
            "Selected Year: " + document.getElementById("yearDropdown").value + "\n" +
            "Selected UserID: " + document.getElementById("teamDropdown").value + "\n" +
            "Selected Segment: " + this.value;

        // Show the submit button
        document.querySelector('input[type="submit"]').style.display = "block";
    });
</script>

<!-- Display the data from the database -->
<?php
// Check if the form is submitted via dropdowns
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get user input from the dropdowns
    $selectedYear = $_POST['selectedYear'];
    $selectedUserID = $_POST['selectedUserID'];
    $selectedSegment = $_POST['selectedSegment'];

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
        echo "<div id='$table-label' style='display:none;'>$table: </div>";

        // Fetch the results and display as a concise comma-separated list with the table name
        $results = array();
        while (mysqli_stmt_fetch($stmt)) {
            $results[] = "$driverA, $driverB, $driverC, $driverD, $entryDate";
        }

        // Output the results with line breaks
        echo "<div id='$table' style='display:none;'>";
        echo implode(" <br>$table: ", $results);
        echo "</div>";

        // Close the statement for the current table
        mysqli_stmt_close($stmt);

        // Output a line break after each table's results, except for the last table
        if ($table !== end($tables)) {
            echo "<br><br>";
        }

        // Use JavaScript to show the table data after the form is submitted
        echo "<script>document.addEventListener('DOMContentLoaded', function() {";
        echo "document.getElementById('$table-label').style.display = 'block';";
        echo "document.getElementById('$table').style.display = 'block';";
        echo "});</script>";
    }
}
?>

</body>
</html>
