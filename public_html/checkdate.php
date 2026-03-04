<?Php

date_default_timezone_set("	America/New_York");
include "config.php"; // setup variables for database connection 
include "config_mrl.php"; // setup variables for current MRL season & segment

date_default_timezone_set("America/New_York");
$today = date("n/j/Y g:i a"); 
echo $today;
echo "<br>";

  // Convert to timestamp
  $end_ts = strtotime($formLockDate);  // $formLockDate is set in config_mrl.php
  $user_ts = strtotime($today); 
  
  if ($end_ts >= $user_ts) {
    include 'form-mrl003.php';
} else {
    echo "$formLockedMessage"; 
  }

?>