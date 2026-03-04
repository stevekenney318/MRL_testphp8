<?php
// include 'header.php';
// Start the session to access session variables
session_start();

// Import the USER class and create an instance
require_once 'class.user.php';
$user_home = new USER();

// If the user is not logged in, redirect to the login page
if(!$user_home->is_logged_in())
{
    $user_home->redirect('login.php');
}

// Import the config.php and config_mrl.php files
require "config.php"; 
require "config_mrl.php"; 

// Set the timezone to America/New_York and get the current time
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

// Prepare a mysqli statement to select all columns from the users table where userID matches the userSession variable in the session array
$stmt = mysqli_prepare($dbconnect, "SELECT * FROM users WHERE userID=?");
mysqli_stmt_bind_param($stmt, "i", $_SESSION['userSession']);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();

// Check if the database connection was successful
if (!$dbconnect) {
    // If not, print an error message and terminate the script
    die("Connection failed: " . mysqli_connect_error());
}

////////////////////////////////////////////////////////////////
/////////// Include this section if Admin is required //////////
//////////  (uses function isAdmin in class.user.php) //////////
////////////////////////////////////////////////////////////////

// Get the user ID from the session array
$userID = $_SESSION['userSession'];

// Check if the user is an admin using the isAdmin function in class.user.php
if (!isAdmin($userID)) {
    // If not, print an error message and terminate the script
    die("You are NOT authorized to view/use this page");
}

////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////

/////////// Begin code here //////////////////////

// Print a message indicating that the user is authorized to view/use this page
echo "You are authorized to view/use this page";

////////// End code here and close ///////////////

// Close the database connection
mysqli_close($dbconnect);
?>
