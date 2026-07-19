<?php
declare(strict_types=1);

/**
 * race_results_snapshot_views_helper.php
 *
 * VERSION: v001
 * LAST MODIFIED: 7/19/2026 1:35:18 pm
 *
 * PURPOSE:
 * - Generate the complete companion family for one accepted canonical snapshot:
 *     snapshot_..._lite.html
 *     snapshot_..._mrl.html
 *     snapshot_..._mrl_segment.html
 * - Keep generation separate from comparison/classification/version display.
 * - PHP 7.3 compatible.
 *
 * CHANGELOG:
 * v001 (7/19/2026 1:35:18 pm)
 *   - NEW: Shared canonical -> _lite -> _mrl -> _mrl_segment generation.
 *   - NEW: _mrl uses all A/B/C/D drivers listed for the selected year.
 *   - NEW: _mrl_segment uses every driver appearing anywhere in user_picks for that segment,
 *          including SEG, LP, RD, and any other stored pick type.
 *   - NEW: Generated titles include retained/source counts, such as (20/38).
 *   - NEW: Preserves the MRL Lite column-header row and original NASCAR finishing positions.
 */

date_default_timezone_set('America/New_York');

function rrsv_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function rrsv_ns(string $value): string
{
    return trim((string)preg_replace('/\s+/', ' ', $value));
}

function rrsv_name_key(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = rrsv_ns($name);
    $name = str_replace(["\xC2\xA0", '’'], [' ', "'"], $name);
    $name = preg_replace('/\s+\([A-Za-z0-9 -]+\)$/', '', $name);
    return strtolower(trim((string)$name));
}

function rrsv_segment_from_race_number(int $raceNumber): string
{
    if ($raceNumber >= 1 && $raceNumber <= 8) return 'S1';
    if ($raceNumber >= 9 && $raceNumber <= 17) return 'S2';
    if ($raceNumber >= 18 && $raceNumber <= 26) return 'S3';
    if ($raceNumber >= 27 && $raceNumber <= 36) return 'S4';
    return '';
}

function rrsv_race_code_from_number(int $raceNumber): string
{
    return 'R' . str_pad((string)$raceNumber, 2, '0', STR_PAD_LEFT);
}

function rrsv_short_name_from_folder(string $folder): string
{
    $name = preg_replace('/^[RE]\d+_?/i', '', $folder);
    $name = preg_replace('/_\d{10,}$/', '', (string)$name);
    $name = preg_replace('/^NASCAR_Cup_Series_at_/i', '', (string)$name);
    $name = rrsv_ns(str_replace('_', ' ', (string)$name));
    if ($name === 'Circuit of the Americas') return 'COTA';
    return $name;
}

function rrsv_snapshot_parts(string $canonicalBase): ?array
{
    if (!preg_match('/^snapshot_(\d{8})_(\d{9})\.html$/', $canonicalBase, $m)) {
        return null;
    }

    $dt = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . substr($m[2], 0, 6));
    if (!$dt) return null;

    return [
        'stamp' => $m[1] . '_' . $m[2],
        'display' => $dt->format('n/j/y g:ia'),
    ];
}

function rrsv_canonical_files(string $raceFolder): array
{
    $files = glob($raceFolder . DIRECTORY_SEPARATOR . 'snapshot_*.html') ?: [];
    $out = [];

    foreach ($files as $file) {
        if (preg_match('/^snapshot_\d{8}_\d{9}\.html$/', basename((string)$file))) {
            $out[] = (string)$file;
        }
    }

    sort($out, SORT_STRING);
    return $out;
}

function rrsv_snapshot_version(string $canonicalPath): int
{
    $files = rrsv_canonical_files(dirname($canonicalPath));
    $base = basename($canonicalPath);

    foreach ($files as $index => $file) {
        if (basename($file) === $base) {
            return $index + 1;
        }
    }

    return count($files) > 0 ? count($files) : 1;
}

function rrsv_atomic_write(string $path, string $content): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) return false;

    $tmp = $path . '.tmp_' . str_replace('.', '', uniqid('', true));
    $bytes = @file_put_contents($tmp, $content, LOCK_EX);
    if ($bytes === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function rrsv_outer_html(DOMDocument $dom, DOMNode $node): string
{
    return (string)$dom->saveHTML($node);
}

function rrsv_create_lite_html(string $canonicalPath, string $title): array
{
    $raw = @file_get_contents($canonicalPath);
    if ($raw === false || trim($raw) === '') {
        return ['ok' => false, 'error' => 'Canonical snapshot unreadable or empty.'];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML($raw);
    libxml_clear_errors();

    if (!$loaded) {
        return ['ok' => false, 'error' => 'Could not parse canonical snapshot HTML.'];
    }

    $xp = new DOMXPath($dom);
    $table = null;

    $nodes = $xp->query('//table[contains(concat(" ", normalize-space(@class), " "), " tablehead ")]');
    if ($nodes !== false && $nodes->length) {
        $table = $nodes->item(0);
    }

    if (!$table instanceof DOMElement) {
        return ['ok' => false, 'error' => 'Race results table not found.'];
    }

    $links = $xp->query('.//a', $table);
    if ($links !== false) {
        for ($i = $links->length - 1; $i >= 0; $i--) {
            $a = $links->item($i);
            if (!$a || !$a->parentNode) continue;
            while ($a->firstChild) {
                $a->parentNode->insertBefore($a->firstChild, $a);
            }
            $a->parentNode->removeChild($a);
        }
    }

    $rows = $xp->query('.//tr', $table);
    if ($rows !== false && $rows->length) {
        $firstRow = $rows->item(0);
        if ($firstRow && strcasecmp(rrsv_ns((string)$firstRow->textContent), 'Race Results') === 0 && $firstRow->parentNode) {
            $firstRow->parentNode->removeChild($firstRow);
        }
    }

    $headBits = [];
    $styles = $xp->query('//head/style | //head/link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="stylesheet"]');
    if ($styles !== false) {
        foreach ($styles as $styleNode) {
            $headBits[] = rrsv_outer_html($dom, $styleNode);
        }
    }

    $bodyClass = '';
    $bodies = $xp->query('//body');
    if ($bodies !== false && $bodies->length && $bodies->item(0) instanceof DOMElement) {
        $bodyClass = $bodies->item(0)->getAttribute('class');
    }

    $tableHtml = rrsv_outer_html($dom, $table);
    $fallback = '<style>html,body{margin:0;padding:0;background:#fff}body{font-family:Arial,Helvetica,sans-serif}.mrl-lite-wrap{display:inline-block;min-width:675px;margin:8px;border:1px solid #888;background:#fff}.mrl-lite-title{padding:7px 10px;background:#7a430f;color:#fff;font-size:16px;font-weight:700;line-height:1.2}.mrl-lite-table-wrap table{border-collapse:collapse;width:100%}.mrl-lite-table-wrap th,.mrl-lite-table-wrap td{white-space:nowrap}.mrl-lite-table-wrap a{color:inherit;text-decoration:none;pointer-events:none}</style>';

    $html = "<!DOCTYPE html>\n"
        . "<html lang=\"en\">\n<head>\n"
        . "<meta charset=\"UTF-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . "<meta name=\"robots\" content=\"noindex,nofollow\">\n"
        . "<title>" . rrsv_h($title) . "</title>\n"
        . implode("\n", $headBits) . "\n"
        . $fallback . "\n"
        . "</head>\n"
        . "<body class=\"" . rrsv_h($bodyClass) . "\">\n"
        . "<div class=\"mrl-lite-wrap\">\n"
        . "<div class=\"mrl-lite-title\">" . rrsv_h($title) . "</div>\n"
        . "<div class=\"mrl-lite-table-wrap\">\n"
        . $tableHtml . "\n"
        . "</div>\n</div>\n"
        . "<!-- Source: " . rrsv_h(basename($canonicalPath)) . " -->\n"
        . "</body>\n</html>\n";

    return ['ok' => true, 'html' => $html];
}

function rrsv_query_year_drivers(string $year, $dbo, $dbconnect): array
{
    $names = [];
    $tables = ['A Drivers', 'B Drivers', 'C Drivers', 'D Drivers'];

    if ($dbo instanceof PDO) {
        foreach ($tables as $table) {
            $stmt = $dbo->prepare('SELECT driverName FROM `' . $table . '` WHERE driverYear = :year');
            $stmt->execute([':year' => $year]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = trim((string)($row['driverName'] ?? ''));
                if ($name !== '') $names[rrsv_name_key($name)] = $name;
            }
        }
        return $names;
    }

    if ($dbconnect instanceof mysqli) {
        foreach ($tables as $table) {
            $stmt = mysqli_prepare($dbconnect, 'SELECT driverName FROM `' . $table . '` WHERE driverYear = ?');
            if (!$stmt) continue;
            mysqli_stmt_bind_param($stmt, 's', $year);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_assoc($result)) {
                $name = trim((string)($row['driverName'] ?? ''));
                if ($name !== '') $names[rrsv_name_key($name)] = $name;
            }
            mysqli_stmt_close($stmt);
        }
    }

    return $names;
}

function rrsv_query_segment_drivers(string $year, string $segment, $dbo, $dbconnect): array
{
    $names = [];

    if ($dbo instanceof PDO) {
        $stmt = $dbo->prepare(
            'SELECT driverA, driverB, driverC, driverD FROM user_picks '
            . 'WHERE raceYear = :year AND segment = :segment'
        );
        $stmt->execute([':year' => $year, ':segment' => $segment]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
                $name = trim((string)($row[$field] ?? ''));
                if ($name !== '') $names[rrsv_name_key($name)] = $name;
            }
        }
        return $names;
    }

    if ($dbconnect instanceof mysqli) {
        $stmt = mysqli_prepare(
            $dbconnect,
            'SELECT driverA, driverB, driverC, driverD FROM user_picks WHERE raceYear = ? AND segment = ?'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ss', $year, $segment);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_assoc($result)) {
                foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
                    $name = trim((string)($row[$field] ?? ''));
                    if ($name !== '') $names[rrsv_name_key($name)] = $name;
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    return $names;
}

function rrsv_filter_lite_html(string $liteHtml, array $allowedDrivers, string $viewLabel): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML($liteHtml);
    libxml_clear_errors();

    if (!$loaded) {
        return ['ok' => false, 'error' => 'Could not parse generated lite HTML.'];
    }

    $xp = new DOMXPath($dom);
    $nodes = $xp->query('//table[contains(concat(" ", normalize-space(@class), " "), " tablehead ")]');
    $table = ($nodes !== false && $nodes->length) ? $nodes->item(0) : null;

    if (!$table instanceof DOMElement) {
        return ['ok' => false, 'error' => 'MRL Lite results table not found.'];
    }

    $driverIndex = 1;
    $kept = 0;
    $removed = 0;
    $sourceDriverCount = 0;
    $rows = $xp->query('.//tr[td]', $table);

    if ($rows !== false) {
        for ($i = $rows->length - 1; $i >= 0; $i--) {
            $row = $rows->item($i);
            if (!$row || !$row->parentNode) continue;

            $rowClass = '';
            if ($row->attributes && $row->attributes->getNamedItem('class')) {
                $rowClass = ' ' . rrsv_ns((string)$row->attributes->getNamedItem('class')->nodeValue) . ' ';
            }

            if (strpos($rowClass, ' colhead ') !== false) {
                continue;
            }

            $cells = $xp->query('./td', $row);
            if ($cells === false || $cells->length <= $driverIndex) continue;

            $sourceDriverCount++;
            $driverName = rrsv_ns((string)$cells->item($driverIndex)->textContent);
            $driverKey = rrsv_name_key($driverName);

            if ($driverKey !== '' && isset($allowedDrivers[$driverKey])) {
                $kept++;
            } else {
                $row->parentNode->removeChild($row);
                $removed++;
            }
        }
    }

    $titleNodes = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " mrl-lite-title ")]');
    if ($titleNodes !== false && $titleNodes->length) {
        $titleNode = $titleNodes->item(0);
        if ($titleNode) {
            $baseTitle = rrsv_ns((string)$titleNode->textContent);
            while ($titleNode->firstChild) {
                $titleNode->removeChild($titleNode->firstChild);
            }
            $titleNode->appendChild(
                $dom->createTextNode($baseTitle . ' — ' . $viewLabel . ' (' . $kept . '/' . $sourceDriverCount . ')')
            );
        }
    }

    $comments = $xp->query('//comment()[contains(., "Source:")]');
    if ($comments !== false) {
        foreach ($comments as $comment) {
            if ($comment->parentNode) {
                $comment->parentNode->replaceChild(
                    $dom->createComment(' Source: generated lite companion | View: ' . $viewLabel . ' '),
                    $comment
                );
            }
        }
    }

    $html = $dom->saveHTML();
    if (!is_string($html) || trim($html) === '') {
        return ['ok' => false, 'error' => 'Filtered companion HTML could not be generated.'];
    }

    return [
        'ok' => true,
        'html' => $html,
        'kept' => $kept,
        'removed' => $removed,
        'source_count' => $sourceDriverCount,
    ];
}

function rrsv_generate_companion_set(
    string $canonicalPath,
    int $year,
    int $raceNumber,
    string $raceFolderName,
    $dbo = null,
    $dbconnect = null,
    bool $overwrite = true
): array {
    $result = [
        'ok' => false,
        'canonical' => basename($canonicalPath),
        'files' => [],
        'errors' => [],
        'counts' => [],
    ];

    if (!is_file($canonicalPath)) {
        $result['errors'][] = 'Canonical snapshot file does not exist.';
        return $result;
    }

    $parts = rrsv_snapshot_parts(basename($canonicalPath));
    if ($parts === null) {
        $result['errors'][] = 'Canonical snapshot filename is not recognized.';
        return $result;
    }

    $segment = rrsv_segment_from_race_number($raceNumber);
    if ($segment === '') {
        $result['errors'][] = 'Race number does not map to a segment.';
        return $result;
    }

    $version = rrsv_snapshot_version($canonicalPath);
    $raceCode = rrsv_race_code_from_number($raceNumber);
    $shortName = rrsv_short_name_from_folder($raceFolderName);
    $title = $year . ' ' . $raceCode . ' ' . $shortName . ' v' . $version . ' (' . $parts['display'] . ')';

    $litePath = preg_replace('/\.html$/', '_lite.html', $canonicalPath);
    $mrlPath = preg_replace('/\.html$/', '_mrl.html', $canonicalPath);
    $segmentPath = preg_replace('/\.html$/', '_mrl_segment.html', $canonicalPath);

    $lite = rrsv_create_lite_html($canonicalPath, $title);
    if (empty($lite['ok'])) {
        $result['errors'][] = 'Lite: ' . (string)($lite['error'] ?? 'unknown error');
        return $result;
    }

    if ($overwrite || !is_file($litePath)) {
        if (!rrsv_atomic_write($litePath, (string)$lite['html'])) {
            $result['errors'][] = 'Could not write ' . basename($litePath);
        } else {
            $result['files']['lite'] = basename($litePath);
        }
    } else {
        $result['files']['lite'] = basename($litePath);
    }

    $yearDrivers = rrsv_query_year_drivers((string)$year, $dbo, $dbconnect);
    $segmentDrivers = rrsv_query_segment_drivers((string)$year, $segment, $dbo, $dbconnect);

    if (empty($yearDrivers)) {
        $result['errors'][] = 'No MRL year drivers were found.';
    } else {
        $mrl = rrsv_filter_lite_html((string)$lite['html'], $yearDrivers, 'MRL Year Drivers');
        if (empty($mrl['ok'])) {
            $result['errors'][] = 'MRL: ' . (string)($mrl['error'] ?? 'unknown error');
        } elseif ($overwrite || !is_file($mrlPath)) {
            if (!rrsv_atomic_write($mrlPath, (string)$mrl['html'])) {
                $result['errors'][] = 'Could not write ' . basename($mrlPath);
            } else {
                $result['files']['mrl'] = basename($mrlPath);
                $result['counts']['mrl'] = [
                    'kept' => (int)$mrl['kept'],
                    'source' => (int)$mrl['source_count'],
                ];
            }
        } else {
            $result['files']['mrl'] = basename($mrlPath);
        }
    }

    if (empty($segmentDrivers)) {
        $result['errors'][] = 'No MRL segment drivers were found for ' . $segment . '.';
    } else {
        $mrlSegment = rrsv_filter_lite_html(
            (string)$lite['html'],
            $segmentDrivers,
            'MRL Segment ' . $segment . ' Drivers'
        );

        if (empty($mrlSegment['ok'])) {
            $result['errors'][] = 'MRL segment: ' . (string)($mrlSegment['error'] ?? 'unknown error');
        } elseif ($overwrite || !is_file($segmentPath)) {
            if (!rrsv_atomic_write($segmentPath, (string)$mrlSegment['html'])) {
                $result['errors'][] = 'Could not write ' . basename($segmentPath);
            } else {
                $result['files']['mrl_segment'] = basename($segmentPath);
                $result['counts']['mrl_segment'] = [
                    'kept' => (int)$mrlSegment['kept'],
                    'source' => (int)$mrlSegment['source_count'],
                ];
            }
        } else {
            $result['files']['mrl_segment'] = basename($segmentPath);
        }
    }

    $mtime = @filemtime($canonicalPath);
    if ($mtime !== false) {
        foreach ([$litePath, $mrlPath, $segmentPath] as $path) {
            if (is_file($path)) @touch($path, $mtime, $mtime);
        }
    }

    $result['ok'] = empty($result['errors'])
        && isset($result['files']['lite'])
        && isset($result['files']['mrl'])
        && isset($result['files']['mrl_segment']);

    return $result;
}
