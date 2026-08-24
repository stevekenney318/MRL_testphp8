<?php
declare(strict_types=1);

/**
 * current_user_team_chart.php
 *
 * VERSION: v002
 * LAST MODIFIED: 4/13/2026 9:08:59 pm
 *
 * DESCRIPTION:
 * Current user team chart shown on team.php.
 * Preserves the existing paid-status / team-info layout while applying
 * LP/RD display logic to the current-year segment rows.
 *
 * CHANGELOG:
 *
 * v002 (4/13/2026)
 * - FIX: Suppressed the original standalone row for any segment that also has an RD row.
 * - CHANGE: RD rows now render as a merged two-row block with unchanged driver cells row-spanned vertically.
 * - CHANGE: Added stacked footnotes below the year table for LP/RD notes with explicit effective-race wording.
 * - CHANGE: Preserved existing LP behavior and existing driver-tag display behavior.
 *
 * v001 (4/7/2026)
 * - CHANGE: Added render-time (LP) / (RD) markers plus legend for team page display.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');
include 'config.php';
include 'config_mrl.php';

$uid = isset($_SESSION['userSession']) ? $_SESSION['userSession'] : null;

echo "<style type='text/css'>
      table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
        padding: 3px;
        font-size: 16px;
      }
      .cuytc-rd-merged {
        vertical-align: middle;
      }
      .cuytc-notes-row {
        background:#fabf8f !important;
        color:#000000 !important;
        font-size:13px !important;
        line-height:1.35 !important;
        padding:8px 12px !important;
        text-align:left !important;
      }
      .cuytc-note-line + .cuytc-note-line {
        margin-top:4px;
      }
   </style>";

echo "<table align=center style=width:80%>";

$sql = "SELECT * FROM `user_teams` WHERE `userID` = $uid AND `raceYear` = $raceYear";
$result = $dbo->query($sql);

if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Paid Status ' . $raceYear . "</td><td style=background-color:#b7dee8>" . cuytc_h((string)($row['paidStatus'] ?? '')) . "</td><td style=background-color:#b7dee8>" . ' ' . "</td></tr>";
    }
}

$result = $dbo->query($sql);
if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Amount' . "</td><td style=background-color:#b7dee8>" . "$" . cuytc_h((string)($row['paidAmount'] ?? '')) . "</td><td style=background-color:#b7dee8>" . ' ' . "</td></tr>";
    }
}

$result = $dbo->query($sql);
if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'How' . "</td><td style=background-color:#b7dee8>" . cuytc_h((string)($row['paidHow'] ?? '')) . "</td><td style=background-color:#b7dee8>" . ' ' . "</td></tr>";
    }
}

$result = $dbo->query($sql);
if ($result && $result->rowCount() > 0) {
    foreach ($result as $row) {
        echo "<tr><td style=width:14%;background-color:#b7dee8>" . 'Comment' . "</td><td style=background-color:#b7dee8>" . cuytc_h((string)($row['paidComment'] ?? '')) . "</td><td style=background-color:#b7dee8>" . ' ' . "</td></tr>";
    }
}

$sql = "SELECT * FROM `user_teams` WHERE `userID` = $uid AND `raceYear` = $raceYear";
foreach ($dbo->query($sql) as $row) {
    echo "<tr><td style=width:14%;background-color:#f2dcdb>" . 'Team Name' . "</td><td style=background-color:#f2dcdb>" . cuytc_h((string)($row['teamName'] ?? '')) . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td></tr>";
}

$sql = "SELECT * FROM `users` WHERE `userID` = $uid";
foreach ($dbo->query($sql) as $row) {
    echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Team Owner' . "</td><td style=background-color:#f2dcdb>" . cuytc_h((string)($row['userName'] ?? '')) . "</td><td style=background-color:#f2dcdb>" . ' ' . "</td>";
}

foreach ($dbo->query($sql) as $row) {
    echo "<tr><td style=width:175px;background-color:#f2dcdb>" . 'Email Address(es)' . "</td><td style=background-color:#f2dcdb>" . cuytc_h((string)($row['userEmail'] ?? '')) . "</td><td style=background-color:#f2dcdb>" . cuytc_h((string)($row['userEmail2'] ?? '')) . "</td></tr>";
}

echo "</table>";

echo "<table align=center style=width:80%>";
echo "<tr style=background-color:#fabf8f>";
echo "<th style=width:14%>" . cuytc_h((string)$raceYear) . "</th><th style=width:18%>Group A</th><th style=width:18%>Group B</th><th style=width:18%>Group C</th><th style=width:18%>Group D</th><th style=width:14%>Submission Time</th></tr>";

$segments = ['S1', 'S2', 'S3', 'S4'];
$allRows = [];

foreach ($segments as $segment) {
    $sql = "SELECT `pickID`, `pick_type`, `supersedes_pickID`, `effective_race`, `segment`, `driverA`, `driverB`, `driverC`, `driverD`, `entryDate`
            FROM `user_picks`
            WHERE `userID` = $uid
              AND `raceYear` = $raceYear
              AND `segment` = '$segment'
            ORDER BY `entryDate` ASC, `pickID` ASC";
    $result = $dbo->query($sql);
    $segmentRows = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($segmentRows as $segmentRow) {
        $allRows[] = $segmentRow;
    }
}

$chartContext = cuytc_build_chart_context($allRows);

if (empty($chartContext['displayRows'])) {
    echo "<tr><td colspan='6' style='text-align:center;'>No picks found for this year.</td></tr>";
} else {
    foreach ($chartContext['displayRows'] as $entry) {
        if ($entry['render_type'] === 'single') {
            $row = $entry['current'];
            $pickType = strtoupper(trim((string)($entry['pick_type'] ?? 'SEG')));
            $marker = (string)($entry['marker'] ?? '');
            $segmentDisplay = (string)($entry['segment_label'] ?? '');

            $driverA = cuytc_format_driver_display($row, 'driverA', 'A');
            $driverB = cuytc_format_driver_display($row, 'driverB', 'B');
            $driverC = cuytc_format_driver_display($row, 'driverC', 'C');
            $driverD = cuytc_format_driver_display($row, 'driverD', 'D');

            if ($marker !== '') {
                $segmentDisplay .= ' ' . $marker;
            }

            if ($pickType === 'LP' && $marker !== '') {
                $driverA .= ' ' . $marker;
                $driverB .= ' ' . $marker;
                $driverC .= ' ' . $marker;
                $driverD .= ' ' . $marker;
            }

            echo "<tr>";
            echo "<td style=width:175px;background-color:#b7dee8>" . cuytc_h($segmentDisplay) . "</td>";
            echo "<td style=background-color:#d9d9d9>" . cuytc_h($driverA) . "</td>";
            echo "<td style=background-color:#c4bd97>" . cuytc_h($driverB) . "</td>";
            echo "<td style=background-color:#b8cce4>" . cuytc_h($driverC) . "</td>";
            echo "<td style=background-color:#d8e4bc>" . cuytc_h($driverD) . "</td>";
            echo "<td style=background-color:#b7dee8>" . cuytc_h((string)($row['entryDate'] ?? '')) . "</td>";
            echo "</tr>";
        } else {
            $row = $entry['current'];
            $reference = is_array($entry['reference'] ?? null) ? $entry['reference'] : [];
            $marker = (string)($entry['marker'] ?? '');
            $segmentDisplay = (string)($entry['segment_label'] ?? '');
            $changedField = (string)($entry['changed_field'] ?? '');
            $fieldOrder = ['driverA', 'driverB', 'driverC', 'driverD'];

            if ($marker !== '') {
                $segmentDisplay .= ' ' . $marker;
            }

            echo "<tr>";
            echo "<td style='width:175px;background-color:#b7dee8' rowspan='2'>" . cuytc_h($segmentDisplay) . "</td>";

            foreach ($fieldOrder as $field) {
                $fieldBg =
                    $field === 'driverA' ? '#d9d9d9' :
                    ($field === 'driverB' ? '#c4bd97' :
                    ($field === 'driverC' ? '#b8cce4' : '#d8e4bc'));

                if ($field === $changedField) {
                    echo "<td style='background-color:" . $fieldBg . "'>" . cuytc_h(cuytc_format_driver_display($reference, $field, cuytc_group_from_field($field))) . "</td>";
                } else {
                    echo "<td class='cuytc-rd-merged' style='background-color:" . $fieldBg . "' rowspan='2'>" . cuytc_h(cuytc_format_driver_display($row, $field, cuytc_group_from_field($field))) . "</td>";
                }
            }

            echo "<td style='background-color:#b7dee8'>" . cuytc_h((string)($reference['entryDate'] ?? $row['entryDate'] ?? '')) . "</td>";
            echo "</tr>";

            $changedFieldBg =
                $changedField === 'driverA' ? '#d9d9d9' :
                ($changedField === 'driverB' ? '#c4bd97' :
                ($changedField === 'driverC' ? '#b8cce4' : '#d8e4bc'));

            $replacementValue = cuytc_format_driver_display($row, $changedField, cuytc_group_from_field($changedField));
            if ($marker !== '') {
                $replacementValue .= ' ' . $marker;
            }

            echo "<tr>";
            echo "<td style='background-color:" . $changedFieldBg . "'>" . cuytc_h($replacementValue) . "</td>";
            echo "<td style='background-color:#b7dee8'>" . cuytc_h((string)($row['entryDate'] ?? '')) . "</td>";
            echo "</tr>";
        }
    }
}

if (!empty($chartContext['notes'])) {
    echo "<tfoot><tr><td colspan='6' class='cuytc-notes-row'>";
    foreach ($chartContext['notes'] as $note) {
        echo "<div class='cuytc-note-line'>" . cuytc_h($note['marker'] . ' ' . $note['text']) . "</div>";
    }
    echo "</td></tr></tfoot>";
}

echo "</table>";

function cuytc_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cuytc_marker_symbol(int $index): string
{
    return str_repeat('*', max(1, $index));
}

function cuytc_effective_race_label($value): string
{
    $num = (int)$value;
    if ($num <= 0) {
        return '';
    }
    return 'R' . str_pad((string)$num, 2, '0', STR_PAD_LEFT);
}

function cuytc_group_from_field(string $field): string
{
    if ($field === 'driverA') return 'A';
    if ($field === 'driverB') return 'B';
    if ($field === 'driverC') return 'C';
    return 'D';
}

function getDriverTag($driverName, $group) {
    global $dbo, $raceYear;
    $driverName = str_replace("'", "''", (string)$driverName);
    $sql = "SELECT `Tag` FROM `" . $group . " Drivers` WHERE `driverName` = '$driverName' AND `driverYear` = $raceYear AND `Available` = 'Y'";
    $result = $dbo->query($sql);
    if ($result && $result->rowCount() > 0) {
        $row = $result->fetch(PDO::FETCH_ASSOC);
        return trim((string)($row['Tag'] ?? ''));
    }
    return '';
}

function cuytc_get_reference_pick_row(array $row, array $rowsByPickId, ?array $baseRow): ?array
{
    $supersedesPickID = (int)($row['supersedes_pickID'] ?? 0);
    if ($supersedesPickID > 0 && isset($rowsByPickId[$supersedesPickID])) {
        return $rowsByPickId[$supersedesPickID];
    }
    return $baseRow;
}

function cuytc_get_changed_field_for_rd(array $row, ?array $referenceRow): ?string
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

function cuytc_format_driver_display(array $row, string $field, string $group): string
{
    $driverName = trim((string)($row[$field] ?? ''));
    if ($driverName === '') {
        return '';
    }

    $parts = [$driverName];
    $tag = getDriverTag($driverName, $group);
    if ($tag !== '') {
        $parts[] = $tag;
    }

    return implode(' ', $parts);
}

function cuytc_build_chart_context(array $rows): array
{
    $rowsByPickId = [];
    $baseRowsBySegment = [];
    $segmentsWithRd = [];
    $displayRows = [];
    $notes = [];
    $markerIndex = 0;

    foreach ($rows as $row) {
        $pickId = (int)($row['pickID'] ?? 0);
        if ($pickId > 0) {
            $rowsByPickId[$pickId] = $row;
        }

        $segment = trim((string)($row['segment'] ?? ''));
        $pickType = strtoupper(trim((string)($row['pick_type'] ?? 'SEG')));

        if ($segment !== '' && !isset($baseRowsBySegment[$segment]) && ($pickType === 'SEG' || $pickType === 'ADJ' || $pickType === '')) {
            $baseRowsBySegment[$segment] = $row;
        }

        if ($segment !== '' && !isset($baseRowsBySegment[$segment])) {
            $baseRowsBySegment[$segment] = $row;
        }

        if ($segment !== '' && $pickType === 'RD') {
            $segmentsWithRd[$segment] = true;
        }
    }

    foreach ($rows as $row) {
        $segment = trim((string)($row['segment'] ?? ''));
        $segmentLabel = mapSegmentName($segment);
        $pickType = strtoupper(trim((string)($row['pick_type'] ?? 'SEG')));
        $referenceRow = cuytc_get_reference_pick_row($row, $rowsByPickId, $baseRowsBySegment[$segment] ?? null);
        $marker = '';

        if (($pickType === 'SEG' || $pickType === 'ADJ' || $pickType === '') && isset($segmentsWithRd[$segment])) {
            continue;
        }

        if ($pickType === 'LP') {
            $markerIndex++;
            $marker = cuytc_marker_symbol($markerIndex);
            $noteText = $segmentLabel . ' — Late Pick';
            $effectiveRaceLabel = cuytc_effective_race_label($row['effective_race'] ?? 0);
            if ($effectiveRaceLabel !== '') {
                $noteText .= ' — Effective ' . $effectiveRaceLabel;
            }
            $notes[] = ['marker' => $marker, 'text' => $noteText];
        } elseif ($pickType === 'RD') {
            $changedField = cuytc_get_changed_field_for_rd($row, $referenceRow);
            if ($changedField !== null) {
                $markerIndex++;
                $marker = cuytc_marker_symbol($markerIndex);
                $noteText = $segmentLabel . ' — Replacement Driver';
                $effectiveRaceLabel = cuytc_effective_race_label($row['effective_race'] ?? 0);
                if ($effectiveRaceLabel !== '') {
                    $noteText .= ' — Effective ' . $effectiveRaceLabel;
                }
                $notes[] = ['marker' => $marker, 'text' => $noteText];
            }
        }

        if ($pickType === 'RD' && $marker !== '') {
            $changedField = cuytc_get_changed_field_for_rd($row, $referenceRow);
            if ($changedField !== null) {
                $displayRows[] = [
                    'render_type' => 'rd_pair',
                    'current' => $row,
                    'reference' => $referenceRow,
                    'marker' => $marker,
                    'segment_label' => $segmentLabel,
                    'changed_field' => $changedField,
                    'pick_type' => $pickType,
                ];
                continue;
            }
        }

        $displayRows[] = [
            'render_type' => 'single',
            'current' => $row,
            'marker' => $marker,
            'segment_label' => $segmentLabel,
            'pick_type' => $pickType,
        ];
    }

    return [
        'displayRows' => $displayRows,
        'notes' => $notes,
    ];
}

function mapSegmentName($segment) {
    switch ($segment) {
        case 'S1':
            return 'Segment #1';
        case 'S2':
            return 'Segment #2';
        case 'S3':
            return 'Segment #3';
        case 'S4':
            return 'Playoffs';
        default:
            return '';
    }
}
?>
