<?php
// 2024_Schedule.php
// Start the session and include necessary files
session_start();
require_once 'class.user.php';
require 'config.php';
require 'config_mrl.php';

// Create a new USER object
$user_home = new USER();

// // Redirect to login if not logged in
// if (!$user_home->is_logged_in()) {
//     $user_home->redirect('login.php');
// } else {
//     // Set the character encoding to UTF-8
//     header('Content-Type: text/html; charset=utf-8');
    
//     echo '<div style="color: green;">As a team owner, you are authorized to view the race schedule.</div>';
//     echo '<br>';
//     echo "<script>console.log('Validated logged in user');</script>";
// }

// Include header for MRL styling, etc.
// include 'header.php';

// Set the time zone and get the current time
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

// Include the HTML content directly
include '2024_Schedule.html';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>2024 Schedule</title>
<style>
    body {
        font-family: Arial, sans-serif;
        padding: 20px;
        margin: 0 auto;
        max-width: 80%;
        line-height: 1.6;
    }
</style>
</head>
<body>

</body>
</html>