<?php
declare(strict_types=1);

/*
  File: espn_latest_race.php
*/

function normalizeYear(string $year): int
{
    $y = (int)preg_replace('~\D~', '', $year);
    if ($y < 2017) $y = 2017;
    if ($y > 2026) $y = 2026;
    return $y;
}

function curlFetch(string $url, int &$httpCode = 0, string &$error = ''): ?string
{
    $ch = curl_init();
    if ($ch === false) {
        $error = "curl_init() failed";
        return null;
    }

    $headers = [
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language: en-US,en;q=0.9",
        "Connection: keep-alive",
        "Upgrade-Insecure-Requests: 1"
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        CURLOPT_ENCODING => "",
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch) ?: "Unknown cURL error";
        curl_close($ch);
        return null;
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return $body;
}

function extractLastRaceResultsUrl(string $html): ?string
{
    preg_match_all(
        '~href=[\'"](?P<href>/racing/raceresults[^\'"]*?/raceId/\d+[^\'"]*)[\'"]~i',
        $html,
        $m
    );

    $hrefs = [];
    if (!empty($m['href'])) {
        $hrefs = $m['href'];
    } else {
        preg_match_all('~(/racing/raceresults[^"\s>]*?/raceId/\d+)~i', $html, $m2);
        if (!empty($m2[1])) {
            $hrefs = $m2[1];
        }
    }

    if (empty($hrefs)) {
        return null;
    }

    $lastHref = end($hrefs);
    if (!is_string($lastHref) || $lastHref === '') {
        return null;
    }

    if (preg_match('~/series/([a-z0-9_-]+)/raceId/(\d+)~i', $lastHref, $mm)) {
        $series = $mm[1];
        $raceId = $mm[2];
        return "https://www.espn.com/racing/raceresults/_/series/{$series}/raceId/{$raceId}";
    }

    return "https://www.espn.com" . $lastHref;
}

function cleanText(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strip_tags($s);
    $s = preg_replace('~\s+~', ' ', $s);
    return trim($s);
}

function looksLikeBrowserWarning(string $text): bool
{
    $t = strtolower($text);
    return (strpos($t, 'your web browser') !== false)
        || (strpos($t, 'no longer supported') !== false)
        || (strpos($t, 'upgrade') !== false && strpos($t, 'browser') !== false);
}

/**
 * Bulletproof header extraction:
 * - Collect ALL <h1 ...>...</h1>
 * - Prefer h1 whose opening tag contains class="h2" (or class='h2' or class contains h2)
 * - Otherwise prefer h1 that starts with a year and contains "Results"
 * - Always ignore browser warning headings
 */
function extractRaceHeaderText(string $raceHtml): ?string
{
    preg_match_all('~(<h1\b[^>]*>)(.*?)</h1>~is', $raceHtml, $m);
    if (empty($m[0])) {
        return null;
    }

    $candidates = [];
    $count = count($m[0]);

    for ($i = 0; $i < $count; $i++) {
        $openTag = $m[1][$i] ?? '';
        $inner   = $m[2][$i] ?? '';

        $text = cleanText((string)$inner);
        if ($text === '') continue;
        if (looksLikeBrowserWarning($text)) continue;

        $openLower = strtolower((string)$openTag);

        $hasH2Class = false;
        // Match: class="h2" or class="... h2 ..." or class='...h2...'
        if (preg_match('~\bclass\s*=\s*([\'"])(.*?)\1~is', $openLower, $cm)) {
            $classes = ' ' . strtolower($cm[2]) . ' ';
            if (strpos($classes, ' h2 ') !== false) {
                $hasH2Class = true;
            }
        }

        $startsWithYear = (bool)preg_match('~^20\d{2}\b~', $text);
        $hasResultsWord = (stripos($text, 'results') !== false);

        $score = 0;
        if ($hasH2Class) $score += 1000;
        if ($startsWithYear) $score += 100;
        if ($hasResultsWord) $score += 50;

        $candidates[] = ['text' => $text, 'score' => $score];
    }

    if (empty($candidates)) {
        return null;
    }

    usort($candidates, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return $candidates[0]['text'] ?? null;
}

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

$selectedYear = isset($_GET['year']) ? normalizeYear((string)$_GET['year']) : 2026;
$resultsUrl = "https://www.espn.com/racing/results/_/year/" . $selectedYear;

$httpCode = 0;
$fetchError = '';
$fetchedHtml = null;

$latestRaceUrl = null;

$raceHttpCode = 0;
$raceFetchError = '';
$raceHtml = null;
$raceHeaderText = null;

if (isset($_GET['year'])) {
    $fetchedHtml = curlFetch($resultsUrl, $httpCode, $fetchError);

    if ($fetchedHtml !== null && $httpCode >= 200 && $httpCode < 400) {
        $latestRaceUrl = extractLastRaceResultsUrl($fetchedHtml);

        if ($latestRaceUrl) {
            $raceHtml = curlFetch($latestRaceUrl, $raceHttpCode, $raceFetchError);
            if ($raceHtml !== null && $raceHttpCode >= 200 && $raceHttpCode < 400) {
                $raceHeaderText = extractRaceHeaderText($raceHtml);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ESPN Latest Race Finder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 26px; line-height: 1.4; }
    .card { max-width: 980px; border: 1px solid #ddd; border-radius: 12px; padding: 16px; }
    .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    label { font-weight: 700; }
    select, button, input { font-size: 16px; padding: 6px 10px; }
    .muted { color: #555; font-size: 13px; margin-top: 8px; }
    .result { margin-top: 16px; padding: 12px; background: #f6f6f6; border-radius: 10px; }
    .field { margin-top: 10px; }
    .field label { display: block; margin-bottom: 6px; }
    .copyrow { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .textbox {
      width: 820px;
      max-width: 100%;
      border: 1px solid #bbb;
      border-radius: 8px;
    }
    .ok { color: #0a6; font-weight: 700; }
    .bad { color: #b00; font-weight: 700; }
    .titleline { font-size: 20px; font-weight: 800; margin: 6px 0 10px 0; }
    .debugbox { margin-top: 16px; padding: 12px; border: 1px dashed #999; border-radius: 10px; background: #fff; }
    pre { white-space: pre-wrap; word-break: break-word; }
    a { font-weight: 700; text-decoration: none; }
    a:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="card">
    <h2 style="margin:0 0 10px 0;">ESPN Latest Race Finder</h2>

    <form method="get" class="row">
      <label for="year">Select Year:</label>
      <select name="year" id="year">
        <?php for ($y = 2026; $y >= 2017; $y--): ?>
          <option value="<?= $y ?>" <?= ((int)$selectedYear === $y ? 'selected' : '') ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>

      <button type="submit">Find Latest Race</button>

      <label style="margin-left:8px;">
        <input type="checkbox" name="debug" value="1" <?= ($debug ? 'checked' : '') ?>>
        Debug
      </label>
    </form>

    <div class="muted">
      Results page used:
      <a href="<?= htmlspecialchars($resultsUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($resultsUrl) ?></a>
    </div>

    <?php if ($latestRaceUrl): ?>
      <div class="result">
        <div class="titleline">
          <?= $raceHeaderText ? htmlspecialchars($raceHeaderText) : '<span class="bad">Race title not found</span>' ?>
        </div>

        <div class="field">
          <label for="raceUrlBox">Latest race URL (click in box, then Copy):</label>
          <div class="copyrow">
            <input id="raceUrlBox" class="textbox" type="text" readonly
                   value="<?= htmlspecialchars($latestRaceUrl) ?>" onclick="this.select();" />
            <button type="button" onclick="copyFromInput('raceUrlBox', 'statusUrl')">Copy Link</button>
            <span id="statusUrl"></span>
          </div>
          <div class="muted">
            Open it:
            <a href="<?= htmlspecialchars($latestRaceUrl) ?>" target="_blank" rel="noopener noreferrer">click here</a>
          </div>
        </div>
      </div>
    <?php elseif (isset($_GET['year'])): ?>
      <div class="result">
        <strong>Could not find race results for <?= htmlspecialchars((string)$selectedYear) ?>.</strong><br>
        <?php if ($fetchError !== ''): ?>Fetch error: <?= htmlspecialchars($fetchError) ?><br><?php endif; ?>
        HTTP status (results page): <?= (int)$httpCode ?><br>
      </div>
    <?php endif; ?>

    <?php if ($debug): ?>
      <div class="debugbox">
        <strong>Debug Info</strong><br><br>

        <div><strong>Race page fetch</strong></div>
        HTTP status: <?= (int)$raceHttpCode ?><br>
        <?php if ($raceFetchError !== ''): ?>Error: <?= htmlspecialchars($raceFetchError) ?><br><?php endif; ?>
        <?php if ($raceHtml !== null): ?>
          <div class="muted">First ~1800 chars:</div>
          <pre><?= htmlspecialchars(substr($raceHtml, 0, 1800)) ?></pre>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

<script>
  async function copyFromInput(inputId, statusId) {
    const el = document.getElementById(inputId);
    const status = document.getElementById(statusId);
    const text = el.value || "";

    if (!text) {
      status.textContent = "Nothing to copy";
      status.className = "bad";
      setTimeout(() => status.textContent = "", 1200);
      return;
    }

    try {
      await navigator.clipboard.writeText(text);
      status.textContent = "Copied!";
      status.className = "ok";
    } catch (e) {
      el.focus();
      el.select();
      try {
        const ok = document.execCommand('copy');
        status.textContent = ok ? "Copied!" : "Copy failed";
        status.className = ok ? "ok" : "bad";
      } catch (err) {
        status.textContent = "Copy blocked";
        status.className = "bad";
      }
    }

    setTimeout(() => status.textContent = "", 1200);
  }
</script>

</body>
</html>
