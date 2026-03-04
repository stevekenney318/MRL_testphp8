<?php
// fix_merged3.php
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
<select id="yearDropdown">
    <option value="" selected disabled>Select Year</option>
    <?php foreach ($years as $year) : ?>
        <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
    <?php endforeach; ?>
</select>

<!-- Display the selected year on the screen -->
<div id="selectedYearDisplay"></div>

<!-- Dropdown for selecting team (hidden initially) -->
<select id="teamDropdown" style="display: none;">
    <option value="" selected disabled>Select Team</option>
</select>

<!-- Display the selected team on the screen -->
<div id="selectedTeamDisplay"></div>

<!-- Dropdown for selecting segment (hidden initially) -->
<select id="segmentDropdown" style="display: none;">
    <option value="" selected disabled>Select Segment</option>
    <?php foreach ($segments as $segment) : ?>
        <option value="<?php echo $segment; ?>"><?php echo $segment; ?></option>
    <?php endforeach; ?>
</select>

<!-- Display the selected segment on the screen -->
<div id="selectedSegmentDisplay"></div>

<!-- Display the selected variables on the screen -->
<div id="selectedVariablesDisplay"></div>

<!-- JavaScript to update the variable and display the selected year, team, and segment -->
<script>
    document.getElementById("yearDropdown").addEventListener("change", function () {
        // Reset the selected variables display
        resetSelectedVariablesDisplay();

        var selectedYear = this.value;
        // Display the selected year on the screen
        document.getElementById("selectedYearDisplay").innerText = "Selected Year: " + selectedYear;

        // Show the team dropdown
        document.getElementById("teamDropdown").style.display = "block";

        // Reset the team dropdown to its initial state
        document.getElementById("teamDropdown").selectedIndex = 0;

        // Reset the selected team display
        document.getElementById("selectedTeamDisplay").innerText = "";

        // Reset the segment dropdown to its initial state
        document.getElementById("segmentDropdown").style.display = "none";
        document.getElementById("segmentDropdown").selectedIndex = 0;

        // Reset the selected segment display
        document.getElementById("selectedSegmentDisplay").innerText = "";

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
        var uniqueTeams = <?php echo json_encode($uniqueTeamsByYear); ?>;

        // Reset the selected variables display
        resetSelectedVariablesDisplay();
        
        // Find the selected team information using the team ID
        var selectedTeamInfo = uniqueTeams[selectedYear][selectedUserID];

        // Display the selected team on the screen
        var displayText = selectedTeamInfo['teamName'];

        // Optionally, display the user name as well
        if (selectedTeamInfo['userName']) {
            displayText += " - " + selectedTeamInfo['userName'];
        }

        document.getElementById("selectedTeamDisplay").innerText = displayText;

        // Show the segment dropdown
        document.getElementById("segmentDropdown").style.display = "block";

        // Reset the segment dropdown to its initial state
        document.getElementById("segmentDropdown").selectedIndex = 0;

        // Reset the selected segment display
        document.getElementById("selectedSegmentDisplay").innerText = "";

        // Store the selected team ID in a variable for later use
        var selectedUserID = this.value;
    });

    document.getElementById("segmentDropdown").addEventListener("change", function () {
        // Display the selected segment on the screen
        document.getElementById("selectedSegmentDisplay").innerText = "Selected Segment: " + this.value;

        // Store the selected segment in a variable for later use
        var selectedSegment = this.value;

        // Store the selected variables and their values
        var selectedYear = document.getElementById("yearDropdown").value;
        var selectedUserID = document.getElementById("teamDropdown").value;

        // Display the selected variables and their values on the screen
        var selectedVariablesDisplay = document.getElementById("selectedVariablesDisplay");
        if (selectedVariablesDisplay) {
            selectedVariablesDisplay.innerHTML = "<br>$selectedYear: " + selectedYear + "<br>" +
                "$selectedUserID: " + selectedUserID + "<br>" +
                "$selectedSegment: " + selectedSegment;
        }

        // Display the selected variables and their values in the console log
        console.log('$selectedYear:', selectedYear);
        console.log('$selectedUserID:', selectedUserID);
        console.log('$selectedSegment:', selectedSegment);
    });
</script>

<?php
// Your SQL parameters
$selectedYear = 2017;
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

</body>
</html>
