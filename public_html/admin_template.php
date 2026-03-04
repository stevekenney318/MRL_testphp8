<?php
declare(strict_types=1);

/*
    filename: admin-template.php
    purpose : Starter template for MRL admin-only pages
    notes   : PHP 7.3 compatible
*/

session_start();

// Remember where the user was (useful after login)
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/';

// Includes (use your established project structure)
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

// Optional (uncomment if you want a consistent timezone on admin pages)
// date_default_timezone_set("America/New_York");

$user_home = new USER();

// Require login
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

// Session user id (your site uses userSession)
$uid = $_SESSION['userSession'] ?? null;

// Require admin
$isAdmin = isAdmin($uid);

// Admin status banner (matches your existing CSS approach)
$adminStatusLine = $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

// Simple HTML escaper
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Date/time helpers (safe for empty/null)
function usaDate($d): string
{
    if (!$d) return '';
    $ts = strtotime((string)$d);
    return $ts ? date('n/j/Y', $ts) : '';
}

function usaTime($t): string
{
    if (!$t) return '';
    $ts = strtotime((string)$t);
    return $ts ? date('g:i A', $ts) : '';
}

function usaDT($dt): string
{
    if (!$dt) return '';
    $ts = strtotime((string)$dt);
    return $ts ? date('n/j/Y g:i A', $ts) : '';
}

/**
 * Flash message helper:
 * - Prefer a session-based flash (set it on redirect pages)
 * - Also allow an immediate local message ($msg) if you set one in the file
 */
$flash = '';
if (!empty($_SESSION['flash_msg'])) {
    $flash = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

// If you set $msg in the page logic, it will override the session flash.
$msg = $msg ?? '';
if ($msg !== '') {
    $flash = (string)$msg;
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

// Page variables (change these per page)
$pageTitle = $pageTitle ?? 'MRL Admin Page (Template)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($pageTitle); ?></title>
    <link rel="stylesheet" href="/mrl-styles.css">

    <style>
        /* Page-specific tweaks can go here */
    </style>
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

<!-- Page content starts here -->
<div style="text-align:center; margin-top:10px;">
    This is a template page
</div>

<!-- Optional unobtrusive form/page identifier (handy when multiple versions exist) -->
<div style="font-size:10px; color:#999; text-align:right; margin:14px 10px 8px 10px; padding:0;">
    admin-template.php
</div>

</body>
</html>
