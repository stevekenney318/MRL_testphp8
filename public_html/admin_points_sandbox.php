<?php
declare(strict_types=1);

/*
    filename: admin_points_sandbox.php
    purpose : Admin "Points Sandbox" (NO DB writes)
             - URL-first: fetch ESPN results page and parse points table
             - Fallback: paste table HTML/text if fetch/parse fails
             - Parse Points + Penalty (when ESPN provides it)
             - Live-compare parsed ESPN driver names vs DB table `drivers` (exact match)
             - Simulate INSERT SQL for missing driver names (NO execution)
             - Find Latest Race by year (uses ESPN year results page; selects LAST race link)
    php     : 7.3+
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

$isAdmin = isAdmin($_SESSION['userSession'] ?? null);

$adminStatusLine = $isAdmin
    ? '<div class="admin-status admin-yes">You are authorized to view/use this page</div>'
    : '<div class="admin-status admin-no">You are NOT authorized to view/use this page</div>';

if (!$isAdmin) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Not Authorized</title></head><body>';
    echo $adminStatusLine;
    echo '</body></html>';
    exit;
}

/* ----------------------- helpers ----------------------- */

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function looksLikeEspnRaceResultsUrl(string $url): bool {
    $url = trim($url);
    if ($url === '') return false;

    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || empty($parts['path'])) return false;

    $host = strtolower($parts['host']);
    if (strpos($host, 'espn.com') === false) return false;

    $path = strtolower($parts['path']);
    if (strpos($path, '/racing/raceresults') === false) return false;

    return (strpos(strtolower($url), 'raceid') !== false);
}

function fetchUrl(string $url, int $timeoutSeconds = 12): array {
    // Returns: [ok(bool), status(int), body(string), error(string)]
    $url = trim($url);

    if ($url === '') {
        return [false, 0, '', 'URL is blank.'];
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    $ch = curl_init();
    if ($ch === false) {
        return [false, 0, '', 'cURL init failed.'];
    }

    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 6,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '', // allow gzip/deflate
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $body === null) {
        return [false, $code, '', $err ?: 'Unknown fetch error.'];
    }

    if ($code >= 400 || $code === 0) {
        return [false, $code, (string)$body, $err ?: ("HTTP error " . $code)];
    }

    return [true, $code, (string)$body, ''];
}

function sqlQuote(string $s): string {
    return "'" . str_replace("'", "''", $s) . "'";
}

function dbFetchDriverNames(): array {
    // returns associative set: [driverName => true]
    global $dbconnect;

    $set = [];
    if (!isset($dbconnect) || !$dbconnect) {
        return $set;
    }

    $sql = "SELECT driverName FROM drivers";
    $res = mysqli_query($dbconnect, $sql);
    if ($res === false) {
        return $set;
    }

    while ($row = mysqli_fetch_assoc($res)) {
        if (!isset($row['driverName'])) continue;
        $name = (string)$row['driverName'];
        $set[$name] = true;
    }

    mysqli_free_result($res);
    return $set;
}

/**
 * ESPN year results page -> extract LAST race results URL.
 * This follows the behavior you observed (latest race appears last).
 */
function fetchLatestEspnRaceByYear(int $year): array {
    // Returns: [ok(bool), url(string), error(string), debug(array)]
    $debug = [
        'year' => $year,
        'resultsPage' => '',
        'httpStatus' => null,
        'htmlBytes' => 0,
        'matchCount' => 0,
        'lastHref' => '',
        'finalUrl' => '',
    ];

    if ($year < 2000 || $year > 2100) {
        return [false, '', 'Year looks invalid.', $debug];
    }

    $resultsUrl = "https://www.espn.com/racing/results/_/year/" . $year;
    $debug['resultsPage'] = $resultsUrl;

    [$ok, $status, $html, $err] = fetchUrl($resultsUrl, 12);
    $debug['httpStatus'] = $status;
    $debug['htmlBytes'] = strlen((string)$html);

    if (!$ok) {
        return [false, '', 'Fetch failed for ESPN year results page' . ($status ? " (HTTP $status)" : '') . ': ' . ($err ?: 'Unknown error'), $debug];
    }

    // Pull all race-results hrefs that include /raceId/<digits>
    preg_match_all(
        '~href=[\'"](?P<href>/racing/raceresults[^\'"]*?/raceId/\d+[^\'"]*)[\'"]~i',
        (string)$html,
        $m
    );

    $hrefs = [];
    if (!empty($m['href'])) {
        $hrefs = $m['href'];
    } else {
        preg_match_all('~(/racing/raceresults[^"\s>]*?/raceId/\d+)~i', (string)$html, $m2);
        if (!empty($m2[1])) {
            $hrefs = $m2[1];
        }
    }

    $debug['matchCount'] = count($hrefs);

    if (empty($hrefs)) {
        return [false, '', 'Could not find any race results links on that ESPN year page.', $debug];
    }

    $lastHref = end($hrefs);
    if (!is_string($lastHref) || $lastHref === '') {
        return [false, '', 'Found race links, but could not read the last one.', $debug];
    }

    $debug['lastHref'] = $lastHref;

    // If series is present, rebuild canonical URL; otherwise prefix espn.com
    $final = '';
    if (preg_match('~/series/([a-z0-9_-]+)/raceId/(\d+)~i', $lastHref, $mm)) {
        $series = $mm[1];
        $raceId = $mm[2];
        $final = "https://www.espn.com/racing/raceresults/_/series/{$series}/raceId/{$raceId}";
    } else {
        $final = "https://www.espn.com" . $lastHref;
    }

    $debug['finalUrl'] = $final;

    return [true, $final, '', $debug];
}
function parseEspnRaceResultsHtml(string $html): array {
    // Returns:
    // [ok(bool), raceTitle(string), rows(array of [driver=>string, pts=>int, penalty=>int|null]), error(string), debug(array)]
    $debug = [
        'h1' => '',
        'tableCount' => 0,
        'rowCount' => 0,
        'driverIdx' => null,
        'ptsIdx' => null,
        'penIdx' => null,
        'driversParsed' => 0,
    ];

    $html = (string)$html;
    if (trim($html) === '') {
        return [false, '', [], 'Empty HTML.', $debug];
    }

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $wrapped = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';

    $loaded = $dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR);
    if (!$loaded) {
        return [false, '', [], 'Could not parse HTML (DOMDocument loadHTML failed).', $debug];
    }

    $xpath = new DOMXPath($dom);

    $raceTitle = '';
    $h1 = $xpath->query('//h1');
    if ($h1 && $h1->length > 0) {
        $raceTitle = trim($h1->item(0)->textContent);
        $debug['h1'] = $raceTitle;
    }
    if ($raceTitle === '') {
        $t = $xpath->query('//title');
        if ($t && $t->length > 0) {
            $raceTitle = trim($t->item(0)->textContent);
        }
    }

    $tables = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " tablehead ")]');
    $debug['tableCount'] = $tables ? $tables->length : 0;

    if (!$tables || $tables->length === 0) {
        return [false, $raceTitle, [], 'Could not find <table class="tablehead"> in the fetched HTML.', $debug];
    }

    $bestTable = null;
    for ($i = 0; $i < $tables->length; $i++) {
        $tbl = $tables->item($i);
        $text = strtoupper(preg_replace('/\s+/', ' ', $tbl->textContent));
        if (strpos($text, 'DRIVER') !== false && strpos($text, 'PTS') !== false) {
            $bestTable = $tbl;
            break;
        }
    }
    if ($bestTable === null) {
        $bestTable = $tables->item(0);
    }

    $rows = $xpath->query('.//tr', $bestTable);
    $debug['rowCount'] = $rows ? $rows->length : 0;

    if (!$rows || $rows->length === 0) {
        return [false, $raceTitle, [], 'Results table found, but no rows were detected.', $debug];
    }

    $driverIdx = null;
    $ptsIdx    = null;
    $penIdx    = null;

    foreach ($rows as $r) {
        $cells = $r->getElementsByTagName('td');
        if ($cells->length === 0) continue;

        $headers = [];
        for ($c = 0; $c < $cells->length; $c++) {
            $headers[] = strtoupper(trim($cells->item($c)->textContent));
        }

        if (in_array('DRIVER', $headers, true) && in_array('PTS', $headers, true)) {
            $driverIdx = array_search('DRIVER', $headers, true);
            $ptsIdx    = array_search('PTS', $headers, true);

            if (in_array('PENALTY', $headers, true)) {
                $penIdx = array_search('PENALTY', $headers, true);
            } elseif (in_array('PEN', $headers, true)) {
                $penIdx = array_search('PEN', $headers, true);
            }
            break;
        }
    }

    if ($driverIdx === null) $driverIdx = 1;
    if ($ptsIdx === null) $ptsIdx = 7;

    $debug['driverIdx'] = $driverIdx;
    $debug['ptsIdx'] = $ptsIdx;
    $debug['penIdx'] = $penIdx;

    $out = [];

    foreach ($rows as $r) {
        $cells = $r->getElementsByTagName('td');
        if ($cells->length < 5) continue;

        $firstCell = strtoupper(trim($cells->item(0)->textContent));
        if ($firstCell === 'POS' || $firstCell === 'RACE RESULTS' || $firstCell === '') {
            continue;
        }

        if ($driverIdx >= $cells->length) continue;
        $driverCell = $cells->item($driverIdx);

        $driverName = '';
        $links = $driverCell->getElementsByTagName('a');
        if ($links->length > 0) {
            $driverName = trim($links->item(0)->textContent);
        } else {
            $driverName = trim($driverCell->textContent);
        }
        if ($driverName === '') continue;

        if ($ptsIdx >= $cells->length) continue;
        $ptsRaw = trim($cells->item($ptsIdx)->textContent);
        $ptsRaw = preg_replace('/[^\d\-]/', '', $ptsRaw);
        if ($ptsRaw === '' || !is_numeric($ptsRaw)) continue;

        $penaltyVal = null;
        if ($penIdx !== null && $penIdx < $cells->length) {
            $penRaw = trim($cells->item($penIdx)->textContent);
            $penRaw = preg_replace('/[^\d\-]/', '', $penRaw);
            if ($penRaw !== '' && is_numeric($penRaw)) {
                $penaltyVal = (int)$penRaw;
            } else {
                $penaltyVal = 0;
            }
        }

        $out[] = [
            'driver'  => $driverName,
            'pts'     => (int)$ptsRaw,
            'penalty' => $penaltyVal,
        ];
    }

    $debug['driversParsed'] = count($out);

    if (count($out) === 0) {
        return [false, $raceTitle, [], 'Table found, but no driver points rows were parsed.', $debug];
    }

    // Keep your previous behavior: sort by driver name
    usort($out, function($a, $b) {
        return strcasecmp($a['driver'], $b['driver']);
    });

    return [true, $raceTitle, $out, '', $debug];
}

/* ----------------------- request handling ----------------------- */

$req = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

$url   = trim((string)($req['url'] ?? ''));
$table = (string)($req['table'] ?? '');
$year  = (int)($req['year'] ?? (int)date('Y'));
$action = (string)($req['action'] ?? ''); // '', 'find_latest', 'simulate_insert'
$debugMode = isset($req['debug']) && (string)$req['debug'] === '1';

$attempted = false;
$parsedOk  = false;

$raceTitle = '';
$rows      = []; // parsed rows: driver/pts/penalty
$errorMsg  = '';
$showPasteBox = false;

$debug = [
    'httpStatus' => null,
    'fetchOk' => null,
    'fetchError' => '',
    'htmlBytes' => 0,
    'parse' => [],
    'latest' => [],
];

// Driver matching outputs
$dbDriverSet = [];
$matchRows = [];      // each: driver, pts, penalty, in_db(bool)
$missingDrivers = []; // list of missing driver names
$simSql = '';         // simulated INSERT SQL output

// Action: find latest race for year (select LAST link)
if ($action === 'find_latest') {
    [$lok, $latestUrl, $lerr, $ldebug] = fetchLatestEspnRaceByYear($year);
    $debug['latest'] = $ldebug;

    $attempted = true;

    if (!$lok) {
        $parsedOk = false;
        $errorMsg = $lerr ?: 'Could not find latest race URL for that year.';
        $showPasteBox = true;
    } else {
        // Put the found URL into $url and proceed to parse it below
        $url = $latestUrl;
    }
}

// Parse only if we have something to parse AND no earlier error
if (($url !== '' || trim($table) !== '') && $errorMsg === '') {
    $attempted = true;

    if ($url !== '') {
        if (!looksLikeEspnRaceResultsUrl($url)) {
            $errorMsg = 'That URL does not look like an ESPN race results page. (You can paste the table below.)';
            $showPasteBox = true;
        } else {
            [$ok, $status, $html, $fetchErr] = fetchUrl($url);

            $debug['fetchOk'] = $ok ? 1 : 0;
            $debug['httpStatus'] = $status;
            $debug['fetchError'] = $fetchErr;
            $debug['htmlBytes'] = strlen((string)$html);

            if (!$ok) {
                $errorMsg = 'Fetch failed' . ($status ? " (HTTP $status)" : '') . ': ' . ($fetchErr ?: 'Unknown error');
                $showPasteBox = true;
            } else {
                [$pOk, $rTitle, $d, $pErr, $pDebug] = parseEspnRaceResultsHtml($html);
                $debug['parse'] = $pDebug;

                if (!$pOk) {
                    $errorMsg = $pErr ?: 'Parse failed for unknown reason.';
                    $raceTitle = $rTitle ?: '';
                    $showPasteBox = true;
                } else {
                    $parsedOk = true;
                    $raceTitle = $rTitle ?: '';
                    $rows = $d;
                }
            }
        }
    }

    // Fallback: pasted table
    if (!$parsedOk && trim($table) !== '') {
        [$pOk, $rTitle, $d, $pErr, $pDebug] = parseEspnRaceResultsHtml($table);
        $debug['parse'] = $pDebug;

        if ($pOk) {
            $parsedOk = true;
            if ($raceTitle === '' && $rTitle !== '') $raceTitle = $rTitle;
            $rows = $d;
            $errorMsg = '';
        } else {
            $errorMsg = $errorMsg ?: ($pErr ?: 'Paste parse failed.');
            $showPasteBox = true;
        }
    }

    if (!$parsedOk) {
        $showPasteBox = true;
    }
}

if (isset($_GET['showPaste']) && $_GET['showPaste'] === '1') {
    $showPasteBox = true;
}

// If we parsed OK, do the live DB match (exact match against drivers.driverName)
if ($parsedOk) {
    $dbDriverSet = dbFetchDriverNames();

    foreach ($rows as $r) {
        $name = (string)($r['driver'] ?? '');
        $inDb = isset($dbDriverSet[$name]);

        $matchRows[] = [
            'driver'  => $name,
            'pts'     => (int)($r['pts'] ?? 0),
            'penalty' => array_key_exists('penalty', $r) ? $r['penalty'] : null,
            'in_db'   => $inDb,
        ];

        if (!$inDb && $name !== '') {
            $missingDrivers[$name] = true;
        }
    }

    $missingDrivers = array_keys($missingDrivers);
    sort($missingDrivers, SORT_NATURAL | SORT_FLAG_CASE);
}

// Simulate INSERT SQL for checked missing drivers (NO execution)
if ($parsedOk && $action === 'simulate_insert') {
    $selected = $_POST['missing'] ?? [];
    if (!is_array($selected)) $selected = [];

    $selected = array_values(array_filter(array_map('strval', $selected), function($v) {
        return trim($v) !== '';
    }));

    // De-dupe while preserving order
    $seen = [];
    $final = [];
    foreach ($selected as $name) {
        if (isset($seen[$name])) continue;
        $seen[$name] = true;
        $final[] = $name;
    }

    if (count($final) > 0) {
        $lines = [];
        $lines[] = "-- SIMULATION ONLY (do not auto-run): adds missing ESPN names into drivers";
        $lines[] = "-- Generated: " . date('Y-m-d H:i:s');
        $lines[] = "";
        foreach ($final as $name) {
            $lines[] = "INSERT INTO drivers (driverName) VALUES (" . sqlQuote($name) . ");";
        }
        $simSql = implode("\n", $lines);
    } else {
        $simSql = "-- No names selected.";
    }
}
/* ----------------------- page output ----------------------- */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Points Sandbox (No DB Writes)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
    body{
        margin:0 !important;
        font-family: Arial, Helvetica, sans-serif !important;
        background:#111 !important;
        color:#eee !important;
    }
    .topbar{
        display:flex !important;
        justify-content:space-between !important;
        align-items:center !important;
        padding:10px 14px !important;
        font-size:14px !important;
    }
    .admin-status{ font-weight:bold !important; }
    .admin-yes{ color:#39d353 !important; }
    .admin-no{ color:#ff6b6b !important; }

    .wrap{
        max-width:1100px !important;
        margin:18px auto 40px !important;
        padding:0 14px !important;
    }

    .card{
        background:#1b1b1b !important;
        border:1px solid #2b2b2b !important;
        border-radius:10px !important;
        padding:18px !important;
        box-shadow: 0 8px 24px rgba(0,0,0,.35) !important;
        margin-bottom:16px !important;
        color:#eee !important;
    }

    h1{
        margin:0 0 8px 0 !important;
        font-size:34px !important;
        color:#d8c08a !important;
        letter-spacing:.2px !important;
    }
    .sub{
        margin:0 0 14px 0 !important;
        color:#c9bfa9 !important;
    }

    label{
        display:block !important;
        margin:10px 0 6px !important;
        color:#c9bfa9 !important;
        font-weight:bold !important;
    }

    input[type="text"], select{
        width:100% !important;
        box-sizing:border-box !important;
        padding:12px 12px !important;
        border-radius:8px !important;
        border:1px solid #333 !important;
        background:#121212 !important;
        color:#fff !important;
        font-size:16px !important;
        outline:none !important;
    }

    textarea{
        width:100% !important;
        min-height:220px !important;
        box-sizing:border-box !important;
        padding:12px !important;
        border-radius:8px !important;
        border:1px solid #333 !important;
        background:#121212 !important;
        color:#fff !important;
        font-size:14px !important;
        outline:none !important;
        resize:vertical !important;
    }

    .row{
        display:flex !important;
        gap:10px !important;
        align-items:center !important;
        margin-top:12px !important;
        flex-wrap:wrap !important;
    }

    .btn{
        display:inline-block !important;
        border:0 !important;
        border-radius:8px !important;
        padding:12px 18px !important;
        font-weight:bold !important;
        cursor:pointer !important;
        font-size:16px !important;
        text-decoration:none !important;
    }
    .btn-primary{ background:#2f6feb !important; color:#fff !important; }
    .btn-ghost{ background:#2a2a2a !important; color:#fff !important; }
    .btn-warn{ background:#444 !important; color:#fff !important; }
    .btn-link{
        background:transparent !important;
        color:#9db7ff !important;
        padding:0 !important;
        border-radius:0 !important;
        font-weight:normal !important;
        font-size:14px !important;
        text-decoration:underline !important;
    }

    .msg-ok{
        background:#1f6f2a !important;
        border:1px solid #2aa93b !important;
        padding:12px 12px !important;
        border-radius:8px !important;
        margin:12px 0 0 !important;
        color:#ffffff !important;
        font-weight:bold !important;
        font-size:16px !important;
    }
    .msg-err{
        background:#7a1f1f !important;
        border:1px solid #ff5a5a !important;
        padding:12px 12px !important;
        border-radius:8px !important;
        margin:12px 0 0 !important;
        color:#ffffff !important;
        font-weight:bold !important;
        font-size:16px !important;
    }

    .results-title{
        margin:0 0 10px 0 !important;
        font-size:18px !important;
        color:#d8c08a !important;
        font-weight:bold !important;
    }

    table.results{
        width:100% !important;
        border-collapse:collapse !important;
        margin-top:6px !important;
        font-size:16px !important;
        color:#ffffff !important;
    }
    table.results th, table.results td{
        border-bottom:1px solid #3a3a3a !important;
        padding:12px 10px !important;
        text-align:left !important;
        color:#ffffff !important;
    }
    table.results th{
        color:#ffd88a !important;
        font-size:16px !important;
    }

    details{
        margin-top:12px !important;
        background:#141414 !important;
        border:1px solid #2b2b2b !important;
        border-radius:8px !important;
        padding:10px 12px !important;
        color:#ddd !important;
    }
    details summary{
        cursor:pointer !important;
        color:#9db7ff !important;
        font-weight:bold !important;
    }
    pre{
        white-space:pre-wrap !important;
        word-break:break-word !important;
        color:#ddd !important;
        margin:10px 0 0 !important;
        font-size:13px !important;
    }

    code{
        background:#111 !important;
        border:1px solid #333 !important;
        padding:2px 6px !important;
        border-radius:6px !important;
        color:#ffd88a !important;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace !important;
        font-size: 13px !important;
    }
</style>
</head>

<body>
<div class="topbar">
    <?php echo $adminStatusLine; ?>
</div>

<div class="wrap">

    <div class="card">
        <h1>Points Sandbox (No DB Writes)</h1>
        <p class="sub">Enter a race URL and Parse, or use “Find Latest Race” by year.</p>

        <form method="get" action="">
            <label for="year">Select Year (for Find Latest Race)</label>
            <div class="row" style="margin-top:0;">
                <div style="flex:1; min-width:220px;">
                    <select id="year" name="year">
                        <?php
                        // Simple dropdown for now; later you’ll pull this from DB.
                        // Keep current year + a little history.
                        $cur = (int)date('Y');
                        $min = max(2017, $cur - 15);
                        $max = max($cur, $year);
                        for ($y = $max; $y >= $min; $y--) {
                            $sel = ($y === (int)$year) ? ' selected' : '';
                            echo '<option value="' . (int)$y . '"' . $sel . '>' . (int)$y . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <button class="btn btn-warn" type="submit" name="action" value="find_latest">Find Latest Race</button>

                <label style="display:flex; align-items:center; gap:8px; margin:0;">
                    <input type="checkbox" name="debug" value="1" <?php echo $debugMode ? 'checked' : ''; ?>>
                    Debug
                </label>
            </div>

            <label for="url" style="margin-top:14px;">ESPN Race Results Page (URL)</label>
            <input id="url" name="url" type="text" value="<?php echo h($url); ?>"
                   placeholder="https://www.espn.com/racing/raceresults/_/series/sprint/raceId/202602150001">

            <div class="row">
                <button class="btn btn-primary" type="submit" name="action" value="">Parse + Show Driver Points</button>
                <a class="btn btn-ghost" href="<?php echo h($_SERVER['PHP_SELF']); ?>">Clear</a>

                <?php if (!$showPasteBox): ?>
                    <a class="btn btn-link" href="?<?php
                        $qs = [];
                        if ($url !== '') $qs[] = 'url=' . rawurlencode($url);
                        $qs[] = 'showPaste=1';
                        echo h(implode('&', $qs));
                    ?>">Need to paste instead?</a>
                <?php endif; ?>
            </div>

            <?php if ($attempted && $parsedOk): ?>
                <div class="msg-ok">
                    SUCCESS: <?php echo h(count($rows)); ?> drivers parsed.
                    <?php if ($raceTitle !== ''): ?>
                        &nbsp;|&nbsp; <?php echo h($raceTitle); ?>
                    <?php endif; ?>
                </div>
            <?php elseif ($attempted && !$parsedOk): ?>
                <div class="msg-err">FAILED: <?php echo h($errorMsg ?: 'No results parsed.'); ?></div>
            <?php endif; ?>

            <?php if ($debugMode && $attempted): ?>
                <details open>
                    <summary>Debug details (click to collapse)</summary>
                    <pre><?php echo h(print_r($debug, true)); ?></pre>
                </details>
            <?php elseif ($attempted): ?>
                <details>
                    <summary>Debug details (click to expand)</summary>
                    <pre><?php echo h(print_r($debug, true)); ?></pre>
                </details>
            <?php endif; ?>

            <?php if ($showPasteBox): ?>
                <label for="table" style="margin-top:16px;">Paste Table / HTML (fallback)</label>
                <textarea id="table" name="table" placeholder="Paste the ESPN results table (or the whole page HTML) here..."><?php echo h($table); ?></textarea>
                <div class="sub" style="margin-top:6px;">
                    Tip: On ESPN you can select the rows (include the header), copy, paste here.
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($parsedOk): ?>

        <?php
        // IMPORTANT: Missing drivers section goes FIRST (per your usability preference)
        $missingCount = count($missingDrivers);
        ?>

        <?php if ($missingCount > 0): ?>
            <div class="card">
                <div class="results-title">Driver Name Match vs DB table <code>drivers</code> (Exact Match)</div>

                <div class="msg-err">
                    Name check: <?php echo (int)$missingCount; ?> driver name(s) did NOT match your DB driver list.
                    (Matched <?php echo (int)(count($matchRows) - $missingCount); ?>/<?php echo (int)count($matchRows); ?>)
                </div>

                <form method="post" action="" style="margin-top:12px;">
                    <input type="hidden" name="url" value="<?php echo h($url); ?>">
                    <input type="hidden" name="year" value="<?php echo (int)$year; ?>">
                    <input type="hidden" name="action" value="simulate_insert">

                    <table class="results" style="margin-top:10px;">
                        <thead>
                            <tr>
                                <th style="width:90px;">Add?</th>
                                <th>Driver Name (from ESPN)</th>
                                <th style="width:140px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($missingDrivers as $name): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="missing[]" value="<?php echo h($name); ?>" checked>
                                </td>
                                <td><?php echo h($name); ?></td>
                                <td>Missing</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="row" style="margin-top:12px;">
                        <button class="btn btn-primary" type="submit">Simulate INSERT SQL</button>
                    </div>

                    <div class="sub" style="margin-top:8px;">
                        This does not write to the DB. It only generates SQL you can copy/paste into phpMyAdmin.
                    </div>
                </form>

                <?php if (trim($simSql) !== ''): ?>
                    <label style="margin-top:14px;">Simulated SQL (copy/paste)</label>
                    <textarea readonly onclick="this.select();"><?php echo h($simSql); ?></textarea>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="results-title">Driver Points (Parsed Output)</div>

            <table class="results">
                <thead>
                    <tr>
                        <th>Driver</th>
                        <th style="text-align:right; width:120px;">Points</th>
                        <th style="text-align:right; width:120px;">Penalty</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($matchRows as $r): ?>
                    <tr>
                        <td><?php echo h($r['driver']); ?></td>
                        <td style="text-align:right;"><?php echo (int)$r['pts']; ?></td>
                        <td style="text-align:right;">
                            <?php
                            if ($r['penalty'] === null) {
                                echo '';
                            } else {
                                echo (int)$r['penalty'];
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="sub" style="margin-top:10px;">
                Note: “Penalty” will be blank on races/pages where ESPN doesn’t provide that column.
            </div>
        </div>

    <?php endif; ?>

    <div style="font-size:10px; color:#999; text-align:right; margin:14px 10px 8px 10px; padding:0;">
        admin_points_sandbox.php
    </div>

</div>
</body>
</html>
