@extends($theme->view('layout'))

@section('title', __('Create account').' – '.$store->name)

@include($theme->view('account.partials.styles'))

@section('content')
    <div class="wrap acct">
        <div class="auth-card">
            <div class="eyebrow">{{ __('Account') }}</div>
            <h1 class="display">{{ __('Create account') }}</h1>
            <p class="lead">{{ __('Track your orders and save your address for next time.') }}</p>

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
