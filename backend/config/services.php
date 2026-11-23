<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Stripe Connect: each store is paid into its own Express account; the platform fee
        // is taken as an application fee. Off = every payment goes to the platform account (dev).
        'connect' => (bool) env('STRIPE_CONNECT', false),
        // Signing secret of the Connect webhook endpoint ("Events on connected accounts")
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
    ],

    // PayPal REST app (https://developer.paypal.com/dashboard/applications). mode: sandbox | live
    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

    // Airwallex (https://www.airwallex.com — Settings → Developer → API keys). env: demo | prod
    'airwallex' => [
        'client_id' => env('AIRWALLEX_CLIENT_ID'),
        'api_key' => env('AIRWALLEX_API_KEY'),
        'env' => env('AIRWALLEX_ENV', 'demo'),
        'webhook_secret' => env('AIRWALLEX_WEBHOOK_SECRET'),
    ],

    // Razorpay (https://dashboard.razorpay.com → Account & Settings → API keys). INR stores: UPI, cards, netbanking
    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
    ],

    // Package tracking (https://api.17track.net — API key from the 17TRACK dashboard).
    // Webhook URL to set there: https://<platform host>/webhooks/17track
    'seventeen_track' => [
        'key' => env('SEVENTEEN_TRACK_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Lead finder: platform-wide fallbacks when a store hasn't set its own key
    'google_places' => ['key' => env('GOOGLE_PLACES_KEY')],

    // "Continue with Google" for shoppers (one OAuth client for the whole platform; redirect URI: {APP_URL}/auth/google/callback)
    'google_login' => [
        'client_id' => env('GOOGLE_LOGIN_CLIENT_ID'),
        'client_secret' => env('GOOGLE_LOGIN_CLIENT_SECRET'),
        'redirect_host' => env('GOOGLE_LOGIN_REDIRECT_HOST'),   // defaults to APP_URL
    ],
    'anthropic' => ['key' => env('ANTHROPIC_API_KEY'), 'workspace' => env('ANTHROPIC_WORKSPACE_ID')],
    // Product research radar: Google Trends / Shopping through serpapi.com (stores can also set their own key)
    'serpapi' => ['key' => env('SERPAPI_KEY')],

];
