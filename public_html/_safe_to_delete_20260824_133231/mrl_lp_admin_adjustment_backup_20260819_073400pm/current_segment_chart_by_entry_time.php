<?php
declare(strict_types=1);

/**
 * current_segment_chart_by_entry_time.php
 *
 * VERSION: v002
 * LAST MODIFIED: 4/13/2026 3:24:07 pm
 *
 * DESCRIPTION:
 * Current segment team chart sorted by entry time for admin use.
 * Matches the public Team Chart LP/RD display pattern, including
 * team-name markers and stacked footnotes, while preserving strict
 * chronological order and keeping RD rows unmerged.
 *
 * CHANGELOG:
 *
 * v002 (4/13/2026)
 * - CHANGE: Rebuilt rendering from user_picks + users so LP/RD markers match the public chart wording pattern.
 * - CHANGE: Preserved strict chronological order by ordering rows by entryDate ASC, then pickID ASC.
 * - CHANGE: Added increasing asterisk markers on team name and affected driver(s), with stacked footnotes below the table.
 * - CHANGE: Kept RD as separate chronological rows only; no merged two-row RD display in this file.
 * - FIX: Added effective race wording to notes when effective_race is present.
 *
 * v001 (4/7/2026)
 * - CHANGE: Rebuilt from user_picks + users with render-time (LP) / (RD) markers plus legend.
 */

session_start();
date_default_timezone_set('America/New_York');
include 'config.php';
include 'config_mrl.php';

function cscet_h($val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function cscet_marker_symbol(int $index): string
{
    return str_repeat('*', max(1, $index));
}

function cscet_effective_race_label($value): string
{
    $num = (int)$value;
    if ($num <= 0) {
        return '';
    }

    return 'R' . str_pad((string)$num, 2, '0', STR_PAD_LEFT);
}

function cscet_get_reference_pick_row(array $row, array $rowsByPickId, ?array $baseRow): ?array
{
    $supersedesPickID = (int)($row['supersedes_pickID'] ?? 0);
    if ($supersedesPickID > 0 && isset($rowsByPickId[$supersedesPickID])) {
        return $rowsByPickId[$supersedesPickID];
    }

    return $baseRow;
}

function cscet_get_changed_fields_for_rd(array $row, ?array $referenceRow): array
{
    $changed = [];

    if (strtoupper(trim((string)($row['pick_type'] ?? 'SEG'))) !== 'RD') {
        return $changed;
    }

    foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
        $current = trim((string)($row[$field] ?? ''));
        $original = trim((string)($referenceRow[$field] ?? ''));

        if ($current !== '' && strcasecmp($current, $original) !== 0) {
            $changed[] = $field;
        }
    }

    return $changed;
}

function cscet_build_chart_context(array $rows): array
{
    $rowsByPickId = [];
    $baseRowsByTeam = [];
    $htmlRows = [];
    $notes = [];
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
    }

    foreach ($rows as $row) {
        $teamName = trim((string)($row['teamName'] ?? ''));
        $pickType = strtoupper(trim((string)($row['pick_type'] ?? 'SEG')));
        $referenceRow = cscet_get_reference_pick_row($row, $rowsByPickId, $baseRowsByTeam[$teamName] ?? null);
        $changedFields = cscet_get_changed_fields_for_rd($row, $referenceRow);
        $marker = '';
        $noteText = '';

        if ($pickType === 'LP') {
            $markerIndex++;
            $marker = cscet_marker_symbol($markerIndex);
            $noteText = $teamName . ' — Late Pick';
            $effectiveRaceLabel = cscet_effective_race_label($row['effective_race'] ?? 0);
            if ($effectiveRaceLabel !== '') {
                $noteText .= ' — Effective ' . $effectiveRaceLabel;
            }
            $notes[] = ['marker' => $marker, 'text' => $noteText];
        } elseif ($pickType === 'RD' && !empty($changedFields)) {
            $markerIndex++;
            $marker = cscet_marker_symbol($markerIndex);
            $noteText = $teamName . ' — Replacement Driver';
            $effectiveRaceLabel = cscet_effective_race_label($row['effective_race'] ?? 0);
            if ($effectiveRaceLabel !== '') {
                $noteText .= ' — Effective ' . $effectiveRaceLabel;
            }
            $notes[] = ['marker' => $marker, 'text' => $noteText];
        }

        $htmlRows[] = [
            'current' => $row,
            'reference' => $referenceRow,
            'pick_type' => $pickType,
            'marker' => $marker,
            'changed_fields' => $changedFields,
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
    ORDER BY up.entryDate ASC, up.pickID ASC
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
      .cscet-notes-row {
        background:#fabf8f !important;
        color:#000000 !important;
        font-size:12px !important;
        line-height:1.35 !important;
        padding:8px 12px !important;
        text-align:left !important;
      }
      .cscet-note-line + .cscet-note-line {
        margin-top:4px;
      }
   </style>";

echo "<table align=center style='width:80%; margin-left:auto; margin-right:auto;'>";
echo "<tr style=background-color:#fabf8f>";
echo "<th colspan=7> " . cscet_h((string)$raceYear) . " " . cscet_h((string)$segmentName) . " Team Chart</th>";
echo "<tr style=background-color:#fabf8f>";
echo "<th>Team</th><th>Owner</th><th>Group A</th><th>Group B</th><th>Group C</th><th>Group D</th><th>Submission Time</th></tr>";

$rows = [];
$result = $dbo->query($sql);
if ($result) {
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
}

$chartContext = ['htmlRows' => [], 'notes' => []];
if (!empty($rows)) {
    $chartContext = cscet_build_chart_context($rows);
}

if (empty($chartContext['htmlRows'])) {
    echo "<tr><td colspan='7' style='text-align:center;'>No picks found for this year / segment.</td></tr>";
} else {
    foreach ($chartContext['htmlRows'] as $entry) {
        $row = $entry['current'];
        $pickType = strtoupper(trim((string)($entry['pick_type'] ?? 'SEG')));
        $marker = (string)($entry['marker'] ?? '');
        $changedFields = is_array($entry['changed_fields'] ?? null) ? $entry['changed_fields'] : [];

        $teamDisplay = trim((string)($row['teamName'] ?? ''));
        $driverA = trim((string)($row['driverA'] ?? ''));
        $driverB = trim((string)($row['driverB'] ?? ''));
        $driverC = trim((string)($row['driverC'] ?? ''));
        $driverD = trim((string)($row['driverD'] ?? ''));

        if ($marker !== '') {
            $teamDisplay .= ' ' . $marker;
        }

        if ($pickType === 'LP' && $marker !== '') {
            $driverA = ($driverA !== '') ? ($driverA . ' ' . $marker) : '';
            $driverB = ($driverB !== '') ? ($driverB . ' ' . $marker) : '';
            $driverC = ($driverC !== '') ? ($driverC . ' ' . $marker) : '';
            $driverD = ($driverD !== '') ? ($driverD . ' ' . $marker) : '';
        } elseif ($pickType === 'RD' && $marker !== '' && !empty($changedFields)) {
            if (in_array('driverA', $changedFields, true) && $driverA !== '') {
                $driverA .= ' ' . $marker;
            }
            if (in_array('driverB', $changedFields, true) && $driverB !== '') {
                $driverB .= ' ' . $marker;
            }
            if (in_array('driverC', $changedFields, true) && $driverC !== '') {
                $driverC .= ' ' . $marker;
            }
            if (in_array('driverD', $changedFields, true) && $driverD !== '') {
                $driverD .= ' ' . $marker;
            }
        }

        echo "<tr>";
        echo "<td style=background-color:#b7dee8>" . cscet_h($teamDisplay) . "</td>";
        echo "<td style=background-color:#b7dee8>" . cscet_h($row['userName'] ?? '') . "</td>";
        echo "<td style=background-color:#d9d9d9>" . cscet_h($driverA) . "</td>";
        echo "<td style=background-color:#c4bd97>" . cscet_h($driverB) . "</td>";
        echo "<td style=background-color:#b8cce4>" . cscet_h($driverC) . "</td>";
        echo "<td style=background-color:#d8e4bc>" . cscet_h($driverD) . "</td>";
        echo "<td style=background-color:#b7dee8>" . cscet_h($row['entryDate'] ?? '') . "</td>";
        echo "</tr>";
    }
}

if (!empty($chartContext['notes'])) {
    echo "<tfoot><tr><td colspan='7' class='cscet-notes-row' style='background:#fabf8f !important; color:#000000 !important;'>";
    foreach ($chartContext['notes'] as $note) {
        echo "<div class='cscet-note-line'>" . cscet_h($note['marker'] . ' ' . $note['text']) . "</div>";
    }
    echo "</td></tr></tfoot>";
}

echo "</table>";
?>
