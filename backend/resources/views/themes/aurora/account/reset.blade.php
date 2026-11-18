@extends($theme->view('layout'))

@section('title', __('Choose a new password').' – '.$store->name)

@include($theme->view('account.partials.styles'))

@section('content')
    <div class="wrap acct">
        <div class="auth-card">
            <div class="eyebrow">{{ __('Account') }}</div>
            <h1 class="display">{{ __('New password') }}</h1>
            <p class="lead">{{ __('Choose a password with at least 8 characters.') }}</p>

            <form method="post" action="/account/reset/{{ $token }}" class="form-stack">
                @csrf
                <label class="sr" for="password">{{ __('New password') }}</label>
                <input class="field {{ $errors->has('password') ? 'invalid' : '' }}" id="password" type="password" name="password" placeholder="{{ __('New password') }}" autocomplete="new-password" minlength="8" required autofocus>
                <label class="sr" for="password_confirmation">{{ __('Confirm password') }}</label>
                <input class="field" id="password_confirmation" type="password" name="password_confirmation" placeholder="{{ __('Confirm new password') }}" autocomplete="new-password" required>
                @error('password')<p class="err">{{ $message }}</p>@enderror
                <button class="btn btn-block" type="submit">{{ __('Save password') }}</button>
            </form>
        </div>
    </div>
@endsection
