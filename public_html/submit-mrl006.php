<?php
/*
filename: submit-mrl006.php
2024-01-24 16:58:13 Steve Kenney evolution from submit.php
*/

session_start();
require_once 'class.user.php';
$user_home = new USER();

if (!$user_home->is_logged_in()) {
  $user_home->redirect('login.php');
}

date_default_timezone_set("	America/New_York");
include "config.php"; // setup variables for database connection 
include "config_mrl.php"; // setup variables for current MRL season & segment 

// get IP address

// $ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);
// echo $ip;



// ******************************************************************
// get ip and store it as $ip
// https://www.w3resource.com/php-exercises/php-basic-exercise-5.php
//
//whether ip is from share internet
if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
  $ip = $_SERVER['HTTP_CLIENT_IP'];
}
//whether ip is from proxy
elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
  $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
}
//whether ip is from remote address
else {
  $ip = $_SERVER['REMOTE_ADDR'];
}
//******************************************************************



// Get user ID 
$uid = isset($_SESSION['userSession']) ? $_SESSION['userSession'] : null;
$DBuserID = $uid;


// Get Team Name

$sqlteam = "SELECT teamName FROM `user_teams` WHERE `userID` = ? AND `raceYear` = ?";

// Prepare the statement
$stmtUserTeams = mysqli_prepare($dbconnect, $sqlteam);

// Check if the preparation was successful
if ($stmtUserTeams) {
  // Bind parameters to the prepared statement
  mysqli_stmt_bind_param($stmtUserTeams, "is", $uid, $raceYear);

  // Execute the prepared statement
  mysqli_stmt_execute($stmtUserTeams);

  // Store the result
  mysqli_stmt_store_result($stmtUserTeams);

  // Check if any results were returned
  if (mysqli_stmt_num_rows($stmtUserTeams) > 0) {
    // Bind the result variables
    mysqli_stmt_bind_result($stmtUserTeams, $DBteamName);

    // Fetch the results
    mysqli_stmt_fetch($stmtUserTeams);

    // Output the team name
    // echo "The value of \$DBteamName = " . $DBteamName . "<br>";
  }

  // Close the statement
  mysqli_stmt_close($stmtUserTeams);
}




// Get Team Owner

$sqlOwner = "SELECT userName FROM `users` WHERE `userID` = ?";

// Prepare the statement
$stmtOwner = mysqli_prepare($dbconnect, $sqlOwner);

// Check if the preparation was successful
if ($stmtOwner) {
  // Bind parameters to the prepared statement
  mysqli_stmt_bind_param($stmtOwner, "i", $uid);

  // Execute the prepared statement
  mysqli_stmt_execute($stmtOwner);

  // Store the result
  mysqli_stmt_store_result($stmtOwner);

  // Check if any results were returned
  if (mysqli_stmt_num_rows($stmtOwner) > 0) {
    // Bind the result variables
    mysqli_stmt_bind_result($stmtOwner, $DBuserName);

    // Fetch the results
    mysqli_stmt_fetch($stmtOwner);

    // Output the user's name
    // echo "The value of \$DBuserName = " . $DBuserName . "<br>";
  }

  // Close the statement
  mysqli_stmt_close($stmtOwner);
}

// Get email address

$sqlEmail = "SELECT userEmail FROM `users` WHERE `userID` = ?";

// Prepare the statement
$stmtEmail = mysqli_prepare($dbconnect, $sqlEmail);

// Check if the preparation was successful
if ($stmtEmail) {
  // Bind parameters to the prepared statement
  mysqli_stmt_bind_param($stmtEmail, "i", $uid);

  // Execute the prepared statement
  mysqli_stmt_execute($stmtEmail);

  // Store the result
  mysqli_stmt_store_result($stmtEmail);

  // Check if any results were returned
  if (mysqli_stmt_num_rows($stmtEmail) > 0) {
    // Bind the result variables
    mysqli_stmt_bind_result($stmtEmail, $DBuserEmail);

    // Fetch the results
    mysqli_stmt_fetch($stmtEmail);

    // Output the user's email
    // echo "The value of \$DBuserEmail = " . $DBuserEmail . "<br>";
  }

  // Close the statement
  mysqli_stmt_close($stmtEmail);
}


//****************************************************************************************************
/*
 * To prevent possible SQL injection vulnerabilities to your MYSQL database, we'll need to run PHP's addslash() function for every post variable.
 * And since the $_POST data is an array, let's make a function that will loop on every variable:
 *
 */
// This function will run within each post array including multi-dimensional arrays
function ExtendedAddslash(&$params)
{
  foreach ($params as &$var) {
    // check if $var is an array. If yes, it will start another ExtendedAddslash() function to loop to each key inside.
    is_array($var) ? ExtendedAddslash($var) : $var = addslashes($var);
    unset($var);
  }
}
//****************************************************************************************************



// Initialize ExtendedAddslash() function for every $_POST variable
ExtendedAddslash($_POST);


// Retrieve variables from form

$driverA = $_POST['group-a-driver'];
$driverB = $_POST['group-b-driver'];
$driverC = $_POST['group-c-driver'];
$driverD = $_POST['group-d-driver'];
$submission_id = $_POST['submission_id'];
//$formID =$_POST['formID'];
//$formID = 'form-mrl003'; // this is form-mrl003

// set current time to use as timestamp

date_default_timezone_set('America/New_York');
$currentTime = date("Y-m-d H:i:s");
$string = "the color is ";
$string .= "red";

$sqlInsert = "INSERT INTO `user_picks` (`pickID`, `userID`, `teamName`, `raceYear`, `segment`, `driverA`, `driverB`, `driverC`, `driverD`, `entryDate`, `submission_id`, `ip`, `formID`) VALUES ('', '$DBuserID', '$DBteamName', '$raceYear', '$segment', '$driverA', '$driverB', '$driverC', '$driverD', '$currentTime', '$submission_id', '$ip', '$formID')";

$sqlInsertHistory = "INSERT INTO `user_picks_history` (`pickID`, `userID`, `teamName`, `raceYear`, `segment`, `driverA`, `driverB`, `driverC`, `driverD`, `entryDate`, `submission_id`, `ip`, `formID`) VALUES ('', '$DBuserID', '$DBteamName', '$raceYear', '$segment', '$driverA', '$driverB', '$driverC', '$driverD', '$currentTime', '$submission_id', '$ip', '$formID')";


$sqlUpdate = "UPDATE `user_picks` SET `driverA` = '$driverA',`driverB` = '$driverB', `driverC` = '$driverC', `driverD` = '$driverD', `entryDate` = '$currentTime 'WHERE `userID` = '$DBuserID' AND `raceYear` = '$raceYear' AND `segment` = '$segment'";


// if current year and segment picks are not in DB , Insert them... if they are there Update them.

$query = "SELECT *  FROM `user_picks` WHERE `userID` = '$DBuserID' AND `raceYear` = '$raceYear' AND `segment` = '$segment' ORDER BY `pickID`  DESC";

$sqlsearch = mysqli_query($dbconnect, $query);

$resultcount = mysqli_num_rows($sqlsearch);

// print_r( $resultcount ); // if equal to 1, Update picks

if ($resultcount == 1) {
  mysqli_query($dbconnect, $sqlUpdate);
} else {
  mysqli_query($dbconnect, $sqlInsert);
}

// insert picks into history

mysqli_query($dbconnect, $sqlInsertHistory);

header("refresh:0; url=team.php#current_user_team_chart");
