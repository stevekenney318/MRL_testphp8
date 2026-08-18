<?php
declare(strict_types=1);

/**
 * team-late-pick.php
 *
 * VERSION: v005
 * LAST MODIFIED: 8/18/2026 3:08:27 am
 *
 * DESCRIPTION:
 * Special wrapper for LP / special-auth team form display.
 * team.php decides when this wrapper is used. This file now restores the
 * red late-pick note/banner while keeping alignment with team.php layout.
 *
 * CHANGELOG:
 *
 * v005 (8/18/2026 3:08:27 am)
 * - CHANGE: LP banner now shows the automatic effective race and race-start deadline.
 * - CHANGE: SPECIAL_AUTH is displayed as a separate manual admin override instead of LP.
 * - CHANGE: Preserved active-form include behavior and layout.
 *
 * v004 (3/30/2026)
 * - Restored the red late-pick / special note banner above the active form.
 * - Kept active config/admin values for race year, segment, and lock date/time.
 * - Preserved width alignment by avoiding an extra container wrapper.
 *
 * v003 (3/30/2026)
 * - Fixed segment/lock message to use the active config/admin values already in scope.
 * - Removed extra width wrapper so the included form aligns with team.php layout.
 * - Kept active-form include behavior and version trace.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');

$specialWrapperVersion = 'v005';

if (!isset($currentForm) || trim((string)$currentForm) === '') {
    echo "<div style='color:red; background:#fabf8f; text-align:center; font-weight:bold; padding:10px;'>Active form is not set.</div>";
    return;
}

$currentFormFile = trim((string)$currentForm);
$currentFormPath = __DIR__ . '/' . ltrim($currentFormFile, '/');

if (!is_file($currentFormPath)) {
    echo "<div style='color:red; background:#fabf8f; text-align:center; font-weight:bold; padding:10px;'>Active form file not found: "
       . htmlspecialchars($currentFormFile, ENT_QUOTES, 'UTF-8')
       . "</div>";
    return;
}

$specialSegmentLabel = isset($segmentName) && trim((string)$segmentName) !== ''
    ? trim((string)$segmentName)
    : trim((string)($segment ?? ''));

$specialLockDate = isset($formLockDate) ? (string)$formLockDate : '';
$specialLockTime = isset($formLockTime) ? (string)$formLockTime : '';
$specialRaceYear = isset($raceYear) ? (string)$raceYear : '';

echo "<br>";

if (isset($teamFormMode) && $teamFormMode === 'LP') {
    $lpRaceCode = '';
    $lpDeadlineDisplay = '';

    try {
        $lpInfo = mrl_get_effective_race_for_lp((int)$specialRaceYear, (string)($segment ?? ''));
        if (is_array($lpInfo)) {
            $lpRaceNumber = (int)($lpInfo['race_number'] ?? 0);
            if ($lpRaceNumber > 0) {
                $lpRaceCode = 'R' . str_pad((string)$lpRaceNumber, 2, '0', STR_PAD_LEFT);
            }

            if (isset($lpInfo['race_start_dt']) && $lpInfo['race_start_dt'] instanceof DateTimeImmutable) {
                $lpDeadlineDisplay = $lpInfo['race_start_dt']->format('n/j/Y g:i a') . ' ET';
            }
        }
    } catch (Throwable $e) {
        $lpRaceCode = '';
        $lpDeadlineDisplay = '';
    }

    $message = "Late Pick window is open for "
        . htmlspecialchars($specialRaceYear, ENT_QUOTES, 'UTF-8')
        . " "
        . htmlspecialchars($specialSegmentLabel, ENT_QUOTES, 'UTF-8')
        . ". Original deadline: "
        . htmlspecialchars($specialLockDate, ENT_QUOTES, 'UTF-8')
        . " "
        . htmlspecialchars($specialLockTime, ENT_QUOTES, 'UTF-8')
        . ".";

    if ($lpRaceCode !== '' && $lpDeadlineDisplay !== '') {
        $message .= " Picks submitted now become effective with "
            . htmlspecialchars($lpRaceCode, ENT_QUOTES, 'UTF-8')
            . " and may be changed until "
            . htmlspecialchars($lpDeadlineDisplay, ENT_QUOTES, 'UTF-8')
            . ".";
    }

    echo "<div style='color:red; font-size:20px; background-color:#fabf8f; text-align:center; font-weight:bold; padding:8px 10px;'>"
       . $message
       . "</div>";
} else {
    echo "<div style='color:red; font-size:20px; background-color:#fabf8f; text-align:center; font-weight:bold; padding:8px 10px;'>"
       . "SPECIAL ADMIN AUTHORIZATION is active for "
       . htmlspecialchars($specialRaceYear, ENT_QUOTES, 'UTF-8')
       . " "
       . htmlspecialchars($specialSegmentLabel, ENT_QUOTES, 'UTF-8')
       . ". Original deadline: "
       . htmlspecialchars($specialLockDate, ENT_QUOTES, 'UTF-8')
       . " "
       . htmlspecialchars($specialLockTime, ENT_QUOTES, 'UTF-8')
       . "."
       . "</div>";
}

include $currentFormPath;

echo "<div style='font:11px/1.2 monospace; color:#999; text-align:right; margin:0; padding:8px 0 0 0;'>"
   . "FILE: " . basename(__FILE__) . " | VERSION: " . $specialWrapperVersion
   . "</div>";
