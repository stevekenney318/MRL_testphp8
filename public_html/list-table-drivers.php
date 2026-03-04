<?Php
include "config.php"; // Database connection using PDO
//
// 2017 A drivers

echo "Please select your drivers below.<br><br>";
echo '<form action="" method="POST">';
$sql="SELECT `driverName` FROM `A Drivers` WHERE `driverYear` = 2017 OR `driverYear` = 0 AND `driverGroup` = 'A' ";

 
echo "<select name=DriverA=''>DriverA</option>"; // list box select command

foreach ($dbo->query($sql) as $row){//Array or records stored in $row

echo "<option value=$row[driverID]>$row[driverName]</option>"; 

/* Option values are added by looping through the array */ 

}

 echo "</select>";// Closing of list box
 

 
 
 
// 
// 2017 B drivers
 
 

$sql="SELECT `driverName` FROM `B Drivers` WHERE `driverYear` = 2017 OR `driverYear` = 0 AND `driverGroup` = 'B' ";
 
echo "<select name=DriverB=''>DriverB</option>"; // list box select command

foreach ($dbo->query($sql) as $row){//Array or records stored in $row

echo "<option value=$row[driverID]>$row[driverName]</option>"; 

/* Option values are added by looping through the array */ 

}

 echo "</select>";// Closing of list box
 

 
 // 
// 2017 C drivers
 
 

$sql="SELECT `driverName` FROM `C Drivers` WHERE `driverYear` = 2017 OR `driverYear` = 0 AND `driverGroup` = 'C' ";

echo "<select name=DriverC=''>DriverC</option>"; // list box select command

foreach ($dbo->query($sql) as $row){//Array or records stored in $row

echo "<option value=$row[driverID]>$row[driverName]</option>"; 

/* Option values are added by looping through the array */ 

}

echo "</select>";// Closing of list box

 
 // 
// 2017 D drivers
 
 


$sql="SELECT `driverName` FROM `D Drivers` WHERE `driverYear` = 2017 OR `driverYear` = 0 AND `driverGroup` = 'D' ";
 
echo "<select name=DriverD=''>DriverD</option>"; // list box select command

foreach ($dbo->query($sql) as $row){//Array or records stored in $row

echo "<option value=$row[driverID]>$row[driverName]</option>"; 

/* Option values are added by looping through the array */ 

}

 echo "</select>";// Closing of list box

echo "<input type=submit>";
echo "</form>";

if(isset($_POST['submit'])) {
$DriverA = $_POST['DriverA'];
$DriverB = $_POST['DriverB'];
$DriverC = $_POST['DriverC'];
$DriverD = $_POST['DriverD'];
echo "info for $DriverA";
echo "info for $DriverB";
echo "info for $DriverC";
echo "info for $DriverD";
}


?>