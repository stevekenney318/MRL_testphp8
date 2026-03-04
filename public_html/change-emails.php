<?php
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
    $new_email2 = htmlspecialchars($_POST["new_email2"]);

    // Validate and sanitize the new email address
    $new_email = filter_var($new_email, FILTER_SANITIZE_EMAIL);
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        echo "Invalid email address.";
        exit();
    }

    // Validate and sanitize the new second email address
    $new_email2 = filter_var($new_email2, FILTER_SANITIZE_EMAIL);
    if (!filter_var($new_email2, FILTER_VALIDATE_EMAIL)) {
        echo "Invalid second email address.";
        exit();
    }

    // Get the user info
    $stmt = $dbconnect->prepare("SELECT userEmail, userEmail2 FROM users WHERE userID = ?");
    $stmt->bind_param("i", $DBuserID);
    $stmt->execute();
    $result = $stmt->get_result();

    // Check if user exists
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_email = $row["userEmail"];
        $user_email2 = $row["userEmail2"];
    } else {
        echo "User not found.";
        exit();
    }

    // Update the user's email in the database
    if (!empty($new_email)) {
        $stmt = $dbconnect->prepare("UPDATE users SET userEmail=? WHERE userID=?");
        $stmt->bind_param("si", $new_email, $DBuserID);
        if ($stmt->execute()) {
            // Update the displayed email
            $user_email = $new_email;
            header("Location: profile.php"); // Redirect after update to prevent resubmission on refresh
            exit();
        } else {
            echo '<p style="color:red;">Error updating user email: ' . $dbconnect->error . '</p>';
        }
    }

    // Update the user's second email in the database
    if (!empty($new_email2)) {
        $stmt = $dbconnect->prepare("UPDATE users SET userEmail2=? WHERE userID=?");
        $stmt->bind_param("si", $new_email2, $DBuserID);
        if ($stmt->execute()) {
            // Update the displayed second email
            $user_email2 = $new_email2;
            header("Location: profile.php"); // Redirect after update to prevent resubmission on refresh
            exit();
        } else {
            echo '<p style="color:red;">Error updating second user email: ' . $dbconnect->error . '</p>';
        }
    }
}

// Get the user info
$stmt = $dbconnect->prepare("SELECT userEmail, userEmail2 FROM users WHERE userID = ?");
$stmt->bind_param("si", $new_email, $DBuserID);
$stmt->execute();
$result = $stmt->get_result();

// Check if user exists
if ($result->num_rows > 0) {
$row = $result->fetch_assoc();
$user_email = $row["userEmail"];
$user_email2 = $row["userEmail2"];
} else {
echo "User not found.";
exit();
}

// Close the prepared statement
$stmt->close();

// Check if the form has been submitted for userEmail2
if ($_SERVER["REQUEST_METHOD"] == "POST") {
// Get the new email from the form
$new_email2 = htmlspecialchars($_POST["new_email2"]);
// Validate and sanitize the new email address
$new_email2 = filter_var($new_email2, FILTER_SANITIZE_EMAIL);
if (!filter_var($new_email2, FILTER_VALIDATE_EMAIL)) {
    echo "Invalid email address.";
    exit();
}

// Update the user's email2 in the database
$stmt = $dbconnect->prepare("UPDATE users SET userEmail2=? WHERE userID=?");
$stmt->bind_param("si", $new_email2, $DBuserID);
if ($stmt->execute()) {
    // Update the displayed email2
    $user_email2 = $new_email2;
    header("Location: ".$_SERVER['PHP_SELF']); // Redirect after update to prevent resubmission on refresh
    exit();
} else {
    echo '<p style="color:red;">Error updating user email2: ' . $dbconnect->error . '</p>';
}
}

// Check if the form has been submitted for userEmail
if ($_SERVER["REQUEST_METHOD"] == "POST") {
// Get the new email from the form
$new_email = htmlspecialchars($_POST["new_email"]);
// Validate and sanitize the new email address
$new_email = filter_var($new_email, FILTER_SANITIZE_EMAIL);
if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
    echo "Invalid email address.";
    exit();
}

// Update the user's email in the database
$stmt = $dbconnect->prepare("UPDATE users SET userEmail=? WHERE userID=?");
$stmt->bind_param("si", $new_email, $DBuserID);
if ($stmt->execute()) {
    // Update the displayed email
    $user_email = $new_email;
    header("Location: ".$_SERVER['PHP_SELF']); // Redirect after update to prevent resubmission on refresh
    exit();
} else {
    echo '<p style="color:red;">Error updating user email: ' . $dbconnect->error . '</p>';
}
}

?>

<!-- HTML form to update user email -->
<form method="post">
    <p class="MsoNormal" style="font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif; color:#dfcca8;">Current email: <?php echo $user_email; ?></p>
    <label for="new_email" style="font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif; color:#dfcca8;">New Email:</label>
    <input type="email" id="new_email" name="new_email" size="30" maxlength="100">
    <input type="submit" value="Update Email">
</form>
<!-- HTML form to update user email2 -->
<form method="post">
    <p class="MsoNormal" style="font-size:16.0pt; line-height:120%; font-family:'Century
    Gothic',sans-serif; color:#dfcca8;">Current alternate email: <?php echo $user_email2; ?></p>
    <label for="new_email2" style="font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif; color:#dfcca8;">New Alternate Email:</label>
    <input type="email" id="new_email2" name="new_email2" size="30" maxlength="100">
    <input type="submit" value="Update Alternate Email">
</form>