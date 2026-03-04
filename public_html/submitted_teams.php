<?php
session_start();
include "config.php"; // Database connection info
include "config_mrl.php"; // setup variables for current MRL season & segment
$currentTimeIs = date("n/j/Y g:i a"); //get date in format '8/25/2020 12:20 am';
?>

<title><?php echo 'Submitted Teams'; ?></title>
<style>
    body {
        font-size: 14pt;
        line-height: 140%;
        font-family: 'Century Gothic', sans-serif;
        color: #dfcca8;
        background-color: #222222;
        background:#222222;
        padding-top: 0px;
        padding-bottom: 20px;
        padding-left: 20px;
    }
</style>

<?php
//
// list of Teams submitted for current year & current segment
// AND `userID` != '0' 

$sql_submitted = "SELECT * FROM `user_picks` WHERE `raceYear` = '$raceYear' AND `userID` != '0' AND `segment` = '$segment' ORDER BY `entryDate` ASC";

echo "Teams submitted for $raceYear $segment :<br><br>";
$result_submitted = mysqli_query($dbconnect, $sql_submitted);
while ($row = mysqli_fetch_assoc($result_submitted)) {
    echo "{$row['entryDate']} : {$row['teamName']}<br>";
}

// Count of Teams submitted for current year & current segment
echo "<br>As of $currentTimeIs, " . mysqli_num_rows($result_submitted) . " teams have submitted their picks for $raceYear $segmentName ";

// list of Teams not yet submitted for current year & current segment
if ($segment != 'S1') {
    echo "<br><br><br>Missing picks from the following teams for $raceYear $segment:<br><br>";
    $notSubmitted = "SELECT `teamName` FROM `user_picks` WHERE `raceYear` = '$raceYear' AND `segment` = '$compareSegment' AND `userID` != '0' AND `teamName` NOT IN ( SELECT `teamName` FROM `user_picks` WHERE `raceYear` = '$raceYear' AND `segment` = '$segment' )";
    $result_notSubmitted = mysqli_query($dbconnect, $notSubmitted);
    while ($row = mysqli_fetch_assoc($result_notSubmitted)) {
        echo "{$row['teamName']}<br>";
    }
}
?>