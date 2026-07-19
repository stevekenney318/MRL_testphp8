# MRL At a Glance Chrome Extension

Extension VERSION: v004  
LAST MODIFIED: 7/7/2026 5:30:30 am

## What changed in v004

- The extension icon now opens an in-page overlay instead of Chrome's normal rectangular popup.
- The overlay has true rounded corners because it is drawn inside the current page.
- The overlay opens centered near the top of the page.
- The background service worker fetches live MRL data from `mrl_at_a_glance.php`.
- Click the extension icon again to close the overlay, or use the `×` button.
- Press `Esc` to close the overlay.

## Chrome limitation

The overlay cannot be injected into Chrome protected pages such as:

- `chrome://...`
- Chrome Web Store pages
- some extension pages
- some browser-internal pages

Normal websites should work.

## Live data source

`https://manliusracingleague.com/race_results/mrl_at_a_glance.php`
