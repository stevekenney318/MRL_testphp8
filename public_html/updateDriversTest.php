<?php
// Start the session and include necessary files
session_start();
require_once 'class.user.php';
$user_home = new USER();

// Redirect to login if not logged in
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
}

// Include header and configuration files
// include 'header.php';
require "config.php";
require "config_mrl.php";

// Set the time zone and get the current time
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

// Check if the user is an admin
if (!isAdmin($_SESSION['userSession'])) {
    echo '<div style="color: red;">You are NOT authorized to view/use this page</div>';
    die();
} else {
    echo '<div style="color: green;">You are authorized to view/use this page</div>';
}

// Reusable function to check for SQL errors
function checkSQLError($dbconnect) {
    if (mysqli_errno($dbconnect) != 0) {
        echo "SQL Error: " . mysqli_error($dbconnect);
    }
}

// HTML and JavaScript section
?>

<!-- Original dropdown and colored chart -->
<div style="display: flex; justify-content: center;">
    <form method="post" action="" id="yearSelectionForm">
        <select name="year">
            <option value="">Select Race Year</option>
            <?php
            $stmt = mysqli_prepare($dbconnect, "SELECT year FROM years WHERE year != '0000'");
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_array($result)) {
                $year = $row['year'];
                echo "<option value=\"$year\">$year</option>";
            }
            ?>
        </select>
    </form>
</div>

<script>
    const yearSelect = document.querySelector('select[name="year"]');
    const yearSelectionForm = document.getElementById('yearSelectionForm');

    yearSelect.addEventListener('change', function () {
        yearSelectionForm.submit();
    });
</script>

<?php

// Check if a year has been selected
if (isset($_POST['year'])) {
    $selectedYear = $_POST['year'];

    // Fetch the modification status for the selected year
    $stmt = mysqli_prepare($dbconnect, "SELECT lockDrivers	 FROM years WHERE year = ?");
    mysqli_stmt_bind_param($stmt, "s", $selectedYear);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $modifyDrivers);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    // Display drivers for the selected year
    $driverGroups = ['A', 'B', 'C', 'D'];
    $driverData = [];

    foreach ($driverGroups as $group) {
        $stmt = mysqli_prepare($dbconnect, "SELECT driverName FROM `$group Drivers` WHERE driverYear = ?");
        mysqli_stmt_bind_param($stmt, "s", $selectedYear);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $driverData[$group] = [];

        while ($row = mysqli_fetch_array($result)) {
            $driverData[$group][] = $row['driverName'];
        }
    }

    $hasDrivers = false;

    foreach ($driverData as $groupDrivers) {
        if (!empty($groupDrivers)) {
            $hasDrivers = true;
            break;
        }
    }

    echo "<div style='display: flex; justify-content: center;'>";
    echo "<table style='text-align: center;'>";
    echo "<tr><th colspan='4' style='background-color:#fabf8f; color:#333333; text-align: center;'>{$selectedYear}</th></tr>";
    echo "<tr style='background-color:#fabf8f; color:#333333;'><th style='text-align: center;'>Group A</th><th style='text-align: center;'>Group B</th><th style='text-align: center;'>Group C</th><th style='text-align: center;'>Group D</th></tr>";

    $maxRows = max(array_map('count', $driverData));

    for ($i = 0; $i < $maxRows; $i++) {
        echo "<tr style='font-family: Tahoma,Verdana,Segoe,sans-serif; font-size:13.0pt; color: #000000;'>";

        foreach ($driverGroups as $group) {
            $driver = isset($driverData[$group][$i]) ? $driverData[$group][$i] : '';
            $backgroundColor = !empty($driver) ? "background-color:" . getBackgroundColor($group) . "; padding: 1px; text-align: left;" : "padding: 1px; text-align: left;";

            echo "<td style='$backgroundColor'>$driver</td>";
        }

        echo "</tr>";
    }

    if ($maxRows == 0) {
        echo "<tr><td colspan='4' style='color: red; text-align: center;'>No drivers found for the selected year.</td></tr>";
    }

    echo "</table>";
    echo "</div>";
    echo "<br>";

    // New dropdown for driver names from the "drivers" table
    if ($modifyDrivers !== 'N') {
        echo "<div style='display: flex; justify-content: center;'>";
        echo "<form method='post' action=''>";
        echo "<select name='selectedDriverName'>";
        
        $stmt = mysqli_prepare($dbconnect, "SELECT driverName FROM drivers");
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        echo "<option value=''>Select Driver</option>";

        while ($row = mysqli_fetch_array($result)) {
            $driverName = $row['driverName'];
            echo "<option value='$driverName'>$driverName</option>";
        }

        echo "</select>";
        echo "</form>";
        echo "</div>";
    }

    // Display the modification status message below the table
    if ($modifyDrivers === 'N') {
        echo "<p style='color: red; text-align: center;'>The modification of drivers for this year is currently set to \"N\". Please change it to \"Y\" to enable modifications.</p>";
    }
} else {
    echo "<p>No year selected.</p>";
}

// Define a function to get background color
function getBackgroundColor($group) {
    switch ($group) {
        case 'A':
            return '#d9d9d9';
        case 'B':
            return '#c4bd97';
        case 'C':
            return '#b8cce4';
        case 'D':
            return '#d8e4bc';
        default:
            return '#ffffff'; // Default background color if group is not found
    }
}
?>