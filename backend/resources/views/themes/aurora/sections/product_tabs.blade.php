{{-- Product tabs: "New in / Best sellers / On sale…", each tab its own product source --}}
@php
    $sd = app(\App\Themes\SectionData::class);
    $tabs = collect([1, 2, 3])->map(fn ($n) => [
        'label' => $s["tab{$n}_label"],
        'products' => $s["tab{$n}_label"] !== '' ? $sd->products($s["tab{$n}_source"], $s["tab{$n}_collection"], (int) $s['limit']) : collect(),
    ])->filter(fn ($t) => $t['label'] !== '' && $t['products']->isNotEmpty())->values();
@endphp
@if ($tabs->isNotEmpty())
<section class="section ptabs" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        @if ($s['heading'])<div class="section-head"><h2 class="display">{{ $s['heading'] }}</h2></div>@endif
        <div class="ptabs-bar" role="tablist">
            @foreach ($tabs as $i => $tab)
                <button type="button" role="tab" id="{{ $section['id'] }}-t{{ $i }}" aria-controls="{{ $section['id'] }}-p{{ $i }}" aria-selected="{{ $i === 0 ? 'true' : 'false' }}">{{ $tab['label'] }}</button>
            @endforeach
        </div>
        @foreach ($tabs as $i => $tab)
            <div role="tabpanel" id="{{ $section['id'] }}-p{{ $i }}" aria-labelledby="{{ $section['id'] }}-t{{ $i }}" @if ($i > 0) hidden @endif>
                @include($theme->view('partials.product-grid'), ['products' => $tab['products'], 'columns' => (int) $s['columns'], 'mobileColumns' => (int) $s['mobile_columns']])
            </div>
        @endforeach
    </div>
</section>
@once
@push('head')
<style>
    .ptabs-bar { display: flex; justify-content: center; gap: 8px; margin-bottom: 28px; overflow-x: auto; scrollbar-width: none; }
    .ptabs-bar button { border: 1px solid var(--border); background: var(--surface); color: var(--ink); border-radius: 999px; padding: 9px 18px; font: inherit; font-size: 14px; cursor: pointer; white-space: nowrap; }
    .ptabs-bar button[aria-selected="true"] { background: var(--ink); color: var(--ivory); border-color: var(--ink); }
    [role="tabpanel"][hidden] { display: none; }
    @media (max-width: 720px) { .ptabs-bar { justify-content: flex-start; } }
</style>
@endpush
@push('scripts')
<script>
document.querySelectorAll('.ptabs').forEach(function (box) {
    var tabs = box.querySelectorAll('[role="tab"]');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (o) { o.setAttribute('aria-selected', o === t ? 'true' : 'false'); document.getElementById(o.getAttribute('aria-controls')).hidden = o !== t; });
        });
    });
});
</script>
@endpush
@endonce
@endif
