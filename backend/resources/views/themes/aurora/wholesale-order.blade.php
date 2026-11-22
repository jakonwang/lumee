@extends($theme->view('layout'))

@section('title', __('Quick order').' · '.$store->name)
@section('noindex', '1')

@push('head')
<style>
    .qo { max-width: 760px; padding-block: 56px 80px; }
    .qo h1 { font-size: clamp(28px, 4vw, 40px); margin: 0 0 8px; }
    .qo .lead { color: var(--ink-muted); margin: 0 0 24px; line-height: 1.7; }
    .qo textarea { width: 100%; min-height: 220px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 14px; }
    .qo .notice { padding: 12px 16px; border-radius: 8px; background: var(--rose-gold-50); border: 1px solid var(--border); margin-bottom: 16px; }
    .qo .errs { color: #B42318; font-size: 14px; margin: 0 0 16px; padding-inline-start: 18px; }
    .qo form { display: grid; gap: 14px; }
</style>
@endpush

@section('content')
    <section class="wrap qo">
        <div class="eyebrow">{{ __('For businesses') }}</div>
        <h1 class="display">{{ __('Quick order') }}</h1>
        <p class="lead">{{ __('Paste SKUs and quantities, one per line (e.g. “RING-07, 24”). Everything goes into your bag at your wholesale prices.') }}</p>
        @if ($wholesale)
            <div class="notice">{{ __('Wholesale prices applied.') }} @if ($wholesale['minimum']) {{ __('Wholesale orders need a subtotal of at least :amount.', ['amount' => $px->display($wholesale['minimum'])]) }} @endif</div>
        @endif
        @if (session('quick_order_errors'))
            <ul class="errs">@foreach (session('quick_order_errors') as $e)<li>{{ $e }}</li>@endforeach</ul>
        @endif
        <form method="post" action="/wholesale/order">
            @csrf
            <textarea class="field" name="lines" required placeholder="RING-07, 24&#10;NECK-16, 12">{{ old('lines') }}</textarea>
            <div><button class="btn" type="submit">{{ __('Add to bag') }}</button></div>
        </form>
    </section>
@endsection
