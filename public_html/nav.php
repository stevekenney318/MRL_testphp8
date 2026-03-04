<?php

session_start(); // ready to go!

// find unique userID
// very important for making picks, DO NOT DELETE
foreach ($_SESSION as $key=>$DBuserID);


require_once 'class.user.php';
$user_home = new USER();

if(!$user_home->is_logged_in())
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
<html class="no-js">
   
    
	<head>
        <title><?php echo $first_name; ?>'s Team Page </title>
        <!-- Bootstrap -->
        <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
        <link href="bootstrap/css/bootstrap-responsive.min.css" rel="stylesheet" media="screen">
        <link href="assets/styles.css" rel="stylesheet" media= "screen">
        <style>
body {
  background-color: #222222;
}
body {
        padding-top: 60px; /* add some padding to the top to make sure content isn't covered by nav bar */
      }
</style>
	</head>

   
	<body>

        <div class="navbar navbar-fixed-top">
            <div class="navbar-inner">
                <div class="container-fluid">
                    <a class="btn btn-navbar" data-toggle="collapse" data-target=".nav-collapse"> <span class="icon-bar"></span>
                     <span class="icon-bar"></span>
                     <span class="icon-bar"></span>
                    </a>
					<ul class="nav pull-left">
                            <li class="dropdown">
                                <a href="#" role="button" class="dropdown-toggle" data-toggle="dropdown"> <i class="icon-user"></i> 
								<?php echo $first_name ; ?> <i class="caret"></i>
                                </a>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a tabindex="-1" href="<?php echo $mrl; ?>">MRL Home</a>
									    <a tabindex="-2" href="<?php echo $mrl; ?>profile.php">Profile Page</a>
                                        <a tabindex="-3" href="<?php echo $mrl; ?>logout.php">Logout</a>
                                    </li>
                                </ul>
                            </li>
                        </ul> 
						<a class="brand" > <ol align='center'><?php echo $sitename; ?> - My Team Page</a>

    <iframe src="https://freesecure.timeanddate.com/clock/i7eqrnfz/n777/fn16/fs18/bas/bat0/pd2/tt0/tw1/tm2" frameborder="1px" width="330" height="28"></iframe>


                    <!--/.nav-collapse -->
                </div>
            </div>
        </div>



<style>


		<!--/.fluid-container-->
        <script src="bootstrap/js/jquery-1.9.1.min.js"></script>
        <script src="bootstrap/js/bootstrap.min.js"></script>
        <script src="assets/scripts.js"></script>
	</body>

</html>