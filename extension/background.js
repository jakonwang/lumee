// Lumée Collector — background worker: sends pages to the store, runs the batch queue
// (opens each product in a background tab, reads it, closes it), the right-click menu,
// the keyboard shortcut and the history list.

const DEFAULTS = { api: '', key: '', currency: 'CNY', floatButton: true, delay: 4, closeTabs: true, tags: '' };
const HISTORY_MAX = 200;

async function settings() {
  return { ...DEFAULTS, ...(await chrome.storage.local.get(Object.keys(DEFAULTS))) };
}

async function api(path, body) {
  const s = await settings();
  if (!s.api || !s.key) throw new Error('还没有连接店铺：打开插件的「设置」，粘贴后台的连接码');
  let res;
  try {
    res = await fetch(s.api + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Collector-Key': s.key },
      body: JSON.stringify(body || {}),
    });
  } catch {
    throw new Error('连不上后台：' + s.api + '，请检查网络或后台地址');
  }
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) throw new Error(data.message || '连接密钥已失效，请在后台重新生成并粘贴新的连接码');
  if (res.status === 422) throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || '页面数据不完整');
  if (res.status === 429) throw new Error('发送太快了，请稍等一分钟');
  if (!res.ok) throw new Error(data.message || `发送失败（HTTP ${res.status}）`);
  return data;
}

// Read the product in a tab (page's own JS world, to see its embedded data)
async function read(tabId, html = true) {
  await chrome.scripting.executeScript({ target: { tabId }, files: ['extract.js'], world: 'MAIN' });
  const [{ result }] = await chrome.scripting.executeScript({
    target: { tabId }, world: 'MAIN', args: [{ html }],
    func: (o) => window.__lumeeExtract(o),
  });
  if (!result) throw new Error('读取页面失败');
  return result;
}

async function send(page, edits = {}, currency) {
  const s = await settings();
  if (currency) page.extracted.currency = currency;   // the merchant chose it in the popup
  const tags = [...(edits.tags || []),...s.tags.split(/[,，]/).map((t) => t.trim()).filter(Boolean)];
  const res = await api('/api/collector/pages', {
    url: page.url, html: page.html, platform: page.platform, extracted: page.extracted,
    currency: currency || s.currency,
    edits: { ...edits, tags: [...new Set(tags)].slice(0, 30) },
  });
  await remember({ ok: true, url: page.url, title: res.title, image: (edits.images || page.extracted.images || [])[0] || '', platform: page.platformName,
    variants: res.variants, images: res.images, duplicate: !!res.duplicate, id: res.id });
  return res;
}

async function remember(entry) {
  const { history = [] } = await chrome.storage.local.get('history');
  history.unshift({ ...entry, at: Date.now() });
  await chrome.storage.local.set({ history: history.slice(0, HISTORY_MAX) });
}

async function collectTab(tabId, edits, currency) {
  try {
    const page = await read(tabId);
    if (!page.extracted.title) throw new Error('这个页面上没有找到商品');
    const res = await send(page, edits, currency);
    flash(tabId, '✓', '#1f7a4d');
    return { ok: true, ...res };
  } catch (e) {
    const tab = await chrome.tabs.get(tabId).catch(() => null);
    await remember({ ok: false, url: tab?.url || '', title: tab?.title || '', error: e.message });
    flash(tabId, '!', '#c0392b');
    return { ok: false, error: e.message };
  }
}

function flash(tabId, text, color) {
  chrome.action.setBadgeBackgroundColor({ color, tabId }).catch(() => {});
  chrome.action.setBadgeText({ text, tabId }).catch(() => {});
}

// ---------- Batch queue ----------
// queue: [{url, title, image, status: waiting|working|done|failed|skipped, error}]
let running = false;

async function queueState() {
  const { queue = [], paused = false } = await chrome.storage.local.get(['queue', 'paused']);
  return { queue, paused };
}

async function setItem(url, patch) {
  const { queue } = await queueState();
  const i = queue.findIndex((q) => q.url === url);
  if (i >= 0) {
    queue[i] = { ...queue[i], ...patch };
    await chrome.storage.local.set({ queue });
  }
}

function waitLoaded(tabId, timeout = 45000) {
  return new Promise((resolve) => {
    const done = () => { clearTimeout(timer); chrome.tabs.onUpdated.removeListener(listen); resolve(); };
    const listen = (id, info) => { if (id === tabId && info.status === 'complete') done(); };
    const timer = setTimeout(done, timeout);
    chrome.tabs.onUpdated.addListener(listen);
    chrome.tabs.get(tabId).then((t) => { if (t.status === 'complete') done(); }).catch(done);
  });
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function runQueue() {
  if (running) return;
  running = true;
  try {
    for (;;) {
      const { queue, paused } = await queueState();
      if (paused) break;
      const next = queue.find((q) => q.status === 'waiting' || q.status === 'working');
      if (!next) break;
      await setItem(next.url, { status: 'working', error: '' });
      updateBadge();
      const s = await settings();
      let tab;
      try {
        tab = await chrome.tabs.create({ url: next.url, active: false });
        await waitLoaded(tab.id);
        // Let the page render its gallery and SKU data, scrolling once for lazy images
        await sleep(Math.max(1, s.delay) * 1000);
        await chrome.scripting.executeScript({ target: { tabId: tab.id }, func: () => window.scrollTo(0, document.body.scrollHeight / 2) }).catch(() => {});
        await sleep(800);
        const page = await read(tab.id);
        if (/login|passport|signin|verify|captcha|punish/i.test(page.url) && !page.extracted.variants) throw new Error('网站要求登录或验证：请在浏览器里先登录、完成验证后再重试');
        if (!page.extracted.title) throw new Error('没有找到商品');
        const res = await send(page, {}, undefined);
        await setItem(next.url, { status: 'done', title: res.title, variants: res.variants });
      } catch (e) {
        await setItem(next.url, { status: 'failed', error: e.message });
        await remember({ ok: false, url: next.url, title: next.title, error: e.message });
        if (/密钥|连接店铺|连不上/.test(e.message)) { await chrome.storage.local.set({ paused: true }); break; }
      } finally {
        if (tab && s.closeTabs) chrome.tabs.remove(tab.id).catch(() => {});
      }
      await sleep(1500 + Math.random() * 2500);   // don't hammer the site
    }
  } finally {
    running = false;
    updateBadge();
  }
}

async function updateBadge() {
  const { queue } = await queueState();
  const left = queue.filter((q) => q.status === 'waiting' || q.status === 'working').length;
  chrome.action.setBadgeBackgroundColor({ color: '#1f7a4d' });
  chrome.action.setBadgeText({ text: left ? String(left) : '' });
}

async function enqueue(items) {
  const { queue } = await queueState();
  const have = new Set(queue.map((q) => q.url));
  const added = items.filter((i) => i.url && !have.has(i.url)).map((i) => ({ url: i.url, title: i.title || '', image: i.image || '', status: 'waiting' }));
  await chrome.storage.local.set({ queue: [...queue, ...added], paused: false });
  runQueue();
  return added.length;
}

// ---------- Messages from the popup and the page button ----------
chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  (async () => {
    switch (msg.type) {
      case 'read': return read(msg.tabId, false);
      case 'collect': return collectTab(msg.tabId ?? sender.tab?.id, msg.edits || {}, msg.currency);
      case 'status': return api('/api/collector/status', { urls: msg.urls || [] });
      case 'links': {
        const [{ result }] = await chrome.scripting.executeScript({ target: { tabId: msg.tabId }, files: ['extract.js'] })
          .then(() => chrome.scripting.executeScript({ target: { tabId: msg.tabId }, func: () => window.__lumeeLinks() }));
        return result || [];
      }
      case 'enqueue': return { added: await enqueue(msg.items || []) };
      case 'queue': {
        if (msg.action === 'pause') await chrome.storage.local.set({ paused: true });
        if (msg.action === 'resume') { await chrome.storage.local.set({ paused: false }); runQueue(); }
        if (msg.action === 'retry') {
          const { queue } = await queueState();
          await chrome.storage.local.set({ queue: queue.map((q) => (q.status === 'failed' ? { ...q, status: 'waiting', error: '' } : q)), paused: false });
          runQueue();
        }
        if (msg.action === 'clear') {
          const { queue } = await queueState();
          await chrome.storage.local.set({ queue: queue.filter((q) => q.status === 'waiting' || q.status === 'working') });
        }
        if (msg.action === 'stop') await chrome.storage.local.set({ queue: [], paused: false });
        updateBadge();
        return queueState();
      }
      default: return null;
    }
  })().then((r) => reply({ ok: true, data: r }), (e) => reply({ ok: false, error: e.message || String(e) }));
  return true;
});

// ---------- Right-click menu and shortcut ----------
function menus() {
  chrome.contextMenus.removeAll(() => {
    chrome.contextMenus.create({ id: 'page', title: '采集这个商品到 Lumée', contexts: ['page', 'image'] });
    chrome.contextMenus.create({ id: 'link', title: '采集这个链接的商品（后台打开）', contexts: ['link'] });
  });
}

chrome.runtime.onInstalled.addListener(async (details) => {
  menus();
  updateBadge();
  if (details.reason === 'install') chrome.runtime.openOptionsPage?.();
});
chrome.runtime.onStartup.addListener(() => { menus(); updateBadge(); runQueue(); });

chrome.contextMenus.onClicked.addListener(async (info, tab) => {
  if (info.menuItemId === 'link' && info.linkUrl) return enqueue([{ url: info.linkUrl }]);
  if (info.menuItemId === 'page' && tab?.id) {
    const r = await collectTab(tab.id);
    chrome.tabs.sendMessage(tab.id, { type: 'toast', ...r }).catch(() => {});
  }
});

chrome.commands.onCommand.addListener(async (command, tab) => {
  if (command !== 'collect-page') return;
  tab = tab || (await chrome.tabs.query({ active: true, currentWindow: true }))[0];
  if (!tab?.id) return;
  const r = await collectTab(tab.id);
  chrome.tabs.sendMessage(tab.id, { type: 'toast', ...r }).catch(() => {});
});

// Keep the queue going if the worker was stopped in the middle
chrome.alarms.create('queue', { periodInMinutes: 1 });
chrome.alarms.onAlarm.addListener((a) => { if (a.name === 'queue') runQueue(); });
