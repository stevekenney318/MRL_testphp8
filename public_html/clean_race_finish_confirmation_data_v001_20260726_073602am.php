<?php
declare(strict_types=1);

/**
 * clean_race_finish_confirmation_data.php
 *
 * VERSION: v001
 * LAST MODIFIED: 7/26/2026 7:36:02 am
 *
 * DESCRIPTION:
 * One-time cleanup utility for the MRL Race Finish Confirmation Monitor.
 *
 * PURPOSE:
 * - Deletes runtime observation data only.
 * - Preserves configuration, documentation, monitor code, dashboard code,
 *   scheduler configuration, and cron setup.
 *
 * USAGE:
 * 1. Upload this file to /race_results/.
 * 2. Open it in a browser.
 * 3. Click "Clean Monitor Data".
 * 4. Review the result.
 * 5. Delete this cleanup file from the server.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const CLEANER_VERSION = 'v001';

$baseDir = __DIR__;
$dataDir = $baseDir . '/_race_finish_confirmation';

header('Content-Type: text/html; charset=UTF-8');

function crf_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function crf_delete_file(string $path, array &$messages): void
{
    if (!is_file($path)) {
        $messages[] = 'NOT PRESENT: ' . basename($path);
        return;
    }

    if (@unlink($path)) {
        $messages[] = 'DELETED: ' . basename($path);
    } else {
        $messages[] = 'ERROR: Could not delete ' . basename($path);
    }
}

function crf_clear_directory(string $dir, array &$messages): void
{
    if (!is_dir($dir)) {
        if (@mkdir($dir, 0755, true) || is_dir($dir)) {
            $messages[] = 'CREATED EMPTY FOLDER: ' . basename($dir);
        } else {
            $messages[] = 'ERROR: Could not create folder ' . basename($dir);
        }
        return;
    }

    $deletedCount = 0;
    $keptCount = 0;

    $items = scandir($dir);
    if (!is_array($items)) {
        $messages[] = 'ERROR: Could not read folder ' . basename($dir);
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if ($item === '.gitkeep') {
            $keptCount++;
            continue;
        }

        if (is_file($path) || is_link($path)) {
            if (@unlink($path)) {
                $deletedCount++;
            }
            continue;
        }

        if (is_dir($path)) {
            crf_remove_tree($path, $deletedCount);
        }
    }

    $messages[] = 'CLEARED FOLDER: ' . basename($dir)
        . ' (' . $deletedCount . ' item' . ($deletedCount === 1 ? '' : 's') . ' deleted'
        . ($keptCount > 0 ? ', .gitkeep preserved' : '') . ')';
}

function crf_remove_tree(string $dir, int &$deletedCount): void
{
    $items = scandir($dir);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                crf_remove_tree($path, $deletedCount);
            } elseif (@unlink($path)) {
                $deletedCount++;
            }
        }
    }

    if (@rmdir($dir)) {
        $deletedCount++;
    }
}

$messages = [];
$didRun = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clean_data'])) {
    $didRun = true;

    if (!is_dir($dataDir)) {
        $messages[] = 'ERROR: Monitor data folder was not found: ' . $dataDir;
    } else {
        crf_delete_file($dataDir . '/latest.json', $messages);
        crf_delete_file($dataDir . '/state.json', $messages);
        crf_delete_file($dataDir . '/monitor.log', $messages);
        crf_delete_file($dataDir . '/monitor.lock', $messages);

        crf_clear_directory($dataDir . '/history', $messages);
        crf_clear_directory($dataDir . '/raw', $messages);

        $messages[] = 'DONE: Runtime observation data cleanup completed.';
        $messages[] = 'PRESERVED: config.json, README.md, monitor PHP, dashboard PHP, schedule.json, and cron setup.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clean Race Finish Confirmation Data</title>
<style>
body {
    margin: 0;
    background: #11151b;
    color: #edf2f7;
    font-family: Arial, Helvetica, sans-serif;
}
.wrap {
    max-width: 850px;
    margin: 40px auto;
    padding: 24px;
}
.panel {
    background: #1b222c;
    border: 1px solid #394656;
    border-radius: 12px;
    padding: 22px;
}
h1 {
    margin-top: 0;
}
.note {
    background: #202a36;
    border-left: 4px solid #58a6ff;
    padding: 12px 14px;
    margin: 16px 0;
}
button {
    background: #1976d2;
    color: white;
    border: 0;
    border-radius: 8px;
    padding: 12px 18px;
    font-size: 16px;
    cursor: pointer;
}
button:hover {
    background: #2585e5;
}
pre {
    white-space: pre-wrap;
    background: #0d1117;
    border: 1px solid #394656;
    border-radius: 8px;
    padding: 15px;
    line-height: 1.45;
}
.small {
    color: #aab4c0;
    font-size: 13px;
}
.success {
    color: #56d364;
}
</style>
</head>
<body>
<div class="wrap">
<div class="panel">
<h1>Clean Race Finish Confirmation Data</h1>

<p>This removes only the saved runtime observations from:</p>

<pre><?= crf_h($dataDir) ?></pre>

<div class="note">
<strong>It will preserve:</strong><br>
config.json, README.md, the monitor, the dashboard, schedule.json, and the existing cron setup.
</div>

<?php if (!$didRun): ?>
<form method="post">
    <button type="submit" name="clean_data" value="1">Clean Monitor Data</button>
</form>
<?php else: ?>
<h2 class="success">Cleanup Result</h2>
<pre><?= crf_h(implode("\n", $messages)) ?></pre>
<p><strong>You may now delete this cleanup file from the server.</strong></p>
<?php endif; ?>

<p class="small">Version <?= crf_h(CLEANER_VERSION) ?> · Generated 7/26/2026 7:36:02 am America/New_York</p>
</div>
</div>
</body>
</html>
