<?php
session_start();
require_once 'class.user.php';
$user_login = new USER();

if ($user_login->is_logged_in() != "") {
    $user_login->redirect(getReturnURL());
}

// Store the current page URL in the session if not already set
if (!isset($_SESSION['return_to'])) {
    $_SESSION['return_to'] = $_SERVER['REQUEST_URI'];
}

if (isset($_POST['btn-login'])) {
    $email = trim($_POST['txtemail']);
    $upass = trim($_POST['txtupass']);

    if ($user_login->login($email, $upass)) {
        // Redirect the user back to the stored URL
        $return_to = isset($_SESSION['return_to']) ? $_SESSION['return_to'] : getReturnURL();
        unset($_SESSION['return_to']); // Clear the stored URL
        $user_login->redirect($return_to);
        // Debugging: Output the stored URL and redirection target
        echo "<script>console.log('Stored URL: ".$_SESSION['return_to']."');</script>";
        echo "<script>console.log('Redirecting to: $return_to');</script>";
        exit(); // Make sure no further output is sent after header modification
    } else {
        // Handle login failure
        echo "<script>console.log('Login failure');</script>";
    }
}

function getReturnURL() {
    // Default return URL
    $defaultReturnURL = 'index.php';
    // Check if there's a return URL stored in the session
    return isset($_SESSION['return_to']) ? $_SESSION['return_to'] : $defaultReturnURL;
}

function get_ip_address()
{
    foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip); // just to be safe

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manlius Racing League</title>
    <!-- Bootstrap -->
    <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
    <link href="bootstrap/css/bootstrap-responsive.min.css" rel="stylesheet" media="screen">
    <link href="assets/styles.css" rel="stylesheet" media="screen">
    <style>
        body {
            background-color: #222222;
        }
    </style>
</head>
<body id="login">
<div class="container">
    <?php
    if (isset($_GET['inactive'])) {
        ?>
        <div class='alert alert-error'>
            <button class='close' data-dismiss='alert'>&times;</button>
            <strong>Notice -- </strong> Your account not yet activated. An email with instructions was sent to you while registering.
        </div>
        <?php
    }
    ?>
    <form class="form-signin" method="post">
        <?php
        if (isset($_GET['error'])) {
            ?>
            <div class='alert alert-error'>
                <button class='close' data-dismiss='alert'>&times;</button>
                <strong>Incorrect Email Address or Password</strong>
            </div>
            <?php
        }
        ?>
        <h3 class="form-signin-heading">Manlius Racing League</h3>
        <p class="info-text">You must be logged in to access <span style="color: green;"><?php echo trim($_SESSION['return_to'], '/'); ?></span></p>
        <a href="register.php">Still need to Register ? </a>
        <HR>
        <input type="email" class="input-block-level" placeholder="Email address" name="txtemail" required/>
        <input type="password" class="input-block-level" placeholder="Password" name="txtupass" required/>
        <a href="fpass.php">Forgot Password ? </a>
        <HR WIDTH="75%">
        <button class="btn btn-large btn-primary" type="submit" name="btn-login">Login</button>
    </form>
</div> <!-- /container -->
<script src="bootstrap/js/jquery-1.9.1.min.js"></script>
<script src="bootstrap/js/bootstrap.min.js"></script>
</body>
</html>
