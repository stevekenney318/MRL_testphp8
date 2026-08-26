<?php
/**
 * MRL Root Migration Candidate Audit
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 1:49:05 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 1:49:05 am)
 * - Initial read-only root-level TESTPHP8 -> LIVE migration candidate audit.
 * - Designed for the next consolidation phase after race_results migration completed.
 * - Compares immediate files in /public_html/testphp8/ against /public_html/.
 * - Excludes /race_results/ because that migration phase is already complete.
 * - Excludes known WordPress core root files from migration consideration.
 * - Excludes obvious installers, audits, diagnostics, backups, test debris, and generated exports.
 * - Reports exact version, SHA-256, size, modified time, and TEST/LIVE status.
 * - Classifies likely migration candidates conservatively:
 *     TEST_ONLY_CUSTOM
 *     TEST_NEWER_VERSION
 *     DIFFERENT_SAME_VERSION
 *     LIVE_NEWER_VERSION
 *     LIVE_ONLY_CUSTOM
 *     IDENTICAL
 * - Scans likely candidate PHP files for local include/require references so dependencies
 *   can be reviewed before any migration installer is built.
 * - Provides timestamped JSON and TXT exports.
 * - Makes NO filesystem, database, or scheduler changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 *
 * SAFETY:
 * - READ ONLY.
 * - Does not modify TESTPHP8.
 * - Does not modify LIVE.
 * - Does not stop or change schedulers.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

const MRL_ROOT_AUDIT_VERSION = 'v001';
const MRL_ROOT_AUDIT_TITLE = 'MRL Root Migration Candidate Audit';

function mra_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mra_norm(string $p): string
{
    return str_replace('\\', '/', $p);
}

function mra_read(string $p): string
{
    if (!is_file($p) || !is_readable($p)) {
        return '';
    }
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function mra_sha(string $p): string
{
    if (!is_file($p) || !is_readable($p)) {
        return '';
    }
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function mra_version(string $p): string
{
    $t = mra_read($p);
    if ($t === '') {
        return '';
    }

    foreach ([
        '/\bVERSION\s*:\s*(v\d{3})\b/i',
        '/\bVERSION\s*=\s*[\'"]?(v\d{3})\b/i',
        '/\b(v\d{3})\b/',
    ] as $rx) {
        if (preg_match($rx, $t, $m)) {
            return strtolower((string)$m[1]);
        }
    }
    return '';
}

function mra_version_num(string $version): ?int
{
    if (preg_match('/^v(\d{3})$/i', trim($version), $m)) {
        return (int)$m[1];
    }
    return null;
}

function mra_info(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $size = @filesize($path);
    $mtime = @filemtime($path);

    return [
        'path' => mra_norm($path),
        'version' => mra_version($path),
        'sha256' => mra_sha($path),
        'size' => is_int($size) ? $size : null,
        'mtime' => is_int($mtime) ? date('Y-m-d H:i:s T', $mtime) : null,
    ];
}

function mra_is_wordpress_core_root(string $name): bool
{
    $lower = strtolower($name);

    $exact = [
        'index.php',
        'license.txt',
        'readme.html',
        'wp-activate.php',
        'wp-blog-header.php',
        'wp-comments-post.php',
        'wp-config-sample.php',
        'wp-cron.php',
        'wp-links-opml.php',
        'wp-load.php',
        'wp-login.php',
        'wp-mail.php',
        'wp-settings.php',
        'wp-signup.php',
        'wp-trackback.php',
        'xmlrpc.php',
    ];

    return in_array($lower, $exact, true);
}

function mra_is_obvious_non_candidate(string $name): bool
{
    $lower = strtolower($name);

    $fragments = [
        'install',
        'installer',
        'audit',
        'preflight',
        'diagnostic',
        'debug',
        'probe',
        'cleanup',
        'clean_',
        'migration_readiness',
        'migration_candidate',
        'backup',
        '.bak',
        '_bak',
        'copy of ',
        'test_',
        '_test',
        'sandbox',
        'scratch',
        'export',
    ];

    foreach ($fragments as $f) {
        if (strpos($lower, $f) !== false) {
            return true;
        }
    }

    return false;
}

function mra_extension_allowed(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, ['php', 'html', 'htm', 'css', 'js', 'json'], true);
}

function mra_root_files(string $root): array
{
    $out = [];
    if (!is_dir($root)) {
        return $out;
    }

    $items = @scandir($root);
    if (!is_array($items)) {
        return $out;
    }

    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $root . '/' . $name;
        if (!is_file($path)) {
            continue;
        }

        if (!mra_extension_allowed($name)) {
            continue;
        }

        if (mra_is_wordpress_core_root($name)) {
            continue;
        }

        $out[$name] = $path;
    }

    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function mra_classify(?array $test, ?array $live, string $name): array
{
    if ($test !== null && $live !== null && $test['sha256'] === $live['sha256']) {
        return ['classification' => 'IDENTICAL', 'candidate' => false, 'reason' => 'Exact SHA-256 match.'];
    }

    if (mra_is_obvious_non_candidate($name)) {
        return ['classification' => 'TOOLING_OR_DEBRIS', 'candidate' => false, 'reason' => 'Installer/audit/test/cleanup/backup-style filename.'];
    }

    if ($test !== null && $live === null) {
        return ['classification' => 'TEST_ONLY_CUSTOM', 'candidate' => true, 'reason' => 'Custom root file exists only in TESTPHP8; review for intentional migration.'];
    }

    if ($test === null && $live !== null) {
        return ['classification' => 'LIVE_ONLY_CUSTOM', 'candidate' => false, 'reason' => 'Custom root file exists only in LIVE; preserve until explicitly reviewed.'];
    }

    if ($test !== null && $live !== null) {
        $tv = mra_version_num((string)$test['version']);
        $lv = mra_version_num((string)$live['version']);

        if ($tv !== null && $lv !== null) {
            if ($tv > $lv) {
                return ['classification' => 'TEST_NEWER_VERSION', 'candidate' => true, 'reason' => 'TESTPHP8 version is newer than LIVE.'];
            }
            if ($lv > $tv) {
                return ['classification' => 'LIVE_NEWER_VERSION', 'candidate' => false, 'reason' => 'LIVE version is newer than TESTPHP8; do not overwrite automatically.'];
            }
            return ['classification' => 'DIFFERENT_SAME_VERSION', 'candidate' => true, 'reason' => 'Same version marker but different content; requires deliberate review.'];
        }

        return ['classification' => 'DIFFERENT_UNVERSIONED', 'candidate' => true, 'reason' => 'Different custom files without comparable version markers; requires deliberate review.'];
    }

    return ['classification' => 'UNKNOWN', 'candidate' => false, 'reason' => 'Unexpected state.'];
}

function mra_php_dependencies(string $path): array
{
    $deps = [];
    $text = mra_read($path);
    if ($text === '') {
        return $deps;
    }

    $lines = preg_split('/\R/', $text);
    if (!is_array($lines)) {
        return $deps;
    }

    foreach ($lines as $i => $line) {
        if (!preg_match('/\b(require|require_once|include|include_once)\b/i', $line)) {
            continue;
        }

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

function mra_txt(array $report): string
{
    $out = [];
    $out[] = MRL_ROOT_AUDIT_TITLE;
    $out[] = 'Version: ' . MRL_ROOT_AUDIT_VERSION;
    $out[] = 'Generated: ' . $report['generated_at'];
    $out[] = 'Read only: YES';
    $out[] = '';
    $out[] = 'SUMMARY';
    foreach ($report['summary'] as $k => $v) {
        $out[] = $k . ': ' . $v;
    }
    $out[] = '';
    $out[] = 'LIKELY MIGRATION CANDIDATES';
    foreach ($report['migration_candidates'] as $row) {
        $out[] = '- ' . $row['path'] . ' | ' . $row['classification']
            . ' | TEST ' . (($row['test']['version'] ?? '') ?: '(none)')
            . ' | LIVE ' . (($row['live']['version'] ?? '') ?: '(missing)')
            . ' | ' . $row['reason'];
    }
    $out[] = '';
    $out[] = 'LIVE-ONLY CUSTOM FILES';
    foreach ($report['live_only'] as $row) {
        $out[] = '- ' . $row['path'] . ' | preserve/review';
    }
    return implode("\r\n", $out) . "\r\n";
}

/* Environment */
$selfDir = rtrim(mra_norm(__DIR__), '/');
$expected = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$testRoot = $selfDir;
$liveRoot = dirname($testRoot);

$generatedAt = date('Y-m-d H:i:s T');
$downloadStamp = strtolower(date('Ymd_hisA'));

$testFiles = mra_root_files($testRoot);
$liveFiles = mra_root_files($liveRoot);

$names = array_unique(array_merge(array_keys($testFiles), array_keys($liveFiles)));
sort($names, SORT_NATURAL | SORT_FLAG_CASE);

$rows = [];
$candidates = [];
$liveOnly = [];
$dependencyRows = [];

$counts = [
    'total_considered' => 0,
    'migration_candidates' => 0,
    'identical' => 0,
    'live_only_custom' => 0,
    'tooling_or_debris' => 0,
    'test_only_custom' => 0,
    'test_newer_version' => 0,
    'different_same_version' => 0,
    'different_unversioned' => 0,
    'live_newer_version' => 0,
];

foreach ($names as $name) {
    $test = isset($testFiles[$name]) ? mra_info($testFiles[$name]) : null;
    $live = isset($liveFiles[$name]) ? mra_info($liveFiles[$name]) : null;
    $class = mra_classify($test, $live, $name);

    $row = [
        'path' => $name,
        'classification' => $class['classification'],
        'candidate' => $class['candidate'],
        'reason' => $class['reason'],
        'test' => $test,
        'live' => $live,
    ];

    $rows[] = $row;
    $counts['total_considered']++;

    $mapKey = strtolower($class['classification']);
    if ($class['classification'] === 'IDENTICAL') {
        $counts['identical']++;
    } elseif ($class['classification'] === 'LIVE_ONLY_CUSTOM') {
        $counts['live_only_custom']++;
        $liveOnly[] = $row;
    } elseif ($class['classification'] === 'TOOLING_OR_DEBRIS') {
        $counts['tooling_or_debris']++;
    } elseif ($class['classification'] === 'TEST_ONLY_CUSTOM') {
        $counts['test_only_custom']++;
    } elseif ($class['classification'] === 'TEST_NEWER_VERSION') {
        $counts['test_newer_version']++;
    } elseif ($class['classification'] === 'DIFFERENT_SAME_VERSION') {
        $counts['different_same_version']++;
    } elseif ($class['classification'] === 'DIFFERENT_UNVERSIONED') {
        $counts['different_unversioned']++;
    } elseif ($class['classification'] === 'LIVE_NEWER_VERSION') {
        $counts['live_newer_version']++;
    }

    if ($class['candidate']) {
        $counts['migration_candidates']++;
        $candidates[] = $row;

        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'php' && $test !== null) {
            $dependencyRows[$name] = mra_php_dependencies($testRoot . '/' . $name);
        }
    }
}

$report = [
    'report' => MRL_ROOT_AUDIT_TITLE,
    'report_version' => MRL_ROOT_AUDIT_VERSION,
    'generated_at' => $generatedAt,
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'read_only' => true,
    'expected_location' => $expected,
    'paths' => [
        'test_root' => mra_norm($testRoot),
        'live_root' => mra_norm($liveRoot),
        'race_results_excluded' => true,
    ],
    'scope' => [
        'level' => 'root files only; non-recursive',
        'extensions' => ['php', 'html', 'htm', 'css', 'js', 'json'],
        'wordpress_core_root_files_excluded' => true,
        'obvious_tooling_debris_classified_separately' => true,
        'purpose' => 'Find the next deliberate TESTPHP8 -> LIVE custom-code migration candidates without touching race_results.',
    ],
    'summary' => $counts,
    'migration_candidates' => $candidates,
    'live_only' => $liveOnly,
    'dependency_references' => $dependencyRows,
    'all_rows' => $rows,
    'warnings' => $expected ? [] : [
        'This audit is not running from the expected /public_html/testphp8 location.'
    ],
];

$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_root_migration_candidate_audit_' . $downloadStamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_root_migration_candidate_audit_' . $downloadStamp . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo mra_txt($report);
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
<title><?= mra_h(MRL_ROOT_AUDIT_TITLE) ?></title>
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
    <h1><?= mra_h(MRL_ROOT_AUDIT_TITLE) ?></h1>
    <div class="small">v001 · <?= mra_h($generatedAt) ?> · READ ONLY · root-level custom files only</div>

    <div class="summary">
        <div class="pill">Candidates: <span class="warn"><?= (int)$counts['migration_candidates'] ?></span></div>
        <div class="pill">Identical: <span class="pass"><?= (int)$counts['identical'] ?></span></div>
        <div class="pill">LIVE-only: <span class="info"><?= (int)$counts['live_only_custom'] ?></span></div>
        <div class="pill">Tooling/debris: <?= (int)$counts['tooling_or_debris'] ?></div>
        <div class="pill">Expected location: <span class="<?= $expected ? 'pass' : 'fail' ?>"><?= $expected ? 'YES' : 'NO' ?></span></div>
    </div>

    <a class="button" href="?format=json&x=<?= mra_h((string)microtime(true)) ?>">Download JSON Results</a>
    <a class="button" href="?format=txt&x=<?= mra_h((string)microtime(true)) ?>">Download TXT Results</a>
</div>

<div class="panel">
    <h2>Likely migration candidates</h2>
    <?php if (!$candidates): ?>
        <p class="pass">No likely root-level migration candidates found.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>Path</th><th>Class</th><th>TEST</th><th>LIVE</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($candidates as $row): ?>
            <tr>
                <td><code><?= mra_h($row['path']) ?></code></td>
                <td class="warn"><?= mra_h($row['classification']) ?></td>
                <td><?= mra_h(($row['test']['version'] ?? '') ?: '(none)') ?></td>
                <td><?= mra_h(($row['live']['version'] ?? '') ?: ($row['live'] === null ? 'MISSING' : '(none)')) ?></td>
                <td><?= mra_h($row['reason']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>LIVE-only custom root files</h2>
    <?php if (!$liveOnly): ?>
        <p class="pass">None.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>Path</th><th>LIVE version</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($liveOnly as $row): ?>
            <tr>
                <td><code><?= mra_h($row['path']) ?></code></td>
                <td><?= mra_h(($row['live']['version'] ?? '') ?: '(none)') ?></td>
                <td><?= mra_h($row['reason']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Safety / scope</h2>
    <ul>
        <li>This is non-recursive: it compares only files directly inside TESTPHP8 root and LIVE root.</li>
        <li><code>race_results/</code> is intentionally excluded; that consolidation is already complete.</li>
        <li>WordPress core root files are excluded.</li>
        <li>No files are copied, deleted, renamed, or modified.</li>
        <li>No scheduler changes.</li>
        <li>JSON export contains the complete rows plus include/require dependency references for likely PHP candidates.</li>
    </ul>
</div>

</div>
</body>
</html>
