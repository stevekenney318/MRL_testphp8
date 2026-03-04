<?php
// fix_picks.php
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

// Include header for MRL styling, etc.
// include 'header.php';

// Check if the user is an admin
$isAdmin = isAdmin($_SESSION['userSession']);

// Display admin status
if ($isAdmin) {
    echo '<div style="color: green;">You are authorized to view/use this page</div>';
    echo "<script>console.log('Validated admin');</script>";
} else {
    echo '<div style="color: red;">You are NOT authorized to view/use this page</div>';
    echo "<script>console.log('Validated non-admin');</script>";
    die();
}

// Set the time zone and get the current time
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");


// Get the user ID from the session array
// Set $uid to the value of userSession
$uid = isset($_SESSION['userSession']) ? $_SESSION['userSession'] : null;

// Now $uid contains the value of userSession, or null if it doesn't exist
// echo "User ID: $uid";
// ----------------------------------------------	
// set variable to userID to temp check teams -->
 
echo '<form action="" method="POST">';
echo '<input type="text" name="input_value">';
echo '<input type="submit" name="submit">';

if (isset($_POST['submit']))
  {
  // Execute this code if the submit button is pressed.
  $uid = $_POST['input_value'];
  echo "info for user $uid";
  }
  
// end of set variable to userID to temp check teams
// ------------------------------------------------- 


   //include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
    border: 1px solid black;
    border-collapse: collapse;
    padding: 3px;
    font-size: 16px;
    color:black
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

$sql = "UPDATE users SET LoginTime = $logintime WHERE 'userID` = $uid" ;




//	Table 

echo "<table align=center style=width:80%>"; // start a table tag in the HTML

//	Team Name

$sql = "SELECT * FROM `user_teams` WHERE `userID` = $uid AND `raceYear` = $raceYear";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:14%;background-color:#f2dcdb>" . 'Team Name' . "</td><td style=background-color:#f2dcdb>" . $row[teamName] . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td></tr>"; 
$DBteamName=$row[teamName]; 
}

//	Team Owner

$sql = "SELECT * FROM `users` WHERE `userID` = $uid";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Team Owner' . "</td><td style=background-color:#f2dcdb>" . $row[userName] . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td>";
$DBuserName=$row[userName];   
}



//	email addresses

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Email Address(es)' . "</td><td style=background-color:#f2dcdb>" . $row[userEmail] . "</td><td style=background-color:#f2dcdb>" . $row[userEmail2] . "</td></tr>";
$DBuserEmail=$row[userEmail];  
}


echo "</table>"; //Close the table in HTML
// echo "<BR>";




//	Table Heading
	

echo "<table align=center style=width:80%>"; // start a table tag in the HTML
echo "<tr style=background-color:#fabf8f>";
echo "<th style=width:14%>$raceYear</th><th style=width:18%>Group A</th><th style=width:18%>Group B</th><th style=width:18%>Group C</th><th style=width:18%>Group D</th><th style=width:14%>Submission Time</th></tr>";

//	Segment 1

$sql = "SELECT * FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = $raceYear AND `segment` = 'S1'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=background-color:#b7dee8>" . 'Segment #1' . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=width:18%;background-color:#c4bd97>" . $row[driverB] . "</td><td style=width:18%;background-color:#b8cce4>" . $row[driverC] . "</td><td style=width:18%;background-color:#d8e4bc>" . $row[driverD] . "</td><td style=width:14%;background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}


//	Segment 2
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = $raceYear AND `segment` = 'S2'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #2'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}

//	Segment 3
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = $raceYear AND `segment` = 'S3'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #3'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}

//	Segment 4
	
$sql = "SELECT * FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = $raceYear AND `segment` = 'S4'";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Playoffs'    . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}

echo "</table>"; //Close the table in HTML

?>
<?php
    $sql = "SELECT * FROM `years` WHERE `year` < '$raceYear' AND `year` > '0' ORDER BY `years`.`year` DESC";
    foreach ($dbo->query($sql) as $row) {
        $prevRaceYear = $row[year];
        include 'prior_year_user_team_chart.php';

    }
    ?>
