<?php
declare(strict_types=1);

/**
 * install_race_results_dashboard_v022_20260726_104138am.php
 *
 * VERSION: v001
 * LAST MODIFIED: 7/26/2026 10:41:38 am
 *
 * PURPOSE:
 * - Updates race_results_dashboard.php from v021 to v022.
 * - Suppresses non-Cup NASCAR At a Glance cache details.
 * - Shows "Waiting for race start" until Cup Series live data is present.
 *
 * USAGE:
 * 1. Upload this installer to /race_results/.
 * 2. Open it once in a browser.
 * 3. Review the success report.
 * 4. Delete this installer from the server.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');
header('Content-Type: text/plain; charset=UTF-8');

$baseDir = __DIR__;
$target = $baseDir . '/race_results_dashboard.php';
$backupDir = $baseDir . '/_race_results_dashboard_install_backup_20260726_104138am';
$backupFile = $backupDir . '/race_results_dashboard.php';

echo "MRL Race Results Dashboard installer v022\n";
echo "Base: " . $baseDir . "\n\n";

if (!is_file($target)) {
    exit("ERROR: race_results_dashboard.php was not found. Nothing was changed.\n");
}

$source = @file_get_contents($target);
if (!is_string($source) || $source === '') {
    exit("ERROR: Could not read race_results_dashboard.php. Nothing was changed.\n");
}

if (strpos($source, "const RACE_RESULTS_DASHBOARD_VERSION = 'v022';") !== false) {
    exit("ALREADY INSTALLED: race_results_dashboard.php is already v022.\n");
}

$requiredPatterns = [
    " * VERSION: v021",
    " * LAST MODIFIED: 6/21/2026 10:42:17 pm",
    "const RACE_RESULTS_DASHBOARD_VERSION = 'v021';",
    "<?php if (is_file(__DIR__ . '/mrl_nascar_at_a_glance_panel.php')) { require __DIR__ . '/mrl_nascar_at_a_glance_panel.php'; } ?>"
];

foreach ($requiredPatterns as $pattern) {
    if (strpos($source, $pattern) === false) {
        exit("ERROR: Expected v021 pattern was not found:\n" . $pattern . "\n\nNothing was changed.\n");
    }
}

if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
    exit("ERROR: Could not create backup directory. Nothing was changed.\n");
}

if (!@copy($target, $backupFile)) {
    exit("ERROR: Could not back up race_results_dashboard.php. Nothing was changed.\n");
}

echo "BACKUP: race_results_dashboard.php\n";

$source = str_replace(
    " * VERSION: v021",
    " * VERSION: v022",
    $source,
    $countVersion
);

$source = str_replace(
    " * LAST MODIFIED: 6/21/2026 10:42:17 pm",
    " * LAST MODIFIED: 7/26/2026 10:41:38 am",
    $source,
    $countModified
);

$changelogNeedle = " * CHANGELOG:\n *\n";
$changelogInsert = " * CHANGELOG:\n *\n"
    . " * v022 (7/26/2026 10:41:38 am)\n"
    . " *   - CHANGE: NASCAR At a Glance now displays only Cup Series live data.\n"
    . " *   - CHANGE: Non-Cup or unavailable cache data is hidden and replaced with Waiting for race start.\n"
    . " *   - NOTE: The underlying cache remains available for technical use but is not shown on the operator dashboard.\n"
    . " *\n";

$source = str_replace(
    $changelogNeedle,
    $changelogInsert,
    $source,
    $countChangelog
);

$source = str_replace(
    "const RACE_RESULTS_DASHBOARD_VERSION = 'v021';",
    "const RACE_RESULTS_DASHBOARD_VERSION = 'v022';",
    $source,
    $countConst
);

$oldPanelLine = "<?php if (is_file(__DIR__ . '/mrl_nascar_at_a_glance_panel.php')) { require __DIR__ . '/mrl_nascar_at_a_glance_panel.php'; } ?>";

$newPanelBlock = <<<'PHPBLOCK'
<?php
    $nascarAtGlanceCache = rr_dash_load_json_file(__DIR__ . '/_mrl_nascar_live_status.json');
    $nascarAtGlanceSeriesId = (int)($nascarAtGlanceCache['series_id'] ?? 0);
    $nascarAtGlanceIsCup = ($nascarAtGlanceSeriesId === 1);

    if ($nascarAtGlanceIsCup && is_file(__DIR__ . '/mrl_nascar_at_a_glance_panel.php')) {
        require __DIR__ . '/mrl_nascar_at_a_glance_panel.php';
    } else {
?>
        <div class="race-progress-row" style="margin-top:14px;">
            <span class="pill"><strong>NASCAR At a Glance:</strong> Waiting for race start</span>
        </div>
<?php
    }
?>
PHPBLOCK;

$source = str_replace(
    $oldPanelLine,
    $newPanelBlock,
    $source,
    $countPanel
);

if (
    $countVersion !== 1 ||
    $countModified !== 1 ||
    $countChangelog !== 1 ||
    $countConst !== 1 ||
    $countPanel !== 1
) {
    @copy($backupFile, $target);
    exit(
        "ERROR: Update counts were unexpected. Original file was restored.\n"
        . "version=" . $countVersion
        . " modified=" . $countModified
        . " changelog=" . $countChangelog
        . " const=" . $countConst
        . " panel=" . $countPanel . "\n"
    );
}

$tmp = $target . '.tmp_' . bin2hex(random_bytes(4));
if (@file_put_contents($tmp, $source, LOCK_EX) === false) {
    @unlink($tmp);
    @copy($backupFile, $target);
    exit("ERROR: Could not write temporary updated file. Original file was restored.\n");
}

if (!@rename($tmp, $target)) {
    @unlink($tmp);
    @copy($backupFile, $target);
    exit("ERROR: Could not install updated file. Original file was restored.\n");
}

echo "UPDATED: race_results_dashboard.php\n";
echo "VERSION: v022\n";
echo "CHANGE: Non-Cup NASCAR cache details are now hidden.\n";
echo "DISPLAY: Waiting for race start until Cup Series live data is available.\n";
echo "\nSUCCESS\n";
echo "Backup: " . $backupDir . "\n";
echo "Delete this installer after verification.\n";
