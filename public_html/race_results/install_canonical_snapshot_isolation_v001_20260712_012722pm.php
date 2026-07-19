<?php
declare(strict_types=1);

/**
 * MRL CANONICAL SNAPSHOT ISOLATION INSTALLER
 *
 * VERSION: v001
 * LAST MODIFIED: 7/12/2026 1:27:22 pm
 * TIMEZONE: America/New_York
 *
 * PURPOSE:
 * - Prevent derived snapshot files such as _lite.html and future _mrl.html
 *   from being treated as canonical revision snapshots.
 * - Make release-history rebuilding use canonical snapshots only.
 * - Make classifier Pending Review status follow release-level metadata,
 *   not stale race-folder under_review.flag files.
 * - Back up stale under_review.flag files when no release is pending.
 * - Rebuild classification artifacts from canonical snapshots.
 *
 * INSTALL LOCATION:
 * - Upload to /public_html/race_results/
 * - Run once in a browser.
 * - Delete after successful verification.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');
ini_set('display_errors', '1');
error_reporting(E_ALL);

const INSTALLER_VERSION = 'v001';
const INSTALLER_TIMESTAMP = '7/12/2026 1:27:22 pm';

$baseDir = __DIR__;
$year = 2026;
$backupStamp = date('Ymd_His');

$report = [];
$errors = [];

function ci_out(string $message, string $type = 'info'): void
{
    global $report;
    $report[] = ['type' => $type, 'message' => $message];
}

function ci_read(string $path): string
{
    $data = @file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Unable to read: ' . $path);
    }

    // Normalize Windows/Unix line endings before applying installer patches.
    // The GitHub and Hostinger copies may have identical code but different
    // CRLF/LF line endings.
    return str_replace(["\r\n", "\r"], "\n", $data);
}

function ci_write(string $path, string $content): void
{
    global $backupStamp;

    if (!is_file($path)) {
        throw new RuntimeException('Target file does not exist: ' . $path);
    }

    $backup = $path . '.bak_' . $backupStamp;
    if (!@copy($path, $backup)) {
        throw new RuntimeException('Unable to create backup: ' . $backup);
    }

    $tmp = $path . '.tmp_' . getmypid();
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write temporary file: ' . $tmp);
    }

    @chmod($tmp, 0644);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace target file: ' . $path);
    }

    @chmod($path, 0644);
    ci_out('Backup created: ' . basename($backup), 'ok');
    ci_out('Updated: ' . basename($path), 'ok');
}

function ci_replace_once(string $label, string $source, string $old, string $new): string
{
    $count = substr_count($source, $old);

    if ($count !== 1) {
        throw new RuntimeException(
            $label . ' expected exactly one match; found ' . $count . '.'
        );
    }

    ci_out('Patched: ' . $label, 'ok');
    return str_replace($old, $new, $source);
}

function ci_replace_all_required(string $label, string $source, string $old, string $new): string
{
    $count = substr_count($source, $old);

    if ($count < 1) {
        throw new RuntimeException($label . ' expected at least one match; found 0.');
    }

    ci_out('Patched: ' . $label . ' (' . $count . ' occurrence' . ($count === 1 ? '' : 's') . ')', 'ok');
    return str_replace($old, $new, $source);
}

function ci_load_json(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function ci_release_is_pending(array $release): bool
{
    $status = strtolower(trim((string)($release['status'] ?? '')));
    $public = strtolower(trim((string)($release['public_status'] ?? '')));

    return (
        !empty($release['manual_pending_review']) ||
        !empty($release['pending_review']) ||
        !empty($release['under_review']) ||
        $status === 'pending_review' ||
        $public === 'pending_review'
    );
}

function ci_pending_race_codes(string $historyPath): array
{
    $history = ci_load_json($historyPath);
    $pending = [];

    $releases = isset($history['releases']) && is_array($history['releases'])
        ? $history['releases']
        : [];

    foreach ($releases as $release) {
        if (!is_array($release) || !ci_release_is_pending($release)) continue;
        $raceCode = strtoupper(trim((string)($release['race_code'] ?? '')));
        if ($raceCode !== '') {
            $pending[$raceCode] = true;
        }
    }

    return $pending;
}

try {
    $snapshotHelperPath = $baseDir . '/race_results_snapshot_helper.php';
    $classifierPath = $baseDir . '/race_results_classify_revisions.php';
    $releaseHelperPath = $baseDir . '/weekly_standings_release_history_helper.php';

    // ---------------------------------------------------------------------
    // 1. Shared canonical snapshot helper
    // ---------------------------------------------------------------------
    $src = ci_read($snapshotHelperPath);

    if (
        strpos($src, 'VERSION: v1.00.01') === false ||
        strpos($src, 'BUILD TS: 20260312_001500000') === false
    ) {
        throw new RuntimeException(
            'race_results_snapshot_helper.php does not match expected GitHub version v1.00.01.'
        );
    }

    $src = ci_replace_once(
        'snapshot helper version/date/build',
        $src,
        " * VERSION: v1.00.01\n * LAST MODIFIED: 2026-03-12\n * BUILD TS: 20260312_001500000",
        " * VERSION: v1.00.02\n * LAST MODIFIED: 7/12/2026 1:15:52 pm\n * BUILD TS: 20260712_131552000"
    );

    $src = ci_replace_once(
        'snapshot helper changelog v1.00.02',
        $src,
        " * CHANGELOG:\n",
        " * CHANGELOG:\n"
        . " * v1.00.02 (7/12/2026 1:15:52 pm)\n"
        . " *   - NEW: Added strict canonical snapshot filename detection.\n"
        . " *   - NEW: Added shared canonical snapshot discovery/latest helpers.\n"
        . " *   - FIX: Derived files such as _lite.html and future _mrl.html are excluded.\n"
        . " *\n"
    );

    $canonicalFunctions = <<<'PHP'

if (!function_exists('rrs_is_canonical_snapshot_filename')) {
    /**
     * Canonical source snapshots use exactly:
     * snapshot_YYYYMMDD_HHMMSSmmm.html
     *
     * Derived views such as _lite.html and _mrl.html are intentionally excluded.
     */
    function rrs_is_canonical_snapshot_filename(string $pathOrFilename): bool
    {
        return preg_match(
            '/^snapshot_\d{8}_\d{9}\.html$/',
            basename($pathOrFilename)
        ) === 1;
    }
}

if (!function_exists('rrs_canonical_snapshot_files')) {
    function rrs_canonical_snapshot_files(string $raceFolder): array
    {
        $files = glob(rtrim($raceFolder, '/\\') . '/snapshot_*.html');

        if (!is_array($files) || empty($files)) {
            return [];
        }

        $canonical = [];

        foreach ($files as $file) {
            if (rrs_is_canonical_snapshot_filename((string)$file)) {
                $canonical[] = (string)$file;
            }
        }

        sort($canonical, SORT_STRING);
        return array_values($canonical);
    }
}

if (!function_exists('rrs_latest_canonical_snapshot')) {
    function rrs_latest_canonical_snapshot(string $raceFolder): string
    {
        $files = rrs_canonical_snapshot_files($raceFolder);
        return empty($files) ? '' : (string)$files[count($files) - 1];
    }
}

PHP;

    $src = ci_replace_once(
        'shared canonical snapshot functions',
        $src,
        "if (!function_exists('rrs_norm_text')) {",
        $canonicalFunctions . "if (!function_exists('rrs_norm_text')) {"
    );

    ci_write($snapshotHelperPath, $src);

    // ---------------------------------------------------------------------
    // 2. Revision classifier
    // ---------------------------------------------------------------------
    $src = ci_read($classifierPath);

    if (
        strpos($src, 'VERSION: v010') === false ||
        strpos($src, "const RRCR_VERSION = 'v010';") === false
    ) {
        throw new RuntimeException(
            'race_results_classify_revisions.php does not match expected GitHub version v010.'
        );
    }

    $src = ci_replace_once(
        'classifier version/date',
        $src,
        " * VERSION: v010\n * LAST MODIFIED: 6/20/2026 5:56:51 pm",
        " * VERSION: v011\n * LAST MODIFIED: 7/12/2026 1:15:52 pm"
    );

    $src = ci_replace_once(
        'classifier changelog v011',
        $src,
        " * CHANGELOG:\n *\n",
        " * CHANGELOG:\n *\n"
        . " * v011 (7/12/2026 1:15:52 pm)\n"
        . " * - FIX: Snapshot discovery now uses canonical source snapshots only.\n"
        . " * - FIX: Derived _lite.html / _mrl.html files no longer create false comparison pairs.\n"
        . " * - CHANGE: Pending Review now follows release-level metadata used by weekly_pending_review_admin.php.\n"
        . " * - CHANGE: under_review.flag remains diagnostic only and cannot create false Pending Review status.\n"
        . " *\n"
    );

    $src = ci_replace_once(
        'classifier constants v011',
        $src,
        "const RRCR_VERSION = 'v010';\nconst RRCR_SIGNATURE = 'RACE_RESULTS_CLASSIFY_REVISIONS v010';",
        "const RRCR_VERSION = 'v011';\nconst RRCR_SIGNATURE = 'RACE_RESULTS_CLASSIFY_REVISIONS v011';"
    );

    $oldCandidate = <<<'PHP'
        $snapshotFiles = glob($raceFolder . '/snapshot_*.html');
        if (!is_array($snapshotFiles) || count($snapshotFiles) < 2) continue;
PHP;

    $newCandidate = <<<'PHP'
        $snapshotFiles = rrs_canonical_snapshot_files($raceFolder);
        if (count($snapshotFiles) < 2) continue;
PHP;

    $src = ci_replace_once(
        'classifier candidate snapshot discovery',
        $src,
        $oldCandidate,
        $newCandidate
    );

    $oldList = <<<'PHP'
function rrcr_get_race_snapshots(string $raceFolder): array
{
    $files = glob(rtrim($raceFolder, '/\\') . '/snapshot_*.html');
    if (!is_array($files) || empty($files)) {
        return [];
    }
    sort($files, SORT_STRING);
    return array_values($files);
}
PHP;

    $newList = <<<'PHP'
function rrcr_get_race_snapshots(string $raceFolder): array
{
    return rrs_canonical_snapshot_files($raceFolder);
}
PHP;

    $src = ci_replace_once(
        'classifier canonical snapshot list',
        $src,
        $oldList,
        $newList
    );

    $pendingHelpers = <<<'PHP'

function rrcr_release_is_pending(array $release): bool
{
    $status = strtolower(trim((string)($release['status'] ?? '')));
    $public = strtolower(trim((string)($release['public_status'] ?? '')));

    return (
        !empty($release['manual_pending_review']) ||
        !empty($release['pending_review']) ||
        !empty($release['under_review']) ||
        $status === 'pending_review' ||
        $public === 'pending_review'
    );
}

function rrcr_release_pending_for_race(
    string $raceFolder,
    string $raceCode,
    string $raceYear
): array {
    $result = [
        'pending' => false,
        'release_id' => '',
        'reason' => '',
        'history_path' => '',
    ];

    $raceCode = strtoupper(trim($raceCode));
    $raceYear = trim($raceYear);

    if ($raceCode === '' || $raceYear === '') {
        return $result;
    }

    $yearFolder = dirname(rtrim($raceFolder, '/\\'));
    $historyPath = $yearFolder . '/_weekly_standings_release_history.json';
    $result['history_path'] = $historyPath;

    $history = rrcr_read_json_file($historyPath);
    $releases = isset($history['releases']) && is_array($history['releases'])
        ? $history['releases']
        : [];

    foreach ($releases as $release) {
        if (!is_array($release)) continue;
        if (strtoupper(trim((string)($release['race_code'] ?? ''))) !== $raceCode) continue;
        if (!rrcr_release_is_pending($release)) continue;

        $result['pending'] = true;
        $result['release_id'] = (string)(
            $release['release_id']
            ?? $release['generated_id']
            ?? $release['snapshot_id']
            ?? ''
        );
        $result['reason'] = (string)(
            $release['pending_review_reason']
            ?? $release['reason_public']
            ?? $release['reason']
            ?? ''
        );
        break;
    }

    return $result;
}

PHP;

    $src = ci_replace_once(
        'classifier release-level pending helpers',
        $src,
        "function rrcr_revision_status_for_race(string \$raceFolder): array\n{",
        $pendingHelpers
        . "function rrcr_revision_status_for_race(\n"
        . "    string \$raceFolder,\n"
        . "    string \$raceCode = '',\n"
        . "    string \$raceYear = ''\n"
        . "): array\n{"
    );

    $oldPending = <<<'PHP'
    $statusCode = (string)($meta['status'] ?? '');

    // Active pending-review state is controlled by under_review.flag.
    // Older revision_meta.json files can be useful history, but they must not
    // keep a race in Pending Review after the flag is removed.
    $pendingReview = $hasFlag || $statusCode === 'pending_review';

    $displayTag = (string)($meta['display_tag'] ?? '');
PHP;

    $newPending = <<<'PHP'
    $statusCode = (string)($meta['status'] ?? '');

    // v011: Active Pending Review is release-level metadata controlled by
    // weekly_pending_review_admin.php. under_review.flag is retained only as
    // a diagnostic/history signal and cannot create active Pending Review.
    $releasePending = rrcr_release_pending_for_race(
        $raceFolder,
        $raceCode,
        $raceYear
    );
    $pendingReview = !empty($releasePending['pending']);

    if ($pendingReview) {
        $statusCode = 'pending_review';
    } elseif ($statusCode === 'pending_review') {
        $statusCode = 'classified';
    }

    $displayTag = (string)($meta['display_tag'] ?? '');
PHP;

    $src = ci_replace_once(
        'classifier Pending Review source',
        $src,
        $oldPending,
        $newPending
    );

    $src = ci_replace_once(
        'classifier status return release metadata',
        $src,
        "        'under_review_flag_path' => \$hasFlag ? \$flagPath : '',\n",
        "        'under_review_flag_path' => \$hasFlag ? \$flagPath : '',\n"
        . "        'release_pending_review' => \$pendingReview,\n"
        . "        'pending_release_id' => (string)(\$releasePending['release_id'] ?? ''),\n"
        . "        'pending_release_reason' => (string)(\$releasePending['reason'] ?? ''),\n"
        . "        'release_history_path' => (string)(\$releasePending['history_path'] ?? ''),\n"
    );

    $src = ci_replace_all_required(
        'classifier status calls include race/year',
        $src,
        "rrcr_revision_status_for_race((string)\$raceInfo['raceFolder'])",
        "rrcr_revision_status_for_race(\n"
        . "            (string)\$raceInfo['raceFolder'],\n"
        . "            (string)(\$raceInfo['raceCode'] ?? ''),\n"
        . "            (string)(\$raceInfo['year'] ?? '')\n"
        . "        )"
    );

    ci_write($classifierPath, $src);

    // ---------------------------------------------------------------------
    // 3. Release-history helper
    // ---------------------------------------------------------------------
    $src = ci_read($releaseHelperPath);

    if (
        strpos($src, 'VERSION: v002') === false ||
        strpos($src, "'WEEKLY_STANDINGS_RELEASE_HISTORY v002'") === false
    ) {
        throw new RuntimeException(
            'weekly_standings_release_history_helper.php does not match expected GitHub version v002.'
        );
    }

    $src = ci_replace_once(
        'release helper version/date',
        $src,
        " * VERSION: v002\n * LAST MODIFIED: 6/25/2026 2:34:45 pm",
        " * VERSION: v003\n * LAST MODIFIED: 7/12/2026 1:15:52 pm"
    );

    $src = ci_replace_once(
        'release helper changelog v003',
        $src,
        " * CHANGELOG:\n",
        " * CHANGELOG:\n"
        . " * v003 (7/12/2026 1:15:52 pm)\n"
        . " *   - FIX: Release-history rebuilds now use canonical source snapshots only.\n"
        . " *   - FIX: Derived _lite.html / _mrl.html views cannot become releases or versions.\n"
        . " *   - CHANGE: Uses the shared snapshot helper for canonical discovery.\n"
        . " *\n"
    );

    $src = ci_replace_once(
        'release helper require snapshot helper',
        $src,
        "date_default_timezone_set('America/New_York');\n",
        "date_default_timezone_set('America/New_York');\n\n"
        . "require_once __DIR__ . '/race_results_snapshot_helper.php';\n"
    );

    $src = str_replace(
        "'WEEKLY_STANDINGS_RELEASE_HISTORY v002'",
        "'WEEKLY_STANDINGS_RELEASE_HISTORY v003'",
        $src
    );
    $src = str_replace(
        "'version' => 'v002'",
        "'version' => 'v003'",
        $src
    );

    $oldWsrel = <<<'PHP'
function wsrel_snapshot_files(string $raceFolder): array
{
    $files = glob(rtrim($raceFolder, '/\\') . '/snapshot_*.html');
    if (!is_array($files)) return [];
    sort($files, SORT_STRING);
    return array_values($files);
}
PHP;

    $newWsrel = <<<'PHP'
function wsrel_snapshot_files(string $raceFolder): array
{
    return rrs_canonical_snapshot_files($raceFolder);
}
PHP;

    $src = ci_replace_once(
        'release helper canonical snapshot list',
        $src,
        $oldWsrel,
        $newWsrel
    );

    ci_write($releaseHelperPath, $src);

    // ---------------------------------------------------------------------
    // 4. Stale under_review.flag cleanup
    // ---------------------------------------------------------------------
    $yearFolder = $baseDir . '/' . (string)$year;
    $historyPath = $yearFolder . '/_weekly_standings_release_history.json';
    $pendingRaceCodes = ci_pending_race_codes($historyPath);

    $raceFolders = glob($yearFolder . '/R*', GLOB_ONLYDIR);
    if (!is_array($raceFolders)) $raceFolders = [];

    $staleFlagCount = 0;

    foreach ($raceFolders as $raceFolder) {
        $folderName = basename($raceFolder);
        if (!preg_match('/^(R\d{2})_/', $folderName, $m)) continue;

        $raceCode = strtoupper($m[1]);
        $flagPath = $raceFolder . '/under_review.flag';

        if (!is_file($flagPath)) continue;
        if (isset($pendingRaceCodes[$raceCode])) {
            ci_out('Kept active flag for pending release: ' . $raceCode, 'info');
            continue;
        }

        $backupFlag = $flagPath . '.stale_backup_' . $backupStamp;
        if (@rename($flagPath, $backupFlag)) {
            $staleFlagCount++;
            ci_out(
                'Archived stale flag: ' . $raceCode . '/under_review.flag -> ' . basename($backupFlag),
                'ok'
            );
        } else {
            ci_out('Could not archive stale flag: ' . $flagPath, 'warn');
        }
    }

    ci_out('Stale under_review.flag files archived: ' . $staleFlagCount, 'ok');

    // ---------------------------------------------------------------------
    // 5. Back up/remove stale classifier artifacts before clean rebuild
    // ---------------------------------------------------------------------
    $artifactPaths = [
        $yearFolder . '/_race_results_classification_summary.json',
        $yearFolder . '/_race_results_classification_last_run.json',
        $yearFolder . '/_race_results_pair_classification_history.json',
    ];

    foreach ($raceFolders as $raceFolder) {
        $artifactPaths[] = $raceFolder . '/mrl_impact_pair_history.json';
    }

    $artifactBackupCount = 0;

    foreach ($artifactPaths as $artifactPath) {
        if (!is_file($artifactPath)) continue;

        $backupPath = $artifactPath . '.pre_canonical_' . $backupStamp;
        if (@copy($artifactPath, $backupPath)) {
            $artifactBackupCount++;
            @unlink($artifactPath);
        }
    }

    ci_out('Classification artifacts backed up for clean rebuild: ' . $artifactBackupCount, 'ok');

    // ---------------------------------------------------------------------
    // 6. Rebuild classification summary/history immediately
    // ---------------------------------------------------------------------
    if (!defined('RRCR_AUTO_RUN')) {
        define('RRCR_AUTO_RUN', false);
    }

    require_once $classifierPath;

    if (
        function_exists('rrcr_run') &&
        isset($dbo) &&
        $dbo instanceof PDO
    ) {
        $rebuild = rrcr_run([
            'year' => (string)$year,
            'race_code' => '',
            'verbose' => false,
            'write_artifacts' => true,
            'base_dir' => $baseDir,
        ], $dbo);

        $classified = is_array($rebuild)
            ? (int)($rebuild['classifiedCount'] ?? 0)
            : 0;

        ci_out('Classification rebuild completed. Classified races: ' . $classified, 'ok');
    } else {
        ci_out(
            'Automatic rebuild was not available. Open race_results_classify_revisions.php and click Regenerate Summary.',
            'warn'
        );
    }

    ci_out('INSTALLATION COMPLETE', 'ok');
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
    ci_out('INSTALLATION STOPPED: ' . $e->getMessage(), 'error');
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Canonical Snapshot Isolation Installer</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:20px;background:#f3f3f3;color:#111;font-size:14px}
h1{margin:0 0 8px;font-size:26px}
.meta{margin-bottom:14px;color:#444}
.panel{background:#fff;border:1px solid #aaa;border-radius:8px;padding:14px;max-width:1200px}
.row{padding:6px 8px;border-bottom:1px solid #ddd}
.row:last-child{border-bottom:0}
.ok{color:#087b2e;font-weight:700}
.warn{color:#a05b00;font-weight:700}
.error{color:#c00000;font-weight:700}
.info{color:#222}
code{font-family:Consolas,monospace}
</style>
</head>
<body>
<h1>MRL Canonical Snapshot Isolation Installer</h1>
<div class="meta">
Version <?= htmlspecialchars(INSTALLER_VERSION, ENT_QUOTES, 'UTF-8') ?> ·
Generated <?= htmlspecialchars(INSTALLER_TIMESTAMP, ENT_QUOTES, 'UTF-8') ?> America/New_York
</div>

<div class="panel">
<?php foreach ($report as $row): ?>
<div class="row <?= htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8') ?>">
<?= htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endforeach; ?>
</div>

<?php if (empty($errors)): ?>
<p><strong>Next check:</strong> Open the Revision Summary and confirm that only real
<code>snapshot_YYYYMMDD_HHMMSSmmm.html</code> files appear in comparison pairs.</p>
<p>After verification, delete this installer.</p>
<?php else: ?>
<p><strong>No further action was attempted after the reported error.</strong>
Backups created before the error remain available.</p>
<?php endif; ?>
</body>
</html>
