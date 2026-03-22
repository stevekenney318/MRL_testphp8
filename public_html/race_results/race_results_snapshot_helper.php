<?php
declare(strict_types=1);

/**
 * race_results_snapshot_helper.php
 *
 * VERSION: v1.00.01
 * LAST MODIFIED: 2026-03-12
 * BUILD TS: 20260312_001500000
 *
 * CHANGELOG:
 * v1.00.01 (2026-03-12)
 *   - FIX: Correct NET calculation for ESPN/NASCAR race results.
 *   - NET is now calculated as:
 *       PTS - PENALTY
 *     because BONUS is already included in PTS.
 *
 * v1.00.00 (2026-03-12)
 *   - Initial shared helper for reading one ESPN race-results snapshot HTML file.
 *   - Extracts driver scoring rows from the scoring table.
 *   - Returns driverName => [pts, bonus, penalty, net].
 *
 * PHP: 7.3 compatible.
 */

if (!function_exists('rrs_norm_text')) {
    function rrs_norm_text(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim($s);
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }
}

if (!function_exists('rrs_parse_int')) {
    function rrs_parse_int(string $s): ?int
    {
        $s = trim($s);
        $s = preg_replace('/[^0-9\-]/', '', $s);

        if ($s === '' || $s === '-') {
            return null;
        }

        if (!preg_match('/^-?\d+$/', $s)) {
            return null;
        }

        return (int)$s;
    }
}

if (!function_exists('rrs_find_scoring_table')) {
    function rrs_find_scoring_table(DOMXPath $xp): array
    {
        $tables = $xp->query('//table');
        if (!$tables || $tables->length === 0) {
            return [null, null, []];
        }

        for ($t = 0; $t < $tables->length; $t++) {
            $table = $tables->item($t);
            if (!$table instanceof DOMElement) {
                continue;
            }

            $rows = $xp->query('.//tr', $table);
            if (!$rows || $rows->length === 0) {
                continue;
            }

            for ($r = 0; $r < $rows->length; $r++) {
                $row = $rows->item($r);
                if (!$row instanceof DOMElement) {
                    continue;
                }

                $cells = $xp->query('./th|./td', $row);
                if (!$cells || $cells->length < 8) {
                    continue;
                }

                $idx = [
                    'driver'  => null,
                    'pts'     => null,
                    'bonus'   => null,
                    'penalty' => null,
                ];

                for ($i = 0; $i < $cells->length; $i++) {
                    $txt = strtoupper(rrs_norm_text((string)$cells->item($i)->textContent));

                    if ($idx['driver'] === null && strpos($txt, 'DRIVER') !== false) {
                        $idx['driver'] = $i;
                    }

                    if ($idx['pts'] === null && (strpos($txt, 'PTS') !== false || strpos($txt, 'POINT') !== false)) {
                        $idx['pts'] = $i;
                    }

                    if ($idx['bonus'] === null && strpos($txt, 'BONUS') !== false) {
                        $idx['bonus'] = $i;
                    }

                    if ($idx['penalty'] === null && strpos($txt, 'PENALTY') !== false) {
                        $idx['penalty'] = $i;
                    }
                }

                if (
                    $idx['driver'] !== null &&
                    $idx['pts'] !== null &&
                    $idx['bonus'] !== null &&
                    $idx['penalty'] !== null
                ) {
                    return [$table, $row, $idx];
                }
            }
        }

        return [null, null, []];
    }
}

if (!function_exists('rrs_load_snapshot_driver_points')) {
    /**
     * Reads one snapshot HTML file and returns:
     *
     * [
     *   'Ryan Blaney' => [
     *       'pts' => 65,
     *       'bonus' => 10,
     *       'penalty' => 0,
     *       'net' => 65
     *   ],
     *   ...
     * ]
     *
     * IMPORTANT:
     * - ESPN/NASCAR PTS already includes BONUS.
     * - Therefore:
     *     NET = PTS - PENALTY
     *
     * @param string $snapshotHtmlFile
     * @return array
     */
    function rrs_load_snapshot_driver_points(string $snapshotHtmlFile): array
    {
        if (!is_file($snapshotHtmlFile)) {
            return [];
        }

        $html = @file_get_contents($snapshotHtmlFile);
        if ($html === false || trim($html) === '') {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        if (!$loaded) {
            return [];
        }

        $xp = new DOMXPath($dom);

        list($table, $headerRow, $idx) = rrs_find_scoring_table($xp);
        if (!$table instanceof DOMElement || !$headerRow instanceof DOMElement || empty($idx)) {
            return [];
        }

        $out = [];
        $rows = $xp->query('.//tr[td]', $table);
        if (!$rows || $rows->length === 0) {
            return [];
        }

        $headerSeen = false;

        for ($r = 0; $r < $rows->length; $r++) {
            $row = $rows->item($r);
            if (!$row instanceof DOMElement) {
                continue;
            }

            if ($row->isSameNode($headerRow)) {
                $headerSeen = true;
                continue;
            }

            if (!$headerSeen) {
                continue;
            }

            $tds = $xp->query('./td', $row);
            if (!$tds || $tds->length === 0) {
                continue;
            }

            // Require first cell to look like a finishing position number.
            $firstCell = rrs_norm_text((string)$tds->item(0)->textContent);
            $posDigits = preg_replace('/\D+/', '', $firstCell);
            if ($posDigits === '' || !preg_match('/^\d+$/', $posDigits)) {
                continue;
            }

            $driver = '';
            if ($idx['driver'] !== null && $idx['driver'] < $tds->length) {
                $driver = rrs_norm_text((string)$tds->item($idx['driver'])->textContent);
            }

            if ($driver === '') {
                continue;
            }

            $pts = 0;
            $bonus = 0;
            $penalty = 0;

            if ($idx['pts'] !== null && $idx['pts'] < $tds->length) {
                $v = rrs_parse_int((string)$tds->item($idx['pts'])->textContent);
                $pts = ($v === null) ? 0 : $v;
            }

            if ($idx['bonus'] !== null && $idx['bonus'] < $tds->length) {
                $v = rrs_parse_int((string)$tds->item($idx['bonus'])->textContent);
                $bonus = ($v === null) ? 0 : $v;
            }

            if ($idx['penalty'] !== null && $idx['penalty'] < $tds->length) {
                $v = rrs_parse_int((string)$tds->item($idx['penalty'])->textContent);
                $penalty = ($v === null) ? 0 : $v;
            }

            $out[$driver] = [
                'pts' => $pts,
                'bonus' => $bonus,
                'penalty' => $penalty,
                'net' => $pts - $penalty,
            ];
        }

        return $out;
    }
}