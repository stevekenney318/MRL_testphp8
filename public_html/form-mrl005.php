<?php
session_start();

$formID = 'form-mrl005';
date_default_timezone_set("America/New_York");
include "config.php";
include "config_mrl.php";

$uid = isset($_SESSION['userSession']) ? $_SESSION['userSession'] : null;
// echo $uid;

$sqlDrivers = [
    'A' => "SELECT `driverName` FROM `A Drivers` WHERE `driverYear` = $raceYear AND `Available` = 'Y' AND `driverName` NOT IN (SELECT `driverA` FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$raceYear' AND `segment` != '$segment')",
    'B' => "SELECT `driverName` FROM `B Drivers` WHERE `driverYear` = $raceYear AND `Available` = 'Y' AND `driverName` NOT IN (SELECT `driverB` FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$raceYear' AND `segment` != '$segment')",
    'C' => "SELECT `driverName` FROM `C Drivers` WHERE `driverYear` = $raceYear AND `Available` = 'Y' AND `driverName` NOT IN (SELECT `driverC` FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$raceYear' AND `segment` != '$segment')",
    'D' => "SELECT `driverName` FROM `D Drivers` WHERE `driverYear` = $raceYear AND `Available` = 'Y' AND `driverName` NOT IN (SELECT `driverD` FROM `user_picks` WHERE `userID` = $uid AND `raceYear` = '$raceYear' AND `segment` != '$segment')"
];

$column = "driverName";

$result = mysqli_query($dbconnect, $sqlDrivers['A']);

?><!--END PHP-->

<form action="/submit.php" method="post">

<?php //--START PHP--//

// Table Heading
echo "<table align=center style=width:100%>";
echo "<tr style=background-color:#fabf8f><th>$formHeaderMessage</th></tr>";
echo "<tr style=background-color:#b7dee8><th>$formHeaderMessage2</th></tr>";
echo "</table>";

echo "<table align=center style=width:100%>";
echo "<tr style=background-color:#fabf8f><th style=width:14%>$raceYear</th><th style=width:18%>A Driver</th><th style=width:18%>B Driver</th><th style=width:18%>C Driver</th><th style=width:18%>D Driver</th><th style=width:14% id='clockCell'></th></tr>";

// Segment    
echo "<tr style=background-color:#b7dee8><th>$segmentName</th>";

// A drivers
$result = mysqli_query($dbconnect, $sqlDrivers['A']);
?><!--END PHP-->
<style>
    .driverA {
        width:100%;
        height:auto;
        border: 1px solid black !important; /* Override previous color settings */
        font-size: 18px;
        color: black !important; /* Override previous color settings */
        background-color: #d9d9d9;
        border-radius: 4px;
    }
</style>
<td style=background-color:#d9d9d9;width:18%;>
    <select class="driverA" name="group-a-driver" required>
        <option value=""></option>
        <?php //--START PHP--//
        $i = 0;
        while ($row = mysqli_fetch_array($result)) {
        ?><!--END PHP-->
        <option value="<?= $row[$column] ?>"><?= $row[$column] ?></option>
        <?php //--START PHP--//
        $i++;
        }
        ?><!--END PHP-->
    </select>
</td> 
<?php //--START PHP--//

// B drivers
$result = mysqli_query($dbconnect, $sqlDrivers['B']);
?><!--END PHP-->
<style>
    .driverB {
        width:100%;
        height:auto;
        border: 1px solid black !important; /* Override previous color settings */
        font-size: 18px;
        color: black !important; /* Override previous color settings */;
        background-color: #c4bd97;
        border-radius: 4px;
    }
</style>
<td style=background-color:#c4bd97;width:18%>
    <select class="driverB" name="group-b-driver" required>
        <option value=""></option>
        <?php //--START PHP--//
        $i = 0;
        while ($row = mysqli_fetch_array($result)) {
        ?><!--END PHP-->
        <option value="<?= $row[$column] ?>"><?= $row[$column] ?></option>
        <?php //--START PHP--//
        $i++;
        }
        ?><!--END PHP-->
    </select>
</td> 
<?php //--START PHP--//

// C drivers
$result = mysqli_query($dbconnect, $sqlDrivers['C']);
?><!--END PHP-->
<style>
    .driverC {
        width:100%;
        height:auto;
        border: 1px solid black !important; /* Override previous color settings */
        font-size: 18px;
        color: black !important; /* Override previous color settings */
        background-color: #b8cce4;
        border-radius: 4px;
    }
</style>
<td style=background-color:#b8cce4;width:18%>
    <select class="driverC" name="group-c-driver" style="width:100%" required>
        <option value=""></option>
        <?php //--START PHP--//
        $i = 0;
        while ($row = mysqli_fetch_array($result)) {
        ?><!--END PHP--><!--END PHP-->
        <option value="<?= $row[$column] ?>"><?= $row[$column] ?></option>
        <?php //--START PHP--//
        $i++;
        }
        ?>
    </select>
</td> 

<?php //--START PHP--//

// D drivers
$result = mysqli_query($dbconnect, $sqlDrivers['D']);
?><!--END PHP-->
<style>
    .driverD {
        width:100%;
        height:auto;
        border: 1px solid black !important; /* Override previous color settings */
        font-size: 18px;
        color: black !important; /* Override previous color settings */
        background-color: #d8e4bc;
        border-radius: 4px; 
    }
</style>
<td style=background-color:#d8e4bc;width:18%>
    <select class="driverD" name="group-d-driver" style="width:100%" required>
        <option value=""></option>
        <?php //--START PHP--//
        $i = 0;
        while ($row = mysqli_fetch_array($result)) {
        ?><!--END PHP-->
        <option value="<?= $row[$column] ?>"><?= $row[$column] ?></option>
        <?php //--START PHP--//
        $i++;
        }
        ?><!--END PHP-->
    </select>
</td>

<?php //--START PHP--//

// Reset & Submit buttons
?><!--END PHP-->
<td style=text-align:center;background-color:#b7dee8;width:14%>
    <input type="reset">
    <input type="submit" value="Submit Picks">
</td>

</table>

</form>

<script>
    function updateClock() {
        var now = new Date();
        var year = now.getFullYear();
        var month = (now.getMonth() + 1).toString().padStart(2, '0');
        var day = now.getDate().toString().padStart(2, '0');
        var hours = now.getHours().toString().padStart(2, '0');
        var minutes = now.getMinutes().toString().padStart(2, '0');
        var seconds = now.getSeconds().toString().padStart(2, '0');

        var formattedTime = year + '-' + month + '-' + day + ' ' + hours + ':' + minutes + ':' + seconds;

        // Update the content of the cell
        document.getElementById('clockCell').innerText = formattedTime;
    }

    // Update the clock every second
    setInterval(updateClock, 1000);

    // Call the function initially to set the initial value
    updateClock();
</script>

<?php //--START PHP--//

mysqli_close($dbconnect);

?><!--END PHP-->

