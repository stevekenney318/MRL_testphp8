<?php

date_default_timezone_set("	America/New_York");
include "config.php"; // setup variables for database connection 
include "config_mrl.php"; // setup variables for current MRL season & segment

// set connection variable

$dbconnect = mysqli_connect($host_name,$username,$password,$database) or die("Connection Error: " . mysqli_error($dbconnect));


// this is only for testing ... this variable lives in config_mrl.php
// $raceYear = "2017"; // Current Year

// info for getting current year drivers 

$sql_A_drivers = "SELECT *  FROM `A Drivers` WHERE `driverYear` = '$raceYear'";
$sql_B_drivers = "SELECT *  FROM `B Drivers` WHERE `driverYear` = '$raceYear'";
$sql_C_drivers = "SELECT *  FROM `C Drivers` WHERE `driverYear` = '$raceYear'";
$sql_D_drivers = "SELECT *  FROM `D Drivers` WHERE `driverYear` = '$raceYear'";

$column = "driverName";

//  <option value="">  </option>

$result = mysqli_query($dbconnect, $sql_A_drivers);
?>


<select name="driver_A_pick"  style="width:24.5%;font-family:'Arial';color:#000000;background-color:#d9d9d9;font-size:14pt;">
<option value="">  </option>
<?php
$i=0;
while($row = mysqli_fetch_array($result)) {
?>
<option value="<?=$row[$column];?>"><?=$row[$column];?></option>
<?php
$i++;
}
?>
</select>


<?php

$result = mysqli_query($dbconnect, $sql_B_drivers);
?>
<select name="driver_B_pick" style="width:24.6%">
<option value="">  </option>
<?php
$i=0;
while($row = mysqli_fetch_array($result)) {
?>
<option value="<?=$row[$column];?>"><?=$row[$column];?></option>
<?php
$i++;
}
?>
</select>

<?php

$result = mysqli_query($dbconnect, $sql_C_drivers);
?>
<select name="driver_C_pick" style="width:24.6%">
<option value="">  </option>
<?php
$i=0;
while($row = mysqli_fetch_array($result)) {
?>
<option value="<?=$row[$column];?>"><?=$row[$column];?></option>
<?php
$i++;
}
?>
</select>

<?php

$result = mysqli_query($dbconnect, $sql_D_drivers);
?>
<select name="driver_D_pick" style="width:24.6%">
<option value="">  </option>
<?php
$i=0;
while($row = mysqli_fetch_array($result)) {
?>
<option value="<?=$row[$column];?>"><?=$row[$column];?></option>
<?php
$i++;
}
?>
</select>


<form>
<select name="menu" style="font-family:'Arial';color:#000000;background-color:#d9d9d9;font-size:14pt;">
<option value="http://www.msn.com/">MSN</option>
<option value="http://www.google.com/">Google</option>
</select>
<input type="button" onClick="location=this.form.menu.options[this.form.menu.selectedIndex].value;" value="GO" style="font-family:'Arial';color:#222222;background-color:#d9d9d9;font-size:14pt;">
</form>




<?php
mysqli_close($dbconnect);


/*

** info for posting data fields

$driverA = $_POST['group-a-driver'];
$driverB = $_POST['group-b-driver'];
$driverC = $_POST['group-c-driver'];
$driverD = $_POST['group-d-driver'];
$submission_id = $_POST['submission_id'];
$formID =$_POST['formID'];
$ip = $_POST['ip'];



*/
?>