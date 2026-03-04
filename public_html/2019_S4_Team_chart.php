<?Php
include "config.php"; // Database connection using PDO


//
// 2019 Segment 4 Team Chart 

$sql = "SELECT * FROM `picks` WHERE `raceYear` = '2019' AND `segment` = 'S4' AND `userName` != 'MRL' ";
// $sql = "SELECT *  FROM `picks` WHERE `driverA` = '-- No Pick Yet --'";



{
	# code...
}
   //include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
    border: 1px solid black;
    border-collapse: collapse;
    padding: 3px;
}
   </style>";
   
echo "<table align=center>"; // start a table tag in the HTML


echo "<tr style=background-color:#fabf8f>";
echo "<th colspan=6>2019 Playoffs Team Chart</th>";
echo "<tr style=background-color:#fabf8f>";
echo "<th>Team</th><th>Owner</th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th></tr>";
foreach ($dbo->query($sql) as $row) {

// uncomment next line to release picks 
echo "<tr><td style=background-color:#b7dee8>" . $row[teamName] . "</td><td style=background-color:#b7dee8>" . $row[userName] . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td></tr>";  

// comment out next line to release picks
// echo "<tr><td style=background-color:#b7dee8>" . $row[teamName] . "</td><td style=background-color:#b7dee8>" . $row[userName] . "</td><td style=background-color:#d9d9d9>" . '***************' . "</td><td style=background-color:#c4bd97>" . '***************' . "</td><td style=background-color:#b8cce4>" . '***************' . "</td><td style=background-color:#d8e4bc>" . '***************' . "</td></tr>";  
}
echo "</table>"; //Close the table in HTML
?>