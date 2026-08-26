<?php
/**
 * MRL Live Root Post-Install Verification
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 2:49:01 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 2:49:01 am)
 * - Initial read-only post-install verification for the completed 15-file root migration.
 * - Confirms all 15 LIVE files exactly match the approved TESTPHP8 versions/hashes.
 * - Confirms protected LIVE environment/email files remain unchanged.
 * - Confirms the four TESTPHP8 shutdown-checklist files remain untouched.
 * - Re-checks direct PHP dependencies against the now-complete LIVE package.
 * - Confirms LIVE race_results/race_schedule_helper.php is still available.
 * - Provides timestamped JSON and TXT exports.
 * - Makes NO changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_RPIV_VERSION = 'v001';
const MRL_RPIV_TITLE = 'MRL Live Root Post-Install Verification';

function piv_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function piv_norm(string $p): string { return str_replace('\\', '/', $p); }
function piv_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}
function piv_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}
function piv_version(string $p): string {
    $t = piv_read($p);
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
function piv_info(string $p): ?array {
    if (!is_file($p)) return null;
    $size = @filesize($p);
    $mtime = @filemtime($p);
    return [
        'path' => piv_norm($p),
        'version' => piv_version($p),
        'sha256' => piv_sha($p),
        'size' => is_int($size) ? $size : null,
        'mtime' => is_int($mtime) ? date('Y-m-d H:i:s T', $mtime) : null,
    ];
}
function piv_dependencies(string $path): array {
    $deps = [];
    $text = piv_read($path);
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
function piv_resolve(string $dep, string $liveRoot): array {
    $clean = str_replace('\\', '/', $dep);

    if (strpos($clean, '/race_results/') === 0) {
        $candidate = rtrim($liveRoot, '/') . $clean;
    } else {
        $candidate = $liveRoot . '/' . ltrim($clean, '/');
    }

    return [
        'dependency' => $dep,
        'resolved_path' => piv_norm($candidate),
        'exists' => is_file($candidate),
    ];
}

$selfDir = rtrim(piv_norm(__DIR__), '/');
$expected = (bool)preg_match('#/public_html/testphp8$#', $selfDir);
$testRoot = $selfDir;
$liveRoot = dirname($testRoot);
$now = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$package = [
    'admin_pick_adjustment.php' => ['version' => 'v002', 'sha256' => '512b63908bdbc3ec73af979fb4f74e7de887a10d0a593277dcdc8326791bae71'],
    'admin_setup.php' => ['version' => 'v004', 'sha256' => 'a9c2b6f462d903af44adf0007acca73af8d6e942b034e211a40e2d60f29d50ea'],
    'config_mrl.php' => ['version' => 'v003', 'sha256' => '34eef0d70d79e243c124239bb361a2fd4f1ab0bcca6b93141be0c75a0f40543a'],
    'current_segment_chart.php' => ['version' => 'v007', 'sha256' => '9a9550d334d18b828c9173f37299f428d84d2edc804b049cf4b0703cbec9680c'],
    'current_segment_chart_by_entry_time.php' => ['version' => 'v004', 'sha256' => '1d572c08a85fb90eb243874995195b8653b5bdc3193100fb26e8468fe26b7fe4'],
    'current_user_team_chart.php' => ['version' => 'v005', 'sha256' => '7176e440667b06ea6b18e3401e9e2973c52fdf40362c8811e2ef3fc2c1e130ae'],
    'form-team-picks.php' => ['version' => 'v007', 'sha256' => '7d27ae72f865243ecca758a5403cc75bd370dbbf2f6f2cca820bcc41ef7042b5'],
    'pick_window_helper.php' => ['version' => 'v003', 'sha256' => 'e73a04a0847d38d3329814fe616e647052e90194acc92e9b886fb1c0c7d8e3dc'],
    'prior_year_user_team_chart.php' => ['version' => 'v004', 'sha256' => '1047c91029327e91f5edca9705d151293ef74abcc0bde8935fcba0804cc548cb'],
    'submit-team-picks.php' => ['version' => 'v011', 'sha256' => '797bcfe1f8f9155d04573fde40aa551b384156c0e889f411b1e198e89ddc97d1'],
    'submitted_teams_count.php' => ['version' => 'v001', 'sha256' => 'bf82df10b92d31c17cca5a380cace80d846f6da7788b1bdffe1f644b9a7df66f'],
    'team-late-pick.php' => ['version' => 'v005', 'sha256' => '89233a4e25774a521b75193217c2dd74c82215428ab171c5c75cf230bd351dcb'],
    'team.php' => ['version' => 'v034', 'sha256' => 'ea4542182638143549230d4b47ec51e6148ff018d0a799c1a8c7af0971cfcdfc'],
    'team_chart.php' => ['version' => 'v018', 'sha256' => 'bc546f3b99855ff85fed0342090ced35977da8ddc1171ff676a904cef57255e1'],
    'team_replacement_driver.php' => ['version' => 'v010', 'sha256' => 'e7a30d2768c249b8bd993ea81fa2845b85284c7bb0987a57acef0be66f8c87f1']
];

$packageRows = [];
$packageGate = $expected;
$dependencyGate = $expected;
$dependencyProblems = [];

foreach ($package as $name => $exp) {
    $livePath = $liveRoot . '/' . $name;
    $testPath = $testRoot . '/' . $name;

    $live = piv_info($livePath);
    $test = piv_info($testPath);

    $liveOk = $live !== null
        && strtolower((string)$live['version']) === strtolower($exp['version'])
        && $live['sha256'] === $exp['sha256'];

    $testOk = $test !== null
        && strtolower((string)$test['version']) === strtolower($exp['version'])
        && $test['sha256'] === $exp['sha256'];

    if (!$liveOk || !$testOk) $packageGate = false;

    $deps = [];
    foreach (piv_dependencies($livePath) as $dep) {
        $resolved = piv_resolve($dep['dependency'], $liveRoot);
        $resolved['line'] = $dep['line'];
        $resolved['text'] = $dep['text'];
        $deps[] = $resolved;

        if (!$resolved['exists']) {
            $dependencyGate = false;
            $dependencyProblems[] = [
                'file' => $name,
                'dependency' => $dep['dependency'],
                'resolved_path' => $resolved['resolved_path'],
            ];
        }
    }

    $packageRows[] = [
        'path' => $name,
        'expected_version' => $exp['version'],
        'expected_sha256' => $exp['sha256'],
        'test' => $test,
        'live' => $live,
        'test_matches' => $testOk,
        'live_matches' => $liveOk,
        'dependencies' => $deps,
    ];
}

$protected = [
    'email.php' => '9dd7755aa2189288b55c68a826330b82297c7969eb6356f1bc82c9a456a0853c',
    'conf.inc.php' => '3436b55ecabf4c2307998c42ea15a2e28a8a134b66ab2f7816a8706628e91860',
    'config.php' => 'f8b4e6531dd8c0e88d6927587f9f7e7f0ee9e6c3c31657d79590496550a163a2',
    'dbconfig.php' => 'a9f977e431c241d233058c0f9923eb111c75f24c7630a0f088f98b3ac95d27a1',
    'wp-config.php' => 'c4f2e54f62be414b55ae839244c14316db0794b0a03aa2a646512a76d7ead48e',
];

$protectedRows = [];
$protectedGate = $expected;
foreach ($protected as $name => $hash) {
    $info = piv_info($liveRoot . '/' . $name);
    $ok = $info !== null && $info['sha256'] === $hash;
    if (!$ok) $protectedGate = false;
    $protectedRows[] = [
        'path' => $name,
        'live' => $info,
        'unchanged' => $ok,
    ];
}

$shutdown = [
    'default.php' => [
        'test_sha' => 'aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45',
        'live_sha' => '',
    ],
    'rebuild_year_index.php' => [
        'test_sha' => 'fa62c5bff0fe31622ec1f393b3a32e826eeb0afbea4bb61405ddfaa262f18494',
        'live_sha' => '',
    ],
    'logout.php' => [
        'test_sha' => 'd6fcfb1b937e417f3480f985e4f1ce9abe0fb1b4c54bbd3c56a0b77c6875e5d3',
        'live_sha' => 'b6b406cb824821440b01f02c14e4139a6a2f0fd485c8c911d7234bcab718fb54',
    ],
    'races.html' => [
        'test_sha' => '688203b72b10d0b38ca84e901114fe3370f73b20225f19f9a175eda185af2ac3',
        'live_sha' => '066f1ec64e73f34bc73bb36c3dd893739eaa60d608fb32d6510f00add65751f6',
    ],
];

$shutdownRows = [];
$shutdownGate = $expected;
foreach ($shutdown as $name => $exp) {
    $test = piv_info($testRoot . '/' . $name);
    $live = piv_info($liveRoot . '/' . $name);

    $testOk = $test !== null && $test['sha256'] === $exp['test_sha'];

    if ($exp['live_sha'] === '') {
        $liveOk = $live === null;
    } else {
        $liveOk = $live !== null && $live['sha256'] === $exp['live_sha'];
    }

    if (!$testOk || !$liveOk) $shutdownGate = false;

    $shutdownRows[] = [
        'path' => $name,
        'test' => $test,
        'live' => $live,
        'test_unchanged' => $testOk,
        'live_unchanged' => $liveOk,
        'status' => 'HOLD_FOR_TESTPHP8_SHUTDOWN_REVIEW',
    ];
}

$raceScheduleInfo = piv_info($liveRoot . '/race_results/race_schedule_helper.php');
$raceScheduleGate = $raceScheduleInfo !== null
    && strtolower((string)$raceScheduleInfo['version']) === 'v003'
    && $raceScheduleInfo['sha256'] === '9ed17b411be162140f73173808e974e0468d3c335872dc0ef31487375933ec62';

$overall = $expected && $packageGate && $dependencyGate && $protectedGate && $shutdownGate && $raceScheduleGate;

$report = [
    'report' => MRL_RPIV_TITLE,
    'report_version' => MRL_RPIV_VERSION,
    'generated_at' => $now,
    'read_only' => true,
    'expected_location' => $expected,
    'overall' => [
        'status' => $overall ? 'PASS - ROOT MIGRATION VERIFIED' : 'FAIL - REVIEW REQUIRED',
        'package_hash_version_gate' => $packageGate ? 'PASS' : 'FAIL',
        'dependency_gate' => $dependencyGate ? 'PASS' : 'FAIL',
        'protected_live_gate' => $protectedGate ? 'PASS' : 'FAIL',
        'shutdown_checklist_gate' => $shutdownGate ? 'PASS' : 'FAIL',
        'race_results_helper_gate' => $raceScheduleGate ? 'PASS' : 'FAIL',
    ],
    'package_files' => $packageRows,
    'dependency_problems' => $dependencyProblems,
    'protected_live' => $protectedRows,
    'shutdown_checklist' => $shutdownRows,
    'live_race_schedule_helper' => $raceScheduleInfo,
];

function piv_txt(array $r): string {
    $out = [];
    $out[] = MRL_RPIV_TITLE;
    $out[] = 'Version: ' . MRL_RPIV_VERSION;
    $out[] = 'Generated: ' . $r['generated_at'];
    $out[] = 'Read only: YES';
    $out[] = '';
    $out[] = 'OVERALL: ' . $r['overall']['status'];
    foreach ($r['overall'] as $k => $v) {
        if ($k === 'status') continue;
        $out[] = $k . ': ' . $v;
    }
    $out[] = '';
    foreach ($r['package_files'] as $row) {
        $out[] = '- ' . $row['path'] . ' | LIVE ' . ($row['live_matches'] ? 'MATCH' : 'FAIL');
    }
    return implode("\r\n", $out) . "\r\n";
}

$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_live_root_post_install_verification_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_live_root_post_install_verification_' . $stamp . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo piv_txt($report);
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
<title><?= piv_h(MRL_RPIV_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1600px;margin:0 auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px;font-size:31px}h2{margin:0 0 13px}.small{color:var(--muted);font-size:13px}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;border:1px solid #4c7ba8;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:8px 8px 0 0}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}code{color:#bddcff;background:#111318;padding:2px 5px;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1><?= piv_h(MRL_RPIV_TITLE) ?></h1>
<div class="small">v001 · <?= piv_h($now) ?> · READ ONLY</div>
<div class="summary">
<div class="pill">Overall: <span class="<?= $overall ? 'pass' : 'fail' ?>"><?= piv_h($report['overall']['status']) ?></span></div>
<div class="pill">15 files: <span class="<?= $packageGate ? 'pass' : 'fail' ?>"><?= $packageGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Dependencies: <span class="<?= $dependencyGate ? 'pass' : 'fail' ?>"><?= $dependencyGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Protected LIVE: <span class="<?= $protectedGate ? 'pass' : 'fail' ?>"><?= $protectedGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Shutdown checklist: <span class="<?= $shutdownGate ? 'pass' : 'fail' ?>"><?= $shutdownGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">race_schedule_helper: <span class="<?= $raceScheduleGate ? 'pass' : 'fail' ?>"><?= $raceScheduleGate ? 'PASS' : 'FAIL' ?></span></div>
</div>
<a class="button" href="?format=json&x=<?= piv_h((string)microtime(true)) ?>">Download JSON Results</a>
<a class="button" href="?format=txt&x=<?= piv_h((string)microtime(true)) ?>">Download TXT Results</a>
</div>

<div class="panel">
<h2>Installed package verification</h2>
<table>
<thead><tr><th>File</th><th>Expected</th><th>LIVE</th><th>Hash/version</th></tr></thead>
<tbody>
<?php foreach ($packageRows as $row): ?>
<tr>
<td><code><?= piv_h($row['path']) ?></code></td>
<td><?= piv_h($row['expected_version']) ?></td>
<td><?= piv_h(($row['live']['version'] ?? '') ?: 'MISSING') ?></td>
<td class="<?= $row['live_matches'] ? 'pass' : 'fail' ?>"><?= $row['live_matches'] ? 'MATCH' : 'FAIL' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="panel">
<h2>Dependency verification</h2>
<?php if (!$dependencyProblems): ?>
<p class="pass">PASS — all directly-detected LIVE dependencies resolve.</p>
<?php else: ?>
<table><thead><tr><th>File</th><th>Dependency</th><th>Resolved path</th></tr></thead><tbody>
<?php foreach ($dependencyProblems as $p): ?>
<tr><td><?= piv_h($p['file']) ?></td><td><?= piv_h($p['dependency']) ?></td><td><?= piv_h($p['resolved_path']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>

<div class="panel">
<h2>Still protected / held</h2>
<p>The five protected LIVE environment/email files and the four TESTPHP8 shutdown-checklist files are verified separately in the JSON export.</p>
</div>

<div class="panel">
<h2>Safety</h2>
<ul>
<li>No files are modified.</li>
<li>No database changes.</li>
<li>No scheduler changes.</li>
<li>No cleanup or deletion.</li>
</ul>
</div>

</div>
</body>
</html>
