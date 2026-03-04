<?php
session_start();
date_default_timezone_set("America/New_York");
include "config.php"; // setup variables for database connection
include "config_mrl.php"; // setup variables for current MRL season & segment

// to make a static year/segment chart, set the 3 variables and save as new file.
//
//$raceYear = "2018"; // Current Year
//$segment = "S4"; // Current Submission Segment S1 , S2 , S3 , S4
//$segmentName = 'Playoffs'; // Current Submission Segment Name Segment #1 , Segment #2 , Segment #3 , Playoffs
//

//
// Current Segment Team Chart - show on team page after form is locked

$sql = "SELECT * FROM `picks` WHERE `raceYear` = '$raceYear' AND `segment` = '$segment' AND `userName` != 'MRL' ORDER BY `userID` ASC";

//include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
    border: 1px solid black;
    border-collapse: collapse;
    padding: 3px;
    color: black !important; /* Added to set text color to black */

    font-size: 13pt !important;
    line-height: 140%;
   //  font-family: 'Century Gothic', sans-serif;
   //  font-weight: bold;
    font-family: 'Arial';

}
   </style>";

echo "<table align=center style=width:100%>"; // start a table tag in the HTML


echo "<tr style=background-color:#fabf8f>";
echo "<th colspan=7> $raceYear $segmentName Team Chart</th>";
echo "<tr style=background-color:#fabf8f>";
echo "<th>Team</th><th>Owner</th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th><th>Submission Time</th></tr>";
foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=background-color:#b7dee8>" . $row[teamName] . "</td><td style=background-color:#b7dee8>" . $row[userName] . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";
}

echo "</table>"; //Close the table in HTML
?>
