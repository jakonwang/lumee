@php($items = collect(range(1, 5))->map(fn ($i) => ['q' => $s["q$i"] ?? '', 'a' => $s["a$i"] ?? ''])->filter(fn ($x) => $x['q'] !== ''))
<section class="section" data-section-id="{{ $section['id'] }}">
    <div class="wrap faq">
        @if ($s['heading'])
            <div class="section-head"><h2 class="display">{{ $s['heading'] }}</h2></div>
        @endif
        @foreach ($items as $item)
            <details class="faq-item" @if ($loop->first) open @endif>
                <summary>{{ $item['q'] }}<span class="faq-icon" aria-hidden="true"></span></summary>
                @if ($item['a'])<div class="prose">{{ $item['a'] }}</div>@endif
            </details>
        @endforeach
    </div>
</section>

@once
@push('head')
<style>
    .faq { max-width: 820px; }
    .faq-item { border-bottom: 1px solid var(--border); }
    .faq-item:first-of-type { border-top: 1px solid var(--border); }
    .faq-item summary { list-style: none; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 20px 0; font-size: 17px; font-weight: 500; }
    .faq-item summary::-webkit-details-marker { display: none; }
    .faq-icon { position: relative; width: 14px; height: 14px; flex: none; }
    .faq-icon::before, .faq-icon::after { content: ''; position: absolute; left: 0; top: 6px; width: 14px; height: 2px; background: var(--rose-gold-500); transition: transform .2s; }
    .faq-icon::after { transform: rotate(90deg); }
    .faq-item[open] .faq-icon::after { transform: rotate(0); }
    .faq-item .prose { padding: 0 0 20px; }
</style>
@endpush
@endonce
