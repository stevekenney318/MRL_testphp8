<?php
declare(strict_types=1);

/**
 * race_results_engine.php
 *
 * VERSION: v1.03.00.02
 * LAST MODIFIED: 2026-02-28
 * BUILD TS: 20260228.060413
 *
 * CHANGELOG:
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

/**
 * Fetch URL via cURL.
 * Returns: [ok(bool), httpStatus(int), body(string), error(string)]
 */
function rr_fetch_url(string $url, int $timeoutSeconds): array
{
    $ch = curl_init();
    if ($ch === false) return [false, 0, '', 'cURL init failed'];

    // Cache-buster
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

    // PHP 7.3 compatible:
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($body === false || $body === null) return [false, $code, '', $err ?: 'Unknown fetch error'];
    if ($code >= 400 || $code === 0) return [false, $code, (string)$body, $err ?: ("HTTP error " . $code)];
    return [true, $code, (string)$body, ''];
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