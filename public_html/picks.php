<?Php
include "config.php"; // Database connection using PDO


//
// list of registered users

echo "This is a live list of users and their registation status.<br>";
echo "** A status of 'N' means they won't be able to make S2 picks online <br>";
echo "<br>";
	

// $sql = "SELECT * FROM `users` WHERE `userID` > 0 AND `userEmail` IS NOT NULL AND `userStatus` = 'Y'";
$sql = "SELECT * FROM `users` WHERE `userID` > 0";

echo "<table border=1>"; // start a table tag in the HTML

foreach ($dbo->query($sql) as $row) {   //Creates a loop to loop through results
echo "<tr><td>" . $row['userID'] . "</td><td>" . $row['userName'] . "</td><td>" . $row['userStatus'] . "</td></tr>";  
}

echo "</table>"; //Close the table in HTML

echo "<br><br>";

//
// End of list of users

//
// list of users that haven't clicked confirmation email.

// echo "This is a live list of users that haven't completed the registration process.<br>";
// echo "<br>";
	

// $sql = "SELECT * FROM `users` WHERE `userID` > 0 AND `userEmail` IS NOT NULL AND `userStatus` = 'N'";

// echo "<table border=1>"; // start a table tag in the HTML

// foreach ($dbo->query($sql) as $row) {   //Creates a loop to loop through results
// echo "<tr><td>" . $row['userID'] . "</td><td>" . $row['userName'] . "</td></tr>";  
// }

// echo "</table>"; //Close the table in HTML

// echo "<br><br>";

//
// End of list of registered users



//
// list of picks so far


echo "This is a live list of teams with picks for Segment 1.<br><br>";

include '2017_S1_Team_chart.php';

echo "<br><br>";


echo "Email Adresses for teams with picks for Segment 1:<br><br>";
foreach ($dbo->query($sql) as $row) {
echo "$row[userEmail] , $row[userEmail2] , ";
}
?>