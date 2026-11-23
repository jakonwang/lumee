{{-- AI shopping assistant: a chat bubble on every storefront page (see App\Assistant\ShoppingAssistant).
     The conversation stays in this browser tab (sessionStorage); each question is sent with the last few turns. --}}
@php
    $greeting = trim((string) (\App\Assistant\ShoppingAssistant::status($store)['greeting'] ?? '')) ?: __('Hi! Looking for something? Ask me about sizes, materials, shipping or gift ideas.');
@endphp
<div id="lm-assistant" class="lm-as" data-url="{{ app(\App\I18n\Localization::class)->url('/assistant') }}" data-token="{{ csrf_token() }}"
    data-viewing="{{ isset($product) && $product instanceof \App\Models\Product ? $product->handle : '' }}"
    data-error="{{ __('Sorry, I can’t answer right now. Please try again in a moment.') }}" data-sold-out="{{ __('Sold out') }}">
    <button type="button" class="lm-as-open" aria-expanded="false" aria-controls="lm-as-panel" aria-label="{{ __('Chat with our shopping assistant') }}">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"/><path d="M8.5 11h.01M12 11h.01M15.5 11h.01" stroke-width="2.6" stroke-linecap="round"/></svg>
    </button>
    <section id="lm-as-panel" class="lm-as-panel" role="dialog" aria-label="{{ __('Shopping assistant') }}" hidden>
        <header>
            <strong>{{ __('Shopping assistant') }}</strong>
            <span>{{ __('AI · answers may not be perfect') }}</span>
            <button type="button" class="lm-as-close" aria-label="{{ __('Close') }}">×</button>
        </header>
        <div class="lm-as-log" aria-live="polite">
            <div class="lm-as-msg bot">{{ $greeting }}</div>
        </div>
        <form class="lm-as-form">
            <input type="text" name="q" maxlength="500" autocomplete="off" placeholder="{{ __('Ask a question…') }}" aria-label="{{ __('Your question') }}" required>
            <button type="submit" aria-label="{{ __('Send') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </form>
    </section>
</div>
<style>
    .lm-as { position: fixed; inset-inline-end: 18px; bottom: 18px; z-index: 60; font-size: 14px; }
    .lm-as-open { width: 54px; height: 54px; border-radius: 50%; border: 0; background: var(--rose-gold-500, #111); color: var(--on-primary, #fff); box-shadow: 0 8px 24px rgba(0,0,0,.18); cursor: pointer; display: grid; place-items: center; }
    .lm-as-panel { position: absolute; inset-inline-end: 0; bottom: 66px; width: min(360px, calc(100vw - 24px)); height: min(520px, calc(100vh - 120px)); background: var(--surface, #fff); color: var(--ink, #111); border: 1px solid var(--border, #e5e7eb); border-radius: 16px; box-shadow: 0 16px 48px rgba(0,0,0,.18); display: flex; flex-direction: column; overflow: hidden; }
    .lm-as-panel[hidden] { display: none; }
    .lm-as-panel header { display: grid; grid-template-columns: 1fr auto; padding: 12px 14px; border-bottom: 1px solid var(--border, #e5e7eb); }
    .lm-as-panel header span { grid-column: 1; font-size: 11px; color: var(--ink-muted, #6b7280); }
    .lm-as-close { grid-row: 1 / span 2; grid-column: 2; border: 0; background: none; font-size: 22px; cursor: pointer; color: inherit; }
    .lm-as-log { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 10px; }
    .lm-as-msg { max-width: 85%; padding: 9px 12px; border-radius: 14px; line-height: 1.5; white-space: pre-line; }
    .lm-as-msg.bot { background: var(--surface-muted, #f3f4f6); align-self: flex-start; border-end-start-radius: 4px; }
    .lm-as-msg.me { background: var(--rose-gold-500, #111); color: var(--on-primary, #fff); align-self: flex-end; border-end-end-radius: 4px; }
    .lm-as-msg.typing { color: var(--ink-muted, #6b7280); }
    .lm-as-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; align-self: stretch; }
    .lm-as-card { display: block; border: 1px solid var(--border, #e5e7eb); border-radius: 10px; overflow: hidden; color: inherit; text-decoration: none; font-size: 12.5px; }
    .lm-as-card img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; background: var(--surface-muted, #f3f4f6); }
    .lm-as-card span { display: block; padding: 6px 8px 0; }
    .lm-as-card b { display: block; padding: 2px 8px 8px; }
    .lm-as-form { display: flex; gap: 8px; padding: 10px; border-top: 1px solid var(--border, #e5e7eb); }
    .lm-as-form input { flex: 1; min-width: 0; padding: 10px 12px; border: 1px solid var(--border, #e5e7eb); border-radius: 999px; font: inherit; background: transparent; color: inherit; }
    .lm-as-form button { width: 40px; border: 0; border-radius: 50%; background: var(--rose-gold-500, #111); color: var(--on-primary, #fff); cursor: pointer; display: grid; place-items: center; }
    @media (max-width: 520px) { .lm-as { inset-inline-end: 12px; bottom: 12px; } .lm-as-open { width: 48px; height: 48px; } }
</style>
<script>
(function () {
    var root = document.getElementById('lm-assistant');
    if (!root) return;
    var panel = root.querySelector('.lm-as-panel'), log = root.querySelector('.lm-as-log'), form = root.querySelector('form'), input = form.querySelector('input'), open = root.querySelector('.lm-as-open');
    var KEY = 'lumee_assistant', history = [];
    try { history = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) {}
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    function add(role, text, products) {
        var m = document.createElement('div');
        m.className = 'lm-as-msg ' + (role === 'shopper' ? 'me' : 'bot');
        m.textContent = text;
        log.appendChild(m);
        if (products && products.length) {
            var c = document.createElement('div');
            c.className = 'lm-as-cards';
            c.innerHTML = products.map(function (p) {
                return '<a class="lm-as-card" href="' + esc(p.url) + '">' + (p.image ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">' : '')
                    + '<span>' + esc(p.title) + '</span><b>' + esc(p.sold_out ? root.dataset.soldOut : p.price) + '</b></a>';
            }).join('');
            log.appendChild(c);
        }
        log.scrollTop = log.scrollHeight;
    }
    history.forEach(function (t) { add(t.role, t.text, t.products); });
    function toggle(show) { panel.hidden = !show; open.setAttribute('aria-expanded', show ? 'true' : 'false'); if (show) input.focus(); }
    open.addEventListener('click', function () { toggle(panel.hidden); });
    root.querySelector('.lm-as-close').addEventListener('click', function () { toggle(false); open.focus(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) toggle(false); });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var q = input.value.trim();
        if (!q || form.dataset.busy) return;
        form.dataset.busy = '1';
        input.value = '';
        add('shopper', q);
        var wait = document.createElement('div');
        wait.className = 'lm-as-msg bot typing';
        wait.textContent = '…';
        log.appendChild(wait);
        fetch(root.dataset.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.token },
            body: JSON.stringify({ message: q, viewing: root.dataset.viewing || null, history: history.slice(-6).map(function (t) { return { role: t.role, text: t.text }; }) })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
              wait.remove();
              if (!res.ok) { add('assistant', (res.j && res.j.message) || root.dataset.error); return; }
              history.push({ role: 'shopper', text: q }, { role: 'assistant', text: res.j.reply, products: res.j.products });
              try { sessionStorage.setItem(KEY, JSON.stringify(history.slice(-20))); } catch (e) {}
              add('assistant', res.j.reply, res.j.products);
          })
          .catch(function () { wait.remove(); add('assistant', root.dataset.error); })
          .finally(function () { delete form.dataset.busy; });
    });
})();
</script>
