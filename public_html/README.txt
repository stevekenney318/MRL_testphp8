# MRL Race Results System — Technical Guide

DOCUMENT VERSION: v1.0  
LAST MODIFIED: 2026-02-28  
PURPOSE: Explain how the ESPN Race Results collection + monitoring system works, how to operate it, and how to troubleshoot it.

---

## 1) What this system does

This system maintains a local archive of ESPN race results pages (per season) and detects when results become “FINAL” (meaning: **non-zero scoring appears in the scoring table**). It supports:

- **Backfill**: one-time population of a full season (or multiple seasons).
- **Monitor**: cron-driven polling that watches for the latest race and only saves new snapshots when something changes.

It is designed to:
- Avoid duplicate snapshots when the visible scoring table has not changed.
- Keep stable, schedule-aligned folder numbering for points races.
- Keep exhibition and anomaly races without corrupting points-race numbering.

---

## 2) Current component versions (from PHP file headers)

These are the “production” versions expected for this system:

- `race_results_engine.php` — VERSION: **v1.03.00.01**  
- `race_results_monitor.php` — VERSION: **v1.02.00.01**  
- `race_results_backfill.php` — VERSION: **v1.02.00.01**

Each PHP file also contains its own BUILD TS and CHANGELOG in the header comment.

---

## 3) Files in /race_results/

Required PHP scripts:
- `race_results_engine.php`  
  Shared helper functions (fetching, parsing, hashing, snapshots, etc).
- `race_results_backfill.php`  
  Populates a full year of race folders and state.
- `race_results_monitor.php`  
  Cron-friendly script that checks ESPN and only snapshots/emails when needed.

Operational files created at runtime (these may be deleted if you want to “start clean”):
- `_race_results_monitor_state.json`  
- `_race_results_monitor.log`  
- `_race_results_monitor_heartbeat.txt`  
- `_race_results_monitor_php_errors.log`  
- `_race_results_backfill_state.json`  
- `_race_results_backfill.log`  
- `_race_results_backfill_php_errors.log`  

Per-year index file (created by backfill, used by monitor):
- `/race_results/<YEAR>/_year_index.json`

---

## 4) Folder structure and naming rules

### 4.1 Year folders
Each season is stored under:
- `/race_results/<YEAR>/`

Example:
- `/race_results/2026/`

### 4.2 Race folders (inside a year)

Race folder prefixes:

- **Points races**: `R##_..._<raceId>`
  - `R01_...` through `R36_...` (aligned to the NASCAR season schedule numbering)
- **Exhibition races**: `E##_..._<raceId>`
  - `E01_...` etc
- **Anomalies**: `Z##_..._<raceId>`
  - Rare duplicates / placeholder entries that should not consume `R##` numbering

Examples:
- `R03_NASCAR_CUP_SERIES_AT_CIRCUIT_OF_THE_AMERICAS_2026xxxxxxx`
- `E02_CLASH_AT_DAYTONA_2026xxxxxxx`
- `Z01_DAYTONA_500_202102140001` (example of a “placeholder/duplicate” that shouldn’t steal an R-number)

### 4.3 What’s inside a race folder

Common files:
- `_meta.json`  
  Basic metadata (year, raceId, URL, race name, folder type, numbering, updated timestamp)
- `final_table_hash.txt`  
  The last known scoring-table hash used to suppress duplicates

Snapshots (only saved when needed):
- `snapshot_<timestamp>.html`
- `snapshot_summary_<timestamp>.txt` (spoiler-safe summary)

---

## 5) The “year index” file (important)

Backfill writes:
- `/race_results/<YEAR>/_year_index.json`

This acts as the authoritative mapping of:
- raceId → folder name + kind (R/E/Z) + number + URL + race name

Monitor uses this file (when present) so that:
- It does **not** accidentally re-number races
- It can find the correct folder for a raceId
- It can avoid duplicate snapshots based on stored hashes

If `_year_index.json` is missing, monitor can still run, but numbering stability is best when backfill has been run for that year at least once.

---

## 6) Operating procedure (recommended)

### Step A — Backfill once (per year you care about)
You typically run backfill to populate historical seasons and to create `_year_index.json`.

Browser:
- `https://manliusracingleague.com/race_results/race_results_backfill.php?year=2022`

CLI:
- `php /home/.../public_html/race_results/race_results_backfill.php 2022`

Expected output:
- Races found on year page: 41
- DONE. Processed=41, FINAL detected=XX

Notes:
- “Processed” = total race links found
- “FINAL detected” = races where scoring was non-zero (historical seasons will usually be high)

### Step B — Enable cron for monitor
Once 2026 is backfilled (or at least indexed), you can run monitor by cron.

The monitor will:
- Check ESPN
- If nothing new is FINAL and nothing changed, it will do **no snapshots** and **no email**
- Update heartbeat/log/state so you can confirm it ran

---

## 7) Cron: what “success” looks like

When cron runs successfully, you should see updates to:

- `_race_results_monitor_heartbeat.txt`  
  This is the easiest “it ran” signal.

- `_race_results_monitor.log`  
  Contains status messages for each run.

If you don’t see those files updating, cron either did not run or crashed early.

---

## 8) Email behavior (monitor)

Monitor emails ONLY when:
1) A race is detected as FINAL for the first time (non-zero scoring), OR  
2) The scoring/results changed later (hash change) — commonly penalties/adjustments

Monitor should **not** email:
- On every cron run
- When the visible scoring table hasn’t changed
- When results are still zero

Backfill:
- Email is OFF by default (so you can run it without spam)

---

## 9) Duplicate snapshots: what’s allowed vs not allowed

Goal:
- If the visible scoring table doesn’t change, you should not accumulate multiple identical snapshots.

How suppression works:
- A per-race `final_table_hash.txt` represents the last saved scoring state.
- Monitor compares the current scoring-table hash to the prior value and snapshots only on first FINAL or change.

If you ever see duplicates that appear identical:
- First check whether `final_table_hash.txt` exists in that race folder and whether it is being updated properly.
- Then inspect the snapshot summaries to confirm whether the hash or the scoring tokens changed.

---

## 10) Troubleshooting

### 10.1 HTTP 500 in browser
Most common causes:
- Syntax error / stray character in a PHP file (example: a leading `\` before `<?php`)
- Out-of-date file uploaded (partial paste, truncated content)
- PHP fatal error during execution

Where to look:
- `_race_results_monitor_php_errors.log`
- `_race_results_backfill_php_errors.log`

### 10.2 “Cron ran but nothing changed”
This is normal when:
- There is no new FINAL race
- ESPN hasn’t updated anything since the last run

Confirm by checking:
- heartbeat timestamp changes
- monitor log entries

### 10.3 Year folders missing / empty
If you deleted year folders intentionally:
- Run backfill again for the year(s) to rebuild structure and `_year_index.json`.

---

## 11) Safe reset procedure (clean slate)

If you want to start over:

1) Stop cron (monitor).
2) In `/race_results/`, keep only:
   - `race_results_engine.php`
   - `race_results_monitor.php`
   - `race_results_backfill.php`
   - This README
3) Delete:
   - all year folders (`2017/`, `2018/`, …)
   - all state/log/heartbeat/error files
4) Run backfill for each year you want.
5) Restart cron (monitor).

---

## 12) Versioning conventions used here

- Each PHP file maintains its own VERSION / BUILD TS / CHANGELOG.
- VERSION changes when behavior changes (not just comment edits).
- BUILD TS changes whenever the file is regenerated/updated.

This document has its own “DOCUMENT VERSION” and is intended as an operational reference.

---

## 13) Changelog (this document)

v1.0 (2026-02-28)
- Initial technical guide for engine/monitor/backfill, folder naming, year index, and operations.