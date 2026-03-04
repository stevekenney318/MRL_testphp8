<?php
session_start();
require_once 'class.user.php';

$reg_user = new USER();

if($reg_user->is_logged_in()!="")
{
	$reg_user->redirect('team.php');
}


if(isset($_POST['btn-signup']))
{
	$uname = trim($_POST['txtuname']);
	$email = trim($_POST['txtemail']);
	$upass = trim($_POST['txtpass']);
	$code = md5(uniqid(rand()));
	
	$stmt = $reg_user->runQuery("SELECT * FROM users WHERE userEmail=:email_id");
	$stmt->execute(array(":email_id"=>$email));
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	
	if($stmt->rowCount() > 0)
	{
		$msg = "
		      <div class='alert alert-error'>
				<button class='close' data-dismiss='alert'>&times;</button>
					<strong>Sorry !</strong>  This email address is already registered: <a href='login.php'>Login here</a>
			  </div>
			  ";
	}
	else
	{
		if($reg_user->register($uname,$email,$upass,$code))
		{			
			$id = $reg_user->lasdID();		
			$key = base64_encode($id);
			$id = $key;
			
			$message = "					
						Hello $uname,
						<br />
						Welcome to $sitename!<br/>
						<br />
                        <a href='http://$website/verify.php?id=$id&code=$code'>-- Click here to complete the registartion process --</a>
						<br />						
						Once you complete this registration process, you will be able to login to make and view your segment picks.<br/>
						<br />
						As a reminder, here are your current login credentials:<br/>
						<br />
						Email address : $email <br/>
						Password : $upass <br/>
						<br />
						If you have any issues (or comments) -> manliusracingleague@gmail.com
						<br />
                        <a href='http://$website/verify.php?id=$id&code=$code'>  -- Click here to complete the registartion process --</a>
						<br /><br />
						Thanks for joining.";
						
			$subject = "Confirm Registration";
						
			$reg_user->send_mail($email,$message,$subject);	
			$msg = "
					<div class='alert alert-success'>
						<button class='close' data-dismiss='alert'>&times;</button>
						<strong>Success!</strong>  We've sent an email to $email.
                    Please click on the confirmation link in the email to create your account. 
			  		</div>
					";
			header("refresh:10;login.php");
		}
		else
		{
			echo "sorry , please try again or contact manliusracingleague@gmail.com ...";
		}		
	}
}
?>
<!DOCTYPE html>
<html>
  <head>
    <title>Register - Manlius Racing League</title>
    <!-- Bootstrap -->
    <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
    <link href="bootstrap/css/bootstrap-responsive.min.css" rel="stylesheet" media="screen">
    <link href="assets/styles.css" rel="stylesheet" media="screen">
     <!-- HTML5 shim, for IE6-8 support of HTML5 elements -->
    <!--[if lt IE 9]>
      <script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
    <![endif]-->
    <script src="js/vendor/modernizr-2.6.2-respond-1.1.0.min.js"></script>
  </head>
  <body id="login">
    <div class="container">
				<?php if(isset($msg)) echo $msg;  ?>
      <form class="form-signin" method="post">
        <h3 class="form-signin-heading">Manlius Racing League</h3>
        <p class="info-text">You must register to to access your team info</p>
		<a href="login.php">Already registered ?</a>
		<HR>
        <input type="text" class="input-block-level" placeholder="Team Owner(s) - That's you!" name="txtuname" required />
        <input type="email" class="input-block-level" placeholder="Email address (use for login)" name="txtemail" required />
        <input type="password" class="input-block-level" placeholder="Password" name="txtpass" required />
     	<HR WIDTH="60%">
        <button class="btn btn-large btn-primary" type="submit" name="btn-signup">Register</button>
		<HR WIDTH="60%">
		<p class="info-text">-- manliusracingleague@gmail.com --</p>
      </form>

    </div> <!-- /container -->
    <script src="vendors/jquery-1.9.1.min.js"></script>
    <script src="bootstrap/js/bootstrap.min.js"></script>
  </body>
</html>