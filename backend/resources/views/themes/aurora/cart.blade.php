@extends($theme->view('layout'))

@section('title', __('Your bag').' – '.$store->name)

@push('head')
<style>
    .cart { padding-block: 56px; }
    .cart h1 { font-size: 40px; margin: 6px 0 32px; }
    .cart-grid { display: grid; grid-template-columns: 1fr 340px; gap: 48px; align-items: start; }
    .line { display: grid; grid-template-columns: 96px 1fr auto; gap: 20px; padding: 20px 0; border-bottom: 1px solid var(--border); align-items: center; }
    .line:first-child { border-top: 1px solid var(--border); }
    .thumb { width: 96px; aspect-ratio: 4/5; border-radius: 10px; background: var(--surface-muted); display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .thumb img { width: 100%; height: 100%; object-fit: cover; }
    .thumb img.ph { width: 50%; height: auto; opacity: .5; }
    .line-title { font-weight: 500; }
    .line-meta { color: var(--ink-muted); font-size: 13px; margin-top: 4px; }
    .line-meta .was { color: var(--ink-muted); margin-inline-start: 6px; font-size: 13px; }
    .qty-form { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
    .qty-form .field { width: 70px; padding: 6px 10px; text-align: center; }
    .link-btn { background: none; border: none; padding: 0; color: var(--ink-muted); font-size: 12px; text-decoration: underline; cursor: pointer; }
    .line-total { text-align: end; }
    .summary { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 28px; box-shadow: var(--shadow-soft); }
    .summary-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; }
    .summary-row.total { font-size: 18px; font-weight: 600; margin: 18px 0 22px; padding-top: 18px; border-top: 1px solid var(--border); }
    .note { color: var(--ink-muted); font-size: 12px; margin-top: 14px; text-align: center; }
    .discount-row { color: var(--rose-gold-700); }
    .code-tag { font-size: 11px; letter-spacing: .08em; padding: 1px 8px; border-radius: 999px; background: var(--rose-gold-100); margin-inline-start: 4px; }
    .code-form { display: flex; gap: 8px; align-items: center; margin: 0 0 18px; }
    .code-form .field { padding: 10px 12px; text-transform: uppercase; }
    .code-form .btn { padding: 10px 18px; white-space: nowrap; }
    .code-form .muted { flex: 1; font-size: 13px; color: var(--ink-muted); }
    .code-error { color: var(--danger); font-size: 13px; margin: -10px 0 16px; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .free-bar { font-size: 13px; color: var(--rose-gold-700); margin-bottom: 16px; }
    .free-bar .track { height: 4px; background: var(--rose-gold-100); border-radius: 2px; margin-top: 8px; overflow: hidden; }
    .free-bar .fill { height: 100%; background: var(--gradient-gem); }
    .empty { text-align: center; padding: 64px 0; color: var(--ink-muted); }
    .empty p { margin-bottom: 28px; }
    @media (max-width: 860px) { .cart-grid { grid-template-columns: 1fr; gap: 28px; } .line { grid-template-columns: 72px 1fr; } .thumb { width: 72px; } .line-total { grid-column: 2; text-align: start; } }
</style>
@endpush

@if ($added = session('tracking_add'))
    @push('tracking')
        @php
            $trackEvent = ['currency' => $store->currency, 'value' => round($added['price'] * $added['quantity'] / 100, 2), 'items' => [['id' => $added['id'], 'name' => $added['name'], 'price' => round($added['price'] / 100, 2), 'quantity' => $added['quantity']]]];
        @endphp
        lumeeTrack('add_to_cart', @json($trackEvent));
    @endpush
@endif

@section('content')
    {{-- Blocks of the cart template (theme editor › 购物车); the bag itself is always there --}}
    @include($theme->view('partials.blocks'), ['blocks' => $theme->templateSections('cart')])
@endsection
