<?php
declare(strict_types=1);

ob_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';

// disableCaching() defined in functions_mrl.php
disableCaching();

// visual id of sandbox/test site only
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$docRoot = strtolower($_SERVER['DOCUMENT_ROOT'] ?? '');

$isTestSite =
    strpos($host, 'testphp8') !== false ||
    strpos($docRoot, 'testphp8') !== false;

if ($isTestSite) {
    $sandboxFile = $_SERVER['DOCUMENT_ROOT'] . '/sandbox.html';
    if (is_file($sandboxFile)) {
        require_once $sandboxFile;
    }
}

/**
 * weekly_standings.php
 *
 * VERSION: v060
 * LAST MODIFIED: 6/25/2026 3:31:00 pm
 *
 * CHANGELOG:
 *
 * v060 (6/25/2026 3:31:00 pm)
 *   - CHANGE: As-of button now opens standings_timeline_lite.php for a public-friendly as-of view.
 *
 * v059 (6/25/2026 2:34:45 pm)
 *   - NEW: Adds stable second-row release-version controls using release-history metadata: Current/Superseded, v1/v2/v3 selector, and compact change-label pill.
 *   - NEW: Selected race versions can be opened directly on weekly standings using release-history snapshot metadata while keeping the familiar weekly page layout.
 *   - NEW: Adds an As-of link to standings_timeline_lite.php for the selected release/version.
 *
 * v058 (6/21/26 7:46:06 am)
 *   - CHANGE: Release-history ladder now shows current/newest releases first, matching revision-block reading order.
 *   - FIX: Audit ladder table wraps long change/status text within the audit panel instead of running past the panel edge.
 *
 * v058 (6/21/26 7:27:49 am)
 *   - CHANGE: Audit panel now shows a release-history ladder for the selected race/week so initial and updated releases are visible together.
 *   - CHANGE: Dropped the temporary sk suffix while continuing the v058 weekly audit-panel work.
 *   - CHANGE: Audit panel now supports multi-snapshot release history records with updated-release, supersedes, and change-summary metadata.
 *
 * v058sk (6/21/26)
 *   - CHANGE: Added an Audit button and expandable public audit trail panel beside Validation.
 *   - CHANGE: Weekly audit panel reads _weekly_standings_release_history.json when present and falls back to selected snapshot release metadata.
 *   - CHANGE: Public audit wording uses league-friendly release/update/supersedes language while keeping technical IDs hidden unless available.
 *
 * v057sk (6/11/26)
 *   - CHANGE: Added environment check to show sandbox text.
 *
 * v057 (6/9/2026)
 *   - FIX: Kept the same version and refreshed the timestamp for the historical-note/button-layout correction pass.
 *   - FIX: Restored the left-side top-control behavior and shortened the historical note slot so action buttons can stay on the top row.
 *   - FIX: Replaced the weekly spreadsheet export path with a pure-PHP XLSX writer to avoid a white-screen export failure.
 *   - CHANGE: Added Print and Spreadsheet controls for the clean four-table weekly standings report view.
 *   - CHANGE: Spreadsheet export now creates one XLSX worksheet with the four report tables arranged side-by-side to resemble the web page.
 *   - CHANGE: Print styling now hides controls, validation/debug content, and collapsed detail rows so only the clean report area prints.
 *
 * v056 (4/30/2026)
 *   - CHANGE: Weekly standings now reads revision_meta.json for race revision display labels.
 *   - CHANGE: Direct MRL-impacting revisions show their display tag, such as (Rev A), in the Race dropdown and main heading.
 *   - CHANGE: Downstream races after an MRL-impacting revision show an adjusted label only when that downstream race is not Pending Review.
 *   - CHANGE: Pending Review remains a separate button/banner and is not merged into the race heading.
 *
 * v055 (4/19/2026)
 *   - FIX: Weekly standings now defaults Live and direct race selection to the latest race that has an actual snapshot, preventing in-progress indexed races from rendering empty standings.
 *   - CHANGE: Race dropdown now shows only races with a saved snapshot so in-progress races do not appear selectable before standings are available.
 *   - CHANGE: Added snapshot timestamp beside the race heading using a dedicated snapshot-footnote style.
 *   - CHANGE: Appended any missing yearly roster teams from user_teams to weekly race rows as 0-point rows after normal scoring.
 *   - CHANGE: Missing-pick warnings now evaluate only within the currently selected segment.
 *
 * v054 (4/14/2026)
 *   - CHANGE: Tied rows in weekly, segment, and year tables now share the same lowest numeric position and render those tied positions in bold.
 *   - CHANGE: Weekly winners keeps the first column as week number order and bolds duplicated week numbers only when that week has tied winners.
 *   - CHANGE: Weekly LP / RD markers now use a single shared marker per active condition type on the page.
 *   - CHANGE: If only one special-pick type exists on the page, it uses a single asterisk; if both LP and RD exist, LP uses * and RD uses **.
 *   - CHANGE: Weekly detail and grouped footnotes now use 'As of R##' wording and grouped headings like 'Late Pick:' / 'Replacement Driver:'.
 *   - FIX: Corrected year-table rank bolding so only true tied season positions render in bold.
 *
 * v053 (4/14/2026)
 *   - FIX: Restored the four-panel grid layout after the prior weekly footnote change disrupted panel structure.
 *   - FIX: Restored the weekly scoring table so the week panel renders normally again.
 *   - CHANGE: Weekly LP / RD notes now render below the weekly table as true footnotes with grouped headings.
 *   - CHANGE: Weekly, segment, year, and weekly-winner tie notes now share the same quieter italic footnote style.
 *
 * v051 (4/14/2026)
 *   - CHANGE: Weekly footnotes now use the same background treatment as the expanded team detail section.
 *   - CHANGE: Tie and LP / RD notes are now grouped into one compact footnote block with indented entries.
 *   - CHANGE: Dropped the word Effective from grouped LP / RD footnotes for cleaner scanning.
 *   - CHANGE: LP / RD conditions no longer generate WARN validation entries; only true warning conditions remain.
 *
 * v050 (4/14/2026)
 *   - CHANGE: Set all four report panels to equal width and aligned tables 1–3 more closely to Weekly Winners column proportions.
 *   - CHANGE: Weekly detail special-pick note now uses full wording without repeating the team name.
 *   - CHANGE: Bottom weekly special-pick notes now use compact LP / RD abbreviations for easier scanning.
 *   - CHANGE: Tie footnotes now use a quieter black / gray style instead of red.
 *   - CHANGE: If any tie exists anywhere on the page, single-asterisk is reserved for Tie and LP / RD markers begin at double-asterisk.
 *
 * v049 (4/14/2026)
 *   - CHANGE: Added LP / RD markers to weekly team rows and expanded driver detail rows.
 *   - CHANGE: Expanded weekly detail rows now show the full LP / RD note above the driver list when applicable.
 *   - CHANGE: Added stacked weekly LP / RD notes at the bottom of the weekly standings table.
 *   - CHANGE: Added tie markers to weekly, segment, and year standings rows, matching the existing Weekly Winners tie pattern.
 *
 * v048 (4/7/2026)
 *   - FIX: Live scoring special-pick overlay now uses only user_picks for LP / RD rows.
 *   - FIX: Removed user_picks_history from weekly scoring resolution so audit history no longer affects live results.
 *   - CHANGE: Preserved existing SEG base-row scoring before RD effective_race and existing LP pre-effective blanking behavior.
 *
 * v046 (4/3/2026)
 *   - FIX: Preserved original SEG row scoring before an RD row's effective_race.
 *   - FIX: Prevented future RD rows from incorrectly blanking pre-effective races as 'No Picks'.
 *   - CHANGE: LP pre-effective blanking still works, but RD now correctly overlays only from its effective_race forward.
 *
 * v045 (4/1/2026)
 *   - CHANGE: Added cumulative validation warnings for missed races with no active picks through the selected race.
 *   - CHANGE: Weekly detail rows now show 'No Picks' instead of four zero-value driver rows when no active picks exist.
 *   - CHANGE: Weekly detail rows now show 'No Picks (LP effective R##)' when an LP row exists but has not taken effect yet.
 *
 * v044 (3/31/2026)
 *   - CHANGE: Weekly scoring now starts from normal segment picks and overlays LP / RD rows race-by-race.
 *   - CHANGE: LP rows now correctly score 0 before effective_race while other teams keep normal scores.
 *   - CHANGE: Special-pick overlay logic now affects only teams with LP / RD rows instead of replacing the full segment team set.
 *   - CHANGE: Validation warns when LP or RD rows affect the selected race.
 *
 * v042 (3/23/2026)
 *   - CHANGE: Unified row striping across all four tables.
 *   - CHANGE: Three CSS variables now control all row colors from one place:
 *       --row-odd    (default white, odd rows all tables)
 *       --row-even   (stripe color, even rows all tables)
 *       --row-detail (expanded detail section background)
 *   - CHANGE: Added labeled comment block for easy discovery of color variables.
 *   - CHANGE: stripe-a/stripe-b retained for tables with expandable detail rows.
 *   - CHANGE: Default td background set to var(--row-odd) to prevent page bleed-through.
 *
 * v041 (3/23/2026)
 *   - CHANGE: Added under_review.flag support to indicate a race is Pending Review when the flag file exists in the race folder.
 *   - CHANGE: Added "⚠ Pending Review" button next to the Validation button with expandable detail panel.
 *   - CHANGE: Pending Review indicator is controlled by presence of under_review.flag and can apply to any race.
 *
 * v040 (3/18/2026)
 *   - CHANGE: Added a Live button before the dropdowns that jumps to the latest year + latest race and dims when already on the live view.
 *   - CHANGE: Removed the visible Year and Race labels from the top controls for a cleaner layout.
 *   - CHANGE: Year dropdown now displays earliest → latest while initial page load still defaults to the most current year.
 *   - CHANGE: Race dropdown now displays races in ascending order (R01 → R36) top to bottom.
 *   - CHANGE: Year change now clears the screen and resets Race to a neutral "Select Race" state.
 *   - CHANGE: Race dropdown is now the trigger; selecting a race submits without a separate Show button.
 *   - CHANGE: Added subtle placeholder text when no race is selected: "Select a race to view results".
 *   - CHANGE: Nav arrows, validation button, and historical note now reset cleanly in the no-race-selected state.
 *   - CHANGE: Initial page load still defaults to the most current year and most current race.
 *   - CHANGE: Kept sandbox include line as a commented on/off switch.
 *   - CHANGE: Segment detail rows now list races in ascending order.
 *   - CHANGE: Year table now supports expandable segment-total detail rows through the selected segment.
 *   - CHANGE: Weekly Winners ties now render one winner per row.
 *   - CHANGE: Weekly Winners tied rows now mark the week number with an asterisk and show a footnote when ties exist.
 *
 * v039 (3/16/2026)
 *   - Added navigation arrows (<< >>) for race cycling.
 *   - Added historical disclaimer message for pre-2026 races.
 *   - Updated race naming for consistency and layout stability:
 *       - Circuit of the Americas → COTA
 *       - Indianapolis Road Course → Indianapolis RC
 *       - Charlotte Road Course → Charlotte RC
 *       - World Wide Technology Raceway → World Wide Technology
 *   - Improved dropdown behavior by repopulating Race when Year changes without auto-submitting.
 *   - Replaced the old status indicator with the colored Show Validation / Hide Validation button.
 *   - Synced validation toggle behavior with race navigation and cleaned up related CSS.
 *
 * v038 (3/15/2026)
 *   - CHANGE: Warning logic now reports only zero-point drivers that belong to an actual MRL team pick.
 *   - CHANGE: WARN messages now list the specific zero-point driver and MRL team instead of using a generic count.
 *   - CHANGE: Debug details table Race and Computed Winner columns now left-align for easier scanning.
 *   - CHANGE: Restored light background styling across the full expanded weekly driver detail area.
 *   - CHANGE: Added a small amount of top and bottom padding to the expanded weekly driver detail area.
 *   - CHANGE: Nudged expanded weekly driver detail point values slightly left for better visual balance.
 *   - CHANGE: Added a subtle tinted background behind expanded weekly driver detail point cells.
 *
 * v055 (4/18/2026)
 * - CHANGE: Added selected race snapshot timestamp beside the Table 1 race title using a dedicated snapshot-footnote style.
 *
 * PHP: 7.3 compatible.
 */


// helper files
require_once __DIR__ . '/race_results_team_helper.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';
require_once __DIR__ . '/race_results_engine.php';
require_once __DIR__ . '/weekly_standings_release_history_helper.php';

function rrsg_h($val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}


function rrsg_ranked_summary_rows(array $rows, string $scoreKey, array $tieMap = [], array $markersByTeam = []): array
{
    $out = [];
    $rank = 1;
    $displayRank = 0;
    $prevRankScore = null;

    foreach ($rows as $row) {
        $currentRankScore = (int)($row[$scoreKey] ?? 0);
        if ($prevRankScore === null || $currentRankScore !== $prevRankScore) {
            $displayRank = $rank;
            $prevRankScore = $currentRankScore;
        }

        $teamName = (string)($row['teamName'] ?? '');
        $marker = (string)($markersByTeam[$teamName] ?? '');

        $out[] = [
            'rank' => (string)$displayRank,
            'teamName' => $teamName,
            'marker' => $marker,
            'score' => $currentRankScore,
            'isTie' => isset($tieMap[$teamName]),
        ];

        $rank++;
    }

    return $out;
}

function rrsg_weekly_winner_summary_rows(array $pointRaces, array $weeklyWinners, int $selectedRaceNumber): array
{
    $winnerRows = $pointRaces;
    usort($winnerRows, function ($a, $b) {
        return ((int)$a['number']) <=> ((int)$b['number']);
    });

    $out = [];

    foreach ($winnerRows as $race) {
        $raceNumber = (int)($race['number'] ?? 0);
        if ($raceNumber > $selectedRaceNumber) {
            continue;
        }

        $raceCode = (string)($race['raceCode'] ?? '');
        $winnerNames = $weeklyWinners[$raceCode]['teamNames'] ?? [];
        $winnerPoints = (int)($weeklyWinners[$raceCode]['points'] ?? 0);

        if (empty($winnerNames)) {
            $fallbackWinner = (string)($weeklyWinners[$raceCode]['teamName'] ?? '');
            if ($fallbackWinner !== '') {
                $winnerNames[] = $fallbackWinner;
            }
        }

        $winnerIsTieWeek = (count($winnerNames) > 1);

        if (empty($winnerNames)) {
            $out[] = [
                'week' => (string)$raceNumber,
                'winner' => '',
                'points' => 0,
                'isTie' => false,
            ];
        } else {
            foreach ($winnerNames as $winnerName) {
                $out[] = [
                    'week' => (string)$raceNumber,
                    'winner' => (string)$winnerName,
                    'points' => $winnerPoints,
                    'isTie' => $winnerIsTieWeek,
                ];
            }
        }
    }

    return $out;
}

function rrsg_xlsx_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function rrsg_xlsx_col_letter(int $col): string
{
    $col = max(1, $col);
    $letter = '';
    while ($col > 0) {
        $mod = ($col - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $col = (int)(($col - $mod) / 26);
    }
    return $letter;
}

function rrsg_xlsx_cell_xml(string $cellRef, $value, int $styleIndex, bool $isNumeric = false): string
{
    if ($isNumeric && is_numeric($value)) {
        return '<c r="' . rrsg_xlsx_xml($cellRef) . '" s="' . $styleIndex . '"><v>' . rrsg_xlsx_xml((string)$value) . '</v></c>';
    }

    return '<c r="' . rrsg_xlsx_xml($cellRef) . '" t="inlineStr" s="' . $styleIndex . '"><is><t>' . rrsg_xlsx_xml((string)$value) . '</t></is></c>';
}

function rrsg_zip_dos_parts(?int $timestamp = null): array
{
    $timestamp = $timestamp ?? time();
    $year = (int)date('Y', $timestamp);
    $month = (int)date('n', $timestamp);
    $day = (int)date('j', $timestamp);
    $hour = (int)date('G', $timestamp);
    $minute = (int)date('i', $timestamp);
    $second = (int)date('s', $timestamp);

    $dosTime = ($hour << 11) | ($minute << 5) | (int)floor($second / 2);
    $dosDate = (($year - 1980) << 9) | ($month << 5) | $day;

    return [$dosTime, $dosDate];
}

function rrsg_zip_from_strings(array $files): string
{
    $zipData = '';
    $centralDirectory = '';
    $offset = 0;
    [$dosTime, $dosDate] = rrsg_zip_dos_parts();

    foreach ($files as $name => $data) {
        $name = str_replace('\\', '/', (string)$name);
        $data = (string)$data;
        $nameLength = strlen($name);
        $dataLength = strlen($data);
        $crc = crc32($data);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $dataLength,
            $dataLength,
            $nameLength,
            0
        );

        $zipData .= $localHeader . $name . $data;

        $centralDirectory .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $dataLength,
            $dataLength,
            $nameLength,
            0,
            0,
            0,
            0,
            0,
            $offset
        ) . $name;

        $offset += strlen($localHeader) + $nameLength + $dataLength;
    }

    $centralOffset = strlen($zipData);
    $centralSize = strlen($centralDirectory);
    $fileCount = count($files);

    $endOfCentralDirectory = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $fileCount,
        $fileCount,
        $centralSize,
        $centralOffset,
        0
    );

    return $zipData . $centralDirectory . $endOfCentralDirectory;
}

function rrsg_send_weekly_standings_xlsx(string $filenameBase, array $tables, string $footerText): void
{
    /*
     * Pure-PHP XLSX export.
     *
     * team_chart.php uses PhpSpreadsheet, but this page intentionally writes a
     * small XLSX package directly so export is not dependent on a vendor load
     * path and does not fail as a blank white page when that path is unavailable.
     */
    $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filenameBase);
    if ($safeBase === '') {
        $safeBase = 'weekly_standings';
    }
    $filename = $safeBase . '.xlsx';

    $tableStarts = [1, 5, 9, 13];
    $cellsByRow = [];
    $mergeRanges = [];
    $maxLastRow = 1;

    foreach ($tables as $idx => $table) {
        $startCol = $tableStarts[$idx] ?? (1 + ($idx * 4));
        $endCol = $startCol + 2;
        $startColLetter = rrsg_xlsx_col_letter($startCol);
        $endColLetter = rrsg_xlsx_col_letter($endCol);

        $titleRef = $startColLetter . '1';
        $cellsByRow[1][] = rrsg_xlsx_cell_xml($titleRef, (string)($table['title'] ?? ''), 1, false);
        $mergeRanges[] = $startColLetter . '1:' . $endColLetter . '1';

        $headers = $table['headers'] ?? [];
        for ($c = 0; $c < 3; $c++) {
            $cellRef = rrsg_xlsx_col_letter($startCol + $c) . '2';
            $cellsByRow[2][] = rrsg_xlsx_cell_xml($cellRef, (string)($headers[$c] ?? ''), 2, false);
        }

        $rowNum = 3;
        $rows = $table['rows'] ?? [];
        if (empty($rows)) {
            $cellsByRow[$rowNum][] = rrsg_xlsx_cell_xml($startColLetter . $rowNum, 'No rows generated.', 8, false);
            $mergeRanges[] = $startColLetter . $rowNum . ':' . $endColLetter . $rowNum;
            $rowNum++;
        } else {
            foreach ($rows as $dataRow) {
                $values = $dataRow['values'] ?? [];
                $isEvenStripe = (($rowNum - 3) % 2 === 1);
                for ($c = 0; $c < 3; $c++) {
                    $colIndex = $startCol + $c;
                    $cellRef = rrsg_xlsx_col_letter($colIndex) . $rowNum;
                    $value = $values[$c] ?? '';
                    $isTeamColumn = ($c === 1);
                    $isNumeric = ($c === 2 && is_numeric($value));
                    $boldThisCell = (($c === 0 && !empty($dataRow['boldFirst'])) || ($c === 1 && !empty($dataRow['boldTeam'])));

                    if ($isTeamColumn) {
                        $styleIndex = $isEvenStripe ? ($boldThisCell ? 11 : 9) : ($boldThisCell ? 10 : 8);
                    } else {
                        $styleIndex = $isEvenStripe ? ($boldThisCell ? 7 : 4) : ($boldThisCell ? 6 : 3);
                    }

                    $cellsByRow[$rowNum][] = rrsg_xlsx_cell_xml($cellRef, $value, $styleIndex, $isNumeric);
                }
                $rowNum++;
            }
        }

        $maxLastRow = max($maxLastRow, $rowNum - 1);
    }

    $footerRow = $maxLastRow + 3;
    $cellsByRow[$footerRow][] = rrsg_xlsx_cell_xml('A' . $footerRow, $footerText, 5, false);
    $mergeRanges[] = 'A' . $footerRow . ':O' . $footerRow;

    ksort($cellsByRow, SORT_NUMERIC);
    $sheetRows = '';
    foreach ($cellsByRow as $rowNum => $cellXmlParts) {
        $sheetRows .= '<row r="' . (int)$rowNum . '">' . implode('', $cellXmlParts) . '</row>';
    }

    $mergeXml = '';
    if (!empty($mergeRanges)) {
        $mergeXml .= '<mergeCells count="' . count($mergeRanges) . '">';
        foreach ($mergeRanges as $range) {
            $mergeXml .= '<mergeCell ref="' . rrsg_xlsx_xml($range) . '"/>';
        }
        $mergeXml .= '</mergeCells>';
    }

    $worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>'
        . '<col min="1" max="1" width="7" customWidth="1"/><col min="2" max="2" width="25" customWidth="1"/><col min="3" max="3" width="10" customWidth="1"/><col min="4" max="4" width="2" customWidth="1"/>'
        . '<col min="5" max="5" width="7" customWidth="1"/><col min="6" max="6" width="25" customWidth="1"/><col min="7" max="7" width="10" customWidth="1"/><col min="8" max="8" width="2" customWidth="1"/>'
        . '<col min="9" max="9" width="7" customWidth="1"/><col min="10" max="10" width="25" customWidth="1"/><col min="11" max="11" width="10" customWidth="1"/><col min="12" max="12" width="2" customWidth="1"/>'
        . '<col min="13" max="13" width="7" customWidth="1"/><col min="14" max="14" width="25" customWidth="1"/><col min="15" max="15" width="10" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . $mergeXml
        . '<pageMargins left="0.25" right="0.25" top="0.25" bottom="0.25" header="0.3" footer="0.3"/>'
        . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Arial"/></font>'
        . '<font><b/><sz val="11"/><name val="Arial"/></font>'
        . '<font><sz val="9"/><color rgb="FF666666"/><name val="Arial"/></font>'
        . '<font><b/><sz val="11"/><name val="Arial"/></font>'
        . '</fonts>'
        . '<fills count="5">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFBFF00"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD2E5F7"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FF151313"/></left><right style="thin"><color rgb="FF151313"/></right><top style="thin"><color rgb="FF151313"/></top><bottom style="thin"><color rgb="FF151313"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="12">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Weekly Standings" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $created = gmdate('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>Manlius Racing League</dc:creator><cp:lastModifiedBy>Manlius Racing League</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Manlius Racing League</Application>'
        . '</Properties>';

    $xlsxBinary = rrsg_zip_from_strings([
        '[Content_Types].xml' => $contentTypesXml,
        '_rels/.rels' => $rootRelsXml,
        'docProps/core.xml' => $coreXml,
        'docProps/app.xml' => $appXml,
        'xl/workbook.xml' => $workbookXml,
        'xl/_rels/workbook.xml.rels' => $workbookRelsXml,
        'xl/styles.xml' => $stylesXml,
        'xl/worksheets/sheet1.xml' => $worksheetXml,
    ]);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('Content-Length: ' . strlen($xlsxBinary));

    echo $xlsxBinary;
    exit;
}

function rrsg_driver_net(array $driverPoints, string $driverName): int
{
    if ($driverName === '') {
        return 0;
    }

    if (!isset($driverPoints[$driverName]) || !is_array($driverPoints[$driverName])) {
        return 0;
    }

    return (int)($driverPoints[$driverName]['net'] ?? 0);
}

function rrsg_sort_weekly_rows(array &$rows): void
{
    usort($rows, function ($a, $b) {
        $aTotal = (int)($a['weeklyTotal'] ?? 0);
        $bTotal = (int)($b['weeklyTotal'] ?? 0);

        if ($aTotal !== $bTotal) {
            return ($bTotal <=> $aTotal);
        }

        return strcasecmp((string)($a['teamName'] ?? ''), (string)($b['teamName'] ?? ''));
    });
}

function rrsg_sort_total_rows(array $totals): array
{
    $rows = [];

    foreach ($totals as $teamName => $total) {
        $rows[] = [
            'teamName' => (string)$teamName,
            'total' => (int)$total,
        ];
    }

    usort($rows, function ($a, $b) {
        $aTotal = (int)$a['total'];
        $bTotal = (int)$b['total'];

        if ($aTotal !== $bTotal) {
            return ($bTotal <=> $aTotal);
        }

        return strcasecmp((string)$a['teamName'], (string)$b['teamName']);
    });

    return $rows;
}

function rrsg_find_snapshot_file(string $raceFolder): string
{
    if (!is_dir($raceFolder)) {
        return '';
    }

    $files = glob($raceFolder . '/snapshot_*.html');
    if (!is_array($files) || empty($files)) {
        return '';
    }

    sort($files, SORT_STRING);
    return (string)end($files);
}

function rrsg_filter_races_with_snapshots(array $races): array
{
    $result = [];

    foreach ($races as $race) {
        $raceFolder = (string)($race['raceFolder'] ?? '');
        if ($raceFolder === '') {
            continue;
        }

        if (rrsg_find_snapshot_file($raceFolder) === '') {
            continue;
        }

        $result[] = $race;
    }

    return $result;
}

function rrsg_load_revision_meta(string $raceFolder): array
{
    $path = rtrim($raceFolder, '/\\') . '/revision_meta.json';
    if (!is_file($path)) {
        return [];
    }

    $data = rr_load_json($path);
    return is_array($data) ? $data : [];
}

function rrsg_race_is_pending_review(string $raceFolder): bool
{
    return is_file(rtrim($raceFolder, '/\\') . '/under_review.flag');
}

function rrsg_revision_display_tag_from_meta(array $meta): string
{
    if (empty($meta)) {
        return '';
    }

    if (empty($meta['display_rev']) || empty($meta['mrl_impact'])) {
        return '';
    }

    $tag = trim((string)($meta['display_tag'] ?? ''));
    return $tag !== '' ? $tag : '';
}

function rrsg_direct_revision_tag_for_race(array $race): string
{
    $raceFolder = (string)($race['raceFolder'] ?? '');
    if ($raceFolder === '') {
        return '';
    }

    return rrsg_revision_display_tag_from_meta(rrsg_load_revision_meta($raceFolder));
}

function rrsg_downstream_revision_tag_for_race(array $targetRace, array $pointRaces): string
{
    $targetNumber = (int)($targetRace['number'] ?? 0);
    $targetFolder = (string)($targetRace['raceFolder'] ?? '');

    if ($targetNumber <= 0 || $targetFolder === '') {
        return '';
    }

    // Downstream/adjusted labeling waits until the downstream race itself is no longer Pending Review.
    if (rrsg_race_is_pending_review($targetFolder)) {
        return '';
    }

    $bestSourceNumber = 0;
    $bestSourceTag = '';

    foreach ($pointRaces as $sourceRace) {
        $sourceNumber = (int)($sourceRace['number'] ?? 0);
        if ($sourceNumber <= 0 || $sourceNumber >= $targetNumber) {
            continue;
        }

        $sourceTag = rrsg_direct_revision_tag_for_race($sourceRace);
        if ($sourceTag === '') {
            continue;
        }

        if ($sourceNumber > $bestSourceNumber) {
            $bestSourceNumber = $sourceNumber;
            $bestSourceTag = $sourceTag;
        }
    }

    if ($bestSourceTag === '') {
        return '';
    }

    return 'Adjusted ' . $bestSourceTag;
}

function rrsg_revision_suffix_for_race(array $race, array $pointRaces): string
{
    $directTag = rrsg_direct_revision_tag_for_race($race);
    if ($directTag !== '') {
        return ' (' . $directTag . ')';
    }

    $downstreamTag = rrsg_downstream_revision_tag_for_race($race, $pointRaces);
    if ($downstreamTag !== '') {
        return ' (' . $downstreamTag . ')';
    }

    return '';
}

function rrsg_revision_display_label_for_race(array $race, array $pointRaces): string
{
    return (string)$race['raceCode']
        . ' '
        . rrsg_short_race_label((string)$race['raceName'])
        . rrsg_revision_suffix_for_race($race, $pointRaces);
}

function rrsg_find_latest_available_view(array $availableYears, string $baseDir): array
{
    foreach ($availableYears as $yearOpt) {
        $yearFolder = $baseDir . '/' . $yearOpt;
        $yearIndexFile = $yearFolder . '/_year_index.json';
        $yearIndex = rrsg_load_year_index_file($yearIndexFile);
        $pointRaces = rrsg_points_races_from_index($yearIndex, $yearFolder);
        $selectablePointRaces = rrsg_filter_races_with_snapshots($pointRaces);

        if (!empty($selectablePointRaces)) {
            return [
                'year' => (string)$yearOpt,
                'raceCode' => (string)$selectablePointRaces[0]['raceCode'],
            ];
        }
    }

    return [
        'year' => (!empty($availableYears) ? (string)$availableYears[0] : ''),
        'raceCode' => '',
    ];
}


function rrsg_format_snapshot_timestamp(string $snapshotFile): string
{
    $base = basename($snapshotFile);
    if (!preg_match('/^snapshot_(\d{8})_(\d{6})\d*\.html$/', $base, $m)) {
        return '';
    }

    $dt = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new DateTimeZone('America/New_York'));
    if (!$dt) {
        return '';
    }

    return $dt->format('n/j/y g:ia');
}

function rrsg_snapshot_id_from_file(string $snapshotFile): string
{
    $base = basename($snapshotFile);
    if (!preg_match('/^snapshot_(\d{8}_\d{6}\d*)\.html$/', $base, $m)) {
        return '';
    }

    return (string)$m[1];
}

function rrsg_snapshot_raw_datetime_from_id(string $snapshotId): string
{
    if (!preg_match('/^(\d{8})_(\d{6})/', $snapshotId, $m)) {
        return '';
    }

    $dt = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new DateTimeZone('America/New_York'));
    if (!$dt) {
        return '';
    }

    return $dt->format('Y-m-d H:i:s');
}

function rrsg_format_public_datetime(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $tz = new DateTimeZone('America/New_York');
    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s',
        'm/d/Y H:i:s',
        'n/j/Y g:i:s a',
        'n/j/Y g:i a',
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $raw, $tz);
        if ($dt instanceof DateTime) {
            return $dt->format('M j, Y g:i A');
        }
    }

    try {
        $dt = new DateTime($raw, $tz);
        return $dt->format('M j, Y g:i A');
    } catch (Exception $e) {
        return $raw;
    }
}

function rrsg_load_weekly_release_history(string $yearFolder): array
{
    $path = rtrim($yearFolder, '/\\') . '/_weekly_standings_release_history.json';
    if (!is_file($path)) {
        return [];
    }

    $data = rr_load_json($path);
    return is_array($data) ? $data : [];
}

function rrsg_release_history_rows(array $history): array
{
    if (isset($history['releases']) && is_array($history['releases'])) {
        return $history['releases'];
    }

    if (isset($history['rows']) && is_array($history['rows'])) {
        return $history['rows'];
    }

    return [];
}

function rrsg_find_release_meta_for_race(array $history, string $raceCode, string $snapshotId): array
{
    $rows = rrsg_release_history_rows($history);
    $fallback = [];
    $exact = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ((string)($row['race_code'] ?? '') !== $raceCode) {
            continue;
        }

        $fallback = $row;
        $rowSnapshotId = (string)($row['snapshot_id'] ?? '');
        $rowSourceSnapshotId = (string)($row['source_snapshot_id'] ?? '');
        $rowGeneratedId = (string)($row['generated_id'] ?? '');
        $rowReleaseId = (string)($row['release_id'] ?? '');

        if ($snapshotId !== '' && (
            $rowSnapshotId === $snapshotId ||
            $rowSourceSnapshotId === $snapshotId ||
            strpos($rowGeneratedId, $snapshotId . '_') === 0 ||
            strpos($rowReleaseId, $snapshotId . '_') === 0
        )) {
            $exact = $row;
        }
    }

    return !empty($exact) ? $exact : $fallback;
}


function rrsg_release_history_rows_for_race(array $history, string $raceCode): array
{
    $rows = [];

    foreach (rrsg_release_history_rows($history) as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ((string)($row['race_code'] ?? '') !== $raceCode) {
            continue;
        }

        $rows[] = $row;
    }

    usort($rows, function (array $a, array $b): int {
        $aTime = strtotime((string)($a['released_at'] ?? '')) ?: 0;
        $bTime = strtotime((string)($b['released_at'] ?? '')) ?: 0;

        if ($aTime !== $bTime) {
            return $aTime <=> $bTime;
        }

        $aSnapshot = (string)($a['snapshot_id'] ?? $a['generated_id'] ?? $a['release_id'] ?? '');
        $bSnapshot = (string)($b['snapshot_id'] ?? $b['generated_id'] ?? $b['release_id'] ?? '');
        return strcmp($aSnapshot, $bSnapshot);
    });

    return $rows;
}

function rrsg_release_history_current_id(array $meta): string
{
    return (string)($meta['generated_id'] ?? $meta['release_id'] ?? '');
}

function rrsg_release_history_ladder_rows(array $history, string $raceCode, string $currentReleaseId = ''): array
{
    $ladder = [];

    foreach (rrsg_release_history_rows_for_race($history, $raceCode) as $index => $row) {
        $releaseId = rrsg_release_history_current_id($row);
        $releasedDisplay = (string)($row['released_at_display'] ?? '');
        if ($releasedDisplay === '') {
            $releasedDisplay = rrsg_format_public_datetime((string)($row['released_at'] ?? ''));
        }

        $ladder[] = [
            'number' => $index + 1,
            'release_id' => $releaseId,
            'is_current' => ($currentReleaseId !== '' && $releaseId === $currentReleaseId),
            'release_type_label' => rrsg_audit_release_type_label($row),
            'released_display' => $releasedDisplay,
            'status_label' => (string)($row['public_status'] ?? $row['status_label'] ?? ''),
            'supersedes_label' => rrsg_audit_supersedes_label($row['supersedes'] ?? ''),
            'mrl_impact_label' => rrsg_audit_mrl_impact_label($row),
            'change_summary_label' => (string)($row['change_status_label'] ?? ''),
        ];
    }

    return array_reverse($ladder);
}

function rrsg_audit_release_type_label(array $meta): string
{
    $releaseType = strtolower(trim((string)($meta['release_type'] ?? '')));
    $sourceType = strtolower(trim((string)($meta['source_type'] ?? '')));

    if ($releaseType === 'revised' || $releaseType === 'updated' || $sourceType === 'indirect_revision' || $sourceType === 'direct_revision') {
        return 'Updated release';
    }

    if ($releaseType === 'pending_review') {
        return 'Pending review';
    }

    return 'Initial release';
}

function rrsg_audit_mrl_impact_label(array $meta): string
{
    if (!array_key_exists('mrl_impact', $meta) || $meta['mrl_impact'] === null || $meta['mrl_impact'] === '') {
        return 'Not applicable';
    }

    return !empty($meta['mrl_impact']) ? 'Yes' : 'No';
}

function rrsg_audit_supersedes_label($supersedes): string
{
    if (is_array($supersedes)) {
        $display = (string)($supersedes['released_at_display'] ?? '');
        if ($display !== '') {
            return $display . ' release';
        }

        $raw = (string)($supersedes['released_at'] ?? '');
        if ($raw !== '') {
            return rrsg_format_public_datetime($raw) . ' release';
        }

        $id = (string)($supersedes['release_id'] ?? $supersedes['generated_id'] ?? '');
        return $id !== '' ? $id : 'Prior release';
    }

    $text = trim((string)$supersedes);
    return $text !== '' ? $text : 'None';
}

function rrsg_build_public_audit_meta(array $selectedRaceMeta, ?array $selectedRace, bool $underReview, array $releaseHistory): array
{
    if ($selectedRace === null || (string)($selectedRaceMeta['raceCode'] ?? '') === '') {
        return [];
    }

    $snapshotFile = (string)($selectedRaceMeta['snapshotFile'] ?? '');
    $snapshotId = rrsg_snapshot_id_from_file($snapshotFile);
    $raceCode = (string)($selectedRaceMeta['raceCode'] ?? '');
    $raceLabel = (string)($selectedRaceMeta['raceLabel'] ?? '');
    $existing = rrsg_find_release_meta_for_race($releaseHistory, $raceCode, $snapshotId);

    if (!empty($existing)) {
        $releaseTypeLabel = rrsg_audit_release_type_label($existing);
        $releasedDisplay = (string)($existing['released_at_display'] ?? '');
        if ($releasedDisplay === '') {
            $releasedDisplay = rrsg_format_public_datetime((string)($existing['released_at'] ?? ''));
        }

        $reason = trim((string)($existing['reason_public'] ?? $existing['reason'] ?? ''));
        if ($reason === '') {
            $reason = ($releaseTypeLabel === 'Updated release')
                ? 'Earlier race results changed after this week was released.'
                : 'Official standings release.';
        }

        return [
            'release_type_label' => $releaseTypeLabel,
            'released_display' => $releasedDisplay,
            'status_label' => (string)($existing['public_status'] ?? $existing['status_label'] ?? ($underReview ? 'Pending league review' : 'Official standings release')),
            'reason' => $reason,
            'supersedes_label' => rrsg_audit_supersedes_label($existing['supersedes'] ?? ''),
            'mrl_impact_label' => rrsg_audit_mrl_impact_label($existing),
            'change_summary_label' => (string)($existing['change_status_label'] ?? ''),
            'generated_id' => (string)($existing['generated_id'] ?? $existing['release_id'] ?? ''),
            'caused_by' => (string)($existing['caused_by_public'] ?? $existing['caused_by_event_id'] ?? ''),
            'source_type' => (string)($existing['source_type'] ?? 'initial'),
            'changed_driver_details' => (isset($existing['changed_driver_details']) && is_array($existing['changed_driver_details'])) ? $existing['changed_driver_details'] : [],
            'release_ladder' => rrsg_release_history_ladder_rows($releaseHistory, $raceCode, (string)($existing['generated_id'] ?? $existing['release_id'] ?? '')),
        ];
    }

    $releasedRaw = rrsg_snapshot_raw_datetime_from_id($snapshotId);
    $releasedDisplay = rrsg_format_public_datetime($releasedRaw);
    if ($releasedDisplay === '') {
        $releasedDisplay = (string)($selectedRaceMeta['snapshotDisplay'] ?? '');
    }

    return [
        'release_type_label' => ($underReview ? 'Pending review' : 'Initial release'),
        'released_display' => $releasedDisplay,
        'status_label' => ($underReview ? 'Pending league review' : 'Official standings release'),
        'reason' => ($underReview
            ? 'Results generated automatically and awaiting league release.'
            : 'Initial standings release for ' . $raceCode . ($raceLabel !== '' ? ' ' . $raceLabel : '') . '.'),
        'supersedes_label' => 'None',
        'mrl_impact_label' => 'Not applicable',
        'change_summary_label' => '',
        'generated_id' => ($snapshotId !== '' ? $snapshotId . '_' . $raceCode : ''),
        'caused_by' => '',
        'source_type' => 'initial',
        'changed_driver_details' => [],
        'release_ladder' => rrsg_release_history_ladder_rows($releaseHistory, $raceCode, ($snapshotId !== '' ? $snapshotId . '_' . $raceCode : '')),
    ];
}

function rrsg_build_weekly_rows(array $teamRows, array $driverPoints): array
{
    $weeklyRows = [];

    foreach ($teamRows as $team) {
        $driverA = (string)($team['driverA'] ?? '');
        $driverB = (string)($team['driverB'] ?? '');
        $driverC = (string)($team['driverC'] ?? '');
        $driverD = (string)($team['driverD'] ?? '');

        $netA = rrsg_driver_net($driverPoints, $driverA);
        $netB = rrsg_driver_net($driverPoints, $driverB);
        $netC = rrsg_driver_net($driverPoints, $driverC);
        $netD = rrsg_driver_net($driverPoints, $driverD);

        $weeklyTotal = $netA + $netB + $netC + $netD;

        $weeklyRows[] = [
            'teamName' => (string)($team['teamName'] ?? ''),
            'userName' => (string)($team['userName'] ?? ''),
            'driverA' => $driverA,
            'driverB' => $driverB,
            'driverC' => $driverC,
            'driverD' => $driverD,
            'netA' => $netA,
            'netB' => $netB,
            'netC' => $netC,
            'netD' => $netD,
            'weeklyTotal' => $weeklyTotal,
            'pick_type' => (string)($team['pick_type'] ?? ''),
            'effective_race' => (int)($team['effective_race'] ?? 0),
            'original_driverA' => (string)($team['original_driverA'] ?? ''),
            'original_driverB' => (string)($team['original_driverB'] ?? ''),
            'original_driverC' => (string)($team['original_driverC'] ?? ''),
            'original_driverD' => (string)($team['original_driverD'] ?? ''),
        ];
    }

    rrsg_sort_weekly_rows($weeklyRows);
    return $weeklyRows;
}


function rrsg_get_year_team_roster(string $raceYear, $dbo): array
{
    if (!($dbo instanceof PDO)) {
        return [];
    }

    $sql = "
        SELECT
            ut.userID,
            ut.teamName,
            COALESCE(u.userName, '') AS userName
        FROM user_teams ut
        LEFT JOIN users u ON u.userID = ut.userID
        WHERE ut.raceYear = :raceYear
        ORDER BY ut.teamName ASC, ut.userID ASC
    ";

    $stmt = $dbo->prepare($sql);
    $stmt->execute([
        ':raceYear' => $raceYear,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }

    $roster = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $teamName = trim((string)($row['teamName'] ?? ''));
        if ($teamName === '') {
            continue;
        }

        $roster[$teamName] = [
            'userID' => (int)($row['userID'] ?? 0),
            'teamName' => $teamName,
            'userName' => (string)($row['userName'] ?? ''),
        ];
    }

    ksort($roster, SORT_NATURAL | SORT_FLAG_CASE);
    return $roster;
}

function rrsg_append_missing_roster_rows(array $weeklyRows, array $roster): array
{
    if (empty($roster)) {
        return $weeklyRows;
    }

    $seenTeams = [];
    foreach ($weeklyRows as $row) {
        $teamName = trim((string)($row['teamName'] ?? ''));
        if ($teamName !== '') {
            $seenTeams[strtolower($teamName)] = true;
        }
    }

    foreach ($roster as $teamName => $rosterRow) {
        $teamKey = strtolower((string)$teamName);
        if (isset($seenTeams[$teamKey])) {
            continue;
        }

        $weeklyRows[] = [
            'teamName' => (string)$teamName,
            'userName' => (string)($rosterRow['userName'] ?? ''),
            'driverA' => '',
            'driverB' => '',
            'driverC' => '',
            'driverD' => '',
            'netA' => 0,
            'netB' => 0,
            'netC' => 0,
            'netD' => 0,
            'weeklyTotal' => 0,
            'pick_type' => 'MISS',
            'effective_race' => 0,
            'original_driverA' => '',
            'original_driverB' => '',
            'original_driverC' => '',
            'original_driverD' => '',
        ];
    }

    rrsg_sort_weekly_rows($weeklyRows);
    return $weeklyRows;
}

function rrsg_get_weekly_winner(array $weeklyRows): array
{
    if (empty($weeklyRows)) {
        return [
            'teamName' => '',
            'teamNames' => [],
            'points' => 0,
        ];
    }

    $topPoints = (int)($weeklyRows[0]['weeklyTotal'] ?? 0);
    $winnerNames = [];

    foreach ($weeklyRows as $row) {
        $weeklyTotal = (int)($row['weeklyTotal'] ?? 0);
        if ($weeklyTotal !== $topPoints) {
            break;
        }

        $winnerNames[] = (string)($row['teamName'] ?? '');
    }

    return [
        'teamName' => implode(' / ', $winnerNames),
        'teamNames' => $winnerNames,
        'points' => $topPoints,
    ];
}

function rrsg_segment_from_race_number(int $raceNumber): string
{
    if ($raceNumber >= 1 && $raceNumber <= 8) {
        return 'S1';
    }
    if ($raceNumber >= 9 && $raceNumber <= 17) {
        return 'S2';
    }
    if ($raceNumber >= 18 && $raceNumber <= 26) {
        return 'S3';
    }
    if ($raceNumber >= 27 && $raceNumber <= 36) {
        return 'S4';
    }

    return 'S1';
}

function rrsg_segment_bounds(string $segment): array
{
    if ($segment === 'S1') return ['start' => 1, 'end' => 8];
    if ($segment === 'S2') return ['start' => 9, 'end' => 17];
    if ($segment === 'S3') return ['start' => 18, 'end' => 26];
    if ($segment === 'S4') return ['start' => 27, 'end' => 36];

    return ['start' => 1, 'end' => 8];
}

function rrsg_available_years(string $baseDir): array
{
    $years = [];
    $items = scandir($baseDir);

    if (!is_array($items)) {
        return [];
    }

    foreach ($items as $name) {
        if (!preg_match('/^\d{4}$/', (string)$name)) {
            continue;
        }

        $yearFolder = $baseDir . '/' . $name;
        if (!is_dir($yearFolder)) {
            continue;
        }

        if (is_file($yearFolder . '/_year_index.json')) {
            $years[] = (string)$name;
        }
    }

    rsort($years, SORT_STRING);
    return $years;
}

function rrsg_load_year_index_file(string $path): array
{
    $idx = rr_load_json($path);
    if (!is_array($idx)) return [];
    if (!isset($idx['races']) || !is_array($idx['races'])) return [];
    return $idx;
}

function rrsg_points_races_from_index(array $yearIndex, string $yearBaseFolder): array
{
    $rows = [];

    foreach ($yearIndex['races'] as $raceId => $row) {
        if (!is_array($row)) continue;

        $kind = (string)($row['kind'] ?? '');
        if ($kind !== 'R') continue;

        $number = (int)($row['number'] ?? 0);
        $folder = (string)($row['folder'] ?? '');
        $raceName = (string)($row['race_name'] ?? '');
        $raceUrl = (string)($row['race_url'] ?? '');

        if ($number <= 0 || $folder === '') continue;

        $rows[] = [
            'raceId' => (string)$raceId,
            'kind' => $kind,
            'number' => $number,
            'raceCode' => 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'raceName' => $raceName,
            'raceUrl' => $raceUrl,
            'folder' => $folder,
            'raceFolder' => $yearBaseFolder . '/' . $folder,
        ];
    }

    usort($rows, function ($a, $b) {
        $an = (int)$a['number'];
        $bn = (int)$b['number'];

        if ($an !== $bn) {
            return ($bn <=> $an);
        }

        return strcmp((string)$a['raceId'], (string)$b['raceId']);
    });

    return $rows;
}

function rrsg_sort_races_ascending(array $races): array
{
    usort($races, function ($a, $b) {
        $an = (int)($a['number'] ?? 0);
        $bn = (int)($b['number'] ?? 0);

        if ($an !== $bn) {
            return ($an <=> $bn);
        }

        return strcmp((string)($a['raceCode'] ?? ''), (string)($b['raceCode'] ?? ''));
    });

    return $races;
}

function rrsg_short_race_label(string $raceName): string
{
    $slug = rr_sanitize_for_folder($raceName);

    $map = [
        'EchoPark_Automotive_Grand_Prix' => 'COTA',
        'NASCAR_Cup_Series_at_Circuit_of_the_Americas' => 'COTA',
        'NASCAR_CUP_SERIES_AT_CIRCUIT_OF_THE_AMERICAS' => 'COTA',

        'World_Wide_Technology_Raceway' => 'World Wide Tech',
        'NASCAR_Cup_Series_at_World_Wide_Technology_Raceway' => 'World Wide Tech',
        'NASCAR_CUP_SERIES_AT_WORLD_WIDE_TECHNOLOGY_RACEWAY' => 'World Wide Tech',

        'Indianapolis_Road_Course' => 'Indianapolis RC',
        'NASCAR_Cup_Series_at_Indianapolis_Road_Course' => 'Indianapolis RC',
        'NASCAR_CUP_SERIES_AT_INDIANAPOLIS_ROAD_COURSE' => 'Indianapolis RC',

        'Charlotte_Road_Course' => 'Charlotte RC',
        'NASCAR_Cup_Series_at_Charlotte_Road_Course' => 'Charlotte RC',
        'NASCAR_CUP_SERIES_AT_CHARLOTTE_ROAD_COURSE' => 'Charlotte RC',
    ];

    if (isset($map[$slug])) {
        return $map[$slug];
    }

    $slug = preg_replace('/^MONSTER_ENERGY_NASCAR_CUP_SERIES_AT_/i', '', $slug);
    $slug = preg_replace('/^NASCAR_CUP_SERIES_AT_/i', '', $slug);
    $slug = preg_replace('/^NASCAR_Cup_Series_at_/i', '', $slug);

    $slug = str_replace('World_Wide_Technology_Raceway', 'World Wide Tech', $slug);
    $slug = str_replace('Indianapolis_Road_Course', 'Indianapolis_RC', $slug);
    $slug = str_replace('Charlotte_Road_Course', 'Charlotte_RC', $slug);
    $slug = str_replace('Road_Course', 'RC', $slug);

    $slug = trim((string)$slug, '_');

    if ($slug === '') {
        $slug = 'Race';
    }

    return str_replace('_', ' ', $slug);
}

function rrsg_add_validation(array &$validation, string $level, string $message): void
{
    if (!isset($validation[$level]) || !is_array($validation[$level])) {
        $validation[$level] = [];
    }
    $validation[$level][] = $message;
}

function rrsg_is_sorted_weekly_desc(array $weeklyRows): bool
{
    if (count($weeklyRows) <= 1) {
        return true;
    }

    for ($i = 0; $i < count($weeklyRows) - 1; $i++) {
        $curTotal = (int)($weeklyRows[$i]['weeklyTotal'] ?? 0);
        $nextTotal = (int)($weeklyRows[$i + 1]['weeklyTotal'] ?? 0);

        if ($curTotal < $nextTotal) {
            return false;
        }

        if ($curTotal === $nextTotal) {
            $curName = (string)($weeklyRows[$i]['teamName'] ?? '');
            $nextName = (string)($weeklyRows[$i + 1]['teamName'] ?? '');

            if (strcasecmp($curName, $nextName) > 0) {
                return false;
            }
        }
    }

    return true;
}

function rrsg_validation_status(array $validation): string
{
    if (!empty($validation['fail'])) {
        return 'FAIL';
    }
    if (!empty($validation['warn'])) {
        return 'WARN';
    }
    return 'PASS';
}

function rrsg_build_year_race_options(array $availableYears, string $baseDir): array
{
    $result = [];

    foreach ($availableYears as $yearOpt) {
        $yearFolder = $baseDir . '/' . $yearOpt;
        $yearIndexFile = $yearFolder . '/_year_index.json';
        $yearIndex = rrsg_load_year_index_file($yearIndexFile);
        $pointRaces = rrsg_points_races_from_index($yearIndex, $yearFolder);
        $pointRaces = rrsg_filter_races_with_snapshots($pointRaces);
        $pointRacesAsc = rrsg_sort_races_ascending($pointRaces);

        $result[$yearOpt] = [];

        foreach ($pointRacesAsc as $race) {
            $result[$yearOpt][] = [
                'raceCode' => (string)$race['raceCode'],
                'label' => rrsg_revision_display_label_for_race($race, $pointRacesAsc),
                'number' => (int)$race['number'],
            ];
        }
    }

    return $result;
}

function rrsg_segment_breakdown_rows(
    string $selectedYear,
    string $scoreSegment,
    int $selectedRaceNumber,
    array $pointRaces,
    $dbo,
    $dbconnect
): array {
    $rows = [];
    $yearRoster = rrsg_get_year_team_roster($selectedYear, $dbo ?? null);
    $racesAscending = $pointRaces;

    usort($racesAscending, function ($a, $b) {
        return ((int)$a['number']) <=> ((int)$b['number']);
    });

    foreach ($racesAscending as $race) {
        $raceNumber = (int)($race['number'] ?? 0);

        if ($raceNumber > $selectedRaceNumber) {
            continue;
        }

        if (rrsg_segment_from_race_number($raceNumber) !== $scoreSegment) {
            continue;
        }

        $raceTeamRowsBase = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $selectedYear, $scoreSegment);
        $raceTeamRowsSpecial = rrsg_special_pick_rows($selectedYear, $scoreSegment, $dbo ?? null);
        $raceTeamRows = rrsg_overlay_special_rows_for_race($raceTeamRowsBase, $raceTeamRowsSpecial, $raceNumber, $scoreSegment);
        $snapshotFile = rrsg_find_snapshot_file((string)$race['raceFolder']);
        if ($snapshotFile === '') {
            continue;
        }

        $driverPoints = rrs_load_snapshot_driver_points($snapshotFile);
        $weeklyRows = rrsg_build_weekly_rows($raceTeamRows, $driverPoints);
        $weeklyRows = rrsg_append_missing_roster_rows($weeklyRows, $yearRoster);

        $rows[] = [
            'raceCode' => (string)$race['raceCode'],
            'raceLabel' => rrsg_short_race_label((string)$race['raceName']),
            'weeklyRows' => $weeklyRows,
        ];
    }

    return $rows;
}

function rrsg_visible_segments(string $scoreSegment): array
{
    $segments = ['S1', 'S2', 'S3', 'S4'];
    $result = [];

    foreach ($segments as $segment) {
        $result[] = $segment;
        if ($segment === $scoreSegment) {
            break;
        }
    }

    return $result;
}


function rrsg_special_pick_rows(string $raceYear, string $segment, $dbo): array
{
    if (!($dbo instanceof PDO)) {
        return [];
    }

    $sql = "
        SELECT
            'current' AS src,
            up.userID,
            up.teamName,
            COALESCE(u.userName, '') AS userName,
            up.raceYear,
            up.segment,
            up.driverA,
            up.driverB,
            up.driverC,
            up.driverD,
            up.entryDate,
            up.submission_id,
            up.formID,
            up.pick_type,
            up.effective_race,
            up.supersedes_pickID,
            base.driverA AS original_driverA,
            base.driverB AS original_driverB,
            base.driverC AS original_driverC,
            base.driverD AS original_driverD
        FROM user_picks up
        LEFT JOIN users u ON u.userID = up.userID
        LEFT JOIN user_picks base ON base.pickID = up.supersedes_pickID
        WHERE up.raceYear = :raceYear
          AND up.segment = :segment
          AND up.pick_type IN ('LP', 'RD')
        ORDER BY up.teamName ASC, up.effective_race ASC, up.entryDate ASC, up.pickID ASC
    ";

    $stmt = $dbo->prepare($sql);
    $stmt->execute([
        ':raceYear' => $raceYear,
        ':segment' => $segment,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function rrsg_overlay_special_rows_for_race(array $baseTeamRows, array $specialRows, int $raceNumber, string $segment): array
{
    $rowsByTeam = [];

    foreach ($baseTeamRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $teamName = (string)($row['teamName'] ?? '');
        if ($teamName === '') {
            continue;
        }

        $row['pick_type'] = (string)($row['pick_type'] ?? 'SEG');
        $row['effective_race'] = (int)($row['effective_race'] ?? rrsg_segment_bounds($segment)['start']);
        $row['original_driverA'] = (string)($row['original_driverA'] ?? '');
        $row['original_driverB'] = (string)($row['original_driverB'] ?? '');
        $row['original_driverC'] = (string)($row['original_driverC'] ?? '');
        $row['original_driverD'] = (string)($row['original_driverD'] ?? '');
        $rowsByTeam[$teamName] = $row;
    }

    $specialByTeam = [];
    foreach ($specialRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $teamName = (string)($row['teamName'] ?? '');
        if ($teamName === '') {
            continue;
        }

        if (!isset($specialByTeam[$teamName])) {
            $specialByTeam[$teamName] = [];
        }
        $specialByTeam[$teamName][] = $row;
    }

    foreach ($specialByTeam as $teamName => $teamRows) {
        usort($teamRows, function ($a, $b) {
            $aRace = (int)($a['effective_race'] ?? 0);
            $bRace = (int)($b['effective_race'] ?? 0);

            if ($aRace !== $bRace) {
                return ($aRace <=> $bRace);
            }

            $aDate = strtotime((string)($a['entryDate'] ?? ''));
            $bDate = strtotime((string)($b['entryDate'] ?? ''));

            if ($aDate !== $bDate) {
                return ($aDate <=> $bDate);
            }

            return strcmp((string)($a['src'] ?? ''), (string)($b['src'] ?? ''));
        });

        $applicable = null;
        $firstSpecial = $teamRows[0];

        foreach ($teamRows as $row) {
            $effectiveRace = (int)($row['effective_race'] ?? 0);
            if ($effectiveRace <= $raceNumber) {
                $applicable = $row;
            }
        }

        if ($applicable !== null) {
            $rowsByTeam[$teamName] = [
                'teamName' => $teamName,
                'userName' => (string)($applicable['userName'] ?? ($rowsByTeam[$teamName]['userName'] ?? '')),
                'driverA' => (string)($applicable['driverA'] ?? ''),
                'driverB' => (string)($applicable['driverB'] ?? ''),
                'driverC' => (string)($applicable['driverC'] ?? ''),
                'driverD' => (string)($applicable['driverD'] ?? ''),
                'pick_type' => (string)($applicable['pick_type'] ?? ''),
                'effective_race' => (int)($applicable['effective_race'] ?? 0),
                'original_driverA' => (string)($applicable['original_driverA'] ?? ''),
                'original_driverB' => (string)($applicable['original_driverB'] ?? ''),
                'original_driverC' => (string)($applicable['original_driverC'] ?? ''),
                'original_driverD' => (string)($applicable['original_driverD'] ?? ''),
            ];
            continue;
        }

        $firstEffectiveRace = (int)($firstSpecial['effective_race'] ?? 0);

        if ($firstEffectiveRace > $raceNumber) {
            $existingBaseRow = isset($rowsByTeam[$teamName]) && is_array($rowsByTeam[$teamName])
                ? $rowsByTeam[$teamName]
                : null;

            $existingBasePickType = strtoupper((string)($existingBaseRow['pick_type'] ?? ''));

            /*
             * LP before effective race should show as no picks.
             * RD before effective race should preserve the underlying SEG/base row.
             */
            if ($existingBaseRow === null || $existingBasePickType === 'LP') {
                $rowsByTeam[$teamName] = [
                    'teamName' => $teamName,
                    'userName' => (string)($firstSpecial['userName'] ?? ($rowsByTeam[$teamName]['userName'] ?? '')),
                    'driverA' => '',
                    'driverB' => '',
                    'driverC' => '',
                    'driverD' => '',
                    'pick_type' => (string)($firstSpecial['pick_type'] ?? ''),
                    'effective_race' => $firstEffectiveRace,
                    'original_driverA' => (string)($firstSpecial['original_driverA'] ?? ''),
                    'original_driverB' => (string)($firstSpecial['original_driverB'] ?? ''),
                    'original_driverC' => (string)($firstSpecial['original_driverC'] ?? ''),
                    'original_driverD' => (string)($firstSpecial['original_driverD'] ?? ''),
                ];
            }
        }
    }

    $rows = array_values($rowsByTeam);
    usort($rows, function ($a, $b) {
        return strcasecmp((string)($a['teamName'] ?? ''), (string)($b['teamName'] ?? ''));
    });

    return $rows;
}

function rrsg_no_picks_message(array $row): string
{
    $pickType = strtoupper((string)($row['pick_type'] ?? ''));
    $effectiveRace = (int)($row['effective_race'] ?? 0);

    if ($pickType === 'LP' && $effectiveRace > 0) {
        return 'No Picks (LP effective R' . str_pad((string)$effectiveRace, 2, '0', STR_PAD_LEFT) . ')';
    }

    return 'No Picks';
}


function rrsg_collect_missing_pick_warnings(
    int $selectedRaceNumber,
    array $pointRaces,
    array $selectedRaceWeeklyRows
): array {
    $warnings = [];
    $labelByNumber = [];
    $selectedSegment = rrsg_segment_from_race_number($selectedRaceNumber);
    $segmentBounds = rrsg_segment_bounds($selectedSegment);

    foreach ($pointRaces as $race) {
        $raceNumber = (int)($race['number'] ?? 0);
        if ($raceNumber <= 0 || $raceNumber > $selectedRaceNumber) {
            continue;
        }

        if ($raceNumber < $segmentBounds['start'] || $raceNumber > $segmentBounds['end']) {
            continue;
        }

        $labelByNumber[$raceNumber] = (string)($race['raceCode'] ?? '') . ' ' . rrsg_short_race_label((string)($race['raceName'] ?? ''));
    }

    foreach ($selectedRaceWeeklyRows as $row) {
        $driverA = trim((string)($row['driverA'] ?? ''));
        $driverB = trim((string)($row['driverB'] ?? ''));
        $driverC = trim((string)($row['driverC'] ?? ''));
        $driverD = trim((string)($row['driverD'] ?? ''));
        $teamName = (string)($row['teamName'] ?? '');
        $effectiveRace = (int)($row['effective_race'] ?? 0);

        if ($teamName === '') {
            continue;
        }

        if ($driverA !== '' || $driverB !== '' || $driverC !== '' || $driverD !== '') {
            continue;
        }

        if ($effectiveRace < $segmentBounds['start']) {
            $effectiveRace = $selectedRaceNumber + 1;
        }

        for ($rn = $segmentBounds['start']; $rn <= $selectedRaceNumber; $rn++) {
            if (!isset($labelByNumber[$rn])) {
                continue;
            }

            if ($rn < $effectiveRace) {
                $warnings[] = 'No picks for ' . $teamName . ' in ' . $labelByNumber[$rn];
            }
        }
    }

    return $warnings;
}


function rrsg_tie_team_map(array $rows, string $scoreKey): array
{
    $counts = [];
    $map = [];

    foreach ($rows as $row) {
        $score = (int)($row[$scoreKey] ?? 0);
        if (!isset($counts[$score])) {
            $counts[$score] = 0;
        }
        $counts[$score]++;
    }

    foreach ($rows as $row) {
        $teamName = (string)($row['teamName'] ?? '');
        $score = (int)($row[$scoreKey] ?? 0);

        if ($teamName !== '' && ($counts[$score] ?? 0) > 1) {
            $map[$teamName] = true;
        }
    }

    return $map;
}

function rrsg_weekly_special_changed_field(array $row): ?string
{
    $pickType = strtoupper((string)($row['pick_type'] ?? ''));
    if ($pickType !== 'RD') {
        return null;
    }

    foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
        $current = trim((string)($row[$field] ?? ''));
        $original = trim((string)($row['original_' . $field] ?? ''));
        if ($current !== '' && $original !== '' && strcasecmp($current, $original) !== 0) {
            return $field;
        }
    }

    return null;
}

function rrsg_special_marker_symbol(int $index, bool $tieExistsAnywhere): string
{
    $starCount = $index + ($tieExistsAnywhere ? 1 : 0);
    return str_repeat('*', max(1, $starCount));
}

function rrsg_weekly_special_display_context(array $weeklyRows): array
{
    $hasLp = false;
    $hasRd = false;

    foreach ($weeklyRows as $row) {
        $pickType = strtoupper((string)($row['pick_type'] ?? ''));
        if ($pickType === 'LP') {
            $hasLp = true;
        } elseif ($pickType === 'RD' && rrsg_weekly_special_changed_field($row) !== null) {
            $hasRd = true;
        }
    }

    $lpMarker = '';
    $rdMarker = '';

    if ($hasLp && $hasRd) {
        $lpMarker = '*';
        $rdMarker = '**';
    } elseif ($hasLp) {
        $lpMarker = '*';
    } elseif ($hasRd) {
        $rdMarker = '*';
    }

    $markersByTeam = [];
    $detailNoteByTeam = [];
    $changedFieldByTeam = [];
    $latePickEntries = [];
    $replacementDriverEntries = [];

    foreach ($weeklyRows as $row) {
        $teamName = (string)($row['teamName'] ?? '');
        $pickType = strtoupper((string)($row['pick_type'] ?? ''));

        if ($teamName === '') {
            continue;
        }

        if ($pickType === 'LP' && $lpMarker !== '') {
            $effectiveRace = (int)($row['effective_race'] ?? 0);
            $detailText = 'Late Pick';
            $entryText = $teamName;
            if ($effectiveRace > 0) {
                $detailText .= ' — As of R' . str_pad((string)$effectiveRace, 2, '0', STR_PAD_LEFT);
                $entryText .= ' — As of R' . str_pad((string)$effectiveRace, 2, '0', STR_PAD_LEFT);
            }

            $markersByTeam[$teamName] = $lpMarker;
            $detailNoteByTeam[$teamName] = $detailText;
            $latePickEntries[] = $entryText;
            continue;
        }

        if ($pickType === 'RD' && $rdMarker !== '') {
            $changedField = rrsg_weekly_special_changed_field($row);
            if ($changedField !== null) {
                $effectiveRace = (int)($row['effective_race'] ?? 0);
                $detailText = 'Replacement Driver';
                $entryText = $teamName;
                if ($effectiveRace > 0) {
                    $detailText .= ' — As of R' . str_pad((string)$effectiveRace, 2, '0', STR_PAD_LEFT);
                    $entryText .= ' — As of R' . str_pad((string)$effectiveRace, 2, '0', STR_PAD_LEFT);
                }

                $markersByTeam[$teamName] = $rdMarker;
                $detailNoteByTeam[$teamName] = $detailText;
                $changedFieldByTeam[$teamName] = $changedField;
                $replacementDriverEntries[] = $entryText;
            }
        }
    }

    return [
        'markersByTeam' => $markersByTeam,
        'detailNoteByTeam' => $detailNoteByTeam,
        'changedFieldByTeam' => $changedFieldByTeam,
        'latePickEntries' => $latePickEntries,
        'replacementDriverEntries' => $replacementDriverEntries,
        'lpMarker' => $lpMarker,
        'rdMarker' => $rdMarker,
    ];
}



function rrsg_release_public_change_label(array $row): string
{
    $seq = (int)($row['release_sequence'] ?? $row['snapshot_index'] ?? 0);
    $releaseType = strtolower((string)($row['release_type'] ?? ''));
    if ($seq <= 1 && $releaseType === 'initial') {
        return '';
    }

    $label = trim((string)($row['display_change_label'] ?? $row['change_status_label'] ?? $row['change_label'] ?? ''));
    if ($label === 'MRL-Listed Driver Changed - No Segment Impact') {
        return 'MRL Driver - No Team, No Impact';
    }
    if ($label === 'Pending Review - MRL Impact' || $label === 'Revised standings release') {
        return 'MRL Impact - Results Changed';
    }
    if ($label !== '') {
        return $label;
    }

    if (!empty($row['mrl_impact'])) {
        return 'MRL Impact - Results Changed';
    }
    if ((int)($row['changed_mrl_listed_drivers_count'] ?? 0) > 0 && (int)($row['changed_segment_picked_drivers_count'] ?? 0) === 0) {
        return 'MRL Driver - No Team, No Impact';
    }
    if ((int)($row['changed_all_drivers_count'] ?? 0) > 0) {
        return 'Non-MRL Driver Change';
    }
    return '';
}

function rrsg_release_id_for_ui(array $row): string
{
    return (string)($row['generated_id'] ?? $row['release_id'] ?? '');
}

function rrsg_release_version_for_ui(array $row, int $fallbackIndex): string
{
    $v = trim((string)($row['display_version'] ?? $row['version_label'] ?? ''));
    return $v !== '' ? $v : ('v' . (string)$fallbackIndex);
}

function rrsg_release_short_time_for_ui(array $row): string
{
    $short = trim((string)($row['released_at_short'] ?? ''));
    if ($short !== '') return $short;
    $raw = trim((string)($row['released_at'] ?? ''));
    if ($raw === '') return '';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('America/New_York'));
    if (!($dt instanceof DateTime)) {
        try { $dt = new DateTime($raw, new DateTimeZone('America/New_York')); }
        catch (Exception $e) { return (string)($row['released_at_display'] ?? $raw); }
    }
    return $dt->format('n/j/y g:ia');
}

function rrsg_release_version_option_label(array $row, int $fallbackIndex): string
{
    $label = rrsg_release_version_for_ui($row, $fallbackIndex);
    $short = rrsg_release_short_time_for_ui($row);
    return $label . ($short !== '' ? ' (' . $short . ')' : '');
}

function rrsg_select_release_row(array $rows, string $releaseId): array
{
    if (empty($rows)) return [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $id = rrsg_release_id_for_ui($row);
        if ($releaseId !== '' && $id === $releaseId) return $row;
    }
    return $rows[count($rows) - 1];
}

function rrsg_release_snapshot_path_for_race(string $raceFolder, array $release): string
{
    $base = basename((string)($release['snapshot_file'] ?? $release['current_snapshot'] ?? ''));
    if ($base === '') {
        $snapshotId = (string)($release['snapshot_id'] ?? '');
        if ($snapshotId !== '') $base = 'snapshot_' . $snapshotId . '.html';
    }
    if ($base === '') return '';
    $path = rtrim($raceFolder, '/\\') . '/' . $base;
    return is_file($path) ? $path : '';
}

function rrsg_release_status_for_ui(array $selected, array $rows): array
{
    if (empty($selected)) return ['label' => '', 'class' => 'none'];
    $id = rrsg_release_id_for_ui($selected);
    $latest = !empty($rows) ? $rows[count($rows) - 1] : [];
    $latestId = rrsg_release_id_for_ui($latest);
    if ($latestId !== '' && $id === $latestId) {
        return ['label' => 'Current', 'class' => 'current'];
    }
    return ['label' => 'Superseded', 'class' => 'superseded'];
}

function rrsg_timeline_url_for_release(string $year, array $release): string
{
    $id = rrsg_release_id_for_ui($release);
    if ($id === '') {
        $snapshotId = (string)($release['snapshot_id'] ?? '');
        $raceCode = (string)($release['race_code'] ?? '');
        $id = ($snapshotId !== '' && $raceCode !== '') ? ($snapshotId . '_' . $raceCode) : $snapshotId;
    }
    return 'standings_timeline_lite.php?year=' . rawurlencode($year) . '&snapshot=' . rawurlencode($id);
}
/* ------------------------------------------------------------------
   INPUTS
   ------------------------------------------------------------------ */

$baseDir = __DIR__;
$availableYears = rrsg_available_years($baseDir);
$availableYearsAsc = $availableYears;
sort($availableYearsAsc, SORT_STRING);

$selectedYear = isset($_GET['year']) ? trim((string)$_GET['year']) : '';
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = !empty($availableYears) ? $availableYears[0] : '2026';
}

$yearFolder = $baseDir . '/' . $selectedYear;
$yearIndexFile = $yearFolder . '/_year_index.json';
$yearIndex = rrsg_load_year_index_file($yearIndexFile);
$pointRaces = rrsg_points_races_from_index($yearIndex, $yearFolder);
$selectablePointRaces = rrsg_filter_races_with_snapshots($pointRaces);
$pointRacesAsc = rrsg_sort_races_ascending($pointRaces);
$selectablePointRacesAsc = rrsg_sort_races_ascending($selectablePointRaces);

$latestView = rrsg_find_latest_available_view($availableYears, $baseDir);
$latestYear = (string)($latestView['year'] ?? $selectedYear);
$latestRaceCode = (string)($latestView['raceCode'] ?? '');

$selectedRaceCode = isset($_GET['race']) ? trim((string)$_GET['race']) : '';
if ($selectedRaceCode === '' && !empty($selectablePointRaces)) {
    $selectedRaceCode = (string)$selectablePointRaces[0]['raceCode'];
}

$selectedRaceIndex = -1;
for ($i = 0; $i < count($pointRaces); $i++) {
    if ((string)$pointRaces[$i]['raceCode'] === $selectedRaceCode) {
        $selectedRaceIndex = $i;
        break;
    }
}

$selectedRaceHasSnapshot = false;
if ($selectedRaceIndex >= 0 && isset($pointRaces[$selectedRaceIndex])) {
    $selectedRaceHasSnapshot = (rrsg_find_snapshot_file((string)$pointRaces[$selectedRaceIndex]['raceFolder']) !== '');
}

if ((!$selectedRaceHasSnapshot) && !empty($selectablePointRaces)) {
    $selectedRaceCode = (string)$selectablePointRaces[0]['raceCode'];
    $selectedRaceIndex = -1;

    for ($i = 0; $i < count($pointRaces); $i++) {
        if ((string)$pointRaces[$i]['raceCode'] === $selectedRaceCode) {
            $selectedRaceIndex = $i;
            break;
        }
    }
}

$selectedRace = ($selectedRaceIndex >= 0 && isset($pointRaces[$selectedRaceIndex]))
    ? $pointRaces[$selectedRaceIndex]
    : null;

$selectedReleaseId = isset($_GET['release']) ? trim((string)$_GET['release']) : '';
$weeklyReleaseHistory = rrsg_load_weekly_release_history($yearFolder);
$selectedVersionReleaseRows = rrsg_release_history_rows_for_race($weeklyReleaseHistory, $selectedRaceCode);
$selectedVersionRelease = rrsg_select_release_row($selectedVersionReleaseRows, $selectedReleaseId);
$selectedReleaseId = !empty($selectedVersionRelease) ? rrsg_release_id_for_ui($selectedVersionRelease) : '';
$selectedReleaseStatus = rrsg_release_status_for_ui($selectedVersionRelease, $selectedVersionReleaseRows);
$selectedReleaseChangeLabel = rrsg_release_public_change_label($selectedVersionRelease);
$selectedReleaseTimelineUrl = !empty($selectedVersionRelease) ? rrsg_timeline_url_for_release($selectedYear, $selectedVersionRelease) : '';

$liveUrl = '?year=' . rawurlencode($latestYear);
if ($latestRaceCode !== '') {
    $liveUrl .= '&race=' . rawurlencode($latestRaceCode);
}

$isLiveView = ($selectedYear === $latestYear && $selectedRaceCode === $latestRaceCode);

$scoreYear = $selectedYear;
$scoreSegment = 'S1';
$segmentBounds = ['start' => 1, 'end' => 8];
$selectedRaceNumber = 0;
$selectedRaceDisplay = '';

if ($selectedRace !== null) {
    $selectedRaceNumber = (int)$selectedRace['number'];
    $scoreSegment = rrsg_segment_from_race_number($selectedRaceNumber);
    $segmentBounds = rrsg_segment_bounds($scoreSegment);
    $selectedRaceDisplay = rrsg_revision_display_label_for_race($selectedRace, $pointRacesAsc);
}

$teamRowsBase = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $scoreYear, $scoreSegment);
$teamRowsSpecial = rrsg_special_pick_rows($scoreYear, $scoreSegment, $dbo ?? null);
$teamRows = rrsg_overlay_special_rows_for_race($teamRowsBase, $teamRowsSpecial, $selectedRaceNumber, $scoreSegment);
$yearRoster = rrsg_get_year_team_roster($scoreYear, $dbo ?? null);

$segmentTotals = [];
$seasonTotals = [];
$segmentHistory = [];
$weeklyWinners = [];
$selectedRaceWeeklyRows = [];
$selectedRaceMeta = [
    'raceCode' => '',
    'raceLabel' => '',
    'raceDisplayLabel' => '',
    'snapshotFile' => '',
    'driverCount' => 0,
    'snapshotDisplay' => '',
];

$debugRows = [];

$validation = [
    'pass' => [],
    'warn' => [],
    'fail' => [],
];

foreach ($yearRoster as $teamName => $rosterRow) {
    $segmentTotals[(string)$teamName] = 0;
    $seasonTotals[(string)$teamName] = 0;
}

if ($selectedRace !== null) {
    $racesAscending = $pointRaces;
    usort($racesAscending, function ($a, $b) {
        return ((int)$a['number']) <=> ((int)$b['number']);
    });

    foreach ($racesAscending as $race) {
        $raceCode = (string)$race['raceCode'];
        $raceLabel = rrsg_short_race_label((string)$race['raceName']);
        $raceFolder = (string)$race['raceFolder'];
        $raceNumber = (int)$race['number'];

        if ($raceNumber > $selectedRaceNumber) {
            continue;
        }

        $raceSegment = rrsg_segment_from_race_number($raceNumber);
        $raceTeamRowsBase = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $selectedYear, $raceSegment);
        $raceTeamRowsSpecial = rrsg_special_pick_rows($selectedYear, $raceSegment, $dbo ?? null);
        $raceTeamRows = rrsg_overlay_special_rows_for_race($raceTeamRowsBase, $raceTeamRowsSpecial, $raceNumber, $raceSegment);

        $snapshotFile = rrsg_find_snapshot_file($raceFolder);
        if ($raceCode === $selectedRaceCode && !empty($selectedVersionRelease)) {
            $versionSnapshotFile = rrsg_release_snapshot_path_for_race($raceFolder, $selectedVersionRelease);
            if ($versionSnapshotFile !== '') {
                $snapshotFile = $versionSnapshotFile;
            }
        }
        $driverPoints = [];
        $weeklyRows = [];
        $winner = [
            'teamName' => '',
            'teamNames' => [],
            'points' => 0,
        ];

        if ($snapshotFile !== '') {
            $driverPoints = rrs_load_snapshot_driver_points($snapshotFile);
            $weeklyRows = rrsg_build_weekly_rows($raceTeamRows, $driverPoints);
            $weeklyRows = rrsg_append_missing_roster_rows($weeklyRows, $yearRoster);
            $winner = rrsg_get_weekly_winner($weeklyRows);

            foreach ($weeklyRows as $row) {
                $teamName = (string)$row['teamName'];
                $weeklyTotal = (int)$row['weeklyTotal'];

                if (!isset($seasonTotals[$teamName])) {
                    $seasonTotals[$teamName] = 0;
                }
                $seasonTotals[$teamName] += $weeklyTotal;

                if ($raceNumber >= $segmentBounds['start'] && $raceNumber <= $segmentBounds['end']) {
                    if (!isset($segmentTotals[$teamName])) {
                        $segmentTotals[$teamName] = 0;
                    }
                    $segmentTotals[$teamName] += $weeklyTotal;
                }

                if (!isset($segmentHistory[$teamName])) {
                    $segmentHistory[$teamName] = [
                        'S1' => 0,
                        'S2' => 0,
                        'S3' => 0,
                        'S4' => 0,
                    ];
                }

                if (!isset($segmentHistory[$teamName][$raceSegment])) {
                    $segmentHistory[$teamName][$raceSegment] = 0;
                }

                $segmentHistory[$teamName][$raceSegment] += $weeklyTotal;
            }

            $weeklyWinners[$raceCode] = $winner;
        } else {
            $weeklyWinners[$raceCode] = [
                'teamName' => '',
                'teamNames' => [],
                'points' => 0,
            ];
        }

        $debugRows[] = [
            'raceCode' => $raceCode,
            'raceLabel' => $raceLabel,
            'raceNumber' => $raceNumber,
            'raceSegment' => $raceSegment,
            'teamsLoaded' => count($raceTeamRows),
            'snapshotBase' => ($snapshotFile !== '' ? basename($snapshotFile) : 'NOT FOUND'),
            'winnerTeam' => (string)$winner['teamName'],
            'winnerPoints' => (int)$winner['points'],
        ];

        if ($raceCode === $selectedRaceCode) {
            $selectedRaceWeeklyRows = $weeklyRows;
            $selectedRaceMeta = [
                'raceCode' => $raceCode,
                'raceLabel' => $raceLabel,
                'raceDisplayLabel' => rrsg_revision_display_label_for_race($race, $pointRacesAsc),
                'snapshotFile' => $snapshotFile,
                'driverCount' => count($driverPoints),
                'snapshotDisplay' => rrsg_format_snapshot_timestamp($snapshotFile),
            ];
        }
    }
}

/* ------------------------------------------------------------------
   VALIDATION
   ------------------------------------------------------------------ */

if ($selectedRace === null) {
    rrsg_add_validation($validation, 'pass', 'Select a race to view validation.');
} else {
    rrsg_add_validation($validation, 'pass', 'Selected race found: ' . $selectedRaceCode);

    if ($selectedRaceMeta['snapshotFile'] !== '') {
        rrsg_add_validation($validation, 'pass', 'Snapshot found for selected race.');
    } else {
        rrsg_add_validation($validation, 'fail', 'Selected race snapshot not found.');
    }

    if (count($teamRows) > 0) {
        rrsg_add_validation($validation, 'pass', 'Teams loaded: ' . count($teamRows));
    } else {
        rrsg_add_validation($validation, 'fail', 'No teams loaded for selected segment.');
    }

    if (!empty($selectedRaceWeeklyRows)) {
        rrsg_add_validation($validation, 'pass', 'Weekly rows generated: ' . count($selectedRaceWeeklyRows));
    } else {
        rrsg_add_validation($validation, 'fail', 'No weekly rows generated for selected race.');
    }

    $duplicateTeams = [];
    $teamSeen = [];
    $badTotals = 0;
    $zeroDrivers = [];

    foreach ($selectedRaceWeeklyRows as $row) {
        $teamName = (string)($row['teamName'] ?? '');

        if ($teamName !== '') {
            if (isset($teamSeen[$teamName])) {
                $duplicateTeams[] = $teamName;
            }
            $teamSeen[$teamName] = true;
        }

        $sumDrivers =
            (int)($row['netA'] ?? 0) +
            (int)($row['netB'] ?? 0) +
            (int)($row['netC'] ?? 0) +
            (int)($row['netD'] ?? 0);

        $weeklyTotal = (int)($row['weeklyTotal'] ?? 0);

        if ($sumDrivers !== $weeklyTotal) {
            $badTotals++;
        }

        $drivers = [
            ['name' => (string)($row['driverA'] ?? ''), 'net' => (int)($row['netA'] ?? 0)],
            ['name' => (string)($row['driverB'] ?? ''), 'net' => (int)($row['netB'] ?? 0)],
            ['name' => (string)($row['driverC'] ?? ''), 'net' => (int)($row['netC'] ?? 0)],
            ['name' => (string)($row['driverD'] ?? ''), 'net' => (int)($row['netD'] ?? 0)],
        ];

        foreach ($drivers as $driverRow) {
            if ($driverRow['name'] !== '' && $driverRow['net'] === 0) {
                $zeroDrivers[] = [
                    'driver' => $driverRow['name'],
                    'team' => $teamName,
                ];
            }
        }
    }

    if ($badTotals === 0) {
        rrsg_add_validation($validation, 'pass', 'Weekly totals match sum of driver values.');
    } else {
        rrsg_add_validation($validation, 'fail', 'Weekly total mismatch count: ' . $badTotals);
    }

    if (empty($duplicateTeams)) {
        rrsg_add_validation($validation, 'pass', 'No duplicate teams found in weekly results.');
    } else {
        rrsg_add_validation($validation, 'fail', 'Duplicate teams found: ' . implode(', ', array_unique($duplicateTeams)));
    }

    if (!empty($zeroDrivers)) {
        foreach ($zeroDrivers as $zeroDriver) {
            rrsg_add_validation(
                $validation,
                'warn',
                'Unexpected zero score — ' . $zeroDriver['driver'] . ' (Team: ' . $zeroDriver['team'] . ')'
            );
        }
    } else {
        rrsg_add_validation($validation, 'pass', 'No unexpected zero scores detected.');
    }

    $missingPickWarnings = rrsg_collect_missing_pick_warnings(
        $selectedRaceNumber,
        $pointRaces,
        $selectedRaceWeeklyRows
    );
    if (!empty($missingPickWarnings)) {
        foreach ($missingPickWarnings as $warningMsg) {
            rrsg_add_validation($validation, 'warn', $warningMsg);
        }
    }

    if (rrsg_is_sorted_weekly_desc($selectedRaceWeeklyRows)) {
        rrsg_add_validation($validation, 'pass', 'Weekly standings are sorted correctly.');
    } else {
        rrsg_add_validation($validation, 'fail', 'Weekly standings are not sorted correctly.');
    }

    if (!empty($selectedRaceWeeklyRows)) {
        $winner = rrsg_get_weekly_winner($selectedRaceWeeklyRows);
        $topPoints = (int)($selectedRaceWeeklyRows[0]['weeklyTotal'] ?? 0);

        $expectedWinnerNames = [];
        foreach ($selectedRaceWeeklyRows as $row) {
            $weeklyTotal = (int)($row['weeklyTotal'] ?? 0);
            if ($weeklyTotal !== $topPoints) {
                break;
            }
            $expectedWinnerNames[] = (string)$row['teamName'];
        }

        $expectedWinnerString = implode(' / ', $expectedWinnerNames);

        if ($winner['teamName'] === $expectedWinnerString && (int)$winner['points'] === $topPoints) {
            rrsg_add_validation($validation, 'pass', 'Weekly winner matches top weekly score.');
        } else {
            rrsg_add_validation($validation, 'fail', 'Weekly winner does not match top weekly score.');
        }
    }
}

$validationStatus = rrsg_validation_status($validation);
$segmentStandings = rrsg_sort_total_rows($segmentTotals);
$seasonStandings = rrsg_sort_total_rows($seasonTotals);

$segmentBreakdownRows = [];
if ($selectedRace !== null) {
    $segmentBreakdownRows = rrsg_segment_breakdown_rows(
        $selectedYear,
        $scoreSegment,
        $selectedRaceNumber,
        $pointRaces,
        $dbo ?? null,
        $dbconnect ?? null
    );
}

$visibleSegments = rrsg_visible_segments($scoreSegment);

$weeklyWinnersHasTie = false;
foreach ($weeklyWinners as $winnerData) {
    $winnerNames = isset($winnerData['teamNames']) && is_array($winnerData['teamNames'])
        ? $winnerData['teamNames']
        : [];

    if (count($winnerNames) > 1) {
        $weeklyWinnersHasTie = true;
        break;
    }
}

$weeklyTieMap = rrsg_tie_team_map($selectedRaceWeeklyRows, 'weeklyTotal');
$segmentTieMap = rrsg_tie_team_map($segmentStandings, 'total');
$seasonTieMap = rrsg_tie_team_map($seasonStandings, 'total');

$weeklyHasTie = !empty($weeklyTieMap);
$segmentHasTie = !empty($segmentTieMap);
$seasonHasTie = !empty($seasonTieMap);

$tieExistsAnywhere = ($weeklyHasTie || $segmentHasTie || $seasonHasTie || $weeklyWinnersHasTie);
$weeklySpecialContext = rrsg_weekly_special_display_context($selectedRaceWeeklyRows);

$statusClass = 'status-pass';
if ($validationStatus === 'WARN') {
    $statusClass = 'status-warn';
} elseif ($validationStatus === 'FAIL') {
    $statusClass = 'status-fail';
}

$validationButtonClass = ($selectedRace === null) ? 'status-neutral' : $statusClass;

$historicalNote = '';
if ((int)$selectedYear < 2026 && $selectedRace !== null) {
    $historicalNote = $selectedYear . ' ' . $selectedRaceCode . ' ' . $selectedRaceMeta['raceLabel']
        . ' may contain minor historical scoring differences due to late picks, replacement drivers, and other league adjustments.';
}

$underReview = false;
if ($selectedRace !== null) {
    $underReview = rrsg_race_is_pending_review((string)$selectedRace['raceFolder']);
}

$auditMeta = rrsg_build_public_audit_meta($selectedRaceMeta, $selectedRace, $underReview, $weeklyReleaseHistory);

$yearRaceOptions = rrsg_build_year_race_options($availableYears, $baseDir);

$weeklySummaryRows = rrsg_ranked_summary_rows(
    $selectedRaceWeeklyRows,
    'weeklyTotal',
    $weeklyTieMap,
    $weeklySpecialContext['markersByTeam'] ?? []
);
$segmentSummaryRows = rrsg_ranked_summary_rows($segmentStandings, 'total', $segmentTieMap);
$seasonSummaryRows = rrsg_ranked_summary_rows($seasonStandings, 'total', $seasonTieMap);
$weeklyWinnerSummaryRows = rrsg_weekly_winner_summary_rows($pointRaces, $weeklyWinners, $selectedRaceNumber);

$exportMode = isset($_GET['export']) ? trim((string)$_GET['export']) : '';
if ($exportMode === 'xlsx') {
    $weeklyExcelRows = [];
    foreach ($weeklySummaryRows as $row) {
        $teamDisplay = (string)$row['teamName'];
        if ((string)$row['marker'] !== '') {
            $teamDisplay .= ' ' . (string)$row['marker'];
        }
        $weeklyExcelRows[] = [
            'values' => [(string)$row['rank'], $teamDisplay, (int)$row['score']],
            'boldFirst' => !empty($row['isTie']),
        ];
    }

    $segmentExcelRows = [];
    foreach ($segmentSummaryRows as $row) {
        $segmentExcelRows[] = [
            'values' => [(string)$row['rank'], (string)$row['teamName'], (int)$row['score']],
            'boldFirst' => !empty($row['isTie']),
        ];
    }

    $seasonExcelRows = [];
    foreach ($seasonSummaryRows as $row) {
        $seasonExcelRows[] = [
            'values' => [(string)$row['rank'], (string)$row['teamName'], (int)$row['score']],
            'boldFirst' => !empty($row['isTie']),
        ];
    }

    $winnerExcelRows = [];
    foreach ($weeklyWinnerSummaryRows as $row) {
        $winnerExcelRows[] = [
            'values' => [(string)$row['week'], (string)$row['winner'], (int)$row['points']],
            'boldFirst' => !empty($row['isTie']),
        ];
    }

    $raceTitle = $selectedYear . ' ' . $selectedRaceMeta['raceDisplayLabel'];
    if ((string)$selectedRaceMeta['snapshotDisplay'] !== '') {
        $raceTitle .= ' (' . (string)$selectedRaceMeta['snapshotDisplay'] . ')';
    }

    $tables = [
        [
            'title' => $raceTitle,
            'headers' => ['#', 'Team', 'Week ' . (string)$selectedRaceNumber],
            'rows' => $weeklyExcelRows,
        ],
        [
            'title' => $selectedYear . ' ' . $scoreSegment,
            'headers' => ['#', 'Team', $scoreSegment],
            'rows' => $segmentExcelRows,
        ],
        [
            'title' => $selectedYear,
            'headers' => ['#', 'Team', $selectedYear],
            'rows' => $seasonExcelRows,
        ],
        [
            'title' => $selectedYear . ' Weekly Winners',
            'headers' => ['Week', 'Winner', 'Points'],
            'rows' => $winnerExcelRows,
        ],
    ];

    $filenameBase = 'weekly_standings_' . $selectedYear . '_' . $selectedRaceCode;
    if ($selectedRaceMeta['raceLabel'] !== '') {
        $filenameBase .= '_' . $selectedRaceMeta['raceLabel'];
    }

    rrsg_send_weekly_standings_xlsx(
        $filenameBase,
        $tables,
        'Copyright © 2017-' . $selectedYear . ' Manlius Racing League — All rights reserved.'
    );
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Weekly Standings - Segment Winner Color Test</title>
    <style>
        html {
            scrollbar-gutter: stable;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
            line-height: 1.3;
            margin: 12px;
            color: #111;
        }

        .page-wrap {
            /* max-width: 1750px; */
            max-width: 1400px;
            margin: 0 auto;
        }

        .top-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
            gap: 8px 12px;
            margin-bottom: 6px;
        }

        .top-controls-left,
        .top-controls-right {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px 10px;
        }

        .top-controls select,
        .top-controls button {
            font: inherit;
            padding: 1px 8px;
        }

        .top-controls button {
            cursor: pointer;
        }


        .top-controls-actions {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 6px 10px;
            margin-left: auto;
        }

        .report-action-btn {
            min-width: 92px;
            border: 2px solid #777;
            border-radius: 3px;
            background: #f2f2f2;
            color: #111;
        }

        .report-action-btn:hover {
            filter: brightness(0.96);
        }

        .report-action-btn[disabled] {
            cursor: default;
            opacity: 0.5;
            filter: none;
        }

        .live-btn {
            min-width: 66px;
            font-weight: bold;
            border-radius: 18px;
            background: #d9ecff;
            color: #084298;
            border: 3px solid #7db7ff;
        }

        .live-btn:hover {
            filter: brightness(0.97);
        }

        .live-btn.disabled,
        .live-btn[disabled] {
            cursor: default;
            opacity: 0.5;
            color: #5f6f82;
            background: #eef5fb;
            border-color: #c5d7e7;
            filter: none;
        }

        .live-btn.disabled:hover,
        .live-btn[disabled]:hover {
            filter: none;
        }

        .nav-button {
            min-width: 34px;
            text-align: center;
            padding-left: 6px;
            padding-right: 6px;
        }

        .nav-button[disabled] {
            cursor: default;
            opacity: 0.5;
            color: #666;
            background: #f3f3f3;
        }

        .details-toggle {
            font: inherit;
            padding: 2px 8px;
            cursor: pointer;
        }

        .historical-note-slot {
            display: inline-block;
            width: 460px;
            max-width: 460px;
            min-height: 1.2em;
            margin-left: 6px;
            font-size: 11px;
            font-style: italic;
            color: #0f0d0d;
            white-space: normal;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: top;
            overflow-wrap: anywhere;
            word-break: normal;
        }

        .release-version-row {
            display: flex;
            align-items: center;
            gap: 8px 10px;
            min-height: 32px;
            margin: -2px 0 6px 0;
            padding-left: 82px;
            box-sizing: border-box;
            flex-wrap: wrap;
        }

        .release-status-pill,
        .release-change-pill,
        .timeline-link-pill {
            display: inline-block;
            border-radius: 15px;
            padding: 2px 10px;
            font-size: 13px;
            font-weight: bold;
            line-height: 1.25;
            white-space: nowrap;
        }

        .release-status-pill.current {
            background: #2e8b57;
            border: 2px solid #1f5f3b;
            color: #fff;
        }

        .release-status-pill.superseded {
            background: #c00000;
            border: 2px solid #7a0000;
            color: #fff;
        }

        .release-status-pill.none {
            background: #e6e6e6;
            border: 2px solid #c8c8c8;
            color: #666;
        }

        .release-version-select {
            font: inherit;
            min-width: 210px;
            max-width: 270px;
            padding: 1px 8px;
        }

        .release-change-pill {
            background: #2e8b57;
            border: 2px solid #1f5f3b;
            color: #fff;
            min-width: 220px;
            text-align: center;
        }

        .release-change-pill.empty {
            visibility: hidden;
        }

        .timeline-link-pill {
            background: #d9ecff;
            border: 2px solid #7db7ff;
            color: #084298;
            text-decoration: none;
        }
        .details-content {
            display: none;
            padding: 6px 0 8px 0;
            margin: 0 0 8px 0;
            background: transparent;
            border: none;
        }

        .validation-btn {
            font-weight: bold;
            min-width: 125px;
            border-radius: 25px;
        }

        .validation-btn.status-pass {
            background: #2e8b57;
            color: #fff;
            border: 3px solid #1f5f3b;
        }

        .validation-btn.status-warn {
            background: #f1c232;
            color: #000;
            border: 3px solid #b8961c;
        }

        .validation-btn.status-fail {
            background: #c00000;
            color: #fff;
            border: 3px solid #7a0000;
        }

        .validation-btn.status-neutral {
            background: #e6e6e6;
            color: #666;
            border: 3px solid #c8c8c8;
        }

        .validation-btn:hover {
            filter: brightness(0.95);
        }

        .validation-btn[disabled] {
            cursor: default;
            filter: none;
        }

        .validation-btn[disabled]:hover {
            filter: none;
        }

        .pending-review-btn {
            font-weight: bold;
            min-width: 145px;
            border-radius: 25px;
            background: #f1c232;
            color: #000;
            border: 3px solid #b8961c;
        }

        .pending-review-btn:hover {
            filter: brightness(0.95);
        }

        .pending-review-panel {
            display: none;
            margin: 6px 0 8px 0;
            padding: 8px 10px;
            font-size: 13px;
            line-height: 1.35;
            background: #fff7d6;
            border: 2px solid #e2c14c;
            color: #444;
            border-radius: 8px;
            max-width: 560px;
        }

        .audit-btn {
            font-weight: bold;
            min-width: 82px;
            border-radius: 25px;
            background: #d9ecff;
            color: #084298;
            border: 3px solid #7db7ff;
        }

        .audit-btn:hover {
            filter: brightness(0.96);
        }

        .audit-btn[disabled] {
            cursor: default;
            opacity: 0.5;
            filter: none;
        }

        .audit-panel {
            display: none;
            margin: 6px 0 8px 0;
            padding: 8px 10px;
            font-size: 13px;
            line-height: 1.35;
            background: #eef6ff;
            border: 2px solid #9ec5fe;
            color: #25364a;
            border-radius: 8px;
            max-width: 980px;
            box-sizing: border-box;
            overflow-x: auto;
        }

        .audit-panel-title {
            font-weight: bold;
            font-size: 15px;
            margin: 0 0 6px 0;
        }

        .audit-grid {
            display: grid;
            grid-template-columns: max-content minmax(0, 1fr);
            gap: 3px 10px;
        }

        .audit-label {
            font-weight: bold;
            white-space: nowrap;
        }

        .audit-value {
            min-width: 0;
        }

        .audit-debug {
            margin-top: 6px;
            font-size: 11px;
            color: #4f5f70;
        }

        .audit-ladder {
            margin-top: 10px;
            border-top: 1px solid #bfd9ff;
            padding-top: 8px;
        }

        .audit-ladder-title {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .audit-ladder-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            font-size: 12px;
            background: rgba(255,255,255,0.45);
        }

        .audit-ladder-table th,
        .audit-ladder-table td {
            border: 1px solid #bfd9ff;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }

        .audit-ladder-table th {
            background: #ddecff;
            font-weight: bold;
        }

        .audit-ladder-current {
            outline: 2px solid #7db7ff;
            background: #f7fbff;
        }

        .audit-current-pill {
            display: inline-block;
            margin-left: 5px;
            padding: 1px 6px;
            border-radius: 12px;
            background: #cfe2ff;
            color: #084298;
            font-size: 11px;
            font-weight: bold;
        }

        .race-placeholder {
            padding: 14px 0 10px 0;
            font-size: 12px;
            color: #666;
        }

        .details-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 14px;
            font-size: 12px;
            margin: 0 0 10px 0;
        }

        .details-meta .chunk {
            white-space: nowrap;
        }

        .details-meta strong {
            font-weight: bold;
        }

        .validation-columns {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 10px;
        }

        .validation-column {
            min-width: 240px;
            flex: 1 1 240px;
        }

        .validation-column h3 {
            margin: 0 0 4px 0;
            font-size: 16px;
        }

        .validation-column ul {
            margin: 0;
            padding-left: 18px;
        }

        .validation-column li {
            margin-bottom: 2px;
        }

        .debug-title {
            margin: 8px 0 4px 0;
            font-size: 16px;
            font-weight: bold;
        }

        .report-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            align-items: start;
        }

        .report-panel {
            min-width: 0;
        }

        .panel-title {
            font-size: 15px;
            margin: 10px 0 4px 0;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            table-layout: auto;
            font-size: 16px;
        }

        .report-grid table {
            width: 100%;
            table-layout: fixed;
        }

        th, td {
            border: 2px solid #151313;
            padding: 0px 7px;
            text-align: center;
            vertical-align: top;
            white-space: nowrap;
            background: var(--row-odd);
        }

        th {
            background: #fbff00;
            font-weight: bold;
        }

        td.num {
            text-align: center;
            white-space: nowrap;
        }

        /* ── Table Row Colors - Striping — edit here to change all tables ──────── */
        /* ── original colors ──
        /* :root {
            --row-odd:    #ffffff;
            --row-even:   #dce6f1;
            --row-detail: #f4f4f4;
        } */

        :root {
            --row-odd:    #ffffff;
            --row-even:   #d2e5f7;
            --row-detail: #f4f4f4;
        }

        tbody tr:nth-child(even):not(.stripe-a):not(.stripe-b):not(.team-detail-row) td {
            background: var(--row-even);
        }

        /* Manual stripe classes for tables with expandable detail rows
           (nth-child counts hidden rows and breaks the pattern) */
        .stripe-a td {
            background: var(--row-odd);
        }

        .stripe-b td {
            background: var(--row-even);
        }

        th.team-col,
        td.team-col {
            text-align: left;
        }

        th.debug-text-col,
        td.debug-text-col {
            text-align: left;
        }

        .col-rank,
        .col-week {
            width: 42px;
        }

        .col-score {
            width: 56px;
        }

        .weekly-click-row td {
            transition: none;
        }

        /* ==========================================================
           SHARED DETAIL SYSTEM (FOUNDATION FOR LATER TABLES)
           ========================================================== */

        .team-detail-row > td {
            background: var(--row-detail) !important;
            padding: 4px 8px 4px 8px;
        }

        .team-detail-wrap {
            width: 100%;
        }

        .team-detail-line {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 62px;
            align-items: center;
            gap: 8px;
            min-height: 20px;
            width: 100%;
        }

        .team-detail-line + .team-detail-line {
            margin-top: 1px;
        }

        .team-detail-driver {
            text-align: left;
            padding-left: 18px;
            white-space: nowrap;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .team-detail-label-wrap {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .team-detail-points {
            text-align: center;
            white-space: nowrap;
            background: var(--row-detail);
        }

        .team-detail-total .team-detail-driver,
        .team-detail-total .team-detail-points {
            font-weight: bold;
        }

        .team-detail-note {
            text-align: left;
            padding-left: 18px;
            padding-bottom: 4px;
            font-size: 13px;
            color: #333;
            font-style: italic;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .table-footnote {
            margin-top: 4px;
            margin-left: 10px;
            font-size: 16px;
            color: #444;
            font-style: italic;
        }

        .footnote-block {
            margin-top: 4px;
            margin-left: 10px;
            font-size: 13px;
            line-height: 1.35;
            color: #444;
            font-style: italic;
            font-weight: 500;
            text-align: left;
        }

        .footnote-line + .footnote-line {
            margin-top: 2px;
        }

        .footnote-entry {
            padding-left: 18px;
        }

        .tie-rank {
            font-weight: bold;
        }

        .pick-marker {
            font-weight: bold;
        }

        .winner-footnote {
            margin-top: 4px;
            margin-left: 10px;
            font-size: 16px;
            color: #444;
            font-style: italic;
        }

        .snapshot-footnote {
            margin-left: 6px;
            font-size: 0.82em;
            color: #777;
            font-style: normal;
            white-space: nowrap;
        }


        /* ── TEST ONLY: Weekly Winners segment background colors ─────────────
           Source match target from Jeff's official worksheet/PDF:
           S1/R01-R08: #c5d9f1 light blue
           S2/R09-R17: #c4bd97 tan/gray
           S3/R18-R26: #fcd5b4 peach
           S4/R27-R36: #c4d79b green
           This overrides normal striping only for Table 4 winner rows.
        */
        .weekly-winner-segment-row.weekly-winner-segment-S1 td { background: #c5d9f1 !important; }
        .weekly-winner-segment-row.weekly-winner-segment-S2 td { background: #c4bd97 !important; }
        .weekly-winner-segment-row.weekly-winner-segment-S3 td { background: #fcd5b4 !important; }
        .weekly-winner-segment-row.weekly-winner-segment-S4 td { background: #c4d79b !important; }
        .weekly-winner-segment-row td {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        @media print {
            @page {
                size: landscape;
                margin: 0.25in;
            }

            html,
            body {
                margin: 0;
                padding: 0;
                color: #000;
                background: #fff;
            }

            .page-wrap {
                max-width: none;
                width: 100%;
                margin: 0;
            }

            .top-controls,
            .pending-review-panel,
            .audit-panel,
            .race-placeholder,
            .details-content,
            .team-detail-row {
                display: none !important;
            }

            #resultsArea {
                display: block !important;
            }

            .report-grid {
                display: grid !important;
                grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
                gap: 10px;
                align-items: start;
                page-break-inside: avoid;
            }

            .table-wrap {
                overflow: visible !important;
            }

            .panel-title {
                font-size: 12px;
                margin: 0 0 3px 0;
            }

            table {
                font-size: 10px;
                width: 100%;
                table-layout: fixed;
                page-break-inside: avoid;
            }

            th,
            td {
                border: 1px solid #151313;
                padding: 1px 4px;
                white-space: nowrap;
            }

            .team-col {
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .footnote-block,
            .winner-footnote,
            .table-footnote {
                font-size: 9px;
            }
        }

        @media (max-width: 1500px) {
            .report-grid {
                grid-template-columns: minmax(280px, 1fr) minmax(280px, 1fr);
            }

            .historical-note-slot {
                max-width: 480px;
            }
        }

        @media (max-width: 760px) {
            body {
                margin: 8px;
                font-size: 13px;
            }

            .top-controls {
                flex-wrap: wrap;
                gap: 4px 8px;
            }

            .top-controls select,
            .top-controls button {
                font-size: 12px;
                padding: 2px 6px;
            }

            .live-btn {
                min-width: 58px;
            }

            .pending-review-btn {
                min-width: 132px;
            }

            .pending-review-panel {
                font-size: 12px;
                padding: 8px 9px;
                max-width: 100%;
            }

            .historical-note-slot {
                width: 100%;
                white-space: normal;
                min-height: 1.2em;
                margin-left: 0;
            }

            .details-meta {
                display: block;
                font-size: 11px;
                margin-bottom: 8px;
            }

            .details-meta .chunk {
                display: block;
                white-space: normal;
                margin-bottom: 3px;
            }

            .report-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 4px 6px;
            }

            .team-detail-driver,
            .team-detail-note {
                padding-left: 10px;
            }
        }
    </style>
</head>
<body>

<div class="page-wrap">

    <form id="weeklyStandingsForm" method="get" action=""></form>

    <div class="top-controls">
        <div class="top-controls-left">
            <button type="button"
                    class="live-btn<?php echo ($isLiveView ? ' disabled' : ''); ?>"
                    id="liveBtn"
                    onclick="goLiveView()"
                    title="Go to live view"
                    <?php echo ($isLiveView ? 'disabled' : ''); ?>>
                Live
            </button>

            <select name="year" id="year" form="weeklyStandingsForm" aria-label="Year">
                <?php foreach ($availableYearsAsc as $yearOpt): ?>
                    <option value="<?php echo rrsg_h($yearOpt); ?>" <?php echo ($yearOpt === $selectedYear ? 'selected' : ''); ?>>
                        <?php echo rrsg_h($yearOpt); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="race" id="race" form="weeklyStandingsForm" aria-label="Race">
                <option value="">Select Race</option>
                <?php foreach ($selectablePointRacesAsc as $raceOpt): ?>
                    <option value="<?php echo rrsg_h($raceOpt['raceCode']); ?>" <?php echo ($raceOpt['raceCode'] === $selectedRaceCode ? 'selected' : ''); ?>>
                        <?php echo rrsg_h(rrsg_revision_display_label_for_race($raceOpt, $selectablePointRacesAsc)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="nav-button" id="navPrevBtn" onclick="navigateRace(-1)" title="Previous Race">&lt;&lt;</button>
            <button type="button" class="nav-button" id="navNextBtn" onclick="navigateRace(1)" title="Next Race">&gt;&gt;</button>
        </div>

        <div class="top-controls-right">
            <button type="button"
                    class="details-toggle validation-btn <?php echo rrsg_h($validationButtonClass); ?>"
                    id="detailsToggle"
                    onclick="toggleDetails()"
                    <?php echo ($selectedRace === null ? 'disabled' : ''); ?>>
                Show Validation
            </button>

            <button type="button"
                    class="details-toggle audit-btn"
                    id="auditToggle"
                    onclick="toggleAuditPanel()"
                    <?php echo ($selectedRace === null ? 'disabled' : ''); ?>>
                Audit
            </button>

            <?php if ($underReview): ?>
                <button type="button"
                        class="details-toggle pending-review-btn"
                        id="reviewToggle"
                        onclick="toggleReviewPanel()">
                    ⚠ Pending Review
                </button>
            <?php endif; ?>

            <span class="historical-note-slot" id="historicalNoteSlot"><?php echo ($historicalNote !== '' ? rrsg_h($historicalNote) : '&nbsp;'); ?></span>
        </div>

        <div class="top-controls-actions">
            <button type="button"
                    class="report-action-btn"
                    id="weeklyPrintBtn"
                    onclick="printWeeklyReport()"
                    <?php echo ($selectedRace === null ? 'disabled' : ''); ?>>Print</button>
            <button type="button"
                    class="report-action-btn"
                    id="weeklySpreadsheetBtn"
                    onclick="exportWeeklyStandingsXlsx()"
                    <?php echo ($selectedRace === null ? 'disabled' : ''); ?>>Spreadsheet</button>
        </div>
    </div>

    <div class="release-version-row" id="releaseVersionRow">
        <?php if ($selectedRace !== null && !empty($selectedVersionRelease)): ?>
            <span class="release-status-pill <?php echo rrsg_h((string)$selectedReleaseStatus['class']); ?>"><?php echo rrsg_h((string)$selectedReleaseStatus['label']); ?></span>
            <select name="release" id="release" form="weeklyStandingsForm" class="release-version-select" aria-label="Race version" onchange="document.getElementById('weeklyStandingsForm').submit();">
                <?php foreach ($selectedVersionReleaseRows as $idxRelease => $releaseRow): ?>
                    <?php if (!is_array($releaseRow)) continue; ?>
                    <?php $releaseOptionId = rrsg_release_id_for_ui($releaseRow); ?>
                    <option value="<?php echo rrsg_h($releaseOptionId); ?>" <?php echo ($releaseOptionId === $selectedReleaseId ? 'selected' : ''); ?>>
                        <?php echo rrsg_h(rrsg_release_version_option_label($releaseRow, $idxRelease + 1)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="release-change-pill <?php echo ($selectedReleaseChangeLabel === '' ? 'empty' : ''); ?>"><?php echo rrsg_h($selectedReleaseChangeLabel !== '' ? $selectedReleaseChangeLabel : 'No change label'); ?></span>
            <?php if ($selectedReleaseTimelineUrl !== ''): ?>
                <a class="timeline-link-pill" href="<?php echo rrsg_h($selectedReleaseTimelineUrl); ?>">As-of</a>
            <?php endif; ?>
        <?php else: ?>
            <span class="release-status-pill none">No version</span>
            <select class="release-version-select" disabled><option>No release history</option></select>
            <span class="release-change-pill empty">No change label</span>
        <?php endif; ?>
    </div>

    <?php if ($underReview): ?>
        <div class="pending-review-panel" id="reviewPanel">
            Results generated automatically. Pending league review.
        </div>
    <?php endif; ?>

    <?php if ($selectedRace !== null): ?>
        <div class="audit-panel" id="auditPanel">
            <div class="audit-panel-title">Audit Trail</div>
            <div class="audit-grid">
                <div class="audit-label">Release:</div>
                <div class="audit-value"><?php echo rrsg_h((string)($auditMeta['release_type_label'] ?? 'Initial release')); ?></div>

                <div class="audit-label">Released:</div>
                <div class="audit-value"><?php echo rrsg_h((string)($auditMeta['released_display'] ?? '')); ?></div>

                <div class="audit-label">Status:</div>
                <div class="audit-value"><?php echo rrsg_h((string)($auditMeta['status_label'] ?? 'Official standings release')); ?></div>

                <div class="audit-label">Reason:</div>
                <div class="audit-value"><?php echo rrsg_h((string)($auditMeta['reason'] ?? 'Initial standings release.')); ?></div>

                <div class="audit-label">Supersedes:</div>
                <div class="audit-value"><?php echo rrsg_h((string)($auditMeta['supersedes_label'] ?? 'None')); ?></div>

                <div class="audit-label">MRL Impact:</div>
                <div class="audit-value"><?php echo rrsg_h((string)($auditMeta['mrl_impact_label'] ?? 'Not applicable')); ?></div>

                <?php if (!empty($auditMeta['change_summary_label'])): ?>
                    <div class="audit-label">Change:</div>
                    <div class="audit-value"><?php echo rrsg_h((string)$auditMeta['change_summary_label']); ?></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($auditMeta['generated_id'])): ?>
                <div class="audit-debug">Release ID: <?php echo rrsg_h((string)$auditMeta['generated_id']); ?></div>
            <?php endif; ?>

            <?php if (!empty($auditMeta['release_ladder']) && is_array($auditMeta['release_ladder']) && count($auditMeta['release_ladder']) > 1): ?>
                <div class="audit-ladder">
                    <div class="audit-ladder-title">Release history for <?php echo rrsg_h($selectedRaceCode . ($selectedRaceMeta['raceLabel'] !== '' ? ' ' . $selectedRaceMeta['raceLabel'] : '')); ?></div>
                    <table class="audit-ladder-table">
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Released</th>
                                <th>Status</th>
                                <th>MRL Impact</th>
                                <th>Supersedes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditMeta['release_ladder'] as $ladderRow): ?>
                                <tr class="<?php echo (!empty($ladderRow['is_current']) ? 'audit-ladder-current' : ''); ?>">
                                    <td>
                                        <?php echo rrsg_h((string)($ladderRow['release_type_label'] ?? 'Release')); ?>
                                        <?php if (!empty($ladderRow['is_current'])): ?>
                                            <span class="audit-current-pill">current</span>
                                        <?php endif; ?>
                                        <?php if (!empty($ladderRow['change_summary_label'])): ?>
                                            <br><small><?php echo rrsg_h((string)$ladderRow['change_summary_label']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo rrsg_h((string)($ladderRow['released_display'] ?? '')); ?></td>
                                    <td><?php echo rrsg_h((string)($ladderRow['status_label'] ?? '')); ?></td>
                                    <td><?php echo rrsg_h((string)($ladderRow['mrl_impact_label'] ?? 'Not applicable')); ?></td>
                                    <td><?php echo rrsg_h((string)($ladderRow['supersedes_label'] ?? 'None')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="race-placeholder" id="racePlaceholder" <?php echo ($selectedRace !== null ? 'style="display:none;"' : ''); ?>>
        Select a race to view results
    </div>

    <div id="resultsArea" <?php echo ($selectedRace === null ? 'style="display:none;"' : ''); ?>>
        <div class="details-content" id="detailsContent">
            <div class="details-meta">
                <span class="chunk"><strong>Scoring:</strong> <?php echo rrsg_h($scoreYear . ' / ' . $scoreSegment . ' / ' . $selectedRaceDisplay); ?></span>
                <span class="chunk"><strong>Teams:</strong> <?php echo count($teamRows); ?></span>
                <span class="chunk"><strong>Drivers:</strong> <?php echo rrsg_h($selectedRaceMeta['driverCount']); ?></span>
                <span class="chunk"><strong>Snapshot:</strong> <?php echo rrsg_h($selectedRaceMeta['snapshotFile'] !== '' ? basename($selectedRaceMeta['snapshotFile']) : 'NOT FOUND'); ?></span>
            </div>

            <div class="validation-columns">
                <div class="validation-column">
                    <h3>PASS</h3>
                    <ul>
                        <?php if (empty($validation['pass'])): ?>
                            <li>None</li>
                        <?php else: ?>
                            <?php foreach ($validation['pass'] as $msg): ?>
                                <li><?php echo rrsg_h($msg); ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="validation-column">
                    <h3>WARN</h3>
                    <ul>
                        <?php if (empty($validation['warn'])): ?>
                            <li>None</li>
                        <?php else: ?>
                            <?php foreach ($validation['warn'] as $msg): ?>
                                <li><?php echo rrsg_h($msg); ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="validation-column">
                    <h3>FAIL</h3>
                    <ul>
                        <?php if (empty($validation['fail'])): ?>
                            <li>None</li>
                        <?php else: ?>
                            <?php foreach ($validation['fail'] as $msg): ?>
                                <li><?php echo rrsg_h($msg); ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="debug-title"><?php echo rrsg_h($selectedYear); ?> Debug Race Build Through <?php echo rrsg_h($selectedRaceCode); ?></div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="debug-text-col">Race</th>
                            <th class="col-rank">#</th>
                            <th>Segment</th>
                            <th>Teams</th>
                            <th>Snapshot Used</th>
                            <th class="debug-text-col">Computed Winner</th>
                            <th class="col-score">Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($debugRows)): ?>
                            <tr>
                                <td colspan="7">No debug rows generated.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($debugRows as $row): ?>
                                <tr>
                                    <td class="debug-text-col"><?php echo rrsg_h($row['raceCode'] . ' ' . $row['raceLabel']); ?></td>
                                    <td class="num"><?php echo rrsg_h($row['raceNumber']); ?></td>
                                    <td><?php echo rrsg_h($row['raceSegment']); ?></td>
                                    <td class="num"><?php echo rrsg_h($row['teamsLoaded']); ?></td>
                                    <td><?php echo rrsg_h($row['snapshotBase']); ?></td>
                                    <td class="debug-text-col"><?php echo rrsg_h($row['winnerTeam']); ?></td>
                                    <td class="num"><?php echo rrsg_h($row['winnerPoints']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="report-grid">
            <div class="report-panel">
                <div class="panel-title"><?php echo rrsg_h($selectedYear . ' ' . $selectedRaceMeta['raceDisplayLabel']); ?><?php if ((string)$selectedRaceMeta['snapshotDisplay'] !== ''): ?> <span class="snapshot-footnote">(<?php echo rrsg_h($selectedRaceMeta['snapshotDisplay']); ?>)</span><?php endif; ?></div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-rank">#</th>
                                <th class="team-col">Team</th>
                                <th class="col-score">Week <?php echo rrsg_h((string)$selectedRaceNumber); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($selectedRaceWeeklyRows)): ?>
                                <tr>
                                    <td colspan="3">No weekly rows generated.</td>
                                </tr>
                            <?php else: ?>
                                <?php $rank = 1; $displayRank = 0; $prevRankScore = null; ?>
                                <?php foreach ($selectedRaceWeeklyRows as $row): ?>
                                    <?php
                                    $currentRankScore = (int)($row['weeklyTotal'] ?? 0);
                                    if ($prevRankScore === null || $currentRankScore !== $prevRankScore) {
                                        $displayRank = $rank;
                                        $prevRankScore = $currentRankScore;
                                    }
                                    ?>
                                    <?php
                                    $detailId = 'weekly-detail-' . $rank;
                                    $stripeClass = ($rank % 2 === 1) ? 'stripe-a' : 'stripe-b';
                                    $teamName = (string)($row['teamName'] ?? '');
                                    $teamMarker = (string)($weeklySpecialContext['markersByTeam'][$teamName] ?? '');
                                    $teamDisplay = $teamName;
                                    if ($teamMarker !== '') {
                                        $teamDisplay .= ' ' . $teamMarker;
                                    }
                                    $rankDisplay = (string)$displayRank;
                                    ?>
                                    <tr
                                        class="team-row weekly-click-row <?php echo $stripeClass; ?>"
                                        onclick="toggleWeeklyDetail('<?php echo rrsg_h($detailId); ?>', this)"
                                    >
                                        <td class="num"><?php
                                            $isTieRank = isset($weeklyTieMap[$teamName]);
                                            if ($isTieRank) {
                                                echo '<span class="tie-rank">' . rrsg_h($rankDisplay) . '</span>';
                                            } else {
                                                echo rrsg_h($rankDisplay);
                                            }
                                            ?></td>
                                        <td class="team-col"><?php
                                            if ($teamMarker !== '') {
                                                echo rrsg_h($teamName) . ' <span class="pick-marker">' . rrsg_h($teamMarker) . '</span>';
                                            } else {
                                                echo rrsg_h($teamDisplay);
                                            }
                                            ?></td>
                                        <td class="num"><?php echo rrsg_h($row['weeklyTotal']); ?></td>
                                    </tr>
                                    <tr class="team-detail-row" id="<?php echo rrsg_h($detailId); ?>" style="display:none;">
                                        <td></td>
                                        <td colspan="2">
                                            <div class="team-detail-wrap">
                                                <?php
                                                $hasAnyDrivers =
                                                    ((string)$row['driverA'] !== '') ||
                                                    ((string)$row['driverB'] !== '') ||
                                                    ((string)$row['driverC'] !== '') ||
                                                    ((string)$row['driverD'] !== '');
                                                $detailNoteText = (string)($weeklySpecialContext['detailNoteByTeam'][$teamName] ?? '');
                                                $detailChangedField = (string)($weeklySpecialContext['changedFieldByTeam'][$teamName] ?? '');
                                                ?>
                                                <?php if ($detailNoteText !== ''): ?>
                                                    <div class="team-detail-note"><span class="pick-marker"><?php echo rrsg_h($teamMarker); ?></span> <?php echo rrsg_h($detailNoteText); ?></div>
                                                <?php endif; ?>

                                                <?php if ($hasAnyDrivers): ?>
                                                    <?php
                                                    $driverFields = [
                                                        ['field' => 'driverA', 'net' => 'netA'],
                                                        ['field' => 'driverB', 'net' => 'netB'],
                                                        ['field' => 'driverC', 'net' => 'netC'],
                                                        ['field' => 'driverD', 'net' => 'netD'],
                                                    ];
                                                    ?>
                                                    <?php foreach ($driverFields as $driverMeta): ?>
                                                        <?php
                                                        $field = $driverMeta['field'];
                                                        $netKey = $driverMeta['net'];
                                                        $driverName = (string)($row[$field] ?? '');
                                                        if ($driverName === '') {
                                                            continue;
                                                        }

                                                        $driverDisplay = $driverName;
                                                        if ($teamMarker !== '') {
                                                            $pickType = strtoupper((string)($row['pick_type'] ?? ''));
                                                            if ($pickType === 'LP') {
                                                                $driverDisplay .= ' ' . $teamMarker;
                                                            } elseif ($pickType === 'RD' && $detailChangedField === $field) {
                                                                $driverDisplay .= ' ' . $teamMarker;
                                                            }
                                                        }
                                                        ?>
                                                        <div class="team-detail-line">
                                                            <div class="team-detail-driver"><?php
                                                                if ($teamMarker !== '' && substr($driverDisplay, -strlen($teamMarker)) === $teamMarker) {
                                                                    $driverBaseDisplay = substr($driverDisplay, 0, -strlen($teamMarker));
                                                                    echo rrsg_h(rtrim($driverBaseDisplay)) . ' <span class="pick-marker">' . rrsg_h($teamMarker) . '</span>';
                                                                } else {
                                                                    echo rrsg_h($driverDisplay);
                                                                }
                                                                ?></div>
                                                            <div class="team-detail-points"><?php echo rrsg_h($row[$netKey]); ?></div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <div class="team-detail-line">
                                                        <div class="team-detail-driver"><?php echo rrsg_h(rrsg_no_picks_message($row)); ?></div>
                                                        <div class="team-detail-points">0</div>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="team-detail-line team-detail-total">
                                                    <div class="team-detail-driver">Total</div>
                                                    <div class="team-detail-points"><?php echo rrsg_h($row['weeklyTotal']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php $rank++; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($selectedRace !== null && (!empty($weeklySpecialContext['latePickEntries']) || !empty($weeklySpecialContext['replacementDriverEntries']))): ?>
                    <div class="footnote-block">
                        <?php if (!empty($weeklySpecialContext['latePickEntries'])): ?>
                            <div class="footnote-line"><span class="pick-marker"><?php echo rrsg_h($weeklySpecialContext['lpMarker']); ?></span> <?php echo rrsg_h('Late Pick:'); ?></div>
                            <?php foreach ($weeklySpecialContext['latePickEntries'] as $entryText): ?>
                                <div class="footnote-line footnote-entry"><?php echo rrsg_h($entryText); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($weeklySpecialContext['replacementDriverEntries'])): ?>
                            <div class="footnote-line"><span class="pick-marker"><?php echo rrsg_h($weeklySpecialContext['rdMarker']); ?></span> <?php echo rrsg_h('Replacement Driver:'); ?></div>
                            <?php foreach ($weeklySpecialContext['replacementDriverEntries'] as $entryText): ?>
                                <div class="footnote-line footnote-entry"><?php echo rrsg_h($entryText); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="report-panel">
                <div class="panel-title"><?php echo rrsg_h($selectedYear . ' ' . $scoreSegment); ?></div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-rank">#</th>
                                <th class="team-col">Team</th>
                                <th class="col-score"><?php echo rrsg_h($scoreSegment); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($segmentStandings)): ?>
                                <tr>
                                    <td colspan="3">No segment standings generated.</td>
                                </tr>
                            <?php else: ?>
                                <?php $rank = 1; $displayRank = 0; $prevRankScore = null; ?>
                                <?php foreach ($segmentStandings as $row): ?>
                                    <?php
                                    $currentRankScore = (int)($row['total'] ?? 0);
                                    if ($prevRankScore === null || $currentRankScore !== $prevRankScore) {
                                        $displayRank = $rank;
                                        $prevRankScore = $currentRankScore;
                                    }
                                    ?>
                                    <?php
                                    $detailId = 'segment-detail-' . $rank;
                                    $stripeClass = ($rank % 2 === 1) ? 'stripe-a' : 'stripe-b';
                                    $rankDisplay = (string)$displayRank;
                                    ?>
                                    <tr
                                        class="team-row weekly-click-row <?php echo $stripeClass; ?>"
                                        onclick="toggleWeeklyDetail('<?php echo rrsg_h($detailId); ?>', this)"
                                    >
                                        <td class="num"><?php
                                            $segmentTeamName = (string)$row['teamName'];
                                            $isTieRank = isset($segmentTieMap[$segmentTeamName]);
                                            if ($isTieRank) {
                                                echo '<span class="tie-rank">' . rrsg_h($rankDisplay) . '</span>';
                                            } else {
                                                echo rrsg_h($rankDisplay);
                                            }
                                            ?></td>
                                        <td class="team-col"><?php echo rrsg_h($row['teamName']); ?></td>
                                        <td class="num"><?php echo rrsg_h($row['total']); ?></td>
                                    </tr>
                                    <tr class="team-detail-row" id="<?php echo rrsg_h($detailId); ?>" style="display:none;">
                                        <td></td>
                                        <td colspan="2">
                                            <div class="team-detail-wrap">
                                                <?php foreach ($segmentBreakdownRows as $segmentRaceRow): ?>
                                                    <?php
                                                    $teamRacePoints = 0;
                                                    foreach ($segmentRaceRow['weeklyRows'] as $weeklyRow) {
                                                        if ((string)$weeklyRow['teamName'] === (string)$row['teamName']) {
                                                            $teamRacePoints = (int)$weeklyRow['weeklyTotal'];
                                                            break;
                                                        }
                                                    }
                                                    ?>
                                                    <div class="team-detail-line">
                                                        <div class="team-detail-driver team-detail-label-wrap">
                                                            <?php echo rrsg_h($segmentRaceRow['raceCode'] . ' ' . $segmentRaceRow['raceLabel']); ?>
                                                        </div>
                                                        <div class="team-detail-points"><?php echo rrsg_h($teamRacePoints); ?></div>
                                                    </div>
                                                <?php endforeach; ?>

                                                <div class="team-detail-line team-detail-total">
                                                    <div class="team-detail-driver">Total</div>
                                                    <div class="team-detail-points"><?php echo rrsg_h($row['total']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php $rank++; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>

            <div class="report-panel">
                <div class="panel-title"><?php echo rrsg_h($selectedYear); ?></div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-rank">#</th>
                                <th class="team-col">Team</th>
                                <th class="col-score"><?php echo rrsg_h($selectedYear); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($seasonStandings)): ?>
                                <tr>
                                    <td colspan="3">No season standings generated.</td>
                                </tr>
                            <?php else: ?>
                                <?php $rank = 1; $displayRank = 0; $prevRankScore = null; ?>
                                <?php foreach ($seasonStandings as $row): ?>
                                    <?php
                                    $currentRankScore = (int)($row['total'] ?? 0);
                                    if ($prevRankScore === null || $currentRankScore !== $prevRankScore) {
                                        $displayRank = $rank;
                                        $prevRankScore = $currentRankScore;
                                    }
                                    ?>
                                    <?php
                                    $detailId = 'year-detail-' . $rank;
                                    $stripeClass = ($rank % 2 === 1) ? 'stripe-a' : 'stripe-b';
                                    $rankDisplay = (string)$displayRank;
                                    ?>
                                    <tr
                                        class="team-row weekly-click-row <?php echo $stripeClass; ?>"
                                        onclick="toggleWeeklyDetail('<?php echo rrsg_h($detailId); ?>', this)"
                                    >
                                        <td class="num"><?php
                                            $seasonTeamName = (string)$row['teamName'];
                                            if (isset($seasonTieMap[$seasonTeamName])) {
                                                echo '<span class="tie-rank">' . rrsg_h($rankDisplay) . '</span>';
                                            } else {
                                                echo rrsg_h($rankDisplay);
                                            }
                                            ?></td>
                                        <td class="team-col"><?php echo rrsg_h($row['teamName']); ?></td>
                                        <td class="num"><?php echo rrsg_h($row['total']); ?></td>
                                    </tr>
                                    <tr class="team-detail-row" id="<?php echo rrsg_h($detailId); ?>" style="display:none;">
                                        <td></td>
                                        <td colspan="2">
                                            <div class="team-detail-wrap">
                                                <?php foreach ($visibleSegments as $segmentLabel): ?>
                                                    <?php
                                                    $segmentValue = 0;
                                                    if (isset($segmentHistory[$row['teamName']][$segmentLabel])) {
                                                        $segmentValue = (int)$segmentHistory[$row['teamName']][$segmentLabel];
                                                    }
                                                    ?>
                                                    <div class="team-detail-line">
                                                        <div class="team-detail-driver"><?php echo rrsg_h($segmentLabel); ?></div>
                                                        <div class="team-detail-points"><?php echo rrsg_h($segmentValue); ?></div>
                                                    </div>
                                                <?php endforeach; ?>

                                                <div class="team-detail-line team-detail-total">
                                                    <div class="team-detail-driver">Total</div>
                                                    <div class="team-detail-points"><?php echo rrsg_h($row['total']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php $rank++; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>

            <div class="report-panel">
                <div class="panel-title"><?php echo rrsg_h($selectedYear . ' Weekly Winners'); ?></div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-week">Week</th>
                                <th class="team-col">Winner</th>
                                <th class="col-score">Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($selectedRace === null): ?>
                                <tr>
                                    <td colspan="3">No race selected.</td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $winnerRows = $pointRaces;
                                usort($winnerRows, function ($a, $b) {
                                    return ((int)$a['number']) <=> ((int)$b['number']);
                                });

                                ?>
                                <?php foreach ($winnerRows as $race): ?>
                                    <?php if ((int)$race['number'] > $selectedRaceNumber) continue; ?>
                                    <?php $raceCode = (string)$race['raceCode']; ?>
                                    <?php
                                    $winnerNames = $weeklyWinners[$raceCode]['teamNames'] ?? [];
                                    $winnerPoints = (int)($weeklyWinners[$raceCode]['points'] ?? 0);
                                    $winnerWeekDisplay = (string)$race['number'];

                                    if (empty($winnerNames)) {
                                        $winnerNames = [];
                                        $fallbackWinner = (string)($weeklyWinners[$raceCode]['teamName'] ?? '');
                                        if ($fallbackWinner !== '') {
                                            $winnerNames[] = $fallbackWinner;
                                        }
                                    }

                                    $winnerIsTieWeek = (count($winnerNames) > 1);
                                    ?>

                                    <?php if (empty($winnerNames)): ?>
                                        <tr class="weekly-winner-segment-row weekly-winner-segment-<?php echo rrsg_h(rrsg_segment_from_race_number((int)$race['number'])); ?>">
                                            <td class="num"><?php echo rrsg_h($winnerWeekDisplay); ?></td>
                                            <td class="team-col"></td>
                                            <td class="num">0</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($winnerNames as $winnerName): ?>
                                            <tr class="weekly-winner-segment-row weekly-winner-segment-<?php echo rrsg_h(rrsg_segment_from_race_number((int)$race['number'])); ?>">
                                                <td class="num"><?php
                                                    if ($winnerIsTieWeek) {
                                                        echo '<span class="tie-rank">' . rrsg_h($winnerWeekDisplay) . '</span>';
                                                    } else {
                                                        echo rrsg_h($winnerWeekDisplay);
                                                    }
                                                ?></td>
                                                <td class="team-col"><?php echo rrsg_h($winnerName); ?></td>
                                                <td class="num"><?php echo rrsg_h($winnerPoints); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>

</div>

<script>
var rrsgYearRaceOptions = <?php echo json_encode($yearRaceOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var rrsgLiveUrl = <?php echo json_encode($liveUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var rrsgInitialLoad = true;

function rrsgPadRaceCode(num) {
    var n = parseInt(num, 10);
    if (isNaN(n)) {
        return '';
    }
    return 'R' + ('0' + n).slice(-2);
}

function rrsgRaceNumberFromCode(code) {
    var match = String(code || '').match(/^R(\d+)$/);
    return match ? parseInt(match[1], 10) : null;
}


function printWeeklyReport() {
    window.print();
}

function exportWeeklyStandingsXlsx() {
    var url = new URL(window.location.href);
    url.searchParams.set('export', 'xlsx');
    window.location.href = url.toString();
}

function goLiveView() {
    if (!rrsgLiveUrl) {
        return;
    }

    window.location.href = rrsgLiveUrl;
}

function repopulateRaceOptions() {
    var yearEl = document.getElementById('year');
    var raceEl = document.getElementById('race');
    var yearVal = yearEl ? yearEl.value : '';
    var raceList = rrsgYearRaceOptions[yearVal] || [];
    var i;
    var opt;

    if (!raceEl) {
        return;
    }

    raceEl.innerHTML = '';

    opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'Select Race';
    raceEl.appendChild(opt);

    for (i = 0; i < raceList.length; i++) {
        opt = document.createElement('option');
        opt.value = raceList[i].raceCode;
        opt.textContent = raceList[i].label;
        raceEl.appendChild(opt);
    }

    raceEl.value = '';
    updateNavButtons();
}

function setNoRaceSelectedState() {
    var detailsEl = document.getElementById('detailsContent');
    var detailsBtn = document.getElementById('detailsToggle');
    var auditBtn = document.getElementById('auditToggle');
    var auditPanel = document.getElementById('auditPanel');
    var resultsArea = document.getElementById('resultsArea');
    var placeholderEl = document.getElementById('racePlaceholder');
    var noteEl = document.getElementById('historicalNoteSlot');

    if (detailsEl) {
        detailsEl.style.display = 'none';
    }

    if (detailsBtn) {
        detailsBtn.textContent = 'Show Validation';
        detailsBtn.disabled = true;
        detailsBtn.classList.remove('status-pass', 'status-warn', 'status-fail');
        detailsBtn.classList.add('status-neutral');
    }

    if (auditPanel) {
        auditPanel.style.display = 'none';
    }

    if (auditBtn) {
        auditBtn.disabled = true;
    }

    if (resultsArea) {
        resultsArea.style.display = 'none';
    }

    if (placeholderEl) {
        placeholderEl.style.display = 'block';
    }

    if (noteEl) {
        noteEl.innerHTML = '&nbsp;';
    }
}

function updateNavButtons() {
    var raceEl = document.getElementById('race');
    var prevBtn = document.getElementById('navPrevBtn');
    var nextBtn = document.getElementById('navNextBtn');
    var optionNumbers = [];
    var currentNumber = rrsgRaceNumberFromCode(raceEl ? raceEl.value : '');
    var i;
    var idx = -1;

    if (!raceEl || !prevBtn || !nextBtn) {
        return;
    }

    for (i = 0; i < raceEl.options.length; i++) {
        var raceNum = rrsgRaceNumberFromCode(raceEl.options[i].value);
        if (raceNum !== null) {
            optionNumbers.push(raceNum);
        }
    }

    optionNumbers.sort(function (a, b) {
        return a - b;
    });

    for (i = 0; i < optionNumbers.length; i++) {
        if (optionNumbers[i] === currentNumber) {
            idx = i;
            break;
        }
    }

    prevBtn.disabled = (idx <= 0);
    nextBtn.disabled = (idx < 0 || idx >= optionNumbers.length - 1);
}

function rrsgClearReleaseBeforeRaceChange() {
    var releaseEl = document.getElementById('release');
    if (releaseEl) {
        releaseEl.disabled = true;
    }
}
function navigateRace(direction) {
    var raceEl = document.getElementById('race');
    var formEl = document.getElementById('weeklyStandingsForm');
    var optionNumbers = [];
    var currentNumber = rrsgRaceNumberFromCode(raceEl ? raceEl.value : '');
    var i;
    var idx = -1;
    var targetNumber;

    if (!raceEl || !formEl) {
        return;
    }

    for (i = 0; i < raceEl.options.length; i++) {
        var raceNum = rrsgRaceNumberFromCode(raceEl.options[i].value);
        if (raceNum !== null) {
            optionNumbers.push(raceNum);
        }
    }

    optionNumbers.sort(function (a, b) {
        return a - b;
    });

    for (i = 0; i < optionNumbers.length; i++) {
        if (optionNumbers[i] === currentNumber) {
            idx = i;
            break;
        }
    }

    if (idx < 0) {
        return;
    }

    targetNumber = optionNumbers[idx + direction];
    if (typeof targetNumber === 'undefined') {
        return;
    }

    raceEl.value = rrsgPadRaceCode(targetNumber);
    rrsgClearReleaseBeforeRaceChange();
    formEl.submit();
}

function toggleAuditPanel() {
    var panel = document.getElementById('auditPanel');

    if (!panel) {
        return;
    }

    if (panel.style.display === 'none' || panel.style.display === '') {
        panel.style.display = 'block';
    } else {
        panel.style.display = 'none';
    }
}

function toggleReviewPanel() {
    var panel = document.getElementById('reviewPanel');

    if (!panel) {
        return;
    }

    if (panel.style.display === 'none' || panel.style.display === '') {
        panel.style.display = 'block';
    } else {
        panel.style.display = 'none';
    }
}

function toggleDetails() {
    var details = document.getElementById('detailsContent');
    var button = document.getElementById('detailsToggle');

    if (!details || !button) {
        return;
    }

    if (details.style.display === 'none' || details.style.display === '') {
        details.style.display = 'block';
        button.textContent = 'Hide Validation';
    } else {
        details.style.display = 'none';
        button.textContent = 'Show Validation';
    }
}

function toggleWeeklyDetail(detailId, rowEl) {
    var rows = document.getElementsByClassName('team-detail-row');
    var target = document.getElementById(detailId);
    var isOpen = false;
    var i;

    if (!target || !rowEl) {
        return;
    }

    isOpen = (target.style.display !== 'none' && target.style.display !== '');

    for (i = 0; i < rows.length; i++) {
        rows[i].style.display = 'none';
    }

    if (!isOpen) {
        target.style.display = 'table-row';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var yearEl = document.getElementById('year');
    var raceEl = document.getElementById('race');
    var detailsEl = document.getElementById('detailsContent');
    var detailsBtn = document.getElementById('detailsToggle');
    var reviewPanel = document.getElementById('reviewPanel');
    var auditPanel = document.getElementById('auditPanel');

    if (yearEl) {
        yearEl.addEventListener('change', function () {
            repopulateRaceOptions();
            setNoRaceSelectedState();
            rrsgInitialLoad = false;
        });
    }

    if (raceEl) {
        raceEl.addEventListener('change', function () {
            updateNavButtons();

            if (raceEl.value === '') {
                if (!rrsgInitialLoad) {
                    setNoRaceSelectedState();
                }
                return;
            }

            if (detailsEl) {
                detailsEl.style.display = 'none';
            }

            if (auditPanel) {
                auditPanel.style.display = 'none';
            }

            if (detailsBtn) {
                detailsBtn.textContent = 'Show Validation';
            }

            rrsgInitialLoad = false;
            rrsgClearReleaseBeforeRaceChange();
            document.getElementById('weeklyStandingsForm').submit();
        });
    }

    if (detailsEl) {
        detailsEl.style.display = 'none';
    }

    if (detailsBtn) {
        detailsBtn.textContent = 'Show Validation';
    }

    if (reviewPanel) {
        reviewPanel.style.display = 'none';
    }

    if (auditPanel) {
        auditPanel.style.display = 'none';
    }

    updateNavButtons();
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/footer-light.php'; ?>
</body>
</html>
