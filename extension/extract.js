// Lumée Collector — page reader. Injected into the tab (chrome.scripting) and also loaded as a
// content script on product sites. Defines window.__lumeeExtract(): reads the product the page
// shows, using what each known platform embeds in the page (with SKUs) and generic tags otherwise.
// Runs only when the merchant collects; nothing is sent anywhere from here.
(() => {
  if (window.__lumeeExtract) return;

  const PLATFORMS = [
    { id: '1688', name: '1688', host: /(^|\.)1688\.com$/, product: /\/offer\/\d+|detail\.1688\.com/ },
    { id: 'taobao', name: '淘宝', host: /(^|\.)taobao\.com$/, product: /item\.htm/ },
    { id: 'tmall', name: '天猫', host: /(^|\.)tmall\.(com|hk)$/, product: /item\.htm/ },
    { id: 'aliexpress', name: 'AliExpress', host: /(^|\.)aliexpress\.(com|us|ru)$/, product: /\/item\/\d+/ },
    { id: 'alibaba', name: 'Alibaba', host: /(^|\.)alibaba\.com$/, product: /\/product-detail\// },
    { id: 'temu', name: 'Temu', host: /(^|\.)temu\.com$/, product: /-g-\d+\.html|goods\.html/ },
    { id: 'shein', name: 'SHEIN', host: /(^|\.)shein\.(com|co\.uk|com\.mx)$/, product: /-p-\d+/ },
    { id: 'amazon', name: 'Amazon', host: /(^|\.)amazon\.[a-z.]+$/, product: /\/(dp|gp\/product)\/[A-Z0-9]{10}/ },
    { id: 'ebay', name: 'eBay', host: /(^|\.)ebay\.[a-z.]+$/, product: /\/itm\// },
    { id: 'etsy', name: 'Etsy', host: /(^|\.)etsy\.com$/, product: /\/listing\/\d+/ },
    { id: 'walmart', name: 'Walmart', host: /(^|\.)walmart\.com$/, product: /\/ip\// },
    { id: 'tiktok', name: 'TikTok Shop', host: /(^|\.)tiktok\.com$/, product: /\/(view\/)?product\/\d+|\/pdp\// },
    { id: 'shopee', name: 'Shopee', host: /(^|\.)shopee\.[a-z.]+$/, product: /-i\.\d+\.\d+|\/product\/\d+\/\d+/ },
    { id: 'lazada', name: 'Lazada', host: /(^|\.)lazada\.[a-z.]+$/, product: /\/products\/.*-i\d+/ },
    { id: 'mercadolibre', name: 'Mercado Libre', host: /(^|\.)mercadoli[bv]re\.[a-z.]+$/, product: /\/MLA|\/MLM|\/MLB|\/p\/ML|-_JM/ },
  ];

  const SYMBOLS = { 'R$': 'BRL', 'US$': 'USD', 'MX$': 'MXN', 'C$': 'CAD', 'A$': 'AUD', '€': 'EUR', '£': 'GBP', '¥': 'CNY', '￥': 'CNY', Rp: 'IDR', '฿': 'THB', '₱': 'PHP', RM: 'MYR', '₫': 'VND', '₹': 'INR', '₩': 'KRW' };
  const HOST_CURRENCY = { '1688': 'CNY', taobao: 'CNY', tmall: 'CNY' };

  const clean = (s) => String(s ?? '').replace(/\s+/g, ' ').trim();
  const abs = (u) => {
    if (!u || typeof u !== 'string') return '';
    u = u.trim();
    if (u.startsWith('//')) u = location.protocol + u;
    try { return new URL(u, location.href).href; } catch { return ''; }
  };
  const meta = (n) => document.querySelector(`meta[property="${n}"], meta[name="${n}"], meta[itemprop="${n}"]`)?.content || '';
  const num = (v) => (v === null || v === undefined || v === '' ? '' : String(v).replace(/[^\d.,]/g, '').slice(0, 40));

  // Thumbnails → the full-size image (CDN size suffixes)
  function bigImage(u) {
    u = abs(u);
    if (!u) return '';
    return u
      .replace(/(\.(jpe?g|png|webp))_\d+x\d+[^/]*$/i, '$1')          // alicdn: x.jpg_220x220q90.jpg
      .replace(/\.(\d+x\d+|summ|search|400x400)\.(jpe?g|png|webp)$/i, '.$2') // 1688: x.220x220.jpg
      .replace(/\._[A-Z0-9_,]+_\.(jpe?g|png|webp)$/i, '.$1')          // amazon: x._AC_SX300_.jpg
      .replace(/\/s-l\d+\.(jpe?g|png|webp)/i, '/s-l1600.$1')           // ebay
      .replace(/\?imageView2[^#]*$|\?x-oss-process=[^#]*$/i, '');
  }

  function imagesFromDom(min = 300) {
    return [...document.images]
      .filter((img) => (img.naturalWidth || img.width) >= min && (img.naturalHeight || img.height) >= min)
      .sort((a, b) => (b.naturalWidth * b.naturalHeight) - (a.naturalWidth * a.naturalHeight))
      .slice(0, 30)
      .map((img) => img.currentSrc || img.src || img.dataset.src);
  }

  function priceText(selectors) {
    for (const sel of selectors) {
      for (const el of document.querySelectorAll(sel)) {
        const t = clean(el.getAttribute('content') || el.textContent);
        if (/\d/.test(t) && t.length < 60) return t;
      }
    }
    return '';
  }

  function currencyOf(text) {
    const sym = (String(text).match(/R\$|US\$|MX\$|C\$|A\$|€|£|¥|￥|Rp|฿|₱|RM|₫|₹|₩|\$/) || [])[0];
    if (sym === '$') return '';
    return SYMBOLS[sym] || ((String(text).match(/\b(USD|EUR|GBP|CNY|MXN|BRL|CAD|AUD|JPY|IDR|THB|PHP|MYR|VND|INR)\b/) || [])[1] || '');
  }

  // A JSON object assigned in an inline script: window.runParams = {...}; or "key": {...}
  function scriptJson(pattern) {
    for (const s of document.scripts) {
      const text = s.textContent;
      if (!text || text.length < 50) continue;
      const m = text.match(pattern);
      if (!m) continue;
      const start = text.indexOf('{', m.index + m[0].length - 1);
      if (start < 0) continue;
      let depth = 0, inStr = false, esc = false, q = '';
      for (let i = start; i < text.length; i++) {
        const c = text[i];
        if (inStr) {
          if (esc) esc = false;
          else if (c === '\\') esc = true;
          else if (c === q) inStr = false;
          continue;
        }
        if (c === '"' || c === "'") { inStr = true; q = c; continue; }
        if (c === '{') depth++;
        else if (c === '}' && --depth === 0) {
          try { return JSON.parse(text.slice(start, i + 1)); } catch { break; }
        }
      }
    }
    return null;
  }

  function find(obj, test, depth = 0, seen = new Set()) {
    if (!obj || typeof obj !== 'object' || depth > 12 || seen.has(obj)) return null;
    seen.add(obj);
    if (test(obj)) return obj;
    for (const v of Object.values(obj)) {
      const r = find(v, test, depth + 1, seen);
      if (r) return r;
    }
    return null;
  }

  function jsonLd() {
    const out = [];
    const walk = (n) => {
      if (!n || typeof n !== 'object') return;
      if (Array.isArray(n)) return n.forEach(walk);
      const t = [].concat(n['@type'] || []);
      if (t.includes('Product') || t.includes('ProductGroup')) out.push(n);
      if (n['@graph']) walk(n['@graph']);
    };
    document.querySelectorAll('script[type="application/ld+json"]').forEach((s) => { try { walk(JSON.parse(s.textContent)); } catch { /* bad JSON */ } });
    return out.sort((a, b) => [].concat(b['@type']).includes('ProductGroup') - [].concat(a['@type']).includes('ProductGroup'))[0] || null;
  }

  // ---- Platform readers: return partial product data, merged over the generic read ----
  // AliExpress (2026 pages): the page data comes through window.__INIT_DATA_CALLBACK__(resolve, reject)
  function aeInitData() {
    const cb = window.__INIT_DATA_CALLBACK__;
    if (typeof cb !== 'function') return Promise.resolve(null);
    return new Promise((resolve) => {
      const t = setTimeout(() => resolve(null), 4000);
      try { cb((d) => { clearTimeout(t); resolve(d); }, () => { clearTimeout(t); resolve(null); }); } catch { clearTimeout(t); resolve(null); }
    });
  }

  // "30.918 ₫|30918|" / "US $9.99|9|99" → "30918" / "9.99"
  function aePrice(local, fallback) {
    const parts = String(local || '').split('|');
    if (parts.length >= 2 && /^\d+$/.test(parts[1])) return parts[1] + (/^\d+$/.test(parts[2] || '') ? '.' + parts[2] : '');
    return num(fallback);
  }

  async function aliexpressNew() {
    const d = await aeInitData();
    const r = d?.data?.result;
    if (!r?.PRODUCT_TITLE) return null;
    const out = {
      title: r.PRODUCT_TITLE.text,
      images: r.HEADER_IMAGE_PC?.imagePathList || [],
      vendor: r.SHOP_CARD_PC?.storeName,
    };
    const props = (r.SKU?.skuProperties || []).slice(0, 3);
    const priceMap = r.PRICE?.skuIdStrPriceInfoMap || {};
    if (props.length && r.SKU?.skuPaths?.length) {
      const valueOf = {};
      props.forEach((p) => (p.skuPropertyValues || []).forEach((v) => {
        valueOf[`${p.skuPropertyId}:${v.propertyValueIdLong ?? v.propertyValueId}`] = { name: p.skuPropertyName, value: v.propertyValueDisplayName || v.propertyValueName, image: v.skuPropertyImagePath };
      }));
      out.options = props.map((p) => ({ name: p.skuPropertyName, values: (p.skuPropertyValues || []).map((v) => v.propertyValueDisplayName || v.propertyValueName) }));
      out.variants = r.SKU.skuPaths.map((s) => {
        const opts = {}; let image = '';
        String(s.path || '').split(';').forEach((pair) => {
          const v = valueOf[pair];
          if (v) { opts[v.name] = v.value; image = image || v.image || ''; }
        });
        const pr = priceMap[s.skuIdStr] || priceMap[String(s.skuId)] || {};
        return { options: opts, sku: s.skuIdStr, price: aePrice(pr.salePriceLocal, pr.salePriceString), compare_price: num(pr.originalPrice?.value), inventory: Number.isInteger(s.skuStock) ? s.skuStock : undefined, image };
      });
      out.currency = Object.values(priceMap)[0]?.originalPrice?.currency || '';
    }
    // Specifications and the seller's description
    const specs = (r.PRODUCT_PROP_PC?.outerProps || r.PRODUCT_PROP_PC?.props || []).filter((p) => p.attrName && p.attrValue);
    let desc = '';
    const descUrl = r.DESC?.pcDescUrl || r.DESC?.msiteDescUrl;
    if (descUrl) {
      try { desc = await (await fetch(descUrl, { credentials: 'omit' })).text(); } catch { /* description is optional */ }
      desc = desc.replace(/<script[\s\S]*?<\/script>/gi, '').replace(/<style[\s\S]*?<\/style>/gi, '');
    }
    out.description = (specs.length ? '<table>' + specs.map((p) => `<tr><th>${esc(p.attrName)}</th><td>${esc(p.attrValue)}</td></tr>`).join('') + '</table>' : '') + desc;
    return out;
  }

  function esc(s) {
    return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }

  const READERS = {
    async aliexpress() {
      const fresh = await aliexpressNew();
      if (fresh) return fresh;
      // Older pages: window.runParams
      const d = window.runParams?.data || scriptJson(/window\.runParams\s*=\s*\{?/)?.data || scriptJson(/"skuModule"\s*:/) && { skuModule: scriptJson(/"skuModule"\s*:/) } || {};
      const sku = d.skuModule || d.skuComponent;
      const out = {
        title: d.titleModule?.subject || d.productInfoComponent?.subject,
        images: d.imageModule?.imagePathList || d.imageComponent?.imagePathList,
        vendor: d.storeModule?.storeName || d.sellerComponent?.storeName,
      };
      const props = sku?.productSKUPropertyList || d.skuComponent?.productSKUPropertyList;
      const list = sku?.skuPriceList || d.priceComponent?.skuPriceList;
      if (props && list) {
        const valueMap = {};
        out.options = props.slice(0, 3).map((p) => {
          (p.skuPropertyValues || []).forEach((v) => {
            valueMap[`${p.skuPropertyId}:${v.propertyValueId || v.propertyValueIdLong}`] = { name: p.skuPropertyName, value: v.propertyValueDisplayName || v.propertyValueName, image: v.skuPropertyImagePath };
          });
          return { name: p.skuPropertyName, values: (p.skuPropertyValues || []).map((v) => v.propertyValueDisplayName || v.propertyValueName) };
        });
        out.variants = list.map((s) => {
          const opts = {}; let image = '';
          String(s.skuPropIds || s.skuAttr || '').split(/[,;]/).forEach((part, i) => {
            const id = part.split('#')[0];
            const pid = props[i]?.skuPropertyId;
            const v = valueMap[`${pid}:${id.includes(':') ? id.split(':')[1] : id}`] || valueMap[id];
            if (v) { opts[v.name] = v.value; image = image || v.image; }
          });
          const val = s.skuVal || {};
          return { options: opts, price: num(val.skuActivityAmount?.value ?? val.skuAmount?.value ?? val.actSkuCalPrice ?? val.skuCalPrice), compare_price: num(val.skuAmount?.value ?? val.skuCalPrice), inventory: val.availQuantity ?? undefined, image };
        });
        out.currency = list[0]?.skuVal?.skuAmount?.currency || '';
      }
      return out;
    },

    '1688'() {
      // Offer pages have moved their data between these over the years
      const init = { a: window.__INIT_DATA || scriptJson(/window\.__INIT_DATA\s*=\s*/), b: window.context || scriptJson(/window\.context\s*=\s*/),
        c: window.__GLOBAL_DATA || scriptJson(/window\.__GLOBAL_DATA\s*=\s*/), d: window.iDetailData, e: window.iDetailConfig };
      const model = find(init, (o) => o.skuProps && (o.skuInfoMap || o.skuInfoMapOriginal)) || window.iDetailData?.sku || null;
      const out = {
        title: find(init, (o) => typeof o.subject === 'string')?.subject || clean(document.querySelector('.title-text, .d-title, h1')?.textContent),
        images: (find(init, (o) => Array.isArray(o.offerImgList))?.offerImgList) || (find(init, (o) => Array.isArray(o.images) && o.images[0]?.fullPathImageURI)?.images || []).map((i) => i.fullPathImageURI).filter(Boolean).concat([...document.querySelectorAll('.detail-gallery-turn img, .tab-trigger img, .img-list-wrapper img, [class*="gallery"] img')].map((i) => i.src)),
        vendor: find(init, (o) => typeof o.companyName === 'string')?.companyName,
        currency: 'CNY',
      };
      if (model) {
        const props = (model.skuProps || []).slice(0, 3);
        out.options = props.map((p) => ({ name: p.prop, values: (p.value || []).map((v) => v.name) }));
        const imgOf = {};
        props.forEach((p) => (p.value || []).forEach((v) => { if (v.imageUrl) imgOf[v.name] = v.imageUrl; }));
        const map = model.skuInfoMap || model.skuInfoMapOriginal || {};
        const base = model.priceRange?.[0]?.[1] ?? model.price ?? model.refPrice;
        out.variants = Object.entries(map).map(([key, s]) => {
          const vals = key.split(/&gt;|>/);
          const opts = {};
          props.forEach((p, i) => { if (vals[i] !== undefined) opts[p.prop] = vals[i]; });
          return { options: opts, price: num(s.discountPrice ?? s.price ?? base), inventory: s.canBookCount ?? undefined, image: imgOf[vals[0]] || '' };
        });
        out.price = num(base ?? model.priceRangeOriginal?.[0]?.[1]);
      }
      out.price = out.price || num(priceText(['.price-now', '.price-text', '[class*="price-value"]', '.price', '[class*="Price"]']));
      return out;
    },

    taobao() { return READERS.tmall(); },
    tmall() {
      const out = { currency: 'CNY' };
      out.title = clean(document.querySelector('[class*="mainTitle"], [class*="ItemTitle"], .tb-main-title, h1')?.textContent);
      out.images = [...document.querySelectorAll('[class*="thumbnail"] img, [class*="Thumbnail"] img, #J_UlThumb img, [class*="picGallery"] img')].map((i) => i.src);
      out.price = num(priceText(['[class*="highlightPrice"] [class*="text"]', '[class*="Price--priceText"]', '.tm-price', '.tb-rmb-num', '[class*="price"]']));
      const groups = [...document.querySelectorAll('[class*="skuItem"], [class*="SkuContent"] > div, .tb-sku dl, .tm-sale-prop')].slice(0, 3);
      const options = groups.map((g) => ({
        name: clean(g.querySelector('[class*="ItemLabel"], [class*="labelText"], dt')?.textContent).replace(/[:：]$/, ''),
        values: [...g.querySelectorAll('[class*="valueItem"], [class*="ValueItem"], dd li')].map((v) => clean(v.getAttribute('title') || v.textContent)).filter(Boolean),
      })).filter((o) => o.name && o.values.length);
      if (options.length) out.options = options;
      return out;
    },

    temu() {
      const raw = window.rawData || scriptJson(/window\.rawData\s*=\s*/) || {};
      const goods = find(raw, (o) => o.goodsName && (o.gallery || o.hdThumbUrl)) || find(raw, (o) => typeof o.goodsName === 'string') || {};
      const out = { title: goods.goodsName, images: (goods.gallery || []).map((g) => g.url || g) };
      const skus = find(raw, (o) => Array.isArray(o.skuList) && o.skuList.length && o.skuList[0].specs)?.skuList;
      if (skus) {
        const names = [];
        out.variants = skus.map((s) => {
          const opts = {};
          (s.specs || []).forEach((sp) => { const n = sp.specKey || sp.parentSpecName; opts[n] = sp.specValue || sp.specName; if (!names.includes(n)) names.push(n); });
          return { options: opts, price: num(s.normalPriceStr || s.priceStr || (s.normalPrice ?? s.price) / 100), compare_price: num(s.normalLinePriceStr || s.linePriceStr), image: s.thumbUrl || '' };
        });
        out.options = names.slice(0, 3).map((n) => ({ name: n, values: [...new Set(out.variants.map((v) => v.options[n]).filter(Boolean))] }));
      }
      out.price = num(priceText(['[class*="goodsPrice"]', '[data-type="price"]', '[class*="price"]']));
      return out;
    },

    amazon() {
      const out = {
        title: clean(document.querySelector('#productTitle')?.textContent),
        vendor: clean(document.querySelector('#bylineInfo')?.textContent).replace(/^(Visit the |Brand: )/, '').replace(/ Store$/, ''),
        price: num(priceText(['.priceToPay .a-offscreen', '#corePrice_feature_div .a-offscreen', '#priceblock_ourprice', '#priceblock_dealprice', '.a-price .a-offscreen'])),
        compare_price: num(priceText(['.basisPrice .a-offscreen', '.a-text-price[data-a-strike] .a-offscreen'])),
        description: [...document.querySelectorAll('#feature-bullets li')].map((li) => '<li>' + clean(li.textContent) + '</li>').join(''),
        category: clean([...document.querySelectorAll('#wayfinding-breadcrumbs_feature_div li a')].pop()?.textContent),
      };
      if (out.description) out.description = '<ul>' + out.description + '</ul>';
      const imgs = [];
      for (const s of document.scripts) {
        const m = s.textContent.match(/'colorImages'\s*:\s*\{\s*'initial'\s*:\s*(\[.*?\])\s*\}/s);
        if (m) { try { JSON.parse(m[1]).forEach((i) => imgs.push(i.hiRes || i.large)); } catch { /* */ } break; }
      }
      const land = document.querySelector('#landingImage');
      if (!imgs.length && land?.dataset.aDynamicImage) { try { imgs.push(...Object.keys(JSON.parse(land.dataset.aDynamicImage))); } catch { /* */ } }
      out.images = imgs;
      out.currency = currencyOf(out.price ? document.querySelector('.priceToPay .a-offscreen, .a-price .a-offscreen')?.textContent || '' : '');
      return out;
    },

    shein() {
      const d = window.gbRawData || scriptJson(/gbRawData\s*=\s*/) || {};
      const info = find(d, (o) => o.goods_name && o.goods_img) || {};
      return {
        title: info.goods_name,
        images: [info.goods_img, ...((find(d, (o) => Array.isArray(o.goods_images))?.goods_images) || []).map((i) => i.origin_image || i.goods_img)].filter(Boolean),
        price: num(info.salePrice?.amount ?? info.sale_price?.amount),
        compare_price: num(info.retailPrice?.amount ?? info.retail_price?.amount),
      };
    },

    ebay() {
      return {
        title: clean(document.querySelector('.x-item-title__mainTitle, #itemTitle')?.textContent).replace(/^Details about\s*/, ''),
        price: num(priceText(['.x-price-primary', '#prcIsum'])),
        images: [...document.querySelectorAll('.ux-image-carousel-item img, #icImg')].map((i) => i.dataset.zoomSrc || i.src),
      };
    },

    etsy() {
      return { images: [...document.querySelectorAll('[data-carousel-pane] img, .listing-page-image-carousel-component img')].map((i) => i.dataset.srcZoom || i.src) };
    },
  };

  function detectPlatform() {
    const host = location.hostname.replace(/^www\./, '');
    const p = PLATFORMS.find((x) => x.host.test(host));
    if (p) return { ...p, isProduct: p.product.test(location.href) };
    const shopify = !!(window.Shopify || document.querySelector('meta[name="shopify-digital-wallet"], link[href*="cdn.shopify.com"]'));
    const woo = !!document.querySelector('body.woocommerce, body.single-product');
    const ld = !!jsonLd();
    const og = /product/i.test(meta('og:type'));
    return {
      id: shopify ? 'shopify' : woo ? 'woocommerce' : 'web',
      name: shopify ? 'Shopify' : woo ? 'WooCommerce' : host,
      isProduct: ld || og || (shopify && /\/products\//.test(location.pathname)) || (woo && document.body.classList.contains('single-product')),
    };
  }

  function generic() {
    const ld = jsonLd();
    const out = {};
    if (ld) {
      const offer = [].concat(ld.offers || [])[0] || {};
      out.title = clean(ld.name);
      out.description = ld.description || '';
      out.images = [].concat(ld.image || []).map((i) => (typeof i === 'string' ? i : i?.url || i?.contentUrl));
      out.price = num(offer.price ?? offer.lowPrice ?? offer.priceSpecification?.price);
      out.currency = offer.priceCurrency || '';
      out.vendor = typeof ld.brand === 'string' ? ld.brand : ld.brand?.name;
      out.sku = ld.sku;
    }
    out.title = out.title || clean(meta('og:title') || document.querySelector('h1')?.textContent || document.title);
    out.description = out.description || meta('og:description') || meta('description');
    out.images = [meta('og:image'), ...(out.images || []), ...imagesFromDom()];
    const pt = priceText(['[itemprop="price"]', 'meta[property="product:price:amount"]', '[class*="price" i]', '[id*="price" i]']);
    out.price = out.price || num(pt);
    out.currency = out.currency || meta('product:price:currency') || currencyOf(pt);
    return out;
  }

  window.__lumeeExtract = async function (opts = {}) {
    const platform = detectPlatform();
    let data = generic();
    try {
      const own = (await READERS[platform.id]?.()) || {};
      for (const [k, v] of Object.entries(own)) {
        if (v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)) continue;
        data[k] = k === 'images' ? [...v, ...data.images] : v;
      }
    } catch (e) {
      data.readerError = String(e?.message || e);
    }
    const images = [];
    for (const u of data.images || []) {
      const b = bigImage(typeof u === 'string' ? u : u?.url);
      if (/^https?:/.test(b) && !/sprite|logo|icon|avatar|placeholder|\.svg|\.gif/i.test(b) && !images.includes(b)) images.push(b);
    }
    const variants = (data.variants || []).filter((v) => v && v.options && Object.keys(v.options).length).slice(0, 300)
      .map((v) => ({ ...v, price: v.price ? String(v.price) : undefined, compare_price: v.compare_price ? String(v.compare_price) : undefined, image: v.image ? bigImage(v.image) : undefined,
        inventory: Number.isInteger(v.inventory) ? v.inventory : undefined, sku: v.sku ? String(v.sku).slice(0, 100) : undefined }));
    const prices = variants.map((v) => parseFloat(String(v.price || '').replace(/,/g, ''))).filter((n) => n > 0);
    const currency = (data.currency || HOST_CURRENCY[platform.id] || '').toUpperCase();
    const extracted = {
      title: clean(data.title).slice(0, 480),
      description: String(data.description || '').slice(0, 50000),
      images: images.slice(0, 50),
      // With SKUs, the lowest SKU price is the base price (the page's display price may be formatted for the visitor)
      price: String((prices.length ? Math.min(...prices) : data.price) || '').slice(0, 40),
      compare_price: data.compare_price ? String(data.compare_price).slice(0, 40) : undefined,
      currency: /^[A-Z]{3}$/.test(currency) ? currency : undefined,
      vendor: data.vendor ? clean(data.vendor).slice(0, 190) : undefined,
      category: data.category ? clean(data.category).slice(0, 190) : undefined,
      sku: data.sku ? String(data.sku).slice(0, 100) : undefined,
      options: variants.length ? (data.options || []).filter((o) => o.name && o.values?.length).slice(0, 3).map((o) => ({ name: clean(o.name).slice(0, 60), values: o.values.map((v) => clean(v).slice(0, 190)) })) : undefined,
      variants: variants.length ? variants : undefined,
    };
    const result = { url: location.href, platform: platform.id, platformName: platform.name, isProduct: platform.isProduct || !!extracted.variants, extracted };
    if (opts.html !== false) {
      const html = document.documentElement.outerHTML;
      result.html = html.length > 1500000 ? html.slice(0, 1500000) : html;
    }
    if (data.readerError) result.readerError = data.readerError;
    return result;
  };

  // Product links on a listing / search / store page, for batch collecting
  window.__lumeeLinks = function () {
    const host = location.hostname.replace(/^www\./, '');
    const p = PLATFORMS.find((x) => x.host.test(host));
    const isProduct = (u) => (p ? p.product.test(u) : /\/products?\/|\/item\/|\/dp\/|\/p\/|-p-\d+|\/offer\/\d+/i.test(u));
    const seen = new Map();
    for (const a of document.querySelectorAll('a[href]')) {
      let u = abs(a.getAttribute('href'));
      if (!/^https?:/.test(u) || u === location.href) continue;
      try {
        const url = new URL(u);
        url.hash = '';
        // Keep the query only where it carries the product id
        if (!/[?&](id|goods_id|item_id)=/.test(url.search)) url.search = '';
        if (/aliexpress\./.test(url.hostname)) url.hostname = 'www.aliexpress.com';
        u = url.href;
      } catch { continue; }
      if (!isProduct(u) || seen.has(u)) continue;
      const img = a.querySelector('img') || a.closest('li, article, [class*="card" i], [class*="item" i]')?.querySelector('img');
      const title = clean(a.getAttribute('title') || img?.alt || a.textContent).slice(0, 140);
      seen.set(u, { url: u, title, image: img ? bigImage(img.currentSrc || img.src || img.dataset.src) : '' });
      if (seen.size >= 200) break;
    }
    return [...seen.values()];
  };

  window.__lumeePlatform = detectPlatform;

  // Is the site asking for a login or a human check instead of showing the product?
  window.__lumeeBlocked = function () {
    const u = location.href;
    const text = (document.body?.innerText || '').slice(0, 3000);
    if (/login\.(taobao|1688|tmall|aliexpress)\.|passport\.|\/login|signin|sign-in/i.test(u) || (/temu\.com/.test(u) && /\bLogin\b/.test(document.title))) return 'login';
    if (/punish|captcha|_____tmd_____|x5sec|verify|validate|robot|errors\/validateCaptcha/i.test(u)) return 'captcha';
    // Only what the visitor can see: sites keep hidden anti-bot frames on normal pages
    const visible = (el) => { const r = el.getBoundingClientRect(); return r.width > 60 && r.height > 30 && getComputedStyle(el).visibility !== 'hidden'; };
    if ([...document.querySelectorAll('#nc_1_wrapper, .nc-container, #baxia-dialog-content, iframe[src*="captcha" i], iframe[src*="punish" i], form[action*="validateCaptcha"], #px-captcha, .g-recaptcha, .h-captcha, [class*="slider-captcha" i]')].some(visible)) return 'captcha';
    if (/Access restricted|Robot Check|unusual traffic|请按住滑块|拖动到最右边|访问验证|安全验证|Are you a human|验证码/i.test(text)) return 'captcha';
    return '';
  };

  // Scroll down like a reader so lazy lists and galleries load
  window.__lumeeScroll = async function (steps = 8) {
    for (let i = 0; i < steps; i++) {
      window.scrollBy(0, window.innerHeight * (0.6 + Math.random() * 0.4));
      await new Promise((r) => setTimeout(r, 450 + Math.random() * 500));
    }
    window.scrollTo(0, document.body.scrollHeight);
    await new Promise((r) => setTimeout(r, 1200));
    return document.body.scrollHeight;
  };

  // Go to the next page of a listing: a link when there is one, otherwise the pager's button
  window.__lumeeNext = function () {
    const link = document.querySelector('a[rel="next"], link[rel="next"], a.s-pagination-next, a[aria-label*="next" i], a[aria-label*="下一页"], .next-pagination-item.next, a.pagination-next');
    if (link?.href) { location.href = link.href; return 'link'; }
    // Most specific first; never the "jump 5 pages" (…) buttons, whose class also says next
    const usable = (el) => el && !el.disabled && el.getAttribute('aria-disabled') !== 'true' && !/disabled|jump/i.test(String(el.className) + ' ' + String(el.parentElement?.className));
    const selectors = ['.comet-pagination-next', '.next-pagination-item.next', '.pagination-next', 'button[aria-label*="next" i]', '[class*="pagination" i] [class*="next" i]', '[class*="pager" i] [class*="next" i]'];
    let btn = null;
    for (const sel of selectors) { btn = [...document.querySelectorAll(sel)].find(usable); if (btn) break; }
    btn = btn || [...document.querySelectorAll('a, button')].find((el) => usable(el) && /^(下一页|下页|Next|Next page|›|»|>)$/i.test(el.textContent.trim()));
    // The match may be the pager's <li>: click the button or link inside it
    const target = btn && (btn.matches('a, button') ? btn : btn.querySelector('a, button') || btn);
    if (target && !target.disabled) {
      if (target.href && !/^javascript:/i.test(target.getAttribute('href') || '')) { location.href = target.href; return 'link'; }
      target.click();
      return 'button';
    }
    // A pager whose "next" is disabled: this is the last page
    if (document.querySelector('.comet-pagination-next.comet-pagination-disabled, [class*="pagination" i] [class*="next" i][class*="disabled" i], [class*="pagination" i] [class*="next" i] button[disabled], [class*="pagination" i] button[class*="next" i][disabled], a[aria-label*="next" i][aria-disabled="true"]')) return '';
    // ?page=N in the address
    const url = new URL(location.href);
    const key = ['page', 'pageNo', 'beginPage', 'p'].find((k) => url.searchParams.has(k));
    if (key) { url.searchParams.set(key, String((parseInt(url.searchParams.get(key), 10) || 1) + 1)); location.href = url.href; return 'param'; }
    return '';
  };
})();
