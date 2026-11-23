{{-- Filter panel (collection and search pages). Expects $filters from App\Catalog\CollectionFilters. --}}
<details class="filters" id="filters" @if ($filters['any']) data-open @endif>
    <summary>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M7 12h10M10 18h4"/></svg>
        {{ __('Filter') }}@if (count($filters['active'])) <span class="f-count">{{ count($filters['active']) }}</span>@endif
    </summary>
    <form method="get" class="filter-form">
        @foreach (['sort', 'q'] as $keep)
            @if (is_string(request($keep)) && request($keep) !== '')<input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">@endif
        @endforeach

        <label class="f-check f-stock">
            <input type="checkbox" name="f[stock]" value="1" @checked($filters['stock'] ?? false) onchange="this.form.submit()">
            <span>{{ __('In stock only') }}</span><em>{{ $filters['in_stock_count'] ?? '' }}</em>
        </label>

        @foreach ($filters['groups'] as $g)
            <fieldset class="f-group">
                <legend>{{ $g['label'] }}</legend>
                <div class="f-values {{ count($g['values']) <= 12 ? 'chips' : '' }}">
                    @foreach ($g['values'] as $v)
                        <label class="f-check {{ $v['count'] === 0 && ! $v['checked'] ? 'empty' : '' }}">
                            <input type="checkbox" name="{{ $g['param'] }}" value="{{ $v['value'] }}" @checked($v['checked']) onchange="this.form.submit()">
                            <span>{{ $v['value'] }}</span><em>{{ $v['count'] }}</em>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        @if ($filters['price'] && $filters['price']['max'] > $filters['price']['min'])
            <fieldset class="f-group">
                <legend>{{ __('Price') }} <small>({{ $filters['price']['currency'] }})</small></legend>
                <div class="f-price">
                    <input type="number" name="f[min]" min="0" step="1" inputmode="numeric" value="{{ $filters['price']['from'] }}" placeholder="{{ $filters['price']['min'] }}" aria-label="{{ __('Min') }}">
                    <span>–</span>
                    <input type="number" name="f[max]" min="0" step="1" inputmode="numeric" value="{{ $filters['price']['to'] }}" placeholder="{{ $filters['price']['max'] }}" aria-label="{{ __('Max') }}">
                    <button type="submit">{{ __('Apply') }}</button>
                </div>
            </fieldset>
        @endif
    </form>
</details>

@once
@push('head')
<style>
    .coll-body { display: block; }
    .has-filters .coll-body { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 32px; align-items: start; }
    .filters summary { display: none; }
    .filters { position: sticky; top: 90px; max-height: calc(100vh - 110px); overflow: auto; font-size: 14px; }
    .filter-form { display: grid; gap: 20px; }
    .f-group { border: 0; padding: 0; margin: 0; }
    .f-group legend { font-weight: 600; margin-bottom: 10px; padding: 0; }
    .f-group legend small { color: var(--ink-muted); font-weight: 400; }
    .f-values { display: grid; gap: 6px; }
    .f-values.chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .f-check { display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .f-check em { font-style: normal; color: var(--ink-muted); font-size: 12px; margin-inline-start: auto; }
    .f-check.empty { opacity: .45; }
    .f-values.chips .f-check { border: 1px solid var(--border); border-radius: 999px; padding: 5px 12px; gap: 6px; }
    .f-values.chips .f-check input { position: absolute; opacity: 0; pointer-events: none; }
    .f-values.chips .f-check:has(input:checked) { border-color: var(--ink); background: var(--ink); color: var(--bg, #fff); }
    .f-values.chips .f-check:has(input:checked) em { color: inherit; opacity: .7; }
    .f-values.chips .f-check:has(input:focus-visible) { outline: 2px solid var(--accent); outline-offset: 2px; }
    .f-price { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
    .f-price button { flex-basis: 100%; }
    .f-price input { flex: 1; min-width: 0; width: 72px; padding: 7px 8px; border: 1px solid var(--border); border-radius: 8px; font: inherit; background: transparent; color: inherit; }
    .f-price button { padding: 7px 12px; border-radius: 8px; border: 1px solid var(--ink); background: var(--ink); color: var(--bg, #fff); font: inherit; cursor: pointer; }
    .f-stock { font-weight: 500; }
    .f-active { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 18px; }
    .f-chip { display: inline-flex; gap: 6px; align-items: center; padding: 5px 12px; border-radius: 999px; background: var(--surface-muted); color: inherit; text-decoration: none; font-size: 13px; }
    .f-clear { font-size: 13px; color: var(--ink-muted); }
    .f-count { display: inline-grid; place-items: center; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: var(--ink); color: var(--bg, #fff); font-size: 11px; }
    @media (max-width: 900px) {
        .has-filters .coll-body { display: block; }
        .filters { position: static; max-height: none; overflow: visible; margin-bottom: 16px; }
        .filters summary { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border: 1px solid var(--border); border-radius: 999px; cursor: pointer; list-style: none; font-weight: 500; }
        .filters summary::-webkit-details-marker { display: none; }
        .filters[open] .filter-form { margin-top: 16px; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius-card, 12px); }
    }
</style>
@endpush
@push('scripts')
<script>
    // Filters stay open beside the grid on wide screens; on phones they fold behind a "Filter" button
    (function () {
        var d = document.getElementById('filters');
        if (!d) return;
        var wide = window.matchMedia('(min-width: 901px)');
        var sync = function () { if (wide.matches) d.open = true; };
        sync();
        wide.addEventListener ? wide.addEventListener('change', sync) : wide.addListener(sync);
        d.addEventListener('toggle', function () { if (wide.matches && !d.open) d.open = true; });
    })();
</script>
@endpush
@endonce
