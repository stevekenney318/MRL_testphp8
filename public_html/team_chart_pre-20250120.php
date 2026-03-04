<?php
session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Team Chart</title>
        <link rel="stylesheet" href="/mrl-styles.css">
    </head>
    <body><?php echo $adminStatusLine; ?></body>
    </html>
    <?php

// set variables equal to current year & segment and lock time to decide whether
// to show chart or not
// $CurrentRaceYear = $raceYear;
// $CurrentSegment = $segment;
// echo $CurrentRaceYear;
// echo '<br />';
// echo $CurrentSegment;
// echo '<br />';
/*
// MRL Submission form if not locked

  // Convert to timestamp
  $end_ts = strtotime($formLockDate);  // $formLockDate is set in config_mrl.php
  $user_ts = strtotime($currentTimeIs); 
  
 
if ($formLocked == no) {
    if ($end_ts > $user_ts) {
        include $currentForm;
        include 'submitted_teams_count.php';
    } else {
        echo "$formLockedMessage - past Lock date of $formLockDate for $raceYear $segmentName -";
        include 'current_segment_chart.php'; 
      }
     
} else {

    echo "$formLockedMessage - offline/maintenance ";
}

*/


// clear required variables
unset($raceYear); unset($segment); unset($segmentName);

// these 2 lines will generate excel spreadsheet - it's hit and miss, needs work.
//header("Content-type: application/vnd.ms-excel");
//header("Content-Disposition: attachment; filename=spreadsheet.xls");

?><!--END PHP-->

<form method="post">

<!-- this is the start of years dropdown -->
<style>
      .year {
        width: 120px;
        height: 30px;
        border: 1px solid #999;
        font-size: 18px;
        color: rgb(0, 0, 0);
        background-color: #eee;
        border-radius: 4px;
        box-shadow: 4px 4px #ccc;
      }
    </style> 

<label for="year">Choose year:   </label>
<select class="year"; name="year"; required>
<option value=""></option>
<?php //--START PHP--//
$sql_years = "SELECT year  FROM `years` WHERE `year` > '0'";
$result = mysqli_query($dbconnect, $sql_years);
$column = "year";
$i=0;
while($row = mysqli_fetch_array($result)) {
?><!--END PHP-->
<option value="<?=$row[$column];?>"><?=$row[$column];?></option>
<?php //--START PHP--//
$i++;
}

?><!--END PHP-->
</select>


<style>
      .segment {
        width: 120px;
        height: 30px;

        font-size: 18px;
        color: #000000;
        background-color: #eee;
        border-radius: 4px;
        box-shadow: 4px 4px #ccc;
      }
    </style>

<label for="segment">Choose segment:</label>

<select class="segment"; name="segment"; required>
<option value=""></option>
<?php //--START PHP--//
$sql_segments = "SELECT `segment`  FROM `segments`";
$result = mysqli_query($dbconnect, $sql_segments);
$column = "segment";
$i=0;
while($row = mysqli_fetch_array($result)) {
?><!--END PHP-->
<option value="<?=$row[$column];?>"><?=$row[$column];?></option>
<?php //--START PHP--//
$i++;
}
?><!--END PHP-->
</select>
  <input type="submit">
  </form>
<?Php

// Retrieve variables from form

$raceYear = $_POST['year'];
$segment = $_POST['segment'];

// set segmentName based on segment
if ($segment == 'S1') {
    $segmentName = 'Segment #1';
}
if ($segment == 'S2') {
    $segmentName = 'Segment #2';
}
if ($segment == 'S3') {
    $segmentName = 'Segment #3';
}
if ($segment == 'S4') {
    $segmentName = 'Playoffs';
}




//
// Selected Segment Team Chart

$sql = "SELECT * FROM `picks` WHERE `raceYear` = '$raceYear' AND `segment` = '$segment' AND `userName` != 'MRL' ORDER BY `userID` ASC";
;



{
	# code...
}
   //include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
    border-collapse: collapse;
    border: 1px solid black;
    color: #000;
    padding: 3px;
}
   </style>";
   
echo "<table align=center>"; // start a table tag in the HTML


echo "<tr style=background-color:#fabf8f>";
echo "<th colspan=7> $raceYear $segmentName Team Chart</th>";
echo "<tr style='background-color:#fabf8f; color:#222222;'>";
echo "<th>Team</th><th>Owner</th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th><th>Submission Time</th></tr>";
foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=background-color:#b7dee8>" . $row[teamName] . "</td><td style=background-color:#b7dee8>" . $row[userName] . "</td><td style=background-color:#d9d9d9>" . $row[driverA] . "</td><td style=background-color:#c4bd97>" . $row[driverB] . "</td><td style=background-color:#b8cce4>" . $row[driverC] . "</td><td style=background-color:#d8e4bc>" . $row[driverD] . "</td><td style=background-color:#b7dee8>" . $row[entryDate] . "</td></tr>";  
}

echo "</table>"; //Close the table in HTML

//header("refresh:0; url=team.php");
?>