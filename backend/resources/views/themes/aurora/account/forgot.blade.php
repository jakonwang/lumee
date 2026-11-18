@extends($theme->view('layout'))

@section('title', __('Reset password').' – '.$store->name)

@include($theme->view('account.partials.styles'))

@section('content')
    <div class="wrap acct">
        <div class="auth-card">
            <div class="eyebrow">{{ __('Account') }}</div>
            <h1 class="display">{{ __('Reset password') }}</h1>
            <p class="lead">{{ __("Enter your email and we'll send you a link to set a new password. Ordered here before without an account? This also lets you set one up.") }}</p>
            @include($theme->view('account.partials.flash'))

            <form method="post" action="/account/forgot-password" class="form-stack">
                @csrf
                <label class="sr" for="email">{{ __('Email') }}</label>
                <input class="field {{ $errors->has('email') ? 'invalid' : '' }}" id="email" type="email" name="email" value="{{ old('email') }}" placeholder="{{ __('Email') }}" autocomplete="email" required autofocus>
                @error('email')<p class="err">{{ $message }}</p>@enderror
                <button class="btn btn-block" type="submit">{{ __('Send link') }}</button>
            </form>
            <p class="alt"><a href="/account/login">{{ __('Back to log in') }}</a></p>
        </div>
    </div>
@endsection
