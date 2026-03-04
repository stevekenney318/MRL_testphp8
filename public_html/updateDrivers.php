<?php
session_start();
require_once 'class.user.php';
$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
}

// include 'header.php';
require "config.php";
require "config_mrl.php";

date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

if (!isAdmin($_SESSION['userSession'])) {
    // echo '<div style="background-color: red; color: white;">You are NOT authorized to view/use this page</div>';
    echo '<div style="color: red;">You are NOT authorized to view/use this page</div>';
    die();
} else {
    echo '<div style="color: green;">You are authorized to view/use this page</div>';
}



$selectedYear = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['year'])) {
        $selectedYear = $_POST['year'];
    }

    if (!empty($selectedYear)) {
        if (isset($_POST['addDriver'])) {
            $selectedDriver = $_POST['driverName'];
            $selectedColumn = $_POST['column'];

            if (!empty($selectedDriver) && !empty($selectedColumn)) {
                $stmt = mysqli_prepare($dbconnect, "INSERT INTO `$selectedColumn Drivers` (driverName, driverYear) VALUES (?, ?)");
                mysqli_stmt_bind_param($stmt, "ss", $selectedDriver, $selectedYear);
                mysqli_stmt_execute($stmt);

                if (mysqli_affected_rows($dbconnect) > 0) {
                    echo "$selectedDriver added to $selectedColumn column for $selectedYear.";
                } else {
                    echo "Failed to add $selectedDriver to $selectedColumn column for $selectedYear.";
                }
            }
        } elseif (isset($_POST['removeDriver'])) {
            $selectedDriver = $_POST['driverName'];
            $selectedColumn = $_POST['column'];

            if (!empty($selectedDriver) && !empty($selectedColumn)) {
                $disableForeignKeySQL = "SET foreign_key_checks = 0";
                mysqli_query($dbconnect, $disableForeignKeySQL);

                $stmt = mysqli_prepare($dbconnect, "DELETE FROM `$selectedColumn Drivers` WHERE driverName = ? AND driverYear = ?");
                mysqli_stmt_bind_param($stmt, "ss", $selectedDriver, $selectedYear);
                mysqli_stmt_execute($stmt);

                if (mysqli_affected_rows($dbconnect) > 0) {
                    echo "$selectedDriver removed from $selectedColumn column for $selectedYear.";
                } else {
                    echo "Failed to remove $selectedDriver from $selectedColumn column for $selectedYear.";
                }

                $enableForeignKeySQL = "SET foreign_key_checks = 1";
                mysqli_query($dbconnect, $enableForeignKeySQL);
            }
        }
    }
}
?>

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
        <!-- <input type="submit" name="submitForm" value="Select Year"> -->
    </form>
</div>

<script>
    const yearSelect = document.querySelector('select[name="year"]');
    const yearSelectionForm = document.getElementById('yearSelectionForm');

    yearSelect.addEventListener('change', function () {
        yearSelectionForm.submit();
    });
</script>

<br>

<?php
if (!empty($selectedYear)) {
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
} else {
    echo "<p>No year selected.</p>";
}
?>

<div style="display: flex; justify-content: center;">
    <form method="post" action="" onsubmit="return confirm('DO NOT USE FOR CURRENT OR PAST YEARS - Are you sure you want to make this change? ');">
        <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
        <select name="driverName">
            <option value=""> Select Driver </option>
            <?php
            $stmt = mysqli_prepare($dbconnect, "SELECT driverName FROM drivers");
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_array($result)) {
                $driverName = $row['driverName'];
                echo "<option value=\"$driverName\">$driverName</option>";
            }
            ?>
        </select>
        <select name="column">
            <option value=""> Select Column </option>
            <option value="A">A</option>
            <option value="B">B</option>
            <option value="C">C</option>
            <option value="D">D</option>
        </select>
        <input type="submit" name="addDriver" value="Add Driver">
        <input type="submit" name="removeDriver" value="Remove Driver">
    </form>
</div>

<script>
    function updateHiddenYearForDriver(select) {
        const hiddenYear = document.getElementById('hiddenYear');
        hiddenYear.value = select.value;
    }
</script>

<?php
if (isset($_POST['addDriver'])) {
    $selectedDriver = $_POST['driverName'];
    $selectedColumn = $_POST['column'];
    $selectedYear = $_POST['selectedYear']; // Add this line

    // Prepare a statement to insert the driver into the selected column for the selected year
    $stmt = mysqli_prepare($dbconnect, "INSERT INTO `$selectedColumn Drivers` (driverName, driverYear) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ss", $selectedDriver, $selectedYear);
    mysqli_stmt_execute($stmt);

    if (mysqli_errno($dbconnect) != 0) {
        echo "Failed to add $selectedDriver to $selectedColumn column for $selectedYear. This driver already exists for the selected year.";
    } else {
        echo "$selectedDriver added to $selectedColumn column for $selectedYear.";
    }
}


if (isset($_POST['removeDriver'])) {
    $selectedDriver = $_POST['driverName'];
    $selectedColumn = $_POST['column'];
    $selectedYear = $_POST['selectedYear']; // Add this line

    // Temporarily disable foreign key checks
    $disableForeignKeySQL = "SET foreign_key_checks = 0";
    mysqli_query($dbconnect, $disableForeignKeySQL);

    // Prepare a statement to delete the driver from the selected column for the selected year
    $stmt = mysqli_prepare($dbconnect, "DELETE FROM `$selectedColumn Drivers` WHERE driverName = ? AND driverYear = ?");
    mysqli_stmt_bind_param($stmt, "ss", $selectedDriver, $selectedYear);
    mysqli_stmt_execute($stmt);

    // Check if any rows were affected, indicating a successful removal
    if (mysqli_affected_rows($dbconnect) > 0) {
        echo "$selectedDriver removed from $selectedColumn column for $selectedYear.";
    } else {
        // Check for SQL errors
        if (mysqli_errno($dbconnect) != 0) {
            echo "SQL Error: " . mysqli_error($dbconnect);
        } else {
            echo "$selectedDriver not found in the $selectedColumn column for $selectedYear.";
        }
    }

    // Re-enable foreign key checks
    $enableForeignKeySQL = "SET foreign_key_checks = 1";
    mysqli_query($dbconnect, $enableForeignKeySQL);
}

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
