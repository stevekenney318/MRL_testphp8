<?php
declare(strict_types=1);

/*
    filename: points_sandbox.php
    purpose : Admin "Points Sandbox" (NO DB writes)
             - URL-first: fetch ESPN results page and parse points table
             - Fallback: paste table HTML/text if fetch/parse fails
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

function parseEspnRaceResultsHtml(string $html): array {
    // Returns:
    // [ok(bool), raceTitle(string), drivers(array of [driver=>string, pts=>int, penalty=>int|null]), error(string), debug(array)]
    $debug = [
        'h1' => '',
        'tableCount' => 0,
        'rowCount' => 0,
        'driverIdx' => null,
        'ptsIdx' => null,
        'penaltyIdx' => null,
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
    $penaltyIdx = null;

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

            // PENALTY is sometimes present in the same table row as a header label
            if (in_array('PENALTY', $headers, true)) {
                $penaltyIdx = array_search('PENALTY', $headers, true);
            }
            break;
        }
    }

    if ($driverIdx === null) $driverIdx = 1;
    if ($ptsIdx === null) $ptsIdx = 7;
    // If PENALTY wasn't detected by header scan, we keep it null and parse as null.

    $debug['driverIdx'] = $driverIdx;
    $debug['ptsIdx'] = $ptsIdx;
    $debug['penaltyIdx'] = $penaltyIdx;

    $drivers = [];

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

        if ($ptsRaw === '' || !is_numeric($ptsRaw)) {
            continue;
        }

        // PENALTY (optional)
        $penaltyVal = null;
        if ($penaltyIdx !== null && $penaltyIdx < $cells->length) {
            $penRaw = trim($cells->item($penaltyIdx)->textContent);
            $penRaw = preg_replace('/[^\d\-]/', '', $penRaw);
            if ($penRaw !== '' && is_numeric($penRaw)) {
                $penaltyVal = (int)$penRaw;
            } else {
                // If ESPN shows blank/—/etc, keep it null
                $penaltyVal = null;
            }
        }

        $drivers[] = [
            'driver'  => $driverName,
            'pts'     => (int)$ptsRaw,
            'penalty' => $penaltyVal,
        ];
    }

    $debug['driversParsed'] = count($drivers);

    if (count($drivers) === 0) {
        return [false, $raceTitle, [], 'Table found, but no driver points rows were parsed.', $debug];
    }

    usort($drivers, function($a, $b) {
        return strcasecmp($a['driver'], $b['driver']);
    });

    return [true, $raceTitle, $drivers, '', $debug];
}

/* ----------------------- request handling ----------------------- */

$url   = trim((string)($_GET['url'] ?? ''));
$table = (string)($_GET['table'] ?? '');

$attempted = false;
$parsedOk  = false;

$raceTitle = '';
$drivers   = [];
$errorMsg  = '';

$showPasteBox = false;

$debug = [
    'httpStatus' => null,
    'fetchOk' => null,
    'fetchError' => '',
    'htmlBytes' => 0,
    'parse' => [],
];

if ($url !== '' || trim($table) !== '') {
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
                    $drivers = $d;
                }
            }
        }
    }

    if (!$parsedOk && trim($table) !== '') {
        [$pOk, $rTitle, $d, $pErr, $pDebug] = parseEspnRaceResultsHtml($table);
        $debug['parse'] = $pDebug;

        if ($pOk) {
            $parsedOk = true;
            if ($raceTitle === '' && $rTitle !== '') $raceTitle = $rTitle;
            $drivers = $d;
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

/* ----------------------- page output ----------------------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Points Sandbox (No DB Writes)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
    /* These !important rules are specifically to protect against any site-wide CSS overrides */
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

    input[type="text"]{
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

    .race-title{
        margin:0 0 10px 0 !important;
        font-size:18px !important;
        color:#ffffff !important;
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
    table.results td:last-child, table.results th:last-child{
        text-align:right !important;
        width:120px !important;
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
</style>
</head>

<body>
<div class="topbar">
    <?php echo $adminStatusLine; ?>
</div>

<div class="wrap">

    <div class="card">
        <h1>Points Sandbox (No DB Writes)</h1>
        <p class="sub">Paste-free when possible: enter the ESPN race results URL and click Parse.</p>

        <form method="get" action="">
            <label for="url">ESPN Race Results Page (URL)</label>
            <input id="url" name="url" type="text" value="<?php echo h($url); ?>"
                   placeholder="https://www.espn.com/racing/raceresults/_/series/sprint/raceId/202602150001">

            <div class="row">
                <button class="btn btn-primary" type="submit">Parse + Show Driver Points</button>
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
                    SUCCESS: <?php echo h(count($drivers)); ?> drivers parsed.
                    <?php if ($raceTitle !== ''): ?>
                        &nbsp;|&nbsp; <?php echo h($raceTitle); ?>
                    <?php endif; ?>
                </div>
            <?php elseif ($attempted && !$parsedOk): ?>
                <div class="msg-err">FAILED: <?php echo h($errorMsg ?: 'No results parsed.'); ?></div>
            <?php endif; ?>

            <?php if ($attempted): ?>
                <details>
                    <summary>Debug details (click to expand)</summary>
                    <pre><?php
                        echo h(print_r($debug, true));
                    ?></pre>
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
        <div class="card">
            <div class="results-title">Driver Points (Parsed Output)</div>

            <?php if ($raceTitle !== ''): ?>
                <div class="race-title"><strong>Race:</strong> <?php echo h($raceTitle); ?></div>
            <?php endif; ?>

            <table class="results">
                <thead>
                    <tr>
                        <th>Driver</th>
                        <th>Points</th>
                        <th>Penalty</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($drivers as $row): ?>
                    <tr>
                        <td><?php echo h($row['driver']); ?></td>
                        <td><?php echo h($row['pts']); ?></td>
                        <td><?php echo h($row['penalty'] === null ? '' : (string)$row['penalty']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>
</body>
</html>
