<?php
declare(strict_types=1);

/**
 * mrl_pick_db_readiness_check.php
 *
 * VERSION: v001
 * LAST MODIFIED: 8/16/2026 11:53:00 am
 *
 * DESCRIPTION:
 * Read-only database readiness checker for the MRL SEG / LP / RD pick system.
 *
 * BASELINE REVIEWED:
 * - team.php v016
 * - form-team-picks.php v004
 * - team-late-pick.php v004
 * - team_replacement_driver.php v008
 * - submit-team-picks.php v004
 *
 * SAFETY:
 * - SELECT / SHOW / INFORMATION_SCHEMA only.
 * - No INSERT, UPDATE, DELETE, ALTER, CREATE, DROP or TRUNCATE.
 * - No database credentials are displayed.
 *
 * CHANGELOG:
 * v001 (8/16/2026 11:53:00 am)
 * - Initial Live/testphp8 database readiness checker.
 */

date_default_timezone_set('America/New_York');
const MRL_PICK_DB_CHECK_VERSION = 'v001';

$baseDir = __DIR__;
$loadedConfig = '';

foreach ([$baseDir . '/config.php', $baseDir . '/dbconfig.php'] as $candidate) {
    if (!is_file($candidate)) {
        continue;
    }
    require_once $candidate;
    $loadedConfig = basename($candidate);
    if (isset($dbconnect) && $dbconnect instanceof mysqli) {
        break;
    }
}

$requirements = [
    'admin_setup' => ['raceYear','segment','formLockDate','formLockTime','currentForm'],
    'users' => ['userID','changeAuth'],
    'user_teams' => ['userID','raceYear','teamName'],
    'user_picks' => [
        'pickID','userID','teamName','raceYear','segment',
        'driverA','driverB','driverC','driverD','entryDate',
        'submission_id','ip','formID','pick_type','effective_race','supersedes_pickID'
    ],
    'user_picks_history' => [
        'userID','teamName','raceYear','segment',
        'driverA','driverB','driverC','driverD','entryDate',
        'submission_id','ip','formID','pick_type','effective_race','supersedes_pickID'
    ],
    'segment_race_ranges' => ['raceYear','segment','startRace'],
    'A Drivers' => ['driverName','Tag','driverYear','Available'],
    'B Drivers' => ['driverName','Tag','driverYear','Available'],
    'C Drivers' => ['driverName','Tag','driverYear','Available'],
    'D Drivers' => ['driverName','Tag','driverYear','Available'],
];

$report = [
    'tool' => 'MRL Pick DB Readiness Check',
    'version' => MRL_PICK_DB_CHECK_VERSION,
    'generated_at' => date('Y-m-d H:i:s T'),
    'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
    'script' => (string)($_SERVER['SCRIPT_NAME'] ?? ''),
    'loaded_config' => $loadedConfig,
    'read_only' => true,
    'connection' => ['ok'=>false,'database'=>'','server_version'=>'','charset'=>''],
    'summary' => [
        'required_tables'=>count($requirements),
        'tables_pass'=>0,'tables_fail'=>0,
        'required_columns'=>0,'columns_pass'=>0,'columns_fail'=>0,
        'schema_ready'=>false,'data_ready'=>false,'overall_ready'=>false,
    ],
    'tables' => [],
    'data_checks' => [],
    'notes' => [],
];

foreach ($requirements as $cols) {
    $report['summary']['required_columns'] += count($cols);
}

if (!isset($dbconnect) || !($dbconnect instanceof mysqli)) {
    $report['notes'][] = 'FAIL: No mysqli $dbconnect connection was available.';
    output_report($report);
}

$report['connection']['ok'] = true;
$report['connection']['server_version'] = mysqli_get_server_info($dbconnect);
$report['connection']['charset'] = mysqli_character_set_name($dbconnect);

$r = mysqli_query($dbconnect, 'SELECT DATABASE() AS db_name');
if ($r) {
    $row = mysqli_fetch_assoc($r);
    $report['connection']['database'] = (string)($row['db_name'] ?? '');
    mysqli_free_result($r);
}
$dbName = $report['connection']['database'];

if ($dbName === '') {
    $report['notes'][] = 'FAIL: Connected, but no active database name was returned.';
    output_report($report);
}

/* ---------- Schema checks ---------- */
foreach ($requirements as $table => $requiredColumns) {
    $item = [
        'exists'=>false,
        'status'=>'FAIL',
        'row_count'=>null,
        'required_columns'=>$requiredColumns,
        'missing_columns'=>[],
        'columns'=>[],
        'indexes'=>[],
    ];

    $item['exists'] = table_exists($dbconnect, $dbName, $table);

    if (!$item['exists']) {
        $item['missing_columns'] = $requiredColumns;
        $report['summary']['tables_fail']++;
        $report['summary']['columns_fail'] += count($requiredColumns);
        $report['tables'][$table] = $item;
        continue;
    }

    $report['summary']['tables_pass']++;
    $columns = get_columns($dbconnect, $dbName, $table);
    $columnNames = [];

    foreach ($columns as $column) {
        $name = (string)$column['COLUMN_NAME'];
        $columnNames[$name] = true;
        $item['columns'][$name] = [
            'type'=>(string)$column['COLUMN_TYPE'],
            'nullable'=>(string)$column['IS_NULLABLE'],
            'default'=>$column['COLUMN_DEFAULT'],
            'key'=>(string)$column['COLUMN_KEY'],
            'extra'=>(string)$column['EXTRA'],
        ];
    }

    foreach ($requiredColumns as $column) {
        if (isset($columnNames[$column])) {
            $report['summary']['columns_pass']++;
        } else {
            $report['summary']['columns_fail']++;
            $item['missing_columns'][] = $column;
        }
    }

    $item['indexes'] = get_indexes($dbconnect, $table);
    $item['row_count'] = row_count($dbconnect, $table);
    $item['status'] = empty($item['missing_columns']) ? 'PASS' : 'FAIL';
    $report['tables'][$table] = $item;
}

$report['summary']['schema_ready'] =
    $report['summary']['tables_fail'] === 0 &&
    $report['summary']['columns_fail'] === 0;

/* ---------- Current admin_setup ---------- */
$adminRows = [];
$currentYear = '';
$currentSegment = '';

if (!empty($report['tables']['admin_setup']['exists']) &&
    $report['tables']['admin_setup']['status'] === 'PASS') {
    $r = @mysqli_query(
        $dbconnect,
        'SELECT raceYear,segment,formLockDate,formLockTime,currentForm FROM admin_setup'
    );
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $adminRows[] = $row;
        }
        mysqli_free_result($r);
    }
}

if (!empty($adminRows)) {
    $currentYear = trim((string)($adminRows[0]['raceYear'] ?? ''));
    $currentSegment = trim((string)($adminRows[0]['segment'] ?? ''));
}

$report['data_checks']['admin_setup'] = [
    'status'=>count($adminRows) >= 1 ? 'PASS' : 'FAIL',
    'row_count'=>count($adminRows),
    'raceYear'=>$currentYear,
    'segment'=>$currentSegment,
    'formLockDate'=>(string)($adminRows[0]['formLockDate'] ?? ''),
    'formLockTime'=>(string)($adminRows[0]['formLockTime'] ?? ''),
    'currentForm'=>(string)($adminRows[0]['currentForm'] ?? ''),
    'warning'=>count($adminRows) > 1
        ? 'More than one admin_setup row exists; Test code uses LIMIT 1.'
        : '',
];

/* ---------- S1-S4 segment ranges ---------- */
$segmentRows = [];
if ($currentYear !== '' &&
    !empty($report['tables']['segment_race_ranges']['exists']) &&
    $report['tables']['segment_race_ranges']['status'] === 'PASS') {
    $stmt = mysqli_prepare(
        $dbconnect,
        'SELECT segment,startRace FROM segment_race_ranges WHERE raceYear=? ORDER BY segment'
    );
    if ($stmt) {
        $yearInt = (int)$currentYear;
        mysqli_stmt_bind_param($stmt, 'i', $yearInt);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        if ($r) {
            while ($row = mysqli_fetch_assoc($r)) {
                $segmentRows[(string)$row['segment']] = (int)$row['startRace'];
            }
        }
        mysqli_stmt_close($stmt);
    }
}
$missingSegments = [];
foreach (['S1','S2','S3','S4'] as $s) {
    if (!array_key_exists($s, $segmentRows)) {
        $missingSegments[] = $s;
    }
}
$report['data_checks']['segment_race_ranges'] = [
    'status'=>($currentYear !== '' && empty($missingSegments)) ? 'PASS' : 'FAIL',
    'raceYear'=>$currentYear,
    'segments'=>$segmentRows,
    'missing'=>$missingSegments,
];

/* ---------- Driver-table current-year data ---------- */
$driverChecks = [];
foreach (['A Drivers','B Drivers','C Drivers','D Drivers'] as $table) {
    $check = ['status'=>'FAIL','current_year_rows'=>null,'available_rows'=>null];

    if ($currentYear !== '' &&
        !empty($report['tables'][$table]['exists']) &&
        $report['tables'][$table]['status'] === 'PASS') {
        $q = quote_ident($table);
        $stmt = mysqli_prepare(
            $dbconnect,
            "SELECT COUNT(*) total_rows,
                    SUM(CASE WHEN Available='Y' THEN 1 ELSE 0 END) available_rows
             FROM {$q} WHERE driverYear=?"
        );
        if ($stmt) {
            $yearInt = (int)$currentYear;
            mysqli_stmt_bind_param($stmt, 'i', $yearInt);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            $row = $r ? mysqli_fetch_assoc($r) : null;
            if (is_array($row)) {
                $check['current_year_rows'] = (int)($row['total_rows'] ?? 0);
                $check['available_rows'] = (int)($row['available_rows'] ?? 0);
                $check['status'] =
                    $check['current_year_rows'] > 0 && $check['available_rows'] > 0
                    ? 'PASS' : 'FAIL';
            }
            mysqli_stmt_close($stmt);
        }
    }
    $driverChecks[$table] = $check;
}
$report['data_checks']['driver_tables'] = $driverChecks;

/* ---------- Observed pick types ---------- */
foreach (['user_picks','user_picks_history'] as $table) {
    $values = [];
    if (!empty($report['tables'][$table]['exists']) &&
        isset($report['tables'][$table]['columns']['pick_type'])) {
        $q = quote_ident($table);
        $r = @mysqli_query(
            $dbconnect,
            "SELECT COALESCE(pick_type,'(NULL)') pick_type,COUNT(*) row_count
             FROM {$q} GROUP BY pick_type ORDER BY pick_type"
        );
        if ($r) {
            while ($row = mysqli_fetch_assoc($r)) {
                $values[(string)$row['pick_type']] = (int)$row['row_count'];
            }
            mysqli_free_result($r);
        }
    }
    $report['data_checks'][$table . '_pick_types'] = [
        'status'=>'INFO',
        'values'=>$values,
        'note'=>'SEG, LP and RD are written by the tested code; ADJ is also read as a base-pick type.',
    ];
}

/* ---------- File dependencies ---------- */
$report['data_checks']['supporting_files'] = [
    'status'=>(
        is_file($baseDir . '/config_mrl.php') &&
        is_file($baseDir . '/race_results/race_schedule_helper.php')
    ) ? 'PASS' : 'FAIL',
    'files'=>[
        'config_mrl.php'=>is_file($baseDir . '/config_mrl.php'),
        'race_results/race_schedule_helper.php'=>
            is_file($baseDir . '/race_results/race_schedule_helper.php'),
    ],
];

$requiredStatuses = [
    $report['data_checks']['admin_setup']['status'],
    $report['data_checks']['segment_race_ranges']['status'],
    $report['data_checks']['supporting_files']['status'],
];
foreach ($driverChecks as $check) {
    $requiredStatuses[] = $check['status'];
}

$report['summary']['data_ready'] = !in_array('FAIL', $requiredStatuses, true);
$report['summary']['overall_ready'] =
    $report['connection']['ok'] &&
    $report['summary']['schema_ready'] &&
    $report['summary']['data_ready'];

$report['notes'][] =
    $report['summary']['overall_ready']
    ? 'PASS: Required schema, current data and helper-file prerequisites are present.'
    : 'NOT READY: One or more required checks failed. Review the red items.';

$report['notes'][] =
    'Run this exact file on Live and testphp8 and download both JSON reports. ' .
    'The JSON includes full column metadata and indexes for exact comparison.';

$report['notes'][] =
    'This checker makes no database changes. Delete it from both sites after the reports are saved.';

output_report($report);


/* ========================== HELPERS ========================== */

function table_exists(mysqli $db, string $dbName, string $table): bool
{
    $stmt = mysqli_prepare(
        $db,
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=?'
    );
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'ss', $dbName, $table);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    $ok = mysqli_stmt_fetch($stmt) && (int)$count > 0;
    mysqli_stmt_close($stmt);
    return $ok;
}

function get_columns(mysqli $db, string $dbName, string $table): array
{
    $rows = [];
    $stmt = mysqli_prepare(
        $db,
        'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
         ORDER BY ORDINAL_POSITION'
    );
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 'ss', $dbName, $table);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function get_indexes(mysqli $db, string $table): array
{
    $rows = [];
    $r = @mysqli_query($db, 'SHOW INDEX FROM ' . quote_ident($table));
    if (!$r) return [];
    while ($row = mysqli_fetch_assoc($r)) {
        $rows[] = [
            'name'=>(string)($row['Key_name'] ?? ''),
            'unique'=>((int)($row['Non_unique'] ?? 1) === 0),
            'sequence'=>(int)($row['Seq_in_index'] ?? 0),
            'column'=>(string)($row['Column_name'] ?? ''),
        ];
    }
    mysqli_free_result($r);
    return $rows;
}

function row_count(mysqli $db, string $table): ?int
{
    $r = @mysqli_query($db, 'SELECT COUNT(*) c FROM ' . quote_ident($table));
    if (!$r) return null;
    $row = mysqli_fetch_assoc($r);
    mysqli_free_result($r);
    return is_array($row) ? (int)($row['c'] ?? 0) : null;
}

function quote_ident(string $value): string
{
    return '`' . str_replace('`', '``', $value) . '`';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function status_class(string $value): string
{
    if ($value === 'PASS') return 'pass';
    if ($value === 'FAIL' || $value === 'NOT READY') return 'fail';
    return 'info';
}

function output_report(array $report): void
{
    if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="mrl_pick_db_readiness_' .
            preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)($report['host'] ?? 'site')) .
            '_' . date('Ymd_His') . '.json"'
        );
        echo json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $overall = !empty($report['summary']['overall_ready']) ? 'PASS' : 'NOT READY';
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MRL Pick DB Readiness Check</title>
<style>
:root{color-scheme:dark;--bg:#0f1318;--panel:#19212b;--panel2:#222c38;--text:#edf3f9;--muted:#aab7c5;--border:#3a4858;--pass:#55d36a;--fail:#ff6666;--info:#f2bd49;--link:#66adff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif}.wrap{max-width:1500px;margin:auto;padding:22px}
h1{margin:0 0 4px;font-size:30px}h2{margin:25px 0 10px}.sub,.small{color:var(--muted)}.small{font-size:13px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:15px;margin-bottom:13px}
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.big{font-size:26px;font-weight:700;margin-top:5px}.label{font-size:12px;color:var(--muted);text-transform:uppercase}
.pass{color:var(--pass)}.fail{color:var(--fail)}.info{color:var(--info)}a{color:var(--link)}
.button{display:inline-block;background:var(--panel2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);text-decoration:none;margin:0 7px 14px 0}
table{width:100%;border-collapse:collapse;background:var(--panel)}th,td{border:1px solid var(--border);padding:8px;text-align:left;vertical-align:top}th{background:var(--panel2)}
code{color:#d7e8ff}.pill{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:3px 8px;margin:2px}
details{margin-bottom:8px}summary{cursor:pointer;color:var(--link)}.note{border-left:4px solid var(--info);background:var(--panel);padding:10px 12px;margin:8px 0}
@media(max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.grid{grid-template-columns:1fr}.wrap{padding:12px}}
</style>
</head>
<body><div class="wrap">
<h1>MRL Pick DB Readiness Check</h1>
<div class="sub"><?= h((string)$report['version']) ?> · READ ONLY · <?= h((string)$report['generated_at']) ?></div>

<p>
<a class="button" href="?format=json">Download JSON report</a>
<a class="button" href="?x=<?= rawurlencode((string)microtime(true)) ?>">Run again</a>
</p>

<div class="card">
<div class="label">Overall result</div>
<div class="big <?= status_class($overall) ?>"><?= h($overall) ?></div>
<p>
Host: <strong><?= h((string)$report['host']) ?></strong><br>
Database: <strong><?= h((string)$report['connection']['database']) ?></strong><br>
Loaded DB config: <strong><?= h((string)$report['loaded_config']) ?></strong>
</p>
<div class="small">No credentials are displayed and this tool performs no database writes.</div>
</div>

<div class="grid">
<div class="card"><div class="label">Connection</div><div class="big <?= $report['connection']['ok']?'pass':'fail' ?>"><?= $report['connection']['ok']?'PASS':'FAIL' ?></div><div class="small"><?= h((string)$report['connection']['server_version']) ?></div></div>
<div class="card"><div class="label">Required tables</div><div class="big <?= $report['summary']['tables_fail']===0?'pass':'fail' ?>"><?= (int)$report['summary']['tables_pass'] ?>/<?= (int)$report['summary']['required_tables'] ?></div><div class="small">Missing: <?= (int)$report['summary']['tables_fail'] ?></div></div>
<div class="card"><div class="label">Required columns</div><div class="big <?= $report['summary']['columns_fail']===0?'pass':'fail' ?>"><?= (int)$report['summary']['columns_pass'] ?>/<?= (int)$report['summary']['required_columns'] ?></div><div class="small">Missing: <?= (int)$report['summary']['columns_fail'] ?></div></div>
<div class="card"><div class="label">Data / helper checks</div><div class="big <?= $report['summary']['data_ready']?'pass':'fail' ?>"><?= $report['summary']['data_ready']?'PASS':'FAIL' ?></div></div>
</div>

<h2>Required schema</h2>
<table>
<thead><tr><th>Table</th><th>Status</th><th>Rows</th><th>Required columns</th><th>Missing</th></tr></thead>
<tbody>
<?php foreach ($report['tables'] as $name=>$t): ?>
<tr>
<td><strong><?= h((string)$name) ?></strong></td>
<td class="<?= status_class((string)$t['status']) ?>"><strong><?= h((string)$t['status']) ?></strong></td>
<td><?= $t['row_count']===null?'—':(int)$t['row_count'] ?></td>
<td><code><?= h(implode(', ',(array)$t['required_columns'])) ?></code></td>
<td><?= empty($t['missing_columns'])?'<span class="pass">None</span>':'<span class="fail"><strong>'.h(implode(', ',(array)$t['missing_columns'])).'</strong></span>' ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>

<h2>Current-data checks</h2>
<div class="card">
<strong>admin_setup: <span class="<?= status_class((string)$report['data_checks']['admin_setup']['status']) ?>"><?= h((string)$report['data_checks']['admin_setup']['status']) ?></span></strong><br><br>
Race year: <strong><?= h((string)$report['data_checks']['admin_setup']['raceYear']) ?></strong> ·
Segment: <strong><?= h((string)$report['data_checks']['admin_setup']['segment']) ?></strong><br>
Lock: <?= h((string)$report['data_checks']['admin_setup']['formLockDate']) ?> <?= h((string)$report['data_checks']['admin_setup']['formLockTime']) ?><br>
Current form: <code><?= h((string)$report['data_checks']['admin_setup']['currentForm']) ?></code>
<?php if ($report['data_checks']['admin_setup']['warning']!==''): ?><div class="note"><?= h((string)$report['data_checks']['admin_setup']['warning']) ?></div><?php endif; ?>
</div>

<div class="card">
<strong>segment_race_ranges: <span class="<?= status_class((string)$report['data_checks']['segment_race_ranges']['status']) ?>"><?= h((string)$report['data_checks']['segment_race_ranges']['status']) ?></span></strong><br><br>
<?php foreach (['S1','S2','S3','S4'] as $s): ?>
<?= h($s) ?>: <strong><?= isset($report['data_checks']['segment_race_ranges']['segments'][$s])?'R'.(int)$report['data_checks']['segment_race_ranges']['segments'][$s]:'MISSING' ?></strong>&nbsp;&nbsp;
<?php endforeach; ?>
</div>

<div class="card">
<strong>Current-year driver pools</strong>
<table style="margin-top:10px">
<thead><tr><th>Table</th><th>Status</th><th>Rows</th><th>Available=Y</th></tr></thead>
<tbody>
<?php foreach ($report['data_checks']['driver_tables'] as $name=>$d): ?>
<tr><td><?= h((string)$name) ?></td><td class="<?= status_class((string)$d['status']) ?>"><?= h((string)$d['status']) ?></td><td><?= $d['current_year_rows']===null?'—':(int)$d['current_year_rows'] ?></td><td><?= $d['available_rows']===null?'—':(int)$d['available_rows'] ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>

<div class="card">
<strong>Supporting files: <span class="<?= status_class((string)$report['data_checks']['supporting_files']['status']) ?>"><?= h((string)$report['data_checks']['supporting_files']['status']) ?></span></strong><br><br>
<?php foreach ($report['data_checks']['supporting_files']['files'] as $name=>$exists): ?>
<code><?= h((string)$name) ?></code>: <span class="<?= $exists?'pass':'fail' ?>"><?= $exists?'FOUND':'MISSING' ?></span><br>
<?php endforeach; ?>
</div>

<h2>Observed pick types</h2>
<div class="card">
<?php foreach (['user_picks','user_picks_history'] as $table): $p=$report['data_checks'][$table.'_pick_types']; ?>
<strong><?= h($table) ?></strong>:
<?php if (empty($p['values'])): ?><span class="small">No values found.</span>
<?php else: foreach ($p['values'] as $type=>$count): ?><span class="pill"><?= h((string)$type) ?>: <?= (int)$count ?></span><?php endforeach; endif; ?>
<br><br>
<?php endforeach; ?>
<div class="small">Informational only. No existing LP/RD rows does not by itself mean the database is unready.</div>
</div>

<h2>Full schema details</h2>
<?php foreach ($report['tables'] as $name=>$t): ?>
<details><summary><?= h((string)$name) ?> — columns and indexes</summary><div class="card">
<table><thead><tr><th>Column</th><th>Type</th><th>Null</th><th>Default</th><th>Key</th><th>Extra</th></tr></thead><tbody>
<?php foreach ($t['columns'] as $col=>$c): ?>
<tr><td><?= h((string)$col) ?></td><td><code><?= h((string)$c['type']) ?></code></td><td><?= h((string)$c['nullable']) ?></td><td><?= $c['default']===null?'<em>NULL</em>':h((string)$c['default']) ?></td><td><?= h((string)$c['key']) ?></td><td><?= h((string)$c['extra']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<strong>Indexes</strong>
<?php if (empty($t['indexes'])): ?><div class="small">No indexes returned.</div>
<?php else: ?><table style="margin-top:8px"><thead><tr><th>Name</th><th>Unique</th><th>Seq</th><th>Column</th></tr></thead><tbody>
<?php foreach ($t['indexes'] as $idx): ?><tr><td><?= h((string)$idx['name']) ?></td><td><?= $idx['unique']?'YES':'NO' ?></td><td><?= (int)$idx['sequence'] ?></td><td><?= h((string)$idx['column']) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
</div></details>
<?php endforeach; ?>

<h2>Notes</h2>
<?php foreach ($report['notes'] as $note): ?><div class="note"><?= h((string)$note) ?></div><?php endforeach; ?>

<div class="small" style="margin-top:20px">FILE: <?= h(basename(__FILE__)) ?> · VERSION: <?= h(MRL_PICK_DB_CHECK_VERSION) ?></div>
</div></body></html>
<?php
    exit;
}
