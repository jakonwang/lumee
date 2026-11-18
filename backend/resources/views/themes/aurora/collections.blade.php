@extends($theme->view('layout'))

@section('title', __('Collections').' – '.$store->name)

@section('content')
    <div class="wrap">
        <div style="text-align:center;padding:56px 0 40px">
            <div class="eyebrow">{{ __('Shop by') }}</div>
            <h1 class="display" style="font-size:clamp(32px,5vw,52px);margin:8px 0 0">{{ __('Collections') }}</h1>
        </div>
        @if ($collections->isEmpty())
            <p style="text-align:center;color:var(--ink-muted)">{{ __('Collections are coming soon.') }} <a href="/collections/all" style="text-decoration:underline">{{ __('Browse all products') }}</a>.</p>
        @else
            @include($theme->view('partials.collection-grid'), ['collections' => $collections])
        @endif
    </div>
@endsection
