<?php
// include 'header.php';
session_start(); // ready to go!

require_once 'class.user.php';
$user_home = new USER();
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
}

require "config.php"; // setup variables for database connection
require "config_mrl.php"; // setup variables for the current MRL season & segment
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a"); //get date in format '8/25/2020 12:20 am'

// use $dbconnect to connect to the database
require_once 'config.php';
$mysqli = mysqli_connect($host_name, $username, $password, $database);
if (!$mysqli) {
    die("Connection failed: " . mysqli_connect_error());
}

////////////IMPORTANT//////////////////
// determine if logged in user is Admin
require_once 'Admin.php';
///////////////////////////////////////

// fetch user names from the database
$result = mysqli_query($mysqli, "SELECT userName, changeAuth FROM users WHERE userActive='Y'");

$usernames = array();
while ($row = mysqli_fetch_array($result)) {
    $usernames[] = array('name' => $row['userName'], 'auth' => $row['changeAuth']);
}
?>

<html>
<head>
    <title>MRL Admin</title>
    <style>
        .bold-border {
            border: 3px solid #FFFF00; /* Add a thicker border for Y cells */
            text-align: center; /* Center-align text in cells */
        }
    </style>
</head>
<body>

<!-- <p>This is the page to use to view user access to make late picks or change a driver.</p> -->
<p> </p>

<table>
    <tr>
        <th>Name</th>
        <th>Authorization (Y/N)</th>
    </tr>
    <?php foreach ($usernames as $user) { ?>
        <tr>
            <td <?php echo ($user['auth'] == 'Y') ? 'class="bold-border"' : ''; ?>><?php echo ($user['auth'] == 'Y') ? '<strong>' . $user['name'] . '</strong>' : $user['name']; ?></td>
            <td <?php echo ($user['auth'] == 'Y') ? 'class="bold-border"' : ''; ?>><?php echo ($user['auth'] == 'Y') ? '<strong>Y</strong>' : 'N'; ?></td>
        </tr>
    <?php } ?>
</table>

</body>
</html>



