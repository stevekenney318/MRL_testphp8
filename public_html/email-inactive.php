<?Php
include "config.php"; // Database connection using PDO
include "config_mrl.php"; // setup variables for current MRL season & segment


//
// list of active email addresses


$sql = "SELECT * FROM `users` WHERE `userID` > 0 AND `userActive` = 'N'";

echo "Inactive email adresses :<br><br>";
foreach ($dbo->query($sql) as $row) {
echo "$row[userEmail]  $row[userEmail2]  ";
}


?>