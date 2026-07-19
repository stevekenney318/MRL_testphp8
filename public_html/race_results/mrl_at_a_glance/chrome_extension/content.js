/*
 * MRL At a Glance Chrome Extension Content Overlay
 * Extension VERSION: v004
 * LAST MODIFIED: 7/7/2026 5:30:30 am
 */

(() => {
  const ROOT_ID = 'mrl-at-a-glance-extension-overlay-root';
  const WEEKLY_STANDINGS_URL = 'https://manliusracingleague.com/race_results/weekly_standings.php';

  if (window.__mrlAtAGlanceOverlayInstalled) {
    return;
  }
  window.__mrlAtAGlanceOverlayInstalled = true;

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[char]));
  }

  function safeStatus(status) {
    return ['green', 'yellow', 'red'].includes(status) ? status : 'yellow';
  }

  function rowHtml(item) {
    const status = safeStatus(item && item.status);
    const label = esc(item && item.label);
    const text = esc(item && item.text);
    const detail = esc((item && item.detail) || '');
    const url = (item && item.url) || '';
    const inner = `<span class="mrlc-dot"></span><b>${label}</b><span>${text}</span>`;

    if (url) {
      return `<a class="mrlc-row ${status}" href="${esc(url)}" target="_blank" rel="noopener" title="${detail}">${inner}</a>`;
    }

    return `<div class="mrlc-row ${status}" title="${detail}">${inner}</div>`;
  }

  function panelCss() {
    return `
      :host {
        all: initial;
        position: fixed;
        inset: 0;
        z-index: 2147483647;
        pointer-events: none;
        font-family: Arial, Helvetica, sans-serif;
      }

      .mrlc-overlay-shell {
        position: fixed;
        top: 18px;
        left: 50%;
        transform: translateX(-50%);
        width: min(690px, calc(100vw - 28px));
        pointer-events: auto;
        color: #111;
      }

      .mrlc-panel {
        width: 100%;
        box-sizing: border-box;
        border: 3px solid #7db7ff;
        background: #f3f9ff;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 8px 26px rgba(0, 0, 0, 0.28);
      }

      .mrlc-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 12px;
        background: #d9ecff;
        border-bottom: 2px solid #9ecbff;
      }

      .mrlc-title {
        font-size: 22px;
        font-weight: 800;
        color: #084298;
        line-height: 1.1;
      }

      .mrlc-sub {
        margin-top: 2px;
        font-size: 12px;
        color: #406080;
        font-weight: 700;
      }

      .mrlc-head-actions {
        display: flex;
        align-items: center;
        gap: 8px;
      }

      .mrlc-icon-btn {
        border: 0;
        border-radius: 11px;
        background: #cfe4ff;
        color: #084298;
        font-size: 20px;
        font-weight: 900;
        cursor: pointer;
        padding: 4px 10px;
        line-height: 1.2;
        font-family: Arial, Helvetica, sans-serif;
      }

      .mrlc-icon-btn:hover {
        background: #bad8ff;
      }

      .mrlc-close-btn {
        background: transparent;
        font-size: 24px;
        padding: 2px 8px;
      }

      .mrlc-body {
        padding: 10px 12px;
      }

      .mrlc-row {
        display: grid;
        grid-template-columns: 18px 155px 1fr;
        gap: 7px;
        align-items: center;
        padding: 7px 8px;
        margin: 5px 0;
        border-radius: 12px;
        border: 2px solid rgba(0, 0, 0, 0.12);
        background: #fff;
        line-height: 1.2;
        text-decoration: none;
        color: #111;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 16px;
      }

      a.mrlc-row:hover {
        background: #f7fbff;
        border-color: #7db7ff;
      }

      .mrlc-row b {
        color: #111;
        font-weight: 800;
      }

      .mrlc-row span:last-child {
        color: #222;
        font-weight: 600;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .mrlc-dot {
        width: 13px;
        height: 13px;
        border-radius: 50%;
        display: inline-block;
        box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.15);
      }

      .green .mrlc-dot { background: #2e8b57; }
      .yellow .mrlc-dot { background: #f1c232; }
      .red .mrlc-dot { background: #c00000; }

      .mrlc-foot {
        padding: 8px 12px 10px 12px;
        font-size: 12px;
        border-top: 1px solid #bfdcff;
        background: #eef6ff;
        font-family: Arial, Helvetica, sans-serif;
      }

      .mrlc-foot a {
        color: #084298;
        font-weight: 700;
        text-decoration: none;
      }

      .mrlc-foot a:hover {
        text-decoration: underline;
      }

      @media (max-width: 760px) {
        .mrlc-overlay-shell {
          top: 10px;
          width: calc(100vw - 16px);
        }

        .mrlc-row {
          grid-template-columns: 16px 128px 1fr;
          font-size: 14px;
        }
      }
    `;
  }

  function createOverlay() {
    let host = document.getElementById(ROOT_ID);
    if (host) return host;

    host = document.createElement('div');
    host.id = ROOT_ID;
    document.documentElement.appendChild(host);

    const shadow = host.attachShadow({ mode: 'open' });
    shadow.innerHTML = `
      <style>${panelCss()}</style>
      <div class="mrlc-overlay-shell" role="dialog" aria-label="MRL At a Glance">
        <div class="mrlc-panel">
          <div class="mrlc-head">
            <div>
              <div class="mrlc-title">MRL 👀</div>
              <div class="mrlc-sub" id="mrlc-updated-text">loading live status...</div>
            </div>
            <div class="mrlc-head-actions">
              <button type="button" class="mrlc-icon-btn" id="mrlc-refresh-btn" title="Refresh">↻</button>
              <button type="button" class="mrlc-icon-btn mrlc-close-btn" id="mrlc-close-btn" title="Close">×</button>
            </div>
          </div>
          <div class="mrlc-body" id="mrlc-status-rows">
            <div class="mrlc-row yellow"><span class="mrlc-dot"></span><b>Status</b><span>Loading...</span></div>
          </div>
          <div class="mrlc-foot">
            <a href="${WEEKLY_STANDINGS_URL}" target="_blank" rel="noopener">Open Weekly Standings</a>
            <span> &nbsp; Live data from mrl_at_a_glance.php</span>
          </div>
        </div>
      </div>
    `;

    shadow.getElementById('mrlc-close-btn').addEventListener('click', hideOverlay);
    shadow.getElementById('mrlc-refresh-btn').addEventListener('click', loadStatus);

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        hideOverlay();
      }
    });

    return host;
  }

  function showLoading() {
    const host = createOverlay();
    const shadow = host.shadowRoot;
    shadow.getElementById('mrlc-updated-text').textContent = 'loading live status...';
    shadow.getElementById('mrlc-status-rows').innerHTML = '<div class="mrlc-row yellow"><span class="mrlc-dot"></span><b>Status</b><span>Loading...</span></div>';
  }

  function showOverlay() {
    const host = createOverlay();
    host.style.display = 'block';
    loadStatus();
  }

  function hideOverlay() {
    const host = document.getElementById(ROOT_ID);
    if (host) {
      host.style.display = 'none';
    }
  }

  function toggleOverlay() {
    const host = document.getElementById(ROOT_ID);
    if (host && host.style.display !== 'none') {
      hideOverlay();
      return;
    }
    showOverlay();
  }

  function loadStatus() {
    const host = createOverlay();
    const shadow = host.shadowRoot;
    const rows = shadow.getElementById('mrlc-status-rows');
    const updated = shadow.getElementById('mrlc-updated-text');

    showLoading();

    chrome.runtime.sendMessage({ type: 'MRL_AT_A_GLANCE_FETCH' }, (response) => {
      if (chrome.runtime.lastError) {
        updated.textContent = 'live status unavailable';
        rows.innerHTML = `<div class="mrlc-row red"><span class="mrlc-dot"></span><b>Error</b><span>${esc(chrome.runtime.lastError.message)}</span></div>`;
        return;
      }

      if (!response || !response.ok) {
        updated.textContent = 'live status unavailable';
        rows.innerHTML = `<div class="mrlc-row red"><span class="mrlc-dot"></span><b>Error</b><span>${esc(response && response.error ? response.error : 'Failed to fetch')}</span></div>`;
        return;
      }

      const data = response.data || {};
      if (!Array.isArray(data.items)) {
        updated.textContent = 'live status unavailable';
        rows.innerHTML = '<div class="mrlc-row red"><span class="mrlc-dot"></span><b>Error</b><span>No status rows returned</span></div>';
        return;
      }

      updated.textContent = 'updated ' + (data.generated_display || '');
      rows.innerHTML = data.items.map(rowHtml).join('');
    });
  }

  chrome.runtime.onMessage.addListener((message) => {
    if (message && message.type === 'MRL_AT_A_GLANCE_TOGGLE') {
      toggleOverlay();
    }
  });
})();
