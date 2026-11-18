@extends('emails.layout')

@section('preheader'){{ __('Confirm your email to see your orders at :store.', ['store' => $store->name]) }}@endsection

@section('content')
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('Confirm your email') }}</h1>
    <p style="margin:0 0 8px;">{{ $customer->first_name ? __('Hi :name,', ['name' => $customer->first_name]) : __('Hi,') }}</p>
    <p style="margin:0 0 8px;">{{ __("Thanks for creating an account with :store. Please confirm this is your email address — once confirmed, you'll see all your orders in your account.", ['store' => $store->name]) }}</p>

    @include('emails.partials.button', ['href' => $url, 'label' => __('Confirm email')])

    <p style="margin:0;font-size:13px;color:{{ $c['muted'] }};">{{ __("This link expires in 3 days. If you didn't create an account, you can ignore this email.") }}</p>
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
