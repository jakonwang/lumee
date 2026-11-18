<?php

namespace App\Support;

/**
 * Starter content for the pages every cross-border store needs. Written in English (the
 * storefront language); {store} becomes the store name. Pages start unpublished so the
 * merchant reviews and adapts them first — they are templates, not legal advice.
 */
final class PageTemplates
{
    public static function all(): array
    {
        return [
            'about-us' => [
                'label' => __('关于我们'),
                'title' => 'About Us',
                'body' => <<<'MD'
## Our story

{store} began with a simple idea: beautiful pieces, made with care, delivered anywhere in the world.

## What we believe

- **Quality first** — we choose materials that last.
- **Thoughtful design** — every piece is made to be worn and loved.
- **Real people** — questions? Just reply to any of our emails.

Thank you for supporting a small, independent store.
MD,
            ],
            'faq' => [
                'label' => __('常见问题'),
                'title' => 'FAQ',
                'body' => <<<'MD'
## How long does shipping take?

Most orders ship within 1–3 business days. Delivery times depend on your country and the shipping method you choose at checkout.

## Can I track my order?

Yes. When your order ships we email you a tracking number. You can also check your order status from the link in your confirmation email.

## Can I change or cancel my order?

Contact us as soon as possible. If the order hasn't shipped yet, we'll do our best to help.

## How do I contact you?

Reply to any email from us and we'll get back to you within 1–2 business days.
MD,
            ],
            'shipping-policy' => [
                'label' => __('配送政策'),
                'title' => 'Shipping Policy',
                'body' => <<<'MD'
## Processing time

Orders are processed within 1–3 business days (excluding weekends and holidays).

## Shipping rates and delivery

Shipping costs and estimated delivery times are shown at checkout before you pay.

## Customs and import duties

International orders may be subject to import duties and taxes charged by the destination country. These are the buyer's responsibility.

## Lost or damaged parcels

If your parcel arrives damaged or doesn't arrive, contact us and we'll help resolve it.
MD,
            ],
            'refund-policy' => [
                'label' => __('退换货政策'),
                'title' => 'Refund Policy',
                'body' => <<<'MD'
## Returns

You have **30 days** after receiving your item to request a return. Items must be unworn, in their original condition and packaging.

## How to start a return

Contact us with your order number. We'll reply with return instructions.

## Refunds

Once we receive and inspect your return, we'll email you. Approved refunds go back to your original payment method within 5–10 business days.

## Exchanges

The fastest way to get a different item is to return the one you have and place a new order.
MD,
            ],
            'privacy-policy' => [
                'label' => __('隐私政策'),
                'title' => 'Privacy Policy',
                'body' => <<<'MD'
## What we collect

When you place an order or create an account we collect your name, email, shipping address and order details. Payment card details are handled by our payment provider and never stored by {store}.

## How we use it

To process and ship your orders, to send order updates, and — only if you opt in — to send news and offers.

## Your rights

You can ask us to access, correct or delete your personal information at any time. Just reply to any of our emails.

## Cookies

We use essential cookies to keep your shopping bag and sign-in working.
MD,
            ],
            'terms-of-service' => [
                'label' => __('服务条款'),
                'title' => 'Terms of Service',
                'body' => <<<'MD'
## Overview

By placing an order with {store} you agree to these terms.

## Products and pricing

We try to show products and prices accurately. If an error occurs we'll contact you before processing the order.

## Orders

We may refuse or cancel an order, for example if a product is unavailable or a payment can't be verified. You will be refunded in full.

## Contact

Questions about these terms? Reply to any of our emails.
MD,
            ],
        ];
    }
}
