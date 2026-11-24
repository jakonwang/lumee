// Lumée Collector popup: preview and edit the product on the current page before sending,
// batch-collect product links, history, and the store connection.
const $ = (id) => document.getElementById(id);
const CURRENCIES = ['CNY', 'USD', 'EUR', 'GBP', 'MXN', 'BRL', 'CAD', 'AUD', 'JPY', 'KRW', 'IDR', 'THB', 'PHP', 'MYR', 'VND', 'SGD', 'INR', 'TRY', 'AED', 'SAR'];
const MAX_IMAGES = 12;
const STATUS_TEXT = { ready: '待审核', pending: '处理中', published: '已上架', rejected: '已拒绝', failed: '失败' };

const params = new URLSearchParams(location.search);
if (params.get('full')) document.body.classList.add('full');

let conf = {};
let tab = null;
let page = null;          // what the reader found on the current page
let images = [];          // [{url, on}]
let links = [];           // [{url, title, image, on, collected}]
let collectedMap = {};

function bg(msg) {
  return new Promise((resolve, reject) => chrome.runtime.sendMessage(msg, (r) => {
    if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
    r?.ok ? resolve(r.data) : reject(new Error(r?.error || '插件没有响应'));
  }));
}

function show(text, error = false, link) {
  const m = $('msg');
  m.className = error ? 'msg err' : 'msg';
  m.textContent = text;
  if (link) {
    const a = document.createElement('a');
    a.href = link.href; a.target = '_blank'; a.textContent = link.text;
    m.append(a);
  }
  m.hidden = false;
  clearTimeout(show.t);
  show.t = setTimeout(() => { m.hidden = true; }, error ? 9000 : 6000);
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function ago(t) {
  const s = Math.round((Date.now() - t) / 1000);
  if (s < 60) return '刚刚';
  if (s < 3600) return Math.floor(s / 60) + ' 分钟前';
  if (s < 86400) return Math.floor(s / 3600) + ' 小时前';
  return new Date(t).toLocaleDateString();
}

// ---------- Tabs ----------
function go(view) {
  document.querySelectorAll('#tabs button').forEach((b) => b.classList.toggle('on', b.dataset.view === view));
  document.querySelectorAll('.view').forEach((v) => { v.hidden = v.id !== 'view-' + view; });
  if (view === 'batch' && !links.length && tab) scan();
  if (view === 'history') renderHistory();
}
document.querySelectorAll('#tabs button').forEach((b) => b.addEventListener('click', () => go(b.dataset.view)));
document.querySelectorAll('[data-go]').forEach((b) => b.addEventListener('click', () => go(b.dataset.go)));

// ---------- Connection ----------
function fillCurrencies(sel, value) {
  sel.innerHTML = CURRENCIES.map((c) => `<option>${c}</option>`).join('');
  sel.value = CURRENCIES.includes(value) ? value : 'CNY';
}

function setConn(state, text) {
  const c = $('conn');
  c.className = 'conn ' + (state || '');
  c.querySelector('span').textContent = text;
}

async function checkConnection() {
  if (!conf.api || !conf.key) {
    setConn('', '未连接店铺');
    $('openAdmin').hidden = true;
    $('disconnect').hidden = true;
    return null;
  }
  setConn('', '正在连接…');
  try {
    const s = await bg({ type: 'status', urls: [] });
    const waiting = s.counts?.ready || 0;
    setConn('ok', `${s.store}${waiting ? ` · ${waiting} 个待审核` : ''}`);
    $('openAdmin').hidden = false;
    $('openAdmin').onclick = () => chrome.tabs.create({ url: s.admin_url });
    conf.adminUrl = s.admin_url;
    $('disconnect').hidden = false;
    $('connInfo').hidden = false;
    $('connInfo').textContent = `已连接：${s.store}（${conf.api}）`;
    return s;
  } catch (e) {
    setConn('bad', '连接失败');
    $('connInfo').hidden = true;
    show(e.message, true);
    return null;
  }
}

function parseCode(text) {
  text = text.trim();
  if (!text) return null;
  const m = text.match(/LUMEE-([A-Za-z0-9_-]+)/);
  if (!m) throw new Error('连接码格式不对：请从后台复制以 LUMEE- 开头的完整连接码');
  let json;
  try {
    const b64 = m[1].replace(/-/g, '+').replace(/_/g, '/');
    json = JSON.parse(atob(b64 + '='.repeat((4 - (b64.length % 4)) % 4)));
  } catch {
    throw new Error('连接码不完整，请重新复制');
  }
  return { api: json.api, key: json.key };
}

$('save').addEventListener('click', async () => {
  let api, key;
  try {
    const fromCode = parseCode($('code').value);
    api = fromCode?.api ?? $('api').value.trim();
    key = fromCode?.key ?? $('key').value.trim();
    if (!api && !key) throw new Error('请粘贴连接码');
    api = new URL(api).origin;
  } catch (e) {
    return show(e.message.includes('URL') ? '后台地址格式不对，例如 https://api.your-store.com' : e.message, true);
  }
  if (!/^lmc_\d+_[A-Za-z0-9]{40}$/.test(key)) return show('连接密钥格式不对，请从后台复制完整的密钥', true);
  // Save first: nothing after this can lose it
  await chrome.storage.local.set({ api, key });
  conf = { ...conf, api, key };
  $('api').value = api;
  $('key').value = key;
  $('code').value = '';
  $('save').disabled = true;
  const s = await checkConnection();
  $('save').disabled = false;
  if (s) {
    show('已保存，连接成功');
    loadPage();
  }
});

$('disconnect').addEventListener('click', async () => {
  await chrome.storage.local.remove(['api', 'key']);
  conf.api = conf.key = '';
  $('api').value = $('key').value = '';
  $('connInfo').hidden = true;
  checkConnection();
  show('已断开');
});

// Preferences save as you change them
function pref(id, key, read = (el) => el.value) {
  $(id).addEventListener('change', () => chrome.storage.local.set({ [key]: read($(id)) }));
}
pref('defCurrency', 'currency');
pref('defTags', 'tags');
pref('delay', 'delay', (el) => Math.min(30, Math.max(1, parseInt(el.value, 10) || 4)));
pref('floatButton', 'floatButton', (el) => el.checked);
pref('closeTabs', 'closeTabs', (el) => el.checked);

// ---------- Current page ----------
async function loadPage() {
  $('pageLoading').hidden = false;
  $('pageEmpty').hidden = true;
  $('product').hidden = true;
  if (!tab || !/^https?:/.test(tab.url || '')) {
    $('pageLoading').hidden = true;
    $('pageEmpty').hidden = false;
    $('pageEmptyText').textContent = '这个页面不能采集。打开一个商品页再点插件。';
    document.querySelector('#pageEmpty [data-go]').hidden = true;
    return;
  }
  try {
    page = await bg({ type: 'read', tabId: tab.id });
  } catch (e) {
    $('pageLoading').hidden = true;
    $('pageEmpty').hidden = false;
    $('pageEmptyText').textContent = /Cannot access|cannot be scripted/i.test(e.message) ? '浏览器不允许读取这个页面' : '读取页面失败：' + e.message;
    return;
  }
  $('pageLoading').hidden = true;
  const x = page.extracted;
  if (!x.title || (!page.isProduct && !x.price && x.images.length < 2)) {
    $('pageEmpty').hidden = false;
    $('pageEmptyText').textContent = '这个页面不像商品详情页。如果是搜索结果或店铺页，可以批量采集。';
    return;
  }
  $('product').hidden = false;
  $('platform').textContent = page.platformName;
  const vs = x.variants || [];
  $('skuChip').hidden = !vs.length;
  $('skuChip').textContent = vs.length ? `${vs.length} 个 SKU · ${(x.options || []).map((o) => o.name).join(' / ')}` : '';
  $('title').value = x.title;
  $('price').value = x.price ? x.price + (x.compare_price ? `（原价 ${x.compare_price}）` : '') : '未识别，可在后台填写';
  $('currency').value = x.currency && CURRENCIES.includes(x.currency) ? x.currency : (conf.currency || 'CNY');
  $('curHint').textContent = x.currency ? '页面标明' : '默认';
  images = x.images.map((url, i) => ({ url, on: i < 8 }));
  renderImages();
  renderSkus(x);
  $('tags').value = '';
  if (conf.api) {
    bg({ type: 'status', urls: [page.url] }).then((s) => {
      const c = s.collected?.[page.url];
      $('dupChip').hidden = !c;
      if (c) $('dupChip').textContent = `已采集过 · ${STATUS_TEXT[c.status] || c.status}`;
      $('send').querySelector('span').textContent = c ? (c.status === 'published' ? '已上架（不会覆盖）' : '重新采集并更新') : '采集到待审核';
    }).catch(() => {});
  }
}

function renderImages() {
  const box = $('images');
  box.innerHTML = '';
  let n = 0;
  images.forEach((img, i) => {
    const el = document.createElement('div');
    el.className = 'img' + (img.on ? ' on' : '');
    if (img.on) n++;
    el.innerHTML = `<img src="${esc(img.url)}" loading="lazy" referrerpolicy="no-referrer" alt="">${img.on ? `<b>${n}</b>` : ''}${img.on && n === 1 ? '<span class="main">主图</span>' : ''}`;
    el.querySelector('img').onerror = () => { img.on = false; img.broken = true; el.remove(); updateImgCount(); };
    el.addEventListener('click', () => {
      if (!img.on && images.filter((x) => x.on).length >= MAX_IMAGES) return show(`最多选 ${MAX_IMAGES} 张图片`, true);
      img.on = !img.on;
      renderImages();
    });
    box.append(el);
  });
  updateImgCount();
}
function updateImgCount() {
  const on = images.filter((x) => x.on && !x.broken).length;
  $('imgCount').textContent = `已选 ${on} / 共 ${images.filter((x) => !x.broken).length} 张`;
}
$('imgAll').addEventListener('click', (e) => { e.preventDefault(); images.forEach((x, i) => { x.on = !x.broken && images.slice(0, i).filter((y) => y.on).length < MAX_IMAGES; }); renderImages(); });
$('imgFirst').addEventListener('click', (e) => { e.preventDefault(); let k = 0; images.forEach((x) => { x.on = !x.broken && k++ < 8; }); renderImages(); });

function renderSkus(x) {
  const vs = x.variants || [];
  $('skuBox').hidden = !vs.length;
  if (!vs.length) return;
  const names = (x.options || []).map((o) => o.name);
  const rows = vs.slice(0, 100).map((v) => `<tr>${names.map((n) => `<td>${esc(v.options[n] || '')}</td>`).join('')}<td class="n">${esc(v.price || '')}</td><td class="n">${v.inventory ?? ''}</td></tr>`).join('');
  $('skuTable').innerHTML = `<table><thead><tr>${names.map((n) => `<th>${esc(n)}</th>`).join('')}<th>价格</th><th>库存</th></tr></thead><tbody>${rows}</tbody></table>`;
  $('skuBox').querySelector('summary').textContent = `SKU 明细（${vs.length}）`;
}

$('send').addEventListener('click', async () => {
  if (!conf.api || !conf.key) { go('settings'); return show('先连接店铺：粘贴后台的连接码', true); }
  const chosen = images.filter((x) => x.on && !x.broken).map((x) => x.url);
  if (!chosen.length) return show('至少选一张图片', true);
  const btn = $('send');
  btn.disabled = true;
  btn.querySelector('span').textContent = '正在采集…';
  const title = $('title').value.trim();
  try {
    const r = await bg({
      type: 'collect', tabId: tab.id, currency: $('currency').value,
      edits: { title: title !== page.extracted.title ? title : undefined, images: chosen, tags: $('tags').value.split(/[,，]/).map((t) => t.trim()).filter(Boolean) },
    });
    if (!r.ok) throw new Error(r.error);
    show(`已加入待审核：${r.variants > 1 ? r.variants + ' 个 SKU，' : ''}${r.images} 张图${r.duplicate ? '（店里可能已有相似商品）' : ''}`, false, conf.adminUrl ? { href: conf.adminUrl, text: '去审核 →' } : null);
    btn.querySelector('span').textContent = '重新采集并更新';
    $('dupChip').hidden = false;
    $('dupChip').textContent = '已采集过 · 待审核';
    checkConnection();
  } catch (e) {
    show(e.message, true);
    btn.querySelector('span').textContent = '采集到待审核';
  } finally {
    btn.disabled = false;
  }
});

// ---------- Batch ----------
async function scan() {
  $('links').innerHTML = '<div class="state"><span class="spinner"></span>正在扫描…</div>';
  try {
    links = (await bg({ type: 'links', tabId: tab.id })).map((l) => ({ ...l, on: true }));
  } catch {
    links = [];
  }
  if (links.length && conf.api) {
    try {
      collectedMap = (await bg({ type: 'status', urls: links.slice(0, 100).map((l) => l.url) })).collected || {};
      links.forEach((l) => { l.collected = collectedMap[l.url]; if (l.collected) l.on = false; });
    } catch { /* still usable without */ }
  }
  renderLinks();
}
$('scan').addEventListener('click', scan);

function renderLinks() {
  $('linkBar').hidden = !links.length;
  if (!links.length) {
    $('links').innerHTML = '<p class="hint">这个页面上没有找到商品链接。也可以在下面粘贴链接。</p>';
  } else {
    $('links').innerHTML = '';
    links.forEach((l) => {
      const row = document.createElement('label');
      row.className = 'li' + (l.image ? '' : ' noimg');
      row.innerHTML = `<input type="checkbox" ${l.on ? 'checked' : ''}>${l.image ? `<img src="${esc(l.image)}" referrerpolicy="no-referrer" loading="lazy" alt="">` : ''}
        <div class="t"><div>${esc(l.title || l.url)}</div><small>${esc(new URL(l.url).pathname)}</small></div>
        ${l.collected ? `<span class="tag ok">${STATUS_TEXT[l.collected.status] || '已采集'}</span>` : '<span></span>'}`;
      row.querySelector('input').addEventListener('change', (e) => { l.on = e.target.checked; updateBatchButton(); });
      $('links').append(row);
    });
  }
  updateBatchButton();
}

function pastedUrls() {
  return $('pasted').value.split(/\s+/).map((u) => u.trim()).filter((u) => /^https?:\/\/\S+$/.test(u));
}
function updateBatchButton() {
  const n = links.filter((l) => l.on).length + pastedUrls().length;
  $('batchGo').disabled = !n;
  $('batchGo').textContent = n ? `开始采集 ${n} 个商品` : '开始采集';
  $('linkSel').textContent = links.length ? `（${links.filter((l) => l.on).length} / ${links.length}）` : '';
  $('linkAll').checked = links.length && links.every((l) => l.on);
}
$('pasted').addEventListener('input', updateBatchButton);
$('linkAll').addEventListener('change', (e) => { links.forEach((l) => { l.on = e.target.checked; }); renderLinks(); });
$('linkNew').addEventListener('click', (e) => { e.preventDefault(); links.forEach((l) => { l.on = !l.collected; }); renderLinks(); });

$('batchGo').addEventListener('click', async () => {
  if (!conf.api || !conf.key) { go('settings'); return show('先连接店铺：粘贴后台的连接码', true); }
  const items = [...links.filter((l) => l.on), ...pastedUrls().map((url) => ({ url }))];
  const r = await bg({ type: 'enqueue', items });
  $('pasted').value = '';
  links.forEach((l) => { l.on = false; });
  renderLinks();
  show(r.added ? `已加入队列 ${r.added} 个，正在后台逐个采集` : '这些链接已经在队列里了');
  renderQueue();
});

async function renderQueue() {
  const { queue = [], paused = false } = await chrome.storage.local.get(['queue', 'paused']);
  const left = queue.filter((q) => q.status === 'waiting' || q.status === 'working').length;
  $('queueCount').hidden = !left;
  $('queueCount').textContent = left;
  $('queueCard').hidden = !queue.length;
  if (!queue.length) return;
  const done = queue.filter((q) => q.status === 'done').length;
  const failed = queue.filter((q) => q.status === 'failed').length;
  $('qBar').style.width = Math.round(((done + failed) / queue.length) * 100) + '%';
  $('qText').textContent = `${done} 个成功 · ${failed} 个失败 · ${left} 个等待${paused && left ? '（已暂停）' : ''}`;
  $('qPause').hidden = paused || !left;
  $('qResume').hidden = !paused || !left;
  $('qRetry').hidden = !failed;
  const icon = { waiting: ['', '等待'], working: ['run', '采集中'], done: ['ok', '完成'], failed: ['err', '失败'] };
  $('qList').innerHTML = queue.slice().reverse().slice(0, 60).map((q) => `
    <div class="li${q.image ? '' : ' noimg'}"><span></span>${q.image ? `<img src="${esc(q.image)}" referrerpolicy="no-referrer" loading="lazy" alt="">` : ''}<div class="t"><div>${esc(q.title || q.url)}</div>${q.error ? `<small class="err-text">${esc(q.error)}</small>` : `<small>${esc(q.variants ? q.variants + ' 个 SKU' : new URL(q.url).hostname)}</small>`}</div>
    <span class="tag ${icon[q.status][0]}">${icon[q.status][1]}</span></div>`).join('');
}
const qAction = (action) => async (e) => { e.preventDefault(); await bg({ type: 'queue', action }); renderQueue(); };
$('qPause').addEventListener('click', qAction('pause'));
$('qResume').addEventListener('click', qAction('resume'));
$('qRetry').addEventListener('click', qAction('retry'));
$('qClear').addEventListener('click', qAction('clear'));
$('qStop').addEventListener('click', qAction('stop'));

// ---------- History ----------
async function renderHistory() {
  const { history = [] } = await chrome.storage.local.get('history');
  const ok = history.filter((h) => h.ok).length;
  $('histSummary').textContent = history.length ? `最近 ${history.length} 条 · 成功 ${ok} · 失败 ${history.length - ok}` : '';
  $('history').innerHTML = history.length ? '' : '<div class="state"><p>还没有采集记录</p></div>';
  history.slice(0, 100).forEach((h) => {
    const row = document.createElement('a');
    row.className = 'li' + (h.image ? '' : ' noimg');
    row.href = h.url; row.target = '_blank';
    row.innerHTML = `<span></span>${h.image ? `<img src="${esc(h.image)}" referrerpolicy="no-referrer" loading="lazy" alt="">` : ''}
      <div class="t"><div style="color: var(--ink)">${esc(h.title || h.url)}</div>
      <small class="${h.ok ? '' : 'err-text'}">${h.ok ? `${esc(h.platform || '')}${h.variants > 1 ? ' · ' + h.variants + ' 个 SKU' : ''} · ${ago(h.at)}` : esc(h.error) + ' · ' + ago(h.at)}</small></div>
      <span class="tag ${h.ok ? 'ok' : 'err'}">${h.ok ? '成功' : '失败'}</span>`;
    $('history').append(row);
  });
}
$('histClear').addEventListener('click', async (e) => { e.preventDefault(); await chrome.storage.local.set({ history: [] }); renderHistory(); });

chrome.storage.onChanged.addListener((c) => {
  if (c.queue || c.paused) renderQueue();
  if (c.history && !$('view-history').hidden) renderHistory();
});

// ---------- Start ----------
(async () => {
  conf = await chrome.storage.local.get(['api', 'key', 'currency', 'tags', 'delay', 'floatButton', 'closeTabs', 'hiddenSites']);
  fillCurrencies($('currency'), conf.currency);
  fillCurrencies($('defCurrency'), conf.currency || 'CNY');
  $('api').value = conf.api || '';
  $('key').value = conf.key || '';
  $('defTags').value = conf.tags || '';
  $('delay').value = conf.delay || 4;
  $('floatButton').checked = conf.floatButton !== false;
  $('closeTabs').checked = conf.closeTabs !== false;
  if (conf.hiddenSites?.length) {
    $('hiddenSitesInfo').hidden = false;
    $('hiddenSitesInfo').innerHTML = `这些网站上隐藏了按钮：${esc(conf.hiddenSites.join('、'))} <a href="#" id="unhide">恢复</a>`;
    $('unhide').onclick = async (e) => { e.preventDefault(); await chrome.storage.local.set({ hiddenSites: [] }); $('hiddenSitesInfo').hidden = true; };
  }
  renderQueue();
  [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (params.get('full')) tab = null;   // the options page has no page to collect
  if (params.get('tabId')) tab = await chrome.tabs.get(Number(params.get('tabId'))).catch(() => null);   // opened in a tab, for another tab

  const view = params.get('view') || (!conf.api || !conf.key ? 'settings' : 'page');
  go(view);
  if (!conf.api || !conf.key) {
    setConn('', '未连接店铺');
    if (view === 'settings') show('先连接店铺：在后台「商品 › 采集 › 浏览器插件」生成连接码，粘贴到下面');
  } else {
    checkConnection();
  }
  if (tab) loadPage();
})();
