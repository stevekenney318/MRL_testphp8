<?php
// Start the session to access session variables
session_start();

// Import the USER class and create an instance
require_once 'class.user.php';
$user_home = new USER();

// If the user is not logged in, redirect to the login page
if (!$user_home->is_logged_in())
{
	$user_home->redirect('login.php');
}

date_default_timezone_set('America/New_York');
require "config.php"; // setup variables for database connection 
require "config_mrl.php"; // setup variables for current MRL season & segment
$currentTimeIs = date("n/j/Y g:i a"); //get date in format '8/25/2020 12:20 am'

$stmt = $user_home->runQuery("SELECT * FROM users WHERE userID=:uid");
$stmt->execute(array(":uid"=>$_SESSION['userSession']));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Get First name from Full name
// Assuming $row['userName'] contains "John Smith"
$name_parts = explode(' ', $row['userName']);
$first_name = $name_parts[0];
?>
<!DOCTYPE html>
<html>
  <head> 
    <meta charset="UTF-8">
    <link rel="stylesheet" type="text/css" href="mrl-styles.css">
  </head>
  <body>
  <nav>
  <div class="dropdown">
    <button class="dropbtn">Welcome <?php echo $first_name ; ?></button>
    <div class="dropdown-content">
        <a tabindex="-1" href="<?php echo $mrl; ?>">MRL Home</a>
        <a tabindex="-2" href="<?php echo $mrl; ?>profile.php">Profile Page</a>
        <a tabindex="-3" href="<?php echo $mrl; ?>logout.php">Logout</a>
    </div>
  </div>
  <div class="title"><?php echo $sitename; ?> - My Team Page</div>
  <iframe src="https://free.timeanddate.com/clock/i8tgfcpc/n777/fn7/fcdfcca8/tct/pct/tt0/tw1/td1/th2/ta1/tb1" frameborder="0" width="333" height="21" allowtransparency="true"></iframe>







  <label class="switch" style="float: right;">
    <input type="checkbox" id="mySlider">
    <span class="slider round"></span>
  </label>
  <script type="text/javascript">
    // Get the slider element
    var slider = document.getElementById("mySlider");

    // Add an event listener to the slider that changes the background color
    slider.addEventListener("change", function() {
      if (slider.checked) {
        // If the slider is checked, change the background color to white
        document.body.style.backgroundColor = "white";
      } else {
        // If the slider is not checked, change the background color to the previously saved color
        document.body.style.backgroundColor = "<?php echo $_SESSION['bg_color']; ?>";
      }
    });
  </script>

</nav>
  
 </body>
</html>