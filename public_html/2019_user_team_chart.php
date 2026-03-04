<?Php
include "config.php"; // Database connection using PDO



// user team chart




// find unique userID

session_start();
  
    foreach ($_SESSION as $key=>$val);
	// echo "<br><br>";
	// echo "$key";
	// echo "<br><br>";
	// echo "$val";


   //include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
    border: 1px solid black;
    border-collapse: collapse;
    padding: 3px;
    font-size: 16px;
}
   </style>";

   
// set login time of user

// Assuming today is March 10th, 2001, 5:16:18 pm, and that we are in the
// Mountain Standard Time (MST) Time Zone

$today = date("m.d.y");                         // 03.10.01
$today = date("j, n, Y");                       // 10, 3, 2001
$today = date("Ymd");                           // 20010310
$today = date('h-i-s, j-m-y, it is w Day');     // 05-16-18, 10-03-01, 1631 1618 6 Satpm01
$today = date('\i\t \i\s \t\h\e jS \d\a\y.');   // it is the 10th day.
$today = date("D M j G:i:s T Y");               // Sat Mar 10 17:16:18 MST 2001
$today = date('H:m:s \m \i\s\ \m\o\n\t\h');     // 17:03:18 m is month
$today = date("H:i:s");                         // 17:16:18
$today = date("Y-m-d H:i:s");                   // 2001-03-10 17:16:18 (the MySQL DATETIME format)
$today = date("F j, Y, g:i a");                 // March 10, 2001, 5:16 pm

// echo $today;

$logintime = date("F j, Y, g:i a");                 // March 10, 2001, 5:16 pm

$sql = "UPDATE users SET LoginTime = $logintime WHERE 'userID` = $val" ;




//	Table 

echo "<table align=center style=width:70%>"; // start a table tag in the HTML

//	Team Name

$sql = "SELECT * FROM `user_teams` WHERE `userID` = $val AND `raceYear` = '2019'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Team Name' . "</td><td style=background-color:#f2dcdb>" . $row[teamName] . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td></tr>";  
}

//	Team Owner

$sql = "SELECT * FROM `users` WHERE `userID` = $val";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Team Owner' . "</td><td style=background-color:#f2dcdb>" . $row[userName] . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td>";  
}



//	email addresses

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Email Address(es)' . "</td><td style=background-color:#f2dcdb>" . $row[userEmail] . "</td><td style=background-color:#f2dcdb>" . $row[userEmail2] . "</td></tr>";  

}


echo "</table>"; //Close the table in HTML
echo "<BR>";




//	Table Heading
	

echo "<table align=center style=width:70%>"; // start a table tag in the HTML
echo "<tr style=width:175px;background-color:#fabf8f>";
echo "<th>2019</th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th></tr>";

//	Segment 1

$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = '2019' AND `segment` = 'S1'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #1' . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td></tr>";  
}


//	Segment 2
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = '2019' AND `segment` = 'S2'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #2'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td></tr>";  
}

//	Segment 3
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = '2019' AND `segment` = 'S3'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #3'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td></tr>";  
}

//	Segment 4
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $val AND `raceYear` = '2019' AND `segment` = 'S4'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Playoffs'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td></tr>";  
}

echo "</table>"; //Close the table in HTML
?>