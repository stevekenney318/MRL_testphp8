<?php
declare(strict_types=1);

session_start();
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('/login.php');
    exit;
}

if (!function_exists('isAdmin') || !isAdmin($_SESSION['userSession'] ?? null)) {
    http_response_code(403);
    echo 'Not authorized.';
    exit;
}

date_default_timezone_set('America/New_York');

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function load_json_file(string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_json_atomic(string $path, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return false;
    $json .= "\n";
    $tmp = $path . '.tmp_' . getmypid() . '_' . date('Ymd_His');
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0644);
    if (is_file($path)) {
        @copy($path, dirname($path) . '/_weekly_standings_release_history_previous_manual.json');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0644);
    return true;
}

function release_id(array $r): string {
    $id = (string)($r['release_id'] ?? $r['generated_id'] ?? '');
    if ($id !== '') return $id;
    $sid = (string)($r['snapshot_id'] ?? '');
    $rc = (string)($r['race_code'] ?? '');
    if ($sid !== '' && $rc !== '') return $sid . '_' . $rc;
    return $sid;
}

function is_pending(array $r): bool {
    $status = strtolower(trim((string)($r['status'] ?? '')));
    $public = strtolower(trim((string)($r['public_status'] ?? '')));
    return (
        !empty($r['manual_pending_review']) ||
        !empty($r['pending_review']) ||
        !empty($r['under_review']) ||
        $status === 'pending_review' ||
        $public === 'pending_review'
    );
}

function short_release_label(array $r): string {
    $race = trim((string)($r['race_code'] ?? '') . ' ' . (string)($r['race_label'] ?? $r['short_name'] ?? ''));
    if ($race === '') $race = (string)($r['race_name'] ?? '');
    $ver = (string)($r['display_version'] ?? '');
    $rel = (string)($r['released_at_display'] ?? '');
    return trim($race . ($ver !== '' ? ' ' . $ver : '') . ($rel !== '' ? ' — ' . $rel : ''));
}

$baseDir = __DIR__;
$year = isset($_GET['year']) ? preg_replace('/[^0-9]/', '', (string)$_GET['year']) : date('Y');
if ($year === '') $year = date('Y');

$yearDir = $baseDir . '/' . $year;
$historyPath = $yearDir . '/_weekly_standings_release_history.json';

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postYear = preg_replace('/[^0-9]/', '', (string)($_POST['year'] ?? $year));
    if ($postYear !== '') $year = $postYear;
    $yearDir = $baseDir . '/' . $year;
    $historyPath = $yearDir . '/_weekly_standings_release_history.json';

    $action = (string)($_POST['action'] ?? '');
    $rid = (string)($_POST['release_id'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));

    $history = load_json_file($historyPath);
    if (!isset($history['releases']) || !is_array($history['releases'])) {
        $error = 'Release history not found or invalid for ' . $year . '.';
    } else {
        $found = false;
        foreach ($history['releases'] as &$release) {
            if (!is_array($release)) continue;
            if (release_id($release) !== $rid) continue;

            $found = true;
            $now = date('Y-m-d H:i:s');
            $event = [
                'event_at' => $now,
                'event_at_display' => date('M j, Y g:i A'),
                'action' => $action,
                'reason' => $reason,
                'user_id' => (int)($_SESSION['userSession'] ?? 0),
                'source' => 'weekly_pending_review_admin.php',
            ];

            if (!isset($release['pending_review_events']) || !is_array($release['pending_review_events'])) {
                $release['pending_review_events'] = [];
            }
            $release['pending_review_events'][] = $event;

            if ($action === 'add_pending') {
                $release['status'] = 'pending_review';
                $release['public_status'] = 'Pending league review';
                $release['manual_pending_review'] = true;
                $release['pending_review'] = true;
                $release['under_review'] = true;
                $release['pending_review_reason'] = $reason !== '' ? $reason : 'Manually flagged for league review.';
                $release['pending_review_updated_at'] = $now;
            } elseif ($action === 'remove_pending') {
                $release['status'] = 'released';
                $release['public_status'] = 'Official standings release';
                $release['manual_pending_review'] = false;
                $release['pending_review'] = false;
                $release['under_review'] = false;
                $release['pending_review_removed_reason'] = $reason !== '' ? $reason : 'Manual review cleared.';
                $release['pending_review_updated_at'] = $now;
            }
            break;
        }
        unset($release);

        if (!$found) {
            $error = 'Release ID not found: ' . $rid;
        } else {
            $pendingCount = 0;
            foreach ($history['releases'] as $r) {
                if (is_array($r) && is_pending($r)) $pendingCount++;
            }
            $history['pending_release_count'] = $pendingCount;
            $history['generated_at'] = date('Y-m-d H:i:s');
            $history['manual_pending_review_admin_last_update'] = date('Y-m-d H:i:s');

            if (write_json_atomic($historyPath, $history)) {
                $msg = 'Release updated.';
            } else {
                $error = 'Failed writing release history.';
            }
        }
    }
}

$history = load_json_file($historyPath);
$releases = isset($history['releases']) && is_array($history['releases']) ? $history['releases'] : [];

usort($releases, function ($a, $b) {
    $ar = (string)($a['race_code'] ?? '');
    $br = (string)($b['race_code'] ?? '');
    if ($ar === $br) {
        return strcmp((string)($b['released_at'] ?? ''), (string)($a['released_at'] ?? ''));
    }
    return strcmp($ar, $br);
});

$pending = [];
foreach ($releases as $r) {
    if (is_array($r) && is_pending($r)) $pending[] = $r;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>MRL Pending Review Admin</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:18px;font-size:16px;color:#111;}
h1{margin:0 0 12px 0;}
.notice{padding:10px 12px;border-radius:8px;margin:10px 0;max-width:1000px;}
.ok{background:#e8f5e9;border:1px solid #2e8b57;}
.err{background:#ffe6e6;border:1px solid #c00000;}
.toolbar{margin:12px 0 18px 0;}
table{border-collapse:collapse;width:100%;max-width:1400px;}
th,td{border:1px solid #777;padding:5px 7px;vertical-align:top;}
th{background:#ffff00;text-align:left;}
tr:nth-child(even) td{background:#d2e5f7;}
.pending-pill{display:inline-block;background:#f1c232;border:2px solid #b8961c;border-radius:15px;padding:2px 9px;font-weight:bold;}
.current-pill{display:inline-block;background:#2e8b57;color:#fff;border:2px solid #1f5f3b;border-radius:15px;padding:2px 9px;font-weight:bold;}
button{font:inherit;padding:2px 8px;cursor:pointer;}
input[type=text]{font:inherit;width:360px;max-width:100%;}
.small{font-size:12px;color:#555;}
form.inline{display:inline;}
</style>
</head>
<body>
<h1>MRL Pending Review Admin</h1>

<div class="toolbar">
<form method="get">
    Year:
    <input type="text" name="year" value="<?php echo h($year); ?>" style="width:80px;">
    <button type="submit">Load</button>
    <a href="weekly_standings.php?year=<?php echo h($year); ?>">Open Weekly Standings</a>
</form>
</div>

<?php if ($msg !== ''): ?><div class="notice ok"><?php echo h($msg); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice err"><?php echo h($error); ?></div><?php endif; ?>

<h2>Pending Review Queue</h2>
<?php if (empty($pending)): ?>
    <p><span class="current-pill">Clear</span> No releases are currently pending review for <?php echo h($year); ?>.</p>
<?php else: ?>
<table>
<thead><tr><th>Release</th><th>Reason</th><th>Release ID</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($pending as $r): ?>
<tr>
<td><span class="pending-pill">Pending Review</span> <?php echo h(short_release_label($r)); ?></td>
<td><?php echo h((string)($r['pending_review_reason'] ?? $r['reason_public'] ?? '')); ?></td>
<td><code><?php echo h(release_id($r)); ?></code></td>
<td>
<form class="inline" method="post">
<input type="hidden" name="year" value="<?php echo h($year); ?>">
<input type="hidden" name="release_id" value="<?php echo h(release_id($r)); ?>">
<input type="hidden" name="action" value="remove_pending">
<input type="text" name="reason" placeholder="Reason for clearing">
<button type="submit">Remove Pending</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<h2>All Releases</h2>
<table>
<thead><tr><th>Status</th><th>Release</th><th>MRL Impact</th><th>Reason</th><th>Release ID</th><th>Manual Control</th></tr></thead>
<tbody>
<?php foreach ($releases as $r): if (!is_array($r)) continue; $rid = release_id($r); $p = is_pending($r); ?>
<tr>
<td><?php echo $p ? '<span class="pending-pill">Pending Review</span>' : '<span class="current-pill">Released</span>'; ?></td>
<td><?php echo h(short_release_label($r)); ?><div class="small"><?php echo h((string)($r['snapshot_file'] ?? '')); ?></div></td>
<td><?php echo !empty($r['mrl_impact']) ? 'Yes' : 'No'; ?></td>
<td><?php echo h((string)($r['pending_review_reason'] ?? $r['reason_public'] ?? $r['reason'] ?? '')); ?></td>
<td><code><?php echo h($rid); ?></code></td>
<td>
<form method="post">
<input type="hidden" name="year" value="<?php echo h($year); ?>">
<input type="hidden" name="release_id" value="<?php echo h($rid); ?>">
<?php if ($p): ?>
<input type="hidden" name="action" value="remove_pending">
<input type="text" name="reason" placeholder="Reason for clearing">
<button type="submit">Remove Pending</button>
<?php else: ?>
<input type="hidden" name="action" value="add_pending">
<input type="text" name="reason" placeholder="Reason for pending review">
<button type="submit">Add Pending</button>
<?php endif; ?>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p class="small">History file: <?php echo h($historyPath); ?></p>
</body>
</html>