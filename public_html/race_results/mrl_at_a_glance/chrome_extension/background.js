/*
 * MRL At a Glance Chrome Extension
 * Extension VERSION: v004
 * LAST MODIFIED: 7/7/2026 5:30:30 am
 *
 * CHANGELOG:
 * v004 (7/7/2026 5:30:30 am)
 * - CHANGE: Extension icon now injects an in-page overlay instead of using Chrome's rectangular popup.
 * - NEW: Overlay supports true rounded corners and centered/custom in-page placement.
 * - NEW: Background service worker performs the MRL status fetch so restricted pages are less likely to block data loading.
 * v003 (7/6/2026 7:36:25 pm)
 * - CHANGE: Dashboard links and standby race-monitor wording updated.
 */

const MRL_STATUS_URL = 'https://manliusracingleague.com/race_results/mrl_at_a_glance.php';

chrome.action.onClicked.addListener(async (tab) => {
  if (!tab || !tab.id) return;

  try {
    await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      files: ['content.js']
    });

    await chrome.tabs.sendMessage(tab.id, { type: 'MRL_AT_A_GLANCE_TOGGLE' });
  } catch (err) {
    // Chrome blocks injection on chrome://, extension pages, the Web Store, and some protected pages.
    // Nothing user-visible is available from here without a popup, so fail quietly.
    console.warn('MRL At a Glance injection failed:', err && err.message ? err.message : err);
  }
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (!message || message.type !== 'MRL_AT_A_GLANCE_FETCH') {
    return false;
  }

  fetch(MRL_STATUS_URL, { cache: 'no-store' })
    .then((response) => {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    })
    .then((data) => {
      sendResponse({ ok: true, data });
    })
    .catch((err) => {
      sendResponse({ ok: false, error: err && err.message ? err.message : String(err) });
    });

  return true;
});
