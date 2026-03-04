<?php
// 2025_Rules.php
// Start the session before any output is sent
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
    echo '<div style="color: green;">Hi ' . $first_name . ', as a team owner, you are authorized to view the league rules.</div>';
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
    <title>2025 Rules</title>
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
        .numbered {
            font-weight: bold;
            text-decoration: none;
        }
        .numbered-text {
            font-weight: bold;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <h1>2025 Rules</h1>
    <p><span class="numbered">1 –</span> <span class="numbered-text">There are 36 NASCAR Points Events this season.</span></p>
    <p>The season has been divided into 4 segments:</span></p>
    <ul>
        <li>Segment #1 will consist of 8 races</li>
        <li>Segments #2 and #3 will consist of 9 races each.</li>
        <li>The final segment is the “Playoffs” and will consist of 10 races.</li>
    </ul>
    <p><span class="numbered">2 –</span> <span class="numbered-text">Each player will create a team of 4 drivers for each segment using the online submission form found on your team page.</span></p>
    <ul>
        <li>Each team must consist of one driver from each Group (A, B, C, D). You then own those 4 drivers for that quarter.</li>
        <li>You can use a driver for ONE quarter only. After you use that driver they are no longer available to you.</li>
        <li>Upon submission, your picks will be reflected on your team page automatically. Your picks are official at that time. You can still make changes by re-selecting drivers at any time, until the form is locked, which will be just prior to the first race of the segment. Specific date and time will appear on your team page.</li>
        <li>Your Team Name will carry over from year to year. If you wish to change it for the current year, eMail us at <a href="mailto:ManliusRacingLeague@gmail.com">ManliusRacingLeague@gmail.com</a> with your new name.</li>
        <li>New players (Team Owners) must provide us with Team Name prior to choosing team.</li>
    </ul>
    <p><span class="numbered">3 –</span> <span class="numbered-text">Scoring</span></p>
    <ul>
        <li>Each player will accumulate the total points that the drivers on your team receive from NASCAR.</li>
        <li>Please note that you are picking a driver and not a team. If your driver does not make the race – “NO POINTS”.</li>
        <li>If your driver is permanently removed from the car by the team owner after taking 2 races without points, you can replace that driver for another. Send the request to <a href="mailto:manliusracingleague@gmail.com">manliusracingleague@gmail.com</a>. It will be reviewed and all teams will be notified of any changes.</li>
        <li>NOTE: Each team is allowed only one “replacement” per year.</li>
        <li>You DO NOT receive the “adjustment” points awarded during the Playoff Format.</li>
    </ul>
    <p><span class="numbered">4 –</span> <span class="numbered-text">Points will be totaled for each quarter and for the entire year. Payouts are as follows:</span></p>
    <ul>
        <li>36 weekly high scores</li>
        <li>9 segment winners (1st & 2nd place for S1-S3, and 1st, 2nd & 3rd place for the Playoffs)</li>
        <li>3 yearly winners (1st, 2nd, 3rd place)</li>
    </ul>
    <p><span class="numbered">5 –</span> <span class="numbered-text">Updates will be weekly (or as often as possible).</span></p>
    <p>Good Luck!</p>

    <script>
        // Add loaded class to body after page is fully loaded
        document.addEventListener("DOMContentLoaded", function () {
            document.body.classList.add("loaded");
        });
    </script>
</body>
</html>
