<?php
declare(strict_types=1);

/**
 * mrl_team_identity_repair.php
 *
 * VERSION: v001
 * LAST MODIFIED: 8/20/2026 1:16:00 am
 *
 * DESCRIPTION:
 * TestPHP8-only admin diagnostic / repair utility for MRL team-name identity
 * damage caused by manual database cleanup/testing.
 *
 * The tool scans the ENTIRE current TestPHP8 database for BASE TABLES that
 * contain all three identity columns:
 *   userID + raceYear + teamName
 *
 * user_teams is treated as the canonical source of the team name for a
 * user/year combination. The tool reports:
 * - NULL / blank teamName values
 * - teamName values that disagree with user_teams
 * - rows with no matching user_teams record
 * - ambiguous user_teams mappings (more than one distinct nonblank team name)
 *
 * Safe repairs are offered only when exactly one canonical nonblank teamName
 * can be determined for that userID + raceYear.
 *
 * IMPORTANT:
 * - READ-ONLY on initial load.
 * - Repair requires an explicit POST + browser confirmation.
 * - No schema changes.
 * - No deletes.
 * - No changes to user_teams.
 * - If a scanned table contains entryDate, the repair explicitly preserves
 *   the existing entryDate value in the same UPDATE so ON UPDATE timestamp
 *   behavior cannot replace the original submission time.
 *
 * CHANGELOG:
 * v001 (8/20/2026 1:16:00 am)
 * - Initial whole-database team identity diagnostic / repair utility.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');

const MRL_TIR_VERSION = 'v001';
const MRL_TIR_REQUIRED_HOST = 'testphp8.manliusracingleague.com';

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/mrl_team_identity_repair.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

$adminUid = (int)($_SESSION['userSession'] ?? 0);

if (!isAdmin($adminUid)) {
    echo '<div style="color:#ff7373;background:#141414;padding:20px;font:18px Arial">You are NOT authorized to view/use this page.</div>';
    exit;
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($host !== MRL_TIR_REQUIRED_HOST) {
    echo '<div style="color:#ff7373;background:#141414;padding:20px;font:18px Arial">'
        . 'SAFETY STOP: This repair utility may run only on '
        . htmlspecialchars(MRL_TIR_REQUIRED_HOST, ENT_QUOTES, 'UTF-8')
        . '.</div>';
    exit;
}

if (!isset($dbconnect) || !($dbconnect instanceof mysqli)) {
    echo '<div style="color:#ff7373;background:#141414;padding:20px;font:18px Arial">Database connection unavailable.</div>';
    exit;
}

function tir_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tir_qi(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function tir_table_columns(mysqli $dbconnect, string $databaseName, string $table): array
{
    $sql = "
        SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, EXTRA
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=?
          AND TABLE_NAME=?
        ORDER BY ORDINAL_POSITION
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare column lookup.');
    }

    mysqli_stmt_bind_param($stmt, 'ss', $databaseName, $table);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $cols = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $cols[(string)$row['COLUMN_NAME']] = [
            'data_type' => (string)$row['DATA_TYPE'],
            'column_type' => (string)$row['COLUMN_TYPE'],
            'extra' => (string)$row['EXTRA'],
        ];
    }

    mysqli_stmt_close($stmt);
    return $cols;
}

function tir_discover_identity_tables(mysqli $dbconnect, string $databaseName): array
{
    $sql = "
        SELECT TABLE_NAME
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA=?
          AND TABLE_TYPE='BASE TABLE'
        ORDER BY TABLE_NAME
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare table discovery.');
    }

    mysqli_stmt_bind_param($stmt, 's', $databaseName);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $tables = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $table = (string)$row['TABLE_NAME'];

        if ($table === 'user_teams') {
            continue;
        }

        $cols = tir_table_columns($dbconnect, $databaseName, $table);
        if (
            array_key_exists('userID', $cols)
            && array_key_exists('raceYear', $cols)
            && array_key_exists('teamName', $cols)
        ) {
            $tables[$table] = $cols;
        }
    }

    mysqli_stmt_close($stmt);
    return $tables;
}

function tir_canonical_team_map(mysqli $dbconnect): array
{
    $sql = "
        SELECT
            userID,
            raceYear,
            COUNT(DISTINCT NULLIF(TRIM(teamName),'')) AS distinct_names,
            MIN(NULLIF(TRIM(teamName),'')) AS canonical_name
        FROM user_teams
        GROUP BY userID, raceYear
    ";

    $res = mysqli_query($dbconnect, $sql);
    if (!$res) {
        throw new RuntimeException('Unable to load canonical user_teams map.');
    }

    $map = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $key = (string)$row['userID'] . '|' . (string)$row['raceYear'];
        $map[$key] = [
            'distinct_names' => (int)$row['distinct_names'],
            'canonical_name' => $row['canonical_name'] === null ? '' : (string)$row['canonical_name'],
        ];
    }

    return $map;
}

function tir_primary_key_columns(mysqli $dbconnect, string $databaseName, string $table): array
{
    $sql = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=?
          AND TABLE_NAME=?
          AND CONSTRAINT_NAME='PRIMARY'
        ORDER BY ORDINAL_POSITION
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare primary-key lookup.');
    }

    mysqli_stmt_bind_param($stmt, 'ss', $databaseName, $table);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $pk = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $pk[] = (string)$row['COLUMN_NAME'];
    }

    mysqli_stmt_close($stmt);
    return $pk;
}

function tir_scan_table(
    mysqli $dbconnect,
    string $databaseName,
    string $table,
    array $columns,
    array $canonicalMap
): array {
    $pkCols = tir_primary_key_columns($dbconnect, $databaseName, $table);

    $selectCols = [];
    foreach ($pkCols as $pk) {
        $selectCols[] = tir_qi($pk);
    }

    foreach (['userID','raceYear','teamName'] as $col) {
        if (!in_array($col, $pkCols, true)) {
            $selectCols[] = tir_qi($col);
        }
    }

    if (array_key_exists('segment', $columns) && !in_array('segment', $pkCols, true)) {
        $selectCols[] = tir_qi('segment');
    }

    if (array_key_exists('entryDate', $columns) && !in_array('entryDate', $pkCols, true)) {
        $selectCols[] = tir_qi('entryDate');
    }

    $sql = "SELECT " . implode(',', $selectCols) . " FROM " . tir_qi($table);
    $res = mysqli_query($dbconnect, $sql);

    if (!$res) {
        throw new RuntimeException('Unable to scan table ' . $table . ': ' . mysqli_error($dbconnect));
    }

    $issues = [];
    $totalRows = 0;

    while ($row = mysqli_fetch_assoc($res)) {
        $totalRows++;

        $userID = (string)($row['userID'] ?? '');
        $raceYear = (string)($row['raceYear'] ?? '');
        $actual = trim((string)($row['teamName'] ?? ''));
        $key = $userID . '|' . $raceYear;

        $status = '';
        $expected = '';
        $repairable = false;

        if (!isset($canonicalMap[$key])) {
            $status = 'NO_USER_TEAMS_MATCH';
        } else {
            $canonical = $canonicalMap[$key];
            $distinct = (int)$canonical['distinct_names'];
            $expected = trim((string)$canonical['canonical_name']);

            if ($distinct !== 1 || $expected === '') {
                $status = 'AMBIGUOUS_CANONICAL';
            } elseif ($actual === '') {
                $status = 'MISSING_TEAM_NAME';
                $repairable = true;
            } elseif (strcasecmp($actual, $expected) !== 0) {
                $status = 'TEAM_NAME_MISMATCH';
                $repairable = true;
            }
        }

        if ($status === '') {
            continue;
        }

        $pkValues = [];
        foreach ($pkCols as $pk) {
            $pkValues[$pk] = $row[$pk] ?? null;
        }

        $issues[] = [
            'table' => $table,
            'pk_columns' => $pkCols,
            'pk_values' => $pkValues,
            'userID' => $userID,
            'raceYear' => $raceYear,
            'segment' => array_key_exists('segment', $row) ? (string)$row['segment'] : '',
            'actual' => $actual,
            'expected' => $expected,
            'status' => $status,
            'repairable' => $repairable,
            'entryDate' => array_key_exists('entryDate', $row) ? (string)$row['entryDate'] : '',
            'has_entryDate' => array_key_exists('entryDate', $columns),
        ];
    }

    return [
        'table' => $table,
        'columns' => $columns,
        'pk_columns' => $pkCols,
        'total_rows' => $totalRows,
        'issues' => $issues,
    ];
}

function tir_issue_key(array $issue): string
{
    $parts = [$issue['table']];

    foreach ($issue['pk_columns'] as $pk) {
        $parts[] = $pk . '=' . (string)($issue['pk_values'][$pk] ?? '');
    }

    if (empty($issue['pk_columns'])) {
        $parts[] = 'userID=' . (string)$issue['userID'];
        $parts[] = 'raceYear=' . (string)$issue['raceYear'];
        $parts[] = 'segment=' . (string)$issue['segment'];
        $parts[] = 'actual=' . (string)$issue['actual'];
    }

    return sha1(implode('|', $parts));
}

function tir_repair_issue(mysqli $dbconnect, array $issue): void
{
    if (empty($issue['repairable'])) {
        throw new RuntimeException('Attempted repair of a non-repairable issue.');
    }

    $table = (string)$issue['table'];
    $expected = (string)$issue['expected'];
    $pkCols = $issue['pk_columns'];

    if (empty($pkCols)) {
        throw new RuntimeException('Refusing repair in ' . $table . ': no primary key is available.');
    }

    $sets = [tir_qi('teamName') . '=?'];
    $types = 's';
    $values = [$expected];

    if (!empty($issue['has_entryDate'])) {
        // Explicitly preserve the existing value so an ON UPDATE timestamp cannot
        // replace the original submission time while teamName is repaired.
        $sets[] = tir_qi('entryDate') . '=?';
        $types .= 's';
        $values[] = (string)$issue['entryDate'];
    }

    $where = [];
    foreach ($pkCols as $pk) {
        $where[] = tir_qi($pk) . '=?';
        $types .= 's';
        $values[] = (string)($issue['pk_values'][$pk] ?? '');
    }

    $sql = "UPDATE " . tir_qi($table)
        . " SET " . implode(', ', $sets)
        . " WHERE " . implode(' AND ', $where)
        . " LIMIT 1";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare repair for ' . $table . '.');
    }

    $bind = [];
    $bind[] = &$types;
    foreach ($values as $i => $value) {
        $bind[] = &$values[$i];
    }

    call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $bind));

    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Repair failed for ' . $table . ': ' . $err);
    }

    mysqli_stmt_close($stmt);
}

$dbName = '';
$dbNameRes = mysqli_query($dbconnect, "SELECT DATABASE() AS dbname");
if ($dbNameRes && ($dbNameRow = mysqli_fetch_assoc($dbNameRes))) {
    $dbName = (string)$dbNameRow['dbname'];
}

if ($dbName === '') {
    echo '<div style="color:#ff7373;background:#141414;padding:20px;font:18px Arial">Unable to determine current database.</div>';
    exit;
}

$message = '';
$messageClass = 'good';

try {
    $canonicalMap = tir_canonical_team_map($dbconnect);
    $identityTables = tir_discover_identity_tables($dbconnect, $dbName);

    $scanResults = [];
    $issueIndex = [];

    foreach ($identityTables as $table => $columns) {
        $scan = tir_scan_table($dbconnect, $dbName, $table, $columns, $canonicalMap);
        $scanResults[$table] = $scan;

        foreach ($scan['issues'] as $issue) {
            $issueIndex[tir_issue_key($issue)] = $issue;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'repair_selected') {
        $selected = isset($_POST['repair']) && is_array($_POST['repair']) ? $_POST['repair'] : [];

        if (empty($selected)) {
            $message = 'No repair rows were selected.';
            $messageClass = 'bad';
        } else {
            $repairCount = 0;

            mysqli_begin_transaction($dbconnect);

            try {
                foreach ($selected as $key => $value) {
                    if (!isset($issueIndex[$key])) {
                        throw new RuntimeException('Selected issue is no longer present in the current scan.');
                    }

                    $issue = $issueIndex[$key];

                    if (empty($issue['repairable'])) {
                        throw new RuntimeException('Selected issue is not eligible for automatic repair.');
                    }

                    tir_repair_issue($dbconnect, $issue);
                    $repairCount++;
                }

                mysqli_commit($dbconnect);
                $message = 'Repair complete: ' . $repairCount . ' row(s) repaired.';
                $messageClass = 'good';

                // Re-scan after repair so the page immediately shows the new state.
                $canonicalMap = tir_canonical_team_map($dbconnect);
                $identityTables = tir_discover_identity_tables($dbconnect, $dbName);
                $scanResults = [];
                $issueIndex = [];

                foreach ($identityTables as $table => $columns) {
                    $scan = tir_scan_table($dbconnect, $dbName, $table, $columns, $canonicalMap);
                    $scanResults[$table] = $scan;

                    foreach ($scan['issues'] as $issue) {
                        $issueIndex[tir_issue_key($issue)] = $issue;
                    }
                }
            } catch (Throwable $repairError) {
                mysqli_rollback($dbconnect);
                throw $repairError;
            }
        }
    }
} catch (Throwable $e) {
    echo '<div style="color:#ff7373;background:#141414;padding:20px;font:18px Arial">'
        . 'ERROR: ' . tir_h($e->getMessage())
        . '</div>';
    exit;
}

$totalTables = count($scanResults);
$totalRows = 0;
$totalIssues = 0;
$totalRepairable = 0;

foreach ($scanResults as $scan) {
    $totalRows += (int)$scan['total_rows'];
    $totalIssues += count($scan['issues']);

    foreach ($scan['issues'] as $issue) {
        if (!empty($issue['repairable'])) {
            $totalRepairable++;
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL Team Identity Diagnostic / Repair</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#151515;color:#eee;font:14px Tahoma,Verdana,Segoe,sans-serif}
.wrap{width:96%;max-width:1600px;margin:12px auto 28px}
.env{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 16px;border:1px solid #9a7014;border-radius:14px;background:linear-gradient(180deg,#3a3118,#282417);margin-bottom:12px}
.env-title{font-size:24px;font-weight:800;letter-spacing:1.2px;color:#fff4df}
.env-domain{color:#ddd;font-size:13px}.env-page{color:#ffd08a;font-size:13px;font-weight:bold;margin-top:5px}
.pill{border:1px solid #826820;background:#4a3c19;color:#ffd166;border-radius:999px;padding:6px 12px;font-weight:bold}
.card{background:#222;border:1px solid #4d473f;border-radius:14px;padding:14px 16px;margin-bottom:12px}
.summary{display:flex;gap:8px;flex-wrap:wrap}.sum{background:#191919;border:1px solid #3c3c3c;border-radius:10px;padding:8px 12px}
h2{margin:0 0 10px;color:#ffd08a;font-size:19px}
h3{margin:14px 0 7px;color:#ffd08a;font-size:16px}
.good{color:#62e89b}.bad{color:#ff8080}.warn{color:#ffd166}.muted{color:#aaa}.msg{font-weight:bold;padding:9px 12px;border-radius:9px;background:#191919;margin-bottom:12px}
table{width:100%;border-collapse:collapse;background:#1c1c1c;margin-top:6px}
th,td{padding:7px 8px;border-bottom:1px solid #393939;text-align:left;vertical-align:top}
th{color:#ffd08a;background:#24211d;font-size:12px}
code{color:#ddd}.status{font-weight:bold}.repairable{color:#62e89b}.notrepair{color:#ffb070}
.btn{background:#3a2f1b;color:#ffd08a;border:1px solid #b18745;border-radius:8px;padding:8px 12px;cursor:pointer;font-weight:bold}
.btn:hover{background:#49391f}.actions{display:flex;gap:10px;align-items:center;margin-top:12px}.empty{padding:12px;color:#9fdda9}
.note{border-left:4px solid #ffd166;background:#2a2519;padding:9px 12px;margin-top:10px}
.pk{font-size:12px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="env">
    <div>
        <div class="env-title">TESTPHP8 / DEMO SITE</div>
        <div class="env-domain">testphp8.manliusracingleague.com</div>
        <div class="env-page">MRL Team Identity Diagnostic / Repair · <?php echo tir_h(MRL_TIR_VERSION); ?></div>
    </div>
    <div class="pill">Demo / test data</div>
</div>

<?php if ($message !== ''): ?>
    <div class="msg <?php echo tir_h($messageClass); ?>"><?php echo tir_h($message); ?></div>
<?php endif; ?>

<div class="card">
    <h2>Whole-Database Scan</h2>
    <div class="summary">
        <div class="sum"><b>Database:</b> <?php echo tir_h($dbName); ?></div>
        <div class="sum"><b>Identity tables scanned:</b> <?php echo $totalTables; ?></div>
        <div class="sum"><b>Rows scanned:</b> <?php echo $totalRows; ?></div>
        <div class="sum"><b>Issues found:</b> <span class="<?php echo $totalIssues ? 'warn' : 'good'; ?>"><?php echo $totalIssues; ?></span></div>
        <div class="sum"><b>Safely repairable:</b> <span class="<?php echo $totalRepairable ? 'repairable' : 'good'; ?>"><?php echo $totalRepairable; ?></span></div>
    </div>

    <div class="note">
        <b>Canonical source:</b> <code>user_teams</code> by <code>userID + raceYear</code>.
        This utility does not alter <code>user_teams</code>, does not delete anything, and does not change schema.
        Rows are repairable only when exactly one nonblank canonical team name exists.
    </div>
</div>

<form method="post" onsubmit="return confirm('Repair the selected rows using user_teams as the canonical team name? Existing entryDate values will be explicitly preserved.');">
<input type="hidden" name="action" value="repair_selected">

<?php foreach ($scanResults as $table => $scan): ?>
    <div class="card">
        <h2><?php echo tir_h($table); ?></h2>
        <div class="muted">
            <?php echo (int)$scan['total_rows']; ?> row(s) scanned ·
            <?php echo count($scan['issues']); ?> issue(s)
            <?php if (array_key_exists('entryDate', $scan['columns'])): ?>
                · entryDate preservation enabled
            <?php endif; ?>
        </div>

        <?php if (empty($scan['issues'])): ?>
            <div class="empty">No team-identity issues found in this table.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Repair</th>
                    <th>Status</th>
                    <th>Row</th>
                    <th>User</th>
                    <th>Year</th>
                    <th>Segment</th>
                    <th>Current teamName</th>
                    <th>Canonical teamName</th>
                    <th>entryDate</th>
                </tr>
                </thead>
                <tbody>

                <?php foreach ($scan['issues'] as $issue): ?>
                    <?php $key = tir_issue_key($issue); ?>
                    <tr>
                        <td>
                            <?php if (!empty($issue['repairable']) && !empty($issue['pk_columns'])): ?>
                                <input type="checkbox" name="repair[<?php echo tir_h($key); ?>]" value="1" checked>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="status <?php echo !empty($issue['repairable']) ? 'repairable' : 'notrepair'; ?>">
                            <?php echo tir_h($issue['status']); ?>
                        </td>
                        <td class="pk">
                            <?php if (!empty($issue['pk_columns'])): ?>
                                <?php foreach ($issue['pk_columns'] as $pk): ?>
                                    <?php echo tir_h($pk); ?>=<?php echo tir_h($issue['pk_values'][$pk] ?? 'NULL'); ?><br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                no primary key
                            <?php endif; ?>
                        </td>
                        <td><?php echo tir_h($issue['userID']); ?></td>
                        <td><?php echo tir_h($issue['raceYear']); ?></td>
                        <td><?php echo tir_h($issue['segment']); ?></td>
                        <td><?php echo $issue['actual'] === '' ? '<span class="bad">NULL / blank</span>' : tir_h($issue['actual']); ?></td>
                        <td><?php echo $issue['expected'] === '' ? '<span class="muted">unresolved</span>' : tir_h($issue['expected']); ?></td>
                        <td><?php echo tir_h($issue['entryDate']); ?></td>
                    </tr>
                <?php endforeach; ?>

                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if ($totalRepairable > 0): ?>
    <div class="card">
        <h2>Repair Selected Rows</h2>
        <p>
            The checked rows are the ones for which this scan found one unambiguous
            <code>user_teams</code> team name. Review the list above before continuing.
        </p>
        <div class="actions">
            <button class="btn" type="submit">REPAIR SELECTED TEAM NAMES</button>
            <span class="muted"><?php echo $totalRepairable; ?> repairable issue(s) currently detected.</span>
        </div>
    </div>
<?php endif; ?>

</form>

<div class="card">
    <h2>After Repair</h2>
    <div class="muted">
        Re-open the Team Chart and Weekly Standings pages. If the missing teamName values
        were the cause, the names and scoring associations should return without restoring
        the database.
    </div>
</div>

</div>
</body>
</html>
