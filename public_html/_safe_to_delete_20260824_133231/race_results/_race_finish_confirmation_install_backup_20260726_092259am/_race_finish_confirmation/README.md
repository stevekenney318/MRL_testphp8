# MRL Race Finish Confirmation Monitor

**Version:** v003  
**Last modified:** 7/26/2026 7:16:39 am America/New_York

## Purpose

This is an observation-only late-race monitor. It uses existing MRL lap/status JSON to activate at 90%, then records when Racing-Reference and Jayski publish race-finish information. It does not drive MRL scoring or final detection.

## v003 changes

- Racing-Reference now uses the deterministic race URL:
  `https://www.racing-reference.info/race-results/{year}-{race_number}/W`
- It watches for `Results not available yet.` to disappear and for populated driver rows to appear.
- It also checks the yearly season page row:
  `https://www.racing-reference.info/season-stats/{year}/W`
- Jayski now locates the known `Race #N of 36` block on the yearly page.
- It records when the `Race Winner` field is populated.
- It extracts the block's Results link instead of trying to guess the slug.
- It checks the linked page for a `Race Results` heading and PDF link.
- The dashboard displays the new source-specific evidence and raw-page links.

## Scheduling

The existing Hostinger cron runs `cron_master_scheduler.php` every minute. The scheduler task launches this monitor every minute. Below 90%, this file reads local JSON and exits. At 90% and above, it follows its internal 5/2/1-minute source cadence. No second cron is required.

## Installation

1. Upload the installer to `/race_results/`.
2. Run it once on Live and once on testphp8.
3. Confirm success.
4. Delete the installer.
5. Open `race_finish_confirmation_dashboard.php`.

The installer backs up existing monitor, dashboard, config, and scheduler configuration before replacing anything. It preserves runtime history/state/raw files because those are not overwritten.

## Source status meanings

### Racing-Reference

- `waiting_results`: waiting phrase still present.
- `race_results_posted`: waiting phrase gone and populated driver rows confirmed.
- `season_row_completed`: yearly row appears converted from schedule format to completed result.
- `race_and_season_results_posted`: both checks succeeded.

### Jayski

- `waiting_results`: race block found, but winner/results not confirmed.
- `winner_posted`: Race Winner field populated.
- `results_page_posted`: extracted Results link leads to a populated results page.
- `winner_and_results_posted`: both checks succeeded.

## Saved files

Changed source HTML is saved under `_race_finish_confirmation/raw/`. Every due observation is saved under `_race_finish_confirmation/history/`.
