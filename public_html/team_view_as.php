<?php
declare(strict_types=1);

/*
    filename: team_view_as.php
    purpose : Admin-only "View As" helper to simulate another user for viewing team.php (no edits to team.php)
    notes   : PHP 7.3 compatible

    How it works:
    - Admin selects a user from a dropdown (userID + userName)
    - We store the admin's original user ID in session (alternate_admin_uid)
    - We store the selected user ID in session (alternate_user_uid)
    - We replace $_SESSION['userSession'] with the selected userID (so team.php behaves naturally)
    - This page stays accessible because admin-check uses alternate_admin_uid when present
    - Provide an "Open team.php in new tab" link
    - Provide a Reset link to restore the admin session

    Special note:
    - Supports a real userID of 0 (your "MRL" test user)
*/

session_start();

// Remember where the user was (useful after login)
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/';

// Includes (use your established project structure)
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

// Require login
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

// Simple HTML escaper
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Session keys used:
// - userSession: your existing "logged in as" user ID (we will swap this to the alternate user)
// - alternate_admin_uid: the original admin user ID who started "view as"
// - alternate_user_uid: the selected alternate user ID (can be 0)
$alternateAdminUid = isset($_SESSION['alternate_admin_uid']) ? (int)$_SESSION['alternate_admin_uid'] : 0;

// IMPORTANT: alternate user can be 0, so we use isset() to detect "set"
$hasAlternateUser = array_key_exists('alternate_user_uid', $_SESSION);
$alternateUserUid = $hasAlternateUser ? (int)$_SESSION['alternate_user_uid'] : 0;

// Use the saved admin ID (if present) for authorization checks on this page
$authUid = $alternateAdminUid > 0 ? $alternateAdminUid : (int)($_SESSION['userSession'] ?? 0);

// Require admin (based on $authUid)
$isAdmin = isAdmin($authUid);

// Admin status banner (matches your existing CSS approach)
$adminStatusLine = $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

// Flash message helper (session-based)
$flash = '';
if (!empty($_SESSION['flash_msg'])) {
    $flash = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

// If not authorized, render a minimal page and exit
if (!$isAdmin) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Not Authorized</title>
        <link rel="stylesheet" href="/mrl-styles.css">
    </head>
    <body>
        <?php echo $adminStatusLine; ?>
        <div style="text-align:center; margin-top:12px;">
            Access denied.
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Reset / return to admin
if (isset($_GET['reset']) && $_GET['reset'] === '1') {

    if ($alternateAdminUid > 0) {
        // Restore original admin userSession
        $_SESSION['userSession'] = $alternateAdminUid;
    }

    // Clear alternate mode
    unset($_SESSION['alternate_admin_uid']);
    unset($_SESSION['alternate_user_uid']);

    $_SESSION['flash_msg'] = 'Reset complete. You are back to your admin account.';

    // Redirect back to this page (clean URL)
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Handle form submission: set alternate user (but do NOT include team.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $selectedUidRaw = $_POST['view_as_userid'] ?? '';

    // Allow userID = 0; only block empty or non-numeric
    if ($selectedUidRaw === '' || !is_numeric($selectedUidRaw)) {
        $_SESSION['flash_msg'] = 'Please select a user.';
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    $selectedUid = (int)$selectedUidRaw;

    // If we are not already in alternate mode, remember who the admin is
    if (!isset($_SESSION['alternate_admin_uid']) || (int)$_SESSION['alternate_admin_uid'] <= 0) {
        $_SESSION['alternate_admin_uid'] = (int)($_SESSION['userSession'] ?? 0);
    }

    // Save the selected alternate user (can be 0)
    $_SESSION['alternate_user_uid'] = $selectedUid;

    // Swap the session identity to the selected user (so team.php works unchanged)
    $_SESSION['userSession'] = $selectedUid;

    $_SESSION['flash_msg'] = 'Alternate user set. Use the link below to open team.php in a new tab.';

    // Redirect back to this page so refresh doesn’t resubmit POST
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

/**
 * Build dropdown list of active users
 * Uses PDO ($dbo) which is already established by your config.php.
 */
$users = [];
$error = '';

try {
    if (!isset($dbo)) {
        throw new RuntimeException('PDO connection $dbo not available (check config.php).');
    }

    $sql = "SELECT userID, userName
            FROM users
            WHERE userActive = 'Y'
            -- WHERE userStatus = 'Y'
            ORDER BY userName ASC";
    $stmt = $dbo->prepare($sql);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $error = $e->getMessage();
}

// For display: look up the alternate user's name (works for userID 0 too)
$alternateUserName = '';
if ($hasAlternateUser && isset($dbo)) {
    try {
        $stmt = $dbo->prepare("SELECT userName FROM users WHERE userID = :uid LIMIT 1");
        $stmt->execute([':uid' => $alternateUserUid]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $alternateUserName = (string)($r['userName'] ?? '');
    } catch (Throwable $e) {
        // ignore display-only error
    }
}

$pageTitle = 'MRL Admin - Team View As';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($pageTitle); ?></title>
    <link rel="stylesheet" href="/mrl-styles.css">
</head>

<body>

<?php echo $adminStatusLine; ?>

<?php if ($flash !== ''): ?>
    <div id="flashMsg" class="flash-top notice-success"><?php echo h($flash); ?></div>
    <script>
    (function () {
        var el = document.getElementById('flashMsg');
        if (!el) return;

        window.setTimeout(function () {
            el.style.transition = "opacity 0.6s ease";
            el.style.opacity = "0";
            window.setTimeout(function () {
                el.style.display = "none";
            }, 650);
        }, 2200);
    })();
    </script>
<?php endif; ?>

<div style="text-align:center; margin-top:10px;">
    <div style="max-width:760px; margin:0 auto; text-align:left;">
        <h2 style="margin: 8px 0 10px 0;"><?php echo h($pageTitle); ?></h2>

        <p>
            Use this page to temporarily switch to an alternate user for viewing <b>team.php</b>.
            This page stays usable even if the alternate user is not an admin.
        </p>

        <?php if ($hasAlternateUser): ?>
            <div style="margin:12px 0; padding:10px; border:1px solid #666; border-radius:6px;">
                <b>Alternate user currently set:</b>
                <?php echo h($alternateUserName !== '' ? $alternateUserName : 'Unknown'); ?>
                <?php echo h(' (ID ' . $alternateUserUid . ')'); ?>
                <br><br>

                <a href="/team.php" target="_blank" rel="noopener noreferrer"
                   style="font-weight:bold; text-decoration:underline;">
                    Open team.php in a new tab
                </a>

                <br><br>
                <a href="?reset=1">Reset / return to admin</a>
            </div>
        <?php else: ?>
            <p><b>No alternate user is set.</b></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div style="color:#ff6666; font-weight:bold; margin:12px 0;">
                Error: <?php echo h($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" style="margin-top: 10px;">
            <label for="view_as_userid"><b>Select a user:</b></label><br>

            <select name="view_as_userid" id="view_as_userid" style="min-width:320px;">
                <option value="">Select Name</option>
                <?php foreach ($users as $u): ?>
                    <?php
                        $id = (int)($u['userID'] ?? 0);
                        $name = (string)($u['userName'] ?? '');
                    ?>
                    <option value="<?php echo $id; ?>">
                        <?php echo h($name . ' (ID ' . $id . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <br><br>
            <input type="submit" value="Set Alternate User">
        </form>
    </div>
</div>

<?php
echo "<div style='font:11px/1.2 monospace; color:#999; text-align:center; margin:0; padding:10px 0 0 0;'>"
   . "FILE: " . basename(__FILE__) . " | " . date('Y-m-d H:i:s')
   . "</div>";
?>

</body>
</html>
