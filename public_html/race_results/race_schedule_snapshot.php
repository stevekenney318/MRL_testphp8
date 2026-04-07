<?php
declare(strict_types=1);

/**
 * race_schedule_snapshot.php
 *
 * VERSION: v014
 * LAST MODIFIED: 3/31/2026 9:36:00 pm
 *
 * DESCRIPTION:
 * ESPN schedule snapshot helper tuned to the real flattened page structure.
 * Writes a canonical MRL schedule JSON file with normalized race names that
 * match existing MRL naming conventions already used elsewhere in the project.
 *
 * CHANGELOG:
 *
 * v014 (3/31/2026)
 * - Added stronger track cleanup to stop footer bleed-through after valid track suffixes.
 * - Added explicit World Wide Technology Raceway special-case handling.
 * - Added explicit Homestead-Miami cleanup handling.
 * - Preserved canonical output file: /race_results/{year}/_schedule.json
 *
 * v013 (3/31/2026)
 * - Added run timestamp as the first output line for easier refresh/debug tracking.
 * - Added explicit COTA special-case handling to prevent doubled track text.
 * - Keeps canonical output file: /race_results/{year}/_schedule.json
 *
 * v012 (3/31/2026)
 * - Added canonical JSON output file: /race_results/{year}/_schedule.json
 * - Added segment calculation (S1=1-8, S2=9-17, S3=18-26, S4=27-36)
 * - Added MRL race name normalization so schedule names match existing MRL usage
 * - Fixed COTA special-case handling
 * - Keeps parsed/raw debug files for verification while schedule structure stabilizes
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';

// disableCaching() defined in functions_mrl.php
disableCaching();

// visual id of a sandbox file - SK & background
require_once $_SERVER['DOCUMENT_ROOT'] . '/sandbox.html';

date_default_timezone_set('America/New_York');

echo "<pre>";
echo 'RUN TS: ' . date('Y-m-d H:i:s') . "\n\n";

function rsOut($label, $value = '')
{
    echo $label;
    if ($value !== '') {
        echo ' ' . $value;
    }
    echo "\n";
}

function rsStop($message)
{
    echo "\nERROR: " . $message . "\n";
    exit;
}

function rsFetchUrl($url)
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'body' => '', 'http_code' => 0, 'error' => 'cURL is not available.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MRL Schedule Snapshot/1.0)',
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array(
        'ok' => ($errno === 0 && $httpCode >= 200 && $httpCode < 300 && is_string($body) && $body !== ''),
        'body' => is_string($body) ? $body : '',
        'http_code' => $httpCode,
        'error' => $errno !== 0 ? $error : '',
    );
}

function rsNormalizeLines($html)
{
    $html = html_entity_decode((string)$html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
    $text = strip_tags((string)$html);
    $text = str_replace("\xc2\xa0", ' ', (string)$text);
    $text = preg_replace("/\r\n?|\n/", "\n", (string)$text);

    $rawLines = explode("\n", (string)$text);
    $lines = array();

    foreach ($rawLines as $line) {
        $line = trim((string)preg_replace('/\s+/', ' ', (string)$line));
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return $lines;
}

function rsShouldSkipEvent($name)
{
    $skipPhrases = array('Clash', 'Duel #1', 'Duel #2', 'All-Star', 'All Star');

    foreach ($skipPhrases as $phrase) {
        if (stripos((string)$name, $phrase) !== false) {
            return true;
        }
    }

    return false;
}

function rsCleanEventText($text)
{
    $text = trim((string)$text);

    $patterns = array(
        '/(FOX|FS1|Prime Video|TNT|USA Net|NBC)(.*)$/i',
        '/(Starting Grid|Race Results|Tickets)(.*)$/i',
    );

    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, '', $text);
        $text = trim((string)$text);
    }

    $text = preg_replace('/\*\*Moved from .*$/i', '', $text);
    $text = trim((string)$text);

    return $text;
}

function rsFinalizeTrackName($track)
{
    $track = trim((string)$track);
    $track = preg_replace('/\s+/', ' ', $track);

    // Explicit known cleanup first.
    if (stripos($track, 'World Wide Technology Raceway') !== false) {
        return 'World Wide Technology Raceway';
    }

    if (stripos($track, 'Homestead-Miami Speedway') !== false) {
        return 'Homestead-Miami Speedway';
    }

    // Hard stop at valid track suffixes to prevent ESPN footer bleed-through.
    if (preg_match('/^.*?\b(Superspeedway|Speedway|Raceway|International|Coronado)\b/i', $track, $m)) {
        return trim((string)$m[0]);
    }

    return $track;
}

function rsSplitRaceAndTrack($eventText)
{
    $eventText = trim((string)$eventText);

    // Explicit COTA fix for flattened/doubled ESPN text variants.
    if (stripos($eventText, 'Circuit of the Americas') !== false || stripos($eventText, 'Circuit Of The Americas') !== false) {
        return array('COTA', 'Circuit of the Americas');
    }

    // Explicit World Wide Tech fix.
    if (stripos($eventText, 'World Wide Technology Raceway') !== false) {
        return array('World Wide Tech', 'World Wide Technology Raceway');
    }

    // Explicit Homestead / Miami fix.
    if (stripos($eventText, 'Homestead-Miami Speedway') !== false) {
        return array('Miami', 'Homestead-Miami Speedway');
    }

    $knownTracks = array(
        'Bowman Gray Stadium',
        'Daytona International Speedway',
        'Echopark Speedway',
        'Phoenix Raceway',
        'Las Vegas Motor Speedway',
        'Darlington Raceway',
        'Martinsville Speedway',
        'Bristol Motor Speedway',
        'Kansas Speedway',
        'Talladega Superspeedway',
        'Texas Motor Speedway',
        'Watkins Glen International',
        'Dover Motor Speedway',
        'Charlotte Motor Speedway',
        'Nashville Superspeedway',
        'Michigan International Speedway',
        'Pocono Raceway',
        'Naval Base Coronado',
        'Sonoma Raceway',
        'Chicagoland Speedway',
        'North Wilkesboro Speedway',
        'Indianapolis Motor Speedway',
        'Iowa Speedway',
        'Richmond Raceway',
        'New Hampshire Motor Speedway',
        'World Wide Technology Raceway',
        'Homestead-Miami Speedway',
        'MiamiHomestead-Miami Speedway'
    );

    foreach ($knownTracks as $track) {
        $pos = stripos($eventText, $track);
        if ($pos !== false) {
            $raceName = trim(substr($eventText, 0, $pos));
            $trackName = trim(substr($eventText, $pos));

            if ($trackName === 'MiamiHomestead-Miami Speedway') {
                $raceName = trim(str_replace('Miami', '', $raceName));
                $trackName = 'Homestead-Miami Speedway';
            }

            return array($raceName, rsFinalizeTrackName($trackName));
        }
    }

    return array($eventText, '');
}

function rsNormalizeTrackName($track)
{
    $track = rsFinalizeTrackName($track);

    $map = array(
        'Circuit Of The Americas' => 'Circuit of the Americas',
    );

    return isset($map[$track]) ? $map[$track] : $track;
}

function rsNormalizeMrlRaceName($raceName, $track, $officialRaceNumber)
{
    $raceName = trim((string)$raceName);
    $track = trim((string)$track);
    $trackLower = strtolower($track);

    if ($raceName === 'COTA' || $trackLower === 'circuit of the americas') {
        return 'COTA';
    }

    if ($raceName === 'World Wide Tech' || $trackLower === 'world wide technology raceway') {
        return 'World Wide Tech';
    }

    if ($raceName === 'Miami' || $trackLower === 'homestead-miami speedway') {
        return 'Miami';
    }

    if ($trackLower === 'chicagoland speedway') {
        return 'Chicago';
    }

    $trackMap = array(
        'daytona international speedway' => 'Daytona',
        'echopark speedway' => 'Atlanta',
        'phoenix raceway' => 'Phoenix',
        'las vegas motor speedway' => 'Las Vegas',
        'darlington raceway' => 'Darlington',
        'martinsville speedway' => 'Martinsville',
        'bristol motor speedway' => 'Bristol',
        'kansas speedway' => 'Kansas',
        'talladega superspeedway' => 'Talladega',
        'texas motor speedway' => 'Texas',
        'watkins glen international' => 'Watkins Glen',
        'charlotte motor speedway' => 'Charlotte',
        'nashville superspeedway' => 'Nashville',
        'michigan international speedway' => 'Michigan',
        'pocono raceway' => 'Pocono',
        'naval base coronado' => 'San Diego',
        'sonoma raceway' => 'Sonoma',
        'north wilkesboro speedway' => 'North Wilkesboro',
        'indianapolis motor speedway' => 'Indianapolis',
        'iowa speedway' => 'Iowa',
        'richmond raceway' => 'Richmond',
        'new hampshire motor speedway' => 'New Hampshire',
        'homestead-miami speedway' => 'Miami',
    );

    if (isset($trackMap[$trackLower])) {
        return $trackMap[$trackLower];
    }

    $raceName = preg_replace('/^NASCAR Cup Series at\s+/i', '', $raceName);
    $raceName = preg_replace('/^NASCAR Cup Series Race at\s+/i', '', $raceName);
    $raceName = trim((string)$raceName);

    if ($raceName !== '') {
        return $raceName;
    }

    return 'Race ' . (string)$officialRaceNumber;
}

function rsGetSegmentForRaceNumber($raceNumber)
{
    $raceNumber = (int)$raceNumber;

    if ($raceNumber >= 1 && $raceNumber <= 8) {
        return 'S1';
    }

    if ($raceNumber >= 9 && $raceNumber <= 17) {
        return 'S2';
    }

    if ($raceNumber >= 18 && $raceNumber <= 26) {
        return 'S3';
    }

    if ($raceNumber >= 27 && $raceNumber <= 36) {
        return 'S4';
    }

    return '';
}

function rsParseFromBlob($blob, $year)
{
    $events = array();

    $pattern = '/((Mon|Tue|Wed|Thu|Fri|Sat|Sun),\s+[A-Z][a-z]{2}\s+\d{1,2})(\d{1,2}:\d{2}\s+[AP]M\s+ET)(.*?)(?=(Mon|Tue|Wed|Thu|Fri|Sat|Sun),\s+[A-Z][a-z]{2}\s+\d{1,2}\d{1,2}:\d{2}\s+[AP]M\s+ET|$)/s';
    preg_match_all($pattern, (string)$blob, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $dateLine = trim((string)$m[1]);
        $timeLine = trim((string)$m[3]);
        $eventRaw = trim((string)$m[4]);

        if ($dateLine === '' || $timeLine === '' || $eventRaw === '') {
            continue;
        }

        $dt = DateTime::createFromFormat(
            'D, M j Y g:i A',
            $dateLine . ' ' . $year . ' ' . strtoupper(str_replace(' ET', '', $timeLine)),
            new DateTimeZone('America/New_York')
        );

        if (!($dt instanceof DateTime)) {
            continue;
        }

        $eventClean = rsCleanEventText($eventRaw);
        list($raceNameRaw, $trackNameRaw) = rsSplitRaceAndTrack($eventClean);

        $events[] = array(
            'espn_date_line'       => $dateLine,
            'espn_time_line'       => $timeLine,
            'event_text_raw'       => $eventRaw,
            'race_name_raw'        => $raceNameRaw,
            'track_raw'            => $trackNameRaw,
            'race_datetime_local'  => $dt->format('Y-m-d H:i:s'),
            'timezone'             => 'America/New_York',
            'is_points_race'       => !rsShouldSkipEvent($raceNameRaw),
            'official_race_number' => null,
            'segment'              => '',
            'mrl_race_name'        => '',
            'track'                => '',
        );
    }

    $officialRaceNumber = 0;
    foreach ($events as $idx => $event) {
        if (!empty($event['is_points_race'])) {
            $officialRaceNumber++;

            $track = rsNormalizeTrackName($event['track_raw']);
            $mrlRaceName = rsNormalizeMrlRaceName($event['race_name_raw'], $track, $officialRaceNumber);

            $events[$idx]['official_race_number'] = $officialRaceNumber;
            $events[$idx]['segment'] = rsGetSegmentForRaceNumber($officialRaceNumber);
            $events[$idx]['track'] = $track;
            $events[$idx]['mrl_race_name'] = $mrlRaceName;
        } else {
            $events[$idx]['track'] = rsNormalizeTrackName($event['track_raw']);
        }
    }

    return $events;
}

$year = isset($_GET['year']) && ctype_digit((string)$_GET['year'])
    ? (int)$_GET['year']
    : (isset($raceYear) ? (int)$raceYear : 0);

rsOut('FILE:', basename(__FILE__));
rsOut('VERSION:', 'v014');
rsOut('ACTIVE YEAR:', (string)$year);

if ($year <= 0) {
    rsStop('Could not determine active year.');
}

$url = 'https://www.espn.com/racing/schedule';
rsOut('FETCH URL:', $url);

$fetch = rsFetchUrl($url);
rsOut('HTTP CODE:', (string)$fetch['http_code']);

if (!$fetch['ok']) {
    rsStop('Fetch failed: ' . $fetch['error']);
}

$html = $fetch['body'];
rsOut('FETCHED BYTES:', (string)strlen($html));

$outputDir = __DIR__ . '/' . $year;
if (!is_dir($outputDir)) {
    if (!mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        rsStop('Could not create output directory: ' . $outputDir);
    }
}

$rawFile = $outputDir . '/_schedule_snapshot_raw.html';
file_put_contents($rawFile, $html);
rsOut('RAW FILE:', $rawFile);

$lines = rsNormalizeLines($html);
rsOut('NORMALIZED LINES:', (string)count($lines));

$linesFile = $outputDir . '/_schedule_snapshot_lines.txt';
$linesText = '';
foreach ($lines as $idx => $line) {
    $linesText .= str_pad((string)$idx, 4, ' ', STR_PAD_LEFT) . ': ' . $line . "\n";
}
file_put_contents($linesFile, $linesText);
rsOut('LINES FILE:', $linesFile);

$blob = implode("\n", $lines);
$events = rsParseFromBlob($blob, $year);
$pointsRaceCount = 0;

foreach ($events as $event) {
    if (!empty($event['is_points_race'])) {
        $pointsRaceCount++;
    }
}

rsOut('EVENTS FOUND:', (string)count($events));
rsOut('POINTS RACES:', (string)$pointsRaceCount);

if (count($events) === 0) {
    rsStop('No schedule events were parsed from ESPN.');
}

$parsedDebugFile = $outputDir . '/_schedule_snapshot_parsed.json';
$canonicalFile = $outputDir . '/_schedule.json';

$debugPayload = array(
    'source'            => 'ESPN',
    'source_url'        => $url,
    'season_year'       => $year,
    'fetched_at_local'  => date('Y-m-d H:i:s'),
    'timezone'          => 'America/New_York',
    'points_race_count' => $pointsRaceCount,
    'events'            => $events,
);

$canonicalRaces = array();
foreach ($events as $event) {
    if (empty($event['is_points_race'])) {
        continue;
    }

    $canonicalRaces[] = array(
        'race_number'        => (int)$event['official_race_number'],
        'segment'            => $event['segment'],
        'date'               => substr($event['race_datetime_local'], 0, 10),
        'time_et'            => substr($event['race_datetime_local'], 11, 8),
        'datetime_et'        => $event['race_datetime_local'],
        'mrl_race_name'      => $event['mrl_race_name'],
        'track_name'         => $event['track'],
        'espn_race_name_raw' => $event['race_name_raw'],
        'espn_date_line'     => $event['espn_date_line'],
        'espn_time_line'     => $event['espn_time_line'],
    );
}

$canonicalPayload = array(
    'year'               => $year,
    'source'             => 'ESPN',
    'source_url'         => $url,
    'fetched_at_local'   => date('Y-m-d H:i:s'),
    'timezone'           => 'America/New_York',
    'points_race_count'  => $pointsRaceCount,
    'races'              => $canonicalRaces,
);

$debugJson = json_encode($debugPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$canonicalJson = json_encode($canonicalPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($debugJson === false || $canonicalJson === false) {
    rsStop('json_encode failed.');
}

if (file_put_contents($parsedDebugFile, $debugJson) === false) {
    rsStop('Could not write parsed snapshot debug file: ' . $parsedDebugFile);
}

if (file_put_contents($canonicalFile, $canonicalJson) === false) {
    rsStop('Could not write canonical schedule file: ' . $canonicalFile);
}

rsOut('PARSED DEBUG FILE:', $parsedDebugFile);
rsOut('CANONICAL FILE:', $canonicalFile);

echo "\nFIRST 20 POINTS RACES\n";
echo "------------------------------------------------------------\n";
$max = min(20, count($canonicalRaces));
for ($i = 0; $i < $max; $i++) {
    $race = $canonicalRaces[$i];
    echo str_pad((string)$i, 3, ' ', STR_PAD_LEFT) . ': ';
    echo 'RACE# ' . $race['race_number'];
    echo ' | ' . $race['segment'];
    echo ' | ' . $race['date'];
    echo ' ' . $race['time_et'];
    echo ' | ' . $race['mrl_race_name'];
    echo ' | ' . $race['track_name'];
    echo "\n";
}

echo "\nDONE\n";
