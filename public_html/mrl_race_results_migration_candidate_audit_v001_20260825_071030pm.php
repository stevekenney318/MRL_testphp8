<?php
/**
 * MRL race_results Migration Candidate Audit
 *
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 7:10:30 pm
 *
 * PURPOSE:
 * Focused, read-only migration analysis after the full TESTPHP8 ↔ LIVE
 * race_results filesystem comparison.
 *
 * MIGRATION PRINCIPLE:
 * - LIVE is authoritative for race data, snapshots, runtime state/history,
 *   current-season generated files, and operational outputs.
 * - TESTPHP8 is authoritative only for newer code/config intentionally
 *   developed and verified there.
 * - Old race-finish-confirmation / Racing-Reference / Jayski experiments,
 *   installers, backups, diagnostics, and historical test debris are not
 *   migration candidates.
 *
 * SAFETY:
 * - TESTPHP8 host only.
 * - READ-ONLY.
 * - No file writes/moves/deletes/renames.
 * - No database writes.
 * - No scheduler changes.
 * - PHP 7.3 compatible.
 *
 * OUTPUT:
 * - Focused code/config differences
 * - Provisional classification
 * - Dependency/reference scan for key migration/obsolete terms
 * - Timestamped JSON and TXT exports
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';

$testRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
$liveRoot = dirname($testRoot);

$testRR = $testRoot . '/race_results';
$liveRR = $liveRoot . '/race_results';

$generatedAt = date('Y-m-d H:i:s T');
$exportStamp = strtolower(date('Ymd_hisA'));

$errors = array();
$rows = array();
$referenceRows = array();
$summary = array(
    'migrate_candidate' => 0,
    'keep_live' => 0,
    'obsolete_or_test_only' => 0,
    'review' => 0,
    'identical_code' => 0
);

function mrlmc_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mrlmc_read_version($path) {
    if (!is_file($path) || !is_readable($path)) return '';

    $data = @file_get_contents($path, false, null, 0, 20000);
    if ($data === false) return '';

    if (preg_match('/VERSION:\s*(v[0-9]+)/i', $data, $m) === 1) return $m[1];
    if (preg_match('/\bv([0-9]{3})\b/', $data, $m) === 1) return 'v' . $m[1];

    return '';
}

function mrlmc_hash($path) {
    if (!is_file($path) || !is_readable($path)) return '';
    $h = @hash_file('sha256', $path);
    return $h === false ? '' : $h;
}

function mrlmc_is_noise_path($rel) {
    $p = strtolower(str_replace('\\', '/', $rel));
    $base = basename($p);

    if (preg_match('#^20[0-9]{2}/#', $p) === 1) return true;
    if (strpos($p, '_safe_to_delete_') !== false) return true;

    if (
        strpos($p, '_race_finish_confirmation/') === 0 ||
        strpos($p, '_race_finish_confirmation_install_backup_') === 0
    ) return true;

    if (strpos($p, '_secondary_source_access_test/') === 0) return true;

    if (
        strpos($base, 'install_') === 0 ||
        strpos($base, 'installer') !== false ||
        strpos($base, '_backup_') !== false ||
        preg_match('/backup.*\.php$/', $base) === 1 ||
        preg_match('/\.bak(\.|$)/', $base) === 1 ||
        preg_match('/diagnostic.*\.php$/', $base) === 1 ||
        strpos($base, 'test_secondary_source_access') === 0
    ) return true;

    return false;
}

function mrlmc_is_codeish($rel) {
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    return in_array($ext, array('php','json','md','txt','html','js','css'), true);
}

function mrlmc_is_runtime_file($rel) {
    $p = strtolower(str_replace('\\', '/', $rel));
    $base = basename($p);

    $runtimeNames = array(
        '_mrl_nascar_live_status.json',
        '_mrl_nascar_live_status_raw.json',
        '_race_results_classification_last_run.json',
        '_race_results_classification_summary.json',
        '_race_results_monitor_heartbeat.txt',
        '_race_results_monitor_state.json',
        '_race_results_pair_classification_history.json',
        '_race_results_rd_status.json',
        '_race_results_revision_monitor_heartbeat.txt',
        '_race_results_schedule.json',
        '_scheduler/heartbeat.txt',
        '_scheduler/log.txt',
        '_scheduler/state.json'
    );

    if (in_array($p, $runtimeNames, true)) return true;

    if (
        strpos($base, 'heartbeat') !== false ||
        $base === 'state.json' ||
        $base === 'log.txt' ||
        strpos($base, '_history.json') !== false ||
        strpos($base, '_last_run.json') !== false ||
        strpos($base, '_status.json') !== false ||
        strpos($base, '_status_raw.json') !== false
    ) return true;

    return false;
}

function mrlmc_provisional_classification($rel, $status, $testVersion, $liveVersion) {
    $p = strtolower(str_replace('\\', '/', $rel));

    if (mrlmc_is_runtime_file($rel)) {
        return array(
            'classification' => 'KEEP_LIVE',
            'reason' => 'Operational/runtime state. LIVE remains authoritative.'
        );
    }

    if (mrlmc_is_noise_path($rel)) {
        return array(
            'classification' => 'OBSOLETE_OR_TEST_ONLY',
            'reason' => 'Installer/backup/diagnostic/experiment/history item; not a migration candidate.'
        );
    }

    $explicitMigrate = array(
        'race_results_monitor.php',
        'race_results_rd_helper.php',
        'race_schedule_helper.php'
    );

    if (in_array($p, $explicitMigrate, true)) {
        return array(
            'classification' => 'MIGRATE_CANDIDATE',
            'reason' => 'Verified TESTPHP8 code developed during the current migration phase.'
        );
    }

    if ($status === 'IDENTICAL') {
        return array(
            'classification' => 'NO_ACTION',
            'reason' => 'Same SHA-256 on TESTPHP8 and LIVE.'
        );
    }

    if ($status === 'LIVE_ONLY') {
        return array(
            'classification' => 'KEEP_LIVE',
            'reason' => 'LIVE-only active code/config; preserve unless dependency review says otherwise.'
        );
    }

    return array(
        'classification' => 'REVIEW',
        'reason' => 'Non-runtime code/config difference requires explicit dependency review.'
    );
}

function mrlmc_inventory_active_code($base) {
    $out = array();

    if (!is_dir($base) || !is_readable($base)) return $out;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $info) {
        if (!$info->isFile()) continue;

        $path = $info->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($base))), '/');

        if (!mrlmc_is_codeish($rel)) continue;
        if (mrlmc_is_noise_path($rel)) continue;
        if (preg_match('#^20[0-9]{2}/#', $rel) === 1) continue;

        $out[$rel] = array(
            'path' => $rel,
            'version' => mrlmc_read_version($path),
            'sha256' => mrlmc_hash($path),
            'size' => @filesize($path)
        );
    }

    ksort($out);
    return $out;
}

function mrlmc_reference_scan($base, $environment) {
    $terms = array(
        'race_finish_confirmation',
        'jayski',
        'racing-reference',
        'racing_reference',
        'race_schedule_helper.php',
        'race_results_rd_helper.php',
        'team_replacement_driver.php',
        '2 hour',
        '2-hour',
        '120 minute',
        '120-minute'
    );

    $rows = array();

    if (!is_dir($base) || !is_readable($base)) return $rows;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $info) {
        if (!$info->isFile()) continue;

        $path = $info->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($base))), '/');

        if (!mrlmc_is_codeish($rel)) continue;
        if (mrlmc_is_noise_path($rel)) continue;
        if (preg_match('#^20[0-9]{2}/#', $rel) === 1) continue;

        if (!is_readable($path)) continue;

        $data = @file_get_contents($path);
        if ($data === false) continue;

        $lines = preg_split('/\R/', $data);

        foreach ($lines as $i => $line) {
            $lower = strtolower($line);

            foreach ($terms as $term) {
                if (strpos($lower, strtolower($term)) !== false) {
                    $rows[] = array(
                        'environment' => $environment,
                        'path' => $rel,
                        'line' => $i + 1,
                        'term' => $term,
                        'text' => trim($line)
                    );
                    break;
                }
            }
        }
    }

    return $rows;
}

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: TESTPHP8-only. Current host: ' . $host;
}
if ($testRoot === '' || !is_dir($testRoot)) {
    $errors[] = 'TESTPHP8 document root unavailable.';
}
if (!is_dir($testRR) || !is_readable($testRR)) {
    $errors[] = 'TESTPHP8 race_results unavailable/unreadable.';
}
if (!is_dir($liveRR) || !is_readable($liveRR)) {
    $errors[] = 'LIVE race_results unavailable/unreadable.';
}

$testFiles = array();
$liveFiles = array();

if (empty($errors)) {
    try {
        $testFiles = mrlmc_inventory_active_code($testRR);
        $liveFiles = mrlmc_inventory_active_code($liveRR);
    } catch (Exception $e) {
        $errors[] = 'Inventory error: ' . $e->getMessage();
    }
}

$all = array();

foreach ($testFiles as $rel => $r) $all[$rel] = true;
foreach ($liveFiles as $rel => $r) $all[$rel] = true;
ksort($all);

foreach ($all as $rel => $dummy) {
    $t = isset($testFiles[$rel]) ? $testFiles[$rel] : null;
    $l = isset($liveFiles[$rel]) ? $liveFiles[$rel] : null;

    if ($t && $l) {
        $status = ($t['sha256'] !== '' && $t['sha256'] === $l['sha256'])
            ? 'IDENTICAL'
            : 'DIFFERENT';
    } elseif ($t) {
        $status = 'TEST_ONLY';
    } else {
        $status = 'LIVE_ONLY';
    }

    $classification = mrlmc_provisional_classification(
        $rel,
        $status,
        $t ? $t['version'] : '',
        $l ? $l['version'] : ''
    );

    if ($classification['classification'] === 'MIGRATE_CANDIDATE') {
        $summary['migrate_candidate']++;
    } elseif ($classification['classification'] === 'KEEP_LIVE') {
        $summary['keep_live']++;
    } elseif ($classification['classification'] === 'OBSOLETE_OR_TEST_ONLY') {
        $summary['obsolete_or_test_only']++;
    } elseif ($classification['classification'] === 'NO_ACTION') {
        $summary['identical_code']++;
    } else {
        $summary['review']++;
    }

    $rows[] = array(
        'path' => $rel,
        'status' => $status,
        'classification' => $classification['classification'],
        'reason' => $classification['reason'],
        'test' => $t,
        'live' => $l
    );
}

if (empty($errors)) {
    try {
        $referenceRows = array_merge(
            mrlmc_reference_scan($testRR, 'TESTPHP8'),
            mrlmc_reference_scan($liveRR, 'LIVE')
        );
    } catch (Exception $e) {
        $errors[] = 'Reference scan error: ' . $e->getMessage();
    }
}

$migrationRows = array();
$reviewRows = array();

foreach ($rows as $r) {
    if ($r['classification'] === 'MIGRATE_CANDIDATE') {
        $migrationRows[] = $r;
    }
    if ($r['classification'] !== 'NO_ACTION') {
        $reviewRows[] = $r;
    }
}

$resultData = array(
    'report' => 'MRL race_results Migration Candidate Audit',
    'report_version' => 'v001',
    'generated_at' => $generatedAt,
    'host' => $host,
    'test_root' => $testRR,
    'live_root' => $liveRR,
    'read_only' => true,
    'migration_principle' => array(
        'live_authoritative_for' => 'race data, snapshots, runtime state/history, generated current-season operational outputs',
        'test_authoritative_for' => 'newer verified code/config intentionally developed in TESTPHP8',
        'do_not_migrate' => 'old race-finish-confirmation/Racing-Reference/Jayski experiments, installers, backups, diagnostics, historical test debris'
    ),
    'errors' => $errors,
    'summary' => $summary,
    'migration_candidates' => $migrationRows,
    'focused_review_rows' => $reviewRows,
    'reference_hits' => $referenceRows
);

$export = isset($_GET['export']) ? strtolower((string)$_GET['export']) : '';

if ($export === 'json') {
    $filename = 'mrl_race_results_migration_candidate_audit_' . $exportStamp . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo json_encode($resultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($export === 'txt') {
    $filename = 'mrl_race_results_migration_candidate_audit_' . $exportStamp . '.txt';

    $lines = array();
    $lines[] = 'MRL race_results Migration Candidate Audit v001';
    $lines[] = 'Generated: ' . $generatedAt;
    $lines[] = 'TEST: ' . $testRR;
    $lines[] = 'LIVE: ' . $liveRR;
    $lines[] = '';
    $lines[] = 'SUMMARY';

    foreach ($summary as $k => $v) {
        $lines[] = $k . ': ' . $v;
    }

    $lines[] = '';
    $lines[] = 'MIGRATION CANDIDATES';

    foreach ($migrationRows as $r) {
        $lines[] =
            $r['path'] .
            ' | status=' . $r['status'] .
            ' | TEST=' . ($r['test'] ? $r['test']['version'] : '') .
            ' | LIVE=' . ($r['live'] ? $r['live']['version'] : '') .
            ' | ' . $r['reason'];
    }

    $lines[] = '';
    $lines[] = 'REFERENCE HITS';

    foreach ($referenceRows as $r) {
        $lines[] =
            $r['environment'] . ' | ' .
            $r['path'] . ':' . $r['line'] .
            ' | ' . $r['term'] .
            ' | ' . $r['text'];
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo implode("\r\n", $lines);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL race_results Migration Candidate Audit v001</title>
<style>
body{margin:0;background:#111;color:#eee;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1650px;margin:16px auto;padding:0 14px}
.banner{border:1px solid #396d36;background:#173717;border-radius:12px;padding:14px 18px}
.banner h1{margin:0 0 4px;color:#e9ffd9;font-size:28px}
.sub{color:#d9efc9}
.card{margin-top:14px;border:1px solid #444;background:#1b1b1b;border-radius:11px;padding:14px 18px}
.notice{margin-top:12px;border:1px solid #2f6590;background:#102c46;border-radius:9px;padding:11px 14px;color:#b9ddff}
.bad{margin-top:12px;border:1px solid #ad3e3e;background:#511818;border-radius:8px;padding:11px 14px;color:#ffb1b1}
h2{margin:2px 0 12px;font-size:24px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:7px 8px;border-bottom:1px solid #353535;text-align:left;vertical-align:top}
th{background:#242424}
.migrate{color:#78ef9c;font-weight:bold}
.keep{color:#8bc6ff;font-weight:bold}
.review{color:#ffd36f;font-weight:bold}
.obsolete{color:#ff9cb4;font-weight:bold}
.path{font-family:Consolas,monospace;color:#cddcff;word-break:break-all}
.summary{display:flex;gap:10px;flex-wrap:wrap}
.pill{border:1px solid #555;border-radius:999px;padding:7px 11px;background:#222}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.btn{display:inline-block;padding:9px 14px;border:1px solid #5e8ab0;border-radius:7px;background:#234c70;color:#fff;text-decoration:none;font-weight:bold}
.small{font-size:13px;color:#bbb}
.code{font-family:Consolas,monospace;word-break:break-word}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL race_results Migration Candidate Audit v001</h1>
<div class="sub">READ-ONLY • focused code/config reconciliation • LIVE runtime data remains authoritative</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="bad"><?=mrlmc_h($e)?></div>
<?php endforeach; ?>

<div class="notice">
<strong>Migration rule:</strong>
ignore TESTPHP8 race data/snapshots/runtime history; LIVE is authoritative for those.
This report focuses on newer code/config and dependency references that matter to the Live migration.
</div>

<div class="card">
<h2>Focused summary</h2>
<div class="summary">
<div class="pill">MIGRATE candidates: <strong><?=$summary['migrate_candidate']?></strong></div>
<div class="pill">KEEP LIVE: <strong><?=$summary['keep_live']?></strong></div>
<div class="pill">REVIEW: <strong><?=$summary['review']?></strong></div>
<div class="pill">Identical code: <strong><?=$summary['identical_code']?></strong></div>
</div>

<div class="actions">
<a class="btn" href="?export=json">Download JSON Results</a>
<a class="btn" href="?export=txt">Download TXT Results</a>
</div>
</div>

<div class="card">
<h2>Provisional migration candidates</h2>
<?php if (count($migrationRows) === 0): ?>
<div class="review">No automatic migration candidates found.</div>
<?php else: ?>
<table>
<thead>
<tr>
<th>Path</th>
<th style="width:105px">Status</th>
<th style="width:95px">TEST</th>
<th style="width:95px">LIVE</th>
<th>Reason</th>
</tr>
</thead>
<tbody>
<?php foreach ($migrationRows as $r): ?>
<tr>
<td class="path"><?=mrlmc_h($r['path'])?></td>
<td><?=mrlmc_h($r['status'])?></td>
<td><?=mrlmc_h($r['test'] ? $r['test']['version'] : '')?></td>
<td><?=mrlmc_h($r['live'] ? $r['live']['version'] : '')?></td>
<td class="migrate"><?=mrlmc_h($r['reason'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="card">
<h2>All focused non-identical code/config items</h2>
<table>
<thead>
<tr>
<th>Path</th>
<th style="width:105px">Status</th>
<th style="width:185px">Classification</th>
<th style="width:95px">TEST</th>
<th style="width:95px">LIVE</th>
<th>Reason</th>
</tr>
</thead>
<tbody>
<?php foreach ($reviewRows as $r): ?>
<?php
$cls = 'review';
if ($r['classification'] === 'MIGRATE_CANDIDATE') $cls = 'migrate';
elseif ($r['classification'] === 'KEEP_LIVE') $cls = 'keep';
elseif ($r['classification'] === 'OBSOLETE_OR_TEST_ONLY') $cls = 'obsolete';
?>
<tr>
<td class="path"><?=mrlmc_h($r['path'])?></td>
<td><?=mrlmc_h($r['status'])?></td>
<td class="<?=$cls?>"><?=mrlmc_h($r['classification'])?></td>
<td><?=mrlmc_h($r['test'] ? $r['test']['version'] : '')?></td>
<td><?=mrlmc_h($r['live'] ? $r['live']['version'] : '')?></td>
<td><?=mrlmc_h($r['reason'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="card">
<h2>Dependency / obsolete-feature reference scan</h2>
<?php if (count($referenceRows) === 0): ?>
<div class="migrate">No matching references found in active code/config.</div>
<?php else: ?>
<table>
<thead>
<tr>
<th style="width:100px">Environment</th>
<th>Path</th>
<th style="width:70px">Line</th>
<th style="width:190px">Matched term</th>
<th>Source line</th>
</tr>
</thead>
<tbody>
<?php foreach ($referenceRows as $r): ?>
<tr>
<td><?=mrlmc_h($r['environment'])?></td>
<td class="path"><?=mrlmc_h($r['path'])?></td>
<td><?=mrlmc_h($r['line'])?></td>
<td class="path"><?=mrlmc_h($r['term'])?></td>
<td class="code"><?=mrlmc_h($r['text'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="card small">
<strong>Next step:</strong> upload the JSON export here. I will turn the provisional list into the final race_results migration classification and identify any dependency edits needed before the controlled Live installer is built.
</div>

</div>
</body>
</html>
