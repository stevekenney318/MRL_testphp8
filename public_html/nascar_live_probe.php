<?php
/**
 * nascar_live_probe.php
 * NASCAR Live Probe for MRL
 * VERSION: v001
 * LAST MODIFIED: 6/21/2026 5:16:25 pm
 *
 * Purpose:
 * - Test whether the public NASCAR live leaderboard page can be reached automatically.
 * - Check whether the useful live leaderboard/dashboard/raw-feed text is visible in server-fetched HTML.
 * - Try several public NASCAR JSON/API discovery paths to see whether a race_id can be found without DevTools.
 * - Build and test likely live-feed JSON URLs when a race_id is discovered.
 *
 * Notes:
 * - This is a diagnostic/probe file, not a production monitor.
 * - Safe to run from a browser or command line.
 * - PHP 7.3 compatible.
 */

@date_default_timezone_set('America/New_York');

$CONFIG = array(
    'season' => 2026,
    'series_id' => 1, // 1 = Cup Series in most NASCAR feed URLs
    'target_race_name' => 'Anduril 250',
    'target_date' => '2026-06-21',
    'known_live_url' => 'https://www.nascar.com/live-results/nascar-cup-series/anduril-250/',
    'leaderboard_redirect_url' => 'https://www.nascar.com/leaderboard',
    'schedule_url' => 'https://www.nascar.com/nascar-cup-series/2026/schedule/',
    'timeout_seconds' => 20,
    'max_body_preview_chars' => 900,
    'max_json_hits' => 12,
    'max_id_candidates' => 30,
);

$is_cli = (PHP_SAPI === 'cli');

function h($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function out($text = '') {
    global $is_cli;
    if ($is_cli) {
        echo $text . PHP_EOL;
    } else {
        echo h($text) . "\n";
    }
}

function out_raw($text = '') {
    echo $text;
}

function section_title($title) {
    out('');
    out('============================================================');
    out($title);
    out('============================================================');
}

function status_line($label, $ok, $details = '') {
    $mark = $ok ? '[OK]' : '[--]';
    out($mark . ' ' . $label . ($details !== '' ? ' — ' . $details : ''));
}

function fetch_url($url, $timeout_seconds) {
    $result = array(
        'url' => $url,
        'effective_url' => $url,
        'status' => 0,
        'content_type' => '',
        'body' => '',
        'headers' => '',
        'error' => '',
        'ok' => false,
    );

    if (!function_exists('curl_init')) {
        $context = stream_context_create(array(
            'http' => array(
                'timeout' => $timeout_seconds,
                'method' => 'GET',
                'header' => "User-Agent: MRL NASCAR Probe/1.0\r\nAccept: text/html,application/json,text/plain,*/*\r\n",
                'follow_location' => 1,
            ),
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true,
            ),
        ));
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $result['error'] = 'file_get_contents failed and cURL is not available.';
            return $result;
        }
        $result['body'] = $body;
        $result['status'] = 200;
        $result['ok'] = true;
        return $result;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout_seconds,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MRL NASCAR Probe/1.0; +https://manliusracingleague.com/)',
        CURLOPT_HTTPHEADER => array(
            'Accept: text/html,application/json,text/plain,*/*',
            'Cache-Control: no-cache',
        ),
    ));

    $response = curl_exec($ch);
    if ($response === false) {
        $result['error'] = curl_error($ch);
        curl_close($ch);
        return $result;
    }

    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $result['headers'] = substr($response, 0, $header_size);
    $result['body'] = substr($response, $header_size);
    $result['status'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $result['effective_url'] = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $result['content_type'] = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $result['ok'] = ($result['status'] >= 200 && $result['status'] < 300 && $result['body'] !== '');
    curl_close($ch);

    return $result;
}

function body_has($body, $needle) {
    return stripos($body, $needle) !== false;
}

function preview_text($body, $limit) {
    $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $body);
    $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', trim($text));
    if (strlen($text) > $limit) {
        $text = substr($text, 0, $limit) . '...';
    }
    return $text;
}

function normalize_url($href, $base_url) {
    $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
    if ($href === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $href)) {
        return $href;
    }
    if (strpos($href, '//') === 0) {
        return 'https:' . $href;
    }
    $parts = parse_url($base_url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return $href;
    }
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (strpos($href, '/') === 0) {
        return $origin . $href;
    }
    $path = isset($parts['path']) ? $parts['path'] : '/';
    $dir = preg_replace('/\/[^\/]*$/', '/', $path);
    return $origin . $dir . $href;
}

function extract_live_leaderboard_links($html, $base_url) {
    $links = array();
    if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $attrs = $m[1];
            $label = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8')));
            if (stripos($label, 'Live Leaderboard') === false && stripos($label, 'Leaderboard') === false) {
                continue;
            }
            if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/is', $attrs, $hm)) {
                $url = normalize_url($hm[2], $base_url);
                if ($url !== '') {
                    $links[$url] = $label;
                }
            }
        }
    }
    return $links;
}

function extract_id_candidates_from_text($text, $max) {
    $ids = array();
    $patterns = array(
        '/\b(?:race_id|raceId|raceID|RaceID|raceIdNumber|event_id|eventId|run_id|runId)\b\s*[=:]\s*["\']?(\d{3,6})["\']?/i',
        '/["\'](?:race_id|raceId|raceID|RaceID|event_id|eventId|run_id|runId)["\']\s*:\s*["\']?(\d{3,6})["\']?/i',
        '/\bseries_1\/(\d{3,6})\//i',
        '/\bcacher\/[0-9]{4}\/1\/(\d{3,6})\//i',
    );
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[(string)$id] = true;
                if (count($ids) >= $max) {
                    break 2;
                }
            }
        }
    }
    return array_keys($ids);
}

function json_decode_safe($body) {
    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    return $decoded;
}

function flatten_for_search($value) {
    if (is_array($value)) {
        $parts = array();
        foreach ($value as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $parts[] = $k . '=' . (string)$v;
            } elseif (is_array($v)) {
                $nested = flatten_for_search($v);
                if ($nested !== '') {
                    $parts[] = $k . '={' . $nested . '}';
                }
            }
        }
        return implode(' | ', $parts);
    }
    return is_scalar($value) ? (string)$value : '';
}

function collect_json_hits($value, $terms, $path, &$hits, $max_hits) {
    if (count($hits) >= $max_hits) {
        return;
    }
    if (!is_array($value)) {
        return;
    }

    $blob = strtolower(flatten_for_search($value));
    $matched = false;
    foreach ($terms as $term) {
        $term = strtolower(trim($term));
        if ($term !== '' && strpos($blob, $term) !== false) {
            $matched = true;
            break;
        }
    }

    if ($matched) {
        $ids = array();
        foreach ($value as $k => $v) {
            if (preg_match('/race.*id|race_id|raceid|event.*id|run.*id|id/i', (string)$k) && (is_scalar($v) || $v === null)) {
                $ids[$k] = $v;
            }
        }
        $hits[] = array(
            'path' => $path,
            'ids' => $ids,
            'preview' => substr(flatten_for_search($value), 0, 700),
        );
        if (count($hits) >= $max_hits) {
            return;
        }
    }

    foreach ($value as $k => $v) {
        if (is_array($v)) {
            collect_json_hits($v, $terms, $path . '/' . $k, $hits, $max_hits);
            if (count($hits) >= $max_hits) {
                return;
            }
        }
    }
}

function collect_numeric_ids_from_hits($hits) {
    $ids = array();
    foreach ($hits as $hit) {
        foreach ($hit['ids'] as $k => $v) {
            if (is_numeric($v) && (int)$v > 100) {
                $key = (string)((int)$v);
                $ids[$key] = true;
            }
        }
        if (preg_match_all('/\b(?:race_id|raceId|RaceID|raceid|id)\s*=\s*(\d{3,6})\b/i', $hit['preview'], $m)) {
            foreach ($m[1] as $id) {
                $ids[(string)((int)$id)] = true;
            }
        }
    }
    return array_keys($ids);
}

function summarize_json_shape($decoded) {
    if (!is_array($decoded)) {
        return 'not an array/object';
    }
    $keys = array_keys($decoded);
    $sample = array_slice($keys, 0, 10);
    return 'top keys: ' . implode(', ', $sample);
}

if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

out('MRL NASCAR Live Probe');
out('Generated: ' . date('n/j/Y g:i:s a T'));
out('PHP: ' . PHP_VERSION);
out('Target race: ' . $CONFIG['target_race_name'] . ' / ' . $CONFIG['target_date']);

section_title('1) Check NASCAR /leaderboard redirect');
$leaderboard = fetch_url($CONFIG['leaderboard_redirect_url'], $CONFIG['timeout_seconds']);
status_line('Fetch ' . $CONFIG['leaderboard_redirect_url'], $leaderboard['ok'], 'HTTP ' . $leaderboard['status']);
out('Effective URL: ' . $leaderboard['effective_url']);
if ($leaderboard['error'] !== '') {
    out('Error: ' . $leaderboard['error']);
}
status_line('Redirect landed on a live-results page', stripos($leaderboard['effective_url'], '/live-results/') !== false, $leaderboard['effective_url']);
status_line('Page mentions target race name', body_has($leaderboard['body'], $CONFIG['target_race_name']), $CONFIG['target_race_name']);
status_line('Page mentions Live Leaderboard', body_has($leaderboard['body'], 'Live Leaderboard'));
status_line('Page mentions Raw Feed', body_has($leaderboard['body'], 'Raw Feed'));
status_line('Page mentions Dashboard', body_has($leaderboard['body'], 'Dashboard'));
status_line('Page includes leaderboard-style POS/Driver text', body_has($leaderboard['body'], 'POS') && body_has($leaderboard['body'], 'Driver'));

$found_links = extract_live_leaderboard_links($leaderboard['body'], $leaderboard['effective_url']);
out('Live/leaderboard-ish links found in redirected page: ' . count($found_links));
foreach ($found_links as $url => $label) {
    out(' - ' . $label . ' => ' . $url);
}

section_title('2) Check direct live-results URL you found');
$direct = fetch_url($CONFIG['known_live_url'], $CONFIG['timeout_seconds']);
status_line('Fetch direct URL', $direct['ok'], 'HTTP ' . $direct['status']);
out('Effective URL: ' . $direct['effective_url']);
status_line('Direct page mentions target race name', body_has($direct['body'], $CONFIG['target_race_name']), $CONFIG['target_race_name']);
status_line('Direct page mentions Raw Feed', body_has($direct['body'], 'Raw Feed'));
status_line('Direct page mentions Dashboard', body_has($direct['body'], 'Dashboard'));
status_line('Direct page includes NASCAR leaderboard columns', body_has($direct['body'], 'Delta Leader') || body_has($direct['body'], 'Best Lap') || body_has($direct['body'], 'Laps Led'));
out('Direct page text preview:');
out(preview_text($direct['body'], $CONFIG['max_body_preview_chars']));

section_title('3) Check schedule page raw fetch');
$schedule = fetch_url($CONFIG['schedule_url'], $CONFIG['timeout_seconds']);
status_line('Fetch schedule page', $schedule['ok'], 'HTTP ' . $schedule['status']);
status_line('Schedule raw HTML says Loading race information', body_has($schedule['body'], 'Loading race information'));
status_line('Schedule raw HTML directly contains target race name', body_has($schedule['body'], $CONFIG['target_race_name']), $CONFIG['target_race_name']);
$status_note = body_has($schedule['body'], 'Loading race information') ? 'This means the schedule is probably JavaScript-fed, so raw PHP may not see all schedule items.' : '';
if ($status_note !== '') {
    out($status_note);
}

section_title('4) Try race_id clues from HTML');
$id_candidates = array();
foreach (array($leaderboard['body'], $direct['body'], $schedule['body']) as $body) {
    foreach (extract_id_candidates_from_text($body, $CONFIG['max_id_candidates']) as $id) {
        $id_candidates[$id] = true;
    }
}
$id_candidates = array_keys($id_candidates);
if (count($id_candidates) === 0) {
    out('No race_id-style number found directly in the server-fetched HTML.');
} else {
    out('race_id-style candidates found in HTML: ' . implode(', ', $id_candidates));
}

section_title('5) Try public NASCAR feed/API discovery URLs');
$terms = array($CONFIG['target_race_name'], $CONFIG['target_date'], 'anduril', 'coronado', 'san diego');
$api_urls = array(
    'https://feed.nascar.com/api/races?series_id=' . $CONFIG['series_id'] . '&season=' . $CONFIG['season'],
    'https://feed.nascar.com/api/races?SeriesID=' . $CONFIG['series_id'] . '&RaceSeason=' . $CONFIG['season'],
    'https://feed.nascar.com/api/racelist?series_id=' . $CONFIG['series_id'] . '&season=' . $CONFIG['season'],
    'https://feed.nascar.com/api/racelist?SeriesID=' . $CONFIG['series_id'] . '&RaceSeason=' . $CONFIG['season'],
    'https://feed.nascar.com/api/LiveFeed',
    'https://feed.nascar.com/api/LiveFlag',
    'https://feed.nascar.com/api/LivePoints',
    'https://cf.nascar.com/live/feeds/live-feed.json',
    'https://cf.nascar.com/cacher/live/live-feed.json',
);

$json_hits_all = array();
foreach ($api_urls as $url) {
    $r = fetch_url($url, $CONFIG['timeout_seconds']);
    $short_type = $r['content_type'] !== '' ? $r['content_type'] : 'unknown content type';
    status_line('Fetch ' . $url, $r['ok'], 'HTTP ' . $r['status'] . ', ' . $short_type);
    if (!$r['ok']) {
        if ($r['error'] !== '') {
            out('   Error: ' . $r['error']);
        }
        continue;
    }

    $decoded = json_decode_safe($r['body']);
    if ($decoded === null) {
        out('   Not valid JSON. Text preview: ' . preview_text($r['body'], 220));
        continue;
    }

    out('   JSON OK; ' . summarize_json_shape($decoded));
    $hits = array();
    collect_json_hits($decoded, $terms, '', $hits, $CONFIG['max_json_hits']);
    if (count($hits) === 0) {
        out('   No obvious target-race hit found in this JSON.');
    } else {
        out('   Target-race-ish hits: ' . count($hits));
        foreach ($hits as $hit) {
            out('   - Path: ' . $hit['path']);
            if (count($hit['ids']) > 0) {
                out('     ID fields: ' . json_encode($hit['ids']));
            }
            out('     Preview: ' . $hit['preview']);
        }
        $json_hits_all = array_merge($json_hits_all, $hits);
    }
}

foreach (collect_numeric_ids_from_hits($json_hits_all) as $id) {
    $id_candidates[$id] = true;
}
$id_candidates = array_keys($id_candidates);

section_title('6) Test likely live-feed URLs for discovered race_id candidates');
if (count($id_candidates) === 0) {
    out('No race_id candidates were discovered automatically, so race-specific feed URLs cannot be tested yet.');
    out('However, the /leaderboard redirect still gives us the current live-results page automatically.');
} else {
    out('Testing race_id candidates: ' . implode(', ', $id_candidates));
    foreach ($id_candidates as $race_id) {
        $candidate_urls = array(
            'https://cf.nascar.com/live/feeds/series_' . $CONFIG['series_id'] . '/' . $race_id . '/live-feed.json',
            'https://cf.nascar.com/live/feeds/series_' . $CONFIG['series_id'] . '/' . $race_id . '/live_feed.json',
            'https://cf.nascar.com/cacher/' . $CONFIG['season'] . '/' . $CONFIG['series_id'] . '/' . $race_id . '/weekend-feed.json',
            'https://feed.nascar.com/api/LiveFeed?series_id=' . $CONFIG['series_id'] . '&race_id=' . $race_id,
            'https://feed.nascar.com/api/LiveFeed?SeriesID=' . $CONFIG['series_id'] . '&RaceID=' . $race_id,
        );
        foreach ($candidate_urls as $url) {
            $r = fetch_url($url, $CONFIG['timeout_seconds']);
            $body_len = strlen($r['body']);
            $json_ok = json_decode_safe($r['body']) !== null;
            status_line('Fetch ' . $url, $r['ok'] && $json_ok, 'HTTP ' . $r['status'] . ', bytes=' . $body_len . ', json=' . ($json_ok ? 'yes' : 'no'));
            if ($r['ok'] && $json_ok) {
                $decoded = json_decode_safe($r['body']);
                out('   JSON shape: ' . summarize_json_shape($decoded));
                out('   Preview: ' . substr(preg_replace('/\s+/', ' ', $r['body']), 0, 450));
            }
        }
    }
}

section_title('Bottom line');
$auto_page_ok = $leaderboard['ok'] && stripos($leaderboard['effective_url'], '/live-results/') !== false;
$useful_html_ok = body_has($leaderboard['body'], 'Raw Feed') || body_has($leaderboard['body'], 'Dashboard') || body_has($leaderboard['body'], 'Live Leaderboard');
status_line('Automatic live page discovery via nascar.com/leaderboard', $auto_page_ok, $leaderboard['effective_url']);
status_line('Server-fetched live page contains useful visible race/dashboard words', $useful_html_ok);
status_line('Automatic race_id discovery from this run', count($id_candidates) > 0, count($id_candidates) > 0 ? implode(', ', $id_candidates) : 'not found');

out('');
out('Suggested next decision:');
out('- If automatic page discovery is enough, use https://www.nascar.com/leaderboard as the weekly entry point.');
out('- If MRL needs the lower-level JSON race_id, keep this as a probe and inspect which API/JSON checks produce a stable race_id.');
out('- Do not make NASCAR the scoring source. Treat it as a live info helper beside the existing ESPN-based scoring pipeline.');
