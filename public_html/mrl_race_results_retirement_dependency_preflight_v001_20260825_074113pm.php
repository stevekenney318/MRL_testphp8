<?php
/**
 * MRL race_results Retirement / Dependency Preflight
 *
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 7:41:13 pm
 *
 * CHANGELOG:
 * v001 (8/25/2026 7:41:13 pm)
 * - Initial read-only preflight for the TESTPHP8 -> LIVE race_results consolidation.
 * - Identifies active LIVE race_finish_confirmation scheduler/menu/code references.
 * - Separates retirement actions from preserved historical/runtime evidence.
 * - Verifies the three-file migration dependency chain:
 *     race_results_monitor.php v139
 *       -> race_results_rd_helper.php v005
 *       -> race_schedule_helper.php v003
 *       -> _race_results_schedule.json
 * - Verifies the TEST candidate hashes captured by the 8/25 migration-candidate audit.
 * - Detects unexpected LIVE drift from the audit baseline before any installer is built.
 * - Provides timestamped JSON and TXT exports generated in memory.
 * - Makes NO filesystem or database changes.
 *
 * SAFETY:
 * - READ ONLY.
 * - Does not edit scheduler configuration.
 * - Does not delete/archive files.
 * - Does not copy migration candidates to LIVE.
 * - Does not write report files to the server.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

const MRL_RDP_VERSION = 'v001';
const MRL_RDP_TITLE = 'MRL race_results Retirement / Dependency Preflight';

function rdp_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rdp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function rdp_file_sha256(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $hash = hash_file('sha256', $path);
    return is_string($hash) ? $hash : '';
}

function rdp_read_file(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $data = @file_get_contents($path);
    return is_string($data) ? $data : '';
}

function rdp_extract_version(string $path): string
{
    $text = rdp_read_file($path);
    if ($text === '') {
        return '';
    }

    $patterns = [
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return strtolower((string)$m[1]);
        }
    }

    return '';
}

function rdp_file_info(string $path): array
{
    if (!is_file($path)) {
        return [
            'exists' => false,
            'readable' => false,
            'path' => rdp_normalize_path($path),
            'version' => '',
            'size' => null,
            'sha256' => '',
            'mtime' => null,
        ];
    }

    $size = @filesize($path);
    $mtime = @filemtime($path);

    return [
        'exists' => true,
        'readable' => is_readable($path),
        'path' => rdp_normalize_path($path),
        'version' => rdp_extract_version($path),
        'size' => is_int($size) ? $size : null,
        'sha256' => rdp_file_sha256($path),
        'mtime' => is_int($mtime) ? date('Y-m-d H:i:s T', $mtime) : null,
    ];
}

function rdp_json_file(string $path): array
{
    $result = [
        'exists' => is_file($path),
        'readable' => is_readable($path),
        'valid_json' => false,
        'data' => null,
        'error' => '',
    ];

    if (!$result['exists']) {
        $result['error'] = 'File not found.';
        return $result;
    }

    $text = rdp_read_file($path);
    if ($text === '') {
        $result['error'] = 'File is empty or unreadable.';
        return $result;
    }

    $data = json_decode($text, true);
    if (!is_array($data)) {
        $result['error'] = 'JSON parse error: ' . json_last_error_msg();
        return $result;
    }

    $result['valid_json'] = true;
    $result['data'] = $data;
    return $result;
}

function rdp_array_has_recursive_key($value, string $needle): bool
{
    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $key => $child) {
        if ((string)$key === $needle) {
            return true;
        }
        if (is_array($child) && rdp_array_has_recursive_key($child, $needle)) {
            return true;
        }
    }

    return false;
}

function rdp_find_recursive_key($value, string $needle, string $path = ''): array
{
    $hits = [];
    if (!is_array($value)) {
        return $hits;
    }

    foreach ($value as $key => $child) {
        $current = $path === '' ? (string)$key : $path . '.' . (string)$key;

        if ((string)$key === $needle) {
            $hits[] = [
                'path' => $current,
                'value' => $child,
            ];
        }

        if (is_array($child)) {
            $hits = array_merge($hits, rdp_find_recursive_key($child, $needle, $current));
        }
    }

    return $hits;
}

function rdp_scan_text_hits(string $path, array $terms): array
{
    $hits = [];
    $text = rdp_read_file($path);
    if ($text === '') {
        return $hits;
    }

    $lines = preg_split('/\R/', $text);
    if (!is_array($lines)) {
        return $hits;
    }

    foreach ($lines as $index => $line) {
        foreach ($terms as $term) {
            if (stripos($line, $term) !== false) {
                $hits[] = [
                    'line' => $index + 1,
                    'term' => $term,
                    'text' => trim($line),
                ];
            }
        }
    }

    return $hits;
}

function rdp_is_active_root_php(string $filename): bool
{
    $lower = strtolower($filename);

    if (substr($lower, -4) !== '.php') {
        return false;
    }

    $excludeFragments = [
        'install',
        'installer',
        'backup',
        '.bak',
        '_bak',
        'probe',
        'diagnostic',
        'audit',
        'preflight',
        'migration_readiness',
        'cleanup',
        'clean_',
        'test_',
        '_test',
        'debug',
    ];

    foreach ($excludeFragments as $fragment) {
        if (strpos($lower, $fragment) !== false) {
            return false;
        }
    }

    return true;
}

function rdp_scan_active_root_php(string $root, array $terms): array
{
    $hits = [];
    if (!is_dir($root)) {
        return $hits;
    }

    $entries = @scandir($root);
    if (!is_array($entries)) {
        return $hits;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || !rdp_is_active_root_php($entry)) {
            continue;
        }

        $full = $root . '/' . $entry;
        if (!is_file($full)) {
            continue;
        }

        $fileHits = rdp_scan_text_hits($full, $terms);
        foreach ($fileHits as $hit) {
            $hits[] = [
                'path' => $entry,
                'line' => $hit['line'],
                'term' => $hit['term'],
                'text' => $hit['text'],
            ];
        }
    }

    return $hits;
}

function rdp_bool_label(bool $value): string
{
    return $value ? 'PASS' : 'FAIL';
}

function rdp_export_txt(array $report): string
{
    $out = [];
    $out[] = MRL_RDP_TITLE;
    $out[] = 'Version: ' . MRL_RDP_VERSION;
    $out[] = 'Generated: ' . (string)$report['generated_at'];
    $out[] = 'Read only: YES';
    $out[] = '';
    $out[] = 'OVERALL: ' . (string)$report['overall']['status'];
    $out[] = 'Migration dependency gate: ' . (string)$report['overall']['migration_dependency_gate'];
    $out[] = 'Retirement review gate: ' . (string)$report['overall']['retirement_review_gate'];
    $out[] = '';

    $out[] = 'THREE-FILE MIGRATION CHAIN';
    foreach ($report['migration_chain']['files'] as $row) {
        $out[] = sprintf(
            '- %s | TEST %s | LIVE %s | TEST hash %s | baseline %s',
            $row['path'],
            $row['test']['version'] !== '' ? $row['test']['version'] : '(none)',
            $row['live']['version'] !== '' ? $row['live']['version'] : '(missing)',
            $row['test_hash_matches_audit'] ? 'PASS' : 'FAIL',
            $row['live_matches_audit_baseline'] ? 'UNCHANGED' : 'DRIFT'
        );
    }
    $out[] = '';

    $out[] = 'DEPENDENCY MARKERS';
    foreach ($report['migration_chain']['dependency_checks'] as $row) {
        $out[] = '- ' . $row['check'] . ': ' . $row['status'] . ' - ' . $row['detail'];
    }
    $out[] = '';

    $out[] = 'LIVE RACE_FINISH_CONFIRMATION RETIREMENT';
    $out[] = 'Scheduler schedule reference: ' . ($report['retirement']['scheduler_schedule_reference']['present'] ? 'PRESENT' : 'NOT FOUND');
    $out[] = 'Scheduler state reference: ' . ($report['retirement']['scheduler_state_reference']['present'] ? 'PRESENT' : 'NOT FOUND');
    $out[] = 'Menu reference: ' . ($report['retirement']['menu_reference']['present'] ? 'PRESENT' : 'NOT FOUND');
    $out[] = 'Unexpected active references: ' . count($report['retirement']['unexpected_active_references']);
    $out[] = '';

    $out[] = 'PROPOSED RETIREMENT ACTIONS (NO ACTIONS PERFORMED)';
    foreach ($report['retirement']['proposed_actions'] as $action) {
        $out[] = '- ' . $action;
    }
    $out[] = '';

    if (!empty($report['warnings'])) {
        $out[] = 'WARNINGS';
        foreach ($report['warnings'] as $warning) {
            $out[] = '- ' . $warning;
        }
        $out[] = '';
    }

    return implode("\r\n", $out) . "\r\n";
}

/* -------------------------------------------------------------------------
 * Resolve environment.
 * Expected upload location:
 *   /public_html/testphp8/race_results/
 * ---------------------------------------------------------------------- */

$selfDir = rtrim(rdp_normalize_path(__DIR__), '/');
$isExpectedTestLocation = (bool)preg_match('#/public_html/testphp8/race_results$#', $selfDir);

$testRoot = $selfDir;
$testPublicRoot = dirname($testRoot);
$publicHtmlRoot = dirname($testPublicRoot);
$liveRoot = $publicHtmlRoot . '/race_results';

$generatedAt = date('Y-m-d H:i:s T');
$exportStamp = date('Ymd_hisA');
$exportStamp = strtolower($exportStamp);

/* -------------------------------------------------------------------------
 * Baseline captured from:
 * mrl_race_results_migration_candidate_audit_20260825_073431pm.json
 * ---------------------------------------------------------------------- */

$baseline = [
    'race_results_monitor.php' => [
        'test_version' => 'v139',
        'test_sha256' => '5b5a11d6dc9257a88465c08ddf3357fad9c7faacf75e6b56b461bf84a482678e',
        'live_version' => 'v138',
        'live_sha256' => '57d5f61289e0b21d3760a3059a838e8fc99f9cfca9f22c19550de5489a919d7a',
    ],
    'race_results_rd_helper.php' => [
        'test_version' => 'v005',
        'test_sha256' => 'f82b6f238ca577018d8e2aa2fb8a89bc925f09d2f4f49602dbceba81d77de08d',
        'live_version' => 'v003',
        'live_sha256' => 'a41651346006b57cd0b661bbf99e4e7863dbcd0b53506012878248e164b41d66',
    ],
    'race_schedule_helper.php' => [
        'test_version' => 'v003',
        'test_sha256' => '9ed17b411be162140f73173808e974e0468d3c335872dc0ef31487375933ec62',
        'live_version' => '',
        'live_sha256' => '',
    ],
];

$migrationRows = [];
$allTestCandidatesMatch = true;
$allLiveBaselinesMatch = true;

foreach ($baseline as $filename => $expected) {
    $testInfo = rdp_file_info($testRoot . '/' . $filename);
    $liveInfo = rdp_file_info($liveRoot . '/' . $filename);

    $testHashMatches = $testInfo['exists']
        && $testInfo['sha256'] === $expected['test_sha256']
        && strtolower((string)$testInfo['version']) === strtolower($expected['test_version']);

    if ($expected['live_sha256'] === '') {
        $liveMatchesBaseline = !$liveInfo['exists'];
    } else {
        $liveMatchesBaseline = $liveInfo['exists']
            && $liveInfo['sha256'] === $expected['live_sha256']
            && strtolower((string)$liveInfo['version']) === strtolower($expected['live_version']);
    }

    if (!$testHashMatches) {
        $allTestCandidatesMatch = false;
    }
    if (!$liveMatchesBaseline) {
        $allLiveBaselinesMatch = false;
    }

    $migrationRows[] = [
        'path' => $filename,
        'expected' => $expected,
        'test' => $testInfo,
        'live' => $liveInfo,
        'test_hash_matches_audit' => $testHashMatches,
        'live_matches_audit_baseline' => $liveMatchesBaseline,
    ];
}

/* -------------------------------------------------------------------------
 * Dependency chain checks.
 * ---------------------------------------------------------------------- */

$dependencyChecks = [];

$testMonitorPath = $testRoot . '/race_results_monitor.php';
$testRdHelperPath = $testRoot . '/race_results_rd_helper.php';
$testScheduleHelperPath = $testRoot . '/race_schedule_helper.php';

$monitorText = rdp_read_file($testMonitorPath);
$rdHelperText = rdp_read_file($testRdHelperPath);
$scheduleHelperText = rdp_read_file($testScheduleHelperPath);

$monitorRequiresRd = strpos($monitorText, "race_results_rd_helper.php") !== false;
$dependencyChecks[] = [
    'check' => 'TEST race_results_monitor.php -> race_results_rd_helper.php',
    'status' => rdp_bool_label($monitorRequiresRd),
    'detail' => $monitorRequiresRd
        ? 'Shared RD helper reference is present.'
        : 'Expected shared RD helper reference was not found.',
];

$rdRequiresSchedule = strpos($rdHelperText, "race_schedule_helper.php") !== false;
$dependencyChecks[] = [
    'check' => 'TEST race_results_rd_helper.php -> race_schedule_helper.php',
    'status' => rdp_bool_label($rdRequiresSchedule),
    'detail' => $rdRequiresSchedule
        ? 'Shared schedule helper reference is present.'
        : 'Expected schedule helper reference was not found.',
];

$scheduleUsesCanonical = strpos($scheduleHelperText, '_race_results_schedule.json') !== false;
$dependencyChecks[] = [
    'check' => 'TEST race_schedule_helper.php -> canonical _race_results_schedule.json',
    'status' => rdp_bool_label($scheduleUsesCanonical),
    'detail' => $scheduleUsesCanonical
        ? 'Canonical root race-results schedule reference is present.'
        : 'Canonical schedule reference was not found.',
];

$scheduleUsesPointsRaces = strpos($scheduleHelperText, 'mrl_points_races') !== false;
$dependencyChecks[] = [
    'check' => 'TEST race_schedule_helper.php uses mrl_points_races',
    'status' => rdp_bool_label($scheduleUsesPointsRaces),
    'detail' => $scheduleUsesPointsRaces
        ? 'mrl_points_races reference is present.'
        : 'mrl_points_races reference was not found.',
];

$liveSchedulePath = $liveRoot . '/_race_results_schedule.json';
$testSchedulePath = $testRoot . '/_race_results_schedule.json';

$liveSchedule = rdp_json_file($liveSchedulePath);
$testSchedule = rdp_json_file($testSchedulePath);

$livePointsRaces = $liveSchedule['valid_json']
    && isset($liveSchedule['data']['mrl_points_races'])
    && is_array($liveSchedule['data']['mrl_points_races'])
    && count($liveSchedule['data']['mrl_points_races']) > 0;

$testPointsRaces = $testSchedule['valid_json']
    && isset($testSchedule['data']['mrl_points_races'])
    && is_array($testSchedule['data']['mrl_points_races'])
    && count($testSchedule['data']['mrl_points_races']) > 0;

$dependencyChecks[] = [
    'check' => 'LIVE canonical schedule exists and contains mrl_points_races',
    'status' => rdp_bool_label($livePointsRaces),
    'detail' => $livePointsRaces
        ? 'LIVE schedule is valid JSON with ' . count($liveSchedule['data']['mrl_points_races']) . ' points-race rows.'
        : ($liveSchedule['error'] !== '' ? $liveSchedule['error'] : 'mrl_points_races is missing or empty.'),
];

$dependencyChecks[] = [
    'check' => 'TEST canonical schedule exists and contains mrl_points_races',
    'status' => rdp_bool_label($testPointsRaces),
    'detail' => $testPointsRaces
        ? 'TEST schedule is valid JSON with ' . count($testSchedule['data']['mrl_points_races']) . ' points-race rows.'
        : ($testSchedule['error'] !== '' ? $testSchedule['error'] : 'mrl_points_races is missing or empty.'),
];

$allDependencyChecksPass = true;
foreach ($dependencyChecks as $check) {
    if ($check['status'] !== 'PASS') {
        $allDependencyChecksPass = false;
        break;
    }
}

/* -------------------------------------------------------------------------
 * race_finish_confirmation retirement discovery on LIVE.
 * ---------------------------------------------------------------------- */

$retirementTerms = [
    'race_finish_confirmation',
    'race_finish_confirmation_monitor.php',
];

$liveScheduleJsonPath = $liveRoot . '/_scheduler/schedule.json';
$liveStateJsonPath = $liveRoot . '/_scheduler/state.json';
$liveMenuPath = $liveRoot . '/race_results_menu.php';

$schedulerScheduleJson = rdp_json_file($liveScheduleJsonPath);
$schedulerStateJson = rdp_json_file($liveStateJsonPath);

$scheduleTaskHits = $schedulerScheduleJson['valid_json']
    ? rdp_find_recursive_key($schedulerScheduleJson['data'], 'race_finish_confirmation_monitor')
    : [];

$stateTaskHits = $schedulerStateJson['valid_json']
    ? rdp_find_recursive_key($schedulerStateJson['data'], 'race_finish_confirmation_monitor')
    : [];

$menuHits = rdp_scan_text_hits($liveMenuPath, $retirementTerms);

$allLiveActiveHits = rdp_scan_active_root_php($liveRoot, $retirementTerms);

$selfOwnedExperimentFiles = [
    'race_finish_confirmation_monitor.php',
    'race_finish_confirmation_dashboard.php',
];

$knownMenuFile = 'race_results_menu.php';

$unexpectedActiveReferences = [];
foreach ($allLiveActiveHits as $hit) {
    if (in_array($hit['path'], $selfOwnedExperimentFiles, true)) {
        continue;
    }
    if ($hit['path'] === $knownMenuFile) {
        continue;
    }
    $unexpectedActiveReferences[] = $hit;
}

$experimentFiles = [];
foreach ($selfOwnedExperimentFiles as $filename) {
    $experimentFiles[] = rdp_file_info($liveRoot . '/' . $filename);
}

$experimentDataDir = $liveRoot . '/_race_finish_confirmation';
$dataDirExists = is_dir($experimentDataDir);
$dataDirEntryCount = 0;
if ($dataDirExists) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($experimentDataDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $dataDirEntryCount++;
        }
    }
}

$proposedActions = [
    'Disable/remove the race_finish_confirmation_monitor task from LIVE _scheduler/schedule.json before removing its PHP files.',
    'Remove the race_finish_confirmation monitor link/reference from LIVE race_results_menu.php.',
    'Preserve _scheduler/log.txt and historical scheduler state as audit/history; do not rewrite old log entries.',
    'Preserve the _race_finish_confirmation data directory as historical experiment evidence unless a separate archive/cleanup step is explicitly approved.',
    'Archive or remove race_finish_confirmation_monitor.php and race_finish_confirmation_dashboard.php only after scheduler/menu references are retired and a post-change scan shows zero unexpected active references.',
    'Do not migrate the TEST race_finish_confirmation/Racing-Reference/Jayski experiment files into LIVE as part of the three-file migration.',
];

$retirementReviewClean = count($unexpectedActiveReferences) === 0
    && $schedulerScheduleJson['valid_json']
    && $schedulerStateJson['valid_json']
    && is_file($liveMenuPath);

/* -------------------------------------------------------------------------
 * Overall gate.
 * A scheduler/menu reference being PRESENT is expected at this preflight stage.
 * It does not fail the review. Unexpected additional active references do.
 * ---------------------------------------------------------------------- */

$warnings = [];

if (!$isExpectedTestLocation) {
    $warnings[] = 'This script is not running from the expected /public_html/testphp8/race_results location.';
}
if (!$allTestCandidatesMatch) {
    $warnings[] = 'One or more TEST migration candidates no longer match the hashes/versions captured by the 7:34:31 PM migration-candidate audit.';
}
if (!$allLiveBaselinesMatch) {
    $warnings[] = 'One or more LIVE migration targets drifted from the 7:34:31 PM audit baseline. Re-audit before building an installer.';
}
if (!$allDependencyChecksPass) {
    $warnings[] = 'One or more migration dependency checks failed.';
}
if (!$retirementReviewClean) {
    $warnings[] = 'Retirement reference review found an unexpected condition or unreadable required file.';
}
if (count($unexpectedActiveReferences) > 0) {
    $warnings[] = 'Unexpected active LIVE PHP references to race_finish_confirmation were found outside the experiment files and race_results_menu.php.';
}

$migrationGate = $isExpectedTestLocation
    && $allTestCandidatesMatch
    && $allLiveBaselinesMatch
    && $allDependencyChecksPass;

$overallReadyForInstallerDesign = $migrationGate && $retirementReviewClean;

$report = [
    'report' => MRL_RDP_TITLE,
    'report_version' => MRL_RDP_VERSION,
    'generated_at' => $generatedAt,
    'host' => isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '',
    'read_only' => true,
    'paths' => [
        'self_dir' => $selfDir,
        'test_root' => rdp_normalize_path($testRoot),
        'live_root' => rdp_normalize_path($liveRoot),
        'expected_test_location' => $isExpectedTestLocation,
    ],
    'overall' => [
        'status' => $overallReadyForInstallerDesign ? 'PASS - READY FOR INSTALLER DESIGN' : 'HOLD - REVIEW REQUIRED',
        'migration_dependency_gate' => $migrationGate ? 'PASS' : 'FAIL',
        'retirement_review_gate' => $retirementReviewClean ? 'PASS' : 'FAIL',
    ],
    'migration_chain' => [
        'files' => $migrationRows,
        'all_test_candidates_match_audit' => $allTestCandidatesMatch,
        'all_live_targets_match_audit_baseline' => $allLiveBaselinesMatch,
        'dependency_checks' => $dependencyChecks,
        'all_dependency_checks_pass' => $allDependencyChecksPass,
        'live_canonical_schedule' => [
            'path' => rdp_normalize_path($liveSchedulePath),
            'exists' => $liveSchedule['exists'],
            'valid_json' => $liveSchedule['valid_json'],
            'points_race_count' => $livePointsRaces ? count($liveSchedule['data']['mrl_points_races']) : 0,
        ],
        'test_canonical_schedule' => [
            'path' => rdp_normalize_path($testSchedulePath),
            'exists' => $testSchedule['exists'],
            'valid_json' => $testSchedule['valid_json'],
            'points_race_count' => $testPointsRaces ? count($testSchedule['data']['mrl_points_races']) : 0,
        ],
    ],
    'retirement' => [
        'scheduler_schedule_reference' => [
            'path' => rdp_normalize_path($liveScheduleJsonPath),
            'valid_json' => $schedulerScheduleJson['valid_json'],
            'present' => count($scheduleTaskHits) > 0,
            'hits' => $scheduleTaskHits,
        ],
        'scheduler_state_reference' => [
            'path' => rdp_normalize_path($liveStateJsonPath),
            'valid_json' => $schedulerStateJson['valid_json'],
            'present' => count($stateTaskHits) > 0,
            'hits' => $stateTaskHits,
        ],
        'menu_reference' => [
            'path' => rdp_normalize_path($liveMenuPath),
            'present' => count($menuHits) > 0,
            'hits' => $menuHits,
        ],
        'experiment_files' => $experimentFiles,
        'experiment_data_directory' => [
            'path' => rdp_normalize_path($experimentDataDir),
            'exists' => $dataDirExists,
            'file_count' => $dataDirEntryCount,
        ],
        'all_active_root_php_hits' => $allLiveActiveHits,
        'unexpected_active_references' => $unexpectedActiveReferences,
        'proposed_actions' => $proposedActions,
    ],
    'warnings' => $warnings,
];

/* -------------------------------------------------------------------------
 * In-memory exports.
 * ---------------------------------------------------------------------- */

$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';

if ($format === 'json') {
    $downloadName = 'mrl_race_results_retirement_dependency_preflight_' . $exportStamp . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'txt') {
    $downloadName = 'mrl_race_results_retirement_dependency_preflight_' . $exportStamp . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo rdp_export_txt($report);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$jsonHref = '?format=json&x=' . rawurlencode((string)microtime(true));
$txtHref = '?format=txt&x=' . rawurlencode((string)microtime(true));

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= rdp_h(MRL_RDP_TITLE) ?></title>
<style>
:root{
    color-scheme:dark;
    --bg:#101114;
    --panel:#181a1f;
    --panel2:#20232a;
    --text:#f3f4f6;
    --muted:#aeb4bf;
    --border:#3a3f49;
    --green:#74f09a;
    --yellow:#ffd166;
    --red:#ff7b7b;
    --blue:#72b7ff;
}
*{box-sizing:border-box}
body{
    margin:0;
    padding:22px;
    background:var(--bg);
    color:var(--text);
    font-family:Arial,Helvetica,sans-serif;
    line-height:1.45;
}
.wrap{max-width:1500px;margin:0 auto}
h1{margin:0 0 6px;font-size:32px}
h2{margin:0 0 14px;font-size:25px}
.small{font-size:13px;color:var(--muted)}
.panel{
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:14px;
    padding:20px;
    margin:0 0 18px;
}
.summary{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin:16px 0 0;
}
.pill{
    border:1px solid var(--border);
    border-radius:999px;
    padding:8px 13px;
    background:var(--panel2);
    font-size:16px;
}
.pass{color:var(--green);font-weight:700}
.warn{color:var(--yellow);font-weight:700}
.fail{color:var(--red);font-weight:700}
.info{color:var(--blue);font-weight:700}
button,.button{
    display:inline-block;
    padding:10px 16px;
    border-radius:7px;
    border:1px solid #4c7ba8;
    background:#205b8c;
    color:white;
    text-decoration:none;
    font-weight:700;
    margin:6px 8px 0 0;
}
table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
}
th,td{
    padding:10px 9px;
    border-bottom:1px solid #333842;
    text-align:left;
    vertical-align:top;
}
th{background:#22252b}
code{
    color:#b9dbff;
    background:#111318;
    padding:2px 5px;
    border-radius:4px;
}
pre{
    white-space:pre-wrap;
    word-break:break-word;
    background:#111318;
    padding:12px;
    border-radius:8px;
    border:1px solid #303540;
}
ul{margin-bottom:0}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
    <h1><?= rdp_h(MRL_RDP_TITLE) ?></h1>
    <div class="small">v001 · Generated <?= rdp_h($generatedAt) ?> · READ ONLY</div>

    <div class="summary">
        <div class="pill">
            Overall:
            <span class="<?= $overallReadyForInstallerDesign ? 'pass' : 'fail' ?>">
                <?= rdp_h($report['overall']['status']) ?>
            </span>
        </div>
        <div class="pill">
            Migration gate:
            <span class="<?= $migrationGate ? 'pass' : 'fail' ?>">
                <?= rdp_h($report['overall']['migration_dependency_gate']) ?>
            </span>
        </div>
        <div class="pill">
            Retirement review:
            <span class="<?= $retirementReviewClean ? 'pass' : 'fail' ?>">
                <?= rdp_h($report['overall']['retirement_review_gate']) ?>
            </span>
        </div>
        <div class="pill">
            Unexpected active refs:
            <span class="<?= count($unexpectedActiveReferences) === 0 ? 'pass' : 'fail' ?>">
                <?= count($unexpectedActiveReferences) ?>
            </span>
        </div>
    </div>

    <a class="button" href="<?= rdp_h($jsonHref) ?>">Download JSON Results</a>
    <a class="button" href="<?= rdp_h($txtHref) ?>">Download TXT Results</a>
</div>

<div class="panel">
    <h2>Three-file migration chain</h2>
    <table>
        <thead>
        <tr>
            <th>File</th>
            <th>TEST</th>
            <th>LIVE</th>
            <th>TEST audit hash</th>
            <th>LIVE baseline</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($migrationRows as $row): ?>
            <tr>
                <td><code><?= rdp_h($row['path']) ?></code></td>
                <td><?= rdp_h($row['test']['exists'] ? $row['test']['version'] : 'MISSING') ?></td>
                <td><?= rdp_h($row['live']['exists'] ? $row['live']['version'] : 'MISSING') ?></td>
                <td class="<?= $row['test_hash_matches_audit'] ? 'pass' : 'fail' ?>">
                    <?= $row['test_hash_matches_audit'] ? 'MATCH' : 'MISMATCH' ?>
                </td>
                <td class="<?= $row['live_matches_audit_baseline'] ? 'pass' : 'fail' ?>">
                    <?= $row['live_matches_audit_baseline'] ? 'UNCHANGED' : 'DRIFTED' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2>Dependency checks</h2>
    <table>
        <thead>
        <tr><th>Check</th><th>Status</th><th>Detail</th></tr>
        </thead>
        <tbody>
        <?php foreach ($dependencyChecks as $row): ?>
            <tr>
                <td><?= rdp_h($row['check']) ?></td>
                <td class="<?= $row['status'] === 'PASS' ? 'pass' : 'fail' ?>"><?= rdp_h($row['status']) ?></td>
                <td><?= rdp_h($row['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2>LIVE race_finish_confirmation retirement map</h2>
    <div class="summary">
        <div class="pill">
            Scheduler schedule task:
            <span class="<?= count($scheduleTaskHits) > 0 ? 'warn' : 'pass' ?>">
                <?= count($scheduleTaskHits) > 0 ? 'PRESENT' : 'NOT FOUND' ?>
            </span>
        </div>
        <div class="pill">
            Scheduler state:
            <span class="<?= count($stateTaskHits) > 0 ? 'info' : 'pass' ?>">
                <?= count($stateTaskHits) > 0 ? 'HISTORY/STATE PRESENT' : 'NOT FOUND' ?>
            </span>
        </div>
        <div class="pill">
            Menu reference:
            <span class="<?= count($menuHits) > 0 ? 'warn' : 'pass' ?>">
                <?= count($menuHits) > 0 ? 'PRESENT' : 'NOT FOUND' ?>
            </span>
        </div>
        <div class="pill">
            Historical data directory:
            <span class="<?= $dataDirExists ? 'info' : 'pass' ?>">
                <?= $dataDirExists ? ('PRESENT (' . $dataDirEntryCount . ' files)') : 'NOT FOUND' ?>
            </span>
        </div>
    </div>

    <p class="small">
        Yellow items are expected at this stage: they identify the wiring that a future controlled
        installer/retirement step would change. Historical scheduler state/logs and the experiment
        data directory are evidence, not migration candidates.
    </p>

    <h3>Proposed actions — nothing below has been performed</h3>
    <ol>
        <?php foreach ($proposedActions as $action): ?>
            <li><?= rdp_h($action) ?></li>
        <?php endforeach; ?>
    </ol>
</div>

<div class="panel">
    <h2>Unexpected active LIVE references</h2>
    <?php if (count($unexpectedActiveReferences) === 0): ?>
        <div class="pass">PASS — no unexpected active root-level PHP references found.</div>
    <?php else: ?>
        <table>
            <thead><tr><th>File</th><th>Line</th><th>Term</th><th>Text</th></tr></thead>
            <tbody>
            <?php foreach ($unexpectedActiveReferences as $hit): ?>
                <tr>
                    <td><code><?= rdp_h($hit['path']) ?></code></td>
                    <td><?= rdp_h($hit['line']) ?></td>
                    <td><?= rdp_h($hit['term']) ?></td>
                    <td><?= rdp_h($hit['text']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if (!empty($warnings)): ?>
<div class="panel">
    <h2>Warnings</h2>
    <ul>
        <?php foreach ($warnings as $warning): ?>
            <li class="fail"><?= rdp_h($warning) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="panel">
    <h2>What this script did NOT do</h2>
    <ul>
        <li>Did not stop the master scheduler.</li>
        <li>Did not edit <code>_scheduler/schedule.json</code>.</li>
        <li>Did not edit <code>race_results_menu.php</code>.</li>
        <li>Did not remove or archive race-finish-confirmation files/data.</li>
        <li>Did not copy the three TEST migration candidates to LIVE.</li>
        <li>Did not modify any JSON/runtime/history files.</li>
    </ul>
</div>

</div>
</body>
</html>
