{{--
    Analytics / ad pixels (GA4, Meta, TikTok) + standard e-commerce events.
    Included in <head> by every storefront layout. Pages add events with:
        @push('tracking') lumeeTrack('view_item', {...}); @endpush
    Ids come from App\Marketing\Tracking and are validated; event data is @json-encoded.
--}}
@php($tracking = \App\Marketing\Tracking::for($store))
@if ($tracking->any())
<script>
(function () {
    var cfg = @json($tracking->all());
    var queue = [], loaded = false;

    function load() {
        if (loaded) return; loaded = true;
        if (cfg.ga4) {
            var s = document.createElement('script'); s.async = true; s.src = 'https://www.googletagmanager.com/gtag/js?id=' + cfg.ga4; document.head.appendChild(s);
            window.dataLayer = window.dataLayer || []; window.gtag = function () { dataLayer.push(arguments); };
            gtag('js', new Date()); gtag('config', cfg.ga4);
        }
        if (cfg.meta) {
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', cfg.meta); fbq('track', 'PageView');
        }
        if (cfg.tiktok) {
            !function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=r;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};n=d.createElement("script");n.type="text/javascript";n.async=!0;n.src=r+"?sdkid="+e+"&lib="+t;e=d.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)}}(window,document,'ttq');
            ttq.load(cfg.tiktok); ttq.page();
        }
        queue.forEach(function (e) { send(e[0], e[1]); }); queue = [];
    }

    // One event, sent to every configured pixel with its own naming
    function send(name, d) {
        var items = (d.items || []).map(function (i) { return { item_id: String(i.id), item_name: i.name, price: i.price, quantity: i.quantity || 1 }; });
        var ids = items.map(function (i) { return i.item_id; });
        var ga = { currency: d.currency, value: d.value, items: items }, meta = { currency: d.currency, value: d.value, content_ids: ids, content_type: 'product' };
        var tt = { currency: d.currency, value: d.value, contents: items.map(function (i) { return { content_id: i.item_id, content_name: i.item_name, price: i.price, quantity: i.quantity }; }) };
        if (d.transaction_id) { ga.transaction_id = d.transaction_id; }
        var map = {
            view_item: ['view_item', 'ViewContent', 'ViewContent'],
            add_to_cart: ['add_to_cart', 'AddToCart', 'AddToCart'],
            begin_checkout: ['begin_checkout', 'InitiateCheckout', 'InitiateCheckout'],
            purchase: ['purchase', 'Purchase', 'CompletePayment'],
        }[name];
        if (!map) return;
        if (cfg.ga4 && window.gtag) gtag('event', map[0], ga);
        if (cfg.meta && window.fbq) fbq('track', map[1], meta, d.transaction_id ? { eventID: 'order-' + d.transaction_id } : undefined);
        if (cfg.tiktok && window.ttq) ttq.track(map[2], tt, d.transaction_id ? { event_id: 'order-' + d.transaction_id } : undefined);
    }

    window.lumeeTrack = function (name, data) {
        // A purchase is reported once per order, even if the shopper reloads the order page
        if (name === 'purchase' && data.transaction_id) {
            try { var k = 'lumee_tracked_' + data.transaction_id; if (localStorage.getItem(k)) return; localStorage.setItem(k, '1'); } catch (e) {}
        }
        loaded ? send(name, data) : queue.push([name, data]);
    };

    var choice = null;
    try { choice = localStorage.getItem('lumee_consent'); } catch (e) {}
    if (!cfg.consent || choice === 'granted') { load(); return; }
    if (choice === 'denied') return;

    // Cookie banner: nothing is loaded until the shopper accepts
    document.addEventListener('DOMContentLoaded', function () {
        var bar = document.createElement('div');
        bar.className = 'lumee-consent';
        bar.setAttribute('role', 'dialog');
        bar.setAttribute('aria-label', @json(__('Cookie consent')));
        bar.innerHTML = '<p></p><div><button type="button" data-v="denied"></button><button type="button" data-v="granted"></button></div>';
        bar.querySelector('p').textContent = @json(__('We use cookies to measure visits and improve our ads. Is that OK?'));
        bar.querySelector('[data-v="denied"]').textContent = @json(__('Decline'));
        bar.querySelector('[data-v="granted"]').textContent = @json(__('Accept'));
        bar.addEventListener('click', function (e) {
            var v = e.target.getAttribute && e.target.getAttribute('data-v');
            if (!v) return;
            try { localStorage.setItem('lumee_consent', v); } catch (err) {}
            bar.remove();
            if (v === 'granted') load();
        });
        document.body.appendChild(bar);
    });
})();
</script>
<style>
    .lumee-consent { position: fixed; left: 16px; right: 16px; bottom: 16px; z-index: 1000; max-width: 560px; margin: 0 auto; background: #fff; color: #1C2621;
        border: 1px solid #E5EAE7; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,.12); padding: 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; font-size: 14px; }
    .lumee-consent p { margin: 0; flex: 1 1 260px; line-height: 1.5; }
    .lumee-consent div { display: flex; gap: 8px; }
    .lumee-consent button { border: 1px solid #D5DDD8; background: #fff; border-radius: 8px; padding: 8px 14px; cursor: pointer; }
    .lumee-consent button[data-v="granted"] { background: var(--rose-gold-600, #0E8A5F); border-color: transparent; color: #fff; }
</style>
@else
<script>window.lumeeTrack = function () {};</script>
@endif
@if ($tracking->any())
<script>
    document.addEventListener('DOMContentLoaded', function () {
        @stack('tracking')
    });
</script>
@endif
