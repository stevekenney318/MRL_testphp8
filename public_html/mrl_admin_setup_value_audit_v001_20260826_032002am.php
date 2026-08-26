<?php
/**
 * MRL admin_setup Value Audit
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 3:20:02 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 3:20:02 am)
 * - Initial read-only TESTPHP8 vs LIVE value audit for admin_setup.
 * - Focuses only on non-secret operational fields relevant to currentForm and pick-window behavior.
 * - Compares currentForm plus the nine pick-window fields added during the LIVE schema migration.
 * - Does not export credentials, unrelated row data, user data, or database connection values.
 * - Provides timestamped JSON and TXT exports.
 * - Makes NO database writes and NO file changes.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 *
 * SAFETY:
 * - READ ONLY.
 * - No INSERT/UPDATE/DELETE/ALTER/CREATE/DROP/TRUNCATE statements.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_ASVA_VERSION = 'v001';
const MRL_ASVA_TITLE = 'MRL admin_setup Value Audit';

function asva_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function asva_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function asva_load_config(string $configPath): array {
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

function asva_table_columns(PDO $pdo, string $dbName, string $table): array {
    $sql = 'SELECT COLUMN_NAME
            FROM information_schema.columns
            WHERE table_schema = :db AND table_name = :tbl
            ORDER BY ORDINAL_POSITION';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $dbName, ':tbl' => $table]);

    $out = [];
    while ($name = $stmt->fetchColumn()) {
        $out[] = (string)$name;
    }
    return $out;
}

function asva_fetch_operational_row(PDO $pdo, array $fields): array {
    if (!$fields) {
        return [];
    }

    $quoted = [];
    foreach ($fields as $field) {
        $quoted[] = '`' . str_replace('`', '``', $field) . '`';
    }

    $sql = 'SELECT ' . implode(', ', $quoted) . ' FROM `admin_setup` LIMIT 1';
    $stmt = $pdo->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

$selfDir = rtrim(asva_norm(__DIR__), '/');
$expectedLocation = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$testRoot = $selfDir;
$liveRoot = dirname($testRoot);

$generatedAt = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));

$testCfg = asva_load_config($testRoot . '/config.php');
$liveCfg = asva_load_config($liveRoot . '/config.php');

$wantedFields = [
    'currentForm',
    'pickOverrideEnabled',
    'pickOverrideSegment',
    'pickOverrideOpenAt',
    'pickOverrideDeadlineAt',
    'pickWindowDefaultDays',
    'pickLeadAdjustYear',
    'pickLeadAdjustSegment',
    'pickLeadAdjustDays',
    'pickLeadAdjustOpenAt',
];

$testAvailable = [];
$liveAvailable = [];
$testRow = [];
$liveRow = [];

if ($testCfg['ok']) {
    $testAvailable = asva_table_columns($testCfg['pdo'], $testCfg['database_name'], 'admin_setup');
    $testFields = array_values(array_intersect($wantedFields, $testAvailable));
    $testRow = asva_fetch_operational_row($testCfg['pdo'], $testFields);
}

if ($liveCfg['ok']) {
    $liveAvailable = asva_table_columns($liveCfg['pdo'], $liveCfg['database_name'], 'admin_setup');
    $liveFields = array_values(array_intersect($wantedFields, $liveAvailable));
    $liveRow = asva_fetch_operational_row($liveCfg['pdo'], $liveFields);
}

$comparisons = [];
$differenceCount = 0;

foreach ($wantedFields as $field) {
    $testHas = array_key_exists($field, $testRow);
    $liveHas = array_key_exists($field, $liveRow);

    $testValue = $testHas ? $testRow[$field] : null;
    $liveValue = $liveHas ? $liveRow[$field] : null;

    $same = $testHas && $liveHas && $testValue === $liveValue;

    if (!$same) {
        $differenceCount++;
    }

    $comparisons[] = [
        'field' => $field,
        'test_present' => $testHas,
        'live_present' => $liveHas,
        'test_value' => $testValue,
        'live_value' => $liveValue,
        'same' => $same,
    ];
}

$currentFormTest = $testRow['currentForm'] ?? null;
$currentFormLive = $liveRow['currentForm'] ?? null;

$currentFormMatches = $currentFormTest !== null
    && $currentFormLive !== null
    && $currentFormTest === $currentFormLive;

$connectionGate = $expectedLocation && $testCfg['ok'] && $liveCfg['ok'];

$report = [
    'report' => MRL_ASVA_TITLE,
    'report_version' => MRL_ASVA_VERSION,
    'generated_at' => $generatedAt,
    'read_only' => true,
    'expected_location' => $expectedLocation,
    'connections' => [
        'test' => [
            'ok' => $testCfg['ok'],
            'database_name' => $testCfg['ok'] ? $testCfg['database_name'] : '',
            'credentials_exported' => false,
        ],
        'live' => [
            'ok' => $liveCfg['ok'],
            'database_name' => $liveCfg['ok'] ? $liveCfg['database_name'] : '',
            'credentials_exported' => false,
        ],
    ],
    'summary' => [
        'connection_gate' => $connectionGate ? 'PASS' : 'FAIL',
        'fields_compared' => count($comparisons),
        'differences' => $differenceCount,
        'current_form_test' => $currentFormTest,
        'current_form_live' => $currentFormLive,
        'current_form_matches' => $currentFormMatches,
    ],
    'comparisons' => $comparisons,
    'safety' => [
        'database_writes_performed' => false,
        'credentials_exported' => false,
        'unrelated_row_data_exported' => false,
        'file_changes_performed' => false,
    ],
];

function asva_txt(array $r): string {
    $out = [];
    $out[] = MRL_ASVA_TITLE;
    $out[] = 'Version: ' . MRL_ASVA_VERSION;
    $out[] = 'Generated: ' . $r['generated_at'];
    $out[] = 'Read only: YES';
    $out[] = '';
    $out[] = 'SUMMARY';
    foreach ($r['summary'] as $k => $v) {
        if (is_bool($v)) {
            $v = $v ? 'true' : 'false';
        } elseif ($v === null) {
            $v = 'NULL';
        }
        $out[] = $k . ': ' . $v;
    }
    $out[] = '';
    $out[] = 'FIELDS';
    foreach ($r['comparisons'] as $row) {
        $out[] = '- ' . $row['field']
            . ' | TEST=' . ($row['test_value'] === null ? 'NULL' : (string)$row['test_value'])
            . ' | LIVE=' . ($row['live_value'] === null ? 'NULL' : (string)$row['live_value'])
            . ' | ' . ($row['same'] ? 'MATCH' : 'DIFFERENT');
    }
    return implode("\r\n", $out) . "\r\n";
}

$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_admin_setup_value_audit_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_admin_setup_value_audit_' . $stamp . '.txt"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo asva_txt($report);
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
<title><?= asva_h(MRL_ASVA_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1500px;margin:0 auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
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
<h1><?= asva_h(MRL_ASVA_TITLE) ?></h1>
<div class="small">v001 · <?= asva_h($generatedAt) ?> · READ ONLY</div>

<div class="summary">
<div class="pill">Connection: <span class="<?= $connectionGate ? 'pass' : 'fail' ?>"><?= $connectionGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Fields compared: <span class="info"><?= count($comparisons) ?></span></div>
<div class="pill">Differences: <span class="<?= $differenceCount === 0 ? 'pass' : 'warn' ?>"><?= $differenceCount ?></span></div>
<div class="pill">currentForm: <span class="<?= $currentFormMatches ? 'pass' : 'warn' ?>"><?= $currentFormMatches ? 'MATCH' : 'DIFFERENT' ?></span></div>
</div>

<a class="button" href="?format=json&x=<?= asva_h((string)microtime(true)) ?>">Download JSON Results</a>
<a class="button" href="?format=txt&x=<?= asva_h((string)microtime(true)) ?>">Download TXT Results</a>
</div>

<div class="panel">
<h2>Operational field comparison</h2>
<table>
<thead><tr><th>Field</th><th>TESTPHP8</th><th>LIVE</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($comparisons as $row): ?>
<tr>
<td><code><?= asva_h($row['field']) ?></code></td>
<td><?= asva_h($row['test_value'] === null ? 'NULL' : $row['test_value']) ?></td>
<td><?= asva_h($row['live_value'] === null ? 'NULL' : $row['live_value']) ?></td>
<td class="<?= $row['same'] ? 'pass' : 'warn' ?>"><?= $row['same'] ? 'MATCH' : 'DIFFERENT' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="panel">
<h2>Safety</h2>
<ul>
<li>No database writes.</li>
<li>No credentials exported.</li>
<li>No unrelated row data exported.</li>
<li>No files changed.</li>
</ul>
</div>

</div>
</body>
</html>
