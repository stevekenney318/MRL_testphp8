<?php
// Start the session to access session variables
session_start();

// Import the USER class and create an instance
require_once 'class.user.php';
$user_home = new USER();

// // If the user is not logged in, redirect to the login page
// if (!$user_home->is_logged_in()) {
//     $user_home->redirect('login.php');
// }

// Import the config.php and config_mrl.php files
require "config.php";
require "config_mrl.php";

// Set the timezone to America/New_York and get the current time
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

// Prepare a mysqli statement to select all columns from the users table where userID matches the userSession variable in the session array
$stmt = mysqli_prepare($dbconnect, "SELECT * FROM users WHERE userID=?");
mysqli_stmt_bind_param($stmt, "i", $_SESSION['userSession']);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();

// Check if the database connection was successful
if (!$dbconnect) {
    // If not, print an error message and terminate the script
    die("Connection failed: " . mysqli_connect_error());
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" type="text/css" href="mrl-styles.css">
</head>
<body>

<label class="switch" style="float: right;">
    <input type="checkbox" id="mySlider">
    <span class="slider round"></span>
</label>

<script type="text/javascript">
    // Get the slider element
    var slider = document.getElementById("mySlider");

    // Add an event listener to the slider that changes the background color
    slider.addEventListener("change", function () {
        if (slider.checked) {
            // If the slider is checked, change the background color to white
            document.body.style.backgroundColor = "white";
        } else {
            // If the slider is not checked, change the background color to the previously saved color
            document.body.style.backgroundColor = "<?php echo $_SESSION['bg_color']; ?>";
        }
    });
</script>

<div style="display: flex; justify-content: center;">
    <form method="post" action="" id="yearForm">
        <select name="year" id="yearSelect" onchange="updateHiddenYear(this)">
            <option value="">Select Race Year</option>
            <?php
            // Query to get a list of years from the database
            $stmt = mysqli_prepare($dbconnect, "SELECT year FROM years WHERE year != '0000'");
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            // Loop through the results and create an option element for each year
            while ($row = mysqli_fetch_array($result)) {
                $year = $row['year'];
                echo "<option value=\"$year\">$year</option>";
            }
            ?>
        </select>
        <input type="hidden" name="year" id="hiddenYear">
        <input type="submit" id="submitForm" style="display: none;">
    </form>
</div>

<script>
    document.getElementById('yearSelect').addEventListener('change', function () {
        // Automatically trigger the form submission when a year is selected
        document.getElementById('submitForm').click();
    });
</script>
<script>
    function updateHiddenYear(select) {
        const hiddenYear = document.getElementById('hiddenYear');
        hiddenYear.value = select.value;
        document.getElementById('submitForm').click();
    }
</script>

<br>

<?php

echo '<button style="float: right;" onclick="window.print()">Print page</button>';

// Retrieve the selected year if available
$selectedYear = "";
if (isset($_POST['year'])) {
    $selectedYear = $_POST['year'];
    if ($selectedYear == '') {
        echo "<p>No year selected.</p>";
    } else {

        // Retrieve A drivers
        $stmtA = mysqli_prepare($dbconnect, "SELECT driverName, Tag FROM `A Drivers` WHERE driverYear = ?");
        mysqli_stmt_bind_param($stmtA, "s", $selectedYear);
        mysqli_stmt_execute($stmtA);
        $resultA = mysqli_stmt_get_result($stmtA);

        // Retrieve B drivers
        $stmtB = mysqli_prepare($dbconnect, "SELECT driverName, Tag FROM `B Drivers` WHERE driverYear = ?");
        mysqli_stmt_bind_param($stmtB, "s", $selectedYear);
        mysqli_stmt_execute($stmtB);
        $resultB = mysqli_stmt_get_result($stmtB);

        // Retrieve C drivers
        $stmtC = mysqli_prepare($dbconnect, "SELECT driverName, Tag FROM `C Drivers` WHERE driverYear = ?");
        mysqli_stmt_bind_param($stmtC, "s", $selectedYear);
        mysqli_stmt_execute($stmtC);
        $resultC = mysqli_stmt_get_result($stmtC);

        // Retrieve D drivers
        $stmtD = mysqli_prepare($dbconnect, "SELECT driverName, Tag FROM `D Drivers` WHERE driverYear = ?");
        mysqli_stmt_bind_param($stmtD, "s", $selectedYear);
        mysqli_stmt_execute($stmtD);
        $resultD = mysqli_stmt_get_result($stmtD);

        // Check if any drivers were found for the selected year
        if (mysqli_num_rows($resultA) > 0 || mysqli_num_rows($resultB) > 0 || mysqli_num_rows($resultC) > 0 || mysqli_num_rows($resultD) > 0) {
            // Table Heading
            echo "<div style='display: flex; justify-content: center;'>";
            echo "<table style='text-align: center;'>";
            echo "<tr><th colspan='4' style='background-color:#fabf8f; color:#333333; text-align: center;'>{$selectedYear}</th></tr>";
            echo "<tr style='background-color:#fabf8f; color:#333333;'><th style='text-align: center;'>Group A</th><th style='text-align: center;'>Group B</th><th style='text-align: center;'>Group C</th><th style='text-align: center;'>Group D</th></tr>";

            // Loop through drivers A-D and display them in the table
            $maxRows = max(mysqli_num_rows($resultA), mysqli_num_rows($resultB), mysqli_num_rows($resultC), mysqli_num_rows($resultD));

            for ($i = 0; $i < $maxRows; $i++) {
                $rowA = mysqli_fetch_array($resultA);
                $rowB = mysqli_fetch_array($resultB);
                $rowC = mysqli_fetch_array($resultC);
                $rowD = mysqli_fetch_array($resultD);
                $driverA = isset($rowA['driverName']) ? $rowA['driverName'] . " " . $rowA['Tag'] : '';
                $driverB = isset($rowB['driverName']) ? $rowB['driverName'] . " " . $rowB['Tag'] : '';
                $driverC = isset($rowC['driverName']) ? $rowC['driverName'] . " " . $rowC['Tag'] : '';
                $driverD = isset($rowD['driverName']) ? $rowD['driverName'] . " " . $rowD['Tag'] : '';

                echo "<tr style=\"font-family: Tahoma,Verdana,Segoe,sans-serif; font-size:13.0pt; color: #000000;\">";

                echo "<td style='background-color:#d9d9d9; padding: 1px; text-align: left;'>";
                if (!empty($driverA)) {
                    echo "<input type='checkbox' name='driver[]' value='{$driverA}'>&nbsp;";
                }
                echo $driverA . "</td>";

                echo "<td style='background-color:#c4bd97; padding: 1px; text-align: left;'>";
                if (!empty($driverB)) {
                    echo "<input type='checkbox' name='driver[]' value='{$driverB}'>&nbsp;";
                }
                echo $driverB . "</td>";

                echo "<td style='background-color:#b8cce4; padding: 1px; text-align: left;'>";
                if (!empty($driverC)) {
                    echo "<input type='checkbox' name='driver[]' value='{$driverC}'>&nbsp;";
                }
                echo $driverC . "</td>";

                echo "<td style='background-color:#d8e4bc; padding: 1px; text-align: left;'>";
                if (!empty($driverD)) {
                    echo "<input type='checkbox' name='driver[]' value='{$driverD}'>&nbsp;";
                }
                echo $driverD . "</td>";

                echo "</tr>";
            }

            if ($maxRows == 0) {
                echo "<tr><td colspan='4' style='color: red; text-align: center;'>No drivers found for the selected year.</td></tr>";
            }

            echo "</table>";
            echo "</div>";

        } else {
            echo "<div style='display: flex; justify-content: center; flex-direction: column; align-items: center;'>";
            echo "<table style='text-align: center;'>";
            echo "<tr><th colspan='4' style='background-color:#fabf8f; color:#333333;'>{$selectedYear}</th></tr>";
            echo "<tr style='background-color:#fabf8f; color:#333333;'><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th></tr>";
            echo "</table>";
            echo "<p style='color: red; text-align: center;'>No drivers found for the selected year.</p>";
            echo "</div>";
        }
    }
}
?>

<script>
    let element = document.getElementById("mybody"); // retrieves the body element with the ID of "mybody"
    let originalColor = element.style.backgroundColor; // saves the original background color of the body element

    let toggle = () => { // defines a function named "toggle" using ES6 arrow function syntax
        if (element.style.backgroundColor === "white") { // checks if the body background color is white
            element.style.backgroundColor = originalColor; // changes the body background color to the original color if it's currently white
        } else {
            element.style.backgroundColor = "white"; // changes the body background color to white if it's currently not white
        }
    }
</script>

</body>
</html>
