<?php
/**
 * MRL Live Root Migration Installer
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 2:37:07 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 2:37:07 am)
 * - Initial controlled 15-file TESTPHP8 root -> LIVE root migration installer.
 * - Preview-first: opening this page makes NO changes.
 * - Verifies exact TEST source hashes/versions from the approved final preflight.
 * - Verifies exact LIVE baselines before allowing installation.
 * - Verifies package-aware dependencies, including the already-migrated
 *   /race_results/race_schedule_helper.php.
 * - Creates timestamped backups of every existing LIVE file replaced.
 * - Copies the 15 approved TESTPHP8 files to LIVE as one dependency unit.
 * - Verifies installed hashes and versions after migration.
 * - Explicitly preserves LIVE environment/configuration files:
 *     conf.inc.php, config.php, dbconfig.php, wp-config.php
 * - Explicitly preserves LIVE email.php.
 * - Explicitly leaves the four TESTPHP8 shutdown-checklist files untouched:
 *     default.php, rebuild_year_index.php, logout.php, races.html
 * - Makes no database, WordPress, scheduler, race-results, or cleanup changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 *
 * OPERATING PROCEDURE:
 * 1. Upload to /public_html/testphp8/
 * 2. Open and review PREVIEW.
 * 3. Download JSON Preview and return it for review before INSTALL.
 * 4. Only after approval, click INSTALL.
 *
 * NOTE:
 * - The Live race-results scheduler can remain running for preview.
 * - This installer does not touch /race_results or scheduler-managed files.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_ROOT_INSTALLER_VERSION = 'v001';

function mri_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mri_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function mri_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function mri_sha(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $h = @hash_file('sha256', $p);
    return is_string($h) ? $h : '';
}

function mri_version(string $p): string {
    $t = mri_read($p);
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

function mri_info(string $p): ?array {
    if (!is_file($p)) return null;
    $size = @filesize($p);
    $mtime = @filemtime($p);
    return [
        'path' => mri_norm($p),
        'version' => mri_version($p),
        'sha256' => mri_sha($p),
        'size' => is_int($size) ? $size : null,
        'mtime' => is_int($mtime) ? date('Y-m-d H:i:s T', $mtime) : null,
    ];
}

function mri_mkdir(string $dir): bool {
    return is_dir($dir) || @mkdir($dir, 0755, true);
}

function mri_copy_preserve(string $src, string $dst): bool {
    if (!is_file($src) || !is_readable($src)) return false;
    if (!mri_mkdir(dirname($dst))) return false;
    if (!@copy($src, $dst)) return false;

    $mtime = @filemtime($src);
    if (is_int($mtime)) @touch($dst, $mtime);

    $perms = @fileperms($src);
    if (is_int($perms)) @chmod($dst, $perms & 0777);

    return true;
}

function mri_extract_dependencies(string $path): array {
    $deps = [];
    $text = mri_read($path);
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

function mri_dependency_status(string $dep, string $liveRoot, array $packageNames): array {
    $clean = str_replace('\\', '/', $dep);
    $base = basename($clean);

    if (in_array($base, $packageNames, true)) {
        return [
            'dependency' => $dep,
            'status' => 'SATISFIED_BY_PACKAGE',
            'resolved_path' => mri_norm($liveRoot . '/' . $base),
            'exists_now' => is_file($liveRoot . '/' . $base),
        ];
    }

    if (strpos($clean, '/race_results/') === 0) {
        $candidate = rtrim($liveRoot, '/') . $clean;
        return [
            'dependency' => $dep,
            'status' => is_file($candidate) ? 'SATISFIED_LIVE' : 'MISSING',
            'resolved_path' => mri_norm($candidate),
            'exists_now' => is_file($candidate),
        ];
    }

    $candidate = $liveRoot . '/' . ltrim($clean, '/');
    return [
        'dependency' => $dep,
        'status' => is_file($candidate) ? 'SATISFIED_LIVE' : 'MISSING',
        'resolved_path' => mri_norm($candidate),
        'exists_now' => is_file($candidate),
    ];
}

$selfDir = rtrim(mri_norm(__DIR__), '/');
$expected = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$testRoot = $selfDir;
$liveRoot = dirname($testRoot);

$now = date('Y-m-d H:i:s T');
$stamp = date('Ymd_His');
$downloadStamp = strtolower(date('Ymd_hisA'));
$backupRoot = $liveRoot . '/_migration_backups/root_migration_' . $stamp;

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
    ]
];

$packageNames = array_keys($package);

$preflightRows = [];
$sourceGate = $expected;
$liveGate = $expected;
$dependencyGate = $expected;
$dependencyProblems = [];

foreach ($package as $name => $exp) {
    $testPath = $testRoot . '/' . $name;
    $livePath = $liveRoot . '/' . $name;

    $testInfo = mri_info($testPath);
    $liveInfo = mri_info($livePath);

    $sourceOk = $testInfo !== null
        && strtolower((string)$testInfo['version']) === strtolower($exp['test_version'])
        && $testInfo['sha256'] === $exp['test_sha256'];

    if ($exp['live_sha256'] === '') {
        $liveOk = $liveInfo === null;
    } else {
        $liveOk = $liveInfo !== null && $liveInfo['sha256'] === $exp['live_sha256'];
    }

    if (!$sourceOk) $sourceGate = false;
    if (!$liveOk) $liveGate = false;

    $deps = [];
    foreach (mri_extract_dependencies($testPath) as $dep) {
        $status = mri_dependency_status($dep['dependency'], $liveRoot, $packageNames);
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

    $preflightRows[] = [
        'path' => $name,
        'test' => $testInfo,
        'live' => $liveInfo,
        'source_matches_baseline' => $sourceOk,
        'live_matches_baseline' => $liveOk,
        'dependencies' => $deps,
    ];
}

$protectedLive = [
    'email.php' => '9dd7755aa2189288b55c68a826330b82297c7969eb6356f1bc82c9a456a0853c',
    'conf.inc.php' => '3436b55ecabf4c2307998c42ea15a2e28a8a134b66ab2f7816a8706628e91860',
    'config.php' => 'f8b4e6531dd8c0e88d6927587f9f7e7f0ee9e6c3c31657d79590496550a163a2',
    'dbconfig.php' => 'a9f977e431c241d233058c0f9923eb111c75f24c7630a0f088f98b3ac95d27a1',
    'wp-config.php' => 'c4f2e54f62be414b55ae839244c14316db0794b0a03aa2a646512a76d7ead48e',
];

$protectedRows = [];
$protectedGate = $expected;

foreach ($protectedLive as $name => $hash) {
    $info = mri_info($liveRoot . '/' . $name);
    $ok = $info !== null && $info['sha256'] === $hash;
    if (!$ok) $protectedGate = false;
    $protectedRows[] = [
        'path' => $name,
        'live' => $info,
        'baseline_unchanged' => $ok,
        'action' => 'PRESERVE_LIVE',
    ];
}

$shutdownChecklist = [
    'default.php' => [
        'test_expected_sha256' => 'aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45',
        'live_expected_sha256' => '',
        'status' => 'HOLD_FOR_TESTPHP8_SHUTDOWN_REVIEW',
    ],
    'rebuild_year_index.php' => [
        'test_expected_sha256' => 'fa62c5bff0fe31622ec1f393b3a32e826eeb0afbea4bb61405ddfaa262f18494',
        'live_expected_sha256' => '',
        'status' => 'HOLD_FOR_TESTPHP8_SHUTDOWN_REVIEW',
    ],
    'logout.php' => [
        'test_expected_sha256' => 'd6fcfb1b937e417f3480f985e4f1ce9abe0fb1b4c54bbd3c56a0b77c6875e5d3',
        'live_expected_sha256' => 'b6b406cb824821440b01f02c14e4139a6a2f0fd485c8c911d7234bcab718fb54',
        'status' => 'HOLD_FOR_TESTPHP8_SHUTDOWN_REVIEW',
    ],
    'races.html' => [
        'test_expected_sha256' => '688203b72b10d0b38ca84e901114fe3370f73b20225f19f9a175eda185af2ac3',
        'live_expected_sha256' => '066f1ec64e73f34bc73bb36c3dd893739eaa60d608fb32d6510f00add65751f6',
        'status' => 'HOLD_FOR_TESTPHP8_SHUTDOWN_REVIEW',
    ],
];

$shutdownRows = [];
$shutdownGate = $expected;

foreach ($shutdownChecklist as $name => $exp) {
    $testInfo = mri_info($testRoot . '/' . $name);
    $liveInfo = mri_info($liveRoot . '/' . $name);

    $testOk = $testInfo !== null && $testInfo['sha256'] === $exp['test_expected_sha256'];

    if ($exp['live_expected_sha256'] === '') {
        $liveOk = $liveInfo === null;
    } else {
        $liveOk = $liveInfo !== null && $liveInfo['sha256'] === $exp['live_expected_sha256'];
    }

    if (!$testOk || !$liveOk) $shutdownGate = false;

    $shutdownRows[] = [
        'path' => $name,
        'test' => $testInfo,
        'live' => $liveInfo,
        'test_unchanged' => $testOk,
        'live_unchanged' => $liveOk,
        'status' => $exp['status'],
    ];
}

$preflightOk = $expected && $sourceGate && $liveGate && $dependencyGate && $protectedGate && $shutdownGate;

$installRequested = isset($_POST['confirm_install']) && $_POST['confirm_install'] === 'YES';
$installAttempted = false;
$installSuccess = false;
$installLog = [];

if ($installRequested) {
    $installAttempted = true;

    if (!$preflightOk) {
        $installLog[] = 'STOP: Preflight is not clean. No changes made.';
    } else {
        $ok = true;

        $installLog[] = 'BEGIN ROOT MIGRATION';
        $installLog[] = 'Timestamp: ' . $now;
        $installLog[] = 'TEST source: ' . $testRoot;
        $installLog[] = 'LIVE target: ' . $liveRoot;
        $installLog[] = 'Backup: ' . $backupRoot;

        if (!mri_mkdir($backupRoot)) {
            $ok = false;
            $installLog[] = 'FAIL: Could not create backup directory.';
        }

        if ($ok) {
            foreach ($package as $name => $exp) {
                $livePath = $liveRoot . '/' . $name;
                if (is_file($livePath)) {
                    if (!mri_copy_preserve($livePath, $backupRoot . '/' . $name)) {
                        $ok = false;
                        $installLog[] = 'FAIL BACKUP: ' . $name;
                        break;
                    }
                    $installLog[] = 'BACKUP: ' . $name;
                } else {
                    $installLog[] = 'BACKUP: ' . $name . ' (new LIVE file; no prior copy)';
                }
            }
        }

        if ($ok) {
            $manifest = [
                'created_at' => $now,
                'installer_version' => MRL_ROOT_INSTALLER_VERSION,
                'test_root' => mri_norm($testRoot),
                'live_root' => mri_norm($liveRoot),
                'package' => $package,
                'preflight' => [
                    'source_gate' => $sourceGate,
                    'live_gate' => $liveGate,
                    'dependency_gate' => $dependencyGate,
                    'protected_live_gate' => $protectedGate,
                    'shutdown_checklist_gate' => $shutdownGate,
                ],
                'protected_live' => $protectedRows,
                'shutdown_checklist' => $shutdownRows,
            ];

            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || @file_put_contents($backupRoot . '/manifest.json', $json . "\n") === false) {
                $ok = false;
                $installLog[] = 'FAIL: Could not write backup manifest.';
            } else {
                $installLog[] = 'BACKUP MANIFEST: manifest.json';
            }
        }

        if ($ok) {
            foreach ($package as $name => $exp) {
                $src = $testRoot . '/' . $name;
                $dst = $liveRoot . '/' . $name;

                if (!mri_copy_preserve($src, $dst)) {
                    $ok = false;
                    $installLog[] = 'FAIL INSTALL: ' . $name;
                    break;
                }

                if (mri_sha($dst) !== $exp['test_sha256']) {
                    $ok = false;
                    $installLog[] = 'FAIL VERIFY HASH: ' . $name;
                    break;
                }

                if (strtolower(mri_version($dst)) !== strtolower($exp['test_version'])) {
                    $ok = false;
                    $installLog[] = 'FAIL VERIFY VERSION: ' . $name;
                    break;
                }

                $installLog[] = 'INSTALLED: ' . $name . ' ' . $exp['test_version'];
            }
        }

        if ($ok) {
            foreach ($protectedLive as $name => $hash) {
                $actual = mri_sha($liveRoot . '/' . $name);
                if ($actual !== $hash) {
                    $ok = false;
                    $installLog[] = 'FAIL VERIFY PROTECTED LIVE FILE CHANGED: ' . $name;
                    break;
                }
            }
            if ($ok) {
                $installLog[] = 'VERIFY PASS: protected LIVE environment/email files unchanged.';
            }
        }

        if ($ok) {
            foreach ($shutdownChecklist as $name => $exp) {
                if (mri_sha($testRoot . '/' . $name) !== $exp['test_expected_sha256']) {
                    $ok = false;
                    $installLog[] = 'FAIL VERIFY SHUTDOWN-CHECKLIST TEST FILE CHANGED: ' . $name;
                    break;
                }

                $livePath = $liveRoot . '/' . $name;
                if ($exp['live_expected_sha256'] === '') {
                    if (is_file($livePath)) {
                        $ok = false;
                        $installLog[] = 'FAIL VERIFY SHUTDOWN-CHECKLIST LIVE FILE APPEARED: ' . $name;
                        break;
                    }
                } else {
                    if (mri_sha($livePath) !== $exp['live_expected_sha256']) {
                        $ok = false;
                        $installLog[] = 'FAIL VERIFY SHUTDOWN-CHECKLIST LIVE FILE CHANGED: ' . $name;
                        break;
                    }
                }
            }
            if ($ok) {
                $installLog[] = 'VERIFY PASS: four shutdown-checklist files untouched.';
            }
        }

        $installSuccess = $ok;

        if ($ok) {
            $installLog[] = 'SUCCESS';
            $installLog[] = 'NEXT: Verify LIVE team.php, team_chart.php, admin_setup.php, and pick-entry flow.';
            $installLog[] = 'NEXT: Do not delete or alter TESTPHP8 yet.';
            $installLog[] = 'NEXT: default.php, rebuild_year_index.php, logout.php, and races.html remain on the TESTPHP8 shutdown checklist.';
        } else {
            $installLog[] = 'INSTALL DID NOT COMPLETE CLEANLY.';
            $installLog[] = 'STOP and provide this output before doing anything else.';
            $installLog[] = 'Backup directory: ' . $backupRoot;
        }
    }
}

$previewReport = [
    'report' => 'MRL Live Root Migration Installer Preview',
    'report_version' => MRL_ROOT_INSTALLER_VERSION,
    'generated_at' => $now,
    'read_only_preview' => !$installRequested,
    'expected_location' => $expected,
    'preflight_ok' => $preflightOk,
    'gates' => [
        'source_hashes' => $sourceGate ? 'PASS' : 'FAIL',
        'live_baselines' => $liveGate ? 'PASS' : 'FAIL',
        'dependencies' => $dependencyGate ? 'PASS' : 'FAIL',
        'protected_live_files' => $protectedGate ? 'PASS' : 'FAIL',
        'shutdown_checklist_files' => $shutdownGate ? 'PASS' : 'FAIL',
    ],
    'package_file_count' => count($packageNames),
    'package' => $preflightRows,
    'dependency_problems' => $dependencyProblems,
    'protected_live' => $protectedRows,
    'shutdown_checklist' => $shutdownRows,
    'install_requested' => $installRequested,
    'install_success' => $installSuccess,
    'install_log' => $installLog,
];

if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_live_root_migration_preview_' . $downloadStamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($previewReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
<title>MRL Live Root Migration Installer</title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1600px;margin:0 auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px;font-size:31px}h2{margin:0 0 13px}.small{color:var(--muted);font-size:13px}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;border:1px solid #4c7ba8;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:8px 8px 0 0}
button{padding:12px 18px;border-radius:8px;border:1px solid #a44141;background:#7a2525;color:white;font-weight:700;font-size:16px;cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}
code,pre{background:#111318;border-radius:5px}code{padding:2px 5px;color:#bddcff}pre{padding:14px;border:1px solid #303540;white-space:pre-wrap;word-break:break-word}
.notice{padding:13px 15px;border-radius:9px;background:#24272e;border:1px solid #404651;margin:12px 0}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
    <h1>MRL Live Root Migration Installer</h1>
    <div class="small">v001 · <?= mri_h($now) ?> · Preview-first controlled migration</div>

    <div class="notice">
        <strong>Current mode:</strong>
        <?php if ($installAttempted): ?>
            <span class="<?= $installSuccess ? 'pass' : 'fail' ?>">
                <?= $installSuccess ? 'INSTALL COMPLETE' : 'INSTALL ATTEMPTED — REVIEW OUTPUT' ?>
            </span>
        <?php else: ?>
            <span class="info">PREVIEW ONLY — NOTHING HAS BEEN CHANGED</span>
        <?php endif; ?>
    </div>

    <div class="summary">
        <div class="pill">Preflight: <span class="<?= $preflightOk ? 'pass' : 'fail' ?>"><?= $preflightOk ? 'PASS' : 'FAIL' ?></span></div>
        <div class="pill">Package: <span class="info"><?= count($packageNames) ?> files</span></div>
        <div class="pill">Source hashes: <span class="<?= $sourceGate ? 'pass' : 'fail' ?>"><?= $sourceGate ? 'PASS' : 'FAIL' ?></span></div>
        <div class="pill">LIVE baselines: <span class="<?= $liveGate ? 'pass' : 'fail' ?>"><?= $liveGate ? 'PASS' : 'FAIL' ?></span></div>
        <div class="pill">Dependencies: <span class="<?= $dependencyGate ? 'pass' : 'fail' ?>"><?= $dependencyGate ? 'PASS' : 'FAIL' ?></span></div>
        <div class="pill">Protected files: <span class="<?= $protectedGate ? 'pass' : 'fail' ?>"><?= $protectedGate ? 'PASS' : 'FAIL' ?></span></div>
        <div class="pill">Shutdown checklist: <span class="<?= $shutdownGate ? 'pass' : 'fail' ?>"><?= $shutdownGate ? 'PASS' : 'FAIL' ?></span></div>
    </div>

    <a class="button" href="?format=json&x=<?= mri_h((string)microtime(true)) ?>">Download JSON Preview</a>
</div>

<div class="panel">
    <h2>15-file migration package</h2>
    <table>
        <thead><tr><th>Path</th><th>TEST</th><th>LIVE</th><th>Source</th><th>LIVE baseline</th></tr></thead>
        <tbody>
        <?php foreach ($preflightRows as $row): ?>
            <tr>
                <td><code><?= mri_h($row['path']) ?></code></td>
                <td><?= mri_h(($row['test']['version'] ?? '') ?: 'MISSING') ?></td>
                <td><?= mri_h(($row['live']['version'] ?? '') ?: ($row['live'] === null ? 'MISSING' : '(none)')) ?></td>
                <td class="<?= $row['source_matches_baseline'] ? 'pass' : 'fail' ?>"><?= $row['source_matches_baseline'] ? 'MATCH' : 'MISMATCH' ?></td>
                <td class="<?= $row['live_matches_baseline'] ? 'pass' : 'fail' ?>"><?= $row['live_matches_baseline'] ? 'UNCHANGED' : 'DRIFTED' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2>Explicitly preserved LIVE files</h2>
    <p>These are not copied from TESTPHP8:</p>
    <ul>
        <li><code>email.php</code></li>
        <li><code>conf.inc.php</code></li>
        <li><code>config.php</code></li>
        <li><code>dbconfig.php</code></li>
        <li><code>wp-config.php</code></li>
    </ul>
</div>

<div class="panel">
    <h2>TESTPHP8 shutdown checklist — intentionally untouched</h2>
    <p class="warn">These four files are deliberately held for discussion before TESTPHP8 is sunset:</p>
    <ul>
        <li><code>default.php</code></li>
        <li><code>rebuild_year_index.php</code></li>
        <li><code>logout.php</code></li>
        <li><code>races.html</code></li>
    </ul>
</div>

<?php if (!$installAttempted): ?>
<div class="panel">
    <h2>INSTALL</h2>
    <p>
        Do not click INSTALL until the JSON preview has been reviewed.
        Opening this page and downloading the preview are read-only.
    </p>
    <form method="post" onsubmit="return confirm('Proceed with the approved 15-file LIVE root migration?');">
        <input type="hidden" name="confirm_install" value="YES">
        <button type="submit" <?= $preflightOk ? '' : 'disabled' ?>>INSTALL 15-FILE LIVE ROOT MIGRATION</button>
    </form>
</div>
<?php endif; ?>

<?php if ($installAttempted): ?>
<div class="panel">
    <h2>Installer output</h2>
    <pre><?= mri_h(implode("\n", $installLog)) ?></pre>
</div>
<?php endif; ?>

<div class="panel">
    <h2>Safety boundaries</h2>
    <ul>
        <li>No database changes.</li>
        <li>No WordPress configuration changes.</li>
        <li>No scheduler changes.</li>
        <li>No /race_results changes.</li>
        <li>No file deletions.</li>
        <li>No historical-file relocation yet.</li>
        <li>No changes to the four TESTPHP8 shutdown-checklist files.</li>
    </ul>
</div>

</div>
</body>
</html>
