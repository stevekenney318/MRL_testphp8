
<?php
// ------------------------------------------------------------
// Admin Setup - View Only
// Displays current MRL configuration from admin_setup
// ------------------------------------------------------------

session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';

// Create USER object
$user_home = new USER();

// Redirect if not logged in
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

// Admin check
$isAdmin = isAdmin($_SESSION['userSession'] ?? null);

// ------------------------------------------------------------
// Fetch current admin_setup row
// ------------------------------------------------------------

$sql = "
    SELECT
        a.raceYear,
        a.segment,
        a.formLocked,
        a.formLockDate,
        a.formLockTime,
        a.currentForm,
        a.updatedAt,
        a.updatedBy,
        u.userName AS updatedByName
    FROM admin_setup a
    LEFT JOIN users u
        ON a.updatedBy = u.userID
    ORDER BY a.updatedAt DESC
    LIMIT 1
";

$result = mysqli_query($dbconnect, $sql);

if (!$result || mysqli_num_rows($result) !== 1) {
    die('Error: Unable to read admin_setup configuration.');
}

$row = mysqli_fetch_assoc($result);

// ------------------------------------------------------------
// USA-style display formatting (VIEW ONLY)
// ------------------------------------------------------------

// Combine Form Lock date + time
$displayFormLock = $row['formLockDate'] . ' ' . $row['formLockTime'];

if (!empty($row['formLockDate']) && !empty($row['formLockTime'])) {
    $dt = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $row['formLockDate'] . ' ' . $row['formLockTime']
    );
    if ($dt) {
        $displayFormLock = $dt->format('n/j/y g:i A');
    }
}

// Format Last Updated
$displayUpdatedAt = $row['updatedAt'];

if (!empty($row['updatedAt'])) {
    $dtUpdated = DateTime::createFromFormat('Y-m-d H:i:s', $row['updatedAt']);
    if ($dtUpdated) {
        $displayUpdatedAt = $dtUpdated->format('n/j/y g:i A');
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>MRL Admin Setup (View)</title>
    <meta charset="UTF-8">

    <link rel="stylesheet" href="/mrl-styles.css">

    <style>
        table {
            border-collapse: collapse;
            margin-top: 20px;
            min-width: 500px;
        }

        th, td {
            border: 1px solid #666;
            padding: 10px 14px;
            text-align: left;
        }

        th {
            background-color: #444;
            color: #fff;
        }

        td {
            background-color: #333;
        }

        h1 {
            margin-bottom: 10px;
        }

        .note {
            margin-top: 15px;
            font-size: 12px;
            color: #bbb;
        }
    </style>
</head>

<body>

<?php
echo $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

if (!$isAdmin) {
    exit;
}
?>

<h1>MRL Admin Setup (Read-Only)</h1>

<table>
    <tr>
        <th>Setting</th>
        <th>Current Value</th>
    </tr>

    <tr>
        <td>Race Year</td>
        <td><?php echo htmlspecialchars($row['raceYear']); ?></td>
    </tr>

    <tr>
        <td>Segment</td>
        <td><?php echo htmlspecialchars($row['segment']); ?></td>
    </tr>

    <tr>
        <td>Form Locked</td>
        <td><?php echo htmlspecialchars($row['formLocked']); ?></td>
    </tr>

    <tr>
        <td>Form Lock</td>
        <td><?php echo htmlspecialchars($displayFormLock); ?></td>
    </tr>

    <tr>
        <td>Current Form</td>
        <td><?php echo htmlspecialchars($row['currentForm']); ?></td>
    </tr>

    <tr>
        <td>Last Updated</td>
        <td><?php echo htmlspecialchars($displayUpdatedAt); ?></td>
    </tr>

    <tr>
        <td>Updated By</td>
        <td>
            <?php
            echo htmlspecialchars(
                $row['updatedByName']
                ? $row['updatedByName']
                : 'User ID ' . $row['updatedBy']
            );
            ?>
        </td>
    </tr>
</table>

<div class="note">
    Phase 1: View-only admin configuration.<br>
    Editing controls will be added in the next phase.
</div>

</body>
</html>
