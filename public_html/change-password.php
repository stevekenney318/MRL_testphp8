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

// Fetch user information from database
$DBuserID = $_SESSION['user'];
$stmt = $dbconnect->prepare("SELECT userEmail, userPass FROM users WHERE userID = ?");
$stmt->bind_param("i", $DBuserID);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$user_email = $row['userEmail'];

// Handle form submission
if (isset($_POST['submit'])) {
    $new_pass = $_POST['new_pass'];
    $confirm_pass = $_POST['confirm_pass'];

    // Check if new password and confirm password match
    if ($new_pass != $confirm_pass) {
        $error = "New password and confirm password do not match";
    }
    // Update the user's password in the database
    else {
        $hashed_new_pass = md5($new_pass);
        $stmt = $dbconnect->prepare("UPDATE users SET userPass=? WHERE userID=?");
        $stmt->bind_param("si", $hashed_new_pass, $DBuserID);
        if ($stmt->execute()) {
            // header("Location: profile.php"); // Redirect after update to prevent resubmission on refresh
            header("Location: ".$_SERVER['PHP_SELF']); // Redirect after update to prevent resubmission on refresh
            exit();
        } else {
            echo "Error updating user password: " . $dbconnect->error;
        }
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Change Password</title>
</head>
<body>
    <h2>Change Password</h2>

    <?php if (isset($error)): ?>
        <p><?php echo $error; ?></p>
    <?php endif; ?>

    <form method="post">
        <p>
            New Password: <input type="password" name="new_pass" value="" required>
            <button type="button" onclick="togglePassword('new_pass')">Show Password</button>
        </p>
        <p>
            Confirm Password: <input type="password" name="confirm_pass" value="" required>
            <button type="button" onclick="togglePassword('confirm_pass')">Show Password</button>
        </p>
        <p>
            <input type="submit" name="submit" value="Change Password">
        </p>
    </form>
    <p>
        Current email: <?php echo $user_email; ?>
    </p>
    <script>
        function togglePassword(inputName) {
            var x = document.getElementsByName(inputName)[0];
            if (x.type === "password") {
                x.type = "text";
            } else {
                x.type = "password";
            }
        }
    </script>
</body>
</html>
