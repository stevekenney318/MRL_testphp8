<?php
declare(strict_types=1);

/**
 * team_replacement_driver.php
 *
 * VERSION: v010
 * LAST MODIFIED: 8/22/2026 8:35:00 pm
 *
 * DESCRIPTION:
 * Replacement Pick wrapper for the real MRL team page.
 *
 * USER FLOW:
 * - One or more eligible drivers are displayed as explicit radio choices.
 * - Even a single eligible driver must be explicitly selected.
 * - After the choice, only that group's replacement dropdown is shown.
 * - The other three groups remain read-only.
 * - Once an RD already exists, team.php limits the choices to the originally
 *   replaced group so an edit cannot become a second replacement.
 *
 * INTERNAL:
 * - pick_type remains RD for compatibility.
 * - submit-team-picks.php performs independent server-side validation.
 *
 * CHANGELOG:
 *
 * v010 (8/22/2026 8:35:00 pm)
 * - COSMETIC: Shortened replacement dropdown placeholder to "Select Replacement".
 * - COSMETIC: Modernized the eligibility notice with rounded corners, border, inset line, centered width and added padding.
 * - PRESERVE: No Replacement Pick eligibility, selection, validation, deadline, submit, or database logic changed.
 *
 * v009 (8/22/2026 7:26:00 pm)
 * - NEW: One required radio choice per eligible driver, including single-driver cases.
 * - NEW: Supports MULTIPLE_RD_AVAILABLE without admin selection.
 * - NEW: Replacement dropdown appears only for the explicitly selected group.
 * - CHANGE: User-facing wording standardized to Replacement Pick.
 * - PRESERVE: Existing MRL group colors, deadline display and RD submission endpoint.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');

$rdWrapperVersion = 'v010';

if (!isset($rdBasePickRow) || !is_array($rdBasePickRow)) {
    echo "<div style='color:#b00000;background:#ffd7d7;text-align:center;font-weight:bold;padding:10px;'>Replacement Pick base row is not available.</div>";
    return;
}

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

function rd_readonly(string $value, string $group): string
{
    return "<div style='width:98%;box-sizing:border-box;min-height:38px;display:flex;align-items:center;"
        . "padding:0 8px;font-size:18px;color:black;background-color:" . rd_cell_style($group) . ";"
        . "border-radius:4px;opacity:.65;margin:0 auto;'>"
        . rd_h($value)
        . "</div>";
}

$rdDisplayYear = isset($raceYear) ? (string)$raceYear : '';
$rdDisplaySegment = isset($rdPendingSegmentLabel) && trim((string)$rdPendingSegmentLabel) !== ''
    ? trim((string)$rdPendingSegmentLabel)
    : trim((string)($rdPendingSegment ?? ''));

$rdChoices = isset($rdPendingQualifiers) && is_array($rdPendingQualifiers)
    ? array_values($rdPendingQualifiers)
    : [];

if (empty($rdChoices)) {
    $legacyGroup = isset($rdPendingGroup) ? strtoupper(trim((string)$rdPendingGroup)) : '';
    $legacyDriver = isset($rdPendingCurrentDriver) ? trim((string)$rdPendingCurrentDriver) : '';

    if (in_array($legacyGroup, ['A','B','C','D'], true) && $legacyDriver !== '') {
        $rdChoices[] = [
            'slot' => $legacyGroup,
            'driver' => $legacyDriver,
            'trigger_races' => isset($rdPendingPayload['trigger_races']) && is_array($rdPendingPayload['trigger_races'])
                ? $rdPendingPayload['trigger_races']
                : [],
            'effective_race' => isset($rdPendingEffectiveRace) ? (string)$rdPendingEffectiveRace : '',
        ];
    }
}

if (empty($rdChoices)) {
    echo "<div style='color:#b00000;background:#ffd7d7;text-align:center;font-weight:bold;padding:10px;'>No eligible Replacement Pick driver is available.</div>";
    return;
}

$rdDisplayPickRow = isset($rdActivePickRow) && is_array($rdActivePickRow)
    ? $rdActivePickRow
    : $rdBasePickRow;

$drivers = [];
foreach (['A','B','C','D'] as $g) {
    $drivers[$g] = trim((string)($rdDisplayPickRow['driver' . $g] ?? ''));
}

$baseDrivers = [];
foreach (['A','B','C','D'] as $g) {
    $baseDrivers[$g] = trim((string)($rdBasePickRow['driver' . $g] ?? ''));
}

$effectiveRace = '';
foreach ($rdChoices as $q) {
    $candidate = trim((string)($q['effective_race'] ?? ''));
    if ($candidate !== '') {
        $effectiveRace = $candidate;
        break;
    }
}

$deadlineRace = isset($rdDeadlineRaceCode) ? trim((string)$rdDeadlineRaceCode) : '';
$deadlineTime = isset($rdDeadlineDisplay) ? trim((string)$rdDeadlineDisplay) : '';
if ($deadlineRace !== '') {
    $effectiveRace = $deadlineRace;
}

$optionsByGroup = isset($rdReplacementOptionsByGroup) && is_array($rdReplacementOptionsByGroup)
    ? $rdReplacementOptionsByGroup
    : [];

$selectedByGroup = isset($rdSelectedDriversByGroup) && is_array($rdSelectedDriversByGroup)
    ? $rdSelectedDriversByGroup
    : [];

$clockId = 'rdClockCell';
?>

<br>
<div style="width:94%;box-sizing:border-box;margin:10px auto;color:#8a1b00;font-size:20px;background-color:#fabf8f;text-align:center;font-weight:bold;padding:12px 16px;font-family:Century Gothic,sans-serif;border:1px solid #9b5a36;border-radius:10px;box-shadow:inset 0 0 0 2px rgba(255,255,255,.22);">
    You are eligible, but not required, to make a Replacement Pick under league rule 3
    <br>(2 successive races with 0 points scored by a driver)
</div>

<form id="rdReplacementForm" action="/submit-team-picks.php" method="post" onsubmit="return rdPrepareSubmit(this);">
    <input type="hidden" name="submission_id" value="">
    <input type="hidden" name="form_id" value="team_replacement_driver.php">
    <input type="hidden" name="form_version" value="<?php echo rd_h($rdWrapperVersion); ?>">
    <input type="hidden" name="pick_type_override" value="RD">
    <input type="hidden" name="rd_segment" value="<?php echo rd_h((string)$rdPendingSegment); ?>">
    <input type="hidden" name="rd_effective_race" value="<?php echo rd_h($effectiveRace); ?>">
    <input type="hidden" name="rd_supersedes_pick_id" value="<?php echo rd_h((string)($rdBasePickRow['pickID'] ?? '')); ?>">
    <input type="hidden" name="rd_selected_driver" id="rdSelectedDriver" value="">

    <?php foreach (['A','B','C','D'] as $g): ?>
        <input type="hidden" name="group-<?php echo strtolower($g); ?>-driver" id="rdCanonical<?php echo $g; ?>" value="<?php echo rd_h($drivers[$g]); ?>">
    <?php endforeach; ?>

    <div style="margin:8px auto;padding:10px;max-width:900px;background:#2a2412;border:1px solid #8d721d;border-radius:7px;color:#fff;">
        <div style="font-weight:bold;color:#ffe08a;margin-bottom:7px;">
            Replacement Pick — choose the driver to replace:
        </div>

        <?php foreach ($rdChoices as $index => $q): ?>
            <?php
            $group = strtoupper(trim((string)($q['slot'] ?? '')));
            $driver = trim((string)($q['driver'] ?? ''));
            $triggers = isset($q['trigger_races']) && is_array($q['trigger_races'])
                ? implode(', ', $q['trigger_races'])
                : '';
            ?>
            <label style="display:block;margin:7px 0;padding:8px 10px;background:#171717;border:1px solid #555;border-radius:6px;cursor:pointer;">
                <input
                    type="radio"
                    name="rd_selected_slot"
                    value="<?php echo rd_h($group); ?>"
                    data-driver="<?php echo rd_h($driver); ?>"
                    onchange="rdChooseGroup(this)"
                    required
                    style="width:auto;margin-right:8px;"
                >
                <strong>Group <?php echo rd_h($group); ?> — <?php echo rd_h($driver); ?></strong>
                <?php if ($triggers !== ''): ?>
                    <span style="color:#aaa;margin-left:8px;">(<?php echo rd_h($triggers); ?>)</span>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>

        <div id="rdChoiceMessage" style="display:none;margin-top:8px;padding:8px;background:#173b20;border:1px solid #2b7740;border-radius:6px;"></div>
    </div>

    <table align="center" style="width:100%;">
        <tr style="background-color:#fabf8f;">
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;">
                Select the eligible driver above, then choose that group's replacement driver.
            </th>
        </tr>
        <tr style="background-color:#b7dee8;">
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;">
                Effective race: <?php echo rd_h($effectiveRace); ?>
                <?php if ($deadlineRace !== '' || $deadlineTime !== ''): ?>
                    &nbsp; | &nbsp; Deadline: start of <?php echo rd_h($deadlineRace); ?>
                    <?php if ($deadlineTime !== ''): ?>(<?php echo rd_h($deadlineTime); ?>)<?php endif; ?>
                <?php endif; ?>
            </th>
        </tr>
    </table>

    <table align="center" style="width:100%;">
        <tr style="background-color:#fabf8f;">
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:14%;"><?php echo rd_h($rdDisplayYear); ?></th>
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:18%;">A Driver</th>
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:18%;">B Driver</th>
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:18%;">C Driver</th>
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:18%;">D Driver</th>
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;width:14%;" id="<?php echo $clockId; ?>"></th>
        </tr>

        <tr style="background-color:#b7dee8;">
            <th style="color:black;text-align:center;font-family:Century Gothic,sans-serif;vertical-align:middle;">
                <?php echo rd_h($rdDisplaySegment); ?>
            </th>

            <?php foreach (['A','B','C','D'] as $g): ?>
                <td style="background-color:<?php echo rd_h(rd_cell_style($g)); ?>;width:18%;padding:2px;vertical-align:middle;">
                    <div id="rdReadonly<?php echo $g; ?>">
                        <?php echo rd_readonly($drivers[$g], $g); ?>
                    </div>

                    <div id="rdEditor<?php echo $g; ?>" style="display:none;">
                        <select
                            id="rdSelect<?php echo $g; ?>"
                            data-group="<?php echo rd_h($g); ?>"
                            onchange="rdReplacementChanged(this)"
                            style="width:100%;height:auto;border:1px solid black;font-size:18px;color:black;background-color:<?php echo rd_h(rd_cell_style($g)); ?>;border-radius:4px;"
                        >
                            <option value="">Select Replacement</option>

                            <?php
                            $currentSelected = trim((string)($selectedByGroup[$g] ?? ''));
                            if ($currentSelected !== '' && $currentSelected !== $baseDrivers[$g]):
                            ?>
                                <option value="<?php echo rd_h($currentSelected); ?>" selected><?php echo rd_h($currentSelected); ?></option>
                            <?php endif; ?>

                            <?php foreach (($optionsByGroup[$g] ?? []) as $driverRow): ?>
                                <?php
                                $driverName = trim((string)($driverRow['driverName'] ?? ''));
                                $driverTag = trim((string)($driverRow['tag'] ?? ''));
                                if ($driverName === '' || $driverName === $currentSelected) continue;
                                $displayText = trim($driverName . ' ' . $driverTag);
                                ?>
                                <option value="<?php echo rd_h($driverName); ?>"><?php echo rd_h($displayText); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </td>
            <?php endforeach; ?>

            <td style="text-align:center;background-color:#b7dee8;width:14%;vertical-align:middle;">
                <input type="reset" value="Reset" onclick="setTimeout(rdResetChoice,0)">
                <input type="submit" value="Submit Picks">
            </td>
        </tr>
    </table>

    <div style="font-size:10px;color:#999;text-align:right;margin:0;padding:0;">
        team_replacement_driver.php <?php echo rd_h($rdWrapperVersion); ?>
    </div>
</form>

<div style="font:11px/1.2 monospace;color:#999;text-align:right;margin:0;padding:8px 0 0 0;">
    ELIGIBLE CHOICES: <?php echo rd_h((string)count($rdChoices)); ?>
    | EFFECTIVE RACE: <?php echo rd_h($effectiveRace); ?>
    <?php if ($deadlineRace !== '' || $deadlineTime !== ''): ?>
        | DEADLINE: start of <?php echo rd_h($deadlineRace); ?>
        <?php if ($deadlineTime !== ''): ?>(<?php echo rd_h($deadlineTime); ?>)<?php endif; ?>
    <?php endif; ?>
</div>

<script>
function rdHideAllEditors() {
    ['A','B','C','D'].forEach(function(group) {
        var editor = document.getElementById('rdEditor' + group);
        var readonly = document.getElementById('rdReadonly' + group);
        if (editor) editor.style.display = 'none';
        if (readonly) readonly.style.display = 'block';
    });
}

function rdChooseGroup(radio) {
    rdHideAllEditors();

    var group = radio.value || '';
    var originalDriver = radio.getAttribute('data-driver') || '';
    var editor = document.getElementById('rdEditor' + group);
    var readonly = document.getElementById('rdReadonly' + group);
    var selectedDriverField = document.getElementById('rdSelectedDriver');
    var msg = document.getElementById('rdChoiceMessage');

    if (editor) editor.style.display = 'block';
    if (readonly) readonly.style.display = 'none';
    if (selectedDriverField) selectedDriverField.value = originalDriver;

    if (msg) {
        msg.textContent = 'Selected: Group ' + group + ' — ' + originalDriver;
        msg.style.display = 'block';
    }

    var select = document.getElementById('rdSelect' + group);
    if (select && select.value !== '') {
        var canonical = document.getElementById('rdCanonical' + group);
        if (canonical) canonical.value = select.value;
    }
}

function rdReplacementChanged(select) {
    var group = select.getAttribute('data-group') || '';
    var canonical = document.getElementById('rdCanonical' + group);
    if (canonical) canonical.value = select.value;
}

function rdPrepareSubmit(form) {
    var selected = form.querySelector('input[name="rd_selected_slot"]:checked');
    if (!selected) return false;

    var group = selected.value || '';
    var select = document.getElementById('rdSelect' + group);

    if (!select || select.value === '') {
        if (select && typeof select.reportValidity === 'function') {
            select.setCustomValidity('Select a replacement driver for Group ' + group + '.');
            select.reportValidity();
            select.setCustomValidity('');
        } else {
            alert('Select a replacement driver for Group ' + group + '.');
        }
        return false;
    }

    var canonical = document.getElementById('rdCanonical' + group);
    if (canonical) canonical.value = select.value;

    return true;
}

function rdResetChoice() {
    rdHideAllEditors();
    var msg = document.getElementById('rdChoiceMessage');
    var selectedDriverField = document.getElementById('rdSelectedDriver');
    if (msg) msg.style.display = 'none';
    if (selectedDriverField) selectedDriverField.value = '';
}

function updateRdClock() {
    var now = new Date();
    var cell = document.getElementById('<?php echo rd_h($clockId); ?>');
    if (!cell) return;

    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    var year = now.getFullYear();
    var hours = now.getHours();
    var minutes = String(now.getMinutes()).padStart(2, '0');
    var seconds = String(now.getSeconds()).padStart(2, '0');
    var ampm = hours >= 12 ? 'PM' : 'AM';
    var h12 = hours % 12;
    if (h12 === 0) h12 = 12;

    cell.textContent = month + '/' + day + '/' + year + ' ' + h12 + ':' + minutes + ':' + seconds + ' ' + ampm;
}

rdHideAllEditors();
updateRdClock();
setInterval(updateRdClock, 1000);
</script>