{{-- Newsletter signup popup (Marketing → Email). Opens once per visitor after a delay; never for subscribers who already signed up here. --}}
@php($popup = \App\Marketing\Newsletter::popup($store))
@if ($popup['enabled'] && ! request()->attributes->get('theme_preview'))
<div class="nl-pop" id="nl-pop" role="dialog" aria-modal="true" aria-labelledby="nl-pop-title" hidden>
    <div class="nl-card">
        <button type="button" class="nl-close" aria-label="{{ __('Close') }}" data-close>×</button>
        <h2 id="nl-pop-title" class="display">{{ $popup['heading'] }}</h2>
        @if ($popup['text'])<p class="nl-text">{{ $popup['text'] }}</p>@endif
        <form class="nl-form" novalidate>
            <input class="field" type="email" name="email" required placeholder="{{ __('Email') }}" autocomplete="email" aria-label="{{ __('Email') }}">
            <input type="text" name="website" tabindex="-1" autocomplete="off" class="nl-hp" aria-hidden="true">
            <button class="btn" type="submit">{{ $popup['button'] }}</button>
        </form>
        <p class="nl-msg" role="status" hidden></p>
        <button type="button" class="nl-later" data-close>{{ __('No thanks') }}</button>
    </div>
</div>
<style>
    .nl-pop { position: fixed; inset: 0; z-index: 900; display: grid; place-items: center; padding: 16px; background: rgba(20, 26, 23, .45); }
    .nl-pop[hidden] { display: none; }
    .nl-card { position: relative; width: 100%; max-width: 420px; background: var(--surface, #fff); border-radius: 16px; padding: 32px 28px 22px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.2); }
    .nl-card h2 { font-size: 26px; margin: 0 0 10px; line-height: 1.2; }
    .nl-text { color: var(--ink-muted); line-height: 1.6; margin: 0 0 18px; font-size: 15px; }
    .nl-form { display: grid; gap: 10px; }
    .nl-form .btn { width: 100%; }
    .nl-close { position: absolute; top: 10px; right: 12px; border: 0; background: none; font-size: 24px; line-height: 1; color: var(--ink-muted); cursor: pointer; }
    .nl-later { margin-top: 12px; border: 0; background: none; color: var(--ink-muted); font-size: 13px; cursor: pointer; text-decoration: underline; }
    .nl-msg { margin: 14px 0 0; font-size: 15px; line-height: 1.6; }
    .nl-msg strong { letter-spacing: 1px; }
    .nl-hp { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; }
</style>
<script>
(function () {
    const KEY = 'lumee_popup_seen';
    try { if (localStorage.getItem(KEY)) return; } catch (e) { return; }
    const pop = document.getElementById('nl-pop');
    const form = pop.querySelector('form');
    const msg = pop.querySelector('.nl-msg');
    const remember = () => { try { localStorage.setItem(KEY, '1'); } catch (e) {} };
    const close = () => { pop.hidden = true; remember(); };
    setTimeout(() => { pop.hidden = false; pop.querySelector('input[type=email]').focus(); }, {{ (int) $popup['delay'] * 1000 }});
    pop.addEventListener('click', (e) => { if (e.target === pop || e.target.hasAttribute('data-close')) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !pop.hidden) close(); });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = form.email.value.trim();
        if (!form.email.checkValidity()) { form.email.reportValidity(); return; }
        const btn = form.querySelector('button'); btn.disabled = true;
        try {
            const res = await fetch(@json(app(\App\I18n\Localization::class)->url('/newsletter')), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                body: JSON.stringify({ email, source: 'popup', website: form.website.value }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || '');
            form.hidden = true;
            msg.hidden = false;
            msg.textContent = data.message;
            if (data.code) { const s = document.createElement('strong'); s.textContent = data.code; msg.textContent = data.message.replace(data.code, ''); msg.append(document.createElement('br'), s); }
            pop.querySelector('.nl-later').textContent = @json(__('Continue shopping'));
            remember();
        } catch (err) {
            msg.hidden = false; msg.textContent = err.message || @json(__('Something went wrong. Please try again.'));
            btn.disabled = false;
        }
    });
})();
</script>
@endif
