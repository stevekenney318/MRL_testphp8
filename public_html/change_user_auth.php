<?php
session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

$isAdmin = isAdmin($_SESSION['userSession'] ?? null);
date_default_timezone_set("America/New_York");
$currentTimeIs = date("n/j/Y g:i a"); //get date in format '8/25/2020 12:20 am'

// use $dbconnect to connect to database
require_once 'config.php';
$mysqli = mysqli_connect($host_name,$username,$password,$database);
if (!$mysqli) {
  die("Connection failed: " . mysqli_connect_error());
}


// fetch user names from database
$result = mysqli_query($mysqli,"SELECT userName, changeAuth FROM users WHERE userActive='Y'");

$usernames = array();
while($row = mysqli_fetch_array($result)) {
  $usernames[$row['userName']] = $row['changeAuth'];
}

// handle form submission
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $selected_user = $_POST['user'];
  
  // toggle changeAuth in database
  $stmt = $user_home->runQuery("UPDATE users SET changeAuth = IF(changeAuth='N', 'Y', 'N') WHERE userName=:user");
  $stmt->bindParam(':user', $selected_user);
  $stmt->execute();

  // update value in $usernames array
  $usernames[$selected_user] = ($usernames[$selected_user] == 'Y') ? 'N' : 'Y';

  // create success message
  $message = "$selected_user's authorization was changed to {$usernames[$selected_user]}";
}
?>

<html>
<head>
<title>MRL Admin - Change User Auth</title>
<link rel="stylesheet" href="/mrl-styles.css">
</head>
<body>

<?php
echo $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

if (!$isAdmin) {
    exit;
}
?>

<p>This is the page to use to change a users access to be able to make late picks or change a driver.</b>


<form method="post">
  <label for="user">Select a user:</label>
  <select name="user" id="user" onchange="document.getElementById('message').innerHTML=''">
    <option value="">Select Name</option>
    <?php foreach ($usernames as $name => $auth) { ?>
      <option value="<?php echo $name; ?>"><?php echo $name . ' (' . $auth . ')'; ?></option>
    <?php } ?>
  </select>
  <br><br>
  <input type="submit" value="Toggle Authorization">
</form>

<div id="message"><?php echo $message; ?></div>

</body>
</html>

<?php
include 'report_all_users_auth.php';
?>
