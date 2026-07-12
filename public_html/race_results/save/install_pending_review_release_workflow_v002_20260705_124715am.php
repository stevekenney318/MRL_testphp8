<?php
declare(strict_types=1);

/**
 * install_pending_review_release_workflow_v002.php
 *
 * VERSION: v002
 * LAST MODIFIED: 7/5/2026 12:47:15 am
 *
 * Purpose:
 *   Installer for the first release-level Pending Review workflow pass.
 *
 * Installs / updates:
 *   - weekly_standings.php -> v062
 *   - race_results_monitor.php -> v137
 *   - race_results_revision_monitor.php -> v012
 *   - weekly_pending_review_admin.php
 *
 * This installer performs a preflight first. If any required target is missing,
 * it stops before writing modified files.
 */

date_default_timezone_set('America/New_York');

$startedAt = date('Y-m-d H:i:s');
$stamp = date('Ymd_His');

$baseDir = __DIR__;
$files = [
    'weekly'   => $baseDir . '/weekly_standings.php',
    'monitor'  => $baseDir . '/race_results_monitor.php',
    'revision' => $baseDir . '/race_results_revision_monitor.php',
    'admin'    => $baseDir . '/weekly_pending_review_admin.php',
];

$errors = [];
$report = [];
$preflight = [];

function prw_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function prw_read_file(string $path, string $label, array &$errors): string {
    if (!is_file($path)) {
        $errors[] = $label . ' missing: ' . basename($path);
        return '';
    }
    if (!is_readable($path)) {
        $errors[] = $label . ' not readable: ' . basename($path);
        return '';
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        $errors[] = $label . ' could not be read or is empty: ' . basename($path);
        return '';
    }
    return $raw;
}

function prw_backup_file(string $path, array &$report): bool {
    global $stamp;
    if (!is_file($path)) return false;
    $backup = $path . '.bak_' . $stamp;
    if (!@copy($path, $backup)) {
        $report[] = 'ERROR: backup failed for ' . basename($path);
        return false;
    }
    $report[] = 'Backup created: ' . basename($backup);
    return true;
}

function prw_write_file(string $path, string $content, array &$report): bool {
    $tmp = $path . '.tmp_' . getmypid() . '_' . date('Ymd_His');
    $bytes = @file_put_contents($tmp, $content, LOCK_EX);
    if ($bytes === false) {
        $report[] = 'ERROR: temporary write failed for ' . basename($path);
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        $report[] = 'ERROR: replace failed for ' . basename($path);
        return false;
    }
    @chmod($path, 0644);
    $report[] = 'Updated: ' . basename($path);
    return true;
}

function prw_replace_once(string $label, string $src, string $search, string $replace, array &$errors): string {
    if (strpos($src, $replace) !== false) {
        return $src; // already patched
    }
    if (strpos($src, $search) === false) {
        $errors[] = 'Pattern not found: ' . $label;
        return $src;
    }
    return str_replace($search, $replace, $src);
}

function prw_regex_once(string $label, string $src, string $pattern, string $replace, array &$errors): string {
    $new = preg_replace($pattern, $replace, $src, 1, $count);
    if ($new === null) {
        $errors[] = 'Regex error: ' . $label;
        return $src;
    }
    if ($count < 1) {
        $errors[] = 'Pattern not found: ' . $label;
        return $src;
    }
    return $new;
}

function build_weekly(string $src, array &$errors): string {
    $src = prw_regex_once(
        'weekly header version',
        $src,
        '/\* VERSION: v\d{3}/',
        '* VERSION: v062',
        $errors
    );

    $src = prw_regex_once(
        'weekly header modified',
        $src,
        '/\* LAST MODIFIED: .*?\n/',
        '* LAST MODIFIED: 7/5/2026 12:47:15 am' . "\n",
        $errors
    );

    if (strpos($src, 'v062 (7/5/2026 12:47:15 am)') === false) {
        $src = prw_replace_once(
            'weekly changelog anchor',
            $src,
            " * CHANGELOG:\n *\n",
            " * CHANGELOG:\n *\n"
            . " * v062 (7/5/2026 12:47:15 am)\n"
            . " *   - CHANGE: Pending Review now uses release-level metadata instead of automatic race-folder under_review.flag state.\n"
            . " *   - NEW: Selected superseded releases receive a full-page translucent red tint.\n"
            . " *   - NEW: Validation details are visually grouped with a light green panel when expanded.\n"
            . " *   - NEW: Added support for the companion weekly_pending_review_admin.php manual review queue.\n"
            . " *\n",
            $errors
        );
    }

    $oldUnder = <<<'PHP'
$underReview = false;
if ($selectedRace !== null) {
    $underReview = rrsg_race_is_pending_review((string)$selectedRace['raceFolder']);
}

$auditMeta = rrsg_build_public_audit_meta($selectedRaceMeta, $selectedRace, $underReview, $weeklyReleaseHistory);
PHP;

    $newUnder = <<<'PHP'
$underReview = false;
$selectedReleaseManualReason = '';
if ($selectedRace !== null && !empty($selectedVersionRelease)) {
    $releaseStatusRaw = strtolower(trim((string)($selectedVersionRelease['status'] ?? '')));
    $releasePublicRaw = strtolower(trim((string)($selectedVersionRelease['public_status'] ?? '')));
    $underReview = (
        !empty($selectedVersionRelease['under_review']) ||
        !empty($selectedVersionRelease['pending_review']) ||
        !empty($selectedVersionRelease['manual_pending_review']) ||
        $releaseStatusRaw === 'pending_review' ||
        $releasePublicRaw === 'pending_review'
    );
    $selectedReleaseManualReason = trim((string)(
        $selectedVersionRelease['pending_review_reason']
        ?? $selectedVersionRelease['manual_review_reason']
        ?? $selectedVersionRelease['reason_public']
        ?? ''
    ));
}

$auditMeta = rrsg_build_public_audit_meta($selectedRaceMeta, $selectedRace, $underReview, $weeklyReleaseHistory);
if ($underReview && $selectedReleaseManualReason !== '') {
    $auditMeta['reason'] = $selectedReleaseManualReason;
}
PHP;

    $src = prw_replace_once('weekly release-level pending state', $src, $oldUnder, $newUnder, $errors);

    $oldBody = <<<'CSS'
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
            line-height: 1.3;
            margin: 12px;
            color: #111;
        }
CSS;

    $newBody = <<<'CSS'
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
            line-height: 1.3;
            margin: 12px;
            color: #111;
        }

        body.release-superseded-view {
            background: rgba(255, 0, 0, 0.10);
        }
CSS;

    $src = prw_replace_once('weekly superseded page tint css', $src, $oldBody, $newBody, $errors);

    $cssNeedle = <<<'CSS'
        .details-content {
            display: none;
            padding: 6px 0 8px 0;
            margin: 0 0 8px 0;
            background: transparent;
            border: none;
        }
CSS;

    $cssReplace = <<<'CSS'
        .details-content {
            display: none;
            padding: 6px 0 8px 0;
            margin: 0 0 8px 0;
            background: transparent;
            border: none;
        }

        .details-content.validation-report-panel {
            margin: 8px 0 8px 0;
            padding: 8px 10px 10px 10px;
            background: rgba(46, 139, 87, 0.18);
            border: 2px solid rgba(46, 139, 87, 0.85);
            border-radius: 8px;
            max-width: 970px;
            box-sizing: border-box;
        }
CSS;

    $src = prw_replace_once('weekly validation green panel css', $src, $cssNeedle, $cssReplace, $errors);

    $src = prw_replace_once(
        'weekly body class',
        $src,
        "<body>\n",
        "<body class=\"<?php echo (!empty(\$selectedReleaseStatus['class']) && (string)\$selectedReleaseStatus['class'] === 'superseded') ? 'release-superseded-view' : ''; ?>\">\n",
        $errors
    );

    $src = prw_replace_once(
        'weekly pending review panel text',
        $src,
        "            Results generated automatically. Pending league review.\n",
        "            <?php echo rrsg_h(\$selectedReleaseManualReason !== '' ? \$selectedReleaseManualReason : 'Pending review has been manually applied to this release.'); ?>\n",
        $errors
    );

    $src = str_replace(
        '<div class="details-content" id="detailsContent">',
        '<div class="details-content validation-report-panel" id="detailsContent">',
        $src
    );

    return $src;
}

function build_monitor(string $src, array &$errors): string {
    $src = prw_regex_once('monitor header version', $src, '/\* VERSION: v\d{3}/', '* VERSION: v137', $errors);
    $src = prw_regex_once('monitor header modified', $src, '/\* LAST MODIFIED: .*?\n/', '* LAST MODIFIED: 7/5/2026 12:47:15 am' . "\n", $errors);

    if (strpos($src, 'v137 (7/5/2026 12:47:15 am)') === false) {
        $src = prw_replace_once(
            'monitor changelog anchor',
            $src,
            " * CHANGELOG:\n *\n",
            " * CHANGELOG:\n *\n"
            . " * v137 (7/5/2026 12:47:15 am)\n"
            . " *   - CHANGE: Final race snapshots no longer automatically create under_review.flag.\n"
            . " *   - CHANGE: Initial weekly release-history records are now released/current by default; Pending Review is manual release metadata.\n"
            . " *\n",
            $errors
        );
    }

    $src = str_replace(
        "    touch(\$raceFolder . '/under_review.flag');\n",
        "    // v137: do not automatically create under_review.flag on normal final capture.\n",
        $src
    );

    $src = str_replace(
        "            'under_review' => is_file(\$raceFolder . '/under_review.flag'),",
        "            'under_review' => false,\n            'status' => 'released',\n            'public_status' => 'Official standings release',",
        $src
    );

    $src = str_replace(
        "'builder_note' => 'Automatically recorded by race_results_monitor after first final-result snapshot capture.',",
        "'builder_note' => 'Automatically recorded by race_results_monitor after first final-result snapshot capture. Pending Review is now manual release metadata only.',",
        $src
    );

    return $src;
}

function build_revision(string $src, array &$errors): string {
    $src = prw_regex_once('revision header version', $src, '/\* VERSION: v\d{3}/', '* VERSION: v012', $errors);
    $src = prw_regex_once('revision header modified', $src, '/\* LAST MODIFIED: .*?\n/', '* LAST MODIFIED: 7/5/2026 12:47:15 am' . "\n", $errors);

    if (strpos($src, 'v012 (7/5/2026 12:47:15 am)') === false) {
        $src = prw_replace_once(
            'revision changelog anchor',
            $src,
            " * CHANGELOG:\n",
            " * CHANGELOG:\n"
            . " * v012 (7/5/2026 12:47:15 am)\n"
            . " *   - CHANGE: Detected revisions no longer automatically create or remove under_review.flag.\n"
            . " *   - CHANGE: Release-history records remain current/released by default while preserving review_required/review_suggested metadata for audit.\n"
            . " *   - CHANGE: Manual Pending Review is controlled through weekly_pending_review_admin.php.\n",
            $errors
        );
    }

    $oldBlock = <<<'PHP'
    // 5. Create under_review.flag only when review is required.
    $flagPath = $raceFolder . '/under_review.flag';
    if ($reviewRequired) {
        rr_atomic_write($flagPath, "");
        rr_log_line($logFile, "UNDER_REVIEW FLAG SET folder={$folderName} reason={$status}");
        rrrev_out("  under_review.flag created.");
    } else {
        if (is_file($flagPath)) {
            @unlink($flagPath);
            rr_log_line($logFile, "UNDER_REVIEW FLAG REMOVED folder={$folderName} reason={$status}");
            rrrev_out("  under_review.flag removed/not required.");
        } else {
            rr_log_line($logFile, "UNDER_REVIEW FLAG NOT SET folder={$folderName} reason={$status}");
            rrrev_out("  under_review.flag not required.");
        }
    }
PHP;

    $newBlock = <<<'PHP'
    // 5. v012: Pending Review is now manual release-level metadata.
    // The classifier may still mark review_required/review_suggested in revision_meta.json,
    // but this script no longer creates or removes under_review.flag automatically.
    $flagPath = $raceFolder . '/under_review.flag';
    rr_log_line($logFile, "UNDER_REVIEW FLAG UNCHANGED folder={$folderName} reviewSuggested=" . ($reviewRequired ? 'YES' : 'NO') . " reason={$status}");
    rrrev_out("  under_review.flag unchanged; Pending Review is manual release metadata.");
PHP;

    $src = prw_replace_once('revision under_review flag block', $src, $oldBlock, $newBlock, $errors);

    $src = str_replace(
        "        'pending_review' => $reviewRequired,\n        'under_review_flag' => $reviewRequired,",
        "        'pending_review' => false,\n        'under_review_flag' => is_file($flagPath),\n        'review_suggested' => $reviewRequired,",
        $src
    );

    $src = str_replace(
        "            'status' => $reviewRequired ? 'pending_review' : 'released',\n            'snapshot_file' => (string)$currentSnapshotBase,",
        "            'status' => 'released',\n            'public_status' => 'Official standings release',\n            'review_suggested' => $reviewRequired,\n            'snapshot_file' => (string)$currentSnapshotBase,",
        $src
    );

    $src = str_replace(
        "            'under_review' => $reviewRequired,\n            'mrl_impact' => $mrlImpact,",
        "            'under_review' => false,\n            'pending_review' => false,\n            'mrl_impact' => $mrlImpact,",
        $src
    );

    return $src;
}

function build_admin_page(): string {
    return <<<'PHP'
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
PHP;
}

// ---------------- PRELOAD ----------------
$weekly = prw_read_file($files['weekly'], 'weekly_standings.php', $errors);
$monitor = prw_read_file($files['monitor'], 'race_results_monitor.php', $errors);
$revision = prw_read_file($files['revision'], 'race_results_revision_monitor.php', $errors);

$newWeekly = $weekly !== '' ? build_weekly($weekly, $errors) : '';
$newMonitor = $monitor !== '' ? build_monitor($monitor, $errors) : '';
$newRevision = $revision !== '' ? build_revision($revision, $errors) : '';
$adminPage = build_admin_page();

if ($newWeekly === $weekly) {
    $preflight[] = 'weekly_standings.php: no text change detected after patch build.';
}
if ($newMonitor === $monitor) {
    $preflight[] = 'race_results_monitor.php: no text change detected after patch build.';
}
if ($newRevision === $revision) {
    $preflight[] = 'race_results_revision_monitor.php: no text change detected after patch build.';
}

foreach ([$files['weekly'], $files['monitor'], $files['revision']] as $path) {
    if (is_file($path) && !is_writable($path)) {
        $errors[] = 'File is not writable: ' . basename($path);
    }
}

if (!is_writable($baseDir)) {
    $errors[] = 'Directory is not writable: ' . $baseDir;
}

if (!empty($errors)) {
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><title>Pending Review Installer - Preflight Failed</title>
    <style>
    body{font-family:Arial,Helvetica,sans-serif;font-size:18px;margin:18px;}
    .err{background:#f8d7da;border:1px solid #f5a5ad;border-radius:8px;padding:12px 16px;}
    .warn{background:#fff3cd;border:1px solid #d6b656;border-radius:8px;padding:12px 16px;margin-top:14px;}
    code{font-family:Consolas,monospace;}
    </style></head><body>
    <h1>Pending Review Release Workflow Installer</h1>
    <h2>Preflight: NO-GO</h2>
    <div class="err">
        <strong>ERROR</strong>
        <ul><?php foreach ($errors as $e): ?><li><?php echo prw_h($e); ?></li><?php endforeach; ?></ul>
    </div>
    <?php if (!empty($preflight)): ?>
    <div class="warn">
        <strong>Notes</strong>
        <ul><?php foreach ($preflight as $p): ?><li><?php echo prw_h($p); ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <p>No files were modified by this installer run.</p>
    </body></html>
    <?php
    exit;
}

// ---------------- INSTALL ----------------
$ok = true;

foreach (['weekly', 'monitor', 'revision'] as $key) {
    $ok = prw_backup_file($files[$key], $report) && $ok;
}
if ($ok) {
    $ok = prw_write_file($files['weekly'], $newWeekly, $report) && $ok;
    $ok = prw_write_file($files['monitor'], $newMonitor, $report) && $ok;
    $ok = prw_write_file($files['revision'], $newRevision, $report) && $ok;
    $ok = prw_write_file($files['admin'], $adminPage, $report) && $ok;
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Pending Review Installer</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;font-size:18px;margin:18px;}
.ok{background:#e8f5e9;border:1px solid #2e8b57;border-radius:8px;padding:12px 16px;}
.err{background:#f8d7da;border:1px solid #f5a5ad;border-radius:8px;padding:12px 16px;}
code{font-family:Consolas,monospace;}
</style>
</head>
<body>
<h1>Pending Review Release Workflow Installer</h1>
<div class="<?php echo $ok ? 'ok' : 'err'; ?>">
    <strong><?php echo $ok ? 'SUCCESS' : 'ERROR'; ?></strong>
    <ul>
        <li>Preflight passed.</li>
        <?php foreach ($report as $line): ?><li><?php echo prw_h($line); ?></li><?php endforeach; ?>
    </ul>
</div>

<h2>Installed Behavior</h2>
<ul>
    <li>Normal race completion no longer automatically applies Pending Review.</li>
    <li>Revision monitor no longer changes under_review.flag automatically.</li>
    <li>Pending Review is now manually controlled at release level in <code>weekly_pending_review_admin.php</code>.</li>
    <li>Manual add/remove actions are written into each release's <code>pending_review_events</code> history.</li>
    <li>Weekly Standings shows Pending Review from selected release metadata.</li>
    <li>Superseded Weekly Standings releases receive a translucent red page tint.</li>
</ul>

<p>Open <code>weekly_pending_review_admin.php</code> and <code>weekly_standings.php</code>. After successful testing, delete this installer.</p>
</body>
</html>
