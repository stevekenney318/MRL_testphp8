<?php
// include 'header.php';
session_start(); // ready to go!

// find unique userID
// very important for making picks, DO NOT DELETE
foreach ($_SESSION as $key=>$DBuserID);

require_once 'class.user.php';
$user_home = new USER();

if(!$user_home->is_logged_in())
{
    $user_home->redirect('login.php');
}

date_default_timezone_set("America/New_York");
require "config.php"; // setup variables for database connection 
require "config_mrl.php"; // setup variables for current MRL season & segment
$currentTimeIs = date("n/j/Y g:i a"); //get date in format '8/25/2020 12:20 am'

$stmt = $user_home->runQuery("SELECT * FROM users WHERE userID=:uid");
$stmt->execute(array(":uid"=>$_SESSION['userSession']));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Submission form for late pick - User must have Y in changeAuth
$stmt = $user_home->runQuery("SELECT * FROM users WHERE userID=:uid AND changeAuth=:changeAuth");
$stmt->execute(array(":uid"=>$_SESSION['userSession'], ":changeAuth" => "Y"));
$count = $stmt->rowCount();
if($count == 1){
    echo '<body style="background-color: #222222;">'; // added background color to body
    // include 'current_user_team_chart.php';
    echo '<div style="width: 80%; margin: 0 auto;">';
    echo "<br>";
    echo "<div style='color: red; font-size: 20px; background-color: #fabf8f; text-align: center; font-weight: bold;'>You are currently making picks past the original deadline of $formLockDate for $raceYear $segmentName</div>";
    include $currentForm;
    echo '</div>';
    echo '</body>'; // close body
} else {
    echo '<body style="background-color: #222222;">'; // added background color to body
}
?>
