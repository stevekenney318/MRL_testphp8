# MRL At a Glance Chrome Extension v003

Generated: 7/6/2026 7:36:25 pm America/New_York

## What changed in this package

- Chrome extension folder is now stored with a stable path inside the package:
  `mrl_at_a_glance/chrome_extension/`
- Popup panel now uses rounded corners.
- Server endpoint v005 changes row links:
  - Scheduler opens `race_results_dashboard.php`
  - Race Monitor opens `race_results_dashboard.php?tab=monitor`
  - Revision Monitor opens `race_results_dashboard.php?tab=revision`
- Race Monitor standby text no longer shows `unknown • unknown • cadence unknown` when the monitor is intentionally disabled between race windows.

## Recommended local folder

Create/use a permanent local folder like:

`H:\!_Steve\!!_websites\mrl\race_results\mrl_at_a_glance\chrome_extension\`

Chrome should load that folder with Developer Mode > Load unpacked.

## Update steps

1. Run the installer PHP on the server to update `mrl_at_a_glance.php` to v005.
2. Copy the contents of this package's `mrl_at_a_glance/chrome_extension/` folder into your permanent local `chrome_extension` folder.
3. Open Chrome Extensions.
4. Click Reload for MRL At a Glance.

Future fine-tuning packages can keep the same extension manifest version unless the update is meaningful enough to bump it.
