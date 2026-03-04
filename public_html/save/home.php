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
									    <a tabindex="-2" href="">Edit profile</a>
                                        <a tabindex="-3" href="logout.php">Logout</a>
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

echo "$host <br />"; 
echo $_SERVER['HTTP_HOST'];
echo $row['userEmail'];
echo $row['userName']; 
echo '<img src="'.$images.'laughing.gif">' ;
echo $images;
 ?>  

<?php  
echo 'One line simple string.<br />';   
echo 'Two line simple string example<br />';   
echo 'Tomorrow I \'ll learn PHP global variables.<br />';   
echo 'This is a bad command : del c:\\*.* <br />';
 ?>  

<?php  
// Variables inside an echo statement.  
$abc='We are learning PHP';  
$xyz='w3resource.com';  
echo "$abc at $xyz <br />";  
// Simple variable display  
echo $abc;  
echo "<br />"; // creating a new line  
echo $xyz;  
echo "<br />"; // creating a new line  
// Displaying arrays  
$fruits=array('fruit1'=>'Apple','fruit2'=>'Banana');  
echo "Fruits are : {$fruits['fruit1']} and   
{$fruits['fruit2']}" ;  
?>  


<?php  
$a=1000;  
$b=1200;  
$c=1400;  
echo "<table style='border: 1px solid red;' cellspacing=0 cellpading=0>  
<tr> <td><font color=blue>Salary of Mr. A is</td> <td>$a$</font></td></tr>  
<tr> <td><font color=blue>Salary of Mr. B is</td> <td>$b$</font></td></tr>  
<tr> <td><font color=blue>Salary of Mr. C is</td> <td>$c$</font></td></tr>  
</table>";  
?>

<?php  
$a=1000;  
$b=1200;  
$c=1400;  
echo "<ol align='left'> <font color=decca size='4pt'> Salary statement for the Current month</font><br><li> <font color=blue>Salary of Mr. A is : $a$</font></li>  
<li> <font color=blue>Salary of Mr. B is : $b$</font></li><br><li> <font color=blue>Salary of Mr. C is : $c$</font></li>  
</ol>";  
?>


<?php  
$a=1000;  
$b=1200;  
$c=1400;  
echo "<table border=1  cellspacing=0 cellpading=0>  
Monthly Salary Statement </table>";  
echo "<table border=1 cellspacing=0 cellpading=0>  
<tr> <td><font color=blue>Salary of Mr. A is</td> <td>$a$</font></td></tr>  
<tr> <td><font color=blue>Salary of Mr. B is</td> <td>$b$</font></td></tr>  
<tr> <td><font color=blue>Salary of Mr. C is</td> <td>$c$</font></td></tr>  
</table>";  
?> 

<?php
$myString = "Hello!";
echo $myString;
echo "<h5>Just another line of text!</h5>";

?>

<label>Select Tour Package<span class="note">*</span>:</label>    
   <select name="package">  
    <option value="Goa" <?= ($_POST['package'] == "1")? "selected":"";?>>Goa</options>  
    <option value="Kashmir" <?= ($_POST['package'] == "2")? "selected":"";?>>Kashmir</options>  
    <option value="Rajasthan" <?= ($_POST['package'] == "3")? "selected":"";?>>Rajasthan</options>  
   </select>

<table align="center" style="border:solid black 1px;" cellspacing="0" cellpadding="0" bgcolor="#FFFFFF"><tr><td><html>
<BODY background="images/bg0.jpg" style="background-repeat:repeat-x" leftmargin="0" topmargin="9" marginwidth="0" marginheight="4" onLoad="setLogoutTime(480000);"><div align="center">
<TABLE WIDTH=800 BORDER=0 CELLPADDING=0 CELLSPACING=0>

	<TR>
		<TD COLSPAN=10>
			<IMG SRC="https://storage.googleapis.com/wzukusers/user-26482815/images/585e346300158ZiYuYnu/B51CB23279394801B2997CBAB9916A16_d400.jpg" WIDTH=400 HEIGHT=150></TD>

	</TR>
</TABLE>



		

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
			<IMG SRC="https://storage.googleapis.com/wzukusers/user-26482815/images/585e346300158ZiYuYnu/B51CB23279394801B2997CBAB9916A16_d400.jpg" WIDTH=800 HEIGHT=150></TD>

	</TR>
</TABLE>

<TABLE ALIGN="center" WIDTH="400" CELLSPACING="1" CELLPADDING="1" BORDER="1">
<TR><TH COLSPAN="4"><FONT COLOR="#FF0000" FACE="Arial">How Many Races does each Network have?</TH></TR>
<TR>
    <TH WIDTH="125" ALIGN="center"><FONT FACE="Arial">TV</TH>
    <TH WIDTH="75" ALIGN="center"><FONT FACE="Arial"># races</TH>
    <TH WIDTH="125" ALIGN="center"><FONT FACE="Arial">TV</TH>
    <TH WIDTH="75" ALIGN="center"><FONT FACE="Arial"># races</TH>
</TR>
<TR>
    <TD ALIGN="center"><FONT FACE="Arial">ABC</TD>
    <TD ALIGN="center"><FONT FACE="Arial">5</TD>
    <TD ALIGN="center"><FONT FACE="Arial">ESPN</TD>
    <TD ALIGN="center"><FONT FACE="Arial">12</TD>
</TR>
<TR>
    <TD ALIGN="center"><FONT FACE="Arial">CBS</TD>
    <TD ALIGN="center"><FONT FACE="Arial">4</TD>
    <TD ALIGN="center"><FONT FACE="Arial">TBS</TD>
    <TD ALIGN="center"><FONT FACE="Arial">3</TD>
</TR>
<TR>
    <TD ALIGN="center"><FONT FACE="Arial">NBC</TD>
    <TD ALIGN="center"><FONT FACE="Arial">1</TD>
    <TD ALIGN="center"><FONT FACE="Arial">TNN</TD>
    <TD ALIGN="center"><FONT FACE="Arial">9</TD>
</TR>
</TABLE>


<TABLE WIDTH="98%" BORDER=1 ALIGN="center" VALIGN="MIDDLE">

<TR>
	<TH ALIGN="center"><FONT FACE="Arial">Race#</TH>
	<TH ALIGN="center"><FONT FACE="Arial">Date</TH>
	<TH ALIGN="center"><FONT FACE="Arial">Location</TH>
	<TH ALIGN="center"><FONT FACE="Arial">Race</TH>
	<TH ALIGN="center"><FONT FACE="Arial">Laps</TH>
	<TH ALIGN="center"><FONT FACE="Arial">Track<BR>Size<BR>Miles</TH>
	<TH ALIGN="center"><FONT FACE="Arial">Race<BR>Length<BR>Miles</TH>
 	<TH ALIGN="center"><FONT FACE="Arial">TV<BR>Radio</TH>
 	<TH ALIGN="center"><FONT FACE="Arial">Time</TH>
	<TH ALIGN="center"><FONT FACE="Arial">Pole<BR>Qual</TH>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">*</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2/13</TD>
	<TD ALIGN="center"><FONT FACE="Arial">Daytona, FL</TD>
	<TH ALIGN="center"><FONT FACE="Arial">Pre-Bud Shootout*</TD>
	<TD ALIGN="center"><FONT FACE="Arial">25</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">62.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ESPN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">11am</TD>
	<TD ALIGN="center"><FONT FACE="Arial">?</TD>
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">*</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2/13</TD>
	<TD ALIGN="center"><FONT FACE="Arial">Daytona, FL</TD>
	<TH ALIGN="center"><FONT FACE="Arial">Bud Shootout*</TD>
	<TD ALIGN="center"><FONT FACE="Arial">25</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">62.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">CBS<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">12noon</TD>
	<TD ALIGN="center"><FONT FACE="Arial">?</TD>
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">*</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2/17</TD>
	<TD ALIGN="center"><FONT FACE="Arial">Daytona, FL</TD>
	<TH ALIGN="center"><FONT FACE="Arial">Twin 125's*</TD>
	<TD ALIGN="center"><FONT FACE="Arial">50</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">125</TD>
	<TD ALIGN="center"><FONT FACE="Arial">CBS<BR>2/19<BR>delay<BR>MRN live</TD>
	<TD ALIGN="center"><FONT FACE="Arial">12:30 2/17</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2/12</TD>
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">1</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2/20</TD>
	<TD ALIGN="center"><FONT FACE="Arial">Daytona, FL</TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-daytona500.htm">Daytona 500</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">200</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">500</TD>
	<TD ALIGN="center"><FONT FACE="Arial">CBS<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">12:00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2/12</TD>
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">2</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2/27</TD>
	<TD ALIGN="center"><FONT FACE="Arial">Rockingham, NC</TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-rock.htm">Dura-Lube Big Kmart 400</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">393</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1.017</TD>
	<TD ALIGN="center"><FONT FACE="Arial">399.68</TD>
	<TD ALIGN="center"><FONT FACE="Arial">TNN<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">12:30</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2/25</TD>
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">3</TD>
	<TD ALIGN="center"><FONT FACE="Arial">3/5</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/lasvegas.htm">Las Vegas, NV</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-vegas.htm">Carsdirect.com 400</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">267</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">400.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ABC<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2:30</TD>
	<TD ALIGN="center"><FONT FACE="Arial">3/3</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">4</TD>
	<TD ALIGN="center"><FONT FACE="Arial">3/12</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/atlanta.htm">Hampton, GA</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-atl.htm">Cracker Barrel 500</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">325</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1.54</TD>
	<TD ALIGN="center"><FONT FACE="Arial">500.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ABC<BR>PRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">3/10</TD>
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">3/19</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/darlington.htm">Darlington, SC</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-darlington.htm">Mall.com 400</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">293</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1.366</TD>
	<TD ALIGN="center"><FONT FACE="Arial">400.24</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">12:30</TD>
	<TD ALIGN="center"><FONT FACE="Arial">3/17</TD>
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">6</TD>
	<TD ALIGN="center"><FONT FACE="Arial">3/26</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/bristol.htm">Bristol, TN</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-bristol.htm">Food City 500</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">500</TD>
	<TD ALIGN="center"><FONT FACE="Arial">0.533</TD>
	<TD ALIGN="center"><FONT FACE="Arial">266.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>PRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">3/24</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">7</TD>
	<TD ALIGN="center"><FONT FACE="Arial">4/2</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/texas.htm">Fort Worth, TX</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-texas.htm">DirecTV 500</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">334</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">1.5</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">500.5</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">CBS<BR>PRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">2:00</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">3/31</TD><!--  -->
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">8</TD>
	<TD ALIGN="center"><FONT FACE="Arial">4/9</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/martinsville.htm">Martinsville, VA</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-martinsville.htm">Goody’s Body Pain 500</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">500</TD>
	<TD ALIGN="center"><FONT FACE="Arial">0.526</TD>
	<TD ALIGN="center"><FONT FACE="Arial">263</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">4/7</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">9</TD>
	<TD ALIGN="center"><FONT FACE="Arial">4/16</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/talladega.htm">Talladega, AL</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-talladega.htm">Diehard 500</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">188</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">2.66</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">500.08</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">ABC<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">4/14</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD>
	<TD ALIGN="center"><FONT FACE="Arial">4/23</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial" COLOR="#FF0000">EASTER</TD><!-- track -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!--  -->
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">10</TD>
	<TD ALIGN="center"><FONT FACE="Arial">4/30</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/california.htm">Fontana, CA</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-california.htm">NAPA Auto Parts 500</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">250</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2</TD>
	<TD ALIGN="center"><FONT FACE="Arial">500</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ABC<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2:00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">4/28</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">11</TD>
	<TD ALIGN="center"><FONT FACE="Arial">5/6</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/richmond.htm">Richmond, VA</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-richmond.htm">Pontiac Excitement 400</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">400</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">0.75</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">300</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">7:30</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">5/5</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD>
	<TD ALIGN="center"><FONT FACE="Arial">5/14</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial" COLOR="#FF0000">Mothers Day</TD><!-- track -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">*</TD>
	<TD ALIGN="center"><FONT FACE="Arial">5/20</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/lowes.htm">Concord, NC</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-thewinston.htm">The Winston*</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">70</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">1.5</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">105</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">TNN<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">7:30</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">5/19</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">12</TD>
	<TD ALIGN="center"><FONT FACE="Arial">5/28</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/lowes.htm">Charlotte, NC</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-cocacola.htm">Coca Cola 600</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">400</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">1.5</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">600</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">TBS<BR>PRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">6:05</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">5/24</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">13</TD>
	<TD ALIGN="center"><FONT FACE="Arial">6/4</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/dover.htm">Dover, DE</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-dover.htm">MBNA Platinum 400</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">400</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">1.00</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">400</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">TNN<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">12:30</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">6/2</TD><!--  -->
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">14</TD>
	<TD ALIGN="center"><FONT FACE="Arial">6/11</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/michigan.htm">Brooklyn, MI</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-mich.htm">Kmart 400</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">200</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2</TD>
	<TD ALIGN="center"><FONT FACE="Arial">400</TD>
	<TD ALIGN="center"><FONT FACE="Arial">CBS<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">6/9</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">15</TD>
	<TD ALIGN="center"><FONT FACE="Arial">6/18</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/pocono.htm">Pocono, PA</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-pocono.htm">Pocono 500</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">200</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">2.5</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">500</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">TNN<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">6/16</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">16</TD>
	<TD ALIGN="center"><FONT FACE="Arial">6/25</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/sonoma.htm">Somona, CA</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-sonoma.htm">Save Mart/<BR>Kragen 350</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">112</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1.95</TD>
	<TD ALIGN="center"><FONT FACE="Arial">216.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>PRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">4:00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">6/23</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">17</TD>
	<TD ALIGN="center"><FONT FACE="Arial">7/1</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/daytona.htm">Daytona, FL</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-daytona2.htm">Pepsi 400</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">160</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">2.5</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">400</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">CBS<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">8:00</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">6/29</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">18</TD>
	<TD ALIGN="center"><FONT FACE="Arial">7/9</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/nhis.htm">Loudon, NH</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-nhis.htm">Thatlook.com 300</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">300</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1.058</TD>
	<TD ALIGN="center"><FONT FACE="Arial">317.4</TD>
	<TD ALIGN="center"><FONT FACE="Arial">TNN<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">7/7</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD>
	<TD ALIGN="center"><FONT FACE="Arial">7/16</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial" COLOR="#FF0000">OPEN</TD><!-- track -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">19</TD>
	<TD ALIGN="center"><FONT FACE="Arial">7/23</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/pocono.htm">Pocono, PA</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-poc2.htm">Pennsylvania 500</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">200</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">2.5</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">500</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">TBS<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">1:05</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">7/21</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD>
	<TD ALIGN="center"><FONT FACE="Arial">7/30</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial" COLOR="#FF0000">OPEN</TD><!-- track -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">20</TD>
	<TD ALIGN="center"><FONT FACE="Arial">8/5</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/indy.htm">Indianapolis, IN</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-indy.htm">Brickyard 400</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">160</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">2.5</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">400</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">ABC<BR>IMS</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">8/3</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">21</TD>
	<TD ALIGN="center"><FONT FACE="Arial">8/13</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/watkinsglen.htm">Watkins Glen, NY</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-glen.htm">Global @ the Glen</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">90</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2.45</TD>
	<TD ALIGN="center"><FONT FACE="Arial">220.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">12:30</TD>
	<TD ALIGN="center"><FONT FACE="Arial">8/11</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">22</TD>
	<TD ALIGN="center"><FONT FACE="Arial">8/20</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/michigan.htm">Brooklyn, MI</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-mich2.htm">Pepsi 400 presented by MEIJER</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">200</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2</TD>
	<TD ALIGN="center"><FONT FACE="Arial">400</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">12:30</TD>
	<TD ALIGN="center"><FONT FACE="Arial">8/18</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">23</TD>
	<TD ALIGN="center"><FONT FACE="Arial">8/26</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/bristol.htm">Bristol, TN</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-bristol2.htm">goRacing.com 500</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">500</TD>
	<TD ALIGN="center"><FONT FACE="Arial">0.533</TD>
	<TD ALIGN="center"><FONT FACE="Arial">266.5</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>PRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">7:30</TD>
	<TD ALIGN="center"><FONT FACE="Arial">8/25</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">24</TD>
	<TD ALIGN="center"><FONT FACE="Arial">9/3</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/darlington.htm">Darlington, SC</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-darl2.htm">Pepsi Southern 500</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">367</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1.366</TD>
	<TD ALIGN="center"><FONT FACE="Arial">501.3</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">9/1</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">25</TD>
	<TD ALIGN="center"><FONT FACE="Arial">9/9</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/richmond.htm">Richmond, VA</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-rir2.htm">Chevrolet Monte Carlo 400</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">400</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">0.75</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">300</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">7:30</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">9/7</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">26</TD>
	<TD ALIGN="center"><FONT FACE="Arial">9/17</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/nhis.htm">Loudon, NH</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-nhis2.htm">Dura Lube 300 presented by Kmart</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">300</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1.058</TD>
	<TD ALIGN="center"><FONT FACE="Arial">317.4</TD>
	<TD ALIGN="center"><FONT FACE="Arial">TNN<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">12:30</TD>
	<TD ALIGN="center"><FONT FACE="Arial">9/15</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">27</TD>
	<TD ALIGN="center"><FONT FACE="Arial">9/24</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/dover.htm">Dover, DE</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-dover2.htm">MBNA.com 400</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">400</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">1.00</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">400</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">TNN<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">12:30</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">9/22</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">28</TD>
	<TD ALIGN="center"><FONT FACE="Arial">10/1</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/martinsville.htm">Martinsville, VA</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-martinsville2.htm">NAPA Autocare 500</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">500</TD>
	<TD ALIGN="center"><FONT FACE="Arial">0.526</TD>
	<TD ALIGN="center"><FONT FACE="Arial">263</TD>
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">9/29</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">29</TD>
	<TD ALIGN="center"><FONT FACE="Arial">10/8</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/lowes.htm">Charlotte, NC</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-lowes2.htm">UAW-GM<BR>Quality 500</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">334</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">1.5</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">501</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">TBS<BR>PRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">1:05</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">10/4</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">30</TD>
	<TD ALIGN="center"><FONT FACE="Arial">10/15</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/talladega.htm">Talladega, AL</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-talladega2.htm">Winston 500 presented by UPS</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">188</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">2.66</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">500.08</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">10/13</TD><!--  -->
</TR>

<TR>
	<TD ALIGN="center"><FONT FACE="Arial">31</TD>
	<TD ALIGN="center"><FONT FACE="Arial">10/22</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/rock.htm">Rockingham, NC</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-rockingham2.htm">Pop-Secret Microwave Popcorn 400</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">393</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1.017</TD>
	<TD ALIGN="center"><FONT FACE="Arial">399.68</TD>
	<TD ALIGN="center"><FONT FACE="Arial">TNN<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">12:30</TD>
	<TD ALIGN="center"><FONT FACE="Arial">10/20</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD>
	<TD ALIGN="center"><FONT FACE="Arial">10/29</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial" COLOR="#FF0000">OPEN</TD><!-- track -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">&nbsp;</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">32</TD>
	<TD ALIGN="center"><FONT FACE="Arial">11/5</TD>
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/phoenix.htm">Phoenix, AZ</A></TD>
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-phoenix.htm">Dura-Lube 500</A></TD>
	<TD ALIGN="center"><FONT FACE="Arial">312</TD>
	<TD ALIGN="center"><FONT FACE="Arial">1.00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">312</TD>
	<TD ALIGN="center"><FONT FACE="Arial">TNN<BR>MRN</TD>
	<TD ALIGN="center"><FONT FACE="Arial">2:00</TD>
	<TD ALIGN="center"><FONT FACE="Arial">11/3</TD>
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">33</TD>
	<TD ALIGN="center"><FONT FACE="Arial">11/12</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/homestead.htm">Homestead, FL</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-hms.htm">Pennzoil 400</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">267</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">1.5</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">400</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">NBC<BR>MRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">12:30</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">11/10</TD><!--  -->
</TR>
<TR>
	<TD ALIGN="center"><FONT FACE="Arial">34</TD>
	<TD ALIGN="center"><FONT FACE="Arial">11/19</TD><!-- date -->
	<TD ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/pages/tracks/atlanta.htm">Hampton, GA</A></TD><!-- track -->
	<TH ALIGN="center"><FONT FACE="Arial"><A HREF="http://www.jayski.com/next/2000/2000-atl2.htm">NAPA 500</A></TD><!-- race -->
	<TD ALIGN="center"><FONT FACE="Arial">325</TD><!-- laps -->
	<TD ALIGN="center"><FONT FACE="Arial">1.54</TD><!-- track len -->
	<TD ALIGN="center"><FONT FACE="Arial">500.5</TD><!-- race len -->
	<TD ALIGN="center"><FONT FACE="Arial">ESPN<BR>PRN</TD><!-- network -->
	<TD ALIGN="center"><FONT FACE="Arial">1:00</TD><!--  -->
	<TD ALIGN="center"><FONT FACE="Arial">11/17</TD><!--  -->
</TR><BR><BR>

</TABLE><BR><BR>

<P><U>All times Eastern and are PM unless noted</U><BR>
*Non-Points Race</P>

<P><B><FONT COLOR="#FF0000">NASCAR Sprint Cup Schedules by Season</FONT></B><BR>
<A HREF="http://www.jayski.com/news/pages/story/_/page/2015-NASCAR-Sprint-Cup-Schedule">2015</A> | 
<A HREF="http://www.jayski.com/news/pages/story/_/page/2014-NASCAR-Sprint-Cup-Schedule">2014</A> | 
<A HREF="http://www.jayski.com/news/pages/story/_/page/2013-NASCAR-Sprint-Cup-Schedule">2013</A> | 
<A HREF="http://www.jayski.com/pages/2012cup_sched.htm">2012</A> | 
<A HREF="http://www.jayski.com/pages/2011cup_sched.htm">2011</A><BR>
<A HREF="http://www.jayski.com/pages/2010cup_sched.htm">2010</A> | 
<A HREF="http://www.jayski.com/pages/2009cup_sched.htm">2009</A> | 
<A HREF="http://www.jayski.com/pages/2008cup_sched.htm">2008</A> | 
<A HREF="http://www.jayski.com/pages/2007cup_sched.htm">2007</A> | 
<A HREF="http://www.jayski.com/pages/2006cup_sched.htm">2006</A><BR>
<A HREF="http://www.jayski.com/pages/2005cup_sched.htm">2005</A> | 
<A HREF="http://www.jayski.com/pages/2004cup_sched.htm">2004</A> | 
<A HREF="http://www.jayski.com/pages/2003wc_sched.htm">2003</A> | 
<A HREF="http://www.jayski.com/pages/2002wc_sched.htm">2002</A> | 
<A HREF="http://www.jayski.com/pages/2001wc_sched.htm">2001</A> | 
<A HREF="http://www.jayski.com/pages/2000wc_sched.htm">2000</A></P>

<P><B><FONT COLOR="#FF0000">Sprint Cup Race Results<BR>Links to Results, Points, Lineups by Season</FONT></B><BR>
<A HREF="http://www.jayski.com/news/pages/story/_/page/2015-NASCAR-Sprint-Cup-Race-Results">2015</A> | 
<A HREF="http://www.jayski.com/news/pages/story/_/page/2014-NASCAR-Sprint-Cup-Race-Results">2014</A> | 
<A HREF="http://www.jayski.com/news/pages/story/_/page/2013-NASCAR-Sprint-Cup-Race-Results">2013</A> | 
<A HREF="http://www.jayski.com/news/pages/story/_/page/2012-NASCAR-Sprint-Cup-Race-Results">2012</A><BR>
<A HREF="http://www.jayski.com/news/pages/story/_/page/2011-NASCAR-Sprint-Cup-Race-Results">2011</A> | 
<A HREF="http://www.jayski.com/pages/2010cupresults.htm">2010</A> | 
<A HREF="http://www.jayski.com/pages/2009cupresults.htm">2009</A> | 
<A HREF="http://www.jayski.com/pages/2008cupresults.htm">2008</A> | 
<A HREF="http://www.jayski.com/pages/2007cupresults.htm">2007</A><BR>
<A HREF="http://www.jayski.com/pages/2006cupresults.htm">2006</A> | 
<A HREF="http://www.jayski.com/pages/2005cupresults.htm">2005</A> | 
<A HREF="http://www.jayski.com/pages/2004cupresults.htm">2004</A> | 
<A HREF="http://www.jayski.com/pages/2003cupresults.htm">2003</A> | 
<A HREF="http://www.jayski.com/pages/2002cupresults.htm">2002</A></P>

<P><A HREF="http://www.jayski.com">Jayski's Silly Season Site</A></P>

		<!--/.fluid-container-->
        <script src="bootstrap/js/jquery-1.9.1.min.js"></script>
        <script src="bootstrap/js/bootstrap.min.js"></script>
        <script src="assets/scripts.js"></script>
	</body>

</html>