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
