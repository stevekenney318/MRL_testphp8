<?php
session_start();

$formID = 'form-mrl003'; // this is form-mrl003 - team selection 1st used 2020 S4
//echo $formID;

date_default_timezone_set("America/New_York");
include "config.php"; // setup variables for database connection 
include "config_mrl.php"; // setup variables for the current MRL season & segment

// Get the user ID from the session array
$uid = isset($_SESSION['userSession']) ? $_SESSION['userSession'] : null;

// Now $uid contains the value of userSession, or null if it doesn't exist
// echo "User ID: $uid";

date_default_timezone_set("America/New_York");
include "config.php"; // setup variables for database connection 
include "config_mrl.php"; // setup variables for current MRL season & segment

// info for getting current year drivers AVAILABLE to user to select
// works for INSERT and UPDATE

$sql_A_drivers = "SELECT `driverName` \n"
    . "FROM `A Drivers` \n"
    . "WHERE `driverYear` = $raceYear \n"
    . "AND `Available` = 'Y' \n"
    . "   AND `driverName` NOT IN (SELECT `driverA` FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$raceYear' AND `segment` != '$segment')";
     //"   AND `driverName` NOT IN (SELECT `driverR` FROM `user_picks` WHERE `userID` = $DBuserID AND `raceYear` = '$raceYear' AND `segment` != '$segment')";

$sql_B_drivers = "SELECT `driverName` \n"
    . "FROM `B Drivers` \n"
    . "WHERE `driverYear` = $raceYear \n"
    . "AND `Available` = 'Y' \n" 
    . "   AND `driverName` NOT IN (SELECT `driverB` FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$raceYear' AND `segment` != '$segment')";

$sql_C_drivers = "SELECT `driverName` \n"
    . "FROM `C Drivers` \n"
    . "WHERE `driverYear` = $raceYear \n"
    . "AND `Available` = 'Y' \n" 
    . "   AND `driverName` NOT IN (SELECT `driverC` FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$raceYear' AND `segment` != '$segment')";    

$sql_D_drivers = "SELECT `driverName` \n"
    . "FROM `D Drivers` \n"
    . "WHERE `driverYear` = $raceYear \n"
    . "AND `Available` = 'Y' \n" 
    . "   AND `driverName` NOT IN (SELECT `driverD` FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$raceYear' AND `segment` != '$segment')";



$column = "driverName";


$result = mysqli_query($dbconnect, $sql_A_drivers);







?><!--END PHP-->

<form action="/submit.php" method="post">

<?php //--START PHP--//


//	Table Heading

echo "<table align=center style=width:100%>"; // start a table tag in the HTML
echo "<style='font-size:22px; height:auto;>";
echo "<tr style=background-color:#fabf8f>";
echo "<th>$formHeaderMessage</th></tr>";
echo "<tr style=background-color:#b7dee8>";
echo "<th>$formHeaderMessage2</th></tr>";


echo "</table>"; //Close the table in HTML

    echo "<table align=center style=width:100%>"; // start a table tag in the HTML
    echo "<tr style=background-color:#fabf8f>";
    echo "<th style=width:14%>$raceYear</th><th style=width:18%>A Driver</th><th style=width:18%>B Driver</th><th style=width:18%>C Driver</th><th style=width:18%>D Driver</th><th style=width:14%>$nowTime</th></tr>";
    


// Segment    
echo "<tr style=background-color:#b7dee8>";
echo "<th>$segmentName</th>";
 

// A drivers
$result = mysqli_query($dbconnect, $sql_A_drivers);
?><!--END PHP-->
<style>
      .driverA {
        width:100%;
        height:auto;
        border: 1px solid #000000;
        font-size: 18px;
        color: #000000;
        background-color: #d9d9d9;
        border-radius: 4px;
        
      }
    </style>
<td style=background-color:#d9d9d9;width:18%;>
<select class="driverA"; name="group-a-driver"; required>
<option value=""></option>
<?php //--START PHP--//
$i=0;
while($row = mysqli_fetch_array($result)) {
?><!--END PHP-->
<option value="<?=$row[$column];?>"><?=$row[$column];?></option>
<?php //--START PHP--//
$i++;
}
?><!--END PHP-->
</select>
</td> 
<?php //--START PHP--//



// B drivers
$result = mysqli_query($dbconnect, $sql_B_drivers);
?><!--END PHP-->
<style>
      .driverB {
        width:100%;
        height: 30px;
        border: 1px solid #000000;
        font-size: 18px;
        color: #000000;
        background-color: #c4bd97;
        border-radius: 4px;
        
      }
    </style>
<td style=background-color:#c4bd97;width:18%>
<select class="driverB"; name="group-b-driver"; required>
<option value=""></option>
<?php //--START PHP--//
$i=0;
while($row = mysqli_fetch_array($result)) {
?><!--END PHP-->
<option value="<?=$row[$column];?>"><?=$row[$column];?></option>
<?php //--START PHP--//
$i++;
}
?><!--END PHP-->
</select>
</td> 
<?php //--START PHP--//

// C drivers

$result = mysqli_query($dbconnect, $sql_C_drivers);
?><!--END PHP-->
<style>
      .driverC {
        width:100%;
        height: 30px;
        border: 1px solid #000000;
        font-size: 18px;
        color: #000000;
        background-color: #b8cce4;
        border-radius: 4px;
        
      }
    </style>
<td style=background-color:#b8cce4;width:18%>
<select class="driverC"; name="group-c-driver"style="width:100%" required>
<option value=""></option>
<?php //--START PHP--//
$i=0;
while($row = mysqli_fetch_array($result)) {
?><!--END PHP-->
<option value="<?=$row[$column];?>"><?=$row[$column];?></option>
<?php //--START PHP--//
$i++;
}
?><!--END PHP-->
</select>
</td> 



<?php //--START PHP--//

// D drivers

$result = mysqli_query($dbconnect, $sql_D_drivers);
?><!--END PHP-->
<style>
      .driverD {
        width:100%;
        height: 30px;
        border: 1px solid #000000;
        font-size: 18px;
        color: #000000;
        background-color: #d8e4bc;
        border-radius: 4px; 
      }
    </style>
<td style=background-color:#d8e4bc;width:18%>
<select class="driverD";  name="group-d-driver"style="width:100%" required>
<option value=""></option>
<?php //--START PHP--//
$i=0;
while($row = mysqli_fetch_array($result)) {
?><!--END PHP-->
<option value="<?=$row[$column];?>"><?=$row[$column];?></option>
<?php //--START PHP--//
$i++;
}
?><!--END PHP-->
</select>
</td>



<?php //--START PHP--//

// reset & submit buttons

// search for #b94a48 to find dropdown text color

?><!--END PHP-->
<td style=text-align:center;background-color:#b7dee8;width:14%>
<input type="reset">
<input type="submit" value="Submit Picks">


</td>



</table>



</form>


<?php //--START PHP--//



mysqli_close($dbconnect);




?><!--END PHP-->