<?Php
include "config.php"; // Database connection using PDO


//
// list of registered users

echo "Test - this is a live list of all users <br>";
echo "First Y/N column - able to make onlinepicks (not verified) <br>";
echo "Second Y/N column - active player in current season";
echo "<br>";
echo "<br>";
	

// $sql = "SELECT * FROM `users` WHERE `userID` > 0 AND `userEmail` IS NOT NULL AND `userStatus` = 'Y'";
// $sql = "SELECT * FROM `users` WHERE `userID` > 0 AND `userStatus` = 'Y'";
$sql = "SELECT * FROM `users` WHERE `userID` > 0";

echo "<table border=1>"; // start a table tag in the HTML

foreach ($dbo->query($sql) as $row) {   //Creates a loop to loop through results
echo "<tr><td>" . $row['userID'] . "</td><td>" . $row['userName'] . "</td><td>" . $row['userStatus'] . "</td><td>" . $row['userActive'] . "</td></tr>";  
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
// list of email addresses so far


// $sql = "SELECT * FROM `users` WHERE `userID` > 0 AND `userStatus` = 'Y'";


echo "Email Adresses :<br><br>";
foreach ($dbo->query($sql) as $row) {
// echo "$row[userEmail] , $row[userEmail2] , ";
echo "$row[userEmail] , ";
}
?>