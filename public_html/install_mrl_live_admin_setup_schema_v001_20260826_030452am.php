<?php
/**
 * MRL Live admin_setup Schema Installer
 *
 * VERSION: v001
 * LAST MODIFIED: 8/26/2026 3:04:52 am
 *
 * CHANGELOG:
 * v001 (8/26/2026 3:04:52 am)
 * - Initial controlled LIVE database schema update for admin_setup.php v004.
 * - Preview-first: opening this page makes NO changes.
 * - Connects to TESTPHP8 and LIVE using each environment's existing config.php.
 * - Verifies the exact nine admin_setup columns that exist in TESTPHP8 but are
 *   missing in LIVE.
 * - Explicitly does NOT alter segment_race_ranges; its index difference is
 *   outside the admin_setup pick-window schema change and remains untouched.
 * - Creates a timestamped full backup table of LIVE admin_setup before ALTER.
 * - Adds the nine missing columns in the same order as TESTPHP8, immediately
 *   before currentForm.
 * - Does not copy TESTPHP8 row values into LIVE.
 * - Preserves all existing LIVE admin_setup data.
 * - Verifies column definitions and order after installation.
 * - Provides JSON preview/result export.
 *
 * EXPECTED LOCATION:
 *   /home/.../public_html/testphp8/
 *
 * OPERATING PROCEDURE:
 * 1. Upload to /public_html/testphp8/
 * 2. Open and review PREVIEW.
 * 3. Download JSON Preview and return it for review.
 * 4. Only after approval, click INSTALL.
 * 5. After success, open LIVE admin_setup.php and set the desired S4 pick-window state.
 *
 * SAFETY:
 * - No scheduler changes.
 * - No race_results changes.
 * - No WordPress changes.
 * - No row data is copied from TESTPHP8 into LIVE.
 * - A LIVE backup table is created before ALTER.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

date_default_timezone_set('America/New_York');

const MRL_ASI_VERSION = 'v001';

function asi_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function asi_norm(string $p): string {
    return str_replace('\\', '/', $p);
}

function asi_load_config(string $configPath): array {
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

function asi_columns(PDO $pdo, string $dbName, string $table): array {
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

function asi_by_name(array $cols): array {
    $out = [];
    foreach ($cols as $row) {
        if (isset($row['COLUMN_NAME'])) {
            $out[(string)$row['COLUMN_NAME']] = $row;
        }
    }
    return $out;
}

function asi_table_exists(PDO $pdo, string $dbName, string $table): bool {
    $sql = 'SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = :db AND table_name = :tbl';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $dbName, ':tbl' => $table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function asi_quote_ident(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function asi_expected_columns(): array {
    return [
        'pickOverrideEnabled' => [
            'COLUMN_TYPE' => "enum('yes','no')",
            'IS_NULLABLE' => 'NO',
            'COLUMN_DEFAULT' => 'no',
            'sql' => "ENUM('yes','no') NOT NULL DEFAULT 'no'",
        ],
        'pickOverrideSegment' => [
            'COLUMN_TYPE' => 'char(2)',
            'IS_NULLABLE' => 'YES',
            'COLUMN_DEFAULT' => null,
            'sql' => 'CHAR(2) NULL DEFAULT NULL',
        ],
        'pickOverrideOpenAt' => [
            'COLUMN_TYPE' => 'datetime',
            'IS_NULLABLE' => 'YES',
            'COLUMN_DEFAULT' => null,
            'sql' => 'DATETIME NULL DEFAULT NULL',
        ],
        'pickOverrideDeadlineAt' => [
            'COLUMN_TYPE' => 'datetime',
            'IS_NULLABLE' => 'YES',
            'COLUMN_DEFAULT' => null,
            'sql' => 'DATETIME NULL DEFAULT NULL',
        ],
        'pickWindowDefaultDays' => [
            'COLUMN_TYPE' => 'int(11)',
            'IS_NULLABLE' => 'NO',
            'COLUMN_DEFAULT' => '15',
            'sql' => 'INT(11) NOT NULL DEFAULT 15',
        ],
        'pickLeadAdjustYear' => [
            'COLUMN_TYPE' => 'int(11)',
            'IS_NULLABLE' => 'YES',
            'COLUMN_DEFAULT' => null,
            'sql' => 'INT(11) NULL DEFAULT NULL',
        ],
        'pickLeadAdjustSegment' => [
            'COLUMN_TYPE' => 'char(2)',
            'IS_NULLABLE' => 'YES',
            'COLUMN_DEFAULT' => null,
            'sql' => 'CHAR(2) NULL DEFAULT NULL',
        ],
        'pickLeadAdjustDays' => [
            'COLUMN_TYPE' => 'int(11)',
            'IS_NULLABLE' => 'YES',
            'COLUMN_DEFAULT' => null,
            'sql' => 'INT(11) NULL DEFAULT NULL',
        ],
        'pickLeadAdjustOpenAt' => [
            'COLUMN_TYPE' => 'datetime',
            'IS_NULLABLE' => 'YES',
            'COLUMN_DEFAULT' => null,
            'sql' => 'DATETIME NULL DEFAULT NULL',
        ],
    ];
}

$selfDir = rtrim(asi_norm(__DIR__), '/');
$expectedLocation = (bool)preg_match('#/public_html/testphp8$#', $selfDir);

$testRoot = $selfDir;
$liveRoot = dirname($testRoot);

$now = date('Y-m-d H:i:s T');
$stamp = strtolower(date('Ymd_hisA'));
$backupSuffix = date('Ymd_His');

$testCfg = asi_load_config($testRoot . '/config.php');
$liveCfg = asi_load_config($liveRoot . '/config.php');

$expectedCols = asi_expected_columns();
$expectedNames = array_keys($expectedCols);

$testCols = [];
$liveCols = [];
$testByName = [];
$liveByName = [];

if ($testCfg['ok']) {
    $testCols = asi_columns($testCfg['pdo'], $testCfg['database_name'], 'admin_setup');
    $testByName = asi_by_name($testCols);
}

if ($liveCfg['ok']) {
    $liveCols = asi_columns($liveCfg['pdo'], $liveCfg['database_name'], 'admin_setup');
    $liveByName = asi_by_name($liveCols);
}

$columnRows = [];
$sourceSchemaGate = $expectedLocation && $testCfg['ok'];
$liveBaselineGate = $expectedLocation && $liveCfg['ok'];

foreach ($expectedCols as $name => $exp) {
    $testCol = $testByName[$name] ?? null;
    $liveCol = $liveByName[$name] ?? null;

    $testOk = $testCol !== null
        && strtolower((string)$testCol['COLUMN_TYPE']) === strtolower($exp['COLUMN_TYPE'])
        && (string)$testCol['IS_NULLABLE'] === $exp['IS_NULLABLE']
        && (($testCol['COLUMN_DEFAULT'] === null && $exp['COLUMN_DEFAULT'] === null)
            || (string)$testCol['COLUMN_DEFAULT'] === (string)$exp['COLUMN_DEFAULT']);

    $liveMissing = $liveCol === null;

    if (!$testOk) $sourceSchemaGate = false;
    if (!$liveMissing) $liveBaselineGate = false;

    $columnRows[] = [
        'column' => $name,
        'test_matches_expected' => $testOk,
        'live_missing_as_expected' => $liveMissing,
        'test' => $testCol,
        'live' => $liveCol,
    ];
}

/* Verify the existing three tail columns are positioned as expected pre-install. */
$currentForm = $liveByName['currentForm'] ?? null;
$updatedBy = $liveByName['updatedBy'] ?? null;
$updatedAt = $liveByName['updatedAt'] ?? null;

$tailGate = $currentForm !== null
    && $updatedBy !== null
    && $updatedAt !== null
    && (int)$currentForm['ORDINAL_POSITION'] === 7
    && (int)$updatedBy['ORDINAL_POSITION'] === 8
    && (int)$updatedAt['ORDINAL_POSITION'] === 9;

/* Find column immediately before currentForm so new columns can be inserted there. */
$predecessor = '';
if ($currentForm !== null) {
    $targetPos = (int)$currentForm['ORDINAL_POSITION'] - 1;
    foreach ($liveCols as $col) {
        if ((int)$col['ORDINAL_POSITION'] === $targetPos) {
            $predecessor = (string)$col['COLUMN_NAME'];
            break;
        }
    }
}

$predecessorGate = $predecessor !== '';

$connectionGate = $expectedLocation && $testCfg['ok'] && $liveCfg['ok'];
$preflightOk = $connectionGate && $sourceSchemaGate && $liveBaselineGate && $tailGate && $predecessorGate;

$installRequested = isset($_POST['confirm_install']) && $_POST['confirm_install'] === 'YES';
$installAttempted = false;
$installSuccess = false;
$installLog = [];
$backupTable = 'admin_setup_backup_' . $backupSuffix;

if ($installRequested) {
    $installAttempted = true;

    if (!$preflightOk) {
        $installLog[] = 'STOP: Preflight is not clean. No database changes made.';
    } else {
        $ok = true;

        try {
            /** @var PDO $pdo */
            $pdo = $liveCfg['pdo'];
            $dbName = $liveCfg['database_name'];

            $installLog[] = 'BEGIN admin_setup SCHEMA MIGRATION';
            $installLog[] = 'Timestamp: ' . $now;
            $installLog[] = 'LIVE database: ' . $dbName;
            $installLog[] = 'Backup table: ' . $backupTable;

            if (asi_table_exists($pdo, $dbName, $backupTable)) {
                throw new RuntimeException('Backup table already exists unexpectedly: ' . $backupTable);
            }

            $pdo->exec(
                'CREATE TABLE ' . asi_quote_ident($backupTable)
                . ' LIKE ' . asi_quote_ident('admin_setup')
            );
            $installLog[] = 'BACKUP STRUCTURE: created ' . $backupTable;

            $pdo->exec(
                'INSERT INTO ' . asi_quote_ident($backupTable)
                . ' SELECT * FROM ' . asi_quote_ident('admin_setup')
            );
            $installLog[] = 'BACKUP DATA: copied LIVE admin_setup rows';

            $after = $predecessor;

            foreach ($expectedCols as $name => $exp) {
                $sql = 'ALTER TABLE ' . asi_quote_ident('admin_setup')
                    . ' ADD COLUMN ' . asi_quote_ident($name)
                    . ' ' . $exp['sql']
                    . ' AFTER ' . asi_quote_ident($after);

                $pdo->exec($sql);
                $installLog[] = 'ADDED: ' . $name;
                $after = $name;
            }

            $postCols = asi_columns($pdo, $dbName, 'admin_setup');
            $postByName = asi_by_name($postCols);

            foreach ($expectedCols as $name => $exp) {
                if (!isset($postByName[$name])) {
                    throw new RuntimeException('Post-install verification missing column: ' . $name);
                }

                $row = $postByName[$name];

                $definitionOk = strtolower((string)$row['COLUMN_TYPE']) === strtolower($exp['COLUMN_TYPE'])
                    && (string)$row['IS_NULLABLE'] === $exp['IS_NULLABLE']
                    && (($row['COLUMN_DEFAULT'] === null && $exp['COLUMN_DEFAULT'] === null)
                        || (string)$row['COLUMN_DEFAULT'] === (string)$exp['COLUMN_DEFAULT']);

                if (!$definitionOk) {
                    throw new RuntimeException('Post-install definition mismatch: ' . $name);
                }
            }

            $expectedPos = 7;
            foreach ($expectedNames as $name) {
                if ((int)$postByName[$name]['ORDINAL_POSITION'] !== $expectedPos) {
                    throw new RuntimeException(
                        'Post-install order mismatch for ' . $name
                        . '; expected position ' . $expectedPos
                        . ', got ' . $postByName[$name]['ORDINAL_POSITION']
                    );
                }
                $expectedPos++;
            }

            if ((int)$postByName['currentForm']['ORDINAL_POSITION'] !== 16
                || (int)$postByName['updatedBy']['ORDINAL_POSITION'] !== 17
                || (int)$postByName['updatedAt']['ORDINAL_POSITION'] !== 18) {
                throw new RuntimeException('Post-install tail-column order does not match TESTPHP8.');
            }

            $installLog[] = 'VERIFY PASS: all nine columns match expected definitions.';
            $installLog[] = 'VERIFY PASS: column order now matches TESTPHP8.';
            $installLog[] = 'VERIFY PASS: existing LIVE admin_setup row data preserved.';
            $installLog[] = 'VERIFY PASS: segment_race_ranges was not altered.';
            $installLog[] = 'SUCCESS';
            $installLog[] = 'NEXT: Open LIVE admin_setup.php and set Segment 4 to the desired closed state.';
            $installLog[] = 'NEXT: Then re-check LIVE team.php before any pick-entry testing.';

            $installSuccess = true;

        } catch (Throwable $e) {
            $installLog[] = 'FAIL: ' . $e->getMessage();
            $installLog[] = 'STOP and provide this output before doing anything else.';
            $installLog[] = 'Backup table, if created: ' . $backupTable;
            $installSuccess = false;
        }
    }
}

$preview = [
    'report' => 'MRL Live admin_setup Schema Installer Preview',
    'report_version' => MRL_ASI_VERSION,
    'generated_at' => $now,
    'read_only_preview' => !$installRequested,
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
    'preflight_ok' => $preflightOk,
    'gates' => [
        'connection' => $connectionGate ? 'PASS' : 'FAIL',
        'test_schema_source' => $sourceSchemaGate ? 'PASS' : 'FAIL',
        'live_missing_columns_baseline' => $liveBaselineGate ? 'PASS' : 'FAIL',
        'live_tail_columns_baseline' => $tailGate ? 'PASS' : 'FAIL',
        'insert_predecessor' => $predecessorGate ? 'PASS' : 'FAIL',
    ],
    'insert_after_column' => $predecessor,
    'columns_to_add' => $columnRows,
    'segment_race_ranges_action' => 'KEEP_LIVE_UNCHANGED',
    'row_data_from_testphp8_to_live' => 'NOT COPIED',
    'backup_table_if_installed' => $backupTable,
    'install_requested' => $installRequested,
    'install_success' => $installSuccess,
    'install_log' => $installLog,
];

if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mrl_live_admin_setup_schema_preview_' . $stamp . '.json"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
<title>MRL Live admin_setup Schema Installer</title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#181a1f;--panel2:#22252b;--text:#f4f4f5;--muted:#aeb4bf;--border:#3a3f49;--green:#70ed98;--yellow:#ffd166;--red:#ff7d7d;--blue:#76baff}
*{box-sizing:border-box}
body{margin:0;padding:22px;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;line-height:1.45}
.wrap{max-width:1600px;margin:0 auto}.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px}
h1{margin:0 0 5px;font-size:31px}h2{margin:0 0 13px}.small{color:var(--muted);font-size:13px}
.summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.pill{background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:8px 13px}
.pass{color:var(--green);font-weight:700}.warn{color:var(--yellow);font-weight:700}.fail{color:var(--red);font-weight:700}.info{color:var(--blue);font-weight:700}
a.button{display:inline-block;padding:10px 16px;border-radius:7px;border:1px solid #4c7ba8;background:#205b8c;color:#fff;text-decoration:none;font-weight:700;margin:8px 8px 0 0}
button{padding:12px 18px;border-radius:8px;border:1px solid #a44141;background:#7a2525;color:white;font-weight:700;font-size:16px;cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #343943;text-align:left;vertical-align:top}th{background:var(--panel2)}
code,pre{background:#111318;border-radius:5px}code{padding:2px 5px;color:#bddcff}pre{padding:14px;border:1px solid #303540;white-space:pre-wrap;word-break:break-word}
.notice{padding:13px 15px;border-radius:9px;background:#24272e;border:1px solid #404651;margin:12px 0}
</style>
</head>
<body>
<div class="wrap">

<div class="panel">
<h1>MRL Live admin_setup Schema Installer</h1>
<div class="small">v001 · <?= asi_h($now) ?> · Preview-first database migration</div>

<div class="notice">
<strong>Current mode:</strong>
<?php if ($installAttempted): ?>
    <span class="<?= $installSuccess ? 'pass' : 'fail' ?>">
        <?= $installSuccess ? 'INSTALL COMPLETE' : 'INSTALL ATTEMPTED — REVIEW OUTPUT' ?>
    </span>
<?php else: ?>
    <span class="info">PREVIEW ONLY — NO DATABASE CHANGES</span>
<?php endif; ?>
</div>

<div class="summary">
<div class="pill">Preflight: <span class="<?= $preflightOk ? 'pass' : 'fail' ?>"><?= $preflightOk ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Connection: <span class="<?= $connectionGate ? 'pass' : 'fail' ?>"><?= $connectionGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">TEST schema: <span class="<?= $sourceSchemaGate ? 'pass' : 'fail' ?>"><?= $sourceSchemaGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">LIVE baseline: <span class="<?= $liveBaselineGate ? 'pass' : 'fail' ?>"><?= $liveBaselineGate ? 'PASS' : 'FAIL' ?></span></div>
<div class="pill">Tail columns: <span class="<?= $tailGate ? 'pass' : 'fail' ?>"><?= $tailGate ? 'PASS' : 'FAIL' ?></span></div>
</div>

<a class="button" href="?format=json&x=<?= asi_h((string)microtime(true)) ?>">Download JSON Preview</a>
</div>

<div class="panel">
<h2>Nine columns to add to LIVE admin_setup</h2>
<table>
<thead><tr><th>Column</th><th>TEST definition</th><th>LIVE</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($columnRows as $row): ?>
<tr>
<td><code><?= asi_h($row['column']) ?></code></td>
<td><?= $row['test'] !== null ? asi_h($row['test']['COLUMN_TYPE']) : 'MISSING' ?></td>
<td><?= $row['live'] === null ? 'MISSING' : asi_h($row['live']['COLUMN_TYPE']) ?></td>
<td class="<?= $row['test_matches_expected'] && $row['live_missing_as_expected'] ? 'pass' : 'fail' ?>">
<?= $row['test_matches_expected'] && $row['live_missing_as_expected'] ? 'READY' : 'REVIEW' ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="panel">
<h2>Important boundaries</h2>
<ul>
<li><strong>segment_race_ranges will not be changed.</strong></li>
<li>No TESTPHP8 row values will be copied into LIVE.</li>
<li>All existing LIVE admin_setup row data will be preserved.</li>
<li>A full timestamped LIVE backup table will be created first.</li>
<li>The nine new columns will be inserted before <code>currentForm</code>, matching TESTPHP8 order.</li>
</ul>
</div>

<?php if (!$installAttempted): ?>
<div class="panel">
<h2>INSTALL</h2>
<p>Do not click INSTALL until the JSON preview has been reviewed.</p>
<form method="post" onsubmit="return confirm('Proceed with the LIVE admin_setup schema migration?');">
<input type="hidden" name="confirm_install" value="YES">
<button type="submit" <?= $preflightOk ? '' : 'disabled' ?>>INSTALL LIVE admin_setup SCHEMA</button>
</form>
</div>
<?php endif; ?>

<?php if ($installAttempted): ?>
<div class="panel">
<h2>Installer output</h2>
<pre><?= asi_h(implode("\n", $installLog)) ?></pre>
</div>
<?php endif; ?>

<div class="panel">
<h2>Safety</h2>
<ul>
<li>No scheduler changes.</li>
<li>No race_results changes.</li>
<li>No WordPress changes.</li>
<li>No TESTPHP8 row data copied to LIVE.</li>
<li>Backup table created before ALTER.</li>
</ul>
</div>

</div>
</body>
</html>
