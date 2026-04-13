<?php
/*
filename: prior_year_user_team_chart.php
2026-04-08 00:19:20 Minimal-change LP/RD render markers added to working baseline.
*/
session_start();

date_default_timezone_set("America/New_York");
include "config.php"; // setup variables for database connection
include "config_mrl.php"; // setup variables for the current MRL season & segment

// Get the user ID from the session array
// $uid = isset($_SESSION['userSession']) ? $_SESSION['userSession'] : null;

// Now $uid contains the value of userSession, or null if it doesn't exist
// echo "User ID: $uid";

date_default_timezone_set("America/New_York");
include "config.php"; // setup variables for database connection
// include "config_mrl.php"; // setup variables for current MRL season & segment


// include CSS Style Sheet
echo "<style type='text/css'>
      table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
        padding: 3px;
        font-size: 16px;
      }
   </style>";

//	Table Heading


echo "<table align=center style=width:80%>"; // start a table tag in the HTML
echo "<tr style=width:175px;background-color:#fabf8f>";
// echo "<th>$prevRaceYear</th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th></tr>";
echo "<th style=width:14%>$prevRaceYear</th><th style=width:18%>Group A</th><th style=width:18%>Group B</th><th style=width:18%>Group C</th><th style=width:18%>Group D</th><th style=width:14%>Submission Time</th></tr>";


if (!function_exists('pytu_display_driver')) {
    function pytu_display_driver(array $row, string $field): string {
        $driver = trim((string)($row[$field] ?? ''));
        if ($driver === '') {
            return '';
        }

        $pickType = strtoupper(trim((string)($row['pick_type'] ?? '')));
        if ($pickType === 'LP') {
            return $driver . ' (LP)';
        }

        if ($pickType === 'RD') {
            $refField = 'original_' . $field;
            $original = trim((string)($row[$refField] ?? ''));
            if ($original !== '' && strcasecmp($driver, $original) !== 0) {
                return $driver . ' (RD)';
            }
        }

        return $driver;
    }
}

//	Segment 1

$sql = "SELECT up.*, base.driverA AS original_driverA, base.driverB AS original_driverB, base.driverC AS original_driverC, base.driverD AS original_driverD
        FROM `user_picks` up
        LEFT JOIN `user_picks` base
          ON base.pickID = up.supersedes_pickID
        WHERE up.`userID` = $uid AND up.`raceYear` = '$prevRaceYear' AND up.`segment` = 'S1'
        ORDER BY up.`entryDate` ASC, up.`pickID` ASC";

foreach ($dbo->query($sql) as $row) {
echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #1' . "</td><td style=background-color:#d9d9d9>" . pytu_display_driver($row, 'driverA') . "</td><td style=background-color:#c4bd97>" . pytu_display_driver($row, 'driverB') . "</td><td style=background-color:#b8cce4>" . pytu_display_driver($row, 'driverC') . "</td><td style=background-color:#d8e4bc>" . pytu_display_driver($row, 'driverD') . "</td><td style=background-color:#b7dee8>" . $row['entryDate'] . "</td></tr>";
}


//	Segment 2

$sql = "SELECT up.*, base.driverA AS original_driverA, base.driverB AS original_driverB, base.driverC AS original_driverC, base.driverD AS original_driverD
        FROM `user_picks` up
        LEFT JOIN `user_picks` base
          ON base.pickID = up.supersedes_pickID
        WHERE up.`userID` = $uid AND up.`raceYear` = '$prevRaceYear' AND up.`segment` = 'S2'
        ORDER BY up.`entryDate` ASC, up.`pickID` ASC";

foreach ($dbo->query($sql) as $row) {
   echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #2' . "</td><td style=background-color:#d9d9d9>" . pytu_display_driver($row, 'driverA') . "</td><td style=background-color:#c4bd97>" . pytu_display_driver($row, 'driverB') . "</td><td style=background-color:#b8cce4>" . pytu_display_driver($row, 'driverC') . "</td><td style=background-color:#d8e4bc>" . pytu_display_driver($row, 'driverD') . "</td><td style=background-color:#b7dee8>" . $row['entryDate'] . "</td></tr>";
   }

//	Segment 3

$sql = "SELECT up.*, base.driverA AS original_driverA, base.driverB AS original_driverB, base.driverC AS original_driverC, base.driverD AS original_driverD
        FROM `user_picks` up
        LEFT JOIN `user_picks` base
          ON base.pickID = up.supersedes_pickID
        WHERE up.`userID` = $uid AND up.`raceYear` = '$prevRaceYear' AND up.`segment` = 'S3'
        ORDER BY up.`entryDate` ASC, up.`pickID` ASC";

foreach ($dbo->query($sql) as $row) {
   echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Segment #3' . "</td><td style=background-color:#d9d9d9>" . pytu_display_driver($row, 'driverA') . "</td><td style=background-color:#c4bd97>" . pytu_display_driver($row, 'driverB') . "</td><td style=background-color:#b8cce4>" . pytu_display_driver($row, 'driverC') . "</td><td style=background-color:#d8e4bc>" . pytu_display_driver($row, 'driverD') . "</td><td style=background-color:#b7dee8>" . $row['entryDate'] . "</td></tr>";
   }

//	Segment 4

$sql = "SELECT up.*, base.driverA AS original_driverA, base.driverB AS original_driverB, base.driverC AS original_driverC, base.driverD AS original_driverD
        FROM `user_picks` up
        LEFT JOIN `user_picks` base
          ON base.pickID = up.supersedes_pickID
        WHERE up.`userID` = $uid AND up.`raceYear` = '$prevRaceYear' AND up.`segment` = 'S4'
        ORDER BY up.`entryDate` ASC, up.`pickID` ASC";

foreach ($dbo->query($sql) as $row) {
   echo "<tr><td style=width:175px;background-color:#b7dee8>" . 'Playoffs' . "</td><td style=background-color:#d9d9d9>" . pytu_display_driver($row, 'driverA') . "</td><td style=background-color:#c4bd97>" . pytu_display_driver($row, 'driverB') . "</td><td style=background-color:#b8cce4>" . pytu_display_driver($row, 'driverC') . "</td><td style=background-color:#d8e4bc>" . pytu_display_driver($row, 'driverD') . "</td><td style=background-color:#b7dee8>" . $row['entryDate'] . "</td></tr>";
   }

echo "</table>"; //Close the table in HTML
echo "<div style='width:80%; margin:6px auto 0 auto; color:#777; font-size:13px; font-family:Arial, sans-serif; text-align:left;'>(LP) - Late Pick &nbsp;&nbsp; (RD) - Replacement Driver</div>";
?>
