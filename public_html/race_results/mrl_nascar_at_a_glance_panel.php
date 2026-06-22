<?php
/**
 * mrl_nascar_at_a_glance_panel.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/21/2026 5:44:47 pm
 *
 * DESCRIPTION:
 * Dashboard include for MRL's optional NASCAR At a Glance display.
 * Include this inside race_results_dashboard.php's Race Status card.
 * Reads _mrl_nascar_live_status.json only; it does not fetch NASCAR directly.
 *
 * PHP: 7.3 compatible.
 */

if (!function_exists('mrl_nascar_panel_h')) {
    function mrl_nascar_panel_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('mrl_nascar_panel_read_status')) {
    function mrl_nascar_panel_read_status(string $baseDir): array
    {
        $path = rtrim($baseDir, '/\\') . '/_mrl_nascar_live_status.json';
        if (!is_file($path)) {
            return [
                'exists' => false,
                'path' => $path,
                'age_text' => 'not created yet',
                'stale' => true,
                'status' => [],
            ];
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $mtime = @filemtime($path);
        $age = ($mtime !== false) ? max(0, time() - (int)$mtime) : null;

        if ($age === null) {
            $ageText = 'unknown age';
        } elseif ($age < 60) {
            $ageText = (string)$age . ' sec old';
        } elseif ($age < 3600) {
            $ageText = (string)floor($age / 60) . ' min old';
        } else {
            $ageText = (string)floor($age / 3600) . ' hr ' . (string)floor(($age % 3600) / 60) . ' min old';
        }

        return [
            'exists' => true,
            'path' => $path,
            'age_text' => $ageText,
            'stale' => ($age === null || $age > 900),
            'status' => is_array($decoded) ? $decoded : [],
        ];
    }
}

if (!isset($baseDir) || !is_string($baseDir) || $baseDir === '') {
    $baseDir = __DIR__;
}

$nascarPanel = mrl_nascar_panel_read_status($baseDir);
$nascarStatus = isset($nascarPanel['status']) && is_array($nascarPanel['status']) ? $nascarPanel['status'] : [];
$nascarOk = !empty($nascarStatus['ok']);
$nascarRace = trim((string)($nascarStatus['race_name'] ?? ''));
$nascarFlag = trim((string)($nascarStatus['flag_label'] ?? ''));
$nascarFlagClass = trim((string)($nascarStatus['flag_class'] ?? 'warn'));
$nascarStage = trim((string)($nascarStatus['stage_label'] ?? ''));
$nascarLaps = trim((string)($nascarStatus['lap_label'] ?? ''));
$nascarElapsed = trim((string)($nascarStatus['elapsed_label'] ?? ''));
$nascarGenerated = trim((string)($nascarStatus['generated_at'] ?? ''));
$nascarMessage = trim((string)($nascarStatus['message'] ?? ''));
?>
<style>
    .mrl-nascar-glance {
        margin-top: 12px;
        border: 1px solid rgba(242,201,142,0.34);
        border-radius: 14px;
        padding: 10px 12px;
        background: rgba(0,0,0,0.18);
    }
    .mrl-nascar-glance-head {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 8px;
        margin-bottom: 8px;
    }
    .mrl-nascar-glance-title {
        color: var(--gold, #f2c98e);
        font-weight: 900;
        font-size: 15px;
        letter-spacing: 0.02em;
    }
    .mrl-nascar-glance-note {
        color: var(--muted, #c9c9c9);
        font-size: 12px;
    }
    .mrl-nascar-glance-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }
    .mrl-nascar-glance .pill strong {
        color: var(--gold, #f2c98e);
    }
</style>
<div class="mrl-nascar-glance">
    <div class="mrl-nascar-glance-head">
        <div class="mrl-nascar-glance-title">NASCAR At a Glance</div>
        <div class="mrl-nascar-glance-note">info only<?php if (!empty($nascarPanel['age_text'])): ?> · <?=mrl_nascar_panel_h((string)$nascarPanel['age_text'])?><?php endif; ?></div>
    </div>

    <?php if (!$nascarPanel['exists']): ?>
        <div class="mrl-nascar-glance-pills">
            <span class="pill warn"><strong>Status:</strong> no NASCAR cache yet</span>
        </div>
    <?php elseif (!$nascarOk): ?>
        <div class="mrl-nascar-glance-pills">
            <span class="pill warn"><strong>Status:</strong> unavailable</span>
            <?php if ($nascarMessage !== ''): ?><span class="pill"><strong>Message:</strong> <?=mrl_nascar_panel_h($nascarMessage)?></span><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="mrl-nascar-glance-pills">
            <?php if ($nascarFlag !== ''): ?><span class="pill <?=mrl_nascar_panel_h($nascarFlagClass)?>"><strong>Flag:</strong> <?=mrl_nascar_panel_h($nascarFlag)?></span><?php endif; ?>
            <?php if ($nascarStage !== ''): ?><span class="pill"><strong>Stage:</strong> <?=mrl_nascar_panel_h($nascarStage)?></span><?php endif; ?>
            <?php if ($nascarLaps !== ''): ?><span class="pill"><strong>Laps:</strong> <?=mrl_nascar_panel_h($nascarLaps)?></span><?php endif; ?>
            <?php if ($nascarElapsed !== ''): ?><span class="pill"><strong>Time:</strong> <?=mrl_nascar_panel_h($nascarElapsed)?></span><?php endif; ?>
            <?php if ($nascarRace !== ''): ?><span class="pill"><strong>Race:</strong> <?=mrl_nascar_panel_h($nascarRace)?></span><?php endif; ?>
            <?php if (!empty($nascarPanel['stale'])): ?><span class="pill warn"><strong>Cache:</strong> stale</span><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
