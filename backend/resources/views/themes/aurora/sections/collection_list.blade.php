@php($list = $sectionCollections[$section['id']] ?? collect())
<section class="section" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        @if ($s['eyebrow'] || $s['heading'])
            <div class="section-head">
                @if ($s['eyebrow'])<div class="eyebrow">{{ $s['eyebrow'] }}</div>@endif
                @if ($s['heading'])<h2 class="display">{{ $s['heading'] }}</h2>@endif
            </div>
        @endif
        @if ($list->isEmpty())
            <div class="empty" style="text-align:center;color:var(--ink-muted)">{{ __('Create collections in the admin to show them here.') }}</div>
        @else
            @include($theme->view('partials.collection-grid'), ['collections' => $list])
        @endif
    </div>
</section>
