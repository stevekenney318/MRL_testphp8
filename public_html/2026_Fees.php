<?php
// 2026_Fees.php
session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

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

// Set the time zone and get the current time
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2026 Fees</title>
    <link rel="stylesheet" href="/mrl-styles.css">
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
    <h1>2026 Fees & Payment</h1>
    <p><span class="section-text">Fees:</span></p>
    <ul>
        <li style="list-style-type: none;">For 2026, the Fee is <span style="color: Red;">$43.88</span> per Race Team (18 teams):</li>
        <br>

        <a href="https://manliusracingleague.com/wp-content/uploads/2026/02/2026-Fees-dark-18-teams-20260216.012530106.jpg" target="_blank" rel="noopener noreferrer">
          <img
            src="https://manliusracingleague.com/wp-content/uploads/2026/02/2026-Fees-dark-18-teams-20260216.012530106.jpg"
            alt="2026 Fees"
            style="
              width: 110%;
              max-width: 600px;
              margin-bottom: 20px;
              cursor: pointer;
              height: auto;
            "
          >
        </a>

        <li style="list-style-type: none;">Here is the <a href="https://manliusracingleague.com/wp-content/uploads/2026/01/2026-Payouts.pdf" target="_blank">Payout structure chart</a> for 2026.</li>
        <br>
        <li style="list-style-type: none;">And here are the invoices for the website cost – 4yr <a href="https://manliusracingleague.com/wp-content/uploads/2026/01/H_33806949.pdf" target="_blank">hosting fee</a> (split cost over 2026, 2027, 2028 & 2029) and 1 yr <a href="https://manliusracingleague.com/wp-content/uploads/2026/01/H_36858448.pdf" target="_blank">domain name fee</a> (invoiced each year).</li>
    </ul>

    <p><span class="section-text">Payment:</span></p>
    <ul>

    <?php
    // Fetch the user ID from the session array
    $uid = isset($_SESSION['userSession']) ? (int)$_SESSION['userSession'] : 0;

    // Inline table styles (kept local to this page)
    echo "<style type='text/css'>
          table, th, td {
            border: 1px solid black;
            border-collapse: collapse;
            padding: 3px;
            font-size: 16px;
            color: black;
          }
       </style>";

    // Gate: Only show the payment status table if a user_teams row exists for this year
    $sql = "SELECT paidStatus, paidAmount, paidHow, paidComment
            FROM user_teams
            WHERE userID = :uid AND raceYear = :raceYear
            LIMIT 1";

    $stmtPay = $dbo->prepare($sql);
    $stmtPay->execute([
        ':uid' => $uid,
        ':raceYear' => $raceYear
    ]);

    $payRow = $stmtPay->fetch(PDO::FETCH_ASSOC);

    if ($payRow) {
        // Table for payment information
        echo "<table align='left' style='width:80%'>";

        echo "<tr>
                <td style='width:14%;background-color:#b7dee8'>Paid Status 2026</td>
                <td style='background-color:#b7dee8'>" . htmlspecialchars($payRow['paidStatus'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
              </tr>";

        echo "<tr>
                <td style='width:14%;background-color:#b7dee8'>Amount</td>
                <td style='background-color:#b7dee8'>$" . htmlspecialchars($payRow['paidAmount'] ?? '0.00', ENT_QUOTES, 'UTF-8') . "</td>
              </tr>";

        echo "<tr>
                <td style='width:14%;background-color:#b7dee8'>How</td>
                <td style='background-color:#b7dee8'>" . htmlspecialchars($payRow['paidHow'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
              </tr>";

        echo "<tr>
                <td style='width:14%;background-color:#b7dee8'>Comment</td>
                <td style='background-color:#b7dee8'>" . htmlspecialchars($payRow['paidComment'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>
              </tr>";

        echo "</table>";

        // Preserve your spacing under the table
        echo "<br><br><br><br>";
    } else {
        // No row yet (likely team name not set / user_teams not created)
        echo "<div class='notice-gate'>
        Please set team name on Team Page to see payment status chart.
      </div>";

    }
    ?>

        <li style="list-style-type: none;">Payment is due by Friday April 3, 2026</li>
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
