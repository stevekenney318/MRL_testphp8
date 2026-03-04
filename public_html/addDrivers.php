<?php
// addDrivers.php
// ------------------------------------------------------------
// Session + admin enforcement (standard, consistent)
// ------------------------------------------------------------
session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

$isAdmin = isAdmin($_SESSION['userSession'] ?? null);

// ------------------------------------------------------------
// Existing logic (UNCHANGED)
// ------------------------------------------------------------
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

// Lookup user (unchanged)
$stmt = mysqli_prepare($dbconnect, "SELECT * FROM users WHERE userID=?");
mysqli_stmt_bind_param($stmt, "i", $_SESSION['userSession']);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();

if (!$dbconnect) {
    die("Connection failed: " . mysqli_connect_error());
}

// Handle selected year
if (isset($_POST['showDrivers'])) {
    $selectedYear = $_POST['year'];
    if ($selectedYear !== '') {
        $_SESSION['selectedYear'] = $selectedYear;
    }
}

$selectedYear = $_SESSION['selectedYear'] ?? '';
$confirmationMessage = "";

// Add driver
if (isset($_POST['addDriver'])) {
    $selectedDriver = $_POST['driverName'];
    $selectedColumn = $_POST['column'];
    $selectedTag    = $_POST['tag'] ?: null;

    if (!empty($selectedYear) && !empty($selectedDriver) && !empty($selectedColumn)) {
        $stmt = mysqli_prepare(
            $dbconnect,
            "INSERT INTO `{$selectedColumn} Drivers` (driverName, driverYear, Tag)
             VALUES (?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, "sss", $selectedDriver, $selectedYear, $selectedTag);
        mysqli_stmt_execute($stmt);

        $confirmationMessage =
            "$selectedDriver added to Group $selectedColumn for year $selectedYear" .
            ($selectedTag ? " with Tag $selectedTag." : ".");
    } else {
        $confirmationMessage = "Please select all fields.";
    }
}

// Add new driver
if (isset($_POST['addNewDriver'])) {
    $newDriverName = trim($_POST['newDriverName']);

    if ($newDriverName === '') {
        $confirmationMessage = "Driver name cannot be empty.";
    } else {
        $stmt = mysqli_prepare($dbconnect, "SELECT COUNT(*) FROM drivers WHERE driverName = ?");
        mysqli_stmt_bind_param($stmt, "s", $newDriverName);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_array($result);

        if ($row[0] > 0) {
            $confirmationMessage =
                "Driver '$newDriverName' already exists in the database.";
        } else {
            $stmt = mysqli_prepare(
                $dbconnect,
                "INSERT INTO drivers (driverName) VALUES (?)"
            );
            mysqli_stmt_bind_param($stmt, "s", $newDriverName);
            mysqli_stmt_execute($stmt);

            $confirmationMessage =
                "Driver '$newDriverName' has been successfully added to the database.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>MRL Add Drivers</title>

    <link rel="stylesheet" href="/mrl-styles.css">

    <style>
        /* Legacy page font scope ONLY (unchanged) */
        table, th, td,
        select, option,
        input, label, p {
            font-family: Tahoma, Verdana, Segoe, sans-serif;
            font-size: 13pt;
            color: #000000;
        }
    </style>
</head>
<body>

<?php
// ------------------------------------------------------------
// Admin status (first visible output, standard pattern)
// ------------------------------------------------------------
echo $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

if (!$isAdmin) {
    exit;
}
?>

<div style="display: flex; justify-content: center;">
    <form method="post">
        <select name="year">
            <option value=""> Select Year </option>
            <?php
            $stmt = mysqli_prepare($dbconnect, "SELECT year FROM years WHERE year != '0000'");
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_array($result)) {
                $year = $row['year'];
                $sel = ($selectedYear == $year) ? 'selected' : '';
                echo "<option value=\"$year\" $sel>$year</option>";
            }
            ?>
        </select>
        <input type="submit" name="showDrivers" value="Show Drivers">
    </form>
</div>

<br>

<?php
if ($selectedYear) {
    $tables = ['A Drivers', 'B Drivers', 'C Drivers', 'D Drivers'];
    $driverData = [];

    foreach ($tables as $table) {
        $stmt = mysqli_prepare(
            $dbconnect,
            "SELECT driverName, Tag FROM `$table` WHERE driverYear = ?"
        );
        mysqli_stmt_bind_param($stmt, "s", $selectedYear);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $driverData[$table][] = $row;
        }
    }

    echo "<div style='display:flex; justify-content:center;'>";
    echo "<table style='border-collapse:collapse; text-align:center;'>";
    echo "<tr><th colspan='4' style='background:#fabf8f;'>$selectedYear</th></tr>";
    echo "<tr>
            <th style='background:#fabf8f;'>Group A</th>
            <th style='background:#fabf8f;'>Group B</th>
            <th style='background:#fabf8f;'>Group C</th>
            <th style='background:#fabf8f;'>Group D</th>
          </tr>";

    $maxRows = max(array_map('count', $driverData));
    for ($i = 0; $i < $maxRows; $i++) {
        echo "<tr>";
        foreach ($tables as $idx => $table) {
            $colors = ['#d9d9d9', '#c4bd97', '#b8cce4', '#d8e4bc'];
            $d = $driverData[$table][$i] ?? ['driverName'=>'','Tag'=>''];
            $text = $d['driverName'] ? $d['driverName'].' '.$d['Tag'] : '';
            $style = $text
                ? "background:{$colors[$idx]}; border:1px solid black; padding:3px; text-align:left;"
                : "padding:3px;";
            echo "<td style=\"$style\">$text</td>";
        }
        echo "</tr>";
    }
    echo "</table></div>";
}
?>

<!-- Add new driver -->
<div style="display:flex; justify-content:center; margin-top:20px;">
    <form method="post">
        <input type="text" name="newDriverName" placeholder="Enter driver name">
        <input type="submit" name="addNewDriver" value="Add New Driver">
    </form>
</div>

<!-- Add driver to group -->
<div style="display:flex; justify-content:center; margin-top:20px;">
    <form method="post">
        
        <select name="column">
            <option value=""> Group </option>
            <option>A</option><option>B</option><option>C</option><option>D</option>
        </select>
    
    <select name="driverName">
            <option value=""> Driver </option>
            <?php
            $already = [];
            if ($selectedYear) {
                foreach ($tables as $t) {
                    $stmt = mysqli_prepare(
                        $dbconnect,
                        "SELECT driverName FROM `$t` WHERE driverYear = ?"
                    );
                    mysqli_stmt_bind_param($stmt, "s", $selectedYear);
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    while ($r = mysqli_fetch_assoc($res)) {
                        $already[] = $r['driverName'];
                    }
                }
            }

            $stmt = mysqli_prepare($dbconnect, "SELECT driverName FROM drivers");
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($r = mysqli_fetch_assoc($res)) {
                if (!in_array($r['driverName'], $already) &&
                    $r['driverName'] !== '-- No Pick Yet --') {
                    echo "<option>{$r['driverName']}</option>";
                }
            }
            ?>
        </select>

        <select name="tag">
            <option value="">Tag</option>
            <?php
            $stmt = mysqli_prepare($dbconnect, "SHOW COLUMNS FROM `A Drivers` LIKE 'Tag'");
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);

            if (preg_match("/^enum\((.*)\)$/", $row['Type'], $m)) {
                $labels = ["(R)"=>"Rookie","(P)"=>"Part Time"];
                foreach (str_getcsv($m[1], ',', "'") as $v) {
                    $label = $labels[$v] ?? $v;
                    echo "<option value=\"$v\">$label</option>";
                }
            }
            ?>
        </select>



        <input type="submit" name="addDriver" value="Add Driver">
    </form>
</div>

<?php if ($confirmationMessage): ?>
<div style="text-align:center; margin-top:20px;">
    <p style="font-weight:bold; color:red;"><?php echo $confirmationMessage; ?></p>
</div>
<?php endif; ?>

</body>
</html>
