// Lumée Collector — a small "collect" button on product pages (can be turned off in the
// extension's settings) and the result toasts for the right-click menu and shortcut.
(async () => {
  if (window.top !== window || window.__lumeeButton) return;
  window.__lumeeButton = true;

  let host, root;
  function mount() {
    if (root) return root;
    host = document.createElement('lumee-collector');
    host.style.cssText = 'all: initial; position: fixed; z-index: 2147483646; right: 20px; bottom: 96px;';
    root = host.attachShadow({ mode: 'closed' });
    root.innerHTML = `
      <style>
        :host { all: initial; }
        .wrap { font: 13px/1.4 system-ui, -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
        button { all: unset; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; background: #1f7a4d; color: #fff; padding: 10px 14px 10px 12px;
          border-radius: 999px; box-shadow: 0 6px 20px rgba(16, 60, 38, .28); font-weight: 600; letter-spacing: .2px; transition: transform .15s, background .15s; }
        button:hover { background: #18663f; transform: translateY(-1px); }
        button[disabled] { opacity: .75; cursor: progress; }
        button.done { background: #fff; color: #1f7a4d; box-shadow: 0 4px 16px rgba(0,0,0,.12); border: 1px solid #cfe5d8; }
        .x { all: unset; cursor: pointer; color: #8a958f; font-size: 11px; padding: 0 4px; }
        .x:hover { color: #18211c; }
        svg { width: 16px; height: 16px; flex: none; }
        .toast { max-width: 300px; background: #fff; color: #18211c; border: 1px solid #e3e8e5; border-left: 3px solid #1f7a4d; border-radius: 10px; padding: 10px 12px;
          box-shadow: 0 8px 24px rgba(0,0,0,.12); }
        .toast.err { border-left-color: #c0392b; }
        .toast b { display: block; margin-bottom: 2px; }
        .row { display: flex; align-items: center; gap: 4px; }
      </style>
      <div class="wrap"><div class="toast" hidden></div><div class="row" hidden><span class="x" title="在这个网站隐藏（可在插件设置里恢复）">✕</span><button type="button"></button></div></div>`;
    (document.body || document.documentElement).appendChild(host);
    return root;
  }

  const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5"/><path d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>';
  const CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

  let toastTimer;
  function toast(title, text, error) {
    const t = mount().querySelector('.toast');
    t.className = 'toast' + (error ? ' err' : '');
    t.innerHTML = '';
    const b = document.createElement('b'); b.textContent = title;
    const p = document.createElement('div'); p.textContent = text || '';
    t.append(b, p);
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, error ? 7000 : 4500);
  }

  function setButton(state) {
    const btn = mount().querySelector('button');
    btn.disabled = state === 'busy';
    btn.className = state === 'done' ? 'done' : '';
    btn.innerHTML = state === 'busy' ? ICON + '<span>正在采集…</span>' : state === 'done' ? CHECK + '<span>已采集 · 再次更新</span>' : ICON + '<span>采集到 Lumée</span>';
  }

  chrome.runtime.onMessage.addListener((msg) => {
    if (msg.type !== 'toast') return;
    if (msg.ok) toast('已加入待审核', `${msg.title}${msg.variants > 1 ? ` · ${msg.variants} 个 SKU` : ''}`);
    else toast('采集失败', msg.error, true);
    if (msg.ok && root) setButton('done');
  });

  const s = await chrome.storage.local.get(['floatButton', 'hiddenSites', 'api', 'key']);
  if (s.floatButton === false || !s.api || !s.key) return;
  const site = location.hostname.replace(/^www\./, '');
  if ((s.hiddenSites || []).includes(site)) return;

  // Product pages render late on most marketplaces: check a few times
  let info = null;
  for (let i = 0; i < 4 && !(info?.isProduct); i++) {
    await new Promise((r) => setTimeout(r, i ? 1500 : 600));
    info = window.__lumeePlatform?.();
  }
  if (!info?.isProduct) return;

  mount().querySelector('.row').hidden = false;
  setButton('idle');
  chrome.runtime.sendMessage({ type: 'status', urls: [location.href] }, (r) => {
    if (r?.ok && r.data?.collected?.[location.href]) setButton('done');
  });

  root.querySelector('button').addEventListener('click', () => {
    setButton('busy');
    chrome.runtime.sendMessage({ type: 'collect' }, (r) => {
      const res = r?.data || { ok: false, error: r?.error || '插件没有响应，请刷新页面再试' };
      setButton(res.ok ? 'done' : 'idle');
      if (res.ok) toast('已加入待审核', `${res.title}${res.variants > 1 ? ` · ${res.variants} 个 SKU` : ''} · ${res.images} 张图`);
      else toast('采集失败', res.error, true);
    });
  });
  root.querySelector('.x').addEventListener('click', async () => {
    const { hiddenSites = [] } = await chrome.storage.local.get('hiddenSites');
    await chrome.storage.local.set({ hiddenSites: [...new Set([...hiddenSites, site])] });
    host.remove();
  });
})();
