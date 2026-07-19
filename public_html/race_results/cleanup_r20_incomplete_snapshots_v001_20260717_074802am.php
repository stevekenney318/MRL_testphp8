<?php
declare(strict_types=1);

/**
 * MRL R20 Incomplete Snapshot Cleanup Installer
 *
 * VERSION: v001
 * GENERATED: 7/17/2026 7:48:02 AM America/New_York
 *
 * PURPOSE:
 *   Archive R20 Atlanta's first two incomplete snapshots and rebuild active
 *   R20 metadata so the 2:04:04 am snapshot becomes the first accepted snapshot.
 *
 * TARGET:
 *   Upload this file to /race_results/ and open it once in a browser.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');
ini_set('display_errors', '1');
error_reporting(E_ALL);

const INSTALLER_VERSION = 'v001';
const TARGET_YEAR = 2026;
const TARGET_RACE_CODE = 'R20';
const TARGET_ARCHIVE_NAME = 'R20_Atlanta';

$removedStamps = [
    '20260713_015202674',
    '20260713_015804895',
];

$retainedStamps = [
    '20260713_020404456',
    '20260713_021609696',
    '20260713_022204362',
    '20260713_022805630',
];

$runStamp = date('Ymd_His');
$baseDir = __DIR__;
$yearDir = $baseDir . '/' . TARGET_YEAR;
$archiveBase = $baseDir . '/_archive/' . TARGET_YEAR . '/' . TARGET_ARCHIVE_NAME;
$archiveRun = $archiveBase . '/cleanup_' . $runStamp;
$fullBackupDir = $archiveRun . '/full_race_folder_backup';
$removedDir = $archiveBase . '/removed_incomplete_snapshots';
$sharedBackupDir = $archiveRun . '/shared_file_backups';

$report = [];
$errors = [];
$warnings = [];

function out_row(string $status, string $message): void
{
    global $report;
    $report[] = [$status, $message];
}

function fail_now(string $message): void
{
    global $errors;
    $errors[] = $message;
    throw new RuntimeException($message);
}

function ensure_dir(string $dir): void
{
    if (is_dir($dir)) return;
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail_now('Could not create directory: ' . $dir);
    }
}

function copy_file_preserve(string $src, string $dst): void
{
    ensure_dir(dirname($dst));
    if (!@copy($src, $dst)) {
        fail_now('Could not copy: ' . $src . ' -> ' . $dst);
    }
    $mtime = @filemtime($src);
    if ($mtime !== false) @touch($dst, $mtime);
}

function copy_tree(string $src, string $dst): void
{
    ensure_dir($dst);
    $items = @scandir($src);
    if (!is_array($items)) fail_now('Could not scan directory: ' . $src);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $from = $src . '/' . $item;
        $to = $dst . '/' . $item;
        if (is_dir($from)) {
            copy_tree($from, $to);
        } elseif (is_file($from)) {
            copy_file_preserve($from, $to);
        }
    }
}

function write_atomic(string $path, string $contents): void
{
    ensure_dir(dirname($path));
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
        fail_now('Could not write temporary file: ' . $tmp);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        fail_now('Could not replace file atomically: ' . $path);
    }
}

function load_json_file(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_json_file(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) fail_now('JSON encoding failed for: ' . $path);
    write_atomic($path, $json . "\n");
}

function value_contains_tokens($value, array $tokens): bool
{
    if (is_string($value)) {
        foreach ($tokens as $token) {
            if ($token !== '' && strpos($value, $token) !== false) return true;
        }
        return false;
    }
    if (is_array($value)) {
        foreach ($value as $v) {
            if (value_contains_tokens($v, $tokens)) return true;
        }
    }
    return false;
}

function prune_tokens($value, array $tokens)
{
    if (!is_array($value)) return $value;

    $isList = array_keys($value) === range(0, count($value) - 1);
    $out = [];

    foreach ($value as $key => $item) {
        if ($isList && value_contains_tokens($item, $tokens)) {
            continue;
        }

        if (!$isList && is_string($item) && value_contains_tokens($item, $tokens)) {
            continue;
        }

        $clean = is_array($item) ? prune_tokens($item, $tokens) : $item;
        if ($isList) {
            $out[] = $clean;
        } else {
            $out[$key] = $clean;
        }
    }
    return $out;
}

function normalized_table_hash(string $htmlPath): string
{
    $html = @file_get_contents($htmlPath);
    if ($html === false) fail_now('Could not read snapshot for hash: ' . $htmlPath);

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    if (!$ok) fail_now('Could not parse snapshot HTML: ' . $htmlPath);

    $xp = new DOMXPath($dom);
    $tables = $xp->query('//table');
    if (!$tables || $tables->length === 0) fail_now('No table found in snapshot: ' . $htmlPath);

    $best = null;
    for ($i = 0; $i < $tables->length; $i++) {
        $table = $tables->item($i);
        if (!$table instanceof DOMElement) continue;
        $txt = strtoupper((string)$table->textContent);
        if (strpos($txt, 'DRIVER') !== false && strpos($txt, 'PTS') !== false && strpos($txt, 'BONUS') !== false) {
            $best = $table;
            break;
        }
    }
    if (!$best instanceof DOMElement) fail_now('Scoring table not found in snapshot: ' . $htmlPath);

    $rows = $xp->query('.//tr', $best);
    $lines = [];
    if ($rows) {
        for ($r = 0; $r < $rows->length; $r++) {
            $row = $rows->item($r);
            if (!$row instanceof DOMElement) continue;
            $cells = $xp->query('./th|./td', $row);
            if (!$cells || $cells->length === 0) continue;
            $vals = [];
            for ($c = 0; $c < $cells->length; $c++) {
                $s = html_entity_decode((string)$cells->item($c)->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $s = trim((string)preg_replace('/\s+/', ' ', $s));
                $vals[] = $s;
            }
            $lines[] = implode("\t", $vals);
        }
    }
    return hash('sha256', implode("\n", $lines));
}

function invoke_by_parameter_names(string $functionName, array $context)
{
    if (!function_exists($functionName)) {
        throw new RuntimeException('Function not available: ' . $functionName);
    }

    $ref = new ReflectionFunction($functionName);
    $args = [];

    foreach ($ref->getParameters() as $param) {
        $name = strtolower($param->getName());
        $valueSet = false;
        $value = null;

        foreach ($context as $key => $candidate) {
            if (strtolower((string)$key) === $name) {
                $value = $candidate;
                $valueSet = true;
                break;
            }
        }

        if (!$valueSet) {
            if (strpos($name, 'base') !== false || strpos($name, 'root') !== false || strpos($name, 'scriptdir') !== false) {
                $value = $context['baseDir'];
                $valueSet = true;
            } elseif (strpos($name, 'yearfolder') !== false || strpos($name, 'yeardir') !== false) {
                $value = $context['yearFolder'];
                $valueSet = true;
            } elseif ($name === 'year' || strpos($name, 'year') !== false) {
                $value = $context['year'];
                $valueSet = true;
            } elseif (strpos($name, 'history') !== false && array_key_exists('history', $context)) {
                $value = $context['history'];
                $valueSet = true;
            } elseif (strpos($name, 'race') !== false && array_key_exists('raceCode', $context)) {
                $value = $context['raceCode'];
                $valueSet = true;
            }
        }

        if (!$valueSet) {
            if ($param->isDefaultValueAvailable()) {
                $value = $param->getDefaultValue();
                $valueSet = true;
            } elseif ($param->allowsNull()) {
                $value = null;
                $valueSet = true;
            }
        }

        if (!$valueSet) {
            throw new RuntimeException('Could not map parameter $' . $param->getName() . ' for ' . $functionName);
        }

        $args[] = $value;
    }

    return $ref->invokeArgs($args);
}

function render_page(): void
{
    global $report, $errors, $warnings, $archiveRun;

    $ok = empty($errors);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>MRL R20 Snapshot Cleanup</title>';
    echo '<style>
        body{font-family:Arial,sans-serif;margin:24px;background:#f3f3f3;color:#111}
        h1{margin:0 0 8px}.sub{margin:0 0 18px;color:#444}
        .box{background:#fff;border:1px solid #bbb;border-radius:8px;padding:16px;margin-bottom:16px}
        table{border-collapse:collapse;width:100%;background:#fff}
        th,td{border:1px solid #ccc;padding:8px;text-align:left;vertical-align:top}
        th{background:#ddd}.ok{color:#087c2c;font-weight:bold}.warn{color:#a66300;font-weight:bold}
        .err{color:#b00000;font-weight:bold}.final{font-size:20px;font-weight:bold}
        code{background:#eee;padding:2px 4px}
    </style></head><body>';
    echo '<h1>MRL R20 Incomplete Snapshot Cleanup</h1>';
    echo '<p class="sub">Installer ' . htmlspecialchars(INSTALLER_VERSION, ENT_QUOTES, 'UTF-8') . '</p>';

    echo '<div class="box final ' . ($ok ? 'ok' : 'err') . '">';
    echo $ok ? 'CLEANUP COMPLETE' : 'CLEANUP STOPPED';
    echo '</div>';

    if (!empty($errors)) {
        echo '<div class="box"><h2>Errors</h2><ul>';
        foreach ($errors as $e) echo '<li class="err">' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '</li>';
        echo '</ul></div>';
    }

    if (!empty($warnings)) {
        echo '<div class="box"><h2>Warnings</h2><ul>';
        foreach ($warnings as $w) echo '<li class="warn">' . htmlspecialchars($w, ENT_QUOTES, 'UTF-8') . '</li>';
        echo '</ul></div>';
    }

    echo '<table><thead><tr><th>Status</th><th>Action</th></tr></thead><tbody>';
    foreach ($report as $row) {
        $cls = $row[0] === 'OK' ? 'ok' : ($row[0] === 'WARN' ? 'warn' : 'err');
        echo '<tr><td class="' . $cls . '">' . htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    echo '</tbody></table>';

    if ($ok) {
        echo '<div class="box"><strong>Archive/backup location:</strong><br><code>'
            . htmlspecialchars($archiveRun, ENT_QUOTES, 'UTF-8') . '</code><br><br>'
            . 'Next checks: open Revision Summary, Standings Timeline, Weekly Standings, and MRL At a Glance. '
            . 'R20 should show four active snapshots beginning at 2:04:04 am.</div>';
    }

    echo '</body></html>';
}

try {
    if (!is_dir($yearDir)) fail_now('Year directory not found: ' . $yearDir);

    $raceCandidates = glob($yearDir . '/R20_*Atlanta*');
    if (!is_array($raceCandidates) || count($raceCandidates) !== 1 || !is_dir($raceCandidates[0])) {
        fail_now('Expected exactly one R20 Atlanta race folder; found ' . (is_array($raceCandidates) ? count($raceCandidates) : 0) . '.');
    }

    $raceFolder = $raceCandidates[0];
    out_row('OK', 'Target race folder: ' . basename($raceFolder));

    $allExpected = array_merge($removedStamps, $retainedStamps);
    foreach ($allExpected as $stamp) {
        $path = $raceFolder . '/snapshot_' . $stamp . '.html';
        if (!is_file($path)) fail_now('Required canonical snapshot missing: ' . basename($path));
    }
    out_row('OK', 'Verified all six expected canonical snapshots.');

    ensure_dir($archiveRun);
    ensure_dir($fullBackupDir);
    ensure_dir($removedDir);
    ensure_dir($sharedBackupDir);

    copy_tree($raceFolder, $fullBackupDir);
    out_row('OK', 'Complete R20 race-folder backup created.');

    $sharedCandidates = [
        $yearDir . '/_weekly_standings_release_history.json',
        $yearDir . '/_race_results_classification_summary.json',
        $yearDir . '/_race_results_classification_last_run.json',
        $baseDir . '/_race_results_monitor_state.json',
        $baseDir . '/_race_results_revision_monitor_state.json',
    ];

    foreach ($sharedCandidates as $shared) {
        if (is_file($shared)) {
            copy_file_preserve($shared, $sharedBackupDir . '/' . basename($shared));
            out_row('OK', 'Backed up shared file: ' . basename($shared));
        }
    }

    $movedCount = 0;
    $items = scandir($raceFolder);
    if (!is_array($items)) fail_now('Could not scan target race folder.');

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $raceFolder . '/' . $item;
        if (!is_file($src)) continue;

        $matchesRemoved = false;
        foreach ($removedStamps as $stamp) {
            if (strpos($item, $stamp) !== false) {
                $matchesRemoved = true;
                break;
            }
        }
        if (!$matchesRemoved) continue;

        $dst = $removedDir . '/' . $item;
        if (is_file($dst)) {
            $dst = $removedDir . '/' . $runStamp . '_' . $item;
        }
        if (!@rename($src, $dst)) {
            copy_file_preserve($src, $dst);
            if (!@unlink($src)) fail_now('Could not remove archived source file: ' . $src);
        }
        $movedCount++;
        out_row('OK', 'Archived inactive file: ' . $item);
    }

    if ($movedCount < 2) fail_now('Fewer than two timestamp families were archived.');
    out_row('OK', 'Archived ' . $movedCount . ' files tied to the two incomplete snapshots.');

    $artifactFiles = [
        'mrl_impact_summary.json',
        'mrl_impact_pair_history.json',
        'mrl_impact_hash.txt',
        'mrl_impact_diff.txt',
        'mrl_impact_data.json',
        'all_driver_impact_summary.json',
        'all_driver_impact_diff.txt',
        'all_driver_impact_data_old.json',
        'all_driver_impact_data_new.json',
        'revision_meta.json',
    ];

    foreach ($artifactFiles as $name) {
        $path = $raceFolder . '/' . $name;
        if (is_file($path)) {
            @unlink($path);
            out_row('OK', 'Removed stale derived artifact for rebuild: ' . $name);
        }
    }

    foreach (glob($raceFolder . '/under_review.flag*') ?: [] as $flag) {
        if (is_file($flag)) {
            @unlink($flag);
            out_row('OK', 'Removed stale review flag artifact: ' . basename($flag));
        }
    }

    $latestSnapshot = $raceFolder . '/snapshot_' . end($retainedStamps) . '.html';
    $latestHash = normalized_table_hash($latestSnapshot);
    write_atomic($raceFolder . '/final_table_hash.txt', $latestHash . "\n");
    out_row('OK', 'Rebuilt final_table_hash.txt from the latest retained snapshot.');

    $jsonTargets = [
        $baseDir . '/_race_results_monitor_state.json',
        $baseDir . '/_race_results_revision_monitor_state.json',
    ];
    foreach ($jsonTargets as $jsonPath) {
        if (!is_file($jsonPath)) continue;
        $data = load_json_file($jsonPath);
        if (empty($data)) continue;
        $clean = prune_tokens($data, $removedStamps);
        save_json_file($jsonPath, $clean);
        out_row('OK', 'Removed inactive snapshot references from ' . basename($jsonPath));
    }

    $docRoot = realpath($baseDir . '/..');
    if ($docRoot === false) $docRoot = dirname($baseDir);

    foreach (['config.php', 'config_mrl.php', 'class.user.php'] as $required) {
        $path = $docRoot . '/' . $required;
        if (is_file($path)) require_once $path;
    }

    if (is_file($baseDir . '/race_results_engine.php')) {
        require_once $baseDir . '/race_results_engine.php';
    }
    if (is_file($baseDir . '/race_results_classify_revisions.php')) {
        require_once $baseDir . '/race_results_classify_revisions.php';
    }
    if (is_file($baseDir . '/weekly_standings_release_history_helper.php')) {
        require_once $baseDir . '/weekly_standings_release_history_helper.php';
    }

    if (function_exists('rrcr_run') && isset($dbo) && $dbo instanceof PDO) {
        $result = rrcr_run([
            'year' => (string)TARGET_YEAR,
            'race_code' => TARGET_RACE_CODE,
            'verbose' => false,
            'write_artifacts' => true,
            'base_dir' => $baseDir,
        ], $dbo);
        out_row('OK', 'R20 classifier rebuild completed.');
    } else {
        $warnings[] = 'Classifier rebuild could not run automatically; rrcr_run() or PDO was unavailable.';
        out_row('WARN', 'Classifier rebuild skipped because required runtime components were unavailable.');
    }

    if (function_exists('wsrel_build_history_from_artifacts')) {
        $context = [
            'baseDir' => $baseDir,
            'base_dir' => $baseDir,
            'yearFolder' => $yearDir,
            'year_folder' => $yearDir,
            'year' => TARGET_YEAR,
            'raceCode' => TARGET_RACE_CODE,
        ];

        $history = invoke_by_parameter_names('wsrel_build_history_from_artifacts', $context);
        $context['history'] = $history;

        if (is_array($history) && function_exists('wsrel_write_history')) {
            invoke_by_parameter_names('wsrel_write_history', $context);
            out_row('OK', 'Weekly standings release history rebuilt from active artifacts.');
        } elseif (is_array($history)) {
            $historyPath = $yearDir . '/_weekly_standings_release_history.json';
            save_json_file($historyPath, $history);
            out_row('OK', 'Weekly standings release history rebuilt and written directly.');
        } else {
            $warnings[] = 'Release-history helper returned no array; verify release history manually.';
            out_row('WARN', 'Release-history helper did not return a rebuild payload.');
        }
    } else {
        $warnings[] = 'wsrel_build_history_from_artifacts() was unavailable.';
        out_row('WARN', 'Weekly release-history rebuild helper unavailable.');
    }

    $activeSnapshots = glob($raceFolder . '/snapshot_*.html') ?: [];
    $activeCanonical = [];
    foreach ($activeSnapshots as $path) {
        $name = basename($path);
        if (preg_match('/^snapshot_\d{8}_\d{9}\.html$/', $name)) {
            $activeCanonical[] = $name;
        }
    }
    sort($activeCanonical, SORT_STRING);

    if (count($activeCanonical) !== 4) {
        fail_now('Final verification expected four active canonical snapshots; found ' . count($activeCanonical) . '.');
    }

    foreach ($removedStamps as $stamp) {
        foreach ($activeCanonical as $name) {
            if (strpos($name, $stamp) !== false) fail_now('Removed timestamp still active: ' . $name);
        }
    }

    $expectedActive = [];
    foreach ($retainedStamps as $stamp) $expectedActive[] = 'snapshot_' . $stamp . '.html';
    if ($activeCanonical !== $expectedActive) {
        fail_now('Final active snapshot list does not match the four expected retained snapshots.');
    }

    out_row('OK', 'Final verification: exactly four active canonical snapshots remain.');
    out_row('OK', 'Earliest active snapshot is now snapshot_' . $retainedStamps[0] . '.html.');
    out_row('OK', 'Latest active snapshot remains snapshot_' . end($retainedStamps) . '.html.');
    out_row('OK', 'Original retained snapshot filenames and file times were preserved.');

} catch (Throwable $e) {
    if (!in_array($e->getMessage(), $errors, true)) {
        $errors[] = $e->getMessage();
    }
}

render_page();
