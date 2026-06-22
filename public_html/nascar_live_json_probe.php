<?php
/**
 * nascar_live_json_probe.php
 * Tests whether Hostinger/PHP can fetch NASCAR's direct live-feed JSON endpoint.
 */

date_default_timezone_set('America/New_York');

$url = 'https://cf.nascar.com/cacher/live/series_1/5613/live-feed.json?_=' . time();

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
    CURLOPT_HTTPHEADER => [
        'Accept: application/json,text/plain,*/*',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Referer: https://www.nascar.com/live-results/nascar-cup-series/anduril-250/',
    ],
]);

$body = curl_exec($ch);
$info = curl_getinfo($ch);
$error = curl_error($ch);

curl_close($ch);

header('Content-Type: text/plain; charset=utf-8');

echo "NASCAR live JSON probe\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n";
echo "URL: {$url}\n";
echo "HTTP: " . ($info['http_code'] ?? 0) . "\n";
echo "Fetch OK: " . (($body !== false && ($info['http_code'] ?? 0) === 200) ? "YES" : "NO") . "\n";

if ($body === false || ($info['http_code'] ?? 0) !== 200) {
    echo "Error: {$error}\n";
    exit;
}

$data = json_decode($body, true);

if (!is_array($data)) {
    echo "JSON decode: FAILED\n";
    echo "First 500 chars:\n";
    echo substr($body, 0, 500);
    exit;
}

echo "JSON decode: OK\n\n";

$fields = [
    'run_name',
    'track_name',
    'track_length',
    'lap_number',
    'laps_in_race',
    'laps_to_go',
    'elapsed_time',
    'flag_state',
    'number_of_caution_laps',
    'number_of_lead_changes',
    'number_of_leaders',
];

foreach ($fields as $field) {
    $value = $data[$field] ?? '(missing)';
    echo "{$field}: {$value}\n";
}

echo "\nStage:\n";
if (isset($data['stage']) && is_array($data['stage'])) {
    foreach ($data['stage'] as $key => $value) {
        echo "stage.{$key}: {$value}\n";
    }
} else {
    echo "(missing)\n";
}

echo "\nVehicles count: ";
echo isset($data['vehicles']) && is_array($data['vehicles']) ? count($data['vehicles']) : 0;
echo "\n";
