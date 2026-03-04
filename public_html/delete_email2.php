<?php
session_start();

// find unique userID 
foreach ($_SESSION as $key=>$DBuserID);

require_once 'class.user.php';
$user_home = new USER();

if(!$user_home->is_logged_in())
{
    $user_home->redirect('login.php');
}

require "config.php"; // setup variables for database connection 

// Get the user ID from the request body
$request_body = json_decode(file_get_contents('php://input'), true);
$userID = $request_body['userID'];

// Update the user's email2 to 'None' in the database
$stmt = $dbconnect->prepare("UPDATE users SET userEmail2='None' WHERE userID=?");
$stmt->bind_param("i", $userID);
if (!$stmt->execute()) {
    http_response_code(500);
    echo "Error updating user email2: " . $dbconnect->error;
    exit();
}

// Close the prepared statement
$stmt->close();

// Close the database connection
$dbconnect->close();
