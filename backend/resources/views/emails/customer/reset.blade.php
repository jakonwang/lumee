@extends('emails.layout')

@section('preheader'){{ __('Choose a new password for your :store account.', ['store' => $store->name]) }}@endsection

@section('content')
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ $customer->hasAccount() ? __('Reset your password') : __('Set up your account') }}</h1>
    <p style="margin:0 0 8px;">{{ $customer->first_name ? __('Hi :name,', ['name' => $customer->first_name]) : __('Hi,') }}</p>
    <p style="margin:0 0 8px;">
        {{ $customer->hasAccount()
            ? __('We received a request to reset the password for your account. Click below to choose a new one.')
            : __('Click below to choose a password. You will then be able to see your past orders and check out faster.') }}
    </p>

    @include('emails.partials.button', ['href' => $url, 'label' => $customer->hasAccount() ? __('Reset password') : __('Set password')])

    <p style="margin:0;font-size:13px;color:{{ $c['muted'] }};">{{ __("This link expires in 1 hour. If you didn't ask for this, you can ignore this email — your password won't change.") }}</p>
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
