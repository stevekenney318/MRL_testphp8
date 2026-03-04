<?php
// fix_combined2.php
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
<select id="teamDropdown">
    <option value="" selected disabled>Select Team</option>
</select>

<!-- Display the selected team on the screen -->
<div id="selectedTeamDisplay"></div>

<!-- Dropdown for selecting segment (hidden initially) -->
<select id="segmentDropdown">
    <option value="" selected disabled>Select Segment</option>
    <?php foreach ($segments as $segment) : ?>
        <option value="<?php echo $segment; ?>"><?php echo $segment; ?></option>
    <?php endforeach; ?>
</select>

<!-- Display the selected segment on the screen -->
<div id="selectedSegmentDisplay"></div>

<!-- Display the fetched data from user_picks -->
<div id="userPicksData"></div>

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
    document.getElementById("segmentDropdown").selectedIndex = 0;

    // Reset the selected segment display
    document.getElementById("selectedSegmentDisplay").innerText = "";

    // Load teams based on the selected year
    loadTeams(selectedYear);
});

document.getElementById("teamDropdown").addEventListener("change", function () {
    var selectedUserID = this.value;
    var selectedYear = document.getElementById("yearDropdown").value;

    // Reset the selected variables display
    resetSelectedVariablesDisplay();

    // Find the selected team information using the team ID
    var selectedTeamInfo = <?php echo json_encode($uniqueTeamsByYear); ?>[selectedYear][selectedUserID];

    // Display the selected team on the screen
    var displayText = selectedTeamInfo['teamName'];

    // Optionally, display the user name as well
    if (selectedTeamInfo['userName']) {
        displayText += " - " + selectedTeamInfo['userName'];
    }

    document.getElementById("selectedTeamDisplay").innerText = displayText;

    // Reset the segment dropdown to its initial state
    document.getElementById("segmentDropdown").selectedIndex = 0;

    // Reset the selected segment display
    document.getElementById("selectedSegmentDisplay").innerText = "";

    // Show the segment dropdown
    document.getElementById("segmentDropdown").style.display = "block";
});

document.getElementById("segmentDropdown").addEventListener("change", function () {
    // Display the selected segment on the screen
    document.getElementById("selectedSegmentDisplay").innerText = "Selected Segment: " + this.value;

    var selectedSegment = this.value;
    var selectedYear = document.getElementById("yearDropdown").value;
    var selectedUserID = document.getElementById("teamDropdown").value;

    // Check if both team and segment are selected before fetching data
    if (selectedUserID && selectedSegment) {
        // Fetch and display data from user_picks based on the selected segment, year, and user ID
        fetchUserPicksData(selectedSegment, selectedYear, selectedUserID);
    }
});

function resetSelectedVariablesDisplay() {
    // Reset the display of selected variables
    document.getElementById("userPicksData").innerHTML = "";
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

function fetchUserPicksData(selectedSegment, selectedYear, selectedUserID) {
    // Use JavaScript to send the selected segment, year, and user ID to a PHP script via AJAX
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "fix_combined.php", true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            // Handle the response from the server for user_picks and user_picks_history
            var userPicksData = JSON.parse(xhr.responseText);

            // Display the fetched data from both tables
            displayUserData(userPicksData.user_picks, "User Picks Data:");
            displayUserData(userPicksData.user_picks_history, "User Picks History Data:");
        }
    };

    // Send the selected segment, year, and user ID to the PHP script (fix_combined.php)
    xhr.send("segment=" + selectedSegment + "&year=" + selectedYear + "&userID=" + selectedUserID);
}

function displayUserData(data, header) {
    var userPicksDataContainer = document.getElementById("userPicksData");

    // Display header
    userPicksDataContainer.innerHTML += "<br>" + header + "<br>";

    // Check if data is available
    if (data.length > 0) {
        // Loop through the data and display it
        data.forEach(function (row) {
            userPicksDataContainer.innerHTML += row.driverA + " , " + row.driverB + " , " + row.driverC + " , " + row.driverD + ", " + row.entryDate + "<br>";
        });
    } else {
        // No data found
        userPicksDataContainer.innerHTML += 'No data found for the selected segment, year, and userID<br>';
    }
}

</script>

</body>
</html>
