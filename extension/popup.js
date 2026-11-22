// Lumée Collector: reads the product page the merchant has open and sends it to the store's
// collection list (POST {api}/api/collector/pages with the store's collector key). The server
// parses the page's structured data; what this script picks out is only a fallback.
const $ = (id) => document.getElementById(id);

function show(text, error = false) {
  const m = $('msg');
  m.textContent = text;
  m.className = error ? 'msg err' : 'msg';
  m.hidden = false;
}

function panel(settings) {
  $('settings').hidden = !settings;
  $('collect').hidden = settings;
  $('toggle').textContent = settings ? '返回' : '设置';
}

async function load() {
  const s = await chrome.storage.local.get(['api', 'key', 'currency']);
  $('api').value = s.api || '';
  $('key').value = s.key || '';
  $('currency').value = s.currency || 'CNY';
  panel(!s.api || !s.key);
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  let host = '—';
  try { host = new URL(tab.url).host; } catch { /* chrome:// pages etc. */ }
  $('page').textContent = '当前页面：' + host;
}

$('toggle').addEventListener('click', () => panel($('settings').hidden));

$('save').addEventListener('click', async () => {
  let api;
  try {
    api = new URL($('api').value.trim()).origin;
  } catch {
    return show('后台地址格式不对，例如 https://api.your-store.com', true);
  }
  const key = $('key').value.trim();
  if (!/^lmc_\d+_[A-Za-z0-9]{40}$/.test(key)) return show('连接密钥格式不对，请从后台复制完整的密钥', true);
  // Permission to call the admin API (that site only)
  const granted = await chrome.permissions.request({ origins: [api + '/*'] });
  if (!granted) return show('需要允许访问后台地址才能发送商品', true);
  await chrome.storage.local.set({ api, key });
  show('已保存');
  panel(false);
});

$('currency').addEventListener('change', () => chrome.storage.local.set({ currency: $('currency').value }));

// Runs inside the page: its HTML plus a best-effort guess at title, images and price
function grab() {
  const meta = (n) => document.querySelector(`meta[property="${n}"], meta[name="${n}"], meta[itemprop="${n}"]`)?.content || '';
  const abs = (u) => { try { return new URL(u, location.href).href; } catch { return ''; } };
  const images = [];
  const add = (u) => { u = abs(u || ''); if (/^https?:/.test(u) && !images.includes(u)) images.push(u); };
  add(meta('og:image'));
  [...document.images]
    .filter((img) => (img.naturalWidth || img.width) >= 300 && (img.naturalHeight || img.height) >= 300)
    .sort((a, b) => (b.naturalWidth * b.naturalHeight) - (a.naturalWidth * a.naturalHeight))
    .slice(0, 20)
    .forEach((img) => add(img.currentSrc || img.src || img.dataset.src));
  const priceEl = [...document.querySelectorAll('[itemprop="price"], [class*="price" i], [id*="price" i]')]
    .find((el) => /\d/.test(el.getAttribute('content') || el.textContent || ''));
  const priceText = (priceEl?.getAttribute('content') || priceEl?.textContent || '').trim();
  const symbol = (priceText.match(/R\$|US\$|MX\$|€|£|¥|￥|\$|Rp|฿|₱|RM/) || [])[0];
  const symbols = { 'R$': 'BRL', 'US$': 'USD', 'MX$': 'MXN', '€': 'EUR', '£': 'GBP', '¥': 'CNY', '￥': 'CNY', Rp: 'IDR', '฿': 'THB', '₱': 'PHP', RM: 'MYR' };
  const currency = meta('product:price:currency') || meta('priceCurrency') || symbols[symbol] || '';
  const html = document.documentElement.outerHTML;
  return {
    url: location.href,
    html: html.length > 1500000 ? html.slice(0, 1500000) : html,
    extracted: {
      title: (meta('og:title') || document.querySelector('h1')?.textContent || document.title || '').trim().slice(0, 480),
      description: (meta('og:description') || meta('description')).slice(0, 5000),
      images: images.slice(0, 30),
      price: ((priceText.match(/[\d.,]+/) || [])[0] || '').slice(0, 40),
      currency: /^[A-Z]{3}$/.test(currency) ? currency : undefined,
    },
  };
}

$('go').addEventListener('click', async () => {
  const { api, key } = await chrome.storage.local.get(['api', 'key']);
  if (!api || !key) return panel(true);
  $('go').disabled = true;
  show('正在读取页面…');
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab?.id || !/^https?:/.test(tab.url || '')) throw new Error('这个页面不能采集');
    const [{ result }] = await chrome.scripting.executeScript({ target: { tabId: tab.id }, func: grab });
    show('正在发送…');
    const res = await fetch(api + '/api/collector/pages', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Collector-Key': key },
      body: JSON.stringify({ ...result, currency: $('currency').value }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(body.message || `发送失败（HTTP ${res.status}）`);
    show(`已加入待审核：${body.title}`);
  } catch (e) {
    show(e.message || String(e), true);
  } finally {
    $('go').disabled = false;
  }
});

load();
