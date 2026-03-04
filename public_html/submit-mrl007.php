<?php
/*
filename: submit-mrl007.php
2026-01-25 20:51:22 Steve Kenney evolution from submit-mrl006.php
fix team name entries with apostrophy
*/

session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

// ******************************************************************
// get ip and store it as $ip
if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
  $ip = $_SERVER['HTTP_CLIENT_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
  $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
} else {
  $ip = $_SERVER['REMOTE_ADDR'];
}
//******************************************************************


// Get user ID
$uid = isset($_SESSION['userSession']) ? $_SESSION['userSession'] : null;
$DBuserID = $uid;

// Safety: if something is wrong with session, stop cleanly
if ($DBuserID === null) {
  exit;
}


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
    is_array($var) ? ExtendedAddslash($var) : $var = addslashes($var);
    unset($var);
  }
}
//****************************************************************************************************


// Initialize ExtendedAddslash() function for every $_POST variable
ExtendedAddslash($_POST);


// Retrieve variables from form
$driverA = isset($_POST['group-a-driver']) ? $_POST['group-a-driver'] : '';
$driverB = isset($_POST['group-b-driver']) ? $_POST['group-b-driver'] : '';
$driverC = isset($_POST['group-c-driver']) ? $_POST['group-c-driver'] : '';
$driverD = isset($_POST['group-d-driver']) ? $_POST['group-d-driver'] : '';
$submission_id = isset($_POST['submission_id']) ? $_POST['submission_id'] : '';

// If you want this tracked, keep it stable here (was previously undefined in your file)
$formID = 'form-mrl007';

// set current time to use as timestamp
date_default_timezone_set('America/New_York');
$currentTime = date("Y-m-d H:i:s");

// Make sure we actually have a team name from DB (if not, stop)
if (!isset($DBteamName) || $DBteamName === '') {
  exit;
}


// --------------------------------------------------------------------
// FIX: Use prepared statements so apostrophes in teamName never break SQL
// --------------------------------------------------------------------

$DBuserID_int = (int)$DBuserID;

// 1) Check if picks row already exists for this user/year/segment
$exists = false;
$checkSql = "SELECT pickID FROM `user_picks` WHERE `userID` = ? AND `raceYear` = ? AND `segment` = ? LIMIT 1";
$stmtCheck = mysqli_prepare($dbconnect, $checkSql);
if ($stmtCheck) {
  mysqli_stmt_bind_param($stmtCheck, "iss", $DBuserID_int, $raceYear, $segment);
  mysqli_stmt_execute($stmtCheck);
  mysqli_stmt_store_result($stmtCheck);
  $exists = (mysqli_stmt_num_rows($stmtCheck) > 0);
  mysqli_stmt_close($stmtCheck);
}

// 2) UPDATE if exists, otherwise INSERT into user_picks
if ($exists) {

  $sqlUpdate = "UPDATE `user_picks`
                SET `teamName` = ?,
                    `driverA`  = ?,
                    `driverB`  = ?,
                    `driverC`  = ?,
                    `driverD`  = ?,
                    `entryDate`= ?,
                    `submission_id` = ?,
                    `ip` = ?,
                    `formID` = ?
                WHERE `userID` = ? AND `raceYear` = ? AND `segment` = ?";

  $stmtUpdate = mysqli_prepare($dbconnect, $sqlUpdate);
  if ($stmtUpdate) {
    mysqli_stmt_bind_param(
      $stmtUpdate,
      "sssssssssiss", // <-- FIXED: 12 types for 12 values
      $DBteamName,
      $driverA,
      $driverB,
      $driverC,
      $driverD,
      $currentTime,
      $submission_id,
      $ip,
      $formID,
      $DBuserID_int,
      $raceYear,
      $segment
    );
    mysqli_stmt_execute($stmtUpdate);
    mysqli_stmt_close($stmtUpdate);
  }

} else {
  $sqlInsert = "INSERT INTO `user_picks`
                (`userID`, `teamName`, `raceYear`, `segment`, `driverA`, `driverB`, `driverC`, `driverD`, `entryDate`, `submission_id`, `ip`, `formID`)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

  $stmtInsert = mysqli_prepare($dbconnect, $sqlInsert);
  if ($stmtInsert) {
    mysqli_stmt_bind_param(
      $stmtInsert,
      "isssssssssss",
      $DBuserID_int,
      $DBteamName,
      $raceYear,
      $segment,
      $driverA,
      $driverB,
      $driverC,
      $driverD,
      $currentTime,
      $submission_id,
      $ip,
      $formID
    );
    mysqli_stmt_execute($stmtInsert);
    mysqli_stmt_close($stmtInsert);
  }
}

// 3) Always insert into history (also prepared)
$sqlInsertHistory = "INSERT INTO `user_picks_history`
                     (`userID`, `teamName`, `raceYear`, `segment`, `driverA`, `driverB`, `driverC`, `driverD`, `entryDate`, `submission_id`, `ip`, `formID`)
                     VALUES
                     (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmtHist = mysqli_prepare($dbconnect, $sqlInsertHistory);
if ($stmtHist) {
  mysqli_stmt_bind_param(
    $stmtHist,
    "isssssssssss",
    $DBuserID_int,
    $DBteamName,
    $raceYear,
    $segment,
    $driverA,
    $driverB,
    $driverC,
    $driverD,
    $currentTime,
    $submission_id,
    $ip,
    $formID
  );
  mysqli_stmt_execute($stmtHist);
  mysqli_stmt_close($stmtHist);
}

header("Location: team.php#current_user_team_chart");
exit;
