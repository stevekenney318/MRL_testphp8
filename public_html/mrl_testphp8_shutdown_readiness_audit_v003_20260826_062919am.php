<?php
/**
 * MRL TESTPHP8 Focused Shutdown Readiness Audit
 *
 * VERSION: v003
 * LAST MODIFIED: 8/26/2026 6:29:19 am
 *
 * CHANGELOG:
 * v003 (8/26/2026 6:29:19 am)
 * - Corrected v002 HTTP 500 by removing recursive LIVE-tree scanning entirely.
 * - Limits all inspection to root-level custom MRL files only.
 * - Does not enter WordPress, formtools, race_results, plugin, vendor, or other subdirectories.
 * - Uses established decisions for default.php, rebuild_year_index.php, and races.html.
 * - Keeps logout.php as the one mandatory deliberate review item.
 * - Scans root-level LIVE custom files only for logout.php references.
 * - Reports any other unexplained root-level TESTPHP8 differences.
 * - Read only; JSON/TXT export.
 *
 * v002 (8/26/2026 6:14:09 am)
 * - Focused root-only shutdown design, but still used a recursive LIVE reference scan.
 *
 * v001 (8/26/2026 6:05:40 am)
 * - Initial broad recursive shutdown-readiness audit.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
date_default_timezone_set('America/New_York');

const MRL_TSRA_VERSION = 'v003';
const MRL_TSRA_TITLE = 'MRL TESTPHP8 Focused Shutdown Readiness Audit';

function s_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function s_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function s_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) {
        return '';
    }
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function s_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) {
        return '';
    }
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function s_version(string $p): string {
    $t = s_read($p);
    if ($t === '') {
        return '';
    }

    $patterns = [
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/'
    ];

    foreach ($patterns as $rx) {
        if (preg_match($rx, $t, $m)) {
            return strtolower((string)$m[1]);
        }
    }

    return '';
}

function s_info(string $p): ?array {
    if (!is_file($p)) {
        return null;
    }

    $m = @filemtime($p);
    $z = @filesize($p);

    return [
        'path' => s_norm($p),
        'version' => s_version($p),
        'sha256' => s_sha($p),
        'size' => is_int($z) ? $z : null,
        'mtime' => is_int($m) ? date('Y-m-d H:i:s T', $m) : null,
    ];
}

function s_root_files(string $root): array {
    $out = [];
    $items = @scandir($root);

    if (!is_array($items)) {
        return $out;
    }

    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $full = $root . '/' . $name;

        if (!is_file($full)) {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, ['php','html','htm','md','txt','json','css','js'], true)) {
            continue;
        }

        $out[$name] = s_norm($full);
    }

    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function s_is_tooling(string $name): bool {
    $s = strtolower($name);

    $patterns = [
        'audit',
        'installer',
        'install_',
        'preflight',
        'diagnostic',
        'debug',
        'probe',
        'cleanup',
        'quarantine',
        'migration',
        'compare',
        'restore',
        'backup',
        'preview',
        'sweep',
        'test_',
        '_test'
    ];

    foreach ($patterns as $p) {
        if (strpos($s, $p) !== false) {
            return true;
        }
    }

    return false;
}

function s_is_yearly_team_chart(string $name): bool {
    if (preg_match('/^(20\d{2})_S\d+_Team_chart\.php$/i', $name)) {
        return true;
    }
    if (preg_match('/^(20\d{2}).*team.*chart.*\.php$/i', $name)) {
        return true;
    }
    if (preg_match('/team.*chart.*20\d{2}.*\.php$/i', $name)) {
        return true;
    }
    return false;
}

function s_is_league_info(string $name): bool {
    return (bool)(
        preg_match('/20\d{2}/', $name)
        && preg_match('/rules|fees|schedule/i', $name)
    );
}

function s_root_live_references(string $liveRoot, string $needle): array {
    $hits = [];
    $files = s_root_files($liveRoot);

    foreach ($files as $name => $full) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['php','html','htm','js'], true)) {
            continue;
        }

        $text = s_read($full);
        if ($text === '') {
            continue;
        }

        $lines = preg_split('/\R/', $text);
        if (!is_array($lines)) {
            continue;
        }

        foreach ($lines as $i => $line) {
            if (stripos($line, $needle) === false) {
                continue;
            }

            $trim = trim($line);
            $commentOnly = (bool)preg_match('#^\s*(//|/\*|\*|<!--)#', $trim);

            $hits[] = [
                'file' => $name,
                'line' => $i + 1,
                'text' => $trim,
                'comment_only' => $commentOnly,
            ];
        }
    }

    return $hits;
}

$testRoot = rtrim(s_norm(__DIR__), '/');
$liveRoot = dirname($testRoot);
$expectedLocation = (bool)preg_match('#/public_html/testphp8$#', $testRoot);

$generatedAt = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$migrated15 = [
    'admin_pick_adjustment.php',
    'admin_setup.php',
    'config_mrl.php',
    'current_segment_chart.php',
    'current_segment_chart_by_entry_time.php',
    'current_user_team_chart.php',
    'form-team-picks.php',
    'pick_window_helper.php',
    'prior_year_user_team_chart.php',
    'submit-team-picks.php',
    'submitted_teams_count.php',
    'team-late-pick.php',
    'team.php',
    'team_chart.php',
    'team_replacement_driver.php',
];

$environmentFiles = [
    'conf.inc.php',
    'config.php',
    'dbconfig.php',
    'wp-config.php',
    'email.php',
];

$knownOutcomes = [
    'default.php' => [
        'classification' => 'KNOWN_DO_NOT_MIGRATE',
        'reason' => 'Previously reviewed: no active LIVE references; legacy TEST-only page.',
    ],
    'rebuild_year_index.php' => [
        'classification' => 'KNOWN_DO_NOT_MIGRATE',
        'reason' => 'Previously reviewed: no active LIVE references; legacy TEST-only utility.',
    ],
    'races.html' => [
        'classification' => 'KNOWN_DO_NOT_MIGRATE',
        'reason' => 'Confirmed legacy and replaced by race_countdown.html.',
    ],
    'README2.html' => [
        'classification' => 'KNOWN_DOCUMENTATION',
        'reason' => 'Documentation only; not production migration code.',
    ],
    'README_Race_Results_System.md' => [
        'classification' => 'KNOWN_DOCUMENTATION',
        'reason' => 'race_results documentation; race_results migration is complete.',
    ],
];

$testFiles = s_root_files($testRoot);

$rows = [];
$blockers = [];

$counts = [
    'root_files_considered' => 0,
    'verified_migrated' => 0,
    'identical' => 0,
    'environment_specific' => 0,
    'known_do_not_migrate' => 0,
    'known_documentation' => 0,
    'tooling_or_test_debris' => 0,
    'future_team_chart_relocation' => 0,
    'future_league_info_relocation' => 0,
    'logout_review' => 0,
    'unexplained_blockers' => 0,
];

foreach ($testFiles as $name => $testPath) {
    $counts['root_files_considered']++;

    $livePath = $liveRoot . '/' . $name;
    $testInfo = s_info($testPath);
    $liveInfo = s_info($livePath);

    $classification = '';
    $reason = '';
    $blocks = false;

    if ($name === 'logout.php') {
        $classification = 'LOGOUT_REVIEW_REQUIRED';
        $reason = 'LIVE actively uses logout.php and TEST/LIVE copies differ; deliberate final decision required.';
        $blocks = true;
        $counts['logout_review']++;

    } elseif (isset($knownOutcomes[$name])) {
        $classification = $knownOutcomes[$name]['classification'];
        $reason = $knownOutcomes[$name]['reason'];

        if ($classification === 'KNOWN_DO_NOT_MIGRATE') {
            $counts['known_do_not_migrate']++;
        } else {
            $counts['known_documentation']++;
        }

    } elseif (
        in_array($name, $migrated15, true)
        && $testInfo !== null
        && $liveInfo !== null
        && $testInfo['sha256'] === $liveInfo['sha256']
    ) {
        $classification = 'VERIFIED_MIGRATED';
        $reason = 'Completed migration; TEST and LIVE hashes match.';
        $counts['verified_migrated']++;

    } elseif (in_array($name, $environmentFiles, true)) {
        $classification = 'ENVIRONMENT_SPECIFIC';
        $reason = 'Intentional TEST/LIVE environment difference; preserve LIVE.';
        $counts['environment_specific']++;

    } elseif (s_is_tooling($name)) {
        $classification = 'TOOLING_OR_TEST_DEBRIS';
        $reason = 'Audit/install/test/migration helper; not production migration code.';
        $counts['tooling_or_test_debris']++;

    } elseif (s_is_yearly_team_chart($name)) {
        $classification = 'FUTURE_TEAM_CHART_RELOCATION';
        $reason = 'Already-decided future organization item for /team_charts/.';
        $counts['future_team_chart_relocation']++;

    } elseif (s_is_league_info($name)) {
        $classification = 'FUTURE_LEAGUE_INFO_RELOCATION';
        $reason = 'Already-decided future organization item for /league_info/.';
        $counts['future_league_info_relocation']++;

    } elseif (
        $testInfo !== null
        && $liveInfo !== null
        && $testInfo['sha256'] === $liveInfo['sha256']
    ) {
        $classification = 'IDENTICAL';
        $reason = 'TEST and LIVE are byte-for-byte identical.';
        $counts['identical']++;

    } else {
        $classification = 'UNEXPLAINED_ROOT_DIFFERENCE';
        $reason = $liveInfo !== null
            ? 'Root-level TEST and LIVE files differ and no prior decision explains it.'
            : 'Root-level TEST-only file has no prior classification.';
        $blocks = true;
        $counts['unexplained_blockers']++;
    }

    $row = [
        'path' => $name,
        'classification' => $classification,
        'reason' => $reason,
        'blocks_shutdown' => $blocks,
        'test' => $testInfo,
        'live' => $liveInfo,
    ];

    $rows[] = $row;

    if ($blocks) {
        $blockers[] = $row;
    }
}

$logoutRefs = s_root_live_references($liveRoot, 'logout.php');
$logoutActive = [];

foreach ($logoutRefs as $hit) {
    if (!$hit['comment_only']) {
        $logoutActive[] = $hit;
    }
}

$nonLogoutBlockers = [];
foreach ($blockers as $row) {
    if ($row['path'] !== 'logout.php') {
        $nonLogoutBlockers[] = $row;
    }
}

$readyExceptLogout = $expectedLocation && count($nonLogoutBlockers) === 0;

$report = [
    'report' => MRL_TSRA_TITLE,
    'report_version' => MRL_TSRA_VERSION,
    'generated_at' => $generatedAt,
    'read_only' => true,
    'expected_location' => $expectedLocation,
    'scope' => [
        'scan' => 'Root-level custom MRL files only',
        'directories_not_entered' => [
            'wp-admin',
            'wp-includes',
            'wp-content',
            'formtools',
            'race_results',
            'all other subdirectories',
        ],
    ],
    'summary' => $counts,
    'shutdown_status' => [
        'ready_except_logout' => $readyExceptLogout,
        'status' => $readyExceptLogout
            ? 'PASS - ONLY logout.php DECISION REMAINS'
            : 'HOLD - logout.php PLUS OTHER ROOT DECISIONS REMAIN',
        'remaining_blockers' => count($blockers),
        'non_logout_blockers' => count($nonLogoutBlockers),
    ],
    'logout_review' => [
        'test' => s_info($testRoot . '/logout.php'),
        'live' => s_info($liveRoot . '/logout.php'),
        'same_hash' => s_sha($testRoot . '/logout.php') === s_sha($liveRoot . '/logout.php'),
        'active_root_live_references' => $logoutActive,
        'active_root_live_reference_count' => count($logoutActive),
    ],
    'blockers' => $blockers,
    'all_root_rows' => $rows,
    'known_future_work' => [
        'yearly_team_charts' => 'Future relocation to /team_charts/.',
        'yearly_league_info' => 'Future relocation to /league_info/.',
        'race_results' => 'Migration complete; future work happens on LIVE.',
    ],
    'safety' => [
        'files_changed' => false,
        'database_writes' => false,
        'scheduler_changes' => false,
        'subdirectories_entered' => false,
    ],
];

function s_txt(array $r): string {
    $out = [];
    $out[] = MRL_TSRA_TITLE;
    $out[] = 'Version: ' . MRL_TSRA_VERSION;
    $out[] = 'Generated: ' . $r['generated_at'];
    $out[] = '';
    $out[] = 'STATUS: ' . $r['shutdown_status']['status'];
    $out[] = 'Remaining blockers: ' . $r['shutdown_status']['remaining_blockers'];
    $out[] = 'Non-logout blockers: ' . $r['shutdown_status']['non_logout_blockers'];
    $out[] = '';
    $out[] = 'BLOCKERS';

    foreach ($r['blockers'] as $b) {
        $out[] = '- ' . $b['path'] . ' | ' . $b['classification'];
    }

    return implode("\r\n", $out) . "\r\n";
}

$format = strtolower((string)($_GET['format'] ?? ''));

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_shutdown_readiness_v003_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_shutdown_readiness_v003_' . $stamp . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo s_txt($report);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= s_h(MRL_TSRA_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1500px;margin:auto}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px}.small{color:var(--muted)}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:10px 8px 0 0}
table{width:100%;border-collapse:collapse}
th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}
th{background:var(--panel2)}
code{color:#bddcff;background:#111318;padding:2px 5px;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1><?= s_h(MRL_TSRA_TITLE) ?></h1>
<div class="small">v003 · <?= s_h($generatedAt) ?> · READ ONLY · root-level files only</div>

<div class="summary">
<div class="pill">Status: <span class="<?= $readyExceptLogout ? 'pass' : 'warn' ?>"><?= s_h($report['shutdown_status']['status']) ?></span></div>
<div class="pill">Remaining blockers: <span class="warn"><?= count($blockers) ?></span></div>
<div class="pill">Non-logout blockers: <span class="<?= count($nonLogoutBlockers) === 0 ? 'pass' : 'warn' ?>"><?= count($nonLogoutBlockers) ?></span></div>
<div class="pill">Verified migrated: <span class="pass"><?= $counts['verified_migrated'] ?></span></div>
<div class="pill">Identical: <span class="pass"><?= $counts['identical'] ?></span></div>
</div>

<a class="button" href="?format=json">Download JSON Results</a>
<a class="button" href="?format=txt">Download TXT Results</a>
</div>

<div class="panel">
<h2>logout.php — final deliberate review</h2>
<p>Active root-level LIVE references: <strong><?= count($logoutActive) ?></strong></p>
<p>TEST/LIVE hash: <strong class="<?= $report['logout_review']['same_hash'] ? 'pass' : 'warn' ?>"><?= $report['logout_review']['same_hash'] ? 'MATCH' : 'DIFFERENT' ?></strong></p>
</div>

<div class="panel">
<h2>Other root-level blockers</h2>

<?php if (count($nonLogoutBlockers) === 0): ?>
<p class="pass">PASS — no other unexplained root-level MRL files remain.</p>
<?php else: ?>
<table>
<thead><tr><th>File</th><th>Reason</th></tr></thead>
<tbody>
<?php foreach ($nonLogoutBlockers as $row): ?>
<tr>
<td><code><?= s_h($row['path']) ?></code></td>
<td><?= s_h($row['reason']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="panel">
<h2>Deliberately excluded</h2>
<p>This version does not enter any subdirectory. WordPress, plugins, formtools, race_results, backups, and other trees cannot generate shutdown noise.</p>
</div>

</div>
</body>
</html>
