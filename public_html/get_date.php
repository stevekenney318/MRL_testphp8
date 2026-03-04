<?php

// get date/time in many formats

// Assuming today is March 10th, 2001, 5:16:18 pm, and that we are in the
// Mountain Standard Time (MST) Time Zone

$today = date("m.d.y");                         // 03.10.01
echo $today;
echo "<br>";
$today = date("j, n, Y");                       // 10, 3, 2001
echo $today;
echo "<br>";
$today = date("Ymd");                           // 20010310
echo $today;
echo "<br>";
$today = date('h-i-s, j-m-y, it is w Day');     // 05-16-18, 10-03-01, 1631 1618 6 Satpm01
echo $today;
echo "<br>";
$today = date('\i\t \i\s \t\h\e jS \d\a\y.');   // it is the 10th day.
echo $today;
echo "<br>";
$today = date("D M j G:i:s T Y");               // Sat Mar 10 17:16:18 MST 2001
echo $today;
echo "<br>";
$today = date('H:m:s \m \i\s\ \m\o\n\t\h');     // 17:03:18 m is month
echo $today;
echo "<br>";
$today = date("H:i:s");                         // 17:16:18
echo $today;
echo "<br>";
$today = date("Y-m-d H:i:s");                   // 2001-03-10 17:16:18 (the MySQL DATETIME format)
echo $today;
echo "<br>";
$today = date("F j, Y, g:i a");                 // March 10, 2001, 5:16 pm
echo $today;
echo "<br>";
echo "****************************************";
echo "<br>";

$currentTimeIs = date("n/j/Y g:i a"); //get date in format '8/25/2020 12:20 am'
$timeStamp = strtotime($currentTimeIs);
echo $timeStamp;
echo "<br>";

?>
<iframe src="https://freesecure.timeanddate.com/clock/i7eqqqbr/n777" frameborder="0" width="114" height="19"></iframe>
