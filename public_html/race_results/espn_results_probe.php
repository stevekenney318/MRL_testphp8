<?php
declare(strict_types=1);

/**
 * espn_results_probe.php
 *
 * VERSION: v001
 * LAST MODIFIED: 4/26/2026 3:02:08 pm
 *
 * CHANGELOG:
 * v001 (4/26/2026)
 *   - TEMP: Self-contained ESPN probe for browser testing on any host.
 *   - TEMP: Does not require MRL config or helper files.
 *   - TEMP: Compares two cURL request styles against ESPN pages.
 *   - TEMP: Saves fetched HTML into a local _espn_probe_output folder.
 *
 * Purpose:
 *   Lightweight one-file diagnostic to test what server-side PHP/cURL receives
 *   from ESPN compared to what the user sees in a normal browser.
 */

date_default_timezone_set('America/New_York');
ini_set('display_errors', '1');
error_reporting(E_ALL);

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function preferred_timestamp(bool $forFilename = false): string
{
    $micro = microtime(true);
    $microStr = sprintf('%.6f', $micro);
    $dt = DateTime::createFromFormat('U.u', $microStr);
    if ($dt === false) {
        $dt = new DateTime('now');
    }
    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $date = $dt->format('Ymd');
    $time = $dt->format('His');
    $ms = (int)floor(($micro - floor($micro)) * 1000);
    if ($ms < 0) $ms = 0;
    if ($ms > 999) $ms = 999;
    $msStr = str_pad((string)$ms, 3, '0', STR_PAD_LEFT);
    return $date . ($forFilename ? '_' : '.') . $time . $msStr;
}

function atomic_write(string $path, string $contents): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $dir . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(4));
    $ok = @file_put_contents($tmp, $contents);
    if ($ok === false) return false;
    return @rename($tmp, $path);
}

function fetch_url_rr_style(string $url, int $timeoutSeconds): array
{
    $ch = curl_init();
    if ($ch === false) return [false, 0, '', 'cURL init failed', ''];

    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $urlWithBust = $url . $sep . '_=' . rawurlencode((string)microtime(true));
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    curl_setopt_array($ch, [
        CURLOPT_URL => $urlWithBust,
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
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($body === false || $body === null) return [false, $code, '', $err ?: 'Unknown fetch error', $effectiveUrl];
    if ($code >= 400 || $code === 0) return [false, $code, (string)$body, $err ?: ('HTTP error ' . $code), $effectiveUrl];
    return [true, $code, (string)$body, '', $effectiveUrl];
}

function fetch_url_alt(string $url, int $timeoutSeconds): array
{
    $ch = curl_init();
    if ($ch === false) return [false, 0, '', 'cURL init failed', ''];

    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:137.0) Gecko/20100101 Firefox/137.0';

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Cache-Control: max-age=0',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($body === false || $body === null) return [false, $code, '', $err ?: 'Unknown fetch error', $effectiveUrl];
    if ($code >= 400 || $code === 0) return [false, $code, (string)$body, $err ?: ('HTTP error ' . $code), $effectiveUrl];
    return [true, $code, (string)$body, '', $effectiveUrl];
}

function extract_title(string $html): string
{
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $title = html_entity_decode(trim(strip_tags((string)$m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/', ' ', $title);
        return (string)$title;
    }
    return '';
}

function count_tables(string $html): int
{
    if ($html === '') return 0;
    preg_match_all('~<table\b~i', $html, $m);
    return is_array($m) ? count($m[0]) : 0;
}

function count_race_links(string $html): int
{
    if ($html === '') return 0;
    preg_match_all('~https://www\.espn\.com/racing/raceresults/_/series/[^"\'\s>]+/raceId/\d+~i', $html, $m1);
    preg_match_all('~href=["\'](?:https://www\.espn\.com)?/racing/raceresults/_/series/[^"\']+/raceId/\d+["\']~i', $html, $m2);
    $count1 = is_array($m1) ? count($m1[0]) : 0;
    $count2 = is_array($m2) ? count($m2[0]) : 0;
    return max($count1, $count2);
}

function short_snippet(string $html, int $max = 360): string
{
    $text = preg_replace('/\s+/', ' ', strip_tags($html));
    $text = trim((string)$text);
    if ($text === '') {
        $text = preg_replace('/\s+/', ' ', $html);
        $text = trim((string)$text);
    }
    if (strlen($text) > $max) {
        $text = substr($text, 0, $max);
    }
    return $text;
}

function token_flags(string $html): string
{
    $flags = [];
    $flags[] = '__NEXT_DATA__=' . (stripos($html, '__NEXT_DATA__') !== false ? 'YES' : 'NO');
    $flags[] = 'raceresults=' . (stripos($html, '/racing/raceresults') !== false ? 'YES' : 'NO');
    $flags[] = 'raceId=' . (stripos($html, '/raceId/') !== false ? 'YES' : 'NO');
    $flags[] = 'awsWaf=' . (stripos($html, 'awsWafCookieDomainList') !== false ? 'YES' : 'NO');
    $flags[] = 'gokuProps=' . (stripos($html, 'gokuProps') !== false ? 'YES' : 'NO');
    return implode(' | ', $flags);
}

$year = isset($_GET['year']) && preg_match('/^\d{4}$/', (string)$_GET['year']) ? (string)$_GET['year'] : '2026';
$timeout = isset($_GET['timeout']) ? max(5, min(60, (int)$_GET['timeout'])) : 25;
$directRaceUrl = isset($_GET['race_url']) && filter_var((string)$_GET['race_url'], FILTER_VALIDATE_URL)
    ? (string)$_GET['race_url']
    : 'https://www.espn.com/racing/raceresults/_/series/sprint/raceId/202604190757';

$targets = [
    ['label' => 'Generic results page', 'url' => 'https://www.espn.com/racing/results'],
    ['label' => 'Year results page', 'url' => 'https://www.espn.com/racing/results/_/year/' . $year],
    ['label' => 'Direct race page', 'url' => $directRaceUrl],
];

$methods = [
    ['label' => 'rr_fetch_url()', 'fn' => 'fetch_url_rr_style'],
    ['label' => 'alternate cURL', 'fn' => 'fetch_url_alt'],
];

$outputDir = __DIR__ . '/_espn_probe_output';
if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0775, true);
}

$rows = [];
foreach ($targets as $target) {
    foreach ($methods as $method) {
        $fn = $method['fn'];
        [$ok, $http, $html, $error, $effectiveUrl] = $fn((string)$target['url'], $timeout);
        $savedName = preg_replace('/[^A-Za-z0-9_]+/', '_', strtolower((string)$target['label']))
            . '_' . preg_replace('/[^A-Za-z0-9_]+/', '_', strtolower((string)$method['label']))
            . '_' . preferred_timestamp(true) . '.html';
        $savedPath = $outputDir . '/' . $savedName;
        atomic_write($savedPath, $html);

        $rows[] = [
            'target' => (string)$target['label'],
            'url' => (string)$target['url'],
            'method' => (string)$method['label'],
            'status' => $ok ? 'OK' : 'FAIL',
            'http' => $http,
            'bytes' => strlen($html),
            'tables' => count_tables($html),
            'race_links' => count_race_links($html),
            'title' => extract_title($html),
            'effective_url' => $effectiveUrl,
            'tokens' => token_flags($html),
            'saved' => $savedName,
            'detail' => $error !== '' ? $error : ('Snippet: ' . short_snippet($html)),
        ];
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ESPN Results Probe</title>
<style>
body { font-family: Arial, Helvetica, sans-serif; margin: 16px; line-height: 1.35; }
h1 { margin: 0 0 8px 0; }
p.meta { margin: 0 0 12px 0; }
table { border-collapse: collapse; width: 100%; font-size: 14px; }
th, td { border: 1px solid #777; padding: 6px 8px; vertical-align: top; text-align: left; }
th { background: #eee; }
.small { font-size: 12px; color: #444; }
code { font-family: Consolas, monospace; }
</style>
</head>
<body>
<h1>ESPN Results Probe</h1>
<p class="meta">
<strong>Year:</strong> <?= e($year) ?><br>
<strong>Run:</strong> <?= e(date('Y-m-d H:i:s')) ?><br>
<strong>Timeout:</strong> <?= e((string)$timeout) ?> seconds
</p>
<p>This is a temporary diagnostic file. It does not change monitor behavior. It compares the current rr_fetch_url() style with one alternate cURL fetch path.</p>
<table>
<thead>
<tr>
    <th>Target</th>
    <th>Method</th>
    <th>Status</th>
    <th>HTTP</th>
    <th>Bytes</th>
    <th>Tables</th>
    <th>Race Links</th>
    <th>Title</th>
    <th>Effective URL</th>
    <th>Tokens</th>
    <th>Saved HTML</th>
    <th>Error / Snippet</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
    <td><strong><?= e($row['target']) ?></strong><br><span class="small"><?= e($row['url']) ?></span></td>
    <td><?= e($row['method']) ?></td>
    <td><?= e($row['status']) ?></td>
    <td><?= e((string)$row['http']) ?></td>
    <td><?= e((string)$row['bytes']) ?></td>
    <td><?= e((string)$row['tables']) ?></td>
    <td><?= e((string)$row['race_links']) ?></td>
    <td><?= e($row['title']) ?></td>
    <td><?= e($row['effective_url']) ?></td>
    <td><?= e($row['tokens']) ?></td>
    <td><?= e($row['saved']) ?></td>
    <td><?= e($row['detail']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p class="small">
Optional query string inputs:<br>
<code>?year=2026</code><br>
<code>?race_url=https://www.espn.com/racing/raceresults/_/series/sprint/raceId/202604190757</code><br>
<code>?timeout=25</code>
</p>
</body>
</html>
