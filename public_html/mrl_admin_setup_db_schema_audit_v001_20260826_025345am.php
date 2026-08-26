<?php
/**
 * MRL admin_setup Database Schema Audit
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 2:53:45 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 2:53:45 am)
 * - Initial read-only TESTPHP8 vs LIVE database schema audit focused on admin_setup.php.
 * - Loads TESTPHP8 and LIVE config.php in isolated scope to obtain each environment's PDO.
 * - Extracts table names referenced by TESTPHP8 admin_setup.php.
 * - Compares TESTPHP8 vs LIVE schema for those referenced tables:
 *     columns
 *     column types/null/default/extra
 *     primary/index definitions
 * - Compares CREATE TABLE structure hashes for fast drift detection.
 * - Never exports usernames, passwords, hosts, DSNs, or row data.
 * - Provides JSON and TXT exports.
 * - Makes NO database writes and NO file changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 *
 * SAFETY:
 * - READ ONLY.
 * - No INSERT/UPDATE/DELETE/ALTER/CREATE/DROP/TRUNCATE statements.
 * - No data rows are queried or exported.
 * - Live scheduler may remain running.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_ASDB_VERSION = 'v001';
const MRL_ASDB_TITLE = 'MRL admin_setup Database Schema Audit';

function asdb_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function asdb_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function asdb_read(string $p): string {
    if (!is_file($p) || !is_readable($p)) return '';
    $d = @file_get_contents($p);
    return is_string($d) ? $d : '';
}

function asdb_load_config(string $configPath): array {
    $result = [
        'ok' => false,
        'pdo' => null,
        'database_name' => '',
        'error' => '',
    ];

    if (!is_file($configPath)) {
        $result['error'] = 'Config file not found.';
        return $result;
    }

    try {
        $loader = static function (string $__configPath): array {
            $database = null;
            $dbconnect = null;
            $dbo = null;
            $host_name = null;
            $password = null;
            $username = null;

            include $__configPath;

            $pdo = null;
            if (isset($dbo) && $dbo instanceof PDO) {
                $pdo = $dbo;
            } elseif (isset($dbconnect) && $dbconnect instanceof PDO) {
                $pdo = $dbconnect;
            }

            return [
                'pdo' => $pdo,
                'database_name' => is_string($database) ? $database : '',
            ];
        };

        $loaded = $loader($configPath);

        if (!isset($loaded['pdo']) || !($loaded['pdo'] instanceof PDO)) {
            $result['error'] = 'No PDO connection object was found after loading config.php.';
            return $result;
        }

        /** @var PDO $pdo */
        $pdo = $loaded['pdo'];
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $dbName = '';
        if (!empty($loaded['database_name'])) {
            $dbName = (string)$loaded['database_name'];
        } else {
            $stmt = $pdo->query('SELECT DATABASE()');
            $dbName = (string)$stmt->fetchColumn();
        }

        $result['ok'] = true;
        $result['pdo'] = $pdo;
        $result['database_name'] = $dbName;
        return $result;

    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        return $result;
    }
}

function asdb_extract_table_candidates(string $phpPath): array {
    $text = asdb_read($phpPath);
    if ($text === '') return [];

    $names = [];

    /* SQL keywords followed by a simple table identifier. */
    if (preg_match_all('/\b(?:FROM|INTO|UPDATE|JOIN|TABLE)\s+`?([A-Za-z0-9_]+)`?/i', $text, $m)) {
        foreach ($m[1] as $name) {
            $names[$name] = true;
        }
    }

    /* Common explicit table variable assignments. */
    if (preg_match_all('/\$[A-Za-z0-9_]*(?:table|tbl)[A-Za-z0-9_]*\s*=\s*[\'"]([A-Za-z0-9_]+)[\'"]/i', $text, $m)) {
        foreach ($m[1] as $name) {
            $names[$name] = true;
        }
    }

    $ignore = [
        'IF', 'NOT', 'EXISTS', 'SET', 'VALUES', 'SELECT', 'WHERE', 'ORDER',
        'GROUP', 'LIMIT', 'DATABASE', 'SCHEMA'
    ];

    foreach (array_keys($names) as $name) {
        if (in_array(strtoupper($name), $ignore, true)) {
            unset($names[$name]);
        }
    }

    $out = array_keys($names);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function asdb_table_exists(PDO $pdo, string $dbName, string $table): bool {
    $sql = 'SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = :db AND table_name = :tbl';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $dbName, ':tbl' => $table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function asdb_columns(PDO $pdo, string $dbName, string $table): array {
    $sql = 'SELECT
                COLUMN_NAME,
                ORDINAL_POSITION,
                COLUMN_DEFAULT,
                IS_NULLABLE,
                DATA_TYPE,
                COLUMN_TYPE,
                CHARACTER_MAXIMUM_LENGTH,
                NUMERIC_PRECISION,
                NUMERIC_SCALE,
                DATETIME_PRECISION,
                COLUMN_KEY,
                EXTRA
            FROM information_schema.columns
            WHERE table_schema = :db AND table_name = :tbl
            ORDER BY ORDINAL_POSITION';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $dbName, ':tbl' => $table]);

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function asdb_indexes(PDO $pdo, string $dbName, string $table): array {
    $sql = 'SELECT
                INDEX_NAME,
                NON_UNIQUE,
                SEQ_IN_INDEX,
                COLUMN_NAME,
                COLLATION,
                SUB_PART,
                INDEX_TYPE
            FROM information_schema.statistics
            WHERE table_schema = :db AND table_name = :tbl
            ORDER BY INDEX_NAME, SEQ_IN_INDEX';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $dbName, ':tbl' => $table]);

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function asdb_create_hash(PDO $pdo, string $table): array {
    $result = [
        'ok' => false,
        'sha256' => '',
        'error' => '',
    ];

    try {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $stmt = $pdo->query('SHOW CREATE TABLE ' . $quoted);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $result['error'] = 'SHOW CREATE TABLE returned no row.';
            return $result;
        }

        $create = '';
        foreach ($row as $key => $value) {
            if (stripos((string)$key, 'Create Table') !== false) {
                $create = (string)$value;
                break;
            }
        }

        if ($create === '') {
            $result['error'] = 'CREATE TABLE text not found.';
            return $result;
        }

        /*
         * Normalize volatile AUTO_INCREMENT value so row-count-related changes
         * do not produce false schema drift.
         */
        $normalized = preg_replace('/AUTO_INCREMENT=\d+\b/i', 'AUTO_INCREMENT=<normalized>', $create);
        if (!is_string($normalized)) $normalized = $create;

        $result['ok'] = true;
        $result['sha256'] = hash('sha256', $normalized);
        return $result;

    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        return $result;
    }
}

function asdb_assoc_by_key(array $rows, string $keyName): array {
    $out = [];
    foreach ($rows as $row) {
        if (isset($row[$keyName])) {
            $out[(string)$row[$keyName]] = $row;
        }
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function asdb_compare_columns(array $testCols, array $liveCols): array {
    $t = asdb_assoc_by_key($testCols, 'COLUMN_NAME');
    $l = asdb_assoc_by_key($liveCols, 'COLUMN_NAME');

    $all = array_unique(array_merge(array_keys($t), array_keys($l)));
    sort($all, SORT_NATURAL | SORT_FLAG_CASE);

    $diffs = [];
    foreach ($all as $name) {
        if (!isset($t[$name])) {
            $diffs[] = [
                'column' => $name,
                'status' => 'LIVE_ONLY',
                'test' => null,
                'live' => $l[$name],
            ];
            continue;
        }
        if (!isset($l[$name])) {
            $diffs[] = [
                'column' => $name,
                'status' => 'TEST_ONLY',
                'test' => $t[$name],
                'live' => null,
            ];
            continue;
        }

        $fields = [
            'ORDINAL_POSITION',
            'COLUMN_DEFAULT',
            'IS_NULLABLE',
            'DATA_TYPE',
            'COLUMN_TYPE',
            'CHARACTER_MAXIMUM_LENGTH',
            'NUMERIC_PRECISION',
            'NUMERIC_SCALE',
            'DATETIME_PRECISION',
            'COLUMN_KEY',
            'EXTRA',
        ];

        $changed = [];
        foreach ($fields as $field) {
            $tv = $t[$name][$field] ?? null;
            $lv = $l[$name][$field] ?? null;
            if ($tv !== $lv) {
                $changed[$field] = [
                    'test' => $tv,
                    'live' => $lv,
                ];
            }
        }

        if ($changed) {
            $diffs[] = [
                'column' => $name,
                'status' => 'DIFFERENT',
                'changes' => $changed,
            ];
        }
    }

    return $diffs;
}

function asdb_compare_indexes(array $testIdx, array $liveIdx): array {
    $normalize = static function (array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $key = implode('|', [
                (string)($row['INDEX_NAME'] ?? ''),
                (string)($row['SEQ_IN_INDEX'] ?? ''),
                (string)($row['COLUMN_NAME'] ?? ''),
            ]);
            $out[$key] = $row;
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    };

    $t = $normalize($testIdx);
    $l = $normalize($liveIdx);

    $all = array_unique(array_merge(array_keys($t), array_keys($l)));
    sort($all, SORT_NATURAL | SORT_FLAG_CASE);

    $diffs = [];
    foreach ($all as $key) {
        if (!isset($t[$key])) {
            $diffs[] = ['key' => $key, 'status' => 'LIVE_ONLY', 'test' => null, 'live' => $l[$key]];
        } elseif (!isset($l[$key])) {
            $diffs[] = ['key' => $key, 'status' => 'TEST_ONLY', 'test' => $t[$key], 'live' => null];
        } elseif ($t[$key] !== $l[$key]) {
            $diffs[] = ['key' => $key, 'status' => 'DIFFERENT', 'test' => $t[$key], 'live' => $l[$key]];
        }
    }
    return $diffs;
}

$selfDir = rtrim(asdb_norm(__DIR__), '/');
$expected = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$testRoot = $selfDir;
$liveRoot = dirname($testRoot);

$testAdminSetup = $testRoot . '/admin_setup.php';
$liveAdminSetup = $liveRoot . '/admin_setup.php';

$generatedAt = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$testCfg = asdb_load_config($testRoot . '/config.php');
$liveCfg = asdb_load_config($liveRoot . '/config.php');

$tables = asdb_extract_table_candidates($testAdminSetup);

/*
 * Add common MRL tables if admin_setup references them indirectly via variables
 * or helper functions and they exist in either environment.
 */
$commonCandidates = [
    'mrl',
    'mrl_users',
    'mrl_teams',
    'mrl_drivers',
    'mrl_segments',
    'mrl_picks',
    'mrl_team_picks',
    'mrl_segment',
];

if ($testCfg['ok'] && $liveCfg['ok']) {
    foreach ($commonCandidates as $candidate) {
        $existsTest = asdb_table_exists($testCfg['pdo'], $testCfg['database_name'], $candidate);
        $existsLive = asdb_table_exists($liveCfg['pdo'], $liveCfg['database_name'], $candidate);
        if ($existsTest || $existsLive) {
            if (!in_array($candidate, $tables, true)) {
                $tables[] = $candidate;
            }
        }
    }
}

sort($tables, SORT_NATURAL | SORT_FLAG_CASE);

$tableResults = [];
$schemaDiffCount = 0;
$missingTableCount = 0;

if ($testCfg['ok'] && $liveCfg['ok']) {
    foreach ($tables as $table) {
        $testExists = asdb_table_exists($testCfg['pdo'], $testCfg['database_name'], $table);
        $liveExists = asdb_table_exists($liveCfg['pdo'], $liveCfg['database_name'], $table);

        $entry = [
            'table' => $table,
            'test_exists' => $testExists,
            'live_exists' => $liveExists,
            'schema_match' => false,
            'test_create_sha256' => '',
            'live_create_sha256' => '',
            'column_differences' => [],
            'index_differences' => [],
        ];

        if (!$testExists || !$liveExists) {
            $missingTableCount++;
            $entry['status'] = !$testExists ? 'MISSING_IN_TEST' : 'MISSING_IN_LIVE';
            $tableResults[] = $entry;
            continue;
        }

        $testCols = asdb_columns($testCfg['pdo'], $testCfg['database_name'], $table);
        $liveCols = asdb_columns($liveCfg['pdo'], $liveCfg['database_name'], $table);

        $testIdx = asdb_indexes($testCfg['pdo'], $testCfg['database_name'], $table);
        $liveIdx = asdb_indexes($liveCfg['pdo'], $liveCfg['database_name'], $table);

        $colDiffs = asdb_compare_columns($testCols, $liveCols);
        $idxDiffs = asdb_compare_indexes($testIdx, $liveIdx);

        $testCreate = asdb_create_hash($testCfg['pdo'], $table);
        $liveCreate = asdb_create_hash($liveCfg['pdo'], $table);

        $entry['test_create_sha256'] = $testCreate['sha256'];
        $entry['live_create_sha256'] = $liveCreate['sha256'];
        $entry['column_differences'] = $colDiffs;
        $entry['index_differences'] = $idxDiffs;
        $entry['schema_match'] = !$colDiffs && !$idxDiffs
            && $testCreate['ok'] && $liveCreate['ok']
            && $testCreate['sha256'] === $liveCreate['sha256'];

        $entry['status'] = $entry['schema_match'] ? 'MATCH' : 'DIFFERENT';

        if (!$entry['schema_match']) {
            $schemaDiffCount++;
        }

        $tableResults[] = $entry;
    }
}

$connectionGate = $expected && $testCfg['ok'] && $liveCfg['ok'];
$overall = $connectionGate && $schemaDiffCount === 0 && $missingTableCount === 0;

$report = [
    'report' => MRL_ASDB_TITLE,
    'report_version' => MRL_ASDB_VERSION,
    'generated_at' => $generatedAt,
    'read_only' => true,
    'expected_location' => $expected,
    'connections' => [
        'test' => [
            'ok' => $testCfg['ok'],
            'database_name' => $testCfg['ok'] ? $testCfg['database_name'] : '',
            'error' => $testCfg['error'],
            'credentials_exported' => false,
        ],
        'live' => [
            'ok' => $liveCfg['ok'],
            'database_name' => $liveCfg['ok'] ? $liveCfg['database_name'] : '',
            'error' => $liveCfg['error'],
            'credentials_exported' => false,
        ],
    ],
    'admin_setup_files' => [
        'test_exists' => is_file($testAdminSetup),
        'live_exists' => is_file($liveAdminSetup),
        'test_sha256' => is_file($testAdminSetup) ? hash_file('sha256', $testAdminSetup) : '',
        'live_sha256' => is_file($liveAdminSetup) ? hash_file('sha256', $liveAdminSetup) : '',
        'same_code' => is_file($testAdminSetup) && is_file($liveAdminSetup)
            && hash_file('sha256', $testAdminSetup) === hash_file('sha256', $liveAdminSetup),
    ],
    'table_scope' => [
        'tables' => $tables,
        'count' => count($tables),
        'derived_from_admin_setup_php' => true,
        'common_mrl_candidates_added_if_present' => true,
    ],
    'summary' => [
        'connection_gate' => $connectionGate ? 'PASS' : 'FAIL',
        'tables_compared' => count($tableResults),
        'schema_differences' => $schemaDiffCount,
        'missing_tables' => $missingTableCount,
        'overall' => $overall ? 'PASS - SCHEMA MATCHES' : 'REVIEW REQUIRED',
    ],
    'tables' => $tableResults,
    'safety' => [
        'database_writes_performed' => false,
        'row_data_queried' => false,
        'credentials_exported' => false,
        'file_changes_performed' => false,
    ],
];

function asdb_txt(array $r): string {
    $out = [];
    $out[] = MRL_ASDB_TITLE;
    $out[] = 'Version: ' . MRL_ASDB_VERSION;
    $out[] = 'Generated: ' . $r['generated_at'];
    $out[] = 'Read only: YES';
    $out[] = '';
    $out[] = 'SUMMARY';
    foreach ($r['summary'] as $k => $v) {
        $out[] = $k . ': ' . $v;
    }
    $out[] = '';
    $out[] = 'TABLES';
    foreach ($r['tables'] as $row) {
        $out[] = '- ' . $row['table'] . ' | ' . $row['status'];
    }
    return implode("\r\n", $out) . "\r\n";
}

$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_admin_setup_db_schema_audit_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_admin_setup_db_schema_audit_' . $stamp . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo asdb_txt($report);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= asdb_h(MRL_ASDB_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1600px;margin:0 auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px;font-size:31px}h2{margin:0 0 13px}.small{color:var(--muted);font-size:13px}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;border:1px solid #4c7ba8;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:8px 8px 0 0}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}
code{color:#bddcff;background:#111318;padding:2px 5px;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1><?= asdb_h(MRL_ASDB_TITLE) ?></h1>
<div class="small">v001 · <?= asdb_h($generatedAt) ?> · READ ONLY</div>

<div class="summary">
<div class="pill">Connection gate: <span class="<?= $connectionGate ? 'pass' : 'fail' ?>"><?= $connectionGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Tables compared: <span class="info"><?= count($tableResults) ?></span></div>
<div class="pill">Schema differences: <span class="<?= $schemaDiffCount === 0 ? 'pass' : 'warn' ?>"><?= $schemaDiffCount ?></span></div>
<div class="pill">Missing tables: <span class="<?= $missingTableCount === 0 ? 'pass' : 'fail' ?>"><?= $missingTableCount ?></span></div>
<div class="pill">Overall: <span class="<?= $overall ? 'pass' : 'warn' ?>"><?= asdb_h($report['summary']['overall']) ?></span></div>
</div>

<a class="button" href="?format=json&x=<?= asdb_h((string)microtime(true)) ?>">Download JSON Results</a>
<a class="button" href="?format=txt&x=<?= asdb_h((string)microtime(true)) ?>">Download TXT Results</a>
</div>

<div class="panel">
<h2>Database connections</h2>
<table>
<thead><tr><th>Environment</th><th>Status</th><th>Database</th><th>Credentials exported</th></tr></thead>
<tbody>
<tr><td>TESTPHP8</td><td class="<?= $testCfg['ok'] ? 'pass' : 'fail' ?>"><?= $testCfg['ok'] ? 'PASS' : 'FAIL' ?></td><td><?= asdb_h($testCfg['database_name']) ?></td><td>NO</td></tr>
<tr><td>LIVE</td><td class="<?= $liveCfg['ok'] ? 'pass' : 'fail' ?>"><?= $liveCfg['ok'] ? 'PASS' : 'FAIL' ?></td><td><?= asdb_h($liveCfg['database_name']) ?></td><td>NO</td></tr>
</tbody>
</table>
</div>

<div class="panel">
<h2>Schema comparison</h2>
<table>
<thead><tr><th>Table</th><th>TEST</th><th>LIVE</th><th>Status</th><th>Column diffs</th><th>Index diffs</th></tr></thead>
<tbody>
<?php foreach ($tableResults as $row): ?>
<tr>
<td><code><?= asdb_h($row['table']) ?></code></td>
<td><?= $row['test_exists'] ? 'YES' : 'NO' ?></td>
<td><?= $row['live_exists'] ? 'YES' : 'NO' ?></td>
<td class="<?= $row['status'] === 'MATCH' ? 'pass' : ($row['status'] === 'DIFFERENT' ? 'warn' : 'fail') ?>"><?= asdb_h($row['status']) ?></td>
<td><?= count($row['column_differences']) ?></td>
<td><?= count($row['index_differences']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="panel">
<h2>Safety</h2>
<ul>
<li>No database writes.</li>
<li>No row data queried or exported.</li>
<li>No credentials exported.</li>
<li>No files changed.</li>
<li>Live scheduler may remain running.</li>
</ul>
</div>

</div>
</body>
</html>
