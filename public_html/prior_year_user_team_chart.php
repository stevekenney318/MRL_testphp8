<?php
session_start();

date_default_timezone_set("America/New_York");
include "config.php"; // setup variables for database connection 
include "config_mrl.php"; // setup variables for the current MRL season & segment

// Get the user ID from the session array
// $uid = isset($_SESSION['userSession']) ? $_SESSION['userSession'] : null;

// Now $uid contains the value of userSession, or null if it doesn't exist
// echo "User ID: $uid";

date_default_timezone_set("America/New_York");
include "config.php"; // setup variables for database connection 
// include "config_mrl.php"; // setup variables for current MRL season & segment


// include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
        padding: 3px;
        font-size: 16px;
      }
   </style>";

//	Table Heading
	

echo "<table align=center style=width:80%>"; // start a table tag in the HTML
echo "<tr style=width:175px;background-color:#fabf8f>";
// echo "<th>$prevRaceYear</th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th></tr>";
echo "<th style=width:14%>$prevRaceYear</th><th style=width:18%>Group A</th><th style=width:18%>Group B</th><th style=width:18%>Group C</th><th style=width:18%>Group D</th><th style=width:14%>Submission Time</th></tr>";

//	Segment 1

$sql = "SELECT * FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$prevRaceYear' AND `segment` = 'S1'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #1' . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}


//	Segment 2
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$prevRaceYear' AND `segment` = 'S2'";

foreach ($dbo->query($sql) as $row) {
   echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #2' . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
   }

//	Segment 3
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$prevRaceYear' AND `segment` = 'S3'";

foreach ($dbo->query($sql) as $row) {
   echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #3' . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
   }

//	Segment 4
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$prevRaceYear' AND `segment` = 'S4'";

foreach ($dbo->query($sql) as $row) {
   echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Playoffs' . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
   }

echo "</table>"; //Close the table in HTML
?>