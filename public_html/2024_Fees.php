<?php
// 2024_Fees.php
// Start the session and include necessary files
session_start();
// Store the current page URL in the session
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];
// Include necessary files after session start
require_once 'class.user.php';
require 'config.php';
require 'config_mrl.php';

// Create a new USER object
$user_home = new USER();


// Redirect to login if not logged in
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
} else {
    // Fetch the user's information from the database
    $stmt = $user_home->runQuery("SELECT * FROM users WHERE userID=:uid");
    $stmt->execute(array(":uid" => $_SESSION['userSession']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Extract the first name from the user's name
    $name_parts = explode(' ', $row['userName']);
    $first_name = $name_parts[0];

    // Output the greeting message with the user's first name
    echo '<div style="color: green;">Hi ' . $first_name . ', as a team owner, you are authorized to view the Fees & Payment information.</div>';
    echo "<script>console.log('Validated logged in user');</script>";
}



// Include header for MRL styling, etc.
// include 'header.php';

// Set the time zone and get the current time
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2024 Fees</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            margin: 0 auto;
            max-width: 80%;
            line-height: 1.6;
            /* Hide content initially */
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease-in-out, visibility 0.3s ease-in-out;
        }
        body.loaded {
            /* Show content when loaded */
            opacity: 1;
            visibility: visible;
        }
        h1 {
            text-align: center;
        }
        p {
            margin-bottom: 15px;
        }

        .section-text {
            font-weight: bold;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <h1>2024 Fees & Payment</h1>
    <p><span class="section-text">Fees:</span></p>
    <ul>
        <li style="list-style-type: none;">For 2024, the Fee is <span style="color: White;">$45.60</span> per Race Team:</li>

        <img src="https://manliusracingleague.com/wp-content/uploads/2024/03/2024-Fees-dark-20240306.175245799.jpg" alt="2024 Fees" style="width: 50%; margin-bottom: 20px;">
        <li style="list-style-type: none;">And here are the invoices for the website cost – 3yr <a href="https://manliusracingleague.com/wp-content/uploads/2023/03/Hostwinds-3-yr-Hosting-Invoice-3009803.pdf" target="_blank">hosting fee</a> (split cost over 2023, 2024 & 2025) and 1 yr <a href="https://manliusracingleague.com/wp-content/uploads/2024/03/Hostwinds-1-yr-Domain-Name-Registration-2024-Invoice-3424936.pdf" target="_blank">domain name fee</a> (invoiced each year).</li>


    </ul>

    <!-- <br> Blank line -->

    <p><span class="section-text">Payment:</span></p>
    <ul>
    
    <?php
    // Fetch the user ID from the session array
$uid = isset($_SESSION['userSession']) ? $_SESSION['userSession'] : null;

// Include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
        padding: 3px;
        font-size: 16px;
        color: black
      }
   </style>";

// Table for user information
echo "<table align=left style=width:80%>"; // Start a table tag in the HTML

// Paid Status
$sql = "SELECT * FROM `user_teams` WHERE `userID` = $uid AND `raceYear` = $raceYear";
$result = $dbo->query($sql);

if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Paid Status ' . $raceYear . "</td><td style=background-color:#b7dee8>" . $row['paidStatus'] . "</td></tr>";
        $DBpaidStatus = $row['paidStatus'];
    }
}

// Paid Amount
$result = $dbo->query($sql);

if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Amount' . "</td><td style=background-color:#b7dee8>" . "$" . $row['paidAmount'] . "</td></tr>";
        $DBpaidAmount = $row['paidAmount'];
    }
}

// Paid How
$result = $dbo->query($sql);

if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'How' . "</td><td style=background-color:#b7dee8>" . $row['paidHow'] . "</td></tr>";
        $DBpaidHow = $row['paidHow'];
    }
}

// Paid Comment
$result = $dbo->query($sql);

if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Comment' . "</td><td style=background-color:#b7dee8>" . $row['paidComment'] . "</td></tr>";
        $DBpaidComment = $row['paidComment'];
    }
}

echo "</table>"; // Close the table for user information

?>
        <br><br><br><br>
        <li style="list-style-type: none;">Payment is due by Friday April 5, 2024</li>
        <li style="list-style-type: none;">Please no comments with payment except -> MRL.</li>
    </ul>
    <div style="padding-left: 20px;">
        <ul>
            <li><a href="https://venmo.com/u/SteveKenney318" target="_blank">Venmo: @SteveKenney318</a></li>
            <li><a href="https://paypal.me/stevekenney" target="_blank">PayPal: @SteveKenney</a></li>
            <li>Zelle: stevekenney318@gmail.com</li>
        </ul>
    </div>

    <p>Thank you - Steve</p>

    <script>
        // Add loaded class to body after page is fully loaded
        document.addEventListener("DOMContentLoaded", function () {
            document.body.classList.add("loaded");
        });
    </script>
</body>
</html>
