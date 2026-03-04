<?Php
//submitted_teams.php
session_start();
date_default_timezone_set("	America/New_York");
include "config.php"; // setup variables for database connection 
include "config_mrl.php"; // setup variables for current MRL season & segment


// find unique userID

session_start();
  
    foreach ($_SESSION as $key=>$val);

   //include CSS Style Sheet
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
echo "<tr style=background-color:#fabf8f>";
echo "<th style=width:14%>$raceYear</th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th><th>Submission Time</th></tr>";

//	Submitted picks

$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = $raceYear AND `segment` = $segment";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=background-color:#b7dee8>" . '$segmentName' . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=width:18%;background-color:#c4bd97>" . $row[driverB] . "</td><td style=width:18%;background-color:#b8cce4>" . $row[driverC] . "</td><td style=width:18%;background-color:#d8e4bc>" . $row[driverD] . "</td><td style=width:14%;background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}


echo "</table>"; //Close the table in HTML

?>
