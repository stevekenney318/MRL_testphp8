# MRL Race Finish Confirmation Monitor

**Version:** v002  
**Last modified:** 7/24/2026 4:34:17 pm America/New_York

## Purpose

This package installs a separate, observation-only late-race monitor and dashboard.

It records when Racing-Reference and Jayski begin showing race/results evidence near the end of a NASCAR Cup race. The saved history will help determine which secondary sources are dependable and how quickly each one updates.

## Scheduling model

This version uses the **existing MRL Hostinger cron and master scheduler**.

```text
Existing Hostinger cron (every minute)
        ↓
cron_master_scheduler.php
        ↓
race_finish_confirmation_monitor.php
```

The installer adds the new monitor as a normal one-minute scheduler task in `_scheduler/schedule.json`.

The master scheduler launches the file every minute, but the new monitor does almost nothing until existing MRL lap/status data reaches the late-race activation threshold.

No second Hostinger cron is required.

## Existing data it reads

The monitor makes no independent NASCAR request. It reads:

```text
/race_results/_mrl_nascar_live_status.json
```

and uses this as a fallback:

```text
/race_results/_race_results_monitor_state.json
```

These files are already maintained by the existing MRL race-monitor system.

## Activation and cadence

Before 90%, the monitor reads the local JSON and exits quietly without contacting Racing-Reference or Jayski and without adding history observations.

Once the existing MRL status reaches 90%, secondary-source observation begins:

| Race state | Secondary-source cadence |
|---|---:|
| Below 90% | Idle; no secondary requests |
| 90%–96.999% | Every 5 minutes |
| 97%–finish | Every 2 minutes |
| White flag | Every minute |
| Checkered flag | Every minute |
| After checkered | Continues for 180 minutes, then stops |

The master scheduler may launch the file every minute, but `next_run_at` prevents unnecessary secondary requests between due observations.

## Isolation and safety

This utility does not call, modify, or control:

- `race_results_monitor.php`
- `race_results_revision_monitor.php`
- ESPN final detection
- scoring or standings
- snapshots or hashes
- emails
- review flags
- race-final decisions

The only existing configuration changed by the installer is `_scheduler/schedule.json`, where it adds one task entry.

The installer does **not** replace or modify `cron_master_scheduler.php`.

## Installed files

```text
/race_results/race_finish_confirmation_monitor.php
/race_results/race_finish_confirmation_dashboard.php
/race_results/_race_finish_confirmation/config.json
/race_results/_race_finish_confirmation/README.md
/race_results/_race_finish_confirmation/history/
/race_results/_race_finish_confirmation/raw/
```

Runtime files created later:

```text
/race_results/_race_finish_confirmation/latest.json
/race_results/_race_finish_confirmation/state.json
/race_results/_race_finish_confirmation/monitor.log
```

## Installation

1. Upload the installer to `/race_results/`.
2. Open it once in a browser.
3. Confirm that it reports:
   - all monitor/dashboard files installed,
   - `schedule.json` backed up,
   - the scheduler task added.
4. Delete the installer after verification.
5. Open:

```text
/race_results/race_finish_confirmation_dashboard.php
```

6. Use **Run observation now** for a manual status test.

The manual test will normally report `IDLE` when no current race is at least 90% complete.

## Scheduler task added

The installer adds:

```json
"race_finish_confirmation_monitor": {
  "enabled": true,
  "type": "interval",
  "script": "race_finish_confirmation_monitor.php",
  "args": ["{{year}}"],
  "interval_minutes": 1,
  "lock_minutes": 2,
  "timeout_seconds": 90,
  "run_method": "url",
  "description": "Observation-only late-race secondary-source monitor. Reads existing MRL lap/status JSON and does not influence MRL decisions."
}
```

## Dashboard

The dashboard refreshes every 30 seconds and shows:

- race and track
- lap progress
- NASCAR flag from the existing MRL cache
- finish-watch status
- next due observation
- Racing-Reference status
- Jayski status
- saved raw pages when source content changes
- the 100 most recent observation records
- downloadable JSON history
- current configuration

## Saved evidence

Each due observation saves:

- timestamp
- race identity
- current lap and scheduled laps
- percentage complete
- flag
- source HTTP status
- page title
- source content hash
- whether source content changed
- matching race/track terms
- generic results-related terms

Changed secondary-source HTML is saved under `raw/`.

## Backup and recovery

The installer creates a timestamped backup of the existing scheduler configuration before changing it:

```text
/race_results/_race_finish_confirmation_install_backup_<timestamp>/
```

For full recovery, retain:

- this installation package,
- the `_race_finish_confirmation/` runtime folder if its observation history matters,
- the normal MRL website backups.

## Adding another source later

Another source can be added without changing existing MRL logic:

1. add its URL to the isolated `config.json`,
2. add one call to the generic secondary-source checker,
3. add one dashboard card/history field.

The source would remain observational only.
