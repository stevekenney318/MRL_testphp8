<?php
declare(strict_types=1);

/**
 * MRL Replacement Pick Fixture Manager
 *
 * VERSION: v001
 * GENERATED: 8/22/2026 7:46:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 * DATABASE CHANGES: NONE
 *
 * PURPOSE:
 * Create/remove one controlled real-flow pending JSON fixture for:
 *   Team: Be Like Biff
 *   Segment: S1
 *   Group B: Denny Hamlin
 *   Group C: Ryan Blaney
 *   Trigger races: R06 / R07
 *   Effective race: R08
 *
 * SAFETY:
 * - Locates exactly one 2026 R07_* folder; refuses otherwise.
 * - If a pending JSON already exists, it is backed up before fixture creation.
 * - REMOVE restores that prior file if one existed; otherwise deletes fixture.
 * - Does not touch PHP application files.
 * - Does not touch the database.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$checks = [];
$errors = [];
$message = '';
$messageOk = false;

function fx_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fx_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail];
}

function fx_read_json(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function fx_write_json(string $path, array $payload): bool
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

// -----------------------------------------------------------------------------
// PREFLIGHT
// -----------------------------------------------------------------------------

fx_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host);
if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: TESTPHP8 host only.';
}

$raceBase = $root . '/race_results/2026';
$matches = is_dir($raceBase) ? glob($raceBase . '/R07_*') : [];
if (!is_array($matches)) $matches = [];

$raceFolders = [];
foreach ($matches as $m) {
    if (is_dir($m)) $raceFolders[] = $m;
}

$oneR07 = count($raceFolders) === 1;
fx_check(
    $checks,
    'Exactly one 2026 R07 race folder found',
    $oneR07,
    $oneR07 ? $raceFolders[0] : ('found ' . count($raceFolders))
);

if (!$oneR07) {
    $errors[] = 'REFUSED: Expected exactly one /race_results/2026/R07_* folder.';
}

$r07 = $oneR07 ? $raceFolders[0] : '';
$target = $r07 !== '' ? $r07 . '/_rd_pending_Be_Like_Biff.json' : '';
$backup = $r07 !== '' ? $r07 . '/_rd_pending_Be_Like_Biff.before_fixture_20260822_074600pm.json' : '';
$marker = $r07 !== '' ? $r07 . '/_rd_pending_Be_Like_Biff.fixture_marker_20260822_074600pm.json' : '';

if ($r07 !== '') {
    fx_check($checks, 'R07 folder is writable', is_writable($r07), $r07);
    if (!is_writable($r07)) {
        $errors[] = 'R07 folder is not writable.';
    }
}

$existing = $target !== '' ? fx_read_json($target) : null;
$existingIsFixture = is_array($existing)
    && !empty($existing['test_fixture'])
    && (string)($existing['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07';

fx_check(
    $checks,
    'Pending target state inspected',
    true,
    $target === '' ? 'n/a' : (is_file($target)
        ? ($existingIsFixture ? 'fixture already present' : 'existing file will be backed up before fixture')
        : 'no existing pending file')
);

$teamPhp = $root . '/team.php';
$wrapperPhp = $root . '/team_replacement_driver.php';
$submitPhp = $root . '/submit-team-picks.php';
$helperPhp = $root . '/race_results/race_results_rd_helper.php';

$teamSrc = is_file($teamPhp) ? (string)@file_get_contents($teamPhp) : '';
$wrapperSrc = is_file($wrapperPhp) ? (string)@file_get_contents($wrapperPhp) : '';
$submitSrc = is_file($submitPhp) ? (string)@file_get_contents($submitPhp) : '';
$helperSrc = is_file($helperPhp) ? (string)@file_get_contents($helperPhp) : '';

$appChecks = [
    'team.php is v024' => strpos($teamSrc, 'VERSION: v024') !== false,
    'team wrapper is v009' => strpos($wrapperSrc, 'VERSION: v009') !== false,
    'submit-team-picks.php is v007' => strpos($submitSrc, 'VERSION: v007') !== false,
    'shared RD helper remains v005' => strpos($helperSrc, 'VERSION: v005') !== false,
];

foreach ($appChecks as $label => $ok) {
    fx_check($checks, $label, $ok, $ok ? 'PASS' : 'FAIL');
    if (!$ok) $errors[] = 'Application baseline mismatch: ' . $label;
}

// -----------------------------------------------------------------------------
// ACTIONS
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'create') {
        if ($existingIsFixture) {
            $message = 'Fixture is already present. Nothing changed.';
            $messageOk = true;
        } else {
            // Back up any real/pre-existing pending file before overwriting it.
            if (is_file($target)) {
                if (is_file($backup)) {
                    $errors[] = 'Backup file already exists; refusing to overwrite it.';
                } elseif (!@copy($target, $backup)) {
                    $errors[] = 'Could not back up the existing pending JSON.';
                }
            }

            if (empty($errors)) {
                $payload = [
                    'userID' => 0,
                    'teamName' => 'Be Like Biff',
                    'segment' => 'S1',
                    'status' => 'MULTIPLE_RD_AVAILABLE',
                    'qualifier_count' => 2,
                    'qualifiers' => [
                        [
                            'slot' => 'B',
                            'driver' => 'Denny Hamlin',
                            'trigger_races' => ['R06', 'R07'],
                            'effective_race' => 'R08',
                        ],
                        [
                            'slot' => 'C',
                            'driver' => 'Ryan Blaney',
                            'trigger_races' => ['R06', 'R07'],
                            'effective_race' => 'R08',
                        ],
                    ],

                    // Backward-compatible singular fields.
                    'slot' => 'B',
                    'driver' => 'Denny Hamlin',
                    'trigger_races' => ['R06', 'R07'],
                    'effective_race' => 'R08',

                    'detected_at' => date('Y-m-d\TH:i:s'),
                    'test_fixture' => true,
                    'fixture_id' => 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07',
                ];

                if (!fx_write_json($target, $payload)) {
                    $errors[] = 'Could not write fixture JSON.';
                } else {
                    $markerPayload = [
                        'fixture_id' => 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07',
                        'target' => $target,
                        'backup' => is_file($backup) ? $backup : '',
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                    if (!fx_write_json($marker, $markerPayload)) {
                        @unlink($target);
                        if (is_file($backup)) @copy($backup, $target);
                        $errors[] = 'Fixture marker write failed; fixture creation was rolled back.';
                    } else {
                        $verify = fx_read_json($target);
                        $ok = is_array($verify)
                            && (string)($verify['teamName'] ?? '') === 'Be Like Biff'
                            && (int)($verify['qualifier_count'] ?? 0) === 2
                            && count((array)($verify['qualifiers'] ?? [])) === 2
                            && (string)($verify['qualifiers'][0]['driver'] ?? '') === 'Denny Hamlin'
                            && (string)($verify['qualifiers'][1]['driver'] ?? '') === 'Ryan Blaney'
                            && (string)($verify['effective_race'] ?? '') === 'R08';

                        if (!$ok) {
                            @unlink($target);
                            if (is_file($backup)) @copy($backup, $target);
                            @unlink($marker);
                            $errors[] = 'Fixture postflight verification failed; fixture was rolled back.';
                        } else {
                            $message = 'FIXTURE CREATED — Be Like Biff now has Denny Hamlin (B) + Ryan Blaney (C), R06/R07, effective R08.';
                            $messageOk = true;
                        }
                    }
                }
            }
        }
    }

    if ($action === 'remove') {
        $markerData = fx_read_json($marker);

        if (!is_array($markerData)) {
            $errors[] = 'Fixture marker not found. Refusing cleanup because this tool cannot prove ownership of the target file.';
        } else {
            $expectedFixture = fx_read_json($target);
            $owned = is_array($expectedFixture)
                && !empty($expectedFixture['test_fixture'])
                && (string)($expectedFixture['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07';

            if (!$owned) {
                $errors[] = 'Target no longer matches this fixture. Refusing cleanup.';
            } else {
                $priorBackup = (string)($markerData['backup'] ?? '');

                if ($priorBackup !== '' && is_file($priorBackup)) {
                    if (!@copy($priorBackup, $target)) {
                        $errors[] = 'Could not restore pre-fixture pending JSON.';
                    } else {
                        @unlink($priorBackup);
                        @unlink($marker);
                        $message = 'FIXTURE REMOVED — previous pending JSON restored.';
                        $messageOk = true;
                    }
                } else {
                    if (!@unlink($target)) {
                        $errors[] = 'Could not delete fixture JSON.';
                    } else {
                        @unlink($marker);
                        $message = 'FIXTURE REMOVED — no prior pending JSON existed.';
                        $messageOk = true;
                    }
                }
            }
        }
    }
}

$currentPayload = $target !== '' ? fx_read_json($target) : null;
$currentFixture = is_array($currentPayload)
    && !empty($currentPayload['test_fixture'])
    && (string)($currentPayload['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07';

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL RP Fixture Manager</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1050px;margin:0 auto;padding:14px}
.banner{background:#2b3219;border:1px solid #6f7e32;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#f2ffb9;font-size:22px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333}
.ok{color:#69ef98;font-weight:bold}
.bad{color:#ff7777;font-weight:bold}
.warn{color:#ffd36b;font-weight:bold}
code{color:#f2d996}
button{padding:9px 14px;border-radius:7px;font-weight:bold;cursor:pointer}
.create{background:#285c32;color:#eaffee;border:1px solid #4b9658}
.remove{background:#6a2626;color:#ffecec;border:1px solid #a54a4a}
.state{font-size:17px;font-weight:bold}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL Replacement Pick Fixture Manager v001</h1>
<div>TESTPHP8 ONLY • Be Like Biff • Denny Hamlin + Ryan Blaney • no DB changes</div>
</div>

<div class="card">
<h2>Fixture</h2>
<table>
<tr><td>Team</td><td><strong>Be Like Biff</strong></td></tr>
<tr><td>Segment</td><td>S1</td></tr>
<tr><td>Qualifier 1</td><td>Group B — Denny Hamlin — R06/R07</td></tr>
<tr><td>Qualifier 2</td><td>Group C — Ryan Blaney — R06/R07</td></tr>
<tr><td>Effective race</td><td>R08</td></tr>
<tr><td>Target</td><td><code><?=fx_h($target)?></code></td></tr>
</table>
</div>

<div class="card">
<h2>Preflight</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:55%"><?=fx_h($c['name'])?></td>
<td style="width:10%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
<td><?=fx_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
<h2 class="bad">STOPPED SAFELY</h2>
<?php foreach ($errors as $e): ?><div class="bad">• <?=fx_h($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($message !== ''): ?>
<div class="card">
<h2 class="<?=$messageOk?'ok':'bad'?>"><?=fx_h($message)?></h2>
</div>
<?php endif; ?>

<?php if (empty($errors)): ?>
<div class="card">
<h2>Current State</h2>
<div class="state <?=$currentFixture?'ok':'warn'?>">
    <?=$currentFixture ? 'TEST FIXTURE IS PRESENT' : 'TEST FIXTURE IS NOT PRESENT'?>
</div>

<form method="post" style="display:inline-block;margin-top:10px">
<button class="create" type="submit" name="action" value="create">CREATE DUAL RP FIXTURE</button>
</form>

<?php if ($currentFixture): ?>
<form method="post" style="display:inline-block;margin-top:10px;margin-left:8px">
<button class="remove" type="submit" name="action" value="remove">REMOVE / RESTORE FIXTURE</button>
</form>
<?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
<h2>Test Sequence After Create</h2>
<ol>
<li>Leave the scheduler OFF.</li>
<li>Open your normal TESTPHP8 <code>/team.php</code>.</li>
<li>The Replacement Pick section should appear automatically.</li>
<li>It should show exactly two required choices: Group B — Denny Hamlin and Group C — Ryan Blaney.</li>
<li>Do not submit the actual pick yet. First send a screenshot so we verify the real page state.</li>
</ol>
</div>

</div>
</body>
</html>
