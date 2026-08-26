<?php
/**
 * MRL TESTPHP8 Final Shutdown Certification
 *
 * VERSION: v002
 * LAST MODIFIED: 8/26/2026 7:34:58 am
 *
 * CHANGELOG:
 * v002 (8/26/2026 7:34:58 am)
 * - Updated final shutdown decision for logout.php after successful LIVE migration.
 * - logout.php is now expected to MATCH the approved TESTPHP8 source hash.
 * - Preserves final decisions:
 *     default.php            -> DO NOT MIGRATE
 *     rebuild_year_index.php -> DO NOT MIGRATE
 *     races.html             -> DO NOT MIGRATE; replaced by race_countdown.html
 *     clean_race_finish_confirmation_data_v001_20260726_073602am.php
 *                            -> DO NOT MIGRATE
 *     sandbox.php            -> PRESERVE LIVE / no migration required
 * - Verifies the completed 15-file root migration still matches LIVE.
 * - Verifies logout.php now also matches TESTPHP8 after its controlled migration.
 * - PASS means no unresolved root-level TESTPHP8 migration decision remains.
 * - Read only; no files, DB, scheduler, WordPress, or race_results changes.
 *
 * v001 (8/26/2026 7:06:39 am)
 * - Initial final shutdown certification before logout.php decision changed.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
date_default_timezone_set('America/New_York');

const MRL_FSC_VERSION = 'v002';
const MRL_FSC_TITLE = 'MRL TESTPHP8 Final Shutdown Certification';
const EXPECTED_LOGOUT_SHA256 = 'd6fcfb1b937e417f3480f985e4f1ce9abe0fb1b4c54bbd3c56a0b77c6875e5d3';

function fsc2_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fsc2_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function fsc2_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function fsc2_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function fsc2_version(string $p): string {
    $text = fsc2_read($p);
    if ($text === '') return '';

    $patterns = array(
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/'
    );

    foreach ($patterns as $rx) {
        if (preg_match($rx, $text, $m)) {
            return strtolower((string)$m[1]);
        }
    }

    return '';
}

function fsc2_info(string $p): ?array {
    if (!is_file($p)) return null;

    $m = @filemtime($p);
    $s = @filesize($p);

    return array(
        'path' => fsc2_norm($p),
        'version' => fsc2_version($p),
        'sha256' => fsc2_sha($p),
        'size' => is_int($s) ? $s : null,
        'mtime' => is_int($m) ? date('Y-m-d H:i:s T', $m) : null
    );
}

function fsc2_root_files(string $root): array {
    $out = array();
    $items = @scandir($root);
    if (!is_array($items)) return $out;

    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;

        $full = $root . '/' . $name;
        if (!is_file($full)) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, array('php','html','htm','md','txt','json','css','js'), true)) continue;

        $out[$name] = fsc2_norm($full);
    }

    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function fsc2_is_tooling(string $name): bool {
    $s = strtolower($name);

    $patterns = array(
        'audit','installer','install_','preflight','diagnostic','debug','probe',
        'cleanup','quarantine','migration','compare','restore','backup','preview',
        'sweep','test_','_test'
    );

    foreach ($patterns as $p) {
        if (strpos($s, $p) !== false) return true;
    }

    return false;
}

function fsc2_is_yearly_team_chart(string $name): bool {
    if (preg_match('/^(20\d{2})_S\d+_Team_chart\.php$/i', $name)) return true;
    if (preg_match('/^(20\d{2}).*team.*chart.*\.php$/i', $name)) return true;
    if (preg_match('/team.*chart.*20\d{2}.*\.php$/i', $name)) return true;
    return false;
}

function fsc2_is_league_info(string $name): bool {
    return (bool)(
        preg_match('/20\d{2}/', $name)
        && preg_match('/rules|fees|schedule/i', $name)
    );
}

$testRoot = rtrim(fsc2_norm(__DIR__), '/');
$liveRoot = dirname($testRoot);
$expectedLocation = preg_match('#/public_html/testphp8$#', $testRoot) ? true : false;

$generatedAt = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$migrated15 = array(
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
    'team_replacement_driver.php'
);

$environmentFiles = array(
    'conf.inc.php',
    'config.php',
    'dbconfig.php',
    'wp-config.php',
    'email.php'
);

$finalDecisions = array(
    'default.php' => array(
        'classification' => 'DO_NOT_MIGRATE',
        'reason' => 'Legacy TEST-only page; previously reviewed with no active LIVE dependency.'
    ),
    'rebuild_year_index.php' => array(
        'classification' => 'DO_NOT_MIGRATE',
        'reason' => 'Legacy TEST-only utility; previously reviewed with no active LIVE dependency.'
    ),
    'races.html' => array(
        'classification' => 'DO_NOT_MIGRATE',
        'reason' => 'Legacy race display replaced by active race_countdown.html.'
    ),
    'clean_race_finish_confirmation_data_v001_20260726_073602am.php' => array(
        'classification' => 'DO_NOT_MIGRATE',
        'reason' => 'Obsolete one-time race-finish-confirmation cleanup utility; zero active LIVE references.'
    ),
    'sandbox.php' => array(
        'classification' => 'PRESERVE_LIVE',
        'reason' => 'Environment-specific sandbox display; no migration required.'
    ),
    'README2.html' => array(
        'classification' => 'DOCUMENTATION_ONLY',
        'reason' => 'Documentation only; not production migration code.'
    ),
    'README_Race_Results_System.md' => array(
        'classification' => 'DOCUMENTATION_ONLY',
        'reason' => 'race_results documentation; race_results migration is already complete.'
    )
);

$testFiles = fsc2_root_files($testRoot);

$rows = array();
$unresolved = array();

$counts = array(
    'root_files_considered' => 0,
    'verified_migrated' => 0,
    'logout_verified_migrated' => 0,
    'identical' => 0,
    'environment_specific' => 0,
    'final_decision_applied' => 0,
    'tooling_or_test_debris' => 0,
    'future_team_chart_relocation' => 0,
    'future_league_info_relocation' => 0,
    'unresolved' => 0
);

foreach ($testFiles as $name => $testPath) {
    $counts['root_files_considered']++;

    $livePath = $liveRoot . '/' . $name;
    $testInfo = fsc2_info($testPath);
    $liveInfo = fsc2_info($livePath);

    $classification = '';
    $reason = '';
    $resolved = true;

    if ($name === 'logout.php') {
        $testSha = fsc2_sha($testPath);
        $liveSha = fsc2_sha($livePath);

        if (
            $testSha === EXPECTED_LOGOUT_SHA256
            && $liveSha === EXPECTED_LOGOUT_SHA256
        ) {
            $classification = 'VERIFIED_MIGRATED';
            $reason = 'logout.php successfully migrated to LIVE; TEST and LIVE match approved $mrl-based source.';
            $counts['logout_verified_migrated']++;
        } else {
            $classification = 'UNRESOLVED';
            $reason = 'logout.php no longer matches the approved migrated TESTPHP8 hash.';
            $resolved = false;
            $counts['unresolved']++;
        }

    } elseif (isset($finalDecisions[$name])) {
        $classification = $finalDecisions[$name]['classification'];
        $reason = $finalDecisions[$name]['reason'];
        $counts['final_decision_applied']++;

    } elseif (
        in_array($name, $migrated15, true)
        && $testInfo !== null
        && $liveInfo !== null
        && $testInfo['sha256'] === $liveInfo['sha256']
    ) {
        $classification = 'VERIFIED_MIGRATED';
        $reason = 'Completed TESTPHP8 -> LIVE migration; hashes match.';
        $counts['verified_migrated']++;

    } elseif (in_array($name, $environmentFiles, true)) {
        $classification = 'ENVIRONMENT_SPECIFIC';
        $reason = 'Intentional TEST/LIVE environment difference; preserve LIVE.';
        $counts['environment_specific']++;

    } elseif (fsc2_is_tooling($name)) {
        $classification = 'TOOLING_OR_TEST_DEBRIS';
        $reason = 'Audit/install/test/migration helper; not production migration code.';
        $counts['tooling_or_test_debris']++;

    } elseif (fsc2_is_yearly_team_chart($name)) {
        $classification = 'FUTURE_TEAM_CHART_RELOCATION';
        $reason = 'Known future organization work for /team_charts/; not a TESTPHP8 migration blocker.';
        $counts['future_team_chart_relocation']++;

    } elseif (fsc2_is_league_info($name)) {
        $classification = 'FUTURE_LEAGUE_INFO_RELOCATION';
        $reason = 'Known future organization work for /league_info/; not a TESTPHP8 migration blocker.';
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
        $classification = 'UNRESOLVED';
        $reason = $liveInfo === null
            ? 'TEST-only root file has no final classification.'
            : 'TEST/LIVE root files differ with no final classification.';
        $resolved = false;
        $counts['unresolved']++;
    }

    $row = array(
        'path' => $name,
        'classification' => $classification,
        'reason' => $reason,
        'resolved' => $resolved,
        'test' => $testInfo,
        'live' => $liveInfo
    );

    $rows[] = $row;
    if (!$resolved) $unresolved[] = $row;
}

$migratedFailures = array();

foreach ($migrated15 as $name) {
    $testSha = fsc2_sha($testRoot . '/' . $name);
    $liveSha = fsc2_sha($liveRoot . '/' . $name);

    if ($testSha === '' || $liveSha === '' || $testSha !== $liveSha) {
        $migratedFailures[] = array(
            'path' => $name,
            'test_sha256' => $testSha,
            'live_sha256' => $liveSha
        );
    }
}

$logoutGate =
    fsc2_sha($testRoot . '/logout.php') === EXPECTED_LOGOUT_SHA256
    && fsc2_sha($liveRoot . '/logout.php') === EXPECTED_LOGOUT_SHA256;

$migration15Gate = count($migratedFailures) === 0;
$decisionGate = count($unresolved) === 0;
$overallPass = $expectedLocation && $migration15Gate && $logoutGate && $decisionGate;

$report = array(
    'report' => MRL_FSC_TITLE,
    'report_version' => MRL_FSC_VERSION,
    'generated_at' => $generatedAt,
    'read_only' => true,
    'expected_location' => $expectedLocation,
    'gates' => array(
        'expected_location' => $expectedLocation,
        'migrated_15_hashes_match' => $migration15Gate,
        'logout_php_migrated_hash_match' => $logoutGate,
        'all_root_decisions_resolved' => $decisionGate,
        'overall_pass' => $overallPass
    ),
    'status' => $overallPass
        ? 'PASS - TESTPHP8 IS READY TO SUNSET'
        : 'HOLD - TESTPHP8 STILL HAS UNRESOLVED ITEMS',
    'summary' => $counts,
    'final_decisions' => $finalDecisions,
    'migrated_hash_failures' => $migratedFailures,
    'unresolved_items' => $unresolved,
    'all_root_rows' => $rows,
    'known_future_work' => array(
        'team_charts' => 'Relocate historical yearly team charts to /team_charts/ in a separate future cleanup.',
        'league_info' => 'Relocate yearly Rules/Fees/Schedule material to /league_info/ in a separate future cleanup.',
        'race_results' => 'Migration complete; all future race_results work happens on LIVE.'
    ),
    'safety' => array(
        'files_changed' => false,
        'database_writes' => false,
        'scheduler_changes' => false,
        'wordpress_changes' => false,
        'race_results_changes' => false
    )
);

$format = strtolower((string)($_GET['format'] ?? ''));

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_testphp8_final_shutdown_certification_v002_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
<title><?= fsc2_h(MRL_FSC_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1500px;margin:auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px}.small{color:var(--muted)}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:10px 8px 0 0}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}
code{color:#bddcff;background:#111318;padding:2px 5px;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1><?= fsc2_h(MRL_FSC_TITLE) ?></h1>
<div class="small">v002 · <?= fsc2_h($generatedAt) ?> · READ ONLY · root-level files only</div>

<div class="summary">
<div class="pill">Overall: <span class="<?= $overallPass ? 'pass' : 'warn' ?>"><?= fsc2_h($report['status']) ?></span></div>
<div class="pill">15 migrated files: <span class="<?= $migration15Gate ? 'pass' : 'fail' ?>"><?= $migration15Gate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">logout.php: <span class="<?= $logoutGate ? 'pass' : 'fail' ?>"><?= $logoutGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Root decisions: <span class="<?= $decisionGate ? 'pass' : 'fail' ?>"><?= $decisionGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Unresolved: <span class="<?= count($unresolved) === 0 ? 'pass' : 'warn' ?>"><?= count($unresolved) ?></span></div>
</div>

<a class="button" href="?format=json">Download JSON Results</a>
</div>

<div class="panel">
<h2>Final explicit decisions</h2>
<table>
<thead><tr><th>File</th><th>Decision</th><th>Reason</th></tr></thead>
<tbody>
<tr><td><code>logout.php</code></td><td class="<?= $logoutGate ? 'pass' : 'fail' ?>"><?= $logoutGate ? 'VERIFIED_MIGRATED' : 'FAIL' ?></td><td>Approved TESTPHP8 $mrl-based logout.php is now installed on LIVE and functionally tested.</td></tr>
<?php foreach ($finalDecisions as $name => $decision): ?>
<tr>
<td><code><?= fsc2_h($name) ?></code></td>
<td class="pass"><?= fsc2_h($decision['classification']) ?></td>
<td><?= fsc2_h($decision['reason']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="panel">
<h2>Unresolved items</h2>
<?php if (count($unresolved) === 0): ?>
<p class="pass">PASS — no unresolved root-level TESTPHP8 migration decisions remain.</p>
<?php else: ?>
<table>
<thead><tr><th>File</th><th>Reason</th></tr></thead>
<tbody>
<?php foreach ($unresolved as $row): ?>
<tr><td><code><?= fsc2_h($row['path']) ?></code></td><td><?= fsc2_h($row['reason']) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

</div>
</body>
</html>
