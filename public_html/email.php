<?php
declare(strict_types=1);

// ----------------------------
// Session + return_to
// ----------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

// ----------------------------
// Includes
// ----------------------------
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

// ----------------------------
// Safe HTML escape helper
// (prevents fatal error if your project doesn't define h())
// ----------------------------
if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// ----------------------------
// Auth
// ----------------------------
$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

$isAdmin = isAdmin($_SESSION['userSession'] ?? null);

$adminStatusLine = $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

if (!$isAdmin) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Not Authorized</title>
        <link rel="stylesheet" href="/mrl-styles.css">
    </head>
    <body><?php echo $adminStatusLine; ?></body>
    </html>
    <?php
    exit;
}

// ----------------------------
// Page state
// ----------------------------
$msg = $msg ?? '';

// ----------------------------
// Helpers
// ----------------------------
function buildEmailOutputs(array $rows): array
{
    $unique = [];

    // Unique list
    foreach ($rows as $r) {
        $e1 = isset($r['userEmail']) ? trim((string)$r['userEmail']) : '';
        $e2 = isset($r['userEmail2']) ? trim((string)$r['userEmail2']) : '';

        foreach ([$e1, $e2] as $e) {
            if ($e === '') continue;
            $key = mb_strtolower($e);
            $unique[$key] = $e; // keep original casing
        }
    }

    // Per-row printable lines
    $lines = [];
    foreach ($rows as $r) {
        $e1 = isset($r['userEmail']) ? trim((string)$r['userEmail']) : '';
        $e2 = isset($r['userEmail2']) ? trim((string)$r['userEmail2']) : '';

        $pair = [];
        if ($e1 !== '') $pair[] = $e1;
        if ($e2 !== '' && mb_strtolower($e2) !== mb_strtolower($e1)) $pair[] = $e2;

        if (!empty($pair)) {
            $lines[] = implode('  ', $pair);
        }
    }

    $csv = implode(', ', array_values($unique));

    return [
        'uniqueEmails' => array_values($unique),
        'lines'        => $lines,
        'csv'          => $csv,
        'count'        => count($unique),
    ];
}

function fetchUsersByActive(PDO $dbo, string $activeFlag): array
{
    $sql = "
        SELECT userEmail, userEmail2
        FROM users
        WHERE userID > 0
          AND userActive = :active
        ORDER BY userEmail ASC
    ";
    $stmt = $dbo->prepare($sql);
    $stmt->execute([':active' => $activeFlag]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ----------------------------
// Data
// ----------------------------
$activeRows   = fetchUsersByActive($dbo, 'Y');
$inactiveRows = fetchUsersByActive($dbo, 'N');

$activeOut   = buildEmailOutputs($activeRows);
$inactiveOut = buildEmailOutputs($inactiveRows);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>MRL Email List</title>
    <link rel="stylesheet" href="/mrl-styles.css">

    <style>
        .email-wrap { max-width: 1100px; margin: 0 auto; padding: 12px; }

        /* Force readable text inside the white boxes */
        .email-section {
            margin-top: 18px;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fff;
            color: #111;
        }
        .email-section * { color: inherit; }

        .email-title { font-size: 20px; font-weight: 700; margin: 0 0 8px 0; }
        .email-subtitle { margin: 0 0 10px 0; opacity: 0.85; }

        .email-lines {
            padding: 10px;
            border: 1px solid #e3e3e3;
            border-radius: 6px;
            background: #fafafa;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .divider { margin: 18px 0; border-top: 1px solid #e6e6e6; }

        .copy-box {
            width: 100%;
            min-height: 88px;
            padding: 10px;
            border: 1px solid #cfcfcf;
            border-radius: 6px;
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
            background: #fff;
            color: #111;
        }

        .email-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .btn-copy {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #888;
            background: #f3f3f3;
            cursor: pointer;
            font-weight: 600;
            color: #111;
        }

        .copy-status { font-weight: 600; opacity: 0.85; }
    </style>
</head>
<body>

<div class="email-wrap">

    <?php echo $adminStatusLine; ?>

    <?php if (!empty($msg)): ?>
        <div id="flashMsg" class="flash-top notice-success"><?php echo h($msg); ?></div>
        <script>
        (function(){
            var el = document.getElementById('flashMsg');
            if(!el) return;
            window.setTimeout(function(){
                el.style.transition = "opacity 0.6s ease";
                el.style.opacity = "0";
                window.setTimeout(function(){ el.style.display = "none"; }, 650);
            }, 2200);
        })();
        </script>
    <?php endif; ?>

    <!-- ACTIVE -->
    <div class="email-section">
        <div class="email-title">Active email addresses</div>
        <div class="email-subtitle">Unique emails: <?php echo (int)$activeOut['count']; ?></div>

        <div class="email-lines"><?php
            if (empty($activeOut['lines'])) {
                echo h('No active emails found.');
            } else {
                echo h(implode("\n", $activeOut['lines']));
            }
        ?></div>

        <div class="divider"></div>

        <div class="email-subtitle" style="margin-bottom:6px;">Copy/paste list (comma-separated) for an email:</div>
        <textarea id="activeCopyBox" class="copy-box" readonly><?php echo h($activeOut['csv']); ?></textarea>

        <div class="email-actions">
            <button class="btn-copy" type="button" onclick="copyFromBox('activeCopyBox','activeCopyStatus')">
                Copy Active Emails
            </button>
            <span id="activeCopyStatus" class="copy-status"></span>
        </div>
    </div>

    <!-- INACTIVE -->
    <div class="email-section">
        <div class="email-title">Inactive email addresses</div>
        <div class="email-subtitle">Unique emails: <?php echo (int)$inactiveOut['count']; ?></div>

        <div class="email-lines"><?php
            if (empty($inactiveOut['lines'])) {
                echo h('No inactive emails found.');
            } else {
                echo h(implode("\n", $inactiveOut['lines']));
            }
        ?></div>

        <div class="divider"></div>

        <div class="email-subtitle" style="margin-bottom:6px;">Copy/paste list (comma-separated) for an email:</div>
        <textarea id="inactiveCopyBox" class="copy-box" readonly><?php echo h($inactiveOut['csv']); ?></textarea>

        <div class="email-actions">
            <button class="btn-copy" type="button" onclick="copyFromBox('inactiveCopyBox','inactiveCopyStatus')">
                Copy Inactive Emails
            </button>
            <span id="inactiveCopyStatus" class="copy-status"></span>
        </div>
    </div>
</div> <!-- /email-wrap -->

<script>
function copyFromBox(textareaId, statusId) {
    var ta = document.getElementById(textareaId);
    var st = document.getElementById(statusId);
    if (!ta) return;

    ta.focus();
    ta.select();
    ta.setSelectionRange(0, ta.value.length);

    var text = ta.value;

    function showStatus(msg) {
        if (!st) return;
        st.textContent = msg;
        window.setTimeout(function(){ st.textContent = ""; }, 2000);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function(){
            showStatus("Copied!");
        }).catch(function(){
            try {
                var ok = document.execCommand('copy');
                showStatus(ok ? "Copied!" : "Copy failed (select and Ctrl+C).");
            } catch (e) {
                showStatus("Copy failed (select and Ctrl+C).");
            }
        });
    } else {
        try {
            var ok2 = document.execCommand('copy');
            showStatus(ok2 ? "Copied!" : "Copy failed (select and Ctrl+C).");
        } catch (e2) {
            showStatus("Copy failed (select and Ctrl+C).");
        }
    }
}
</script>

</body>
</html>
