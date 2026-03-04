<?php
session_start();

// Store the current page URL in the session
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once 'class.user.php';
$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
}

date_default_timezone_set('America/New_York');
require "config.php";
require "config_mrl.php";
$currentTimeIs = date("n/j/Y g:i a");

$stmt = $user_home->runQuery("SELECT * FROM users WHERE userID=:uid");
$stmt->execute(array(":uid" => $_SESSION['userSession']));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$name_parts = explode(' ', $row['userName']);
$first_name = $name_parts[0];

// Check if the user is an admin - used when offline
$isAdmin = isAdmin($_SESSION['userSession']);

$uid = (int)$_SESSION['userSession'];

// ---------------------------------------------------------
// TEAM NAME MODULE (standalone include)
// ---------------------------------------------------------
require_once 'team_name.php';

// Handle AJAX availability check (exits immediately when called)
if (isset($dbconnect)) {
    mrl_teamname_handle_ajax($dbconnect);
}

// Handle save (POST -> redirect -> GET) and get message if any
$teamNameMessage = '';
if (isset($dbconnect)) {
    $teamNameMessage = mrl_teamname_handle_save($dbconnect, (string)$raceYear, $uid);
}

// used for team page maintenance mode
// Display admin status

// if ($isAdmin) {
//     echo '<div style="color: red; text-align: center; font-size:18.0pt;">Team page is currently in maintenance mode</div>'; // current status note
//     echo "<br>"; // line break
//     echo '<div style="color: red; text-align: center; font-size:20.0pt;">-- ONLY AVAILABLE TO ADMIN USERS --</div>'; // additional message for admins
//     echo "<br>"; // line break
//     echo "<script>console.log('Validated admin');</script>";
// } else {
//     echo "<br>"; // line break
//     echo "<script>console.log('Validated non-admin');</script>";
//     include 'maintenance.php'; // currently in maintenance mode for non-admins
//     die(); // STOP for non-admins
// }

?>

<!DOCTYPE html>
<html class="no-js">

<head>
    <title><?php echo $first_name; ?>'s Team Page </title>
    <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
    <link href="bootstrap/css/bootstrap-responsive.min.css" rel="stylesheet" media="screen">
    <link href="assets/styles.css" rel="stylesheet" media="screen">
    <style>
        body {
            background-color: transparent;
            background-color: #222222;
            padding-top: 60px;
        }
    </style>
</head>

<body>

    <div class="navbar navbar-fixed-top">
        <div class="navbar-inner">
            <div class="container-fluid">
                <a class="btn btn-navbar" data-toggle="collapse" data-target=".nav-collapse">
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </a>
                <ul class="nav pull-left">
                    <li class="dropdown">
                        <a href="#" role="button" class="dropdown-toggle" data-toggle="dropdown">
                            <i class="icon-user"></i>
                            <?php echo $first_name; ?> <i class="caret"></i>
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a tabindex="-1" href="<?php echo $mrl; ?>">MRL Home</a>
                                <a tabindex="-2" href="<?php echo $mrl; ?>profile.php">Profile Page</a>
                                <a tabindex="-3" href="<?php echo $mrl; ?>logout.php">Logout</a>
                            </li>
                        </ul>
                    </li>
                </ul>
                <a class="brand">
                    <ol align='center'><?php echo $sitename; ?> - My Team Page
                </a>
                <iframe src="https://freesecure.timeanddate.com/clock/i7eqrnfz/n777/fn16/fs18/bas/bat0/pd2/tt0/tw1/tm2" frameborder="1px" width="330" height="28"></iframe>
            </div>
        </div>
    </div>

    <div style="width:80%; margin:0 auto; text-align: left;">
        <div style="color: #dfcca8; font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif;">
            Hi <?php echo $first_name; ?> ... <br>

            <?php
            $userID = $_SESSION['userSession'];
            if (isAdmin($userID)) {
                echo "<br>";
                echo "*********************** Admin Menu ****************************";
                echo "<br>";
                echo "*******************************************************************";
                echo "<br>";
                echo "<a href='https://manliusracingleague.com/admin_setup.php' target='_blank'>- Setup Year/Segment & Submission Date</a>";
                echo "<br>";
                echo "<a href='https://manliusracingleague.com/Paid_Status_Year.php' target='_blank'>- See Paid Status for selectable year</a>";
                echo "<br>";
                echo "<a href='https://manliusracingleague.com/team_view_as.php' target='_blank'>- View Team page as alternate user</a>";
                echo "<br>";
                echo "<a href='https://manliusracingleague.com/email.php' target='_blank'>- List all  email addresses - active & inactive</a>";
                echo "<br>";
                echo "<a href='https://manliusracingleague.com/change_user_auth.php' target='_blank'>- Toggle user status to make late picks or change driver</a>";
                echo "<br>";
                echo "<a href='https://manliusracingleague.com/addDrivers.php' target='_blank'>- Add drivers for a new year.</a>";
                echo "<br>";
                echo "<a href='https://manliusracingleague.com/current_segment_chart_by_entry_time.php' target='_blank'>- Show current segment team chart sorted by Entry Time.</a>";
                echo "<br>";
                echo "*******************************************************************";
                echo "<br>";
                echo "<a href='https://auth-db1928.hstgr.io/index.php?db=u809830586_MRL_DB' target='_blank'>- phpMyAdmin (Hostinger)</a>";
                echo "<br>";
                echo "<a href='https://manliusracingleague.com/wp-admin/?platform=hpanel&client_id=1017612160' target='_blank'>- WP Admin (Hostinger)</a>";
                echo "<br>";
                echo "<a href='https://hpanel.hostinger.com/websites/manliusracingleague.com/files/backups' target='_blank'>- Backups (Hostinger)</a>";
                echo "<br>";
                echo "<a href='https://hpanel.hostinger.com/websites/manliusracingleague.com' target='_blank'>- hPanel (Hostinger)</a>";
                echo "<br>";
                echo "*******************************************************************";
                echo "<br>";
            } else {
                echo "";
            }
            ?>
            <br>
            Welcome to your team page.<br>
            <br>
            <a style="color:red;">Update 2025-12-11 23:18:31 - See note below regarding previous years picks</a><br>
            <!-- <br>
            Below, you will find links for this year's season, payment status, your current team chart, the latest submission form, or the current segment team chart, and then any previous years played.
            <br> -->
            <br>
            <br>
            <u style="color:red;">League Info as of 2026-02-03 11:09:24</u><br><br>
            2026 Fees & Payment info is <a href="https://manliusracingleague.com/2026_Fees.php" target="_blank" rel="noopener noreferrer">here </a><br>
            2026 Rules are <a href="https://manliusracingleague.com/2026_Rules.php" target="_blank" rel="noopener noreferrer">here </a><br>
            2026 Race Schedule - PDF (on MRL) is <a href="https://manliusracingleague.com/wp-content/uploads/2026/01/2026_Schedule_MRL.pdf" target="_blank" rel="noopener noreferrer">here </a><br>
            2026 Race Schedule - Spreadsheet (on MRL) is <a href="https://manliusracingleague.com/wp-content/uploads/2026/01/2026_Schedule_MRL.xlsx" target="_blank" rel="noopener noreferrer">here </a><br>
            2026 Race Schedule (on NASCAR) is <a href="https://www.nascar.com/nascar-cup-series/2026/schedule/" target="_blank" rel="noopener noreferrer">here </a><br>
            <br>
            

            ************************ Team Menu ******************************
            *******************************************************************
            <br>
            <a href="https://manliusracingleague.com/showDrivers.php" target="_blank" rel="noopener noreferrer">- Driver Chart(s) - view, print for any year. </a><br>
            <a href="https://manliusracingleague.com/team_chart.php" target="_blank" rel="noopener noreferrer">- Team Chart(s) - view, pdf , spreadsheet for any year/segment. </a><br>
            <a href="https://manliusracingleague.com/submitted_teams.php" target="_blank" rel="noopener noreferrer">- Submitted Teams for Current Segment </a><br>
            <a href="https://manliusracingleague.com/profile.php" target="_blank" rel="noopener noreferrer">- Your Profile page (change your email addresses, etc) </a> - Or use dropdown menu - upper left at your name.<br>
            <br>
            *******************************************************************
            <br>
        </div>
    </div>

    <a name="current_user_team_chart"></a>
    <!-- <?php include 'showCurrentDrivers.php'; ?> -->
    <?php include 'current_user_team_chart.php'; ?>

    <?php
    $stmt = $user_home->runQuery("SELECT * FROM users WHERE userID=:uid AND changeAuth=:changeAuth");
    $stmt->execute(array(":uid" => $_SESSION['userSession'], ":changeAuth" => "Y"));
    $count = $stmt->rowCount();
    if ($count == 1) {
        include 'team-late-pick.php';
    }
    ?>
    <div style="width:80%; margin:0 auto; text-align: left;">
        <div style="color: #dfcca8; font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif;">
            <br>
            <?php
            $end_ts = strtotime($formLockDate);
            $user_ts = strtotime($currentTimeIs);

            if ($formLocked == 'no') {
                if ($end_ts > $user_ts) {

                    // NEW: check for team name for current season
                    $teamName = '';

                    if (isset($dbconnect)) {
                        $teamCheck = mysqli_query(
                            $dbconnect,
                            "SELECT teamName
                             FROM user_teams
                             WHERE userID = $uid
                               AND raceYear = $raceYear
                             LIMIT 1"
                        );
                        if ($teamCheck) {
                            $teamRow = mysqli_fetch_assoc($teamCheck);
                            $teamName = trim($teamRow['teamName'] ?? '');
                        }
                    }

                    if ($teamName === '') {

                        // Render the team name form (from team_name.php)
                        if (!isset($dbconnect)) {
                            echo "<div style='color:red; font-weight:bold; font-size:14pt; text-align:center;'>Database connection not available.</div>";
                        } else {
                            mrl_teamname_render_form($dbconnect, (string)$raceYear, $uid, (string)$teamNameMessage);
                        }

                    } else {
                        include $currentForm;
                        include 'submitted_teams_count.php';
                    }

                } else {
                    // echo "$formLockedMessage - past Lock date of $formLockDate for $raceYear $segmentName -";
                    echo "$formLockedMessage - past Lock date of $formLockDate";
                    include 'current_segment_chart.php';
                }
            } else {
                echo "$formLockedMessage";
            }
            ?>
            <br>
            <br>

            <p style='font-size:18.0pt;line-height:120%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>
                <span style="font-size:20.0pt; text-decoration:underline; display:inline;">Previous Years Picks</span>
                <br><br>
                <a style="color:red;"></a>FYI: Great news — With the help of my friend Chad from ChatGPT, the user picks data has now been fully restored. As of 2025-12-11 23:18:31, all data is now being pulled from the final team picks table instead of the historical backup table. You should not see any gaps in your previous years picks. Please let us know if you see anything that doesn't look right to you. Thanks for your patience through all of this.<br>
            </p>
        </div>
    </div>
    <br>

    <?php
    $sql = "SELECT * FROM `years` WHERE `year` < '$raceYear' AND `year` > '0' ORDER BY `years`.`year` DESC";
    foreach ($dbo->query($sql) as $row) {
        $prevRaceYear = $row[year];
        include 'prior_year_user_team_chart.php'; // this is the original line
        // include 'prior_year_user_team_chart_history.php';  // this is the temporary fix to show the history picks
    }
    ?>

    <br>

    <div style="width: 80%; margin: 0 auto; border: none; text-align: left;">
        <p style='font-size: 12.0pt; line-height: 120%; font-family: "Century Gothic", sans-serif; color: #dfcca8;'>
            Copyright &copy; 2017-<script>
                document.write(new Date().getFullYear())
            </script> Manlius Racing League
        </p>
    </div>

    <script src="bootstrap/js/jquery-1.9.1.min.js"></script>
    <script src="bootstrap/js/bootstrap.min.js"></script>
    <script src="assets/scripts.js"></script>
</body>

</html>
