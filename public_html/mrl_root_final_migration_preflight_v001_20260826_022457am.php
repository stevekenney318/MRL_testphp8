<?php
/**
 * MRL Root Final Migration Preflight
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 2:24:57 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 2:24:57 am)
 * - Initial package-aware final preflight for TESTPHP8 root -> LIVE root migration.
 * - Treats dependencies supplied by the same proposed migration package as satisfied.
 * - Verifies all 15 proposed MIGRATE files by exact TEST hash/version.
 * - Verifies all existing LIVE targets against the 8/26 classification-audit baseline.
 * - Confirms LIVE /race_results/race_schedule_helper.php remains available.
 * - Keeps LIVE email.php and all environment-specific config/database/WP files untouched.
 * - Performs targeted active-reference and purpose scans for the three REVIEW files:
 *     default.php
 *     logout.php
 *     rebuild_year_index.php
 * - Treats races.html's reference inside race_countdown.html as a comment-only legacy mention,
 *   not an active dependency.
 * - Confirms race_countdown.html is present as the current Live countdown presentation.
 * - Provides timestamped JSON and TXT exports.
 * - Makes NO filesystem, database, WordPress, or scheduler changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 *
 * SAFETY:
 * - READ ONLY.
 * - Live scheduler may remain running.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_RFM_VERSION = 'v001';
const MRL_RFM_TITLE = 'MRL Root Final Migration Preflight';

function rfm_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rfm_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function rfm_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function rfm_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function rfm_version(string $p): string {
    $t = rfm_read($p);
    if ($t === '') return '';
    foreach ([
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/'
    ] as $rx) {
        if (preg_match($rx, $t, $m)) return strtolower((string)$m[1]);
    }
    return '';
}

function rfm_info(string $path): ?array {
    if (!is_file($path)) return null;
    $size = @filesize($path);
    $mtime = @filemtime($path);
    return [
        'path' => rfm_norm($path),
        'version' => rfm_version($path),
        'sha256' => rfm_sha($path),
        'size' => is_int($size) ? $size : null,
        'mtime' => is_int($mtime) ? date('Y-m-d H:i:s T', $mtime) : null,
    ];
}

function rfm_extract_dependencies(string $path): array {
    $deps = [];
    $text = rfm_read($path);
    if ($text === '') return $deps;

    $lines = preg_split('/\R/', $text);
    if (!is_array($lines)) return $deps;

    foreach ($lines as $i => $line) {
        if (!preg_match('/\b(require|require_once|include|include_once)\b/i', $line)) continue;

        if (preg_match_all('/[\'"]([^\'"]+\.php)[\'"]/', $line, $m)) {
            foreach ($m[1] as $dep) {
                $deps[] = [
                    'line' => $i + 1,
                    'dependency' => $dep,
                    'text' => trim($line),
                ];
            }
        }
    }

    return $deps;
}

function rfm_dependency_status(string $dep, string $liveRoot, array $packageNames): array {
    $clean = str_replace('\\', '/', $dep);
    $base = basename($clean);

    if (in_array($base, $packageNames, true)) {
        return [
            'dependency' => $dep,
            'status' => 'SATISFIED_BY_PACKAGE',
            'exists_now' => is_file($liveRoot . '/' . $base),
            'resolved_path' => rfm_norm($liveRoot . '/' . $base),
        ];
    }

    if (strpos($clean, '/race_results/') === 0) {
        $candidate = rtrim($liveRoot, '/') . $clean;
        return [
            'dependency' => $dep,
            'status' => is_file($candidate) ? 'SATISFIED_LIVE' : 'MISSING',
            'exists_now' => is_file($candidate),
            'resolved_path' => rfm_norm($candidate),
        ];
    }

    $candidate = $liveRoot . '/' . ltrim($clean, '/');
    return [
        'dependency' => $dep,
        'status' => is_file($candidate) ? 'SATISFIED_LIVE' : 'MISSING',
        'exists_now' => is_file($candidate),
        'resolved_path' => rfm_norm($candidate),
    ];
}

function rfm_scan_root_references(string $root, string $needle): array {
    $hits = [];
    $items = @scandir($root);
    if (!is_array($items)) return $hits;

    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $root . '/' . $name;
        if (!is_file($path)) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['php','html','htm','js','css'], true)) continue;

        if (preg_match('/install|audit|preflight|diagnostic|backup|cleanup|clean_|test_|_test|debug/i', $name)) {
            continue;
        }

        $text = rfm_read($path);
        if ($text === '') continue;
        $lines = preg_split('/\R/', $text);
        if (!is_array($lines)) continue;

        foreach ($lines as $i => $line) {
            if (stripos($line, $needle) !== false) {
                $trim = trim($line);
                $commentOnly = false;

                if (preg_match('#^\s*(//|/\*|\*|<!--)#', $trim)) {
                    $commentOnly = true;
                }

                $hits[] = [
                    'file' => $name,
                    'line' => $i + 1,
                    'text' => $trim,
                    'comment_only' => $commentOnly,
                ];
            }
        }
    }

    return $hits;
}

function rfm_keyword_summary(string $path): array {
    $text = rfm_read($path);
    $out = [
        'exists' => is_file($path),
        'size' => is_file($path) ? @filesize($path) : null,
        'contains_form' => false,
        'contains_header_location' => false,
        'contains_session' => false,
        'contains_database_terms' => false,
        'contains_race_results_engine' => false,
        'contains_year_index_terms' => false,
        'contains_login_logout_terms' => false,
    ];
    if ($text === '') return $out;

    $out['contains_form'] = stripos($text, '<form') !== false;
    $out['contains_header_location'] = stripos($text, 'header(') !== false && stripos($text, 'location') !== false;
    $out['contains_session'] = stripos($text, 'session') !== false;
    $out['contains_database_terms'] = (bool)preg_match('/\b(PDO|mysqli|SELECT|INSERT|UPDATE|DELETE)\b/i', $text);
    $out['contains_race_results_engine'] = stripos($text, 'race_results_engine.php') !== false;
    $out['contains_year_index_terms'] = (bool)preg_match('/year[_\s-]*index|rebuild.*year|race_results_engine/i', $text);
    $out['contains_login_logout_terms'] = (bool)preg_match('/logout|login|session_destroy|unset\s*\(/i', $text);

    return $out;
}

/* Environment */
$selfDir = rtrim(rfm_norm(__DIR__), '/');
$expected = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$testRoot = $selfDir;
$liveRoot = dirname($testRoot);

$generatedAt = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

/* Exact package from prior classification audit */
$package = [
    'admin_pick_adjustment.php' => [
        'test_version' => 'v002',
        'test_sha256' => '512b63908bdbc3ec73af979fb4f74e7de887a10d0a593277dcdc8326791bae71',
        'live_sha256' => '',
    ],
    'admin_setup.php' => [
        'test_version' => 'v004',
        'test_sha256' => 'a9c2b6f462d903af44adf0007acca73af8d6e942b034e211a40e2d60f29d50ea',
        'live_sha256' => '9aa3ca2ff447434b8c5e48b3dd16e7ff6362375c4c337d1d1434d41ad7905ca0',
    ],
    'config_mrl.php' => [
        'test_version' => 'v003',
        'test_sha256' => '34eef0d70d79e243c124239bb361a2fd4f1ab0bcca6b93141be0c75a0f40543a',
        'live_sha256' => 'c5429e99a4d297ac421526134f3fcadd727efdfcbf334a2c8a65a3151977084c',
    ],
    'current_segment_chart.php' => [
        'test_version' => 'v007',
        'test_sha256' => '9a9550d334d18b828c9173f37299f428d84d2edc804b049cf4b0703cbec9680c',
        'live_sha256' => 'd19a1dad1e83c94b17fd438d39581ec6da14b58e00b4e60270fbf07327ce78e0',
    ],
    'current_segment_chart_by_entry_time.php' => [
        'test_version' => 'v004',
        'test_sha256' => '1d572c08a85fb90eb243874995195b8653b5bdc3193100fb26e8468fe26b7fe4',
        'live_sha256' => '632beefb0ec63d6607d8f5178cebd551ad5c5aad240ddb670403ddf54b76cf16',
    ],
    'current_user_team_chart.php' => [
        'test_version' => 'v005',
        'test_sha256' => '7176e440667b06ea6b18e3401e9e2973c52fdf40362c8811e2ef3fc2c1e130ae',
        'live_sha256' => '55d9cbf5abcf20f195cf083cfadd61dd1e5e6e9108d631add6667d3a068c80a9',
    ],
    'form-team-picks.php' => [
        'test_version' => 'v007',
        'test_sha256' => '7d27ae72f865243ecca758a5403cc75bd370dbbf2f6f2cca820bcc41ef7042b5',
        'live_sha256' => '',
    ],
    'pick_window_helper.php' => [
        'test_version' => 'v003',
        'test_sha256' => 'e73a04a0847d38d3329814fe616e647052e90194acc92e9b886fb1c0c7d8e3dc',
        'live_sha256' => '',
    ],
    'prior_year_user_team_chart.php' => [
        'test_version' => 'v004',
        'test_sha256' => '1047c91029327e91f5edca9705d151293ef74abcc0bde8935fcba0804cc548cb',
        'live_sha256' => '5d2cd620457af785a8711689dc25e8e1729bdab052a56a9067cc045750cf5714',
    ],
    'submit-team-picks.php' => [
        'test_version' => 'v011',
        'test_sha256' => '797bcfe1f8f9155d04573fde40aa551b384156c0e889f411b1e198e89ddc97d1',
        'live_sha256' => '',
    ],
    'submitted_teams_count.php' => [
        'test_version' => 'v001',
        'test_sha256' => 'bf82df10b92d31c17cca5a380cace80d846f6da7788b1bdffe1f644b9a7df66f',
        'live_sha256' => '083e648694d737b45820917e30f0bc95e274c3f8dc727ef61a149992855812a2',
    ],
    'team-late-pick.php' => [
        'test_version' => 'v005',
        'test_sha256' => '89233a4e25774a521b75193217c2dd74c82215428ab171c5c75cf230bd351dcb',
        'live_sha256' => '506afb336a2a10658df13d2e10f2e415cceef113d7a53ddd56d6479d58875b43',
    ],
    'team.php' => [
        'test_version' => 'v034',
        'test_sha256' => 'ea4542182638143549230d4b47ec51e6148ff018d0a799c1a8c7af0971cfcdfc',
        'live_sha256' => '68ccd765b93bc61be0d528ab6c9be64c8fcf85bfd6f1df22da04907b0b32d384',
    ],
    'team_chart.php' => [
        'test_version' => 'v018',
        'test_sha256' => 'bc546f3b99855ff85fed0342090ced35977da8ddc1171ff676a904cef57255e1',
        'live_sha256' => '9467e26e7cc4a74ea889ed1f6dab0f8d04bb830f67524905c5d7bc1b3b1117a0',
    ],
    'team_replacement_driver.php' => [
        'test_version' => 'v010',
        'test_sha256' => 'e7a30d2768c249b8bd993ea81fa2845b85284c7bb0987a57acef0be66f8c87f1',
        'live_sha256' => '',
    ],
];

$packageNames = array_keys($package);

$rows = [];
$sourceGate = true;
$liveBaselineGate = true;
$dependencyGate = true;
$dependencyProblems = [];

foreach ($package as $name => $expectedInfo) {
    $testPath = $testRoot . '/' . $name;
    $livePath = $liveRoot . '/' . $name;

    $testInfo = rfm_info($testPath);
    $liveInfo = rfm_info($livePath);

    $sourceOk = $testInfo !== null
        && strtolower((string)$testInfo['version']) === strtolower($expectedInfo['test_version'])
        && $testInfo['sha256'] === $expectedInfo['test_sha256'];

    if ($expectedInfo['live_sha256'] === '') {
        $liveOk = $liveInfo === null;
    } else {
        $liveOk = $liveInfo !== null && $liveInfo['sha256'] === $expectedInfo['live_sha256'];
    }

    if (!$sourceOk) $sourceGate = false;
    if (!$liveOk) $liveBaselineGate = false;

    $deps = [];
    foreach (rfm_extract_dependencies($testPath) as $dep) {
        $status = rfm_dependency_status($dep['dependency'], $liveRoot, $packageNames);
        $status['line'] = $dep['line'];
        $status['text'] = $dep['text'];
        $deps[] = $status;

        if ($status['status'] === 'MISSING') {
            $dependencyGate = false;
            $dependencyProblems[] = [
                'file' => $name,
                'dependency' => $dep['dependency'],
                'resolved_path' => $status['resolved_path'],
            ];
        }
    }

    $rows[] = [
        'path' => $name,
        'test' => $testInfo,
        'live' => $liveInfo,
        'source_matches_baseline' => $sourceOk,
        'live_matches_baseline' => $liveOk,
        'dependencies' => $deps,
    ];
}

/* Fixed decisions */
$preserve = [
    'email.php' => [
        'action' => 'KEEP_LIVE',
        'reason' => 'LIVE v002 is newer/versioned and is not overwritten by the migration package.',
        'live' => rfm_info($liveRoot . '/email.php'),
    ],
    'conf.inc.php' => [
        'action' => 'KEEP_LIVE_ENVIRONMENT',
        'reason' => 'Environment-specific values differ; structure matched in prior redacted audit.',
        'live' => rfm_info($liveRoot . '/conf.inc.php'),
    ],
    'config.php' => [
        'action' => 'KEEP_LIVE_ENVIRONMENT',
        'reason' => 'Database/environment-specific values differ; structure matched in prior redacted audit.',
        'live' => rfm_info($liveRoot . '/config.php'),
    ],
    'dbconfig.php' => [
        'action' => 'KEEP_LIVE_ENVIRONMENT',
        'reason' => 'Database/environment-specific values differ; structure matched in prior redacted audit.',
        'live' => rfm_info($liveRoot . '/dbconfig.php'),
    ],
    'wp-config.php' => [
        'action' => 'KEEP_LIVE_ENVIRONMENT',
        'reason' => 'WordPress environment/credential file; never copied from TESTPHP8.',
        'live' => rfm_info($liveRoot . '/wp-config.php'),
    ],
];

/* Review items */
$reviewNames = ['default.php', 'logout.php', 'rebuild_year_index.php'];
$review = [];

foreach ($reviewNames as $name) {
    $testPath = $testRoot . '/' . $name;
    $livePath = $liveRoot . '/' . $name;

    $review[$name] = [
        'test' => rfm_info($testPath),
        'live' => rfm_info($livePath),
        'test_keyword_summary' => rfm_keyword_summary($testPath),
        'live_keyword_summary' => rfm_keyword_summary($livePath),
        'live_active_references_to_name' => rfm_scan_root_references($liveRoot, $name),
    ];
}

/* Schedule presentation */
$racesHits = rfm_scan_root_references($liveRoot, 'races.html');
$racesActiveHits = array_values(array_filter($racesHits, static function ($hit) {
    return !$hit['comment_only'] && $hit['file'] !== 'races.html';
}));

$countdownHits = rfm_scan_root_references($liveRoot, 'race_countdown.html');

$schedulePresentation = [
    'races_html' => [
        'live' => rfm_info($liveRoot . '/races.html'),
        'all_mentions' => $racesHits,
        'active_non_comment_reference_count' => count($racesActiveHits),
        'active_non_comment_references' => $racesActiveHits,
        'classification' => count($racesActiveHits) === 0 ? 'LEGACY_OR_UTILITY' : 'REVIEW',
    ],
    'race_countdown_html' => [
        'live' => rfm_info($liveRoot . '/race_countdown.html'),
        'mentions' => $countdownHits,
        'classification' => is_file($liveRoot . '/race_countdown.html') ? 'CURRENT_PRESENTATION' : 'MISSING',
    ],
];

$overall = $expected && $sourceGate && $liveBaselineGate && $dependencyGate;

$report = [
    'report' => MRL_RFM_TITLE,
    'report_version' => MRL_RFM_VERSION,
    'generated_at' => $generatedAt,
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'read_only' => true,
    'expected_location' => $expected,
    'overall' => [
        'status' => $overall ? 'PASS - PACKAGE READY FOR INSTALLER DESIGN' : 'HOLD - REVIEW REQUIRED',
        'source_hash_gate' => $sourceGate ? 'PASS' : 'FAIL',
        'live_baseline_gate' => $liveBaselineGate ? 'PASS' : 'FAIL',
        'package_aware_dependency_gate' => $dependencyGate ? 'PASS' : 'FAIL',
    ],
    'proposed_migration_package' => [
        'file_count' => count($packageNames),
        'files' => $rows,
        'dependency_problems' => $dependencyProblems,
    ],
    'preserve_live' => $preserve,
    'review_items' => $review,
    'schedule_presentation' => $schedulePresentation,
    'future_relocation_rules' => [
        'yearly_team_charts' => '/team_charts/',
        'yearly_league_info' => '/league_info/',
        'performed_now' => false,
    ],
    'warnings' => $expected ? [] : [
        'This script is not running from the expected /public_html/testphp8 location.'
    ],
];

function rfm_txt(array $report): string {
    $out = [];
    $out[] = MRL_RFM_TITLE;
    $out[] = 'Version: ' . MRL_RFM_VERSION;
    $out[] = 'Generated: ' . $report['generated_at'];
    $out[] = 'Read only: YES';
    $out[] = '';
    $out[] = 'OVERALL: ' . $report['overall']['status'];
    $out[] = 'Source hash gate: ' . $report['overall']['source_hash_gate'];
    $out[] = 'LIVE baseline gate: ' . $report['overall']['live_baseline_gate'];
    $out[] = 'Package dependency gate: ' . $report['overall']['package_aware_dependency_gate'];
    $out[] = '';
    $out[] = 'PACKAGE FILES: ' . $report['proposed_migration_package']['file_count'];
    foreach ($report['proposed_migration_package']['files'] as $row) {
        $out[] = '- ' . $row['path'];
    }
    $out[] = '';
    $out[] = 'REVIEW ITEMS';
    foreach ($report['review_items'] as $name => $data) {
        $out[] = '- ' . $name . ' | live refs: ' . count($data['live_active_references_to_name']);
    }
    $out[] = '';
    $out[] = 'races.html active non-comment refs: ' . $report['schedule_presentation']['races_html']['active_non_comment_reference_count'];
    return implode("\r\n", $out) . "\r\n";
}

$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_root_final_migration_preflight_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_root_final_migration_preflight_' . $stamp . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo rfm_txt($report);
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
<title><?= rfm_h(MRL_RFM_TITLE) ?></title>
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
    <h1><?= rfm_h(MRL_RFM_TITLE) ?></h1>
    <div class="small">v001 · <?= rfm_h($generatedAt) ?> · READ ONLY</div>

    <div class="summary">
        <div class="pill">Overall: <span class="<?= $overall ? 'pass' : 'fail' ?>"><?= rfm_h($report['overall']['status']) ?></span></div>
        <div class="pill">Package files: <span class="info"><?= count($packageNames) ?></span></div>
        <div class="pill">Source hashes: <span class="<?= $sourceGate ? 'pass' : 'fail' ?>"><?= $sourceGate ? 'PASS' : 'FAIL' ?></span></div>
        <div class="pill">LIVE baselines: <span class="<?= $liveBaselineGate ? 'pass' : 'fail' ?>"><?= $liveBaselineGate ? 'PASS' : 'FAIL' ?></span></div>
        <div class="pill">Dependencies: <span class="<?= $dependencyGate ? 'pass' : 'fail' ?>"><?= $dependencyGate ? 'PASS' : 'FAIL' ?></span></div>
    </div>

    <a class="button" href="?format=json&x=<?= rfm_h((string)microtime(true)) ?>">Download JSON Results</a>
    <a class="button" href="?format=txt&x=<?= rfm_h((string)microtime(true)) ?>">Download TXT Results</a>
</div>

<div class="panel">
    <h2>Proposed 15-file migration package</h2>
    <table>
        <thead><tr><th>Path</th><th>TEST</th><th>LIVE</th><th>Source hash</th><th>LIVE baseline</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><code><?= rfm_h($row['path']) ?></code></td>
                <td><?= rfm_h(($row['test']['version'] ?? '') ?: 'MISSING') ?></td>
                <td><?= rfm_h(($row['live']['version'] ?? '') ?: ($row['live'] === null ? 'MISSING' : '(none)')) ?></td>
                <td class="<?= $row['source_matches_baseline'] ? 'pass' : 'fail' ?>"><?= $row['source_matches_baseline'] ? 'MATCH' : 'MISMATCH' ?></td>
                <td class="<?= $row['live_matches_baseline'] ? 'pass' : 'fail' ?>"><?= $row['live_matches_baseline'] ? 'UNCHANGED' : 'DRIFTED' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2>Package-aware dependency check</h2>
    <?php if (!$dependencyProblems): ?>
        <p class="pass">PASS — all dependencies are already on LIVE or are supplied by this same 15-file package.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>File</th><th>Dependency</th><th>Resolved path</th></tr></thead>
            <tbody>
            <?php foreach ($dependencyProblems as $p): ?>
                <tr>
                    <td><?= rfm_h($p['file']) ?></td>
                    <td><?= rfm_h($p['dependency']) ?></td>
                    <td><?= rfm_h($p['resolved_path']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Files explicitly preserved on LIVE</h2>
    <ul>
        <li><code>email.php</code> — keep LIVE version.</li>
        <li><code>conf.inc.php</code> — keep LIVE environment values.</li>
        <li><code>config.php</code> — keep LIVE database/environment values.</li>
        <li><code>dbconfig.php</code> — keep LIVE database/environment values.</li>
        <li><code>wp-config.php</code> — keep LIVE WordPress environment/credentials.</li>
    </ul>
</div>

<div class="panel">
    <h2>Three REVIEW files</h2>
    <table>
        <thead><tr><th>File</th><th>TEST present</th><th>LIVE present</th><th>LIVE root references</th></tr></thead>
        <tbody>
        <?php foreach ($review as $name => $data): ?>
            <tr>
                <td><code><?= rfm_h($name) ?></code></td>
                <td><?= $data['test'] !== null ? 'YES' : 'NO' ?></td>
                <td><?= $data['live'] !== null ? 'YES' : 'NO' ?></td>
                <td><?= count($data['live_active_references_to_name']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="small">JSON export includes non-secret keyword summaries and reference locations for these three files.</p>
</div>

<div class="panel">
    <h2>Schedule presentation</h2>
    <table>
        <thead><tr><th>File</th><th>LIVE present</th><th>Active non-comment refs</th><th>Classification</th></tr></thead>
        <tbody>
        <tr>
            <td><code>races.html</code></td>
            <td><?= is_file($liveRoot . '/races.html') ? 'YES' : 'NO' ?></td>
            <td><?= count($racesActiveHits) ?></td>
            <td><?= rfm_h($schedulePresentation['races_html']['classification']) ?></td>
        </tr>
        <tr>
            <td><code>race_countdown.html</code></td>
            <td><?= is_file($liveRoot . '/race_countdown.html') ? 'YES' : 'NO' ?></td>
            <td>—</td>
            <td><?= rfm_h($schedulePresentation['race_countdown_html']['classification']) ?></td>
        </tr>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2>Safety</h2>
    <ul>
        <li>No files are changed.</li>
        <li>No database changes.</li>
        <li>No WordPress changes.</li>
        <li>No scheduler changes.</li>
        <li>Live scheduler may remain running.</li>
        <li>Yearly team-chart and league-info relocation remains a later cleanup phase.</li>
    </ul>
</div>

</div>
</body>
</html>
