<?php
// include 'header.php';
session_start(); // ready to go!

// find unique userID 
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

// Check if the form has been submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get the new email from the form
    $new_email = htmlspecialchars($_POST["new_email"]);

    // Validate and sanitize the new email address
    $new_email = filter_var($new_email, FILTER_SANITIZE_EMAIL);
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        echo "Invalid email address.";
        exit();
    }

    // Get the user info
    $stmt = $dbconnect->prepare("SELECT userEmail FROM users WHERE userID = ?");
    $stmt->bind_param("i", $DBuserID);
    $stmt->execute();
    $result = $stmt->get_result();

    // Check if user exists
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_email = $row["userEmail"];
    } else {
        echo "User not found.";
        exit();
    }

    // Update the user's email in the database
    $stmt = $dbconnect->prepare("UPDATE users SET userEmail=? WHERE userID=?");
    $stmt->bind_param("si", $new_email, $DBuserID);
    if ($stmt->execute()) {
        // Update the displayed email
        $user_email = $new_email;
        $success_message = "Login email was changed to " . $new_email . ".";
    } else {
        // echo "Error updating user email: " . $dbconnect->error;
        $error_message = "Error updating user email: " . $dbconnect->error;
    }
}

// Get the user info
$stmt = $dbconnect->prepare("SELECT userEmail FROM users WHERE userID = ?");
$stmt->bind_param("i", $DBuserID);
$stmt->execute();
$result = $stmt->get_result();

// Check if user exists
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $user_email = $row["userEmail"];
} else {
    echo "User not found.";
    exit();
}

// Close the prepared statement
$stmt->close();

// Close the database connection
$dbconnect->close();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Change Email</title>
    <meta charset="UTF-8">
    <link rel="stylesheet" type="text/css" href="mrl-styles.css">
</head>
<body>

<?php if (isset($success_message)) { ?>
    <p style="color:green;"><?php echo $success_message; ?></p>
<?php } ?>

<?php if (isset($error_message)) { ?>
    <p style="color:red;"><?php echo $error_message; ?></p>
<?php } ?>

<!-- HTML form to update user email -->
<form method="post">
    <p class="MsoNormal" style="font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif; color:#dfcca8;">Current email: <?php echo $user_email; ?></p>
    <label for="new_email" style="font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif; color:#dfcca8;">New Email:</label>
    <input type="email" id="new_email" name="new_email" size="30" maxlength="100">
    <input type="submit" value="Update Email">
</form>
