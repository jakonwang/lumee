@extends($theme->view('layout'))

@section('title', __('Wholesale').' · '.$store->name)

@push('head')
<style>
    .ws { max-width: 680px; padding-block: 56px 80px; }
    .ws h1 { font-size: clamp(30px, 4vw, 42px); margin: 0 0 8px; }
    .ws .lead { color: var(--ink-muted); margin: 0 0 28px; line-height: 1.7; }
    .ws form { display: grid; gap: 14px; }
    .ws .two { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .ws label { display: grid; gap: 6px; font-size: 14px; }
    .ws .notice { padding: 14px 16px; border-radius: 8px; background: var(--rose-gold-50); border: 1px solid var(--border); margin-bottom: 20px; }
    .ws .err { color: #B42318; font-size: 13px; }
    @media (max-width: 640px) { .ws .two { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')
    <section class="wrap ws">
        <div class="eyebrow">{{ __('For businesses') }}</div>
        <h1 class="display">{{ __('Apply for a wholesale account') }}</h1>
        <p class="lead">{{ __('Retailers, salons, cafés and other businesses can buy at wholesale prices. Tell us about your business — we’ll review your application and email you.') }}</p>

        @if (session('status'))
            <div class="notice" role="status">{{ session('status') }}</div>
        @else
            <form method="post" action="/wholesale">
                @csrf
                <div class="two">
                    <label>{{ __('Company name') }} *<input class="field" name="company_name" value="{{ old('company_name') }}" required maxlength="150"></label>
                    <label>{{ __('Tax / VAT number') }}<input class="field" name="tax_id" value="{{ old('tax_id') }}" maxlength="60"></label>
                </div>
                <div class="two">
                    <label>{{ __('Your name') }} *<input class="field" name="contact_name" value="{{ old('contact_name') }}" required maxlength="100"></label>
                    <label>{{ __('Email') }} *<input class="field" type="email" name="email" value="{{ old('email') }}" required></label>
                </div>
                <div class="two">
                    <label>{{ __('Phone') }}<input class="field" name="phone" value="{{ old('phone') }}" maxlength="40"></label>
                    <label>{{ __('Website') }}<input class="field" name="website" value="{{ old('website') }}" maxlength="200"></label>
                </div>
                <label>{{ __('Country') }}
                    <select class="field" name="country">
                        <option value=""></option>
                        @foreach ($countries as $code => $name)<option value="{{ $code }}" @selected(old('country') === $code)>{{ $name }}</option>@endforeach
                    </select>
                </label>
                <label>{{ __('Tell us about your business') }}<textarea class="field" name="message" rows="4" maxlength="2000">{{ old('message') }}</textarea></label>
                @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
                <button class="btn" type="submit">{{ __('Submit application') }}</button>
            </form>
        @endif
    </section>
@endsection
