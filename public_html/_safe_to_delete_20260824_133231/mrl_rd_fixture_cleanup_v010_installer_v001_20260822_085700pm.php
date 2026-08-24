<?php
declare(strict_types=1);

/**
 * MRL RD/RP Fixture Cleanup Installer
 *
 * VERSION: v001
 * GENERATED: 8/22/2026 8:57:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 * DATABASE CHANGES: NONE
 *
 * PURPOSE:
 * Remove the exact temporary Be Like Biff dual-qualifier fixture created
 * for the S1 R06/R07 -> R08 Replacement Pick test, while accepting the
 * cosmetically polished team_replacement_driver.php v010 baseline.
 *
 * THIS TOOL DOES NOT:
 * - change team.php
 * - change submit-team-picks.php
 * - change team_replacement_driver.php
 * - change race_results_rd_helper.php
 * - change the database
 * - change scheduler state
 *
 * TARGET FIXTURE:
 *   _rd_pending_Be_Like_Biff.json
 *   fixture_id = BE_LIKE_BIFF_DENNY_BLANEY_R06_R07
 *
 * CLEANUP:
 * - If a pre-fixture pending JSON backup exists, restore it.
 * - Otherwise delete only the owned test fixture JSON.
 * - Remove the fixture marker.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$checks = [];
$errors = [];
$postflight = [];
$cleaned = false;
$message = '';

function fc_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fc_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail];
}

function fc_read_json(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

// -----------------------------------------------------------------------------
// Locate the exact R07 folder / fixture marker
// -----------------------------------------------------------------------------

$yearDir = $root . '/race_results/2026';
$markerMatches = is_dir($yearDir)
    ? glob($yearDir . '/R07_*/_rd_pending_Be_Like_Biff.fixture_marker_20260822_074600pm.json')
    : [];
if (!is_array($markerMatches)) $markerMatches = [];

$marker = count($markerMatches) === 1 ? (string)$markerMatches[0] : '';
$r07Dir = $marker !== '' ? dirname($marker) : '';
$target = $r07Dir !== '' ? $r07Dir . '/_rd_pending_Be_Like_Biff.json' : '';

$markerData = $marker !== '' ? fc_read_json($marker) : null;
$fixtureData = $target !== '' ? fc_read_json($target) : null;

$backup = is_array($markerData) ? trim((string)($markerData['backup'] ?? '')) : '';

// -----------------------------------------------------------------------------
// Application baseline checks
// -----------------------------------------------------------------------------

$teamPath = $root . '/team.php';
$submitPath = $root . '/submit-team-picks.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$teamSrc = is_file($teamPath) ? (string)@file_get_contents($teamPath) : '';
$submitSrc = is_file($submitPath) ? (string)@file_get_contents($submitPath) : '';
$wrapperSrc = is_file($wrapperPath) ? (string)@file_get_contents($wrapperPath) : '';
$helperSrc = is_file($helperPath) ? (string)@file_get_contents($helperPath) : '';

fc_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host);
if ($host !== $expectedHost) $errors[] = 'REFUSED: TESTPHP8 only.';

$baselineChecks = [
    'team.php is v024' => strpos($teamSrc, 'VERSION: v024') !== false,
    'team wrapper is v010' =>
        strpos($wrapperSrc, 'VERSION: v010') !== false
        && strpos($wrapperSrc, "\$rdWrapperVersion = 'v010';") !== false,
    'submit-team-picks.php is v007' => strpos($submitSrc, 'VERSION: v007') !== false,
    'shared RD helper remains v005' => strpos($helperSrc, 'VERSION: v005') !== false,
];

foreach ($baselineChecks as $label => $ok) {
    fc_check($checks, $label, $ok, $ok ? 'PASS' : 'FAIL');
    if (!$ok) $errors[] = 'Application baseline mismatch: ' . $label;
}

// -----------------------------------------------------------------------------
// Fixture ownership checks
// -----------------------------------------------------------------------------

$oneMarker = count($markerMatches) === 1;
fc_check(
    $checks,
    'Exactly one owned fixture marker found',
    $oneMarker,
    $oneMarker ? $marker : ('found ' . count($markerMatches))
);
if (!$oneMarker) {
    $errors[] = 'Expected exactly one Be Like Biff fixture marker.';
}

$markerOwned = is_array($markerData)
    && (string)($markerData['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07';

fc_check($checks, 'Fixture marker ownership verified', $markerOwned, $markerOwned ? 'PASS' : 'FAIL');
if (!$markerOwned) {
    $errors[] = 'Fixture marker does not match the expected owned test fixture.';
}

$fixtureOwned = is_array($fixtureData)
    && !empty($fixtureData['test_fixture'])
    && (string)($fixtureData['fixture_id'] ?? '') === 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07'
    && (string)($fixtureData['teamName'] ?? '') === 'Be Like Biff'
    && (string)($fixtureData['segment'] ?? '') === 'S1'
    && (string)($fixtureData['effective_race'] ?? '') === 'R08'
    && (int)($fixtureData['qualifier_count'] ?? 0) === 2;

fc_check($checks, 'Pending fixture ownership verified', $fixtureOwned, $target);
if (!$fixtureOwned) {
    $errors[] = 'Pending JSON is missing or no longer matches the owned test fixture.';
}

$backupState = 'none recorded';
if ($backup !== '') {
    $backupState = is_file($backup) ? ('will restore: ' . $backup) : ('recorded but missing: ' . $backup);
}
fc_check($checks, 'Pre-fixture backup state inspected', true, $backupState);

if ($backup !== '' && !is_file($backup)) {
    $errors[] = 'Marker says a prior pending JSON existed, but its backup file is missing. Refusing cleanup.';
}

// -----------------------------------------------------------------------------
// Cleanup action
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'cleanup') {
        if ($backup !== '' && is_file($backup)) {
            if (!@copy($backup, $target)) {
                $errors[] = 'Could not restore the pre-fixture pending JSON.';
            } else {
                @unlink($backup);
                @unlink($marker);
                $message = 'FIXTURE CLEANUP COMPLETE — previous pending JSON restored.';
            }
        } else {
            if (!@unlink($target)) {
                $errors[] = 'Could not delete the owned fixture JSON.';
            } else {
                @unlink($marker);
                $message = 'FIXTURE CLEANUP COMPLETE — owned test fixture removed; no prior pending JSON existed.';
            }
        }

        if (empty($errors)) {
            $targetStillExists = is_file($target);
            $markerStillExists = is_file($marker);

            $postflight[] = ['Fixture marker removed', !$markerStillExists];

            if ($backup === '') {
                $postflight[] = ['Owned fixture JSON removed', !$targetStillExists];
            } else {
                $restoredData = fc_read_json($target);
                $restoredIsNotFixture = is_array($restoredData)
                    && empty($restoredData['test_fixture'])
                    && (string)($restoredData['fixture_id'] ?? '') !== 'BE_LIKE_BIFF_DENNY_BLANEY_R06_R07';

                $postflight[] = ['Prior pending JSON restored', $targetStillExists && $restoredIsNotFixture];
            }

            $postflight[] = ['team.php still v024', strpos((string)@file_get_contents($teamPath), 'VERSION: v024') !== false];
            $postflight[] = ['wrapper still v010', strpos((string)@file_get_contents($wrapperPath), 'VERSION: v010') !== false];
            $postflight[] = ['submit still v007', strpos((string)@file_get_contents($submitPath), 'VERSION: v007') !== false];
            $postflight[] = ['helper still v005', strpos((string)@file_get_contents($helperPath), 'VERSION: v005') !== false];

            foreach ($postflight as $pf) {
                if (!$pf[1]) {
                    $errors[] = 'Postflight failed: ' . $pf[0];
                }
            }

            if (empty($errors)) {
                $cleaned = true;
            }
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL RP Fixture Cleanup</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1100px;margin:0 auto;padding:14px}
.banner{background:#16301f;border:1px solid #3d7c4f;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#9effbc;font-size:22px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333}
.ok{color:#69ef98;font-weight:bold}
.bad{color:#ff7777;font-weight:bold}
.warn{color:#ffd36b;font-weight:bold}
code{color:#f2d996}
button{padding:9px 14px;border-radius:7px;font-weight:bold;cursor:pointer;background:#265f36;color:#effff3;border:1px solid #4c9a60}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL RP Fixture Cleanup v001</h1>
<div>TESTPHP8 ONLY • accepts wrapper v010 • no DB or application-file changes</div>
</div>

<div class="card">
<h2>Purpose</h2>
<p>Remove only the owned Be Like Biff / Denny Hamlin + Ryan Blaney S1 test fixture.</p>
<p class="warn">This tool does not downgrade or modify the v010 cosmetic wrapper.</p>
</div>

<div class="card">
<h2>Preflight</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:55%"><?=fc_h($c['name'])?></td>
<td style="width:10%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
<td><?=fc_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
<h2 class="bad">STOPPED SAFELY</h2>
<?php foreach ($errors as $e): ?><div class="bad">• <?=fc_h($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($cleaned): ?>
<div class="card">
<h2 class="ok"><?=fc_h($message)?></h2>
<table>
<?php foreach ($postflight as $pf): ?>
<tr><td><?=fc_h($pf[0])?></td><td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td></tr>
<?php endforeach; ?>
</table>
<p class="warn">Next: Ctrl+F5 TESTPHP8 team.php. If normal S4 behavior is back and no RP fixture appears, cleanup is complete.</p>
</div>
<?php elseif (empty($errors)): ?>
<div class="card">
<h2>Ready</h2>
<form method="post">
<button type="submit" name="action" value="cleanup">REMOVE OWNED BE LIKE BIFF TEST FIXTURE</button>
</form>
</div>
<?php endif; ?>

</div>
</body>
</html>
