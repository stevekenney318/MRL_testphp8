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

// Import the config.php and config_mrl.php files
require "config.php";





echo "<link rel='stylesheet' href='mrl-styles.css'>";
?>
<body id="mybody" style="background-color: #222222; padding:25px;">
  <!-- defines the body of the page with an ID of "mybody", a black background color, and 25px of padding -->
  <button onclick="toggle();">Night/Day</button>
  <br /><br />
  <script>
    let element = document.getElementById("mybody"); // retrieves the body element with the ID of "mybody"
    let originalColor = element.style.backgroundColor; // saves the original background color of the body element

    let toggle = () => { // defines a function named "toggle" using ES6 arrow function syntax
      if (element.style.backgroundColor === "white") { // checks if the body background color is white
        element.style.backgroundColor = originalColor; // changes the body background color to the original color if it's currently white
      } else {
        element.style.backgroundColor = "white"; // changes the body background color to white if it's currently not white
      }
    }
  </script>
</body>

