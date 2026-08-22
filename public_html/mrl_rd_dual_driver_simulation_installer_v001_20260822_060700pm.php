<?php
declare(strict_types=1);

/**
 * MRL RD Dual-Driver Simulation Installer
 *
 * VERSION: v001
 * GENERATED: 8/22/2026 6:07:00 pm America/New_York
 *
 * TARGET:
 *   TESTPHP8 ONLY
 *   /public_html/race_results/admin_rd_simulation.php
 *
 * PURPOSE:
 *   Upgrade the existing RD simulator from one simulated driver at a time
 *   to two independent simulated drivers, so we can prove the
 *   MULTIPLE_RD_AVAILABLE / user-choice case.
 *
 * SCOPE:
 *   - admin_rd_simulation.php v005 -> v006
 *   - race_results_rd_helper.php is READ/CHECK ONLY and is NOT modified
 *   - NO DB changes
 *   - NO user_picks/user_picks_history writes
 *   - NO normal snapshot changes
 *   - NO RD submission
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

$simFile = $root . '/race_results/admin_rd_simulation.php';
$helperFile = $root . '/race_results/race_results_rd_helper.php';

$backupDir = $root . '/mrl_rd_dual_driver_sim_backup_20260822_060700pm';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;
$prepared = '';

function ddi_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ddi_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

function ddi_replace_once(string $src, string $old, string $new, string $label): string
{
    $count = substr_count($src, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 source marker, found ' . $count . '.');
    }
    return str_replace($old, $new, $src);
}

function ddi_regex_replace_once(string $src, string $pattern, string $replacement, string $label): string
{
    $result = preg_replace($pattern, $replacement, $src, -1, $count);
    if ($result === null || $count !== 1) {
        throw new RuntimeException($label . ': expected exactly 1 regex replacement, got ' . (int)$count . '.');
    }
    return $result;
}

function ddi_atomic_write(string $path, string $content): bool
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

// -----------------------------------------------------------------------------
// PREFLIGHT
// -----------------------------------------------------------------------------

ddi_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host);
ddi_check($checks, 'PHP 7.3 compatible runtime', PHP_VERSION_ID >= 70300, PHP_VERSION);
ddi_check($checks, 'Simulator file exists', is_file($simFile), $simFile);
ddi_check($checks, 'RD helper file exists', is_file($helperFile), $helperFile);

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: This installer is TESTPHP8-only.';
}
if (PHP_VERSION_ID < 70300) {
    $errors[] = 'PHP 7.3 or newer is required.';
}
if (!is_file($simFile)) {
    $errors[] = 'admin_rd_simulation.php not found.';
}
if (!is_file($helperFile)) {
    $errors[] = 'race_results_rd_helper.php not found.';
}

$current = is_file($simFile) ? (string)@file_get_contents($simFile) : '';
$helper = is_file($helperFile) ? (string)@file_get_contents($helperFile) : '';

$simV005 = strpos($current, 'VERSION: v005') !== false;
$simTom = strpos($current, 'Select a driver for the simulated race values.') !== false;
$simSingleDriver = strpos($current, 'Allows ONE driver to be overridden') !== false;
$simSingleInput = strpos($current, 'name="override_driver"') !== false;
$simSingleRace = strpos($current, 'name="race_net[') !== false;

ddi_check($checks, 'Simulator is expected v005 baseline', $simV005, $simV005 ? 'v005 marker found' : 'v005 marker missing');
ddi_check($checks, 'Existing Tom-test guard found', $simTom, $simTom ? 'guard found' : 'guard missing');
ddi_check($checks, 'Existing single-driver design found', $simSingleDriver, $simSingleDriver ? 'baseline matches' : 'baseline differs');
ddi_check($checks, 'Existing single driver selector found', $simSingleInput, $simSingleInput ? 'override_driver found' : 'missing');
ddi_check($checks, 'Existing single race NET input found', $simSingleRace, $simSingleRace ? 'race_net[] found' : 'missing');

$helperV005 = strpos($helper, 'VERSION: v005') !== false;
$helperMultiple = strpos($helper, 'MULTIPLE_RD_AVAILABLE') !== false;
$helperChoice = strpos($helper, 'user_selection_required') !== false;

ddi_check($checks, 'Shared RD helper is v005', $helperV005, $helperV005 ? 'v005 marker found' : 'v005 marker missing');
ddi_check($checks, 'Helper supports MULTIPLE_RD_AVAILABLE', $helperMultiple, $helperMultiple ? 'supported' : 'missing');
ddi_check($checks, 'Helper exposes user_selection_required', $helperChoice, $helperChoice ? 'supported' : 'missing');

if (!$simV005 || !$simTom || !$simSingleDriver || !$simSingleInput || !$simSingleRace) {
    $errors[] = 'REFUSED: Simulator source does not match the expected v005 baseline.';
}
if (!$helperV005 || !$helperMultiple || !$helperChoice) {
    $errors[] = 'REFUSED: Shared RD helper does not match the expected multi-qualifier v005 capability.';
}

// -----------------------------------------------------------------------------
// PREPARE TRANSFORM
// -----------------------------------------------------------------------------

if (empty($errors)) {
    try {
        $prepared = $current;

        $prepared = ddi_replace_once(
            $prepared,
            'VERSION: v005',
            'VERSION: v006',
            'Version marker'
        );

        $prepared = ddi_replace_once(
            $prepared,
            'LAST MODIFIED: 8/21/2026 12:01:00 am',
            'LAST MODIFIED: 8/22/2026 6:07:00 pm',
            'Last-modified marker'
        );

        $prepared = ddi_replace_once(
            $prepared,
            '- Allows ONE driver to be overridden across selected completed races.',
            '- Allows TWO independent drivers to be overridden across selected completed races.',
            'Purpose wording'
        );

        $changelogMarker = " * CHANGELOG:\n";
        $changelogNew =
            " * CHANGELOG:\n"
            . " * v006 (8/22/2026 6:07:00 pm)\n"
            . " * - NEW: Dual-driver simulation. Driver 1 and Driver 2 each have their own\n"
            . " *   independent per-race NET override inputs.\n"
            . " * - NEW: Simulator can manufacture and prove MULTIPLE_RD_AVAILABLE for one\n"
            . " *   team while preserving the shared RD v005 eligibility engine unchanged.\n"
            . " * - NEW: Guard rejects duplicate Driver 1 / Driver 2 selection.\n"
            . " * - NEW: Fixture audit shows both simulated drivers independently.\n"
            . " * - PRESERVE: TestPHP8-only, DB read-only, isolated fixtures, Tom-test safety,\n"
            . " *   normal snapshots untouched, and no RD submission.\n"
            . " *\n";

        $prepared = ddi_replace_once(
            $prepared,
            $changelogMarker,
            $changelogNew,
            'Changelog insertion'
        );

        $dualFunction = <<<'PHP'

/**
 * Build an isolated fixture set with up to TWO independent driver override maps.
 * The shared RD helper receives only the reparsed race/driver NET map.
 */
function rds_make_dual_fixture_set(
    string $fixtureDir,
    array $sourcePoints,
    string $overrideDriver1,
    array $raceNetOverrides1,
    string $overrideDriver2,
    array $raceNetOverrides2
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

        $driverMeta = [
            1 => [
                'driver' => $overrideDriver1,
                'source_matched' => false,
                'synthetic_added' => false,
                'saved_net' => null,
                'simulated_net' => null,
                'override_applied' => false,
            ],
            2 => [
                'driver' => $overrideDriver2,
                'source_matched' => false,
                'synthetic_added' => false,
                'saved_net' => null,
                'simulated_net' => null,
                'override_applied' => false,
            ],
        ];

        $drivers = [
            1 => [$overrideDriver1, $raceNetOverrides1],
            2 => [$overrideDriver2, $raceNetOverrides2],
        ];

        foreach ($drivers as $driverIndex => $driverConfig) {
            $driverName = (string)$driverConfig[0];
            $overrideMap = is_array($driverConfig[1]) ? $driverConfig[1] : [];

            if ($driverName === '') {
                continue;
            }

            $matchedKey = rds_find_driver_row_key($driverRows, $driverName);

            if ($matchedKey !== '' && isset($driverRows[$matchedKey]) && is_array($driverRows[$matchedKey])) {
                $driverMeta[$driverIndex]['source_matched'] = true;
                $driverMeta[$driverIndex]['saved_net'] = (int)($driverRows[$matchedKey]['net'] ?? 0);
            }

            if (array_key_exists($rn, $overrideMap)) {
                $overrideNet = (int)$overrideMap[$rn];

                if ($matchedKey === '') {
                    $matchedKey = $driverName;
                    $driverRows[$matchedKey] = [
                        'pts' => $overrideNet,
                        'bonus' => 0,
                        'penalty' => 0,
                        'net' => $overrideNet,
                    ];
                    $driverMeta[$driverIndex]['synthetic_added'] = true;
                } else {
                    $driverRows[$matchedKey]['pts'] = $overrideNet;
                    $driverRows[$matchedKey]['bonus'] = 0;
                    $driverRows[$matchedKey]['penalty'] = 0;
                    $driverRows[$matchedKey]['net'] = $overrideNet;
                }

                $driverMeta[$driverIndex]['override_applied'] = true;
                $driverMeta[$driverIndex]['simulated_net'] = $overrideNet;
            } elseif ($driverMeta[$driverIndex]['source_matched']) {
                $driverMeta[$driverIndex]['simulated_net'] = $driverMeta[$driverIndex]['saved_net'];
            }
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
            throw new RuntimeException(
                'Fixture re-parse failed for R'
                . str_pad((string)$rn, 2, '0', STR_PAD_LEFT)
                . '.'
            );
        }

        $points[$rn] = [];
        foreach ($reparsed as $driverName => $data) {
            $points[$rn][$driverName] = (int)($data['net'] ?? 0);
        }

        foreach ([1, 2] as $driverIndex) {
            $driverName = (string)$driverMeta[$driverIndex]['driver'];
            if ($driverName === '') {
                continue;
            }

            $reparsedKey = rds_find_driver_row_key($reparsed, $driverName);
            if ($reparsedKey !== '' && isset($reparsed[$reparsedKey])) {
                $driverMeta[$driverIndex]['simulated_net'] =
                    (int)($reparsed[$reparsedKey]['net'] ?? 0);
            }
        }

        $meta[$rn] = [
            'source' => (string)($raceData['snapshot'] ?? ''),
            'fixture' => $fixturePath,
            'drivers' => $driverMeta,
        ];
    }

    ksort($points, SORT_NUMERIC);

    return [
        'points' => $points,
        'meta' => $meta,
    ];
}

function rds_parse_race_net_input(array $posted, string &$error, string $label): array
{
    $out = [];

    foreach ($posted as $rn => $rawValue) {
        $raceNumber = (int)$rn;
        $value = trim((string)$rawValue);

        if ($raceNumber <= 0 || $value === '') {
            continue;
        }

        if (!preg_match('/^-?\d+$/', $value)) {
            $error = $label . ' NET values must be whole numbers or left blank.';
            return [];
        }

        $out[$raceNumber] = (int)$value;
    }

    return $out;
}

PHP;

        $prepared = ddi_replace_once(
            $prepared,
            "function rds_status_class(string \$status): string\n{",
            $dualFunction . "\nfunction rds_status_class(string \$status): string\n{",
            'Dual fixture functions'
        );

        $oldInputBlock = <<<'PHP'
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
PHP;

        $newInputBlock = <<<'PHP'
$overrideDriver1 = trim((string)($_POST['override_driver_1'] ?? ''));
$overrideDriver2 = trim((string)($_POST['override_driver_2'] ?? ''));

$postedRaceNet1 = isset($_POST['race_net_1']) && is_array($_POST['race_net_1'])
    ? $_POST['race_net_1']
    : [];

$postedRaceNet2 = isset($_POST['race_net_2']) && is_array($_POST['race_net_2'])
    ? $_POST['race_net_2']
    : [];

$raceNetOverrides1 = rds_parse_race_net_input($postedRaceNet1, $error, 'Driver 1');
$raceNetOverrides2 = rds_parse_race_net_input($postedRaceNet2, $error, 'Driver 2');
PHP;

        $prepared = ddi_replace_once(
            $prepared,
            $oldInputBlock,
            $newInputBlock,
            'POST input block'
        );

        $oldRunBlock = <<<'PHP'
            /*
             * "Tom test" UI safety:
             * Do not silently ignore entered simulation values just because
             * no driver was selected.
             */
            if (!empty($raceNetOverrides) && $overrideDriver === '') {
                throw new RuntimeException('Select a driver for the simulated race values.');
            }

            if ($overrideDriver !== '' && !in_array($overrideDriver, $driverNames, true)) {
                throw new RuntimeException('Override driver is not an active driver for the selected team set.');
            }

            foreach (array_keys($raceNetOverrides) as $rn) {
                if ($rn < (int)$bounds['start'] || $rn > $throughRace) {
                    throw new RuntimeException('A race NET override is outside the loaded completed-race range.');
                }
            }

            $fixtureDir = __DIR__
                . '/_rd_simulation/current/'
                . $selectedYear
                . '/'
                . $selectedSegment;

            $fixtureResult = rds_make_fixture_set(
                $fixtureDir,
                $sourcePoints,
                $overrideDriver,
                $raceNetOverrides
            );

            $message = 'Simulation fixtures generated and re-parsed. No DB writes; normal snapshots untouched.';
PHP;

        $newRunBlock = <<<'PHP'
            /*
             * "Tom test" UI safety:
             * Do not silently ignore entered simulation values just because
             * the corresponding driver was not selected.
             */
            if (!empty($raceNetOverrides1) && $overrideDriver1 === '') {
                throw new RuntimeException('Select Driver 1 for the Driver 1 simulated race values.');
            }

            if (!empty($raceNetOverrides2) && $overrideDriver2 === '') {
                throw new RuntimeException('Select Driver 2 for the Driver 2 simulated race values.');
            }

            if ($overrideDriver1 !== '' && !in_array($overrideDriver1, $driverNames, true)) {
                throw new RuntimeException('Driver 1 is not an active driver for the selected team set.');
            }

            if ($overrideDriver2 !== '' && !in_array($overrideDriver2, $driverNames, true)) {
                throw new RuntimeException('Driver 2 is not an active driver for the selected team set.');
            }

            if (
                $overrideDriver1 !== ''
                && $overrideDriver2 !== ''
                && rds_driver_key($overrideDriver1) === rds_driver_key($overrideDriver2)
            ) {
                throw new RuntimeException('Driver 1 and Driver 2 must be different drivers.');
            }

            $allOverrideRaces = array_values(array_unique(array_merge(
                array_keys($raceNetOverrides1),
                array_keys($raceNetOverrides2)
            )));

            foreach ($allOverrideRaces as $rn) {
                if ($rn < (int)$bounds['start'] || $rn > $throughRace) {
                    throw new RuntimeException('A race NET override is outside the loaded completed-race range.');
                }
            }

            $fixtureDir = __DIR__
                . '/_rd_simulation/current/'
                . $selectedYear
                . '/'
                . $selectedSegment;

            $fixtureResult = rds_make_dual_fixture_set(
                $fixtureDir,
                $sourcePoints,
                $overrideDriver1,
                $raceNetOverrides1,
                $overrideDriver2,
                $raceNetOverrides2
            );

            $message = 'Dual-driver simulation fixtures generated and re-parsed. No DB writes; normal snapshots untouched.';
PHP;

        $prepared = ddi_replace_once(
            $prepared,
            $oldRunBlock,
            $newRunBlock,
            'Run-validation block'
        );

        // Replace only the simulator override controls area.
        $formPattern = '~            <div class="grid">\s*'
            . '<div>\s*'
            . '<label>Optional driver override</label>.*?'
            . '            <div style="display:flex;gap:8px;margin-top:10px">~s';

        $newForm = <<<'HTML'
            <div class="grid">
                <div>
                    <label>Driver 1 override</label>
                    <select name="override_driver_1">
                        <option value="">No Driver 1 override</option>
                        <?php foreach ($driverNames as $driver): ?>
                            <option value="<?=rds_h($driver)?>" <?=$overrideDriver1===$driver?'selected':''?>><?=rds_h($driver)?></option>
                        <?php endforeach; ?>
                    </select>

                    <label style="margin-top:9px">Driver 1 simulated NET by completed race</label>
                    <div class="races">
                        <?php foreach ($sourcePoints as $rn => $row): $rn=(int)$rn; ?>
                            <div class="racecheck" style="display:block;min-width:105px">
                                <div style="font-weight:700;margin-bottom:4px">
                                    R<?=str_pad((string)$rn,2,'0',STR_PAD_LEFT)?>
                                </div>
                                <input
                                    name="race_net_1[<?=$rn?>]"
                                    value="<?=isset($raceNetOverrides1[$rn])?rds_h((string)$raceNetOverrides1[$rn]):''?>"
                                    placeholder="saved"
                                    style="width:88px"
                                >
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label>Driver 2 override</label>
                    <select name="override_driver_2">
                        <option value="">No Driver 2 override</option>
                        <?php foreach ($driverNames as $driver): ?>
                            <option value="<?=rds_h($driver)?>" <?=$overrideDriver2===$driver?'selected':''?>><?=rds_h($driver)?></option>
                        <?php endforeach; ?>
                    </select>

                    <label style="margin-top:9px">Driver 2 simulated NET by completed race</label>
                    <div class="races">
                        <?php foreach ($sourcePoints as $rn => $row): $rn=(int)$rn; ?>
                            <div class="racecheck" style="display:block;min-width:105px">
                                <div style="font-weight:700;margin-bottom:4px">
                                    R<?=str_pad((string)$rn,2,'0',STR_PAD_LEFT)?>
                                </div>
                                <input
                                    name="race_net_2[<?=$rn?>]"
                                    value="<?=isset($raceNetOverrides2[$rn])?rds_h((string)$raceNetOverrides2[$rn]):''?>"
                                    placeholder="saved"
                                    style="width:88px"
                                >
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="small" style="margin-top:7px">
                Blank = use saved snapshot NET. For the dual test, choose two different active drivers
                and set the same trailing pair (for example R06/R07) to 0 for both.
                Each driver's values are independent.
            </div>

            <div style="display:flex;gap:8px;margin-top:10px">
HTML;

        $prepared = ddi_regex_replace_once(
            $prepared,
            $formPattern,
            $newForm,
            'Dual input controls'
        );

        $oldAudit = <<<'HTML'
                    <th>Source Row</th>
                    <th>Saved NET</th>
                    <th>Simulated NET</th>
                    <th>Override</th>
HTML;

        $newAudit = <<<'HTML'
                    <th>Driver 1</th>
                    <th>D1 Saved</th>
                    <th>D1 Sim</th>
                    <th>D1 Override</th>
                    <th>Driver 2</th>
                    <th>D2 Saved</th>
                    <th>D2 Sim</th>
                    <th>D2 Override</th>
HTML;

        $prepared = ddi_replace_once(
            $prepared,
            $oldAudit,
            $newAudit,
            'Audit headers'
        );

        $oldAuditCells = <<<'HTML'
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
HTML;

        $newAuditCells = <<<'HTML'
                        <?php $d1 = isset($meta['drivers'][1]) && is_array($meta['drivers'][1]) ? $meta['drivers'][1] : []; ?>
                        <?php $d2 = isset($meta['drivers'][2]) && is_array($meta['drivers'][2]) ? $meta['drivers'][2] : []; ?>

                        <td>
                            <?=rds_h($d1['driver'] ?? '')?>
                            <?php if (!empty($d1['source_matched'])): ?>
                                <span class="small">MATCHED</span>
                            <?php elseif (!empty($d1['synthetic_added'])): ?>
                                <span class="small">SYNTHETIC</span>
                            <?php elseif (!empty($d1['driver'])): ?>
                                <span class="small">MISSING</span>
                            <?php endif; ?>
                        </td>
                        <td><?=array_key_exists('saved_net',$d1) && $d1['saved_net'] !== null ? rds_h((string)$d1['saved_net']) : '—'?></td>
                        <td><?=array_key_exists('simulated_net',$d1) && $d1['simulated_net'] !== null ? rds_h((string)$d1['simulated_net']) : '—'?></td>
                        <td><?=!empty($d1['override_applied'])?'YES':'—'?></td>

                        <td>
                            <?=rds_h($d2['driver'] ?? '')?>
                            <?php if (!empty($d2['source_matched'])): ?>
                                <span class="small">MATCHED</span>
                            <?php elseif (!empty($d2['synthetic_added'])): ?>
                                <span class="small">SYNTHETIC</span>
                            <?php elseif (!empty($d2['driver'])): ?>
                                <span class="small">MISSING</span>
                            <?php endif; ?>
                        </td>
                        <td><?=array_key_exists('saved_net',$d2) && $d2['saved_net'] !== null ? rds_h((string)$d2['saved_net']) : '—'?></td>
                        <td><?=array_key_exists('simulated_net',$d2) && $d2['simulated_net'] !== null ? rds_h((string)$d2['simulated_net']) : '—'?></td>
                        <td><?=!empty($d2['override_applied'])?'YES':'—'?></td>
HTML;

        $prepared = ddi_replace_once(
            $prepared,
            $oldAuditCells,
            $newAuditCells,
            'Audit cells'
        );

        $semanticChecks = [
            'v006 marker' => strpos($prepared, 'VERSION: v006') !== false,
            'dual fixture function' => strpos($prepared, 'function rds_make_dual_fixture_set') !== false,
            'Driver 1 selector' => strpos($prepared, 'name="override_driver_1"') !== false,
            'Driver 2 selector' => strpos($prepared, 'name="override_driver_2"') !== false,
            'Driver 1 race inputs' => strpos($prepared, 'name="race_net_1[') !== false,
            'Driver 2 race inputs' => strpos($prepared, 'name="race_net_2[') !== false,
            'duplicate-driver guard' => strpos($prepared, 'Driver 1 and Driver 2 must be different drivers.') !== false,
            'shared helper include preserved' => strpos($prepared, "require_once __DIR__ . '/race_results_rd_helper.php';") !== false,
            'no DB write SQL added' =>
                stripos($prepared, 'INSERT INTO user_picks') === false
                && stripos($prepared, 'UPDATE user_picks') === false
                && stripos($prepared, 'DELETE FROM user_picks') === false,
        ];

        $allPrepared = true;
        foreach ($semanticChecks as $label => $ok) {
            ddi_check($checks, 'Prepared: ' . $label, $ok, $ok ? 'PASS' : 'FAIL');
            if (!$ok) {
                $allPrepared = false;
            }
        }

        if (!$allPrepared) {
            $errors[] = 'Prepared transform failed one or more semantic checks.';
        }

    } catch (Throwable $e) {
        $errors[] = 'Transform preparation failed: ' . $e->getMessage();
        ddi_check($checks, 'Focused dual-driver transform prepared', false, $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// INSTALL
// -----------------------------------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['install'])
    && empty($errors)
) {
    if (
        !is_dir($backupDir)
        && !@mkdir($backupDir, 0775, true)
        && !is_dir($backupDir)
    ) {
        $errors[] = 'Could not create backup directory.';
    }

    if (
        empty($errors)
        && !@copy($simFile, $backupDir . '/admin_rd_simulation.php')
    ) {
        $errors[] = 'Could not back up current simulator.';
    }

    if (empty($errors) && !ddi_atomic_write($simFile, $prepared)) {
        @copy($backupDir . '/admin_rd_simulation.php', $simFile);
        $errors[] = 'Write failed; rollback attempted.';
    }

    if (empty($errors)) {
        $after = (string)@file_get_contents($simFile);

        $postflight = [
            ['Simulator reports v006', strpos($after, 'VERSION: v006') !== false],
            ['Dual fixture function installed', strpos($after, 'function rds_make_dual_fixture_set') !== false],
            ['Driver 1 selector installed', strpos($after, 'name="override_driver_1"') !== false],
            ['Driver 2 selector installed', strpos($after, 'name="override_driver_2"') !== false],
            ['Driver 1 NET controls installed', strpos($after, 'name="race_net_1[') !== false],
            ['Driver 2 NET controls installed', strpos($after, 'name="race_net_2[') !== false],
            ['Duplicate-driver guard installed', strpos($after, 'Driver 1 and Driver 2 must be different drivers.') !== false],
            ['Shared RD helper still referenced', strpos($after, "require_once __DIR__ . '/race_results_rd_helper.php';") !== false],
            ['No user_picks INSERT introduced', stripos($after, 'INSERT INTO user_picks') === false],
            ['No user_picks UPDATE introduced', stripos($after, 'UPDATE user_picks') === false],
            ['No user_picks DELETE introduced', stripos($after, 'DELETE FROM user_picks') === false],
        ];

        foreach ($postflight as $pf) {
            if (!$pf[1]) {
                $errors[] = 'Postflight failed: ' . $pf[0];
            }
        }

        if (!empty($errors)) {
            @copy($backupDir . '/admin_rd_simulation.php', $simFile);
            $errors[] = 'Postflight failure triggered rollback.';
        } else {
            $installed = true;
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL RD Dual-Driver Simulation Installer</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:14px}
.banner{background:#24351d;border:1px solid #547c3d;border-radius:10px;padding:12px 14px}
.banner h1{margin:0;color:#dfffcf;font-size:22px}
.sub{font-size:12px;color:#bdd4ae;margin-top:4px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#d5efc9;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333;vertical-align:top}
.ok{color:#69ef98;font-weight:700}
.bad{color:#ff7777;font-weight:700}
.note{font-size:12px;color:#bbb}
code{color:#f2d996}
button{background:#285c32;color:#eaffee;border:1px solid #4b9658;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer}
.success{background:#143725;border-color:#2f6c48}
ul{margin:6px 0 0 20px;padding:0}
a{color:#8fc9ff}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
    <h1>MRL RD Dual-Driver Simulation Installer v001</h1>
    <div class="sub">TESTPHP8 ONLY • generated 8/22/2026 6:07:00 pm • DB changes: NONE</div>
</div>

<div class="card">
    <h2>Review — What This Changes</h2>
    <ul>
        <li><code>admin_rd_simulation.php</code> v005 → v006 only.</li>
        <li>Adds <strong>Driver 1</strong> and <strong>Driver 2</strong> selectors.</li>
        <li>Each driver gets independent per-race simulated NET inputs.</li>
        <li>Both overrides are written only into isolated <code>_rd_simulation</code> fixture files.</li>
        <li>The existing shared RD v005 helper then evaluates the reparsed result.</li>
        <li>Expected dual result: <code>MULTIPLE_RD_AVAILABLE</code> with two qualifier names and one effective race.</li>
        <li>The shared RD helper is checked but <strong>not modified</strong>.</li>
    </ul>
    <div class="note" style="margin-top:8px">
        No DB writes. No real picks. No history changes. No normal snapshot changes.
        No RD/RP submission. Live MRL is refused.
    </div>
</div>

<div class="card">
    <h2>Preflight / Prepared Transform</h2>
    <table>
    <?php foreach ($checks as $c): ?>
        <tr>
            <td style="width:48%"><?=ddi_h($c['name'])?></td>
            <td style="width:8%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
            <td><?=ddi_h($c['detail'])?></td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
    <h2 class="bad">STOPPED SAFELY</h2>
    <?php foreach ($errors as $e): ?>
        <div class="bad">• <?=ddi_h($e)?></div>
    <?php endforeach; ?>
</div>
<?php elseif (!$installed): ?>
<div class="card">
    <h2>Ready to Install on TESTPHP8</h2>
    <form method="post">
        <button type="submit" name="install" value="1">INSTALL DUAL-DRIVER SIMULATOR v006</button>
    </form>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card success">
    <h2 class="ok">INSTALL COMPLETE</h2>

    <div><strong>Backup folder:</strong><br><code><?=ddi_h($backupDir)?></code></div>

    <table style="margin-top:9px">
    <?php foreach ($postflight as $pf): ?>
        <tr>
            <td><?=ddi_h($pf[0])?></td>
            <td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td>
        </tr>
    <?php endforeach; ?>
    </table>

    <div style="margin-top:10px">
        <a href="/race_results/admin_rd_simulation.php?year=2026&amp;segment=S1">Open RD Simulator v006</a>
    </div>

    <div class="note" style="margin-top:9px">
        First test after install: load 2026 / S1 through R07, choose two active drivers from the SAME team,
        set R06 = 0 and R07 = 0 for both, then run. We want that team to show
        MULTIPLE_RD_AVAILABLE and both qualifier names.
    </div>

    <div class="note" style="margin-top:7px">
        After verification, sync TestPHP8 server → local with WinSCP, then commit/push GitHub.
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
