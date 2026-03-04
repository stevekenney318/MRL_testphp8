<?php

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

date_default_timezone_set('America/New_York');
require "config.php"; // setup variables for database connection 
require "config_mrl.php"; // setup variables for current MRL season & segment
$currentTimeIs = date("n/j/Y g:i a"); //get date in format '8/25/2020 12:20 am'

$stmt = $user_home->runQuery("SELECT * FROM users WHERE userID=:uid");
$stmt->execute(array(":uid"=>$_SESSION['userSession']));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Get First name from Full name
// Assuming $row['userName'] contains "John Smith"
$name_parts = explode(' ', $row['userName']);
$first_name = $name_parts[0];

// is user an Admin (uses function isAdmin in class.user.php)
$userID = $_SESSION['userSession'];
if (isAdmin($userID)) {

    // Check connection
    if ($dbconnect->connect_error) {
        die("Connection failed: " . $dbconnect->connect_error);
    }
    
    // Fetch the list of names from the database
    $sql = "SELECT userName  FROM `users` WHERE `userActive` = 'Y'";
    $result = $dbconnect->query($sql);
    
// Display the dropdown list of names
if ($result->num_rows > 0) {
    echo "<form method='post'>";
    echo "<select name='userName'>";
    echo "<option value=''>Select Name</option>"; // modify empty option
    while($row = $result->fetch_assoc()) {
        echo "<option value='" . $row['userName'] . "'>" . $row['userName'] . "</option>";
    }
    echo "</select>";
    echo "<input type='submit' value='Submit'>";
    echo "</form>";
} else {
    echo "No users found.";
}



    
    // Close the database connection
    $dbconnect->close();

} else {
    echo "NOT authorized"; 
}

?>
