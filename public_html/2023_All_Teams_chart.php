<?Php
// Start the session and include necessary files
session_start();
require_once 'class.user.php';
require 'config.php';
require 'config_mrl.php';

// Create a new USER object
$user_home = new USER();

// Redirect to login if not logged in
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
}

// Set the time zone and get the current time
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

// Check if the user is an admin
if (!isAdmin($_SESSION['userSession'])) {
    echo '<div style="color: red;">You are NOT authorized to view/use this page</div>';
    die();
} else {
    echo '<div style="color: green;">You are authorized to view/use this page</div>';
}

//
// 2023 Segment 1 Team Chart - change segment 99 to S1 to release

$sql = "SELECT * FROM `picks` WHERE `raceYear` = '2023' AND `userName` != 'MRL' ORDER BY `picks`.`userID` ASC, `picks`.`segment` ASC, `picks`.`entryDate` ASC";
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
echo "<th colspan=8>2023 All Segments Team Chart</th>";
echo "<tr style=background-color:#fabf8f>";
echo "<th>Team</th><th>Owner</th><th> </th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th><th>Submission Time</th></tr>";
foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=background-color:#b7dee8>" . $row[teamName] . "</td><td style=background-color:#b7dee8>" . $row[userName] . "</td><td style=background-color:#b7dee8>" . $row[segment] . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}
echo "</table>"; //Close the table in HTML
?>