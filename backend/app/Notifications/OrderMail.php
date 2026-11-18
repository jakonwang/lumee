<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Store;
use App\Support\Color;
use App\Themes\ThemeContext;
use App\Themes\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** One mailable for every order email; the template is picked by $type. */
class OrderMail extends Mailable
{
    public ThemeContext $storeTheme;

    public function __construct(public Order $order, public string $type, public Store $store)
    {
        // Emails use the live (published) theme: logo and brand colors
        $this->storeTheme = app(ThemeService::class)->resolve($store);
    }

    public function envelope(): Envelope
    {
        $settings = NotificationSettings::for($this->store);
        $replyTo = $settings->get('reply_to');

        return new Envelope(
            // Platform address keeps SPF/DKIM valid; the store's name is what customers see
            from: new Address(config('mail.from.address'), $settings->get('sender_name')),
            replyTo: $replyTo ? [new Address($replyTo, $this->store->name)] : [],
            subject: $this->subjectLine(),
        );
    }

    public function content(): Content
    {
        return new Content(view: "emails.orders.{$this->type}", with: [
            'order' => $this->order,
            'store' => $this->store,
            'theme' => $this->storeTheme,
            'orderUrl' => $this->store->storefrontUrl().self::localePrefix($this->order->locale, $this->store)."/orders/{$this->order->token}",
            'adminUrl' => rtrim((string) config('lumee.admin_url'), '/')."/orders/{$this->order->id}",
            'money' => fn (?int $v) => \App\Support\Money::display($v, $this->order->currency),
            'c' => self::palette($this->storeTheme),
        ]);
    }

    /** "/fr" when the shopper used a non-default storefront language (links keep them in it). */
    public static function localePrefix(?string $locale, Store $store): string
    {
        return $locale && $locale !== \App\I18n\Localization::storeDefault($store)
            && in_array($locale, \App\I18n\Localization::enabled($store), true) ? '/'.$locale : '';
    }

    /** Email-safe colors derived from the store's theme (all inputs are validated hex colors). */
    public static function palette(ThemeContext $theme): array
    {
        $primary = $theme->setting('colors.primary');
        $ink = $theme->setting('colors.text');

        return [
            'primary' => $primary,
            'ink' => $ink,
            'muted' => Color::mix($ink, '#FFFFFF', 0.42),
            'bg' => Color::mix($theme->setting('colors.background'), $primary, 0.04),
            'border' => Color::mix('#FFFFFF', $primary, 0.2),
            'soft' => Color::mix('#FFFFFF', $primary, 0.08),
            'on_primary' => Color::luminance($primary) > 0.45 ? $ink : '#FFFFFF',
            'serif' => "Georgia, 'Times New Roman', serif",
            'sans' => "-apple-system, 'Segoe UI', Helvetica, Arial, 'PingFang SC', 'Microsoft YaHei', sans-serif",
        ];
    }

    private function subjectLine(): string
    {
        $n = $this->order->name();

        return match ($this->type) {
            'order_confirmation' => $this->order->isPaid()
                ? __('Order :number confirmed', ['number' => $n])
                : __('Order :number received — payment instructions inside', ['number' => $n]),
            'payment_received' => __('Payment received for order :number', ['number' => $n]),
            'shipping_confirmation' => __('Your order :number is on its way', ['number' => $n]),
            'pickup_ready' => __('Your order :number is ready for pickup', ['number' => $n]),
            'order_cancelled' => __('Order :number has been cancelled', ['number' => $n]),
            'refund_notification' => __('Refund issued for order :number', ['number' => $n]),
            'delivery_confirmation' => __('Your order :number has been delivered', ['number' => $n]),
            'review_request' => __('How are you enjoying your order?'),
            'merchant_new_order' => __('【:p0】新订单 :p1 · ', ['p0' => $this->store->name, 'p1' => $n]).\App\Support\Money::display($this->order->total, $this->order->currency),
        };
    }
}
