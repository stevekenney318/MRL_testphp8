<?Php
date_default_timezone_set("	America/New_York");
include "config.php"; // setup variables for database connection 
include "config_mrl.php"; // setup variables for current MRL season & segment


// find unique userID

session_start();
  
    foreach ($_SESSION as $key=>$val);

   //include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
    border: 1px solid black;
    border-collapse: collapse;
    padding: 3px;
    font-size: 16px;
}
   </style>";





//	get prior years 

$sql = "SELECT * FROM `years` WHERE `year` < '2023' AND `year` > '0' ORDER BY `years`.`year` DESC";

foreach ($dbo->query($sql) as $row) {

$raceYear=$row[year];  // set year for chart
include 'prior_year_user_team_chart.php';
}

?>
