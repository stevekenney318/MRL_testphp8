<?php
declare(strict_types=1);

/**
 * mrl_rd_simulation_race_values_patch.php
 *
 * VERSION: v001
 * GENERATED: 8/20/2026 11:27:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 *
 * PURPOSE:
 * Make the RD simulator race-centric and make override application auditable.
 *
 * INSTALLS:
 * - race_results/admin_rd_simulation.php v003 -> v004
 *
 * CHANGES:
 * - Replaces one shared "Simulated NET points" value with one optional NET
 *   value per completed race.
 * - A blank race value means "use saved snapshot value".
 * - Entering a value for a race means "override this driver's NET for this race".
 * - Removes the confusing race checkboxes.
 * - Adds normalized/case-insensitive driver-name matching when applying an
 *   override to parsed snapshot rows.
 * - If the selected driver is not present in a completed race snapshot,
 *   the simulator adds a synthetic row for THAT ISOLATED FIXTURE ONLY.
 * - Adds a selected-driver audit table showing saved NET, simulated NET,
 *   whether a source row matched, and whether an override was applied.
 *
 * DOES NOT CHANGE:
 * - race_results_rd_helper.php v005
 * - race_results_snapshot_helper.php
 * - database schema or data
 * - normal race snapshots
 * - team.php
 * - submit-team-picks.php
 * - Live MRL
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const MRL_RACE_VALUES_HOST = 'testphp8.manliusracingleague.com';

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$simPath = $root . '/race_results/admin_rd_simulation.php';
$backupDir = $root . '/mrl_rd_simulation_race_values_backup_20260820_112700pm';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;

function rvp_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rvp_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

function rvp_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}

function rvp_atomic_write(string $path, string $content): bool
{
    $tmp = $path . '.mrl_tmp_' . str_replace('.', '', uniqid('', true));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

rvp_check($checks, 'Host is TESTPHP8', $host === MRL_RACE_VALUES_HOST, $host);
rvp_check($checks, 'Document root available', $root !== '' && is_dir($root), $root);
rvp_check($checks, 'Simulator file exists', is_file($simPath), $simPath);

if ($host !== MRL_RACE_VALUES_HOST) $errors[] = 'REFUSED: TestPHP8-only patch.';
if ($root === '' || !is_dir($root)) $errors[] = 'Document root unavailable.';
if (!is_file($simPath)) $errors[] = 'admin_rd_simulation.php not found.';

$current = is_file($simPath) ? (string)@file_get_contents($simPath) : '';
$isV003 = strpos($current, 'VERSION: v003') !== false;
$isV004 = strpos($current, 'VERSION: v004') !== false;

rvp_check(
    $checks,
    'Simulator expected source version',
    $isV003 || $isV004,
    $isV004 ? 'VERSION: v004 (already installed)' : 'VERSION: v003'
);

if (!$isV003 && !$isV004) {
    $errors[] = 'REFUSED: simulator is not expected v003/v004 source.';
}

$prepared = $current;

if (empty($errors) && $isV003) {
    try {
        $requiredMarkers = [
            'function rds_make_fixture_set(',
            '$overrideDriver = trim((string)($_POST[\'override_driver\'] ?? \'\'));',
            'name="override_races[]"',
            'name="override_net"',
            'Fixture audit',
        ];

        foreach ($requiredMarkers as $marker) {
            $found = strpos($current, $marker) !== false;
            rvp_check($checks, 'Expected v003 marker', $found, $marker);
            if (!$found) {
                throw new RuntimeException('Expected v003 marker missing: ' . $marker);
            }
        }

        $prepared = rvp_replace_once(
            $prepared,
            ' * VERSION: v003',
            ' * VERSION: v004',
            'version'
        );

        $prepared = rvp_replace_once(
            $prepared,
            ' * LAST MODIFIED: 8/20/2026 11:05:00 pm',
            ' * LAST MODIFIED: 8/20/2026 11:27:00 pm',
            'timestamp'
        );

        $oldChangelog = <<<'OLD'
 * CHANGELOG:
 * v003 (8/20/2026 11:05:00 pm)
OLD;

        $newChangelog = <<<'NEW'
 * CHANGELOG:
 * v004 (8/20/2026 11:27:00 pm)
 * - CHANGE: Simulator input is now race-centric: each completed race has its
 *   own optional NET value field; blank means use saved snapshot value.
 * - FIX: Override matching now normalizes/case-folds driver names instead of
 *   requiring an exact array-key match.
 * - NEW: If a selected driver is absent from a completed source snapshot, an
 *   isolated synthetic fixture row can be added for simulation only.
 * - NEW: Selected-driver audit shows saved NET, simulated NET, row match, and
 *   override state for every completed race.
 * - REMOVE: Shared NET field + race override checkboxes.
 *
 * v003 (8/20/2026 11:05:00 pm)
NEW;

        $prepared = rvp_replace_once(
            $prepared,
            $oldChangelog,
            $newChangelog,
            'changelog'
        );

        $oldFunction = <<<'OLD'
function rds_make_fixture_set(
    string $fixtureDir,
    array $sourcePoints,
    string $overrideDriver,
    array $overrideRaces,
    int $overrideNet
): array {
    if (!is_dir($fixtureDir) && !@mkdir($fixtureDir, 0775, true) && !is_dir($fixtureDir)) {
        throw new RuntimeException('Could not create fixture directory: ' . $fixtureDir);
    }

    rds_clear_dir($fixtureDir);

    $points = [];
    $meta = [];

    foreach ($sourcePoints as $rn => $raceData) {
        $rn = (int)$rn;
        $driverRows = isset($raceData['drivers']) && is_array($raceData['drivers'])
            ? $raceData['drivers']
            : [];

        $overrideApplied = false;

        if (
            $overrideDriver !== '' &&
            isset($overrideRaces[$rn]) &&
            isset($driverRows[$overrideDriver]) &&
            is_array($driverRows[$overrideDriver])
        ) {
            // NET = PTS - PENALTY. Use a clean simulated row.
            $driverRows[$overrideDriver]['pts'] = $overrideNet;
            $driverRows[$overrideDriver]['bonus'] = 0;
            $driverRows[$overrideDriver]['penalty'] = 0;
            $driverRows[$overrideDriver]['net'] = $overrideNet;
            $overrideApplied = true;
        }

        $fixturePath = $fixtureDir
            . '/R'
            . str_pad((string)$rn, 2, '0', STR_PAD_LEFT)
            . '_rd_fixture.html';

        if (@file_put_contents($fixturePath, rds_fixture_html($rn, $driverRows), LOCK_EX) === false) {
            throw new RuntimeException('Could not write fixture: ' . $fixturePath);
        }

        $reparsed = rrs_load_snapshot_driver_points($fixturePath);
        if (!is_array($reparsed) || empty($reparsed)) {
            throw new RuntimeException('Fixture re-parse failed for R' . str_pad((string)$rn, 2, '0', STR_PAD_LEFT) . '.');
        }

        $points[$rn] = [];
        foreach ($reparsed as $driverName => $data) {
            $points[$rn][$driverName] = (int)($data['net'] ?? 0);
        }

        $meta[$rn] = [
            'source' => (string)($raceData['snapshot'] ?? ''),
            'fixture' => $fixturePath,
            'override_applied' => $overrideApplied,
        ];
    }

    ksort($points, SORT_NUMERIC);

    return [
        'points' => $points,
        'meta' => $meta,
    ];
}
OLD;

        $newFunction = <<<'NEW'
function rds_driver_key(string $name): string
{
    $name = rrs_norm_text($name);
    return strtolower($name);
}

function rds_find_driver_row_key(array $driverRows, string $driverName): string
{
    $wanted = rds_driver_key($driverName);
    if ($wanted === '') {
        return '';
    }

    foreach ($driverRows as $key => $row) {
        if (rds_driver_key((string)$key) === $wanted) {
            return (string)$key;
        }
    }

    return '';
}

function rds_make_fixture_set(
    string $fixtureDir,
    array $sourcePoints,
    string $overrideDriver,
    array $raceNetOverrides
): array {
    if (!is_dir($fixtureDir) && !@mkdir($fixtureDir, 0775, true) && !is_dir($fixtureDir)) {
        throw new RuntimeException('Could not create fixture directory: ' . $fixtureDir);
    }

    rds_clear_dir($fixtureDir);

    $points = [];
    $meta = [];

    foreach ($sourcePoints as $rn => $raceData) {
        $rn = (int)$rn;
        $driverRows = isset($raceData['drivers']) && is_array($raceData['drivers'])
            ? $raceData['drivers']
            : [];

        $overrideApplied = false;
        $sourceMatched = false;
        $syntheticAdded = false;
        $savedNet = null;
        $simulatedNet = null;

        $matchedKey = '';
        if ($overrideDriver !== '') {
            $matchedKey = rds_find_driver_row_key($driverRows, $overrideDriver);

            if ($matchedKey !== '' && isset($driverRows[$matchedKey]) && is_array($driverRows[$matchedKey])) {
                $sourceMatched = true;
                $savedNet = (int)($driverRows[$matchedKey]['net'] ?? 0);
            }
        }

        if ($overrideDriver !== '' && array_key_exists($rn, $raceNetOverrides)) {
            $overrideNet = (int)$raceNetOverrides[$rn];

            if ($matchedKey === '') {
                $matchedKey = $overrideDriver;
                $driverRows[$matchedKey] = [
                    'pts' => $overrideNet,
                    'bonus' => 0,
                    'penalty' => 0,
                    'net' => $overrideNet,
                ];
                $syntheticAdded = true;
            } else {
                $driverRows[$matchedKey]['pts'] = $overrideNet;
                $driverRows[$matchedKey]['bonus'] = 0;
                $driverRows[$matchedKey]['penalty'] = 0;
                $driverRows[$matchedKey]['net'] = $overrideNet;
            }

            $overrideApplied = true;
            $simulatedNet = $overrideNet;
        } elseif ($sourceMatched) {
            $simulatedNet = $savedNet;
        }

        $fixturePath = $fixtureDir
            . '/R'
            . str_pad((string)$rn, 2, '0', STR_PAD_LEFT)
            . '_rd_fixture.html';

        if (@file_put_contents($fixturePath, rds_fixture_html($rn, $driverRows), LOCK_EX) === false) {
            throw new RuntimeException('Could not write fixture: ' . $fixturePath);
        }

        $reparsed = rrs_load_snapshot_driver_points($fixturePath);
        if (!is_array($reparsed) || empty($reparsed)) {
            throw new RuntimeException('Fixture re-parse failed for R' . str_pad((string)$rn, 2, '0', STR_PAD_LEFT) . '.');
        }

        $points[$rn] = [];
        foreach ($reparsed as $driverName => $data) {
            $points[$rn][$driverName] = (int)($data['net'] ?? 0);
        }

        $reparsedMatchedKey = $overrideDriver !== ''
            ? rds_find_driver_row_key($reparsed, $overrideDriver)
            : '';

        if ($reparsedMatchedKey !== '' && isset($reparsed[$reparsedMatchedKey])) {
            $simulatedNet = (int)($reparsed[$reparsedMatchedKey]['net'] ?? 0);
        }

        $meta[$rn] = [
            'source' => (string)($raceData['snapshot'] ?? ''),
            'fixture' => $fixturePath,
            'override_applied' => $overrideApplied,
            'source_matched' => $sourceMatched,
            'synthetic_added' => $syntheticAdded,
            'saved_net' => $savedNet,
            'simulated_net' => $simulatedNet,
        ];
    }

    ksort($points, SORT_NUMERIC);

    return [
        'points' => $points,
        'meta' => $meta,
    ];
}
NEW;

        $prepared = rvp_replace_once(
            $prepared,
            $oldFunction,
            $newFunction,
            'fixture generator'
        );

        $oldInputParse = <<<'OLD'
$overrideDriver = trim((string)($_POST['override_driver'] ?? ''));
$overrideNet = isset($_POST['override_net']) && preg_match('/^-?\d+$/', (string)$_POST['override_net'])
    ? (int)$_POST['override_net']
    : 0;

$overrideRaceMap = [];
if (isset($_POST['override_races']) && is_array($_POST['override_races'])) {
    foreach ($_POST['override_races'] as $rn) {
        $n = (int)$rn;
        if ($n > 0) {
            $overrideRaceMap[$n] = true;
        }
    }
}
OLD;

        $newInputParse = <<<'NEW'
$overrideDriver = trim((string)($_POST['override_driver'] ?? ''));

$raceNetOverrides = [];
$postedRaceNet = isset($_POST['race_net']) && is_array($_POST['race_net'])
    ? $_POST['race_net']
    : [];

foreach ($postedRaceNet as $rn => $rawValue) {
    $raceNumber = (int)$rn;
    $value = trim((string)$rawValue);

    if ($raceNumber <= 0 || $value === '') {
        continue;
    }

    if (!preg_match('/^-?\d+$/', $value)) {
        $error = 'Each simulated NET value must be a whole number or left blank.';
        break;
    }

    $raceNetOverrides[$raceNumber] = (int)$value;
}
NEW;

        $prepared = rvp_replace_once(
            $prepared,
            $oldInputParse,
            $newInputParse,
            'input parsing'
        );

        $oldRunValidation = <<<'OLD'
            foreach (array_keys($overrideRaceMap) as $rn) {
                if ($rn < (int)$bounds['start'] || $rn > $throughRace) {
                    throw new RuntimeException('An override race is outside the loaded completed-race range.');
                }
            }

            $fixtureDir = __DIR__
OLD;

        $newRunValidation = <<<'NEW'
            foreach (array_keys($raceNetOverrides) as $rn) {
                if ($rn < (int)$bounds['start'] || $rn > $throughRace) {
                    throw new RuntimeException('A race NET override is outside the loaded completed-race range.');
                }
            }

            $fixtureDir = __DIR__
NEW;

        $prepared = rvp_replace_once(
            $prepared,
            $oldRunValidation,
            $newRunValidation,
            'run validation'
        );

        $oldCall = <<<'OLD'
            $fixtureResult = rds_make_fixture_set(
                $fixtureDir,
                $sourcePoints,
                $overrideDriver,
                $overrideRaceMap,
                $overrideNet
            );
OLD;

        $newCall = <<<'NEW'
            $fixtureResult = rds_make_fixture_set(
                $fixtureDir,
                $sourcePoints,
                $overrideDriver,
                $raceNetOverrides
            );
NEW;

        $prepared = rvp_replace_once(
            $prepared,
            $oldCall,
            $newCall,
            'fixture call'
        );

        $oldForm = <<<'OLD'
            <div class="grid">
                <div>
                    <label>Optional driver override</label>
                    <select name="override_driver">
                        <option value="">No override — use saved snapshot values</option>
                        <?php foreach ($driverNames as $driver): ?>
                            <option value="<?=rds_h($driver)?>" <?=$overrideDriver===$driver?'selected':''?>><?=rds_h($driver)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Simulated NET points</label>
                    <input name="override_net" value="<?=rds_h((string)$overrideNet)?>">
                </div>
            </div>

            <label style="margin-top:9px">Apply override to completed race(s)</label>
            <div class="races">
                <?php foreach ($sourcePoints as $rn => $row): $rn=(int)$rn; ?>
                    <label class="racecheck">
                        <input type="checkbox" name="override_races[]" value="<?=$rn?>" <?=isset($overrideRaceMap[$rn])?'checked':''?>>
                        R<?=str_pad((string)$rn,2,'0',STR_PAD_LEFT)?>
                    </label>
                <?php endforeach; ?>
            </div>
OLD;

        $newForm = <<<'NEW'
            <div class="grid">
                <div>
                    <label>Optional driver override</label>
                    <select name="override_driver">
                        <option value="">No override — use saved snapshot values</option>
                        <?php foreach ($driverNames as $driver): ?>
                            <option value="<?=rds_h($driver)?>" <?=$overrideDriver===$driver?'selected':''?>><?=rds_h($driver)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label style="margin-top:9px">Optional simulated NET by completed race</label>
            <div class="races">
                <?php foreach ($sourcePoints as $rn => $row): $rn=(int)$rn; ?>
                    <div class="racecheck" style="display:block;min-width:105px">
                        <div style="font-weight:700;margin-bottom:4px">
                            R<?=str_pad((string)$rn,2,'0',STR_PAD_LEFT)?>
                        </div>
                        <input
                            name="race_net[<?=$rn?>]"
                            value="<?=isset($raceNetOverrides[$rn])?rds_h((string)$raceNetOverrides[$rn]):''?>"
                            placeholder="saved"
                            style="width:88px"
                        >
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="small" style="margin-top:6px">
                Blank = use saved snapshot NET. Enter a value only for the race(s) you want to simulate.
            </div>
NEW;

        $prepared = rvp_replace_once(
            $prepared,
            $oldForm,
            $newForm,
            'race-centric form'
        );

        $oldFixtureHeader = <<<'OLD'
                <tr><th>Race</th><th>Source snapshot used</th><th>Simulation fixture</th><th>Override</th></tr>
OLD;

        $newFixtureHeader = <<<'NEW'
                <tr>
                    <th>Race</th>
                    <th>Source snapshot used</th>
                    <th>Simulation fixture</th>
                    <th>Source Row</th>
                    <th>Saved NET</th>
                    <th>Simulated NET</th>
                    <th>Override</th>
                </tr>
NEW;

        $prepared = rvp_replace_once(
            $prepared,
            $oldFixtureHeader,
            $newFixtureHeader,
            'fixture audit header'
        );

        $oldFixtureCells = <<<'OLD'
                        <td class="small"><?=rds_h(basename((string)$meta['source']))?></td>
                        <td class="small"><?=rds_h(str_replace(__DIR__ . '/', '', (string)$meta['fixture']))?></td>
                        <td><?=!empty($meta['override_applied'])?'YES':'—'?></td>
OLD;

        $newFixtureCells = <<<'NEW'
                        <td class="small"><?=rds_h(basename((string)$meta['source']))?></td>
                        <td class="small"><?=rds_h(str_replace(__DIR__ . '/', '', (string)$meta['fixture']))?></td>
                        <td>
                            <?php if (!empty($meta['source_matched'])): ?>
                                MATCHED
                            <?php elseif (!empty($meta['synthetic_added'])): ?>
                                SYNTHETIC
                            <?php else: ?>
                                MISSING
                            <?php endif; ?>
                        </td>
                        <td><?=array_key_exists('saved_net',$meta) && $meta['saved_net'] !== null ? rds_h((string)$meta['saved_net']) : '—'?></td>
                        <td><?=array_key_exists('simulated_net',$meta) && $meta['simulated_net'] !== null ? rds_h((string)$meta['simulated_net']) : '—'?></td>
                        <td><?=!empty($meta['override_applied'])?'YES':'—'?></td>
NEW;

        $prepared = rvp_replace_once(
            $prepared,
            $oldFixtureCells,
            $newFixtureCells,
            'fixture audit cells'
        );

        rvp_check(
            $checks,
            'Race-centric simulator transform prepared',
            true,
            'v003 -> v004; per-race NET + auditable matching'
        );
    } catch (Throwable $e) {
        $errors[] = 'Transform failed: ' . $e->getMessage();
        rvp_check($checks, 'Race-centric simulator transform prepared', false, $e->getMessage());
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['install']) &&
    empty($errors)
) {
    if ($isV004) {
        $installed = true;
    } else {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            $errors[] = 'Could not create backup directory: ' . $backupDir;
        }

        if (empty($errors) && !@copy($simPath, $backupDir . '/admin_rd_simulation.php')) {
            $errors[] = 'Could not back up admin_rd_simulation.php.';
        }

        if (empty($errors) && !rvp_atomic_write($simPath, $prepared)) {
            @copy($backupDir . '/admin_rd_simulation.php', $simPath);
            $errors[] = 'Write failed; rollback attempted.';
        }

        if (empty($errors)) {
            $after = (string)@file_get_contents($simPath);

            $postflight = [
                ['Simulator v004 installed', strpos($after, 'VERSION: v004') !== false],
                ['Per-race NET inputs installed', strpos($after, 'name="race_net[') !== false],
                ['Old override checkboxes removed', strpos($after, 'name="override_races[]"') === false],
                ['Old shared NET field removed', strpos($after, 'name="override_net"') === false],
                ['Normalized driver matching installed', strpos($after, 'function rds_find_driver_row_key') !== false],
                ['Synthetic fixture-row support installed', strpos($after, 'synthetic_added') !== false],
                ['Selected-driver audit columns installed', strpos($after, '<th>Saved NET</th>') !== false && strpos($after, '<th>Simulated NET</th>') !== false],
                ['No RD submission code added', strpos($after, 'INSERT INTO user_picks') === false],
            ];

            foreach ($postflight as $pf) {
                if (!$pf[1]) {
                    $errors[] = 'Postflight failed: ' . $pf[0];
                }
            }

            if (empty($errors)) {
                $installed = true;
            } else {
                @copy($backupDir . '/admin_rd_simulation.php', $simPath);
                $errors[] = 'Postflight failure triggered rollback.';
            }
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL RD Simulation Race Values Patch</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1200px;margin:0 auto;padding:15px}
.banner{background:#3d3212;border:1px solid #9b7200;border-radius:11px;padding:12px 15px}
.banner h1{margin:0;color:#fff0c8;font-size:22px}
.sub{font-size:12px;color:#dac884;margin-top:4px}
.card{background:#1d1d1d;border:1px solid #444;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#ffd08a;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333;vertical-align:top}
.ok{color:#5cf09a;font-weight:700}
.bad{color:#ff7d7d;font-weight:700}
code{color:#f2d78b}
button{background:#443419;color:#ffd08a;border:1px solid #b48636;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer}
.success{background:#143b2b;border-color:#2c7754}
.note{font-size:12px;color:#bbb;line-height:1.45}
a{color:#8fc7ff}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
    <h1>MRL RD Simulation Race Values Patch v001</h1>
    <div class="sub">TESTPHP8 ONLY • simulator v003 → v004 • DB changes: NONE</div>
</div>

<div class="card">
    <h2>What This Fixes</h2>
    <div>
        The simulator becomes race-centric: pick a driver, then optionally type a NET
        value directly under each race. Blank means use the saved snapshot value.
    </div>
    <div class="note" style="margin-top:7px">
        It also records whether the selected driver matched a real source row, what the
        saved NET was, what the fixture re-parsed as, and whether the override was applied.
        If the selected driver is absent, a synthetic row is added only to the isolated fixture.
    </div>
</div>

<div class="card">
    <h2>Preflight</h2>
    <table>
        <?php foreach ($checks as $c): ?>
        <tr>
            <td style="width:43%"><?=rvp_h($c['name'])?></td>
            <td style="width:8%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
            <td><?=rvp_h($c['detail'])?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
    <h2 class="bad">STOPPED SAFELY</h2>
    <?php foreach ($errors as $e): ?><div class="bad">• <?=rvp_h($e)?></div><?php endforeach; ?>
</div>
<?php elseif (!$installed): ?>
<div class="card">
    <h2>Ready to Install</h2>
    <div class="note" style="margin-bottom:9px">
        Changes only <code>race_results/admin_rd_simulation.php</code>.
        No DB, helper, normal snapshot, team.php, submission, or Live changes.
    </div>
    <form method="post">
        <button type="submit" name="install" value="1">INSTALL RACE-CENTRIC SIMULATOR PATCH</button>
    </form>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card success">
    <h2 class="ok">INSTALL COMPLETE</h2>

    <?php if (is_dir($backupDir)): ?>
        <div><strong>Backup folder:</strong><br><code><?=rvp_h($backupDir)?></code></div>
    <?php endif; ?>

    <table style="margin-top:8px">
        <?php foreach ($postflight as $pf): ?>
        <tr>
            <td><?=rvp_h($pf[0])?></td>
            <td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div style="margin-top:10px">
        <a href="/race_results/admin_rd_simulation.php?year=2026&segment=S1&through=6">Open R06 Simulation</a>
    </div>

    <div class="note" style="margin-top:8px">
        Retry: choose Alex Bowman and enter <strong>15 only under R06</strong>.
        Leave every other race blank. After Run Simulation, inspect both the
        eligibility row and the Fixture audit row for R06.
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
