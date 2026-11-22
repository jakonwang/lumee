{{-- "Shopping from Mexico? Visit our Mexico store" — shown on the store's own site when the shopper's country has a market. Never redirects. --}}
@if ($suggestion)
<div class="mk-suggest" id="mk-suggest" role="dialog" aria-labelledby="mk-suggest-text">
    <p id="mk-suggest-text">{{ __('Shopping from :country? Visit our :market store for prices in :currency.', ['country' => $suggestion['country'], 'market' => $suggestion['market']->name, 'currency' => $suggestion['market']->currency]) }}</p>
    <div class="mk-actions">
        <a class="btn" href="{{ $suggestion['href'] }}" data-choice="{{ $suggestion['market']->handle }}">{{ __('Go to :market store', ['market' => $suggestion['market']->name]) }}</a>
        <button type="button" class="mk-stay" data-choice="stay">{{ __('Stay here') }}</button>
    </div>
</div>
<style>
    .mk-suggest { position: fixed; left: 16px; right: 16px; bottom: 16px; z-index: 850; max-width: 460px; margin: 0 auto; background: var(--surface, #fff); color: var(--ink); border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; box-shadow: 0 12px 40px rgba(0,0,0,.16); }
    .mk-suggest p { margin: 0 0 12px; line-height: 1.5; font-size: 15px; }
    .mk-actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .mk-stay { border: 0; background: none; color: var(--ink-muted); cursor: pointer; text-decoration: underline; font-size: 14px; }
</style>
<script>
    (function () {
        var box = document.getElementById('mk-suggest');
        // Remember the choice for a year either way, so the suggestion never nags
        box.querySelectorAll('[data-choice]').forEach(function (el) {
            el.addEventListener('click', function () {
                document.cookie = 'lumee_market_choice=' + el.getAttribute('data-choice') + '; path=/; max-age=31536000; samesite=lax';
                if (el.tagName === 'BUTTON') box.remove();
            });
        });
    })();
</script>
@endif
