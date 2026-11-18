<a class="logo" href="/" aria-label="{{ __(':store home', ['store' => $store->name]) }}">
    @if ($theme->logoUrl())
        <img src="{{ $theme->logoUrl() }}" alt="{{ $store->name }}" style="height: {{ (int) $theme->setting('header.logo_height') }}px; width: auto; max-width: 220px; display: block">
    @else
        {{ $store->name }}
    @endif
</a>
