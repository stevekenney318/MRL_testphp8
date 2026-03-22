<?php
/**
 * team-test.php
 *
 * VERSION: v1.1.0
 * LAST MODIFIED: 2026-03-18
 * BUILD TS: 20260318_152000000
 *
 * CHANGELOG:
 * v1.1.0 (2026-03-18)
 *   - Restored full-width layout (no max-width constraint)
 *   - Restored original font size (16pt) in content areas
 *   - Tables inside included files keep their own original colors untouched
 *   - Cards used only for non-table sections (league info, team menu, admin)
 *   - Yearly config block for easy seasonal updates
 *   - Clean admin menu HTML replacing echo string approach
 *   - PHP 8: bare word array key $row[year] -> $row['year']
 *   - PHP 8: footer uses PHP date() instead of JS document.write()
 *
 * v1.0.0 (2026-03-18)
 *   - Initial modernized version
 */

session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once 'class.user.php';
$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
}

date_default_timezone_set('America/New_York');
require 'config.php';
require 'config_mrl.php';
$currentTimeIs = date('n/j/Y g:i a');

$stmt = $user_home->runQuery('SELECT * FROM users WHERE userID=:uid');
$stmt->execute([':uid' => $_SESSION['userSession']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$name_parts = explode(' ', $row['userName']);
$first_name  = $name_parts[0];

$isAdmin = isAdmin($_SESSION['userSession']);
$uid     = (int)$_SESSION['userSession'];

// ---------------------------------------------------------
// TEAM NAME MODULE
// ---------------------------------------------------------
require_once 'team_name.php';

if (isset($dbconnect)) {
    mrl_teamname_handle_ajax($dbconnect);
}

$teamNameMessage = '';
if (isset($dbconnect)) {
    $teamNameMessage = mrl_teamname_handle_save($dbconnect, (string)$raceYear, $uid);
}

// =========================================================
// YEARLY CONFIG — Edit this section each season
// =========================================================
$season_year  = '2026';
$info_updated = '2026-02-03';

$league_links = [
    ['label' => $season_year . ' Fees & Payment info',         'url' => 'https://manliusracingleague.com/2026_Fees.php'],
    ['label' => $season_year . ' Rules',                       'url' => 'https://manliusracingleague.com/2026_Rules.php'],
    ['label' => $season_year . ' Race Schedule — PDF',         'url' => 'https://manliusracingleague.com/wp-content/uploads/2026/01/2026_Schedule_MRL.pdf'],
    ['label' => $season_year . ' Race Schedule — Spreadsheet', 'url' => 'https://manliusracingleague.com/wp-content/uploads/2026/01/2026_Schedule_MRL.xlsx'],
    ['label' => $season_year . ' Race Schedule on NASCAR.com', 'url' => 'https://www.nascar.com/nascar-cup-series/2026/schedule/'],
];

$notice_message = ''; // Set a red notice banner here, or leave empty
// =========================================================
// END YEARLY CONFIG
// =========================================================

?>
<!DOCTYPE html>
<html class="no-js" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($first_name); ?>'s Team Page</title>

    <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
    <link href="bootstrap/css/bootstrap-responsive.min.css" rel="stylesheet" media="screen">
    <link href="assets/styles.css" rel="stylesheet" media="screen">

    <style>
        /* ── Base ─────────────────────────────────────────── */
        :root {
            --bg:          #222222;
            --bg-card:     #2a2a2a;
            --border:      #3a3a3a;
            --gold:        #dfcca8;
            --gold-bright: #f0ddb8;
            --red-alert:   #e05555;
            --accent:      #c8a96e;
            --text-muted:  #999;
            --font-main:   'Century Gothic', 'Trebuchet MS', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            background-color: var(--bg);
            color: var(--gold);
            font-family: var(--font-main);
            font-size: 16pt;
            line-height: 120%;
            padding-top: 60px;
            margin: 0;
        }

        a { color: var(--accent); text-decoration: none; }
        a:hover { color: var(--gold-bright); text-decoration: underline; }

        /* ── Navbar ───────────────────────────────────────── */
        .navbar { background: #111; border-bottom: 1px solid var(--border); }
        .navbar .brand { color: var(--gold) !important; }
        .navbar .nav > li > a { color: var(--gold) !important; }
        .navbar .dropdown-menu { background: #1e1e1e; border: 1px solid var(--border); }
        .navbar .dropdown-menu li a { color: var(--gold) !important; }
        .navbar .dropdown-menu li a:hover { background: #333; }

        /* ── Full width page wrapper ──────────────────────── */
        .page-wrap {
            width: 100%;
            padding: 18px 20px 40px;
        }

        /* ── Cards (non-table sections only) ──────────────── */
        .mrl-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 16px 20px;
            margin-bottom: 16px;
        }

        .mrl-card-title {
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--accent);
            border-bottom: 1px solid var(--border);
            padding-bottom: 8px;
            margin: 0 0 12px 0;
        }

        /* ── Notice banner ────────────────────────────────── */
        .notice-banner {
            background: #3a1a1a;
            border: 1px solid #7a3333;
            border-radius: 5px;
            color: var(--red-alert);
            padding: 10px 16px;
            margin-bottom: 14px;
            font-size: 14pt;
        }

        /* ── Link lists inside cards ──────────────────────── */
        .link-list {
            list-style: none;
            margin: 0;
            padding: 0;
            font-size: 14pt;
        }

        .link-list li {
            padding: 5px 0;
            border-bottom: 1px solid #333;
        }

        .link-list li:last-child { border-bottom: none; }

        .link-list li a::before {
            content: '→ ';
            color: var(--accent);
            font-weight: bold;
        }

        /* ── Two-column grid ──────────────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        @media (max-width: 800px) {
            .info-grid { grid-template-columns: 1fr; }
        }

        /* ── Admin panel ──────────────────────────────────── */
        .admin-panel {
            background: #1e1210;
            border: 1px solid #6b3a1a;
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 16px;
        }

        .admin-panel-title {
            color: #e08a3a;
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border-bottom: 1px solid #6b3a1a;
            padding-bottom: 8px;
            margin: 0 0 12px 0;
        }

        .admin-section-label {
            color: var(--text-muted);
            font-size: 9pt;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin: 10px 0 4px;
        }

        .admin-links { list-style: none; margin: 0; padding: 0; }
        .admin-links li { padding: 3px 0; }
        .admin-links li a { color: #e08a3a; font-size: 13pt; }
        .admin-links li a:hover { color: #f0aa60; }
        .admin-links li a::before { content: '⚙ '; font-size: 10px; }

        /* ── Content text ─────────────────────────────────── */
        .mrl-content {
            color: var(--gold);
            font-size: 16pt;
            line-height: 120%;
            font-family: var(--font-main);
        }

        /* ── Section divider ──────────────────────────────── */
        .section-divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 22px 0;
        }

        /* ── Footer ───────────────────────────────────────── */
        .mrl-footer {
            font-size: 12pt;
            line-height: 120%;
            font-family: var(--font-main);
            color: var(--gold);
            padding: 16px 0 10px;
            margin-top: 20px;
        }
    </style>
</head>

<body>

<!-- ── Navbar ────────────────────────────────────────────── -->
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
                        <i class="icon-user"></i> <?php echo htmlspecialchars($first_name); ?> <i class="caret"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a tabindex="-1" href="<?php echo $mrl; ?>">MRL Home</a></li>
                        <li><a tabindex="-2" href="<?php echo $mrl; ?>profile.php">Profile Page</a></li>
                        <li><a tabindex="-3" href="<?php echo $mrl; ?>logout.php">Logout</a></li>
                    </ul>
                </li>
            </ul>
            <a class="brand">
                <ol align="center"><?php echo htmlspecialchars($sitename); ?> - My Team Page</ol>
            </a>
            <iframe src="https://freesecure.timeanddate.com/clock/i7eqrnfz/n777/fn16/fs18/bas/bat0/pd2/tt0/tw1/tm2"
                    frameborder="1px" width="330" height="28"></iframe>
        </div>
    </div>
</div>

<!-- ── Page content ──────────────────────────────────────── -->
<div class="page-wrap">

    <!-- Greeting -->
    <div class="mrl-content">
        Hi <?php echo htmlspecialchars($first_name); ?> ...<br><br>
    </div>

    <?php if ($notice_message !== ''): ?>
        <div class="notice-banner"><?php echo htmlspecialchars($notice_message); ?></div>
    <?php endif; ?>

    <!-- ── Admin Panel (admins only) ─────────────────────── -->
    <?php if ($isAdmin): ?>
    <div class="admin-panel">
        <div class="admin-panel-title">⚑ Admin Menu</div>

        <div class="admin-section-label">League Management</div>
        <ul class="admin-links">
            <li><a href="https://manliusracingleague.com/admin_setup.php" target="_blank">Setup Year/Segment &amp; Submission Date</a></li>
            <li><a href="https://manliusracingleague.com/Paid_Status_Year.php" target="_blank">See Paid Status for selectable year</a></li>
            <li><a href="https://manliusracingleague.com/team_view_as.php" target="_blank">View Team page as alternate user</a></li>
            <li><a href="https://manliusracingleague.com/email.php" target="_blank">List all email addresses — active &amp; inactive</a></li>
            <li><a href="https://manliusracingleague.com/change_user_auth.php" target="_blank">Toggle user status to make late picks or change driver</a></li>
            <li><a href="https://manliusracingleague.com/addDrivers.php" target="_blank">Add drivers for a new year</a></li>
            <li><a href="https://manliusracingleague.com/current_segment_chart_by_entry_time.php" target="_blank">Show current segment team chart sorted by Entry Time</a></li>
        </ul>

        <div class="admin-section-label">Hosting &amp; Infrastructure</div>
        <ul class="admin-links">
            <li><a href="https://auth-db1928.hstgr.io/index.php?db=u809830586_MRL_DB" target="_blank">phpMyAdmin (Hostinger)</a></li>
            <li><a href="https://manliusracingleague.com/wp-admin/?platform=hpanel&client_id=1017612160" target="_blank">WP Admin (Hostinger)</a></li>
            <li><a href="https://hpanel.hostinger.com/websites/manliusracingleague.com/files/backups" target="_blank">Backups (Hostinger)</a></li>
            <li><a href="https://hpanel.hostinger.com/websites/manliusracingleague.com" target="_blank">hPanel (Hostinger)</a></li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Welcome + League Info + Team Menu ─────────────── -->
    <div class="mrl-content">
        Welcome to your team page.<br><br>
    </div>

    <div class="info-grid">

        <div class="mrl-card">
            <div class="mrl-card-title">📋 <?php echo $season_year; ?> League Info</div>
            <p style="color:var(--text-muted); font-size:10pt; margin:0 0 8px;">Updated <?php echo htmlspecialchars($info_updated); ?></p>
            <ul class="link-list">
                <?php foreach ($league_links as $link): ?>
                    <li><a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo htmlspecialchars($link['label']); ?>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="mrl-card">
            <div class="mrl-card-title">🏁 Team Menu</div>
            <ul class="link-list">
                <li><a href="https://manliusracingleague.com/showDrivers.php" target="_blank" rel="noopener noreferrer">Driver Chart(s) — view, print for any year</a></li>
                <li><a href="https://manliusracingleague.com/team_chart.php" target="_blank" rel="noopener noreferrer">Team Chart(s) — view, pdf, spreadsheet for any year/segment</a></li>
                <li><a href="https://manliusracingleague.com/submitted_teams.php" target="_blank" rel="noopener noreferrer">Submitted Teams for Current Segment</a></li>
                <li><a href="https://manliusracingleague.com/profile.php" target="_blank" rel="noopener noreferrer">Your Profile page (change your email addresses, etc)</a></li>
            </ul>
        </div>

    </div>

    <!-- ── Current team chart ─────────────────────────────── -->
    <a name="current_user_team_chart"></a>
    <?php include 'current_user_team_chart.php'; ?>

    <!-- ── Late pick (if authorized) ─────────────────────── -->
    <?php
    $stmt = $user_home->runQuery("SELECT * FROM users WHERE userID=:uid AND changeAuth=:changeAuth");
    $stmt->execute([':uid' => $_SESSION['userSession'], ':changeAuth' => 'Y']);
    if ($stmt->rowCount() == 1) {
        include 'team-late-pick.php';
    }
    ?>

    <!-- ── Pick form or locked message ───────────────────── -->
    <div class="mrl-content">
        <br>
        <?php
        $end_ts  = strtotime($formLockDate);
        $user_ts = strtotime($currentTimeIs);

        if ($formLocked == 'no') {
            if ($end_ts > $user_ts) {

                $teamName = '';
                if (isset($dbconnect)) {
                    $teamCheck = mysqli_query(
                        $dbconnect,
                        "SELECT teamName FROM user_teams
                         WHERE userID = $uid AND raceYear = $raceYear LIMIT 1"
                    );
                    if ($teamCheck) {
                        $teamRow  = mysqli_fetch_assoc($teamCheck);
                        $teamName = trim($teamRow['teamName'] ?? '');
                    }
                }

                if ($teamName === '') {
                    if (!isset($dbconnect)) {
                        echo "<div style='color:var(--red-alert); font-weight:bold; font-size:14pt; text-align:center;'>Database connection not available.</div>";
                    } else {
                        mrl_teamname_render_form($dbconnect, (string)$raceYear, $uid, (string)$teamNameMessage);
                    }
                } else {
                    include $currentForm;
                    include 'submitted_teams_count.php';
                }

            } else {
                echo htmlspecialchars($formLockedMessage) . " - past Lock date of " . htmlspecialchars($formLockDate);
                include 'current_segment_chart.php';
            }
        } else {
            echo htmlspecialchars($formLockedMessage);
        }
        ?>
        <br><br>
    </div>

    <!-- ── Previous years ────────────────────────────────── -->
    <hr class="section-divider">

    <div class="mrl-content">
        <p>
            <span style="font-size:20pt; text-decoration:underline;">Previous Years Picks</span>
            <br><br>
            FYI: All previous years picks data has been fully restored and is now being pulled
            from the final team picks table. Please let us know if you see anything that
            doesn't look right to you. Thanks for your patience through all of this.<br>
        </p>
    </div>

    <?php
    $sql = "SELECT * FROM `years` WHERE `year` < '$raceYear' AND `year` > '0' ORDER BY `years`.`year` DESC";
    foreach ($dbo->query($sql) as $row) {
        $prevRaceYear = $row['year'];
        include 'prior_year_user_team_chart.php';
    }
    ?>

    <br>

    <!-- ── Footer ────────────────────────────────────────── -->
    <div class="mrl-footer">
        Copyright &copy; 2017&ndash;<?php echo date('Y'); ?> Manlius Racing League
    </div>

</div><!-- /.page-wrap -->

<script src="bootstrap/js/jquery-1.9.1.min.js"></script>
<script src="bootstrap/js/bootstrap.min.js"></script>
<script src="assets/scripts.js"></script>
</body>
</html>
