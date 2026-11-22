@extends($theme->view('layout'))

@section('title', __('Log in').' – '.$store->name)

@include($theme->view('account.partials.styles'))

@section('content')
    <div class="wrap acct">
        <div class="auth-card">
            <div class="eyebrow">{{ __('Account') }}</div>
            <h1 class="display">{{ __('Log in') }}</h1>
            <p class="lead">{{ __('See your orders and check out faster.') }}</p>
            @include($theme->view('account.partials.flash'))

            <form method="post" action="/account/login" class="form-stack">
                @csrf
                @if ($return ?? null)<input type="hidden" name="return" value="{{ $return }}">@endif
                <label class="sr" for="email">{{ __('Email') }}</label>
                <input class="field {{ $errors->has('email') ? 'invalid' : '' }}" id="email" type="email" name="email" value="{{ old('email') }}" placeholder="{{ __('Email') }}" autocomplete="email" required autofocus>
                @error('email')<p class="err">{{ $message }}</p>@enderror
                <label class="sr" for="password">{{ __('Password') }}</label>
                <input class="field" id="password" type="password" name="password" placeholder="{{ __('Password') }}" autocomplete="current-password" required>
                <div style="text-align: end;font-size:13px;"><a href="/account/forgot-password" style="color:var(--ink-muted);text-decoration:underline;">{{ __('Forgot your password?') }}</a></div>
                <button class="btn btn-block" type="submit">{{ __('Log in') }}</button>
            </form>
            <p class="alt">{{ __('New here?') }} <a href="/account/register">{{ __('Create an account') }}</a></p>
            @if (\App\B2B\B2B::settings($store)['applications'])
                <p class="alt">{{ __('Business buyer?') }} <a href="/wholesale">{{ __('Apply for a wholesale account') }}</a></p>
            @endif
        </div>
    </div>
@endsection
