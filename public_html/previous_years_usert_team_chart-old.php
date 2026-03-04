<?php //--START PHP--//

date_default_timezone_set("	America/New_York");
include "config.php"; // setup variables for database connection 
include "config_mrl.php"; // setup variables for current MRL season & segment
$nowTime = date("n/j/Y g:i a"); //get date in format '8/25/2020 12:20 am'

// set connection variable

$dbconnect = mysqli_connect($host_name,$username,$password,$database) or die("Connection Error: " . mysqli_error($dbconnect));


// loop to show all team charts from years in database
// 

?> <!--END PHP-->
<br>

<?php //--START PHP--//

$sql_Years = "SELECT *  FROM `years` WHERE `year` > '0'";

$result = mysqli_query($dbconnect, $sql_Years);

$i=0;
while($row = mysqli_fetch_array($result)) {
    include 'current_user_team_chart_simple.php';
$i++;
}

?> <!--END PHP-->

//	Paid Status

$sql = "SELECT * FROM `user_teams` WHERE `userID` = $val AND `raceYear` = $raceYear";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:14%;background-color:#f2dcdb>" . 'Paid Status' . "</td><td style=background-color:#f2dcdb>" . $row[paidStatus] . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td></tr>";
$DBpaidStatus=$row[paidStatus]; 
}
