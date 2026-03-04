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
    // Check which button was clicked
    if (isset($_POST["update_email2"])) {
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
            // Update the displayed email
            $user_email2 = $new_email2;
            header("Location: profile.php"); // Redirect after update to prevent resubmission on refresh
            exit();
        } else {
            echo "Error updating user email2: " . $dbconnect->error;
        }
    } elseif (isset($_POST["delete_email2"])) {
        // Delete the user's email2 in the database
        $stmt = $dbconnect->prepare("UPDATE users SET userEmail2=NULL WHERE userID=?");
        $stmt->bind_param("i", $DBuserID);
        if ($stmt->execute()) {
            // Update the displayed email
            $user_email2 = NULL;
            header("Location: profile.php"); // Redirect after update to prevent resubmission on refresh
            exit();
        } else {
            echo "Error deleting user email2: " . $dbconnect->error;
        }
    }
}

// Get the user info
$stmt = $dbconnect->prepare("SELECT userEmail2 FROM users WHERE userID = ?");
$stmt->bind_param("i", $DBuserID);
$stmt->execute();
$result = $stmt->get_result();

// Check if user exists
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $user_email2 = $row["userEmail2"];
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
  <title>Change Email2</title>
  <link href="assets/styles.css" rel="stylesheet">
</head>
<body>

<!-- HTML form to update or delete user email2 -->
<form method="post">
    <p class="MsoNormal" style="font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif; color:#dfcca8;">Current email2: <?php echo $user_email2; ?></p>
    <label for="new_email" style="font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif; color:#dfcca8;">New Email2:</label>
    <input type="email" id="new_email2" name="new_email2">
    <br>
    <input type="submit" value="Update Email2">
    <button type="button" onclick="deleteEmail2()">Delete Email2</button>
</form>

<!-- JavaScript function to delete or set Email2 to 'None' -->
<script>
function deleteEmail2() {
    if (confirm("Are you sure you want to delete Email2?")) {
        // Send a request to delete or set Email2 to 'None'
        fetch('delete_email2.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({userID: '<?php echo $DBuserID; ?>'})
        })
        .then(response => {
            if (response.ok) {
                // Reload the page to update the displayed email
                window.location.reload();
            } else {
                alert("Error deleting Email2.");
            }
        })
        .catch(error => alert("Error deleting Email2: " + error));
    }
}
</script>
