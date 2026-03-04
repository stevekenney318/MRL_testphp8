<?Php
include "config.php"; // Database connection using PDO
include "config_mrl.php"; // setup variables for current MRL season & segment 



// user team chart




// find unique userID

session_start();
  
    foreach ($_SESSION as $key=>$val);



   //include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
    border: 1px solid black;
    border-collapse: collapse;
    padding: 3px;
    font-size: 18px;
}
   </style>";

// info for getting current year drivers 

$sql_A_drivers = "SELECT *  FROM `A Drivers` WHERE `driverYear` = '$mrl_year'";
$sql_B_drivers = "SELECT *  FROM `B Drivers` WHERE `driverYear` = '$mrl_year'";
$sql_C_drivers = "SELECT *  FROM `C Drivers` WHERE `driverYear` = '$mrl_year'";
$sql_D_drivers = "SELECT *  FROM `D Drivers` WHERE `driverYear` = '$mrl_year'";


//	Table Heading

echo "<table align=center style=width:100%>"; // start a table tag in the HTML	
echo "<tr style=width:175px;background-color:#fabf8f>";
echo "<th> $raceYear Drivers available to add to your team.</th></tr>";
echo "</table>"; //Close the table in HTML

echo "<table align=center style=width:100%>"; // start a table tag in the HTML
echo "<tr style=width:175px;background-color:#fabf8f>";
echo "<th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th></tr>";

//	Segment 1

$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = '$mrl_year' AND `segment` = 'S1'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #1' . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td></tr>";  
}


//	Segment 2
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = '$mrl_year' AND `segment` = 'S2'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #2'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td></tr>";  
}

//	Segment 3
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = '$mrl_year' AND `segment` = 'S3'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #3'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td></tr>";  
}

//	Segment 4
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = '$mrl_year' AND `segment` = 'S4'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Playoffs'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td></tr>";  
}

echo "</table>"; //Close the table in HTML
?>