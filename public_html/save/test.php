<?php
session_start();
require_once 'class.user.php';
$user_home = new USER();

if(!$user_home->is_logged_in())
{
	$user_home->redirect('login.php');
}

$stmt = $user_home->runQuery("SELECT * FROM tbl_users WHERE userID=:uid");
$stmt->execute(array(":uid"=>$_SESSION['userSession']));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html class="no-js">
    
    <head>
        <title><?php echo $row['userEmail']; ?></title>
        <!-- Bootstrap -->
        <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
        <link href="bootstrap/css/bootstrap-responsive.min.css" rel="stylesheet" media="screen">
        <link href="assets/styles.css" rel="stylesheet" media="screen">
        <!-- HTML5 shim, for IE6-8 support of HTML5 elements -->
        <!--[if lt IE 9]>
            <script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
        <![endif]-->
		<style>
div.container {
    width: 100%;
    border: 1px solid gray;
}

header, footer {
    padding: 1em;
    color: white;
    background-color: black;
    clear: left;
    text-align: center;
}

nav {
    float: left;
    max-width: 160px;
    margin: 0;
    padding: 1em;
}

nav ul {
    list-style-type: none;
    padding: 0;
}
   
nav ul a {
    text-decoration: none;
}

article {
    margin-left: 170px;
    border-left: 1px solid gray;
    padding: 1em;
    overflow: hidden;
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
								<?php echo $row['userName']; ?> <i class="caret"></i>
                                </a>
                                <ul class="dropdown-menu">
                                    <li>
									    <a tabindex="-1" href="index.html">Home (Jeff's page)</a>
                                        <a tabindex="-2" href="logout.php">Logout</a>
                                    </li>
                                </ul>
                            </li>
                        </ul> 
						<a class="brand" >              <?php echo $sitename; ?> - My page</a>
                    <!--/.nav-collapse -->
                </div>
            </div>
        </div>
<?php
$sql = "SELECT * FROM `tbl_drivers` WHERE `driverYear` = 2017 AND `driverGroup` = \'A\'";

echo $host;
echo $_SERVER['HTTP_HOST'];
echo $row['userEmail'];
echo $row['userName']; 
echo '<img src="'.$images.'laughing.gif">' ;
echo $images;


$myString = "Hello!";
echo $myString;
echo "<h5>Just another line of text!</h5>";

?>

</SCRIPT>
<table align="center" style="border:solid black 1px;" cellspacing="0" cellpadding="0" bgcolor="#FFFFFF"><tr><td><html>
<BODY background="images/bg0.jpg" style="background-repeat:repeat-x" leftmargin="0" topmargin="9" marginwidth="0" marginheight="4" onLoad="setLogoutTime(480000);"><div align="center">
<TABLE WIDTH=680 BORDER=0 CELLPADDING=0 CELLSPACING=0>

	<TR>
		<TD COLSPAN=10>
			<IMG SRC="https://secure.paymentcard.com/images/header.jpg" WIDTH=800 HEIGHT=150></TD>

	</TR>
</TABLE>

<SCRIPT  LANGUAGE="JavaScript">

		

		<?php
			$myString = "Hello!";
			echo $myString;
			echo "<h5>Just another line of text!</h5>";

		?>
		
<article>

		<h4 style="color:#E4D1AC;">This is your personal page, where you will see your yearly picks and make your segment picks </h4>
	    <p style="color:#E4D1AC;">You will also be setting your team name for the season here. </p>
		<p style="color:#E4D1AC;">In the upper left, you should see your name (team owner) that you entered when you first registered.</p>
		<p style="color:#E4D1AC;">There is also a dropdown menu which will take you back to the main page, also allow you to logout.</p>
		<p style="color:#E4D1AC;">In the future I plan to add a link to update your profile information, such as username, login & password, etc.</p>

        <img src="https://storage.googleapis.com/wzukusers/user-26482815/images/585e346300158ZiYuYnu/B51CB23279394801B2997CBAB9916A16_d400.jpg">
        </article>
		
<TABLE WIDTH=680 BORDER=0 CELLPADDING=0 CELLSPACING=0>

	<TR>
		<TD COLSPAN=10>
			<IMG SRC="https://secure.paymentcard.com/images/header.jpg" WIDTH=800 HEIGHT=150></TD>

	</TR>
</TABLE>
		<!--/.fluid-container-->
        <script src="bootstrap/js/jquery-1.9.1.min.js"></script>
        <script src="bootstrap/js/bootstrap.min.js"></script>
        <script src="assets/scripts.js"></script>
	</body>

</html>