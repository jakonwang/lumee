{{-- Product page › reviews (when reviews are on) --}}
<div data-section-id="{{ $section['id'] }}">
    @if ($reviewSettings['enabled'] ?? false)
        @include($theme->view('partials.reviews'), ['customer' => \App\Customers\CustomerSession::user()])
    @endif
</div>
