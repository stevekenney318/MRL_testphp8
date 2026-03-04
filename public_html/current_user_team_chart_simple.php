<?Php
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
echo "<th style=width:14%>$raceYear</th><th style=width:18%>Group A</th><th style=width:18%>Group B</th><th style=width:18%>Group C</th><th style=width:18%>Group D</th><th style=width:14%>Submission Time</th></tr>";

//	Segment 1

$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = $raceYear AND `segment` = 'S1'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=background-color:#b7dee8>" . 'Segment #1' . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=width:18%;background-color:#c4bd97>" . $row[driverB] . "</td><td style=width:18%;background-color:#b8cce4>" . $row[driverC] . "</td><td style=width:18%;background-color:#d8e4bc>" . $row[driverD] . "</td><td style=width:14%;background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}


//	Segment 2
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = $raceYear AND `segment` = 'S2'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #2'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}

//	Segment 3
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = $raceYear AND `segment` = 'S3'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #3'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}

//	Segment 4
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = $raceYear AND `segment` = 'S4'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Playoffs'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}

echo "</table>"; //Close the table in HTML

?>
