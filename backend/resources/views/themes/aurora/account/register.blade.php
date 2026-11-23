@extends($theme->view('layout'))

@section('title', __('Create account').' – '.$store->name)

@include($theme->view('account.partials.styles'))

@section('content')
    <div class="wrap acct">
        <div class="auth-card">
            <div class="eyebrow">{{ __('Account') }}</div>
            <h1 class="display">{{ __('Create account') }}</h1>
            <p class="lead">{{ __('Track your orders and save your address for next time.') }}</p>
            @if (\App\Http\Controllers\Storefront\GoogleLoginController::enabled())
                <a class="btn btn-block btn-ghost google-btn" href="{{ app(\App\I18n\Localization::class)->url('/account/google') }}">
                    <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
                    {{ __('Continue with Google') }}
                </a>
                <div class="or"><span>{{ __('or') }}</span></div>
            @endif

            <form method="post" action="/account/register" class="form-stack">
                @csrf
                <div class="grid2">
                    <div>
                        <label class="sr" for="first_name">{{ __('First name') }}</label>
                        <input class="field {{ $errors->has('first_name') ? 'invalid' : '' }}" id="first_name" name="first_name" value="{{ old('first_name') }}" placeholder="{{ __('First name') }}" autocomplete="given-name" required>
                    </div>
                    <div>
                        <label class="sr" for="last_name">{{ __('Last name') }}</label>
                        <input class="field {{ $errors->has('last_name') ? 'invalid' : '' }}" id="last_name" name="last_name" value="{{ old('last_name') }}" placeholder="{{ __('Last name') }}" autocomplete="family-name" required>
                    </div>
                </div>
                <label class="sr" for="email">{{ __('Email') }}</label>
                <input class="field {{ $errors->has('email') ? 'invalid' : '' }}" id="email" type="email" name="email" value="{{ old('email') }}" placeholder="{{ __('Email') }}" autocomplete="email" required>
                @error('email')<p class="err">{{ $message }}</p>@enderror
                <label class="sr" for="password">{{ __('Password') }}</label>
                <input class="field {{ $errors->has('password') ? 'invalid' : '' }}" id="password" type="password" name="password" placeholder="{{ __('Password (at least 8 characters)') }}" autocomplete="new-password" minlength="8" required>
                <label class="sr" for="password_confirmation">{{ __('Confirm password') }}</label>
                <input class="field" id="password_confirmation" type="password" name="password_confirmation" placeholder="{{ __('Confirm password') }}" autocomplete="new-password" required>
                @error('password')<p class="err">{{ $message }}</p>@enderror
                <label class="check"><input type="checkbox" name="accepts_marketing" value="1" @checked(old('accepts_marketing'))> {{ __('Email me about new arrivals and offers') }}</label>
                <button class="btn btn-block" type="submit">{{ __('Create account') }}</button>
            </form>
            <p class="alt">{{ __('Already have an account?') }} <a href="/account/login">{{ __('Log in') }}</a></p>
        </div>
    </div>
@endsection
