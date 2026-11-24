// Lumée Collector — background worker: sends pages to the store, scans listing pages
// (scrolling and turning pages), runs the batch queue with protection against being
// blocked (human-like pacing, daily limit, rests, stop on captcha/login), the right-click
// menu, the keyboard shortcut and the history list.

const DEFAULTS = {
  api: '', key: '', currency: 'CNY', floatButton: true, tags: '', closeTabs: true,
  delay: 4,          // seconds to let a product page render before reading it
  gapMin: 8,         // seconds between two products (random in between)
  gapMax: 20,
  dailyLimit: 150,   // products per day in batch mode
  restEvery: 25,     // take a break after this many products…
  restMinutes: 3,    // …for this long
};
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

const inject = (tabId, world = 'ISOLATED') => chrome.scripting.executeScript({ target: { tabId }, files: ['extract.js'], world });
async function run(tabId, func, args = [], world = 'ISOLATED') {
  const [{ result }] = await chrome.scripting.executeScript({ target: { tabId }, func, args, world });
  return result;
}

// Read the product in a tab (page's own JS world, to see its embedded data)
async function read(tabId, html = true) {
  await inject(tabId, 'MAIN');
  const result = await run(tabId, (o) => window.__lumeeExtract(o), [{ html }], 'MAIN');
  if (!result) throw new Error('读取页面失败');
  return result;
}

async function blockedIn(tabId) {
  await inject(tabId);
  return run(tabId, () => window.__lumeeBlocked());
}

async function send(page, edits = {}, currency) {
  const s = await settings();
  if (currency) page.extracted.currency = currency;   // the merchant chose it in the popup
  const tags = [...(edits.tags || []), ...s.tags.split(/[,，]/).map((t) => t.trim()).filter(Boolean)];
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

const BLOCK_TEXT = { captcha: '网站要求验证（滑块/验证码）', login: '网站要求登录' };

async function collectTab(tabId, edits, currency) {
  try {
    const blocked = await blockedIn(tabId).catch(() => '');
    const page = await read(tabId);
    // The product data is often there behind a check popup: take it, but tell the merchant
    if (!page.extracted.title || (blocked && !page.extracted.variants && !page.extracted.price)) {
      throw new Error(blocked ? BLOCK_TEXT[blocked] + '：请先在页面上完成，再采集' : '这个页面上没有找到商品');
    }
    const res = await send(page, edits, currency);
    flash(tabId, '✓', '#1f7a4d');
    return { ok: true, ...res, warning: blocked ? BLOCK_TEXT[blocked] + '，已采集成功；建议完成验证后再继续采集，避免被限制' : '' };
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

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
// Waits that keep the service worker alive (it stops after ~30 s without extension calls)
async function wait(ms) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    await sleep(Math.min(10000, end - Date.now()));
    await chrome.storage.local.get('paused');
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

// ---------- Scanning listing pages ----------
// Scrolls each page so lazy lists load, collects product links, then turns the page.
async function scan(tabId, pages = 1, scroll = true) {
  const found = new Map();
  for (let i = 0; i < pages; i++) {
    await chrome.storage.local.set({ scanProgress: { tabId, page: i + 1, pages, found: found.size } });
    await inject(tabId);
    if ((await run(tabId, () => window.__lumeeBlocked())) && i > 0) break;
    if (scroll) await run(tabId, (n) => window.__lumeeScroll(n), [pages > 1 ? 10 : 8]);
    const had = found.size;
    for (const l of (await run(tabId, () => window.__lumeeLinks())) || []) if (!found.has(l.url)) found.set(l.url, l);
    if (i === pages - 1 || (i > 0 && found.size === had)) break;   // last page, or the page didn't change
    const before = (await chrome.tabs.get(tabId)).url;
    const how = await run(tabId, () => window.__lumeeNext());
    if (!how) break;
    await sleep(1500);
    if ((await chrome.tabs.get(tabId)).url !== before || how !== 'button') await waitLoaded(tabId);
    await sleep(2000 + Math.random() * 2000);
  }
  const links = [...found.values()];
  await chrome.storage.local.set({ scanProgress: null, lastScan: { tabId, links, at: Date.now() } });
  return links;
}

// ---------- Batch queue ----------
// queue: [{url, title, image, status: waiting|working|done|failed, error}]
// pause: null | {reason: 'user'|'captcha'|'login'|'limit'|'rest', until?, tabId?, url?}
let running = false;

async function queueState() {
  const { queue = [], pause = null, today = { day: '', n: 0 } } = await chrome.storage.local.get(['queue', 'pause', 'today']);
  return { queue, pause, today: today.day === dayKey() ? today : { day: dayKey(), n: 0 } };
}

function dayKey() {
  return new Date().toLocaleDateString('en-CA');
}

async function setItem(url, patch) {
  const { queue } = await queueState();
  const i = queue.findIndex((q) => q.url === url);
  if (i >= 0) {
    queue[i] = { ...queue[i], ...patch };
    await chrome.storage.local.set({ queue });
  }
}

async function setPause(pause) {
  await chrome.storage.local.set({ pause });
  updateBadge();
}

async function runQueue() {
  if (running) return;
  running = true;
  try {
    for (;;) {
      const { queue, pause, today } = await queueState();
      const next = queue.find((q) => q.status === 'waiting' || q.status === 'working');
      if (!next) break;
      if (pause) {
        // A rest or the daily limit ends by itself; captcha/login/user pauses wait for the merchant
        if ((pause.reason === 'rest' || pause.reason === 'limit') && Date.now() >= pause.until) { await setPause(null); continue; }
        if (pause.until) chrome.alarms.create('queue', { when: Math.max(Date.now() + 30000, pause.until) });
        break;
      }
      const s = await settings();
      if (today.n >= s.dailyLimit) {
        const tomorrow = new Date(); tomorrow.setHours(24, 5, 0, 0);
        await setPause({ reason: 'limit', until: tomorrow.getTime() });
        continue;
      }

      await setItem(next.url, { status: 'working', error: '' });
      updateBadge();
      let tab;
      let keepTab = false;
      try {
        tab = await chrome.tabs.create({ url: next.url, active: false });
        await waitLoaded(tab.id);
        await sleep(Math.max(1, s.delay) * 1000 + Math.random() * 1500);
        const blocked = await blockedIn(tab.id);
        if (blocked) {
          // Stop everything and show the page: one captcha ignored becomes a ban
          keepTab = true;
          await setItem(next.url, { status: 'waiting', error: BLOCK_TEXT[blocked] });
          await setPause({ reason: blocked, tabId: tab.id, url: next.url });
          chrome.tabs.update(tab.id, { active: true }).catch(() => {});
          chrome.windows.update(tab.windowId, { focused: true }).catch(() => {});
          chrome.tabs.sendMessage(tab.id, { type: 'toast', ok: false, error: BLOCK_TEXT[blocked] + '。批量采集已暂停：完成后会自动继续。' }).catch(() => {});
          break;
        }
        await run(tab.id, () => window.__lumeeScroll(3)).catch(() => {});   // like a reader: load the gallery and SKU images
        const page = await read(tab.id);
        if (!page.extracted.title) throw new Error('没有找到商品');
        const res = await send(page, {}, undefined);
        await setItem(next.url, { status: 'done', title: res.title, variants: res.variants, image: next.image || page.extracted.images[0] || '' });
        const st = await queueState();
        await chrome.storage.local.set({ today: { day: dayKey(), n: st.today.n + 1 } });
        if ((st.today.n + 1) % s.restEvery === 0) await setPause({ reason: 'rest', until: Date.now() + s.restMinutes * 60000 });
      } catch (e) {
        await setItem(next.url, { status: 'failed', error: e.message });
        await remember({ ok: false, url: next.url, title: next.title, error: e.message });
        if (/密钥|连接店铺|连不上/.test(e.message)) { await setPause({ reason: 'user' }); break; }
      } finally {
        if (tab && s.closeTabs && !keepTab) chrome.tabs.remove(tab.id).catch(() => {});
      }
      const gap = (s.gapMin + Math.random() * Math.max(0, s.gapMax - s.gapMin)) * 1000;
      await wait(gap);
    }
  } finally {
    running = false;
    updateBadge();
  }
}

// After a captcha/login pause: resume by itself once that page shows the product again
chrome.tabs.onUpdated.addListener(async (tabId, info) => {
  if (info.status !== 'complete') return;
  const { pause } = await queueState();
  if (!pause || pause.tabId !== tabId || !['captcha', 'login'].includes(pause.reason)) return;
  await sleep(2500);
  if (!(await blockedIn(tabId).catch(() => 'x'))) {
    await setPause(null);
    chrome.tabs.remove(tabId).catch(() => {});
    runQueue();
  }
});

async function updateBadge() {
  const { queue, pause } = await queueState();
  const left = queue.filter((q) => q.status === 'waiting' || q.status === 'working').length;
  const stopped = pause && ['captcha', 'login'].includes(pause.reason);
  chrome.action.setBadgeBackgroundColor({ color: stopped ? '#c0392b' : '#1f7a4d' });
  chrome.action.setBadgeText({ text: stopped ? '!' : left ? String(left) : '' });
}

async function enqueue(items) {
  const { queue } = await queueState();
  const have = new Set(queue.map((q) => q.url));
  const added = items.filter((i) => i.url && !have.has(i.url)).map((i) => ({ url: i.url, title: i.title || '', image: i.image || '', status: 'waiting' }));
  await chrome.storage.local.set({ queue: [...queue, ...added] });
  runQueue();
  return added.length;
}

// ---------- Messages from the popup and the page button ----------
chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  (async () => {
    switch (msg.type) {
      case 'read': return read(msg.tabId, false);
      case 'blocked': return blockedIn(msg.tabId);
      case 'collect': return collectTab(msg.tabId ?? sender.tab?.id, msg.edits || {}, msg.currency);
      case 'status': return api('/api/collector/status', { urls: msg.urls || [] });
      case 'links': return scan(msg.tabId, Math.min(20, Math.max(1, msg.pages || 1)), msg.scroll !== false);
      case 'enqueue': return { added: await enqueue(msg.items || []) };
      case 'queue': {
        const { queue, pause } = await queueState();
        if (msg.action === 'pause') await setPause({ reason: 'user' });
        if (msg.action === 'resume') {
          if (pause?.tabId) chrome.tabs.remove(pause.tabId).catch(() => {});
          await setPause(null);
          runQueue();
        }
        if (msg.action === 'retry') {
          await chrome.storage.local.set({ queue: queue.map((q) => (q.status === 'failed' ? { ...q, status: 'waiting', error: '' } : q)) });
          await setPause(null);
          runQueue();
        }
        if (msg.action === 'clear') await chrome.storage.local.set({ queue: queue.filter((q) => q.status === 'waiting' || q.status === 'working') });
        if (msg.action === 'stop') { await chrome.storage.local.set({ queue: [] }); await setPause(null); }
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
    chrome.contextMenus.create({ id: 'link', title: '采集这个链接的商品（加入批量队列）', contexts: ['link'] });
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

// Keep the queue going after rests, the daily limit, or if the worker was stopped in the middle
chrome.alarms.create('tick', { periodInMinutes: 1 });
chrome.alarms.onAlarm.addListener(() => runQueue());
