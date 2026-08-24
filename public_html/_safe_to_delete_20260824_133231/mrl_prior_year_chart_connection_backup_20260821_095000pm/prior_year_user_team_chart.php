<?php
/**
 * prior_year_user_team_chart.php
 *
 * VERSION: v003
 * LAST MODIFIED: 4/13/2026 5:50:30 pm
 *
 * DESCRIPTION:
 * Prior-year user team chart shown on team.php.
 * Preserves the one-user / prior-years layout while matching the updated
 * LP/RD chart display system with explicit markers and stacked footnotes.
 *
 * CHANGELOG:
 *
 * v003 (4/13/2026)
 * - FIX: Suppressed the standalone base/original row for any year/segment that also has an RD row.
 * - FIX: Prior-year RD display now renders only the merged two-row replacement-driver block for that segment.
 *
 * v002 (4/13/2026)
 * - CHANGE: Rebuilt LP rendering to use marker symbols on the segment label and all four driver cells.
 * - CHANGE: Rebuilt RD rendering as a two-row merged block with only the changed driver column split into original and replacement values.
 * - CHANGE: Added stacked year-specific footnotes with explicit wording and effective race labels.
 * - CHANGE: Preserved prior-year table width and overall one-user layout.
 *
 * v001 (4/8/2026)
 * - CHANGE: Added minimal LP/RD render markers to the working baseline.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');
include 'config.php';
include 'config_mrl.php';

if (!function_exists('pyutc_h')) {
    function pyutc_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pyutc_marker_symbol')) {
    function pyutc_marker_symbol(int $index): string
    {
        return str_repeat('*', max(1, $index));
    }
}

if (!function_exists('pyutc_effective_race_label')) {
    function pyutc_effective_race_label($value): string
    {
        $num = (int)$value;
        if ($num <= 0) {
            return '';
        }

        return 'R' . str_pad((string)$num, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('pyutc_segment_label')) {
    function pyutc_segment_label(string $segment): string
    {
        if ($segment === 'S1') {
            return 'Segment #1';
        }
        if ($segment === 'S2') {
            return 'Segment #2';
        }
        if ($segment === 'S3') {
            return 'Segment #3';
        }
        if ($segment === 'S4') {
            return 'Playoffs';
        }

        return $segment;
    }
}

if (!function_exists('pyutc_get_reference_row')) {
    function pyutc_get_reference_row(array $row, array $rowsByPickId, ?array $baseRow): ?array
    {
        $supersedesPickID = (int)($row['supersedes_pickID'] ?? 0);
        if ($supersedesPickID > 0 && isset($rowsByPickId[$supersedesPickID])) {
            return $rowsByPickId[$supersedesPickID];
        }

        return $baseRow;
    }
}

if (!function_exists('pyutc_get_changed_field_for_rd')) {
    function pyutc_get_changed_field_for_rd(array $row, ?array $referenceRow): ?string
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
}

if (!function_exists('pyutc_build_year_chart_context')) {
    function pyutc_build_year_chart_context(array $rows, string $year): array
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
            $segmentLabel = pyutc_segment_label($segment);
            $pickType = strtoupper(trim((string)($row['pick_type'] ?? 'SEG')));
            $referenceRow = pyutc_get_reference_row($row, $rowsByPickId, $baseRowsBySegment[$segment] ?? null);
            $marker = '';
            $noteText = '';

            if (($pickType === 'SEG' || $pickType === 'ADJ' || $pickType === '') && isset($segmentsWithRd[$segment])) {
                continue;
            }

            if ($pickType === 'LP') {
                $markerIndex++;
                $marker = pyutc_marker_symbol($markerIndex);

                $noteText = $year . ' ' . $segmentLabel . ' — Late Pick';
                $effectiveRaceLabel = pyutc_effective_race_label($row['effective_race'] ?? 0);
                if ($effectiveRaceLabel !== '') {
                    $noteText .= ' — Effective ' . $effectiveRaceLabel;
                }

                $notes[] = ['marker' => $marker, 'text' => $noteText];
            } elseif ($pickType === 'RD') {
                $changedField = pyutc_get_changed_field_for_rd($row, $referenceRow);

                if ($changedField !== null) {
                    $markerIndex++;
                    $marker = pyutc_marker_symbol($markerIndex);

                    $noteText = $year . ' ' . $segmentLabel . ' — Replacement Driver';
                    $effectiveRaceLabel = pyutc_effective_race_label($row['effective_race'] ?? 0);
                    if ($effectiveRaceLabel !== '') {
                        $noteText .= ' — Effective ' . $effectiveRaceLabel;
                    }

                    $notes[] = ['marker' => $marker, 'text' => $noteText];
                }
            }

            if ($pickType === 'RD' && $marker !== '') {
                $changedField = pyutc_get_changed_field_for_rd($row, $referenceRow);

                if ($changedField !== null) {
                    $displayRows[] = [
                        'render_type' => 'rd_pair',
                        'current' => $row,
                        'reference' => $referenceRow,
                        'marker' => $marker,
                        'segment_label' => $segmentLabel,
                        'changed_field' => $changedField,
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
}

echo "<style type='text/css'>
      table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
        padding: 3px;
        font-size: 16px;
      }
      .pyutc-rd-merged {
        vertical-align: middle;
      }
      .pyutc-notes-row {
        background:#fabf8f !important;
        color:#000000 !important;
        font-size:13px !important;
        line-height:1.35 !important;
        padding:8px 12px !important;
        text-align:left !important;
      }
      .pyutc-note-line + .pyutc-note-line {
        margin-top:4px;
      }
   </style>";

$segmentOrder = ['S1', 'S2', 'S3', 'S4'];
$rows = [];

foreach ($segmentOrder as $segmentCode) {
    $sql = "
        SELECT
            up.pickID,
            up.pick_type,
            up.supersedes_pickID,
            up.effective_race,
            up.userID,
            up.raceYear,
            up.segment,
            up.driverA,
            up.driverB,
            up.driverC,
            up.driverD,
            up.entryDate
        FROM user_picks up
        WHERE up.userID = $uid
          AND up.raceYear = '$prevRaceYear'
          AND up.segment = '$segmentCode'
        ORDER BY up.entryDate ASC, up.pickID ASC
    ";

    $result = $dbo->query($sql);
    if ($result) {
        $segmentRows = $result->fetchAll(PDO::FETCH_ASSOC);
        foreach ($segmentRows as $segmentRow) {
            $rows[] = $segmentRow;
        }
    }
}

$chartContext = pyutc_build_year_chart_context($rows, (string)$prevRaceYear);

echo "<table align=center style='width:80%'>";
echo "<tr style='width:175px;background-color:#fabf8f'>";
echo "<th style='width:14%'>" . pyutc_h((string)$prevRaceYear) . "</th><th style='width:18%'>Group A</th><th style='width:18%'>Group B</th><th style='width:18%'>Group C</th><th style='width:18%'>Group D</th><th style='width:14%'>Submission Time</th></tr>";

if (empty($chartContext['displayRows'])) {
    echo "<tr><td colspan='6' style='text-align:center;'>No picks found for this year.</td></tr>";
} else {
    foreach ($chartContext['displayRows'] as $entry) {
        if ($entry['render_type'] === 'single') {
            $row = $entry['current'];
            $pickType = strtoupper(trim((string)($entry['pick_type'] ?? 'SEG')));
            $marker = (string)($entry['marker'] ?? '');
            $segmentDisplay = (string)($entry['segment_label'] ?? '');

            $driverA = trim((string)($row['driverA'] ?? ''));
            $driverB = trim((string)($row['driverB'] ?? ''));
            $driverC = trim((string)($row['driverC'] ?? ''));
            $driverD = trim((string)($row['driverD'] ?? ''));

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
            echo "<td style='width:175px;background-color:#b7dee8'>" . pyutc_h($segmentDisplay) . "</td>";
            echo "<td style='background-color:#d9d9d9'>" . pyutc_h($driverA) . "</td>";
            echo "<td style='background-color:#c4bd97'>" . pyutc_h($driverB) . "</td>";
            echo "<td style='background-color:#b8cce4'>" . pyutc_h($driverC) . "</td>";
            echo "<td style='background-color:#d8e4bc'>" . pyutc_h($driverD) . "</td>";
            echo "<td style='background-color:#b7dee8'>" . pyutc_h($row['entryDate'] ?? '') . "</td>";
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
            echo "<td style='width:175px;background-color:#b7dee8' rowspan='2'>" . pyutc_h($segmentDisplay) . "</td>";

            foreach ($fieldOrder as $field) {
                $fieldBg =
                    $field === 'driverA' ? '#d9d9d9' :
                    ($field === 'driverB' ? '#c4bd97' :
                    ($field === 'driverC' ? '#b8cce4' : '#d8e4bc'));

                if ($field === $changedField) {
                    echo "<td style='background-color:" . $fieldBg . "'>" . pyutc_h($reference[$field] ?? '') . "</td>";
                } else {
                    echo "<td class='pyutc-rd-merged' style='background-color:" . $fieldBg . "' rowspan='2'>" . pyutc_h($row[$field] ?? '') . "</td>";
                }
            }

            echo "<td style='background-color:#b7dee8'>" . pyutc_h($reference['entryDate'] ?? $row['entryDate'] ?? '') . "</td>";
            echo "</tr>";

            $changedFieldBg =
                $changedField === 'driverA' ? '#d9d9d9' :
                ($changedField === 'driverB' ? '#c4bd97' :
                ($changedField === 'driverC' ? '#b8cce4' : '#d8e4bc'));

            $replacementValue = trim((string)($row[$changedField] ?? ''));
            if ($marker !== '') {
                $replacementValue .= ' ' . $marker;
            }

            echo "<tr>";
            echo "<td style='background-color:" . $changedFieldBg . "'>" . pyutc_h($replacementValue) . "</td>";
            echo "<td style='background-color:#b7dee8'>" . pyutc_h($row['entryDate'] ?? '') . "</td>";
            echo "</tr>";
        }
    }
}

if (!empty($chartContext['notes'])) {
    echo "<tfoot>";
    echo "<tr>";
    echo "<td colspan='6' class='pyutc-notes-row'>";
    foreach ($chartContext['notes'] as $note) {
        echo "<div class='pyutc-note-line'>" . pyutc_h($note['marker'] . ' ' . $note['text']) . "</div>";
    }
    echo "</td>";
    echo "</tr>";
    echo "</tfoot>";
}

echo "</table>";
?>
