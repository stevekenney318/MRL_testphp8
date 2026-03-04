<?Php
session_start();
include "config.php"; // Database connection info
include "config_mrl.php"; // setup variables for current MRL season & segment
$currentTimeIs = date("n/j/Y g:i a"); //get date in format '8/25/2020 12:20 am'

//
// list of Teams submitted for current year & current segment
// AND `userID` != '0' 

$sql_submitted = "SELECT `teamName` FROM `user_picks` WHERE `raceYear` = '$raceYear' AND `userID` != '0' AND `segment` = '$segment' ORDER BY `userID` ASC";

//$dbconnect = mysqli_connect($host_name,$username,$password,$database); 
$sqlsearch = mysqli_query($dbconnect, $sql_submitted);
$resultcount=mysqli_num_rows($sqlsearch);

?>



<div class=WordSection1>
<p class=MsoNormal ><span style='font-size:16.0pt;
line-height:100%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>
<?php 

// Count of Teams submitted for current year & current segment
echo "As of $currentTimeIs, $resultcount teams have submitted their picks for $raceYear $segmentName ";
?>

<br>
<br>

<!-- 
    Click <a href="submitted_teams.php" target="_blank" rel="noopener noreferrer">here</a> to see the submission status of all teams. <a href="submitted_teams.php" target="_blank" rel="noopener noreferrer"></a><br><br>***** The <?php echo "$raceYear $segmentName" ?> team chart (with drivers) will appear here at <?php echo "$formLockDate" ?> (refresh browser if necessary) *****
-->

Click <a href="team_chart.php">here</a> to see the submission status of all teams. <br><br>***** The <?php echo "$raceYear $segmentName" ?> team chart (with drivers) will appear here at <?php echo "$formLockDate" ?> (refresh browser if necessary) *****


<?Php

// list of Teams not yet submitted for current year & current segment
/* $notSubmitted = "SELECT `teamName` FROM `user_picks` WHERE `raceYear` = '$raceYear' AND `segment` = '$prevSegment' AND `teamName` NOT IN ( SELECT `teamName` FROM `user_picks` WHERE `raceYear` = '$raceYear' AND `segment` = '$segment' )";

echo "<br><br><br>Missing picks from the following teams for $raceYear $segment:<br><br>";
foreach ($dbo->query($notSubmitted) as $row) {
echo "$row[teamName]<br>";

*/



?>
<br>
</p>
</div>

<?php

?>