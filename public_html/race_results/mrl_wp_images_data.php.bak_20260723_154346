<?php
/*
MRL WP IMAGES DATA
VERSION: v004
LAST MODIFIED: 7/23/2026 7:56:44 am

CHANGELOG
v004 FIX (7/23/2026 7:56:44 am)
- FIX: Restored v003 race-data fallback without PHP function-name collisions.
- FIX: Supports winning-driver card status and JPEG uploads.
- PHP 7.3 compatible.
*/
declare(strict_types=1);
date_default_timezone_set('America/New_York');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function mrlwp4_out(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
function mrlwp4_safe_name(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9]+/', '_', trim($name));
    return trim((string)$name, '_') ?: 'Driver';
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$year = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));
$driver = trim((string)($_GET['driver'] ?? $_POST['driver'] ?? ''));

if ($action === 'card_status') {
    if ($driver === '' || $year < 2000 || $year > 2100) {
        mrlwp4_out(['ok' => false, 'error' => 'Invalid driver or year.'], 400);
    }
    $filename = mrlwp4_safe_name($driver) . '_' . $year . '.jpg';
    $relative = $year . '/images/drivers/' . $filename;
    mrlwp4_out([
        'ok' => true,
        'exists' => is_file(__DIR__ . DIRECTORY_SEPARATOR . $relative),
        'filename' => $filename,
        'relative_path' => $relative,
        'url' => '/race_results/' . $relative
    ]);
}

if ($action === 'upload_driver_card') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        mrlwp4_out(['ok' => false, 'error' => 'POST required.'], 405);
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        mrlwp4_out(['ok' => false, 'error' => 'Invalid JSON.'], 400);
    }

    $year = (int)($input['year'] ?? 0);
    $driver = trim((string)($input['driver'] ?? ''));
    $image = (string)($input['image'] ?? '');

    if ($year < 2000 || $year > 2100 || $driver === '' ||
        !preg_match('#^data:image/jpeg;base64,#', $image)) {
        mrlwp4_out(['ok' => false, 'error' => 'Invalid upload.'], 400);
    }

    $raw = base64_decode(substr($image, strpos($image, ',') + 1), true);
    if ($raw === false || strlen($raw) < 1000) {
        mrlwp4_out(['ok' => false, 'error' => 'JPEG decoding failed.'], 400);
    }

    $directory = __DIR__ . DIRECTORY_SEPARATOR . $year .
        DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'drivers';

    if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
        mrlwp4_out(['ok' => false, 'error' => 'Could not create the driver-card folder.'], 500);
    }

    $filename = mrlwp4_safe_name($driver) . '_' . $year . '.jpg';
    $path = $directory . DIRECTORY_SEPARATOR . $filename;

    if (is_file($path)) {
        @copy($path, $path . '.bak_' . date('Ymd_His'));
    }
    if (@file_put_contents($path, $raw) === false) {
        mrlwp4_out(['ok' => false, 'error' => 'Could not save the JPEG.'], 500);
    }

    $relative = $year . '/images/drivers/' . $filename;
    mrlwp4_out([
        'ok' => true,
        'filename' => $filename,
        'relative_path' => $relative,
        'url' => '/race_results/' . $relative,
        'bytes' => strlen($raw)
    ]);
}

/* Everything else remains handled by the preserved working v003 endpoint. */
$legacy = __DIR__ . DIRECTORY_SEPARATOR . 'mrl_wp_images_data_v003_legacy.php';
if (!is_file($legacy)) {
    mrlwp4_out([
        'ok' => false,
        'error' => 'The preserved v003 race-data endpoint is missing.'
    ], 500);
}
require $legacy;
