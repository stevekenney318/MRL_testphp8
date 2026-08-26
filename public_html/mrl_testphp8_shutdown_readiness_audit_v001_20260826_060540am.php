<?php
/**
 * MRL TESTPHP8 Shutdown Readiness Audit
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 6:05:40 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 6:05:40 am)
 * - Initial read-only final shutdown-readiness audit for TESTPHP8.
 * - Recursively compares TESTPHP8 against LIVE.
 * - Recognizes the completed 15-file root migration as VERIFIED_MIGRATED.
 * - Treats TESTPHP8 /race_results as FROZEN_REFERENCE.
 * - Carries forward the four mandatory shutdown-review files:
 *     default.php
 *     rebuild_year_index.php
 *     logout.php
 *     races.html
 * - Preserves environment-specific config files as intentional differences.
 * - Separates obvious installers/audits/diagnostics/backups/test debris.
 * - Tags yearly team-chart and league-info history for later relocation.
 * - Scans LIVE for references to the four held files.
 * - Reports any other unexplained TEST-only/newer/different files.
 * - Provides JSON/TXT exports.
 * - Makes NO changes.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_TSRA_VERSION = 'v001';
const MRL_TSRA_TITLE = 'MRL TESTPHP8 Shutdown Readiness Audit';

function tsra_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function tsra_norm(string $p): string { return str_replace('\\', '/', $p); }

function tsra_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function tsra_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function tsra_version(string $p): string {
    $text = tsra_read($p);
    if ($text === '') return '';
    foreach ([
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/'
    ] as $rx) {
        if (preg_match($rx, $text, $m)) return strtolower((string)$m[1]);
    }
    return '';
}

function tsra_version_num(string $v): ?int {
    if (preg_match('/^v(\d{3})$/i', trim($v), $m)) return (int)$m[1];
    return null;
}

function tsra_info(string $p): ?array {
    if (!is_file($p)) return null;
    $size = @filesize($p);
    $mtime = @filemtime($p);
    return [
        'path' => tsra_norm($p),
        'version' => tsra_version($p),
        'sha256' => tsra_sha($p),
        'size' => is_int($size) ? $size : null,
        'mtime' => is_int($mtime) ? date('Y-m-d H:i:s T', $mtime) : null,
        'mtime_epoch' => is_int($mtime) ? $mtime : null,
    ];
}

function tsra_allowed_extension(string $name): bool {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, ['php','html','htm','css','js','json','md','txt'], true);
}

function tsra_is_tooling_or_debris(string $relative): bool {
    $s = strtolower($relative);
    $patterns = [
        'installer','install_','_install','audit','preflight','diagnostic','debug','probe',
        'cleanup','clean_','migration_','restore_','backup','_backup','.bak',
        '/_migration_backups/','/_retired/','/_quarantine/','/_safe_to_delete',
        '/temp/','/tmp/','/test/','/tests/','scratch','sandbox','preview_','_preview'
    ];
    foreach ($patterns as $pattern) {
        if (strpos($s, $pattern) !== false) return true;
    }
    return false;
}

function tsra_is_yearly_team_chart(string $relative): bool {
    $name = basename($relative);
    if (preg_match('/^(20\d{2})_S\d+_Team_chart\.php$/i', $name)) return true;
    if (preg_match('/^(20\d{2}).*team.*chart.*\.php$/i', $name)) return true;
    if (preg_match('/team.*chart.*20\d{2}.*\.php$/i', $name)) return true;
    return false;
}

function tsra_is_league_info_history(string $relative): bool {
    $name = basename($relative);
    if (!preg_match('/20\d{2}/', $name)) return false;
    return (bool)preg_match('/rules|fees|schedule|league[_ -]?info/i', $name);
}

function tsra_recursive_files(string $root): array {
    $out = [];
    if (!is_dir($root)) return $out;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $rootNorm = rtrim(tsra_norm($root), '/');

    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) continue;
        $full = tsra_norm($fileInfo->getPathname());
        $relative = ltrim(substr($full, strlen($rootNorm)), '/');
        if ($relative === '' || !tsra_allowed_extension($relative)) continue;
        $out[$relative] = $full;
    }

    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function tsra_scan_live_references(string $liveRoot, string $needle): array {
    $hits = [];
    $files = tsra_recursive_files($liveRoot);

    foreach ($files as $relative => $full) {
        if (strpos($relative, 'testphp8/') === 0) continue;
        if (tsra_is_tooling_or_debris($relative)) continue;

        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($ext, ['php','html','htm','js','css'], true)) continue;

        $text = tsra_read($full);
        if ($text === '') continue;

        $lines = preg_split('/\R/', $text);
        if (!is_array($lines)) continue;

        foreach ($lines as $i => $line) {
            if (stripos($line, $needle) === false) continue;
            $trim = trim($line);
            $commentOnly = (bool)preg_match('#^\s*(//|/\*|\*|<!--)#', $trim);
            $hits[] = [
                'file' => $relative,
                'line' => $i + 1,
                'text' => $trim,
                'comment_only' => $commentOnly,
            ];
        }
    }

    return $hits;
}

$selfDir = rtrim(tsra_norm(__DIR__), '/');
$expectedLocation = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$testRoot = $selfDir;
$liveRoot = dirname($testRoot);

$generatedAt = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$migrated15 = [
    'admin_pick_adjustment.php','admin_setup.php','config_mrl.php',
    'current_segment_chart.php','current_segment_chart_by_entry_time.php',
    'current_user_team_chart.php','form-team-picks.php','pick_window_helper.php',
    'prior_year_user_team_chart.php','submit-team-picks.php','submitted_teams_count.php',
    'team-late-pick.php','team.php','team_chart.php','team_replacement_driver.php'
];

$environmentSpecific = ['conf.inc.php','config.php','dbconfig.php','wp-config.php','email.php'];
$shutdownHold = ['default.php','rebuild_year_index.php','logout.php','races.html'];

$testFiles = tsra_recursive_files($testRoot);

$rows = [];
$unexplained = [];
$heldRows = [];

$counts = [
    'total_test_files_considered' => 0,
    'verified_migrated' => 0,
    'identical' => 0,
    'frozen_race_results_reference' => 0,
    'environment_specific' => 0,
    'shutdown_review_hold' => 0,
    'tooling_or_debris' => 0,
    'future_team_chart_relocation' => 0,
    'future_league_info_relocation' => 0,
    'test_only_unexplained' => 0,
    'test_newer_unexplained' => 0,
    'different_unexplained' => 0,
    'live_newer_or_preserve' => 0,
];

foreach ($testFiles as $relative => $testPath) {
    $counts['total_test_files_considered']++;

    $livePath = $liveRoot . '/' . $relative;
    $testInfo = tsra_info($testPath);
    $liveInfo = tsra_info($livePath);

    $classification = '';
    $reason = '';
    $requiresDecision = false;

    if (in_array($relative, $shutdownHold, true)) {
        $classification = 'SHUTDOWN_REVIEW_HOLD';
        $reason = 'Mandatory held item to discuss before TESTPHP8 is sunset.';
        $requiresDecision = true;
        $counts['shutdown_review_hold']++;

    } elseif (in_array($relative, $migrated15, true)
        && $testInfo !== null && $liveInfo !== null
        && $testInfo['sha256'] === $liveInfo['sha256']) {

        $classification = 'VERIFIED_MIGRATED';
        $reason = 'Completed root migration; TEST and LIVE hashes match.';
        $counts['verified_migrated']++;

    } elseif (strpos($relative, 'race_results/') === 0) {
        $classification = 'FROZEN_RACE_RESULTS_REFERENCE';
        $reason = 'race_results migration is complete; future race_results work belongs on LIVE.';
        $counts['frozen_race_results_reference']++;

    } elseif (in_array($relative, $environmentSpecific, true)) {
        $classification = 'ENVIRONMENT_SPECIFIC';
        $reason = 'Intentional TEST/LIVE environment difference; preserve LIVE configuration.';
        $counts['environment_specific']++;

    } elseif (tsra_is_tooling_or_debris($relative)) {
        $classification = 'TOOLING_OR_DEBRIS';
        $reason = 'Installer/audit/diagnostic/backup/test artifact; not production migration code.';
        $counts['tooling_or_debris']++;

    } elseif (tsra_is_yearly_team_chart($relative)) {
        $classification = 'FUTURE_TEAM_CHART_RELOCATION';
        $reason = 'Historical yearly team-chart material; future organization target is /team_charts/.';
        $counts['future_team_chart_relocation']++;

    } elseif (tsra_is_league_info_history($relative)) {
        $classification = 'FUTURE_LEAGUE_INFO_RELOCATION';
        $reason = 'Historical Rules/Fees/Schedule material; future organization target is /league_info/.';
        $counts['future_league_info_relocation']++;

    } elseif ($liveInfo !== null && $testInfo !== null && $liveInfo['sha256'] === $testInfo['sha256']) {
        $classification = 'IDENTICAL';
        $reason = 'TEST and LIVE are byte-for-byte identical.';
        $counts['identical']++;

    } elseif ($liveInfo === null) {
        $classification = 'TEST_ONLY_UNEXPLAINED';
        $reason = 'Exists in TESTPHP8 but not LIVE and is not covered by an established exclusion.';
        $requiresDecision = true;
        $counts['test_only_unexplained']++;

    } else {
        $tv = $testInfo !== null ? tsra_version_num((string)$testInfo['version']) : null;
        $lv = $liveInfo !== null ? tsra_version_num((string)$liveInfo['version']) : null;

        if ($tv !== null && $lv !== null && $tv > $lv) {
            $classification = 'TEST_NEWER_UNEXPLAINED';
            $reason = 'TESTPHP8 has a higher version than LIVE; requires deliberate review.';
            $requiresDecision = true;
            $counts['test_newer_unexplained']++;

        } elseif ($tv !== null && $lv !== null && $lv > $tv) {
            $classification = 'LIVE_NEWER_OR_PRESERVE';
            $reason = 'LIVE version is newer; preserve LIVE unless explicitly reviewed.';
            $counts['live_newer_or_preserve']++;

        } else {
            $classification = 'DIFFERENT_UNEXPLAINED';
            $reason = 'TEST and LIVE differ and no established classification explains the difference.';
            $requiresDecision = true;
            $counts['different_unexplained']++;
        }
    }

    $row = [
        'path' => $relative,
        'classification' => $classification,
        'reason' => $reason,
        'requires_shutdown_decision' => $requiresDecision,
        'test' => $testInfo,
        'live' => $liveInfo,
    ];

    $rows[] = $row;

    if ($requiresDecision) $unexplained[] = $row;
    if ($classification === 'SHUTDOWN_REVIEW_HOLD') $heldRows[] = $row;
}

$heldReferences = [];
foreach ($shutdownHold as $name) {
    $hits = tsra_scan_live_references($liveRoot, $name);
    $activeHits = [];
    foreach ($hits as $hit) {
        if (!$hit['comment_only']) $activeHits[] = $hit;
    }

    $heldReferences[$name] = [
        'all_mentions' => $hits,
        'active_non_comment_mentions' => $activeHits,
        'active_non_comment_count' => count($activeHits),
    ];
}

$otherUnexplained = array_values(array_filter($unexplained, static function ($row) {
    return $row['classification'] !== 'SHUTDOWN_REVIEW_HOLD';
}));

$shutdownReady = $expectedLocation && count($heldRows) === 0 && count($otherUnexplained) === 0;

$report = [
    'report' => MRL_TSRA_TITLE,
    'report_version' => MRL_TSRA_VERSION,
    'generated_at' => $generatedAt,
    'read_only' => true,
    'expected_location' => $expectedLocation,
    'paths' => [
        'test_root' => tsra_norm($testRoot),
        'live_root' => tsra_norm($liveRoot),
    ],
    'authority_rules' => [
        'race_results' => 'LIVE is authoritative; TESTPHP8/race_results is frozen reference only.',
        'live_runtime_data' => 'LIVE remains authoritative for runtime/history/current-season data.',
        'testphp8_role' => 'TESTPHP8 is being sunset after all unique permanent work is consciously handled.',
    ],
    'summary' => $counts,
    'shutdown_status' => [
        'ready_to_sunset' => $shutdownReady,
        'status' => $shutdownReady ? 'PASS - READY TO SUNSET TESTPHP8' : 'HOLD - FINAL DECISIONS STILL REQUIRED',
        'mandatory_held_files_remaining' => count($heldRows),
        'other_unexplained_files_remaining' => count($otherUnexplained),
    ],
    'mandatory_shutdown_review_files' => $heldRows,
    'held_file_live_references' => $heldReferences,
    'other_unexplained_files' => $otherUnexplained,
    'all_rows' => $rows,
    'future_cleanup_rules' => [
        'yearly_team_charts' => '/team_charts/',
        'yearly_league_info' => '/league_info/',
        'performed_by_this_audit' => false,
    ],
    'safety' => [
        'files_changed' => false,
        'database_writes' => false,
        'scheduler_changes' => false,
        'wordpress_changes' => false,
        'race_results_changes' => false,
    ],
];

function tsra_txt(array $r): string {
    $out = [];
    $out[] = MRL_TSRA_TITLE;
    $out[] = 'Version: ' . MRL_TSRA_VERSION;
    $out[] = 'Generated: ' . $r['generated_at'];
    $out[] = 'Read only: YES';
    $out[] = '';
    $out[] = 'STATUS: ' . $r['shutdown_status']['status'];
    $out[] = 'Mandatory held files: ' . $r['shutdown_status']['mandatory_held_files_remaining'];
    $out[] = 'Other unexplained files: ' . $r['shutdown_status']['other_unexplained_files_remaining'];
    $out[] = '';
    $out[] = 'MANDATORY SHUTDOWN REVIEW';
    foreach ($r['mandatory_shutdown_review_files'] as $row) $out[] = '- ' . $row['path'];
    $out[] = '';
    $out[] = 'OTHER UNEXPLAINED FILES';
    foreach ($r['other_unexplained_files'] as $row) $out[] = '- ' . $row['path'] . ' | ' . $row['classification'];
    $out[] = '';
    $out[] = 'SUMMARY';
    foreach ($r['summary'] as $k => $v) $out[] = $k . ': ' . $v;
    return implode("\r\n", $out) . "\r\n";
}

$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_shutdown_readiness_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_shutdown_readiness_' . $stamp . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo tsra_txt($report);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= tsra_h(MRL_TSRA_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1600px;margin:0 auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px;font-size:31px}h2{margin:0 0 13px}.small{color:var(--muted);font-size:13px}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;border:1px solid #4c7ba8;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:8px 8px 0 0}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}
code{color:#bddcff;background:#111318;padding:2px 5px;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1><?= tsra_h(MRL_TSRA_TITLE) ?></h1>
<div class="small">v001 · <?= tsra_h($generatedAt) ?> · READ ONLY</div>

<div class="summary">
<div class="pill">Status: <span class="<?= $shutdownReady ? 'pass' : 'warn' ?>"><?= tsra_h($report['shutdown_status']['status']) ?></span></div>
<div class="pill">Held files: <span class="warn"><?= count($heldRows) ?></span></div>
<div class="pill">Other unexplained: <span class="<?= count($otherUnexplained) === 0 ? 'pass' : 'warn' ?>"><?= count($otherUnexplained) ?></span></div>
<div class="pill">Verified migrated: <span class="pass"><?= $counts['verified_migrated'] ?></span></div>
<div class="pill">Identical: <span class="pass"><?= $counts['identical'] ?></span></div>
<div class="pill">Frozen race_results: <span class="info"><?= $counts['frozen_race_results_reference'] ?></span></div>
<div class="pill">Tooling/debris: <?= $counts['tooling_or_debris'] ?></div>
</div>

<a class="button" href="?format=json&x=<?= tsra_h((string)microtime(true)) ?>">Download JSON Results</a>
<a class="button" href="?format=txt&x=<?= tsra_h((string)microtime(true)) ?>">Download TXT Results</a>
</div>

<div class="panel">
<h2>Mandatory shutdown-review files</h2>
<table>
<thead><tr><th>File</th><th>TEST</th><th>LIVE</th><th>Active LIVE references</th></tr></thead>
<tbody>
<?php foreach ($shutdownHold as $name): ?>
<?php
$row = null;
foreach ($heldRows as $candidate) {
    if ($candidate['path'] === $name) { $row = $candidate; break; }
}
$refCount = $heldReferences[$name]['active_non_comment_count'] ?? 0;
?>
<tr>
<td><code><?= tsra_h($name) ?></code></td>
<td><?= $row && $row['test'] ? tsra_h(($row['test']['version'] ?: 'present')) : 'MISSING' ?></td>
<td><?= $row && $row['live'] ? tsra_h(($row['live']['version'] ?: 'present')) : 'MISSING' ?></td>
<td><?= (int)$refCount ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="panel">
<h2>Other unexplained TESTPHP8 files</h2>
<?php if (count($otherUnexplained) === 0): ?>
<p class="pass">PASS — no additional unexplained TESTPHP8 files remain outside the four mandatory held items.</p>
<?php else: ?>
<table>
<thead><tr><th>Path</th><th>Classification</th><th>TEST</th><th>LIVE</th><th>Reason</th></tr></thead>
<tbody>
<?php foreach ($otherUnexplained as $row): ?>
<tr>
<td><code><?= tsra_h($row['path']) ?></code></td>
<td class="warn"><?= tsra_h($row['classification']) ?></td>
<td><?= $row['test'] ? tsra_h(($row['test']['version'] ?: 'present')) : 'MISSING' ?></td>
<td><?= $row['live'] ? tsra_h(($row['live']['version'] ?: 'present')) : 'MISSING' ?></td>
<td><?= tsra_h($row['reason']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="panel">
<h2>Established shutdown rules</h2>
<ul>
<li><code>/race_results</code> work now belongs on LIVE; TESTPHP8 copy is frozen reference only.</li>
<li>Yearly team charts remain future relocation candidates for <code>/team_charts/</code>.</li>
<li>Yearly Rules / Fees / Schedule material remains future relocation material for <code>/league_info/</code>.</li>
<li>Installers, audits, diagnostics, backups, and migration debris are not production migration candidates.</li>
<li>This audit changes nothing.</li>
</ul>
</div>

</div>
</body>
</html>
