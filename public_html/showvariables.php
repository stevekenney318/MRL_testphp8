<?php
session_start(); // ready to go!
$_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
// Print all session variables
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Print all defined variables
echo "<pre>";
print_r(get_defined_vars());
echo "</pre>";

// find unique userID 
foreach ($_SESSION as $key=>$DBuserID);
require_once 'class.user.php';
$user_home = new USER();
if(!$user_home->is_logged_in())
{
    $user_home->redirect('login.php');
}
require "config.php"; // setup variables for database connection 
require "config_mrl.php"; // setup variables for current MRL season & segment
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a"); //get date in format '8/25/2020 12:20 am'
$stmt = $user_home->runQuery("SELECT * FROM users WHERE userID=:uid");
$stmt->execute(array(":uid"=>$_SESSION['userSession']));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
?>
