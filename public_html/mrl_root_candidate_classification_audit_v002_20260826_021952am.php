<?php
/**
 * MRL Root Candidate Classification Audit
 *
 * VERSION: v002
 * LAST MODIFIED: 8/26/2026 2:19:52 am
 *
 * CHANGELOG:
 * v002 (8/26/2026 2:19:52 am)
 * - Compatibility correction after HTTP 500 on TESTPHP8.
 * - Removed arrow-function callbacks from runtime code.
 * - Corrected LIVE /race_results dependency resolution.
 * - Added visible fatal/runtime error reporting for this audit page so any future
 *   server-side error is shown directly instead of only returning a blank HTTP 500.
 *
 * v001 (8/26/2026 2:12:18 am)
 * - Initial read-only classification pass for the 25 root migration candidates.
 * - Applies established decisions:
 *     * race_results is already migrated and excluded.
 *     * yearly team charts are historical/future relocation to /team_charts/.
 *     * yearly league-info files are historical/future relocation to /league_info/.
 *     * races.html is treated as likely legacy and checked for active references.
 *     * race_countdown.html is checked as the current active countdown/schedule presentation.
 * - Classifies candidate files into:
 *     MIGRATE
 *     KEEP_LIVE
 *     ENVIRONMENT_SPECIFIC
 *     LEGACY_OR_UTILITY
 *     REVIEW
 * - Performs dependency existence checks for likely permanent application files.
 * - Compares config-like files using redacted structural fingerprints only;
 *   secrets/credentials/values are never exported.
 * - Scans active root PHP/HTML files for references to races.html and race_countdown.html.
 * - Provides timestamped JSON and TXT exports.
 * - Makes NO filesystem, database, WordPress, or scheduler changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_RCC_VERSION = 'v002';
const MRL_RCC_TITLE = 'MRL Root Candidate Classification Audit';

function rcc_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rcc_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function rcc_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function rcc_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function rcc_version(string $p): string {
    $t = rcc_read($p);
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

function rcc_info(string $path): ?array {
    if (!is_file($path)) return null;
    $size = @filesize($path);
    $mtime = @filemtime($path);
    return [
        'path' => rcc_norm($path),
        'version' => rcc_version($path),
        'sha256' => rcc_sha($path),
        'size' => is_int($size) ? $size : null,
        'mtime' => is_int($mtime) ? date('Y-m-d H:i:s T', $mtime) : null,
    ];
}

function rcc_extract_local_php_dependencies(string $path): array {
    $deps = [];
    $text = rcc_read($path);
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

function rcc_resolve_dependency(string $dep, string $baseRoot): array {
    $clean = str_replace('\\', '/', $dep);

    if (strpos($clean, '/race_results/') === 0) {
        $candidate = rtrim($baseRoot, '/') . $clean;
        return [
            'dependency' => $dep,
            'resolved_path' => rcc_norm($candidate),
            'exists' => is_file($candidate),
            'scope' => 'LIVE race_results'
        ];
    }

    $clean = ltrim($clean, '/');
    $candidate = $baseRoot . '/' . $clean;

    return [
        'dependency' => $dep,
        'resolved_path' => rcc_norm($candidate),
        'exists' => is_file($candidate),
        'scope' => 'LIVE root'
    ];
}

function rcc_scan_references(string $root, array $needles): array {
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

        $text = rcc_read($path);
        if ($text === '') continue;
        $lines = preg_split('/\R/', $text);
        if (!is_array($lines)) continue;

        foreach ($lines as $i => $line) {
            foreach ($needles as $needle) {
                if (stripos($line, $needle) !== false) {
                    $hits[] = [
                        'file' => $name,
                        'line' => $i + 1,
                        'needle' => $needle,
                        'text' => trim($line),
                    ];
                }
            }
        }
    }

    return $hits;
}

function rcc_redacted_structure(string $path): array {
    $text = rcc_read($path);
    if ($text === '') {
        return [
            'exists' => false,
            'line_count' => 0,
            'define_names' => [],
            'assigned_variable_names' => [],
            'include_targets' => [],
            'has_wp_table_prefix_assignment' => false,
            'has_db_like_keys' => false,
            'content_sha256' => '',
        ];
    }

    $defines = [];
    if (preg_match_all('/\bdefine\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,/i', $text, $m)) {
        $defines = array_values(array_unique($m[1]));
        sort($defines, SORT_NATURAL | SORT_FLAG_CASE);
    }

    $vars = [];
    if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=/m', $text, $m)) {
        $vars = array_values(array_unique($m[1]));
        sort($vars, SORT_NATURAL | SORT_FLAG_CASE);
    }

    $incs = [];
    if (preg_match_all('/\b(?:require|require_once|include|include_once)\b[^;\n]*[\'"]([^\'"]+)[\'"]/i', $text, $m)) {
        $incs = array_values(array_unique($m[1]));
        sort($incs, SORT_NATURAL | SORT_FLAG_CASE);
    }

    $lineCount = preg_match_all('/\R/', $text) + 1;

    $dbLike = false;
    foreach (array_merge($defines, $vars) as $key) {
        if (preg_match('/db|database|user|pass|host|prefix|dsn/i', $key)) {
            $dbLike = true;
            break;
        }
    }

    return [
        'exists' => true,
        'line_count' => $lineCount,
        'define_names' => $defines,
        'assigned_variable_names' => $vars,
        'include_targets' => $incs,
        'has_wp_table_prefix_assignment' => (bool)preg_match('/\$table_prefix\s*=/i', $text),
        'has_db_like_keys' => $dbLike,
        'content_sha256' => rcc_sha($path),
    ];
}

/* -------------------------------------------------------------------------
 * Environment
 * ---------------------------------------------------------------------- */

$selfDir = rtrim(rcc_norm(__DIR__), '/');
$expected = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$testRoot = $selfDir;
$liveRoot = dirname($testRoot);

$generatedAt = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

/* -------------------------------------------------------------------------
 * Candidate set from prior root audit.
 * ---------------------------------------------------------------------- */

$candidateNames = [
    'admin_pick_adjustment.php',
    'admin_setup.php',
    'conf.inc.php',
    'config.php',
    'config_mrl.php',
    'current_segment_chart.php',
    'current_segment_chart_by_entry_time.php',
    'current_user_team_chart.php',
    'dbconfig.php',
    'default.php',
    'email.php',
    'form-team-picks.php',
    'logout.php',
    'pick_window_helper.php',
    'prior_year_user_team_chart.php',
    'races.html',
    'README2.html',
    'rebuild_year_index.php',
    'submit-team-picks.php',
    'submitted_teams_count.php',
    'team-late-pick.php',
    'team.php',
    'team_chart.php',
    'team_replacement_driver.php',
    'wp-config.php',
];

/* Established classification intent. Conservative by design. */
$intent = [
    'admin_pick_adjustment.php' => ['MIGRATE', 'Verified TESTPHP8 application work from the current migration phase.'],
    'admin_setup.php' => ['MIGRATE', 'Verified TESTPHP8 admin/application work; dependency checks required.'],
    'conf.inc.php' => ['ENVIRONMENT_SPECIFIC', 'Environment/config file; compare structure only, do not copy blindly.'],
    'config.php' => ['ENVIRONMENT_SPECIFIC', 'Environment/config file; compare structure only, do not copy blindly.'],
    'config_mrl.php' => ['MIGRATE', 'Verified TESTPHP8 application configuration logic; migrate only after dependency and environment checks.'],
    'current_segment_chart.php' => ['MIGRATE', 'Verified TESTPHP8 current-segment presentation logic.'],
    'current_segment_chart_by_entry_time.php' => ['MIGRATE', 'Verified TESTPHP8 current-segment presentation logic.'],
    'current_user_team_chart.php' => ['MIGRATE', 'Verified TESTPHP8 current-team presentation logic.'],
    'dbconfig.php' => ['ENVIRONMENT_SPECIFIC', 'Database/environment file; never overwrite from TESTPHP8 automatically.'],
    'default.php' => ['REVIEW', 'TEST-only root file; purpose must be confirmed before migration.'],
    'email.php' => ['KEEP_LIVE', 'LIVE has its own newer/versioned implementation; do not overwrite automatically.'],
    'form-team-picks.php' => ['MIGRATE', 'Verified TESTPHP8 team-pick form logic.'],
    'logout.php' => ['REVIEW', 'Different unversioned auth-related file; purpose and behavior require review.'],
    'pick_window_helper.php' => ['MIGRATE', 'Verified TESTPHP8 pick-window helper; depends on Live race_schedule_helper.php.'],
    'prior_year_user_team_chart.php' => ['MIGRATE', 'Verified TESTPHP8 prior-year chart integration fix.'],
    'races.html' => ['LEGACY_OR_UTILITY', 'Likely superseded by race_countdown.html; active-reference scan will verify.'],
    'README2.html' => ['LEGACY_OR_UTILITY', 'Documentation/support artifact, not production migration code.'],
    'rebuild_year_index.php' => ['REVIEW', 'TEST-only utility; depends on race_results_engine.php and needs purpose review.'],
    'submit-team-picks.php' => ['MIGRATE', 'Verified TESTPHP8 pick submission logic.'],
    'submitted_teams_count.php' => ['MIGRATE', 'Verified TESTPHP8 submitted-team count/privacy logic.'],
    'team-late-pick.php' => ['MIGRATE', 'Verified TESTPHP8 LP logic.'],
    'team.php' => ['MIGRATE', 'Primary verified TESTPHP8 team-page controller.'],
    'team_chart.php' => ['MIGRATE', 'Verified TESTPHP8 team-chart application logic.'],
    'team_replacement_driver.php' => ['MIGRATE', 'Verified TESTPHP8 RP/RD application logic.'],
    'wp-config.php' => ['ENVIRONMENT_SPECIFIC', 'WordPress environment file; never overwrite from TESTPHP8 automatically.'],
];

$rows = [];
$summary = [
    'MIGRATE' => 0,
    'KEEP_LIVE' => 0,
    'ENVIRONMENT_SPECIFIC' => 0,
    'LEGACY_OR_UTILITY' => 0,
    'REVIEW' => 0,
];

$dependencySummary = [];
$dependencyProblems = [];

foreach ($candidateNames as $name) {
    [$classification, $reason] = $intent[$name];

    $testPath = $testRoot . '/' . $name;
    $livePath = $liveRoot . '/' . $name;

    $testInfo = rcc_info($testPath);
    $liveInfo = rcc_info($livePath);

    $deps = [];
    $resolvedDeps = [];

    if ($classification === 'MIGRATE' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'php' && $testInfo !== null) {
        $deps = rcc_extract_local_php_dependencies($testPath);
        foreach ($deps as $dep) {
            $resolved = rcc_resolve_dependency($dep['dependency'], $liveRoot);
            $resolved['line'] = $dep['line'];
            $resolved['text'] = $dep['text'];
            $resolvedDeps[] = $resolved;

            if (!$resolved['exists']) {
                $dependencyProblems[] = [
                    'file' => $name,
                    'dependency' => $dep['dependency'],
                    'resolved_path' => $resolved['resolved_path'],
                ];
            }
        }
    }

    $rows[] = [
        'path' => $name,
        'classification' => $classification,
        'reason' => $reason,
        'test' => $testInfo,
        'live' => $liveInfo,
        'dependencies' => $resolvedDeps,
    ];

    $summary[$classification]++;

    if ($classification === 'MIGRATE') {
        $dependencySummary[$name] = [
            'dependency_count' => count($resolvedDeps),
            'missing_count' => count(array_filter($resolvedDeps, static function ($d) {
                return !$d['exists'];
            })),
            'dependencies' => $resolvedDeps,
        ];
    }
}

/* Config structural comparison — redacted */
$configNames = ['conf.inc.php', 'config.php', 'dbconfig.php', 'wp-config.php'];
$configCompare = [];

foreach ($configNames as $name) {
    $testStructure = rcc_redacted_structure($testRoot . '/' . $name);
    $liveStructure = rcc_redacted_structure($liveRoot . '/' . $name);

    $configCompare[$name] = [
        'test' => $testStructure,
        'live' => $liveStructure,
        'same_define_names' => $testStructure['define_names'] === $liveStructure['define_names'],
        'same_variable_names' => $testStructure['assigned_variable_names'] === $liveStructure['assigned_variable_names'],
        'same_include_targets' => $testStructure['include_targets'] === $liveStructure['include_targets'],
        'same_content_hash' => $testStructure['content_sha256'] !== '' && $testStructure['content_sha256'] === $liveStructure['content_sha256'],
        'values_redacted' => true,
    ];
}

/* races.html vs race_countdown.html references */
$referenceHits = rcc_scan_references($liveRoot, ['races.html', 'race_countdown.html']);

$racesRefs = array_values(array_filter($referenceHits, static function ($h) {
    return strtolower($h['needle']) === 'races.html';
}));
$countdownRefs = array_values(array_filter($referenceHits, static function ($h) {
    return strtolower($h['needle']) === 'race_countdown.html';
}));

$raceCountdownInfo = rcc_info($liveRoot . '/race_countdown.html');
$racesInfo = rcc_info($liveRoot . '/races.html');

$overallReadyForMigrationPreflight = count($dependencyProblems) === 0;

$report = [
    'report' => MRL_RCC_TITLE,
    'report_version' => MRL_RCC_VERSION,
    'generated_at' => $generatedAt,
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'read_only' => true,
    'expected_location' => $expected,
    'paths' => [
        'test_root' => rcc_norm($testRoot),
        'live_root' => rcc_norm($liveRoot),
    ],
    'summary' => $summary,
    'migration_dependency_gate' => $overallReadyForMigrationPreflight ? 'PASS' : 'FAIL',
    'dependency_problems' => $dependencyProblems,
    'classifications' => $rows,
    'migration_dependency_summary' => $dependencySummary,
    'config_structural_comparison_redacted' => $configCompare,
    'schedule_presentation_check' => [
        'races_html' => [
            'live' => $racesInfo,
            'active_reference_count' => count($racesRefs),
            'active_references' => $racesRefs,
            'provisional_classification' => count($racesRefs) === 0 ? 'LEGACY_OR_UTILITY' : 'REVIEW',
        ],
        'race_countdown_html' => [
            'live' => $raceCountdownInfo,
            'active_reference_count' => count($countdownRefs),
            'active_references' => $countdownRefs,
            'provisional_classification' => $raceCountdownInfo !== null ? 'CURRENT_PRESENTATION_CANDIDATE' : 'MISSING',
        ],
    ],
    'established_future_relocation_rules' => [
        'yearly_team_charts' => '/team_charts/',
        'yearly_league_info' => '/league_info/',
        'note' => 'No relocation is performed by this audit.',
    ],
    'warnings' => $expected ? [] : [
        'This script is not running from the expected /public_html/testphp8 location.'
    ],
];

function rcc_txt(array $report): string {
    $out = [];
    $out[] = MRL_RCC_TITLE;
    $out[] = 'Version: ' . MRL_RCC_VERSION;
    $out[] = 'Generated: ' . $report['generated_at'];
    $out[] = 'Read only: YES';
    $out[] = '';
    $out[] = 'SUMMARY';
    foreach ($report['summary'] as $k => $v) {
        $out[] = $k . ': ' . $v;
    }
    $out[] = 'Migration dependency gate: ' . $report['migration_dependency_gate'];
    $out[] = '';
    $out[] = 'CLASSIFICATIONS';
    foreach ($report['classifications'] as $row) {
        $out[] = '- ' . $row['path'] . ' | ' . $row['classification'] . ' | ' . $row['reason'];
    }
    $out[] = '';
    $out[] = 'SCHEDULE PRESENTATION';
    $out[] = '- races.html refs: ' . $report['schedule_presentation_check']['races_html']['active_reference_count'];
    $out[] = '- race_countdown.html refs: ' . $report['schedule_presentation_check']['race_countdown_html']['active_reference_count'];
    return implode("\r\n", $out) . "\r\n";
}

$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_root_candidate_classification_audit_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_root_candidate_classification_audit_' . $stamp . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo rcc_txt($report);
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
<title><?= rcc_h(MRL_RCC_TITLE) ?></title>
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
    <h1><?= rcc_h(MRL_RCC_TITLE) ?></h1>
    <div class="small">v001 · <?= rcc_h($generatedAt) ?> · READ ONLY</div>

    <div class="summary">
        <div class="pill">MIGRATE: <span class="pass"><?= (int)$summary['MIGRATE'] ?></span></div>
        <div class="pill">KEEP LIVE: <span class="info"><?= (int)$summary['KEEP_LIVE'] ?></span></div>
        <div class="pill">ENVIRONMENT: <span class="warn"><?= (int)$summary['ENVIRONMENT_SPECIFIC'] ?></span></div>
        <div class="pill">LEGACY/UTILITY: <?= (int)$summary['LEGACY_OR_UTILITY'] ?></div>
        <div class="pill">REVIEW: <span class="<?= $summary['REVIEW'] ? 'warn' : 'pass' ?>"><?= (int)$summary['REVIEW'] ?></span></div>
        <div class="pill">Dependency gate: <span class="<?= $overallReadyForMigrationPreflight ? 'pass' : 'fail' ?>"><?= $overallReadyForMigrationPreflight ? 'PASS' : 'FAIL' ?></span></div>
    </div>

    <a class="button" href="?format=json&x=<?= rcc_h((string)microtime(true)) ?>">Download JSON Results</a>
    <a class="button" href="?format=txt&x=<?= rcc_h((string)microtime(true)) ?>">Download TXT Results</a>
</div>

<div class="panel">
    <h2>Candidate classifications</h2>
    <table>
        <thead><tr><th>Path</th><th>Class</th><th>TEST</th><th>LIVE</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php
            $classCss = $row['classification'] === 'MIGRATE' ? 'pass'
                : ($row['classification'] === 'REVIEW' || $row['classification'] === 'ENVIRONMENT_SPECIFIC' ? 'warn'
                : ($row['classification'] === 'KEEP_LIVE' ? 'info' : ''));
            ?>
            <tr>
                <td><code><?= rcc_h($row['path']) ?></code></td>
                <td class="<?= $classCss ?>"><?= rcc_h($row['classification']) ?></td>
                <td><?= rcc_h(($row['test']['version'] ?? '') ?: ($row['test'] === null ? 'MISSING' : '(none)')) ?></td>
                <td><?= rcc_h(($row['live']['version'] ?? '') ?: ($row['live'] === null ? 'MISSING' : '(none)')) ?></td>
                <td><?= rcc_h($row['reason']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2>Schedule presentation check</h2>
    <table>
        <thead><tr><th>File</th><th>LIVE present</th><th>Active references</th><th>Provisional classification</th></tr></thead>
        <tbody>
        <tr>
            <td><code>races.html</code></td>
            <td><?= $racesInfo !== null ? 'YES' : 'NO' ?></td>
            <td><?= count($racesRefs) ?></td>
            <td><?= rcc_h($report['schedule_presentation_check']['races_html']['provisional_classification']) ?></td>
        </tr>
        <tr>
            <td><code>race_countdown.html</code></td>
            <td><?= $raceCountdownInfo !== null ? 'YES' : 'NO' ?></td>
            <td><?= count($countdownRefs) ?></td>
            <td><?= rcc_h($report['schedule_presentation_check']['race_countdown_html']['provisional_classification']) ?></td>
        </tr>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2>Dependency issues</h2>
    <?php if (!$dependencyProblems): ?>
        <p class="pass">PASS — all directly-detected migration dependencies resolve on LIVE.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Candidate</th><th>Dependency</th><th>Resolved path</th></tr></thead>
            <tbody>
            <?php foreach ($dependencyProblems as $p): ?>
                <tr>
                    <td><?= rcc_h($p['file']) ?></td>
                    <td><?= rcc_h($p['dependency']) ?></td>
                    <td><?= rcc_h($p['resolved_path']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Config comparison safety</h2>
    <p>
        The JSON export contains only structural metadata for <code>conf.inc.php</code>,
        <code>config.php</code>, <code>dbconfig.php</code>, and <code>wp-config.php</code>.
        It exports key/variable names and hashes, but never credential or secret values.
    </p>
</div>

<div class="panel">
    <h2>Future cleanup decisions already established</h2>
    <ul>
        <li>Yearly team chart files → future relocation to <code>/team_charts/</code>.</li>
        <li>Yearly Rules / Fees / Schedule material → future relocation to <code>/league_info/</code>.</li>
        <li>No relocation happens in this audit.</li>
        <li>Live scheduler stays running; this page is read-only.</li>
    </ul>
</div>

</div>
</body>
</html>
