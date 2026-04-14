<?php
declare(strict_types=1);

/**
 * current_segment_chart.php
 *
 * VERSION: v007
 * LAST MODIFIED: 4/13/2026 3:33:00 pm
 *
 * DESCRIPTION:
 * Current segment team chart shown on team.php after the normal deadline.
 * Matches the public Team Chart LP/RD display pattern, including merged
 * two-row RD blocks, team-name markers, and stacked footnotes.
 *
 * CHANGELOG:
 *
 * v007 (4/13/2026)
 * - FIX: Restored table width to 100% so layout works correctly when included inside team.php.
 *
 * v006 (4/12/2026)
 * - CHANGE: Set page background to #222222 to match surrounding MRL pages.
 * - CHANGE: Reduced chart width to 80% and kept it centered to better match the public Team Chart presentation.
 * - FIX: Changed RD rowspan cells to left-aligned driver text instead of centered text.
 *
 * v005 (4/12/2026)
 * - FIX: Rebuilt RD rendering from the working Team Chart pattern so unchanged driver columns use individual rowspan cells.
 * - FIX: Suppressed standalone base SEG/ADJ rows when a team also has an RD row.
 * - FIX: Preserved a space before team-name marker symbols in the Team column.
 * - CHANGE: Kept stacked footnotes with specific team, action, and effective race.
 */

session_start();
date_default_timezone_set('America/New_York');
include 'config.php';
include 'config_mrl.php';

function csc_h($val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function csc_marker_symbol(int $index): string
{
    return str_repeat('*', max(1, $index));
}

function csc_effective_race_label($value): string
{
    $num = (int)$value;
    if ($num <= 0) {
        return '';
    }
    return 'R' . str_pad((string)$num, 2, '0', STR_PAD_LEFT);
}

function csc_get_reference_pick_row(array $row, array $rowsByPickId, ?array $baseRow): ?array
{
    $supersedesPickID = (int)($row['supersedes_pickID'] ?? 0);
    if ($supersedesPickID > 0 && isset($rowsByPickId[$supersedesPickID])) {
        return $rowsByPickId[$supersedesPickID];
    }
    return $baseRow;
}

function csc_get_changed_field_for_rd(array $row, ?array $referenceRow): ?string
{
    if (strtoupper(trim((string)($row['pick_type'] ?? ''))) !== 'RD') {
        return null;
    }

    foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
        $current = trim((string)($row[$field] ?? ''));
        $original = trim((string)($referenceRow[$field] ?? ''));
        if ($current !== '' && strcasecmp($current, $original) !== 0) {
            return $field;
        }
    }

    return null;
}

function csc_build_chart_context(array $rows): array
{
    $rowsByPickId = [];
    $baseRowsByTeam = [];
    $teamsWithRd = [];
    $notes = [];
    $htmlRows = [];
    $markerIndex = 0;

    foreach ($rows as $row) {
        $pickId = (int)($row['pickID'] ?? 0);
        if ($pickId > 0) {
            $rowsByPickId[$pickId] = $row;
        }

        $teamName = trim((string)($row['teamName'] ?? ''));
        $pickType = strtoupper(trim((string)($row['pick_type'] ?? 'SEG')));

        if ($teamName !== '' && !isset($baseRowsByTeam[$teamName]) && ($pickType === 'SEG' || $pickType === 'ADJ' || $pickType === '')) {
            $baseRowsByTeam[$teamName] = $row;
        }

        if ($teamName !== '' && !isset($baseRowsByTeam[$teamName])) {
            $baseRowsByTeam[$teamName] = $row;
        }

        if ($teamName !== '' && $pickType === 'RD') {
            $teamsWithRd[$teamName] = true;
        }
    }

    foreach ($rows as $row) {
        $teamName = trim((string)($row['teamName'] ?? ''));
        $pickType = strtoupper(trim((string)($row['pick_type'] ?? 'SEG')));
        $referenceRow = csc_get_reference_pick_row($row, $rowsByPickId, $baseRowsByTeam[$teamName] ?? null);
        $marker = '';
        $noteText = '';

        if (($pickType === 'SEG' || $pickType === 'ADJ') && isset($teamsWithRd[$teamName])) {
            continue;
        }

        if ($pickType === 'LP') {
            $markerIndex++;
            $marker = csc_marker_symbol($markerIndex);
            $noteText = $teamName . ' — Late Pick';
            $effectiveRaceLabel = csc_effective_race_label($row['effective_race'] ?? 0);
            if ($effectiveRaceLabel !== '') {
                $noteText .= ' — Effective ' . $effectiveRaceLabel;
            }
            $notes[] = ['marker' => $marker, 'text' => $noteText];
        } elseif ($pickType === 'RD') {
            $changedField = csc_get_changed_field_for_rd($row, $referenceRow);
            if ($changedField !== null) {
                $markerIndex++;
                $marker = csc_marker_symbol($markerIndex);
                $noteText = $teamName . ' — Replacement Driver';
                $effectiveRaceLabel = csc_effective_race_label($row['effective_race'] ?? 0);
                if ($effectiveRaceLabel !== '') {
                    $noteText .= ' — Effective ' . $effectiveRaceLabel;
                }
                $notes[] = ['marker' => $marker, 'text' => $noteText];
            }
        }

        if ($pickType === 'RD' && $marker !== '') {
            $changedField = csc_get_changed_field_for_rd($row, $referenceRow);
            if ($changedField !== null) {
                $htmlRows[] = [
                    'render_type' => 'rd_pair',
                    'current' => $row,
                    'reference' => $referenceRow,
                    'marker' => $marker,
                    'changed_field' => $changedField,
                ];
                continue;
            }
        }

        $htmlRows[] = [
            'render_type' => 'single',
            'current' => $row,
            'marker' => $marker,
            'pick_type' => $pickType,
        ];
    }

    return [
        'htmlRows' => $htmlRows,
        'notes' => $notes,
    ];
}

$sql = "
    SELECT
        up.pickID,
        up.pick_type,
        up.supersedes_pickID,
        up.effective_race,
        up.userID,
        up.teamName,
        COALESCE(u.userName, '') AS userName,
        up.driverA,
        up.driverB,
        up.driverC,
        up.driverD,
        up.entryDate
    FROM user_picks up
    LEFT JOIN users u ON u.userID = up.userID
    WHERE up.raceYear = '$raceYear'
      AND up.segment = '$segment'
      AND COALESCE(u.userName, '') != 'MRL'
    ORDER BY up.userID ASC, up.entryDate ASC, up.pickID ASC
";

echo "<style type='text/css'>
      body {
        background-color:#222222;
      }
      table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
        padding: 3px;
        color: black !important;
        font-size: 13pt !important;
        line-height: 140%;
        font-family: Arial, sans-serif;
      }
      .csc-rd-merged {
        text-align: left;
        vertical-align: middle;
      }
      .csc-notes-row {
        background:#fabf8f !important;
        color:#000000 !important;
        font-size:12px !important;
        line-height:1.35 !important;
        padding:8px 12px !important;
        text-align:left !important;
      }
      .csc-note-line + .csc-note-line {
        margin-top:4px;
      }
   </style>";

echo "<table align=center style='width:100%;'>";
echo "<tr style=background-color:#fabf8f>";
echo "<th colspan=7> " . csc_h((string)$raceYear) . " " . csc_h((string)$segmentName) . " Team Chart</th>";
echo "<tr style=background-color:#fabf8f>";
echo "<th>Team</th><th>Owner</th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th><th>Submission Time</th></tr>";

$rows = [];
$result = $dbo->query($sql);
if ($result) {
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
}

$chartContext = ['htmlRows' => [], 'notes' => []];
if (!empty($rows)) {
    $chartContext = csc_build_chart_context($rows);
}

if (empty($chartContext['htmlRows'])) {
    echo "<tr><td colspan='7' style='text-align:center;'>No picks found for this year / segment.</td></tr>";
} else {
    foreach ($chartContext['htmlRows'] as $entry) {

        if ($entry['render_type'] === 'single') {

            $row = $entry['current'];
            $pickType = strtoupper(trim((string)($entry['pick_type'] ?? 'SEG')));
            $marker = (string)($entry['marker'] ?? '');

            $driverA = trim((string)($row['driverA'] ?? ''));
            $driverB = trim((string)($row['driverB'] ?? ''));
            $driverC = trim((string)($row['driverC'] ?? ''));
            $driverD = trim((string)($row['driverD'] ?? ''));

            $teamDisplay = trim((string)($row['teamName'] ?? ''));

            if ($marker !== '') {
                $teamDisplay .= ' ' . $marker;
            }

            if ($pickType === 'LP' && $marker !== '') {
                $driverA .= ' ' . $marker;
                $driverB .= ' ' . $marker;
                $driverC .= ' ' . $marker;
                $driverD .= ' ' . $marker;
            }

            echo "<tr>";
            echo "<td style=background-color:#b7dee8>" . csc_h($teamDisplay) . "</td>";
            echo "<td style=background-color:#b7dee8>" . csc_h($row['userName'] ?? '') . "</td>";
            echo "<td style=background-color:#d9d9d9>" . csc_h($driverA) . "</td>";
            echo "<td style=background-color:#c4bd97>" . csc_h($driverB) . "</td>";
            echo "<td style=background-color:#b8cce4>" . csc_h($driverC) . "</td>";
            echo "<td style=background-color:#d8e4bc>" . csc_h($driverD) . "</td>";
            echo "<td style=background-color:#b7dee8>" . csc_h($row['entryDate'] ?? '') . "</td>";
            echo "</tr>";

        } else {

            $row = $entry['current'];
            $reference = is_array($entry['reference'] ?? null) ? $entry['reference'] : [];
            $marker = (string)($entry['marker'] ?? '');
            $changedField = (string)($entry['changed_field'] ?? '');

            $fieldOrder = ['driverA', 'driverB', 'driverC', 'driverD'];

            $changedCellBg =
                $changedField === 'driverA' ? '#d9d9d9' :
                ($changedField === 'driverB' ? '#c4bd97' :
                ($changedField === 'driverC' ? '#b8cce4' : '#d8e4bc'));

            $teamDisplay = trim((string)($row['teamName'] ?? ''));

            if ($marker !== '') {
                $teamDisplay .= ' ' . $marker;
            }

            echo "<tr>";

            echo "<td style=background-color:#b7dee8 rowspan=2>" . csc_h($teamDisplay) . "</td>";

            echo "<td style=background-color:#b7dee8 rowspan=2>" . csc_h($row['userName'] ?? '') . "</td>";

            foreach ($fieldOrder as $field) {

                $fieldBg =
                    $field === 'driverA' ? '#d9d9d9' :
                    ($field === 'driverB' ? '#c4bd97' :
                    ($field === 'driverC' ? '#b8cce4' : '#d8e4bc'));

                if ($field === $changedField) {

                    echo "<td style='background-color:" . $fieldBg . "'>" . csc_h($reference[$field] ?? '') . "</td>";

                } else {

                    echo "<td class='csc-rd-merged' style='background-color:" . $fieldBg . "' rowspan='2'>" . csc_h($row[$field] ?? '') . "</td>";

                }
            }

            echo "<td style=background-color:#b7dee8>" . csc_h($reference['entryDate'] ?? $row['entryDate'] ?? '') . "</td>";

            echo "</tr>";

            echo "<tr>";

            echo "<td style='background-color:" . $changedCellBg . "'>" . csc_h(trim(((string)($row[$changedField] ?? '')) . ' ' . $marker)) . "</td>";

            echo "<td style=background-color:#b7dee8>" . csc_h($row['entryDate'] ?? '') . "</td>";

            echo "</tr>";

        }

    }
}

if (!empty($chartContext['notes'])) {

    echo "<tfoot>";

    echo "<tr>";

    echo "<td colspan='7' class='csc-notes-row'>";

    foreach ($chartContext['notes'] as $note) {

        echo "<div class='csc-note-line'>" . csc_h($note['marker'] . ' ' . $note['text']) . "</div>";

    }

    echo "</td>";

    echo "</tr>";

    echo "</tfoot>";

}

echo "</table>";

?>
