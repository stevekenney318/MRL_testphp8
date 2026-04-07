<?php
declare(strict_types=1);

/**
 * team_replacement_driver.php
 *
 * VERSION: v008
 * LAST MODIFIED: 4/7/2026 7:54:20 am
 *
 * DESCRIPTION:
 * Special wrapper for RD team form display.
 * team.php decides when this wrapper is used. This file mirrors the
 * LP wrapper style while presenting a Replacement Driver-only form.
 *
 * CHANGELOG:
 *
 * v002 (4/6/2026)
 * - FIX: Updated RD form styling and table structure to more closely mirror form-team-picks.php.
 * - FIX: Corrected RD labels to use Group terminology.
 * - FIX: RD dropdown now expects current-year filtered options from team.php, matching the normal form logic.
 * - CHANGE: Preserved readonly display for unaffected groups while keeping only the eligible group editable.
 *
 * v001 (4/6/2026)
 * - Initial RD wrapper using the LP layout style as the baseline.
 * - Displays league-rule RD eligibility banner.
 * - Shows only the eligible group as a dropdown.
 * - Keeps the other groups visible but not editable.
 * - Excludes the current driver being replaced from the dropdown.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');

$rdWrapperVersion = 'v008';

if (!isset($rdBasePickRow) || !is_array($rdBasePickRow)) {
    echo "<div style='color:red; background:#fabf8f; text-align:center; font-weight:bold; padding:10px;'>RD base pick row is not available.</div>";
    return;
}

$rdDisplayYear = isset($raceYear) ? (string)$raceYear : '';
$rdDisplaySegment = isset($rdPendingSegmentLabel) && trim((string)$rdPendingSegmentLabel) !== ''
    ? trim((string)$rdPendingSegmentLabel)
    : trim((string)($rdPendingSegment ?? ''));
$rdDisplayGroup = isset($rdPendingGroup) ? strtoupper(trim((string)$rdPendingGroup)) : '';
$rdDisplayCurrentDriver = isset($rdPendingCurrentDriver) ? trim((string)$rdPendingCurrentDriver) : '';
$rdDisplayTriggerRaces = isset($rdPendingTriggerRaces) ? trim((string)$rdPendingTriggerRaces) : '';
$rdDisplayEligibleRace = isset($rdPendingEffectiveRace) ? trim((string)$rdPendingEffectiveRace) : '';
$rdDisplayDeadlineRace = isset($rdDeadlineRaceCode) ? trim((string)$rdDeadlineRaceCode) : '';
$rdDisplayDeadlineTime = isset($rdDeadlineDisplay) ? trim((string)$rdDeadlineDisplay) : '';
$rdDisplayEffectiveRace = ($rdDisplayDeadlineRace !== '') ? $rdDisplayDeadlineRace : $rdDisplayEligibleRace;

$rdDisplayPickRow = (isset($rdActivePickRow) && is_array($rdActivePickRow)) ? $rdActivePickRow : $rdBasePickRow;

$rdDriverA = trim((string)($rdDisplayPickRow['driverA'] ?? ''));
$rdDriverB = trim((string)($rdDisplayPickRow['driverB'] ?? ''));
$rdDriverC = trim((string)($rdDisplayPickRow['driverC'] ?? ''));
$rdDriverD = trim((string)($rdDisplayPickRow['driverD'] ?? ''));
$rdSelectedDriver = isset($rdSelectedDriver) ? trim((string)$rdSelectedDriver) : '';

function rd_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function rd_cell_style(string $group): string
{
    if ($group === 'A') return '#d9d9d9';
    if ($group === 'B') return '#c4bd97';
    if ($group === 'C') return '#b8cce4';
    if ($group === 'D') return '#d8e4bc';
    return '#ffffff';
}

function rd_render_readonly_input(string $value, string $group): string
{
    return "<div style='width:98%; box-sizing:border-box; min-height:38px; "
        . "display:flex; align-items:center; padding:0 8px; "
        . "font-size:18px; color:black; "
        . "background-color:" . rd_cell_style($group) . "; "
        . "border-radius:4px; opacity:0.65; margin:0 auto;'>"
        . rd_h($value)
        . "</div>";
}

$clockId = 'rdClockCell';
?>

<br>
<div style="color: red; font-size: 20px; background-color: #fabf8f; text-align: center; font-weight: bold; padding: 8px 10px; font-family: Century Gothic, sans-serif;">
    You are currently eligible, but not required to make a pick for a replacement driver under league rule 3
    <br>(2 successive 0 points scored by a driver)
</div>

<form action="/submit-team-picks.php" method="post">
    <input type="hidden" name="submission_id" value="">
    <input type="hidden" name="form_id" value="team_replacement_driver.php">
    <input type="hidden" name="form_version" value="<?php echo rd_h($rdWrapperVersion); ?>">
    <input type="hidden" name="pick_type_override" value="RD">
    <input type="hidden" name="rd_segment" value="<?php echo rd_h((string)$rdPendingSegment); ?>">
    <input type="hidden" name="rd_effective_race" value="<?php echo rd_h($rdDisplayEffectiveRace); ?>">
    <input type="hidden" name="rd_supersedes_pick_id" value="<?php echo rd_h((string)($rdBasePickRow['pickID'] ?? '')); ?>">

    <table align="center" style="width:100%;">
        <tr style="background-color:#fabf8f;">
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif;">
                Replacement Driver available for Group <?php echo rd_h($rdDisplayGroup); ?>. Only that group can be changed. Current driver being replaced: <?php echo rd_h($rdDisplayCurrentDriver); ?>
            </th>
        </tr>
        <tr style="background-color:#b7dee8;">
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif;">
                Trigger races: <?php echo rd_h($rdDisplayTriggerRaces); ?> &nbsp; | &nbsp; Eligible race: <?php echo rd_h($rdDisplayEligibleRace); ?> &nbsp; | &nbsp; Effective race: <?php echo rd_h($rdDisplayEffectiveRace); ?><?php if ($rdDisplayDeadlineRace !== '' || $rdDisplayDeadlineTime !== ''): ?> &nbsp; | &nbsp; Deadline: start of <?php echo rd_h($rdDisplayDeadlineRace); ?><?php if ($rdDisplayDeadlineTime !== ''): ?> (<?php echo rd_h($rdDisplayDeadlineTime); ?>)<?php endif; ?><?php endif; ?>
            </th>
        </tr>
    </table>

    <table align="center" style="width:100%;">
        <tr style="background-color:#fabf8f;">
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:14%;">
                <?php echo rd_h($rdDisplayYear); ?>
            </th>
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:18%;">A Driver</th>
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:18%;">B Driver</th>
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:18%;">C Driver</th>
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:18%;">D Driver</th>
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:14%;" id="<?php echo $clockId; ?>"></th>
        </tr>

        <tr style="background-color:#b7dee8;">
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; vertical-align:middle;">
                <?php echo rd_h($rdDisplaySegment); ?>
            </th>

            <td style="background-color:<?php echo rd_h(rd_cell_style('A')); ?>; width:18%; padding:2px; vertical-align:middle;">
                <?php
                if ($rdDisplayGroup === 'A') {
                    echo "<select name='group-a-driver' style='width:100%; height:auto; border:1px solid black; font-size:18px; color:black; background-color:" . rd_h(rd_cell_style('A')) . "; border-radius:4px;'>";
                    echo "<option value=''>Select Replacement Driver</option>";
                    if ($rdSelectedDriver !== '') {
                        echo "<option value='" . rd_h($rdSelectedDriver) . "' selected>" . rd_h($rdSelectedDriver) . "</option>";
                    }
                    foreach ($rdReplacementOptions as $driverRow) {
                        $driverName = trim((string)($driverRow['driverName'] ?? ''));
                        $driverTag = trim((string)($driverRow['tag'] ?? ''));
                        $displayText = trim($driverName . ' ' . $driverTag);

                        if ($rdSelectedDriver !== '' && $driverName === $rdSelectedDriver) {
                            continue;
                        }

                        echo "<option value='" . rd_h($driverName) . "'>" . rd_h($displayText) . "</option>";
                    }
                    echo "</select>";
                } else {
                    echo "<input type='hidden' name='group-a-driver' value='" . rd_h($rdDriverA) . "'>";
                    echo rd_render_readonly_input($rdDriverA, 'A');
                }
                ?>
            </td>

            <td style="background-color:<?php echo rd_h(rd_cell_style('B')); ?>; width:18%; padding:2px; vertical-align:middle;">
                <?php
                if ($rdDisplayGroup === 'B') {
                    echo "<select name='group-b-driver' style='width:100%; height:auto; border:1px solid black; font-size:18px; color:black; background-color:" . rd_h(rd_cell_style('B')) . "; border-radius:4px;'>";
                    echo "<option value=''>Select Replacement Driver</option>";
                    if ($rdSelectedDriver !== '') {
                        echo "<option value='" . rd_h($rdSelectedDriver) . "' selected>" . rd_h($rdSelectedDriver) . "</option>";
                    }
                    foreach ($rdReplacementOptions as $driverRow) {
                        $driverName = trim((string)($driverRow['driverName'] ?? ''));
                        $driverTag = trim((string)($driverRow['tag'] ?? ''));
                        $displayText = trim($driverName . ' ' . $driverTag);

                        if ($rdSelectedDriver !== '' && $driverName === $rdSelectedDriver) {
                            continue;
                        }

                        echo "<option value='" . rd_h($driverName) . "'>" . rd_h($displayText) . "</option>";
                    }
                    echo "</select>";
                } else {
                    echo "<input type='hidden' name='group-b-driver' value='" . rd_h($rdDriverB) . "'>";
                    echo rd_render_readonly_input($rdDriverB, 'B');
                }
                ?>
            </td>

            <td style="background-color:<?php echo rd_h(rd_cell_style('C')); ?>; width:18%; padding:2px; vertical-align:middle;">
                <?php
                if ($rdDisplayGroup === 'C') {
                    echo "<select name='group-c-driver' style='width:100%; height:auto; border:1px solid black; font-size:18px; color:black; background-color:" . rd_h(rd_cell_style('C')) . "; border-radius:4px;'>";
                    echo "<option value=''>Select Replacement Driver</option>";
                    if ($rdSelectedDriver !== '') {
                        echo "<option value='" . rd_h($rdSelectedDriver) . "' selected>" . rd_h($rdSelectedDriver) . "</option>";
                    }
                    foreach ($rdReplacementOptions as $driverRow) {
                        $driverName = trim((string)($driverRow['driverName'] ?? ''));
                        $driverTag = trim((string)($driverRow['tag'] ?? ''));
                        $displayText = trim($driverName . ' ' . $driverTag);

                        if ($rdSelectedDriver !== '' && $driverName === $rdSelectedDriver) {
                            continue;
                        }

                        echo "<option value='" . rd_h($driverName) . "'>" . rd_h($displayText) . "</option>";
                    }
                    echo "</select>";
                } else {
                    echo "<input type='hidden' name='group-c-driver' value='" . rd_h($rdDriverC) . "'>";
                    echo rd_render_readonly_input($rdDriverC, 'C');
                }
                ?>
            </td>

            <td style="background-color:<?php echo rd_h(rd_cell_style('D')); ?>; width:18%; padding:2px; vertical-align:middle;">
                <?php
                if ($rdDisplayGroup === 'D') {
                    echo "<select name='group-d-driver' style='width:100%; height:auto; border:1px solid black; font-size:18px; color:black; background-color:" . rd_h(rd_cell_style('D')) . "; border-radius:4px;'>";
                    echo "<option value=''>Select Replacement Driver</option>";
                    if ($rdSelectedDriver !== '') {
                        echo "<option value='" . rd_h($rdSelectedDriver) . "' selected>" . rd_h($rdSelectedDriver) . "</option>";
                    }
                    foreach ($rdReplacementOptions as $driverRow) {
                        $driverName = trim((string)($driverRow['driverName'] ?? ''));
                        $driverTag = trim((string)($driverRow['tag'] ?? ''));
                        $displayText = trim($driverName . ' ' . $driverTag);

                        if ($rdSelectedDriver !== '' && $driverName === $rdSelectedDriver) {
                            continue;
                        }

                        echo "<option value='" . rd_h($driverName) . "'>" . rd_h($displayText) . "</option>";
                    }
                    echo "</select>";
                } else {
                    echo "<input type='hidden' name='group-d-driver' value='" . rd_h($rdDriverD) . "'>";
                    echo rd_render_readonly_input($rdDriverD, 'D');
                }
                ?>
            </td>

            <td style="text-align:center; background-color:#b7dee8; width:14%; vertical-align:middle;">
                <input type="reset" value="Reset">
                <input type="submit" value="Submit Picks">
            </td>
        </tr>
    </table>

    <div style="font-size:10px; color:#999; text-align:right; margin:0; padding:0;">
        team_replacement_driver.php <?php echo rd_h($rdWrapperVersion); ?>
    </div>
</form>

<div style="font:11px/1.2 monospace; color:#999; text-align:right; margin:0; padding:8px 0 0 0;">
    TRIGGER RACES: <?php echo rd_h($rdDisplayTriggerRaces); ?> | ELIGIBLE RACE: <?php echo rd_h($rdDisplayEligibleRace); ?> | EFFECTIVE RACE: <?php echo rd_h($rdDisplayEffectiveRace); ?><?php if ($rdDisplayDeadlineRace !== '' || $rdDisplayDeadlineTime !== ''): ?> | DEADLINE: start of <?php echo rd_h($rdDisplayDeadlineRace); ?><?php if ($rdDisplayDeadlineTime !== ''): ?> (<?php echo rd_h($rdDisplayDeadlineTime); ?>)<?php endif; ?><?php endif; ?>
</div>

<div style="font:11px/1.2 monospace; color:#999; text-align:right; margin:0; padding:8px 0 0 0;">
    FILE: <?php echo rd_h(basename(__FILE__)); ?> | VERSION: <?php echo rd_h($rdWrapperVersion); ?>
</div>

<script>
function updateRdClock() {
    var now = new Date();
    var year = now.getFullYear();
    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    var hours = String(now.getHours()).padStart(2, '0');
    var minutes = String(now.getMinutes()).padStart(2, '0');
    var seconds = String(now.getSeconds()).padStart(2, '0');

    document.getElementById('<?php echo $clockId; ?>').innerText =
        year + '-' + month + '-' + day + ' ' + hours + ':' + minutes + ':' + seconds;
}

setInterval(updateRdClock, 1000);
updateRdClock();
</script>
