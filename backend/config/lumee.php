<?php

return [
    // Root domain for free store subdomains: {handle}.{storefront_domain}
    // Locally *.localhost resolves to 127.0.0.1 in modern browsers.
    'storefront_domain' => env('LUMEE_STOREFRONT_DOMAIN', 'lumee.localhost'),

    // Scheme + port appended when building storefront URLs (handy for local dev)
    'storefront_scheme' => env('LUMEE_STOREFRONT_SCHEME', 'https'),
    'storefront_port' => env('LUMEE_STOREFRONT_PORT'),

    // Custom domains: merchants point a CNAME at this host (or an A record at server_ip)
    'cname_target' => env('LUMEE_CNAME_TARGET', 'stores.lumee.shop'),
    'server_ip' => env('LUMEE_SERVER_IP'),

    // Automatic HTTPS for custom domains (Caddy on-demand TLS). Caddy asks
    // GET /internal/tls/ask?domain=… before getting a certificate; only loopback
    // callers, or callers passing ?token=<this>, get an answer. See docs/https.md.
    'tls_ask_token' => env('LUMEE_TLS_ASK_TOKEN'),

    // Currencies a store can sell in (2-decimal currencies; Money assumes cents)
    'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'SGD', 'HKD', 'CNY', 'CHF', 'SEK', 'DKK', 'NOK', 'MXN', 'BRL', 'AED'],

    // Merchant admin SPA URL (linked from the platform landing page)
    'admin_url' => env('LUMEE_ADMIN_URL', 'http://localhost:5280'),

    'payments' => [
        // Instant "test payment" option at checkout — never enable in production
        'test_gateway' => env('LUMEE_TEST_PAYMENTS', false),
    ],

    // Filesystem disk for product images ("public" locally; an S3/R2 disk in production)
    'media_disk' => env('LUMEE_MEDIA_DISK', 'public'),

    'trial_days' => 14,

    // Storefront languages (code => native name). Built-in storefront text exists for each
    // (lang/{code}.json; English is the source). Merchants translate their own content.
    'languages' => [
        'en' => 'English',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'es' => 'Español',
        'it' => 'Italiano',
        'ja' => '日本語',
    ],
    // Theme new stores start with. Existing stores keep the theme they have.
    'default_theme' => env('LUMEE_DEFAULT_THEME', 'fresh'),
];
