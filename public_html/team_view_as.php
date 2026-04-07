<?php
declare(strict_types=1);

/**
 * team_view_as.php
 *
 * VERSION: v002
 * LAST MODIFIED: 3/30/2026 1:22:11 pm
 *
 * DESCRIPTION:
 * Admin-only "View As" helper to simulate another user for viewing team.php.
 * Works on both production and testphp8 by using site-relative links and the
 * current host for team.php.
 *
 * CHANGELOG:
 *
 * v002 (3/30/2026)
 * - Fixed team.php launch link so it works on both production and testphp8.
 * - Updated header block to current MRL style.
 * - Added traceable VERSION display at the bottom of the page.
 *
 * v001
 * - Initial PHP 7.3-compatible admin "View As" helper.
 */

session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrl_current_host_url(string $path): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'manliusracingleague.com';
    $scheme = 'https';

    if ($path === '') {
        $path = '/';
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return $scheme . '://' . $host . $path;
}

$alternateAdminUid = isset($_SESSION['alternate_admin_uid']) ? (int)$_SESSION['alternate_admin_uid'] : 0;
$hasAlternateUser = array_key_exists('alternate_user_uid', $_SESSION);
$alternateUserUid = $hasAlternateUser ? (int)$_SESSION['alternate_user_uid'] : 0;

$authUid = $alternateAdminUid > 0 ? $alternateAdminUid : (int)($_SESSION['userSession'] ?? 0);
$isAdmin = isAdmin($authUid);

$adminStatusLine = $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

$flash = '';
if (!empty($_SESSION['flash_msg'])) {
    $flash = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

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

if (isset($_GET['reset']) && $_GET['reset'] === '1') {

    if ($alternateAdminUid > 0) {
        $_SESSION['userSession'] = $alternateAdminUid;
    }

    unset($_SESSION['alternate_admin_uid']);
    unset($_SESSION['alternate_user_uid']);

    $_SESSION['flash_msg'] = 'Reset complete. You are back to your admin account.';

    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $selectedUidRaw = $_POST['view_as_userid'] ?? '';

    if ($selectedUidRaw === '' || !is_numeric($selectedUidRaw)) {
        $_SESSION['flash_msg'] = 'Please select a user.';
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    $selectedUid = (int)$selectedUidRaw;

    if (!isset($_SESSION['alternate_admin_uid']) || (int)$_SESSION['alternate_admin_uid'] <= 0) {
        $_SESSION['alternate_admin_uid'] = (int)($_SESSION['userSession'] ?? 0);
    }

    $_SESSION['alternate_user_uid'] = $selectedUid;
    $_SESSION['userSession'] = $selectedUid;

    $_SESSION['flash_msg'] = 'Alternate user set. Use the link below to open team.php in a new tab.';

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

$users = [];
$error = '';

try {
    if (!isset($dbo)) {
        throw new RuntimeException('PDO connection $dbo not available (check config.php).');
    }

    $sql = "SELECT userID, userName
            FROM users
            WHERE userActive = 'Y'
            ORDER BY userName ASC";
    $stmt = $dbo->prepare($sql);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $error = $e->getMessage();
}

$alternateUserName = '';
if ($hasAlternateUser && isset($dbo)) {
    try {
        $stmt = $dbo->prepare("SELECT userName FROM users WHERE userID = :uid LIMIT 1");
        $stmt->execute([':uid' => $alternateUserUid]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $alternateUserName = (string)($r['userName'] ?? '');
    } catch (Throwable $e) {
        // display-only lookup, safe to ignore
    }
}

$pageTitle = 'MRL Admin - Team View As';
$teamPageUrl = mrl_current_host_url('/team.php');
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

                <a href="<?php echo h($teamPageUrl); ?>" target="_blank" rel="noopener noreferrer"
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
   . "FILE: " . basename(__FILE__) . " | VERSION: v002 | " . date('Y-m-d H:i:s')
   . "</div>";
?>

</body>
</html>
