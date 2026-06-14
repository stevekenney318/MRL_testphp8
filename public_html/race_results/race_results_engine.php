<?php
declare(strict_types=1);

/**
 * race_results_engine.php
 *
 * VERSION: v006
 * LAST MODIFIED: 6/13/2026 6:13:08 pm
 *
 * CHANGELOG:
 * v006 (2026-06-13)
 *   - CHANGE: Removed the temporary 2026 San Diego ESPN schedule source correction now that ESPN is supplying the corrected Cup Series schedule row/name.
 *
 * v005 (2026-06-06)
 *   - NEW: Added ESPN year-schedule parsing helpers for monitor race-status and scheduler-data foundation.
 *   - NEW: Added helpers to identify the next scheduled points race and write schedule JSON artifacts.
 *   - CHANGE: Keeps result-page parsing unchanged while adding schedule-page support.
 *   - FIX: Improved schedule date/time parsing for compressed ESPN text such as Jun 73:00 PM ET.
 *   - FIX: Schedule rows no longer assign raw row counts as MRL race numbers.
 *   - NEW: Added year-index annotation so Cup points schedule rows get MRL R## identity from existing race-folder/index logic where possible.
 *   - FIX: Schedule MRL identity now matches existing R folders by race date/name before projecting future R numbers.
 *   - FIX: Projected future R numbers now start after the latest known R race instead of re-numbering all schedule rows.
 *   - FIX: Schedule parser now preserves special race titles such as Daytona 500 instead of grabbing action links like Starting Grid.
 *   - FIX: Schedule MRL eligibility now reuses rr_is_exhibition_race_name() and lets confirmed R-folder/year-index matches force points eligibility.
 *
 * v004 (2026-04-26)
 *   - FIX: rr_fetch_url() no longer appends the cache-buster query string that is now triggering ESPN's AWS WAF challenge response.
 *   - FIX: rr_fetch_url() now uses a simplified primary cURL request pattern that matches the successful probe behavior more closely.
 *   - FIX: rr_fetch_url() now detects the ESPN/AWS WAF challenge shell response and immediately retries with an even lighter fallback request before returning.
 *   - CHANGE: Added rr_body_looks_like_aws_waf_challenge() helper for consistent WAF/interstitial detection.
 *
 * v1.03.00.02 (2026-02-28)
 *   - CHANGE: FINAL table hash is now a stable "data hash" (normalized cell text),
 *     not raw table HTML. Prevents duplicate snapshots when ESPN markup changes but
 *     the visible table values do not.
 *
 * v1.03.00.01 (2026-02-27)
 *   - (Version formatting alignment)
 *
 * v1.02 (2026-02-25)
 *   - FIX (PHP 7.3): rr_preferred_timestamp() no longer uses DateTime('@<float>').
 *     PHP 7.3 cannot parse @1772012515.815 style strings (expects integer seconds).
 *     Now uses DateTime::createFromFormat('U.u', ...) which is PHP 7.3 safe.
 *
 * v1.01 (2026-02-25)
 *   - PHP 7.3 compatibility: use CURLINFO_HTTP_CODE (not CURLINFO_RESPONSE_CODE).
 *
 * v1.0 (2026-02-25)
 *   - Initial shared helper engine for monitor/backfill.
 *
 * Shared helper "engine" used by:
 *   - race_results_monitor.php
 *   - race_results_backfill.php
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * Return a timestamp like:
 *   20260223.164308089  (date.time + milliseconds)
 * If dot ever becomes a problem in a filename, pass $forFilename=true to get underscore:
 *   20260223_164308089
 */
function rr_preferred_timestamp(bool $forFilename = false): string
{
    $micro = microtime(true);

    // PHP 7.3-safe: build DateTime from seconds.microseconds
    // Use 6 digits for microseconds (createFromFormat 'U.u' expects that).
    $microStr = sprintf('%.6f', $micro);

    $dt = DateTime::createFromFormat('U.u', $microStr);
    if ($dt === false) {
        // Fallback (should be rare): use "now" without micro precision
        $dt = new DateTime('now');
    }

    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));

    $date = $dt->format('Ymd');
    $time = $dt->format('His');

    // Milliseconds (3 digits)
    $ms = (int)floor(($micro - floor($micro)) * 1000);
    if ($ms < 0) $ms = 0;
    if ($ms > 999) $ms = 999;

    $msStr = str_pad((string)$ms, 3, '0', STR_PAD_LEFT);

    $sep = $forFilename ? '_' : '.';
    return $date . $sep . $time . $msStr;
}

function rr_now_local_string(): string
{
    return date('Y-m-d H:i:s');
}

function rr_log_line(string $logFile, string $msg): void
{
    $ts = rr_now_local_string();
    @file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

function rr_atomic_write(string $path, string $contents): bool
{
    $dir = dirname($path);
    $tmp = $dir . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(4));
    $ok = @file_put_contents($tmp, $contents);
    if ($ok === false) return false;
    return @rename($tmp, $path);
}

function rr_load_json(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function rr_save_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT);
    if ($json === false) return;
    rr_atomic_write($path, $json);
}

function rr_sha256_file_string(string $path): string
{
    $raw = @file_get_contents($path);
    if ($raw === false) return '';
    return hash('sha256', $raw);
}

function rr_extract_race_id_from_url(string $url): string
{
    if (preg_match('~/raceId/(\d+)~', $url, $m)) return $m[1];
    return 'unknown';
}

function rr_sanitize_for_folder(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = trim($name);

    // Convert common separators to spaces first
    $name = str_replace(['/', '\\', ':', '|'], ' ', $name);

    // Keep letters/numbers/spaces/underscores/dashes only
    $name = preg_replace('/[^A-Za-z0-9 _-]+/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name);

    // Underscore style
    $name = str_replace([' ', '-'], '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    $name = trim($name, '_');

    if ($name === '') $name = 'Race';
    return $name;
}

function rr_is_exhibition_race_name(string $raceName): bool
{
    $u = strtoupper($raceName);

    // Daytona exhibition / qualifiers
    if (strpos($u, 'CLASH') !== false) return true;
    if (strpos($u, 'DUEL') !== false) return true;

    // All-Star events
    if (strpos($u, 'ALL-STAR') !== false) return true;
    if (strpos($u, 'ALL STAR') !== false) return true;
    if (strpos($u, 'OPEN RACE') !== false) return true;

    // If ESPN labels something explicitly as non-points (rare)
    if (strpos($u, 'NON-POINT') !== false) return true;

    return false;
}

function rr_docroot_from_script_dir(string $scriptDir): string
{
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
        if ($docRoot !== '' && is_dir($docRoot)) return $docRoot;
    }

    // scriptDir is expected like: .../public_html/race_results
    $maybe = realpath($scriptDir . '/..');
    $docRoot = $maybe ? rtrim($maybe, '/') : '';
    if ($docRoot !== '' && is_dir($docRoot)) return $docRoot;

    throw new RuntimeException("Could not determine DOCUMENT_ROOT from scriptDir='{$scriptDir}'");
}

function rr_body_looks_like_aws_waf_challenge(string $body, int $httpStatus = 0): bool
{
    if ($body === '') {
        return false;
    }

    $hasAwsWaf = (stripos($body, 'awsWafCookieDomainList') !== false);
    $hasGoku   = (stripos($body, 'gokuProps') !== false);

    if ($hasAwsWaf || $hasGoku) {
        return true;
    }

    if ($httpStatus === 202 && strlen($body) <= 4096) {
        return true;
    }

    return false;
}

/**
 * Internal low-level fetch helper.
 * Returns: [ok(bool), httpStatus(int), body(string), error(string)]
 */
function rr_fetch_url_internal(string $url, int $timeoutSeconds, bool $lightweightMode = false): array
{
    $ch = curl_init();
    if ($ch === false) {
        return [false, 0, '', 'cURL init failed'];
    }

    $ua = $lightweightMode
        ? 'Mozilla/5.0'
        : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    $headers = $lightweightMode
        ? [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]
        : [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_ENCODING, '');

    if ($lightweightMode) {
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.espn.com/racing/results');
    }

    $body = curl_exec($ch);
    $err  = curl_error($ch);

    // PHP 7.3 compatible:
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($body === false || $body === null) {
        return [false, $code, '', $err ?: 'Unknown fetch error'];
    }

    if ($code >= 400 || $code === 0) {
        return [false, $code, (string)$body, $err ?: ('HTTP error ' . $code)];
    }

    return [true, $code, (string)$body, ''];
}

/**
 * Fetch URL via cURL.
 * Returns: [ok(bool), httpStatus(int), body(string), error(string)]
 */
function rr_fetch_url(string $url, int $timeoutSeconds): array
{
    // Primary path: simplified request pattern without cache-buster.
    [$ok, $code, $body, $err] = rr_fetch_url_internal($url, $timeoutSeconds, false);

    // If ESPN/AWS WAF challenge shell is returned, immediately retry with an even lighter request.
    if ($ok && rr_body_looks_like_aws_waf_challenge($body, $code)) {
        [$ok2, $code2, $body2, $err2] = rr_fetch_url_internal($url, $timeoutSeconds, true);

        if ($ok2 && !rr_body_looks_like_aws_waf_challenge($body2, $code2)) {
            return [$ok2, $code2, $body2, $err2];
        }

        // If both paths still look challenged, prefer the second response because it is the freshest retry.
        if ($ok2) {
            return [$ok2, $code2, $body2, $err2];
        }
    }

    return [$ok, $code, $body, $err];
}

/**
 * Parse ESPN year results page and return race list in page order.
 */
function rr_parse_year_page_races(string $yearHtml): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $yearHtml, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    if (!$loaded) return [];

    $xp = new DOMXPath($dom);

    // All links that look like race results pages
    $aNodes = $xp->query("//a[contains(@href,'/racing/raceresults') and contains(@href,'/raceId/')]");
    if (!$aNodes || $aNodes->length === 0) return [];

    $seen = [];
    $raw = [];

    for ($i = 0; $i < $aNodes->length; $i++) {
        $a = $aNodes->item($i);
        if (!$a instanceof DOMElement) continue;

        $href = trim((string)$a->getAttribute('href'));
        if ($href === '') continue;

        // Normalize absolute
        if (strpos($href, 'http') !== 0) {
            $href = 'https://www.espn.com' . $href;
        }

        $raceId = rr_extract_race_id_from_url($href);
        if ($raceId === 'unknown') continue;

        // Use link text as race name; fallback to aria-label
        $name = trim((string)$a->textContent);
        if ($name === '') {
            $name = trim((string)$a->getAttribute('aria-label'));
        }
        if ($name === '') $name = 'Race';

        // De-dupe by raceId (year page can repeat links)
        if (isset($seen[$raceId])) continue;
        $seen[$raceId] = true;

        $isExh = rr_is_exhibition_race_name($name);

        $raw[] = [
            'race_name' => $name,
            'race_url' => $href,
            'race_id' => $raceId,
            'is_exhibition' => $isExh,
            'race_number' => null,
        ];
    }

    // Assign race numbers for points races only (legacy; your backfill now overrides naming)
    $num = 0;
    for ($i = 0; $i < count($raw); $i++) {
        if (!$raw[$i]['is_exhibition']) {
            $num++;
            $raw[$i]['race_number'] = $num;
        }
    }

    return $raw;
}

/**
 * Find latest race URL on the year page (last one in page order).
 * Returns: [ok(bool), latestUrl(string), error(string), debug(array)]
 */
function rr_find_latest_race_results_url(int $year, int $timeoutSeconds): array
{
    $debug = [
        'year' => $year,
        'yearPage' => '',
        'httpStatus' => null,
        'htmlBytes' => 0,
        'raceCount' => 0,
        'latestUrl' => '',
        'latestRaceId' => '',
    ];

    $yearPage = "https://www.espn.com/racing/results/_/year/" . $year;
    $debug['yearPage'] = $yearPage;

    [$ok, $status, $html, $err] = rr_fetch_url($yearPage, $timeoutSeconds);
    $debug['httpStatus'] = $status;
    $debug['htmlBytes'] = strlen($html);

    if (!$ok) return [false, '', "Failed to fetch ESPN year page (HTTP $status): " . $err, $debug];

    $races = rr_parse_year_page_races($html);
    $debug['raceCount'] = count($races);

    if (count($races) === 0) {
        return [false, '', 'No race results links found on ESPN year page.', $debug];
    }

    $last = $races[count($races) - 1];
    $debug['latestUrl'] = (string)$last['race_url'];
    $debug['latestRaceId'] = (string)$last['race_id'];

    return [true, (string)$last['race_url'], '', $debug];
}


function rr_schedule_source_url(int $year): string
{
    return 'https://www.espn.com/racing/schedule/_/year/' . (string)$year;
}

function rr_clean_schedule_text(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = strip_tags($value);
    $value = str_replace("\xc2\xa0", ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value);
}

function rr_schedule_cell_parts(DOMElement $cell): array
{
    $parts = [];

    foreach ($cell->childNodes as $child) {
        $txt = rr_clean_schedule_text((string)$child->textContent);
        if ($txt === '') continue;
        if (!in_array($txt, $parts, true)) {
            $parts[] = $txt;
        }
    }

    if (empty($parts)) {
        $txt = rr_clean_schedule_text((string)$cell->textContent);
        if ($txt !== '') $parts[] = $txt;
    }

    return $parts;
}

function rr_schedule_normalize_time_text(string $timeText): string
{
    $timeText = rr_clean_schedule_text($timeText);
    if ($timeText === '') return '';

    if (preg_match('/\b(TBD|TBA)\b/i', $timeText, $m)) {
        return strtoupper((string)$m[1]);
    }

    if (preg_match('/(\d{1,2}:\d{2}\s*[AP]M|\d{1,2}\s*[AP]M)\s*(?:ET)?/i', $timeText, $m)) {
        $t = strtoupper(preg_replace('/\s+/', ' ', trim((string)$m[1])));
        $t = preg_replace('/(\d)(AM|PM)$/', '$1 $2', (string)$t);
        return $t . ' ET';
    }

    return $timeText;
}

function rr_schedule_extract_date_time_from_text(string $text, int $year): array
{
    $text = rr_clean_schedule_text($text);

    $out = [
        'date_text' => '',
        'time_text' => '',
    ];

    $months = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';
    $days = 'Mon|Tue|Wed|Thu|Fri|Sat|Sun';

    // ESPN schedule text can collapse the day and time together, for example:
    //   Sun, Jun 73:00 PM ET  => Sun, Jun 7 / 3:00 PM ET
    //   Sun, May 317:00 PM ET => Sun, May 31 / 7:00 PM ET
    if (preg_match('/\b(' . $days . ')\s*,?\s*(' . $months . ')\s+([0-9]{2,4}:\d{2}\s*[AP]M|[0-9]{2,4}\s*[AP]M|TBD|TBA)/i', $text, $m)) {
        $dow = ucfirst(strtolower((string)$m[1]));
        $mon = ucfirst(strtolower((string)$m[2]));
        $joined = strtoupper(preg_replace('/\s+/', '', (string)$m[3]));

        if (preg_match('/^(\d+)(:\d{2})?(AM|PM)$/i', $joined, $tm)) {
            $digits = (string)$tm[1];
            $minutePart = isset($tm[2]) ? (string)$tm[2] : '';
            $ampm = strtoupper((string)$tm[3]);
            $len = strlen($digits);

            $bestDay = 0;
            $bestHour = 0;

            for ($dayDigits = 1; $dayDigits <= 2; $dayDigits++) {
                if ($len <= $dayDigits) continue;
                $day = (int)substr($digits, 0, $dayDigits);
                $hour = (int)substr($digits, $dayDigits);
                if ($day < 1 || $day > 31 || $hour < 1 || $hour > 12) continue;

                // Prefer a two-digit day when both interpretations are possible.
                if ($dayDigits === 2 || $bestDay === 0) {
                    $bestDay = $day;
                    $bestHour = $hour;
                }
            }

            if ($bestDay >= 1 && $bestHour >= 1) {
                $out['date_text'] = $dow . ', ' . $mon . ' ' . (string)$bestDay;
                $out['time_text'] = rr_schedule_normalize_time_text((string)$bestHour . ($minutePart !== '' ? $minutePart : ':00') . ' ' . $ampm . ' ET');
                return $out;
            }
        } elseif (preg_match('/^(TBD|TBA)$/i', $joined)) {
            $out['date_text'] = $dow . ', ' . $mon;
            $out['time_text'] = strtoupper($joined);
            return $out;
        }
    }

    // Normal spaced form.
    if (preg_match('/\b(' . $days . ')\s*,?\s*(' . $months . ')\s+([0-3]?\d)\s*(?:(TBD|TBA|\d{1,2}:\d{2}\s*[AP]M|\d{1,2}\s*[AP]M)\s*(?:ET)?)?/i', $text, $m)) {
        $dow = ucfirst(strtolower((string)$m[1]));
        $mon = ucfirst(strtolower((string)$m[2]));
        $day = (int)$m[3];
        if ($day >= 1 && $day <= 31) {
            $out['date_text'] = $dow . ', ' . $mon . ' ' . (string)$day;
            if (isset($m[4]) && trim((string)$m[4]) !== '') {
                $out['time_text'] = rr_schedule_normalize_time_text((string)$m[4]);
            }
        }
    }

    return $out;
}

function rr_schedule_parse_date_time(string $dateText, string $timeText, int $year): array
{
    $dateText = rr_clean_schedule_text($dateText);
    $timeText = rr_schedule_normalize_time_text($timeText);

    $out = [
        'date_text' => $dateText,
        'time_text' => $timeText,
        'start_at' => '',
        'start_ts' => 0,
        'parse_status' => 'not_parsed',
    ];

    if ($dateText === '') {
        return $out;
    }

    $datePart = preg_replace('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+/i', '', $dateText);
    $datePart = trim((string)$datePart);
    if ($datePart === '') {
        return $out;
    }

    $timePart = preg_replace('/\bET\b|\bEST\b|\bEDT\b/i', '', (string)$timeText);
    $timePart = trim((string)$timePart);

    if ($timePart === '' || preg_match('/\bTBD\b|\bTBA\b/i', $timePart)) {
        $candidate = $datePart . ' ' . (string)$year;
        $formats = ['M j Y', 'M d Y', 'F j Y', 'F d Y'];
    } else {
        $candidate = $datePart . ' ' . (string)$year . ' ' . $timePart;
        $formats = ['M j Y g:i A', 'M d Y g:i A', 'F j Y g:i A', 'F d Y g:i A', 'M j Y g A', 'M d Y g A', 'F j Y g A', 'F d Y g A'];
    }

    $tz = new DateTimeZone('America/New_York');
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $candidate, $tz);
        if ($dt instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0)) {
                continue;
            }
            $out['start_at'] = $dt->format('Y-m-d H:i:s');
            $out['start_ts'] = (int)$dt->getTimestamp();
            $out['parse_status'] = ($timePart === '' || preg_match('/\bTBD\b|\bTBA\b/i', $timeText)) ? 'date_only' : 'date_time';
            return $out;
        }
    }

    return $out;
}

function rr_schedule_race_id_from_url(string $url): string
{
    $id = rr_extract_race_id_from_url($url);
    if ($id !== 'unknown') return $id;

    if (preg_match('~/id/(\d+)~', $url, $m)) return (string)$m[1];
    if (preg_match('~/raceId/(\d+)~', $url, $m)) return (string)$m[1];

    return '';
}

function rr_schedule_is_non_race_action_text(string $text): bool
{
    $text = rr_clean_schedule_text($text);
    if ($text === '') return true;

    return (bool)preg_match('/^(Starting Grid|Grid|Race Results|Results|Tickets?|Buy Tickets|Watch|Preview)$/i', $text);
}

function rr_schedule_clean_event_text(string $text): string
{
    $text = rr_clean_schedule_text($text);
    $text = preg_replace('/\b(Starting Grid|Grid|Race Results|Results|Tickets?|Buy Tickets)\b/i', ' ', $text);
    $text = preg_replace('/\b(FOX|FS1|NBC|USA|TNT|Prime|CW|Amazon Prime|ESPN)\b/i', ' ', (string)$text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    return trim((string)$text);
}

function rr_schedule_split_event_and_track(string $eventText): array
{
    $eventText = rr_schedule_clean_event_text($eventText);
    $raceName = $eventText;
    $trackName = '';

    if ($eventText === '') {
        return ['', ''];
    }

    $trackSuffix = '(?:International\s+Speedway|Motor\s+Speedway|Superspeedway|Speedway|Raceway|Road\s+Course|Circuit|Base\s+Coronado)';

    if (preg_match('/^(NASCAR\s+(?:Cup|Xfinity|Truck)\s+Series\s+at\s+.+?)([A-Z][A-Za-z0-9 .&\'\-]+\s+' . $trackSuffix . ')$/', $eventText, $m)) {
        $raceName = trim((string)$m[1]);
        $trackName = trim((string)$m[2]);
    }

    // Clean accidental duplicate display names such as "MichiganMichigan International Speedway" when a track split was not possible.
    $raceName = preg_replace('/\b([A-Z][A-Za-z .&\'\-]{2,})\1\b/', '$1', (string)$raceName);
    $raceName = preg_replace('/\s+/', ' ', (string)$raceName);
    $trackName = preg_replace('/\s+/', ' ', (string)$trackName);

    return [trim((string)$raceName), trim((string)$trackName)];
}

function rr_schedule_series_from_race_name(string $raceName): string
{
    if (preg_match('/\bNASCAR\s+Cup\s+Series\b/i', $raceName)) return 'NASCAR Cup Series';
    if (preg_match('/\bNASCAR\s+Xfinity\s+Series\b/i', $raceName)) return 'NASCAR Xfinity Series';
    if (preg_match('/\bNASCAR\s+Truck\s+Series\b/i', $raceName)) return 'NASCAR Truck Series';
    return '';
}

function rr_schedule_short_name(string $raceName): string
{
    $short = preg_replace('/^NASCAR\s+Cup\s+Series\s+at\s+/i', '', $raceName);
    $short = preg_replace('/^NASCAR\s+Xfinity\s+Series\s+at\s+/i', '', (string)$short);
    $short = preg_replace('/^NASCAR\s+Truck\s+Series\s+at\s+/i', '', (string)$short);
    $short = preg_replace('/\s+/', ' ', (string)$short);
    return trim((string)$short);
}

function rr_schedule_find_known_special_event(array $flat, string $rowText): array
{
    $combined = rr_clean_schedule_text($rowText . ' ' . implode(' ', $flat));

    // ESPN schedule rows for the Daytona 500 can include action links such as
    // "Starting Grid" before/near the real race title. Preserve the actual race
    // title so it can match the existing R01 Daytona folder/year-index entry.
    if (preg_match('/\bDaytona\s+500\b/i', $combined)) {
        $track = '';
        if (preg_match('/\bDaytona\s+International\s+Speedway\b/i', $combined, $m)) {
            $track = 'Daytona International Speedway';
        }
        return ['Daytona 500', $track];
    }

    return ['', ''];
}

function rr_schedule_apply_known_source_corrections(array $race, int $year, string $rowText): array
{
    // Intentionally no current source corrections.
    // ESPN corrected the 2026 San Diego schedule row/name, so MRL now uses the
    // schedule feed directly instead of overriding that race locally.
    return $race;
}

function rr_schedule_pick_event_text(array $cellParts, string $rowText): array
{
    $flat = [];
    foreach ($cellParts as $parts) {
        if (!is_array($parts)) continue;
        foreach ($parts as $part) {
            $part = rr_clean_schedule_text((string)$part);
            if ($part === '') continue;
            if (!in_array($part, $flat, true)) $flat[] = $part;
        }
    }

    [$knownRace, $knownTrack] = rr_schedule_find_known_special_event($flat, $rowText);
    if ($knownRace !== '') {
        return [$knownRace, $knownTrack];
    }

    for ($i = 0; $i < count($flat); $i++) {
        $part = (string)$flat[$i];
        if (rr_schedule_is_non_race_action_text($part)) continue;
        if (preg_match('/\bNASCAR\s+(Cup|Xfinity|Truck)\s+Series\s+at\b/i', $part)) {
            $track = '';
            if (isset($flat[$i + 1])) {
                $maybeTrack = rr_clean_schedule_text((string)$flat[$i + 1]);
                if ($maybeTrack !== '' && !rr_schedule_is_non_race_action_text($maybeTrack) && !preg_match('/\b(TBD|TBA|\d{1,2}:\d{2}\s*[AP]M|\d{1,2}\s*[AP]M)\b/i', $maybeTrack)) {
                    $track = $maybeTrack;
                }
            }
            return [$part, $track];
        }
    }

    $clean = rr_schedule_clean_event_text($rowText);
    if (preg_match('/(NASCAR\s+(?:Cup|Xfinity|Truck)\s+Series\s+at\s+.+)$/i', $clean, $m)) {
        [$race, $track] = rr_schedule_split_event_and_track((string)$m[1]);
        return [$race, $track];
    }

    return ['', ''];
}

function rr_parse_year_schedule_races(string $html, int $year): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    if (!$loaded) return [];

    $xp = new DOMXPath($dom);
    $rows = $xp->query('//tr[td]');
    if (!$rows || $rows->length === 0) return [];

    $out = [];
    $seen = [];
    $scheduleSequence = 0;

    for ($i = 0; $i < $rows->length; $i++) {
        $row = $rows->item($i);
        if (!$row instanceof DOMElement) continue;

        $cells = $xp->query('./td', $row);
        if (!$cells || $cells->length < 2) continue;

        $cellTexts = [];
        $cellParts = [];
        for ($c = 0; $c < $cells->length; $c++) {
            $cell = $cells->item($c);
            if (!$cell instanceof DOMElement) continue;
            $parts = rr_schedule_cell_parts($cell);
            $cellParts[] = $parts;
            $cellTexts[] = rr_clean_schedule_text(implode(' ', $parts));
        }

        $rowText = rr_clean_schedule_text(implode(' ', $cellTexts));
        if ($rowText === '' || !preg_match('/\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)\b|\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b/i', $rowText)) {
            continue;
        }

        $dtText = rr_schedule_extract_date_time_from_text($rowText, $year);
        $dateText = (string)$dtText['date_text'];
        $timeText = (string)$dtText['time_text'];
        if ($dateText === '') continue;

        [$raceNameFromParts, $trackNameFromParts] = rr_schedule_pick_event_text($cellParts, $rowText);
        [$raceName, $trackName] = rr_schedule_split_event_and_track($raceNameFromParts);
        if ($trackName === '' && $trackNameFromParts !== '') {
            $trackName = rr_clean_schedule_text($trackNameFromParts);
        }

        if ($raceName === '') continue;

        $raceUrl = '';
        $links = $xp->query('.//a[@href]', $row);
        if ($links) {
            for ($a = 0; $a < $links->length; $a++) {
                $link = $links->item($a);
                if (!$link instanceof DOMElement) continue;
                $href = trim((string)$link->getAttribute('href'));
                $text = rr_clean_schedule_text((string)$link->textContent);
                if ($href === '') continue;
                if (strpos($href, 'http') !== 0) {
                    $href = 'https://www.espn.com' . $href;
                }
                if (strpos($href, '/racing/') === false) continue;

                // Prefer a result page link; otherwise keep the first useful racing link.
                if (strpos($href, '/raceresults/') !== false) {
                    $raceUrl = $href;
                    break;
                }
                if ($raceUrl === '' && !rr_schedule_is_non_race_action_text($text)) {
                    $raceUrl = $href;
                }
            }
        }

        $series = rr_schedule_series_from_race_name($raceName);
        $isExh = rr_is_exhibition_race_name($raceName);

        // MRL points eligibility should follow the same exhibition-name helper used by the
        // result monitor, not a narrow schedule-title prefix check. Normal Cup rows are
        // eligible, and special Cup titles such as Daytona 500 are eligible because they
        // are not exhibition races. Confirmed year-index R matches later force this true.
        $isMrlPoints = !$isExh && ($series === 'NASCAR Cup Series' || $series === '');

        $dt = rr_schedule_parse_date_time($dateText, $timeText, $year);
        $raceId = rr_schedule_race_id_from_url($raceUrl);
        $dedupeKey = $raceId !== '' ? $raceId : ($dateText . '|' . $timeText . '|' . $raceName);
        if (isset($seen[$dedupeKey])) continue;
        $seen[$dedupeKey] = true;
        $scheduleSequence++;

        $raceRow = [
            'year' => $year,
            'schedule_sequence' => $scheduleSequence,
            'date_text' => $dateText,
            'time_text' => $timeText,
            'start_at' => (string)$dt['start_at'],
            'start_ts' => (int)$dt['start_ts'],
            'start_parse_status' => (string)$dt['parse_status'],
            'race_name' => $raceName,
            'short_name' => rr_schedule_short_name($raceName),
            'track_name' => $trackName,
            'series' => $series,
            'race_url' => $raceUrl,
            'race_id' => $raceId,
            'is_exhibition' => $isExh,
            'mrl_points_eligible' => $isMrlPoints,
            'mrl_race_number' => null,
            'mrl_race_code' => '',
            'race_number' => null,
        ];

        $raceRow = rr_schedule_apply_known_source_corrections($raceRow, $year, $rowText);
        $out[] = $raceRow;
    }

    return $out;
}

function rr_schedule_date_from_race_id(string $raceId): string
{
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $raceId, $m)) {
        return '';
    }
    return $m[1] . '-' . $m[2] . '-' . $m[3];
}

function rr_schedule_date_from_start_at(string $startAt): string
{
    $startAt = trim($startAt);
    if ($startAt === '') return '';
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\b/', $startAt, $m)) {
        return $m[1];
    }
    return '';
}

function rr_schedule_identity_name_key(string $name): string
{
    $name = rr_schedule_short_name($name);
    $name = strtolower($name);
    $name = preg_replace('/[^a-z0-9]+/', '', $name);
    return (string)$name;
}

function rr_schedule_identity_key(string $date, string $name): string
{
    $nameKey = rr_schedule_identity_name_key($name);
    if ($date === '' || $nameKey === '') return '';
    return $date . '|' . $nameKey;
}

function rr_schedule_annotate_mrl_race_numbers(array $scheduleRaces, array $yearIndex): array
{
    $byRaceId = [];
    $byDateName = [];
    $byDateOnly = [];
    $dateCounts = [];
    $maxR = 0;
    $latestKnownRDate = '';
    $latestKnownRTs = 0;

    $idxRaces = isset($yearIndex['races']) && is_array($yearIndex['races']) ? $yearIndex['races'] : [];
    foreach ($idxRaces as $raceId => $row) {
        if (!is_array($row)) continue;
        if ((string)($row['kind'] ?? '') !== 'R') continue;

        $num = (int)($row['number'] ?? 0);
        if ($num <= 0) continue;
        if ($num > $maxR) $maxR = $num;

        $raceId = (string)$raceId;
        $raceDate = rr_schedule_date_from_race_id($raceId);
        $raceName = (string)($row['race_name'] ?? '');
        $raceInfo = [
            'mrl_race_number' => $num,
            'mrl_race_code' => 'R' . str_pad((string)$num, 2, '0', STR_PAD_LEFT),
            'mrl_race_folder' => (string)($row['folder'] ?? ''),
            'mrl_race_name' => $raceName,
            'mrl_race_url' => (string)($row['race_url'] ?? ''),
            'mrl_race_date' => $raceDate,
        ];

        if ($raceId !== '') {
            $byRaceId[$raceId] = $raceInfo;
        }

        if ($raceDate !== '') {
            $key = rr_schedule_identity_key($raceDate, $raceName);
            if ($key !== '') {
                $byDateName[$key] = $raceInfo;
            }
            if (!isset($dateCounts[$raceDate])) $dateCounts[$raceDate] = 0;
            $dateCounts[$raceDate]++;
            $byDateOnly[$raceDate] = $raceInfo;

            $ts = strtotime($raceDate . ' 00:00:00');
            if ($ts !== false && (int)$ts > $latestKnownRTs) {
                $latestKnownRTs = (int)$ts;
                $latestKnownRDate = $raceDate;
            }
        }
    }

    $out = [];
    foreach ($scheduleRaces as $idx => $race) {
        if (!is_array($race)) continue;

        $race['mrl_race_number'] = isset($race['mrl_race_number']) ? $race['mrl_race_number'] : null;
        $race['mrl_race_code'] = (string)($race['mrl_race_code'] ?? '');
        $race['mrl_race_folder'] = (string)($race['mrl_race_folder'] ?? '');
        $race['mrl_identity_source'] = (string)($race['mrl_identity_source'] ?? '');
        $race['race_number'] = null; // Backward-compat field, only populated when it is a true/projected MRL R number.

        $matched = null;
        $raceId = (string)($race['race_id'] ?? '');
        if ($raceId !== '' && isset($byRaceId[$raceId])) {
            $matched = $byRaceId[$raceId];
            $race['mrl_identity_source'] = 'year_index_race_id';
        }

        if ($matched === null) {
            $scheduleDate = rr_schedule_date_from_start_at((string)($race['start_at'] ?? ''));
            $scheduleName = (string)($race['race_name'] ?? '');
            $key = rr_schedule_identity_key($scheduleDate, $scheduleName);
            if ($key !== '' && isset($byDateName[$key])) {
                $matched = $byDateName[$key];
                $race['mrl_identity_source'] = 'year_index_date_name';
            } elseif ($scheduleDate !== '' && isset($byDateOnly[$scheduleDate]) && (int)($dateCounts[$scheduleDate] ?? 0) === 1) {
                // Safe fallback: only use date-only matching when the existing index has exactly one R race on that date.
                $matched = $byDateOnly[$scheduleDate];
                $race['mrl_identity_source'] = 'year_index_date_only';
            }
        }

        if ($matched !== null) {
            $race['mrl_race_number'] = $matched['mrl_race_number'];
            $race['mrl_race_code'] = $matched['mrl_race_code'];
            $race['mrl_race_folder'] = $matched['mrl_race_folder'];
            $race['mrl_race_name'] = $matched['mrl_race_name'];
            $race['mrl_race_url'] = $matched['mrl_race_url'];
            $race['mrl_race_date'] = $matched['mrl_race_date'];
            $race['race_number'] = $race['mrl_race_number'];

            // A confirmed R## folder/year-index match is authoritative. This catches
            // points races with special names like Daytona 500 that do not include the
            // usual "NASCAR Cup Series at ..." wording on ESPN's schedule page.
            $race['is_exhibition'] = false;
            $race['mrl_points_eligible'] = true;
        }

        $out[$idx] = $race;
    }

    $futureIndexes = [];
    foreach ($out as $idx => $race) {
        if (!is_array($race)) continue;
        if (empty($race['mrl_points_eligible'])) continue;
        if (!empty($race['mrl_race_number'])) continue;

        $raceTs = (int)($race['start_ts'] ?? 0);
        if ($latestKnownRTs > 0 && $raceTs > 0) {
            $raceDate = rr_schedule_date_from_start_at((string)($race['start_at'] ?? ''));
            if ($raceDate !== '' && $latestKnownRDate !== '' && strcmp($raceDate, $latestKnownRDate) <= 0) {
                // Past or same-day rows that failed to match the index should not be assigned projected future R numbers.
                $out[$idx]['mrl_identity_source'] = 'unmatched_past_or_known_date';
                continue;
            }
        }

        $futureIndexes[] = $idx;
    }

    usort($futureIndexes, function ($a, $b) use ($out) {
        $aTs = (int)($out[$a]['start_ts'] ?? 0);
        $bTs = (int)($out[$b]['start_ts'] ?? 0);
        if ($aTs > 0 && $bTs > 0 && $aTs !== $bTs) return $aTs <=> $bTs;
        if ($aTs > 0 && $bTs <= 0) return -1;
        if ($aTs <= 0 && $bTs > 0) return 1;
        return (int)($out[$a]['schedule_sequence'] ?? 0) <=> (int)($out[$b]['schedule_sequence'] ?? 0);
    });

    $nextR = $maxR + 1;
    foreach ($futureIndexes as $idx) {
        $out[$idx]['mrl_race_number'] = $nextR;
        $out[$idx]['mrl_race_code'] = 'R' . str_pad((string)$nextR, 2, '0', STR_PAD_LEFT);
        $out[$idx]['mrl_identity_source'] = 'year_index_projected_future_r_sequence';
        $out[$idx]['race_number'] = $nextR;
        $nextR++;
    }

    return array_values($out);
}

function rr_filter_mrl_schedule_points_races(array $scheduleRaces): array
{
    $out = [];
    foreach ($scheduleRaces as $race) {
        if (!is_array($race)) continue;
        if (!empty($race['mrl_points_eligible'])) {
            $out[] = $race;
        }
    }
    return $out;
}

function rr_find_next_scheduled_race(array $scheduleRaces, int $nowTs = 0): array
{
    if ($nowTs <= 0) $nowTs = time();

    $best = [];
    foreach ($scheduleRaces as $race) {
        if (!is_array($race)) continue;
        if (isset($race['mrl_points_eligible']) && empty($race['mrl_points_eligible'])) continue;
        if (!isset($race['mrl_points_eligible']) && !empty($race['is_exhibition'])) continue;
        $ts = (int)($race['start_ts'] ?? 0);
        if ($ts <= 0) continue;

        // Treat a race as still current/upcoming for several hours after scheduled start.
        if ($ts + (8 * 3600) >= $nowTs) {
            if (empty($best) || $ts < (int)($best['start_ts'] ?? PHP_INT_MAX)) {
                $best = $race;
            }
        }
    }

    return $best;
}

function rr_fetch_year_schedule(int $year, int $timeoutSeconds): array
{
    $url = rr_schedule_source_url($year);
    $debug = [
        'year' => $year,
        'schedule_url' => $url,
        'http_status' => 0,
        'html_bytes' => 0,
        'race_count' => 0,
        'mrl_points_race_count' => 0,
        'generated_at' => date('c'),
    ];

    [$ok, $status, $html, $err] = rr_fetch_url($url, $timeoutSeconds);
    $debug['http_status'] = $status;
    $debug['html_bytes'] = strlen($html);

    if (!$ok) {
        return [false, [], 'Failed to fetch schedule page (HTTP ' . $status . '): ' . $err, $debug];
    }

    $races = rr_parse_year_schedule_races($html, $year);
    $debug['race_count'] = count($races);
    $debug['mrl_points_race_count'] = count(rr_filter_mrl_schedule_points_races($races));

    if (count($races) === 0) {
        return [false, [], 'No schedule rows found on schedule page.', $debug];
    }

    return [true, $races, '', $debug];
}

function rr_parse_int_cell(string $s): ?int
{
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = preg_replace('/[^0-9\-]/', '', $s);
    if ($s === '' || $s === '-') return null;
    if (!preg_match('/^-?\d+$/', $s)) return null;
    return (int)$s;
}

function rr_norm_header(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return strtoupper($s);
}

function rr_norm_cell_text(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

/**
 * Stable "data hash" for a DOM table:
 * - iterate rows
 * - take TH/TD textContent
 * - normalize whitespace
 * - hash the resulting normalized grid
 */
function rr_table_data_hash(DOMXPath $xp, DOMElement $table): string
{
    $rows = $xp->query('.//tr', $table);
    if (!$rows || $rows->length === 0) return hash('sha256', '');

    $lines = [];
    for ($r = 0; $r < $rows->length; $r++) {
        $row = $rows->item($r);
        if (!$row instanceof DOMElement) continue;

        $cells = $xp->query('./th|./td', $row);
        if (!$cells || $cells->length === 0) continue;

        $vals = [];
        for ($c = 0; $c < $cells->length; $c++) {
            $vals[] = rr_norm_cell_text((string)$cells->item($c)->textContent);
        }
        $lines[] = implode("\t", $vals);
    }

    return hash('sha256', implode("\n", $lines));
}

/**
 * FINAL detector:
 * - Find scoring table header row (td OR th) that includes PTS/POINTS plus BONUS or PENALTY
 * - Require at least ONE non-zero value in any scoring column
 *
 * Returns: [isFinal(bool), reason(string), details(array)]
 */
function rr_detect_final_scoring_nonzero(string $html): array
{
    $details = [
        'mode' => 'dom_table',
        'tableHash' => '',     // stable DATA hash
        'htmlTableHash' => '', // optional debug hash of raw HTML (not used for gating)
        'rowsChecked' => 0,
        'nonZeroCounts' => ['PTS'=>0,'BONUS'=>0,'PENALTY'=>0],
        'colIndex' => ['PTS'=>null,'BONUS'=>null,'PENALTY'=>null],
        'tablesFound' => 0,
        'headerRowFound' => false,
    ];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    if (!$loaded) return [false, 'DOM load failed.', $details];

    $xp = new DOMXPath($dom);
    $tables = $xp->query('//table');
    $details['tablesFound'] = $tables ? $tables->length : 0;

    if (!$tables || $tables->length === 0) {
        return [false, 'No <table> elements found on page.', $details];
    }

    $bestTable = null;
    $bestHeaders = [];
    $bestHeaderRow = null;

    for ($t = 0; $t < $tables->length; $t++) {
        $tbl = $tables->item($t);
        if (!$tbl instanceof DOMElement) continue;

        $rows = $xp->query('.//tr', $tbl);
        if (!$rows || $rows->length === 0) continue;

        for ($r = 0; $r < $rows->length; $r++) {
            $row = $rows->item($r);
            if (!$row instanceof DOMElement) continue;

            $cells = $xp->query('./th|./td', $row);
            if (!$cells || $cells->length < 5) continue;

            $headers = [];
            for ($i = 0; $i < $cells->length; $i++) {
                $headers[] = rr_norm_header((string)$cells->item($i)->textContent);
            }

            $hasPts = false;
            $hasBonus = false;
            $hasPenalty = false;

            foreach ($headers as $h) {
                if (strpos($h, 'PTS') !== false || strpos($h, 'POINT') !== false) $hasPts = true;
                if (strpos($h, 'BONUS') !== false) $hasBonus = true;
                if (strpos($h, 'PENALTY') !== false) $hasPenalty = true;
            }

            if ($hasPts && ($hasBonus || $hasPenalty)) {
                $bestTable = $tbl;
                $bestHeaderRow = $row;
                $bestHeaders = $headers;
                break 2;
            }
        }
    }

    if (!$bestTable || !$bestHeaderRow) {
        return [false, 'Could not locate a scoring-style table (PTS/POINTS with BONUS/PENALTY).', $details];
    }

    $details['headerRowFound'] = true;

    // DATA hash (stable for gating)
    $details['tableHash'] = rr_table_data_hash($xp, $bestTable);

    // Optional debug hash of raw HTML (NOT used for gating)
    $tableHtml = $dom->saveHTML($bestTable) ?: '';
    if ($tableHtml !== '') $details['htmlTableHash'] = hash('sha256', $tableHtml);

    $idxPTS = null; $idxBON = null; $idxPEN = null;
    for ($i = 0; $i < count($bestHeaders); $i++) {
        $h = $bestHeaders[$i];
        if ($idxPTS === null && (strpos($h, 'PTS') !== false || strpos($h, 'POINT') !== false)) $idxPTS = $i;
        if ($idxBON === null && strpos($h, 'BONUS') !== false) $idxBON = $i;
        if ($idxPEN === null && strpos($h, 'PENALTY') !== false) $idxPEN = $i;
    }

    $details['colIndex'] = ['PTS'=>$idxPTS,'BONUS'=>$idxBON,'PENALTY'=>$idxPEN];

    if ($idxPTS === null && $idxBON === null && $idxPEN === null) {
        return [false, 'Scoring table found, but could not map scoring columns.', $details];
    }

    $rows = $xp->query('.//tr[td]', $bestTable);
    if (!$rows || $rows->length === 0) {
        return [false, 'Scoring table found, but no data rows found.', $details];
    }

    $headerSeen = false;

    for ($r = 0; $r < $rows->length; $r++) {
        $row = $rows->item($r);
        if (!$row instanceof DOMElement) continue;

        if ($row->isSameNode($bestHeaderRow)) {
            $headerSeen = true;
            continue;
        }
        if (!$headerSeen) continue;

        $tds = $xp->query('./td', $row);
        if (!$tds || $tds->length === 0) continue;

        // Require first cell to contain a position number
        $firstCell = trim((string)$tds->item(0)->textContent);
        $posDigits = preg_replace('/\D+/', '', $firstCell);
        if ($posDigits === '' || !preg_match('/^\d+$/', $posDigits)) continue;

        $details['rowsChecked']++;

        $readAt = function(?int $idx) use ($tds): ?int {
            if ($idx === null) return null;
            if ($idx < 0 || $idx >= $tds->length) return null;
            return rr_parse_int_cell((string)$tds->item($idx)->textContent);
        };

        if ($idxPTS !== null) { $v = $readAt($idxPTS); if ($v !== null && $v !== 0) $details['nonZeroCounts']['PTS']++; }
        if ($idxBON !== null) { $v = $readAt($idxBON); if ($v !== null && $v !== 0) $details['nonZeroCounts']['BONUS']++; }
        if ($idxPEN !== null) { $v = $readAt($idxPEN); if ($v !== null && $v !== 0) $details['nonZeroCounts']['PENALTY']++; }

        if ($details['nonZeroCounts']['PTS'] > 0 || $details['nonZeroCounts']['BONUS'] > 0 || $details['nonZeroCounts']['PENALTY'] > 0) {
            return [true, 'Non-zero scoring detected in scoring table.', $details];
        }

        if ($details['rowsChecked'] >= 250) break;
    }

    return [false, 'Scoring table found, but all scoring values are still zero.', $details];
}

function rr_ensure_dir(string $dir): bool
{
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0775, true);
}

function rr_write_meta(string $raceFolder, array $meta): void
{
    rr_ensure_dir($raceFolder);
    rr_save_json($raceFolder . '/_meta.json', $meta);
}

function rr_save_snapshot_html(string $raceFolder, string $timestampForFilename, string $html, int $maxBytes): string
{
    rr_ensure_dir($raceFolder);

    if (strlen($html) > $maxBytes) {
        $html = substr($html, 0, $maxBytes);
    }

    $path = $raceFolder . '/snapshot_' . $timestampForFilename . '.html';
    rr_atomic_write($path, $html);
    return $path;
}

function rr_save_snapshot_summary(string $raceFolder, string $timestampForFilename, string $html): string
{
    rr_ensure_dir($raceFolder);

    $hasNext = (preg_match('~id=["\']__NEXT_DATA__["\']~i', $html) === 1);
    preg_match_all('~<table\b~i', $html, $mTbl);
    $tables = is_array($mTbl) ? count($mTbl[0]) : 0;

    $needles = ['PTS','POINTS','BONUS','PENALTY'];
    $found = [];
    foreach ($needles as $n) {
        $found[$n] = (stripos($html, $n) !== false) ? 'YES' : 'NO';
    }

    $out = [];
    $out[] = "RACE RESULTS SNAPSHOT SUMMARY (spoiler-safe)";
    $out[] = "Generated: " . rr_now_local_string();
    $out[] = "HTML bytes: " . strlen($html);
    $out[] = "HTML sha256: " . hash('sha256', $html);
    $out[] = "__NEXT_DATA__ present: " . ($hasNext ? 'YES' : 'NO');
    $out[] = "<table> tags found: " . $tables;
    $out[] = "Raw token presence:";
    foreach ($found as $k => $v) {
        $out[] = "  {$k}: {$v}";
    }
    $out[] = "";

    $path = $raceFolder . '/snapshot_summary_' . $timestampForFilename . '.txt';
    rr_atomic_write($path, implode("\n", $out));
    return $path;
}
