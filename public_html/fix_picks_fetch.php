<?php
// fix_picks_fetch.php 2024-02-05 22:02:19

// Include the necessary configuration file
require 'config.php';  // Adjust the path as needed

// Get the selected segment, year, and userID from the POST request
$selectedSegment = $_POST['segment'];
$selectedYear = $_POST['year'];
$selectedUserID = $_POST['userID'];

// Initialize identifier and line counter
$dataArrayIdentifier = 1; // Identifier for data array
$lineCounter = 1; // Line counter

// Initialize arrays to store data
$driverAData = array();
$driverBData = array();
$driverCData = array();
$driverDData = array();
$entryDateData = array();

// Fetch and display data from user_picks
$stmtUserPicks = mysqli_prepare($dbconnect, "SELECT driverA, driverB, driverC, driverD, entryDate FROM user_picks WHERE segment = ? AND raceYear = ? AND userID = ?");
mysqli_stmt_bind_param($stmtUserPicks, "sss", $selectedSegment, $selectedYear, $selectedUserID);
mysqli_stmt_execute($stmtUserPicks);
mysqli_stmt_store_result($stmtUserPicks);

if (mysqli_stmt_num_rows($stmtUserPicks) > 0) {
    // Fetch and display the data from user_picks with identifier
    echo "<br>";
    mysqli_stmt_bind_result($stmtUserPicks, $driverA, $driverB, $driverC, $driverD, $entryDate);

    while (mysqli_stmt_fetch($stmtUserPicks)) {
        // Save each piece of data as a new variable
        $driverAData[$lineCounter] = $driverA;
        $driverBData[$lineCounter] = $driverB;
        $driverCData[$lineCounter] = $driverC;
        $driverDData[$lineCounter] = $driverD;
        $entryDateData[$lineCounter] = $entryDate;

        // Display the data with identifier
        echo "<strong>$lineCounter:</strong> $selectedYear/$selectedSegment - Picks: <strong>$entryDate</strong> , $driverA , $driverB , $driverC , $driverD<br>";
        $lineCounter++;
    }
} else {
    // No data found for user_picks, display "NONE" in red text with identifier
    echo '<br>';
    echo "<strong>$lineCounter:</strong> $selectedYear/$selectedSegment - Picks: <span style=\"color: red;\">NONE</span><br>";
    $lineCounter++;
}

// Fetch and display data from user_picks_history
$stmtUserPicksHistory = mysqli_prepare($dbconnect, "SELECT driverA, driverB, driverC, driverD, entryDate FROM user_picks_history WHERE segment = ? AND raceYear = ? AND userID = ?");
mysqli_stmt_bind_param($stmtUserPicksHistory, "sss", $selectedSegment, $selectedYear, $selectedUserID);
mysqli_stmt_execute($stmtUserPicksHistory);
mysqli_stmt_store_result($stmtUserPicksHistory);

if (mysqli_stmt_num_rows($stmtUserPicksHistory) > 0) {
    // Fetch and display the data from user_picks_history with identifier
    echo "<br>";
    mysqli_stmt_bind_result($stmtUserPicksHistory, $driverA, $driverB, $driverC, $driverD, $entryDate);

    while (mysqli_stmt_fetch($stmtUserPicksHistory)) {
        // Save each piece of data as a new variable
        $driverAData[$lineCounter] = $driverA;
        $driverBData[$lineCounter] = $driverB;
        $driverCData[$lineCounter] = $driverC;
        $driverDData[$lineCounter] = $driverD;
        $entryDateData[$lineCounter] = $entryDate;

        // Display the data with identifier
        echo "<strong>$lineCounter:</strong> $selectedYear/$selectedSegment - Saved: <strong>$entryDate</strong> , $driverA , $driverB , $driverC , $driverD<br>";
        $lineCounter++;
    }
} else {
    // No data found for user_picks_history, display "NONE" in red text with identifier
    echo '<br>';
    echo "<strong>$lineCounter:</strong> $selectedYear/$selectedSegment - Saved: <span style=\"color: red;\">NONE</span><br>";
    $lineCounter++;
}

// Close the statements
mysqli_stmt_close($stmtUserPicks);
mysqli_stmt_close($stmtUserPicksHistory);

// Display differences found between user_picks and used_picks_history
echo "<br>";

if ($lineCounter >= 2) {
    // Loop through each data point from used_picks_history (starting from line 2)
    for ($i = 2; $i < $lineCounter; $i++) {
        // Compare each piece of data from used_picks_history to the corresponding data in user_picks
        if ($driverAData[1] != $driverAData[$i] || $driverBData[1] != $driverBData[$i] || $driverCData[1] != $driverCData[$i] || $driverDData[1] != $driverDData[$i] || $entryDateData[1] != $entryDateData[$i]) {
            // If there is a difference, display it along with the field that has the difference
            $differences = array();
            if ($driverAData[1] != $driverAData[$i]) {
                $differences[] = "Driver A";
            }
            if ($driverBData[1] != $driverBData[$i]) {
                $differences[] = "Driver B";
            }
            if ($driverCData[1] != $driverCData[$i]) {
                $differences[] = "Driver C";
            }
            if ($driverDData[1] != $driverDData[$i]) {
                $differences[] = "Driver D";
            }
            if ($entryDateData[1] != $entryDateData[$i]) {
                $differences[] = "Entry Time";
            }
        
            // Display the differences on the screen
            echo "Difference found with entry on line $i in used_picks_history: " . implode(', ', $differences) . "<br>";
        }
    }

} else {
    // If there's only one line of data in user_picks, display a message indicating no comparison needed
    echo "No need to compare data from used_picks_history as there is only one line of data in user_picks<br>";
}
?>

<!-- Add new dropdown menus for selecting data from user_picks and used_picks_history -->
<div id="dropdownMenusContainer"></div>
<script>
    // Define the addDropdownMenus function
    function addDropdownMenus(lineCounter) {
        // Your implementation of the function goes here
        // For demonstration purposes, let's log the lineCounter
        console.log("Line counter:", lineCounter);
    }

    // Call the addDropdownMenus function with the lineCounter variable
    addDropdownMenus(<?php echo $lineCounter; ?>);
</script>
