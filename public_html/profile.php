<?php
//--------------------------------
// error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
//--------------------------------

session_start(); // ready to go!
ob_start(); // Start output buffering

// find unique userID 
// foreach ($_SESSION as $key=>$DBuserID);


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

// Get emails
$email1 = $row['userEmail'];
$email2 = $row['userEmail2'];

?>
<!DOCTYPE html>
<html>
<head>
</head>
<body style="background-color:#222222;">
</body>


<!DOCTYPE html>
<html class="no-js">
    
    
	<head>
        <title><?php echo $first_name; ?>'s Profile Page </title>
        <!-- Bootstrap -->
        <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
        <link href="bootstrap/css/bootstrap-responsive.min.css" rel="stylesheet" media="screen">
        <link href="assets/styles.css" rel="stylesheet" media= "screen">
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
									    <a tabindex="-2" href="<?php echo $mrl; ?>team.php">Team Page</a>
                                        <a tabindex="-3" href="<?php echo $mrl; ?>logout.php">Logout</a>
                                    </li>
                                </ul>
                            </li>
                        </ul> 
						<a class="brand" > <ol align='center'><?php echo $sitename; ?> - My Profile Page</a>

    <iframe src="https://freesecure.timeanddate.com/clock/i7eqrnfz/n777/fn16/fs18/bas/bat0/pd2/tt0/tw1/tm2" frameborder="1px" width="330" height="28"></iframe>


                    <!--/.nav-collapse -->
                </div>
            </div>
        </div>



<style>

table, th, td {
    border: 1.5 px solid black;
    padding: 5px;
}
</style>
<table align="center" style="width:80%"><tr><td>
<div class=WordSection1>
<p class=MsoNormal ><span style='font-size:16.0pt;
line-height:120%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>
Hi <?php echo $first_name ; ?> ... <br>


<br>
<br>
Welcome to your profile page.<br>
<br>


</p>
</div>

<p class=MsoNormal ><span style='font-size:16.0pt;
line-height:120%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>

<a href="change-login-email.php">Change Login Email &#9658;  <?php echo $email1 ; ?> <br></a>
<br>
<a href="change-second-email.php">Change or Add a Secondary Email &#9658;  <?php echo $email2 ; ?> <br></a>
<br>
<!--
<br>
Login Email: <?php echo $email1 ; ?> <br>
<a href="change-login-email.php">Change Login Email</a>
<br>
<br>
Backup Email: <?php echo $email2 ; ?> <br>
<a href="change-second-email.php">Change Secondary Email</a>
<br>
-->





</p>
</div>
</table>




<br>

<table align="center" style="width:80%"><tr><td>
<div class=WordSection1>
<p class=MsoNormal ><span style='font-size:22.0pt;
line-height:100%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>





<div id="footer">
    <p class=MsoNormal ><span style='font-size:10.0pt;
line-height:120%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>

Copyright &copy 2017-<script>document.write(new Date().getFullYear())</script> Manlius Racing League</p>
    </div>

</table> 


		<!--/.fluid-container-->
        <script src="bootstrap/js/jquery-1.9.1.min.js"></script>
        <script src="bootstrap/js/bootstrap.min.js"></script>
        <script src="assets/scripts.js"></script>
	</body>

</html>