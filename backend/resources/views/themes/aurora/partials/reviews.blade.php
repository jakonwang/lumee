{{-- Product reviews: summary, list and "write a review" form. Expects $product, $reviews (paginator), $ratingBreakdown. --}}
@php
    $avg = $product->rating_avg;
    $count = $product->rating_count;
    $stars = fn (float $r) => str_repeat('★', (int) round($r)).str_repeat('☆', 5 - (int) round($r));
    $errs = $errors->getBag('review');
    $prefill = session('review_prefill', []);
    $open = $errs->isNotEmpty() || $prefill;
@endphp
<section class="wrap reviews" id="reviews" aria-labelledby="reviews-title">
    <div class="reviews-head">
        <div>
            <h2 id="reviews-title" class="display">{{ __('Customer reviews') }}</h2>
            @if ($count)
                <div class="rv-summary">
                    <span class="rv-stars" aria-hidden="true">{{ $stars($avg) }}</span>
                    <strong>{{ number_format($avg, 1) }}</strong>
                    <span class="rv-muted">{{ trans_choice('Based on :count review|Based on :count reviews', $count, ['count' => $count]) }}</span>
                </div>
            @else
                <p class="rv-muted">{{ __('No reviews yet. Be the first to share your thoughts.') }}</p>
            @endif
        </div>
        @if ($count)
            <div class="rv-bars" aria-hidden="true">
                @for ($s = 5; $s >= 1; $s--)
                    @php($n = (int) ($ratingBreakdown[$s] ?? 0))
                    <div class="rv-bar"><span>{{ $s }}★</span><span class="rv-track"><span style="width: {{ $count ? round($n / $count * 100) : 0 }}%"></span></span><span>{{ $n }}</span></div>
                @endfor
            </div>
        @endif
    </div>

    @if (session('review_status'))
        <div class="flash" role="status">{{ session('review_status') }}</div>
    @endif

    <details class="rv-write" @if ($open) open @endif>
        <summary class="btn btn-outline">{{ __('Write a review') }}</summary>
        <form method="post" action="/products/{{ $product->handle }}/reviews" enctype="multipart/form-data" class="rv-form" novalidate>
            @csrf
            <fieldset class="rv-rating">
                <legend>{{ __('Your rating') }}</legend>
                <div class="rv-rating-input">
                    @for ($s = 5; $s >= 1; $s--)
                        <input type="radio" id="rv-{{ $s }}" name="rating" value="{{ $s }}" @checked((int) old('rating') === $s)>
                        <label for="rv-{{ $s }}" title="{{ trans_choice(':count star|:count stars', $s, ['count' => $s]) }}">★</label>
                    @endfor
                </div>
                @if ($errs->has('rating'))<p class="rv-error">{{ $errs->first('rating') }}</p>@endif
            </fieldset>
            <div class="rv-row">
                <label>{{ __('Name') }}<input class="field" name="author" value="{{ old('author', $prefill['author'] ?? $customer?->fullName() ?? '') }}" maxlength="80" required autocomplete="name"></label>
                <label>{{ __('Email') }} <span class="rv-muted">({{ __('not shown') }})</span><input class="field" type="email" name="email" value="{{ old('email', $prefill['email'] ?? $customer?->email ?? '') }}" maxlength="255" required autocomplete="email"></label>
            </div>
            @foreach (['author', 'email'] as $f)
                @if ($errs->has($f))<p class="rv-error">{{ $errs->first($f) }}</p>@endif
            @endforeach
            <label>{{ __('Title') }} <span class="rv-muted">({{ __('optional') }})</span><input class="field" name="title" value="{{ old('title') }}" maxlength="150"></label>
            <label>{{ __('Review') }}<textarea class="field" name="body" rows="5" maxlength="5000" required>{{ old('body') }}</textarea></label>
            @if ($errs->has('body'))<p class="rv-error">{{ $errs->first('body') }}</p>@endif
            <label>{{ __('Photos') }} <span class="rv-muted">({{ __('up to 3, optional') }})</span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple></label>
            @foreach ($errs->get('photos*') as $messages)
                <p class="rv-error">{{ is_array($messages) ? $messages[0] : $messages }}</p>
            @endforeach
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="rv-hp" aria-hidden="true">
            <button class="btn" type="submit">{{ __('Submit review') }}</button>
        </form>
    </details>

    @if ($reviews && $reviews->count())
        <ul class="rv-list">
            @foreach ($reviews as $r)
                <li class="rv-item">
                    <div class="rv-meta">
                        <span class="rv-stars" aria-label="{{ trans_choice(':count star|:count stars', $r->rating, ['count' => $r->rating]) }}">{{ $stars($r->rating) }}</span>
                        <strong>{{ $r->displayName() }}</strong>
                        @if ($r->verified)<span class="rv-badge">✓ {{ __('Verified buyer') }}</span>@endif
                        <span class="rv-muted">{{ $r->created_at->toDateString() }}</span>
                    </div>
                    @if ($r->title)<h3>{{ $r->title }}</h3>@endif
                    <p class="rv-body">{{ $r->body }}</p>
                    @if ($urls = $r->imageUrls())
                        <div class="rv-photos">
                            @foreach ($urls as $u)
                                <a href="{{ $u }}" target="_blank" rel="noopener"><img {{ \App\Media\Img::attrs($u, 'thumb') }} alt="{{ __('Photo from :name', ['name' => $r->displayName()]) }}" loading="lazy"></a>
                            @endforeach
                        </div>
                    @endif
                    @if ($r->reply)
                        <div class="rv-reply"><strong>{{ __('Reply from :store', ['store' => $store->name]) }}</strong><p>{{ $r->reply }}</p></div>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($reviews->hasPages())
            <nav class="rv-pages" aria-label="{{ __('Review pages') }}">
                @if ($reviews->previousPageUrl())<a href="{{ $reviews->previousPageUrl() }}">← {{ __('Newer') }}</a>@endif
                <span class="rv-muted">{{ $reviews->currentPage() }} / {{ $reviews->lastPage() }}</span>
                @if ($reviews->nextPageUrl())<a href="{{ $reviews->nextPageUrl() }}">{{ __('Older') }} →</a>@endif
            </nav>
        @endif
    @endif
</section>
<style>
    .reviews { padding-block: 48px 72px; border-top: 1px solid var(--border); scroll-margin-top: 96px; }
    .reviews h2 { font-size: 30px; margin: 0 0 8px; }
    .reviews-head { display: flex; justify-content: space-between; gap: 32px; flex-wrap: wrap; align-items: flex-start; margin-bottom: 20px; }
    .rv-summary { display: flex; align-items: center; gap: 10px; }
    .rv-stars { color: var(--rose-gold-500); letter-spacing: 2px; font-size: 18px; }
    .rv-muted { color: var(--ink-muted); font-size: 13px; }
    .rv-bars { display: grid; gap: 4px; min-width: 240px; font-size: 13px; }
    .rv-bar { display: grid; grid-template-columns: 32px 1fr 32px; align-items: center; gap: 8px; }
    .rv-track { height: 6px; border-radius: 3px; background: var(--rose-gold-50); overflow: hidden; }
    .rv-track span { display: block; height: 100%; background: var(--rose-gold-500); }
    .rv-write { margin: 8px 0 24px; }
    .rv-write summary { list-style: none; display: inline-block; }
    .rv-write summary::-webkit-details-marker { display: none; }
    .btn-outline { background: transparent !important; color: var(--ink) !important; border: 1px solid var(--border) !important; }
    .rv-form { display: grid; gap: 14px; margin-top: 16px; max-width: 640px; }
    .rv-form label { display: grid; gap: 6px; font-size: 14px; }
    .rv-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .rv-rating { border: 0; padding: 0; margin: 0; }
    .rv-rating legend { font-size: 14px; margin-bottom: 4px; }
    .rv-rating-input { display: inline-flex; flex-direction: row-reverse; gap: 4px; }
    .rv-rating-input input { position: absolute; opacity: 0; width: 1px; height: 1px; }
    .rv-rating-input label { font-size: 30px; color: var(--border); cursor: pointer; line-height: 1; }
    .rv-rating-input input:checked ~ label, .rv-rating-input label:hover, .rv-rating-input label:hover ~ label { color: var(--rose-gold-500); }
    .rv-rating-input input:focus-visible + label { outline: 2px solid var(--rose-gold-500); outline-offset: 2px; border-radius: 4px; }
    .rv-error { color: #B42318; font-size: 13px; margin: -6px 0 0; }
    .rv-hp { position: absolute; inset-inline-start: -9999px; width: 1px; height: 1px; opacity: 0; }
    .rv-list { list-style: none; padding: 0; margin: 0; display: grid; }
    .rv-item { padding: 20px 0; border-top: 1px solid var(--border); }
    .rv-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 14px; }
    .rv-badge { font-size: 12px; color: var(--rose-gold-700); background: var(--rose-gold-50); border-radius: 999px; padding: 2px 8px; }
    .rv-item h3 { font-size: 16px; margin: 10px 0 4px; }
    .rv-body { margin: 6px 0 0; line-height: 1.7; white-space: pre-line; }
    .rv-photos { display: flex; gap: 8px; margin-top: 10px; }
    .rv-photos img { width: 84px; height: 84px; object-fit: cover; border-radius: 8px; display: block; }
    .rv-reply { margin-top: 12px; padding: 12px 14px; background: var(--rose-gold-50); border-radius: 10px; font-size: 14px; }
    .rv-reply p { margin: 4px 0 0; white-space: pre-line; }
    .rv-pages { display: flex; gap: 16px; align-items: center; justify-content: center; padding-top: 16px; }
    @media (max-width: 640px) { .rv-row { grid-template-columns: 1fr; } .reviews h2 { font-size: 24px; } }
</style>
