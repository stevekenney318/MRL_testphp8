<?php
session_start();
require_once 'class.user.php';
$user_home = new USER();

if(!$user_home->is_logged_in())
{
	$user_home->redirect('login.php');
}




$stmt = $user_home->runQuery("SELECT * FROM users WHERE userID=:uid");
$stmt->execute(array(":uid"=>$_SESSION['userSession']));
$row = $stmt->fetch(PDO::FETCH_ASSOC);


?>

<!DOCTYPE html>
<html class="no-js">
    
    
	<head>
        <title><?php echo $row['userName']; ?></title>
        <!-- Bootstrap -->
        <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
        <link href="bootstrap/css/bootstrap-responsive.min.css" rel="stylesheet" media="screen">
        <link href="assets/styles.css" rel="stylesheet" media="screen">
        <!-- HTML5 shim, for IE6-8 support of HTML5 elements -->
        <!--[if lt IE 9]>
            <script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
        <![endif]-->
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
									    <a tabindex="-1" href="http://<?php echo $website; ?>">Home</a>
									    <a tabindex="-2" href="">Edit profile (Not Available Yet)</a>
                                        <a tabindex="-3" href="logout.php">Logout</a>
                                    </li>
                                </ul>
                            </li>
                        </ul> 
						<a class="brand" > <ol align='center'>    <?php echo $sitename; ?> - My Team Page</a>
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
<table align="center" style="width:70%"><tr><td>
<div class=WordSection1>
<p class=MsoNormal ><span style='font-size:14.0pt;
line-height:120%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>
Hi <?php echo $row['userName']; ?>... <br>
<br>
Welcome to your team page.<br>
<br>
<br>
Below you will find links for this years season (password is mrl), your current team chart,the latest submission form,<br>
and then any previous years played. The current year team chart will be updated once your drivers have<br>
been entered in the database.<br>
<br>
<br>
2020 Fees & Payment info <a href="https://manliusracingleague.com/2019/12/27/2020-fees-payment/" target="_blank" rel="noopener noreferrer">is here</a><br>
2020 Rules <a href="https://manliusracingleague.com/2019/12/22/2020-rules/" target="_blank" rel="noopener noreferrer">are here</a><br>
2020 Race Schedule <a href="https://manliusracingleague.com/wp-content/uploads/2020/01/2020-schedule.pdf" target="_blank" rel="noopener noreferrer">is here</a><br>
</p>
</div>
</table>

</style>
<table align="center" style="width:70%"><tr><td>
<div class=WordSection1>
<p class=MsoNormal ><span style='font-size:14.0pt;
line-height:120%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>
<br>

- 2020 picks <br> 
</p>
</div>
</table>		
<?php

include '2020_user_team_chart.php';

?>
<table align="center" style="width:70%"><tr><td>
<div class=WordSection1>
<p class=MsoNormal ><span style='font-size:14.0pt;
line-height:120%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>
<br>
<br>

<!-- Do not display this at the moment

** Make your picks below **  If you don't see the form on this page, you can <a href="https://form.jotform.com/193553540870158" target="_blank" rel="noopener noreferrer">click here</a> **<br>

-->
<br>

<!–– This is the start of the form code from Jotform.com  ––>
    

    <iframe
      id="JotFormIFrame-193553540870158"
      onload="window.parent.scrollTo(0,0)"
      allowtransparency="true"
      allowfullscreen="true"
      allow="geolocation; microphone; camera"
      src="https://form.jotform.com/193553540870158"
      frameborder="0"
      style="width: 1px;
      min-width: 100%;
      height:622px;
      border:none;"
      scrolling="no"
    >
    </iframe>
    <script type="text/javascript">
      var ifr = document.getElementById("JotFormIFrame-193553540870158");
      if(window.location.href && window.location.href.indexOf("?") > -1) {
        var get = window.location.href.substr(window.location.href.indexOf("?") + 1);
        if(ifr && get.length > 0) {
          var src = ifr.src;
          src = src.indexOf("?") > -1 ? src + "&" + get : src  + "?" + get;
          ifr.src = src;
        }
      }
      window.handleIFrameMessage = function(e) {
        var args = e.data.split(":");
        if (args.length > 2) { iframe = document.getElementById("JotFormIFrame-" + args[(args.length - 1)]); } else { iframe = document.getElementById("JotFormIFrame"); }
        if (!iframe) { return; }
        switch (args[0]) {
          case "scrollIntoView":
            iframe.scrollIntoView();
            break;
          case "setHeight":
            iframe.style.height = args[1] + "px";
            break;
          case "collapseErrorPage":
            if (iframe.clientHeight > window.innerHeight) {
              iframe.style.height = window.innerHeight + "px";
            }
            break;
          case "reloadPage":
            window.location.reload();
            break;
          case "loadScript":
            var src = args[1];
            if (args.length > 3) {
                src = args[1] + ':' + args[2];
            }
            var script = document.createElement('script');
            script.src = src;
            script.type = 'text/javascript';
            document.body.appendChild(script);
            break;
          case "exitFullscreen":
            if      (window.document.exitFullscreen)        window.document.exitFullscreen();
            else if (window.document.mozCancelFullScreen)   window.document.mozCancelFullScreen();
            else if (window.document.mozCancelFullscreen)   window.document.mozCancelFullScreen();
            else if (window.document.webkitExitFullscreen)  window.document.webkitExitFullscreen();
            else if (window.document.msExitFullscreen)      window.document.msExitFullscreen();
            break;
        }
        var isJotForm = (e.origin.indexOf("jotform") > -1) ? true : false;
        if(isJotForm && "contentWindow" in iframe && "postMessage" in iframe.contentWindow) {
          var urls = {"docurl":encodeURIComponent(document.URL),"referrer":encodeURIComponent(document.referrer)};
          iframe.contentWindow.postMessage(JSON.stringify({"type":"urls","value":urls}), "*");
        }
      };
      if (window.addEventListener) {
        window.addEventListener("message", handleIFrameMessage, false);
      } else if (window.attachEvent) {
        window.attachEvent("onmessage", handleIFrameMessage);
      }
      </script>

<!–– This is the end of the form code from Jotform.com  ––>
    

<br>
<br>
- And your previous years picks (if you played.) <br> 
</p>
</div>
</table>
<?php


include '2019_user_team_chart_simple.php';

?>
<br>
<br>
<?php


include '2018_user_team_chart_simple.php';

?>
<br>
<br>		
<?php

include '2017_user_team_chart_simple.php';

?>
<table align="center" style="width:70%"><tr><td>
<div class=WordSection1>
<p class=MsoNormal ><span style='font-size:14.0pt;
line-height:120%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>
<br>
<br>



		<!--/.fluid-container-->
        <script src="bootstrap/js/jquery-1.9.1.min.js"></script>
        <script src="bootstrap/js/bootstrap.min.js"></script>
        <script src="assets/scripts.js"></script>
	</body>

</html>