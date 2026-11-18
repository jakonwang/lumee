<?php

namespace App\GiftCards;

use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gift card lifecycle: issue, spend at checkout (inside the order transaction, row-locked),
 * restore when the order is cancelled, and manual balance adjustments. Every change is a transaction row.
 */
class GiftCards
{
    /** No 0/O/1/I so codes are easy to read out and type. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generateCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 16; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (GiftCard::where('code', $code)->exists());

        return $code;
    }

    public function issue(array $attrs, int $value, ?string $code = null): GiftCard
    {
        $code = $code ? GiftCard::normalize($code) : $this->generateCode();
        if (strlen($code) < 8 || strlen($code) > 32) {
            throw ValidationException::withMessages(['code' => __('礼品卡代码需要 8–32 位字母或数字')]);
        }
        if (GiftCard::where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('这个礼品卡代码已经存在')]);
        }

        return DB::transaction(function () use ($attrs, $value, $code) {
            $card = GiftCard::create([...$attrs, 'code' => $code, 'last4' => substr($code, -4), 'initial_value' => $value, 'balance' => $value,
                'created_by' => $this->userId()]);
            $this->log($card, $value, 'issued');

            return $card;
        });
    }

    /** The usable card for a code typed by the buyer, or null. Timing-safe enough: codes are 16 random chars. */
    public function findUsable(string $code, string $currency): ?GiftCard
    {
        $card = GiftCard::where('code', GiftCard::normalize($code))->first();

        return $card && $card->usable() && $card->currency === $currency ? $card : null;
    }

    /** Spend up to $amount from the card (call inside the order's DB transaction). Returns what was taken. */
    public function spend(string $code, int $amount, Order $order): array
    {
        $card = GiftCard::where('code', GiftCard::normalize($code))->lockForUpdate()->first();
        if (! $card || ! $card->usable() || $card->currency !== $order->currency) {
            throw ValidationException::withMessages(['gift_card' => __('This gift card can no longer be used.')]);
        }
        $take = min($card->balance, max(0, $amount));
        if ($take > 0) {
            $card->decrement('balance', $take);
            $this->log($card->refresh(), -$take, 'spent', $order->id);
        }

        return [$card, $take];
    }

    /**
     * Gift card products: one card per unit bought, worth the price paid, emailed to the buyer.
     * Idempotent (keyed on the order item), so a repeated "paid" event never issues twice.
     * An order of only gift cards is marked fulfilled — there is nothing to ship.
     */
    public function issuePurchased(Order $order): void
    {
        $order->loadMissing('items');
        $productIds = $order->items->pluck('product_id')->filter()->unique();
        if ($productIds->isEmpty()) {
            return;
        }
        $giftIds = \App\Models\Product::withoutGlobalScope('store')->whereIn('id', $productIds)->where('is_gift_card', true)->pluck('id')->flip();
        if ($giftIds->isEmpty()) {
            return;
        }
        $store = \App\Models\Store::find($order->store_id);
        $issued = [];
        foreach ($order->items as $item) {
            if (! $giftIds->has($item->product_id)) {
                continue;
            }
            $already = GiftCard::withoutGlobalScope('store')->where('store_id', $order->store_id)->where('order_item_id', $item->id)->count();
            for ($i = $already; $i < $item->quantity; $i++) {
                $card = DB::transaction(function () use ($order, $item) {
                    $code = $this->generateCode();
                    $card = GiftCard::withoutGlobalScope('store')->forceCreate([
                        'store_id' => $order->store_id, 'code' => $code, 'last4' => substr($code, -4),
                        'initial_value' => $item->price, 'balance' => $item->price, 'currency' => $order->currency,
                        'customer_id' => $order->customer_id, 'order_item_id' => $item->id, 'source' => 'purchase',
                        'recipient_email' => $order->email, 'note' => $order->name(),
                    ]);
                    $this->log($card, $item->price, 'issued', $order->id);

                    return $card;
                });
                $issued[] = $card;
            }
        }
        foreach ($issued as $card) {
            try {
                $locale = $order->locale ?: \App\I18n\Localization::storeDefault($store);
                \Illuminate\Support\Facades\Mail::to($order->email)->locale($locale)->send(new \App\Notifications\GiftCardMail($card, $store));
                $card->forceFill(['sent_at' => now()])->save();
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if ($issued) {
            $order->log('gift_card', __('已发放 :p0 张礼品卡给买家', ['p0' => count($issued)]));
        }
        if ($order->fulfillment_status === 'unfulfilled' && $order->items->every(fn ($i) => $giftIds->has($i->product_id))) {
            $order->forceFill(['fulfillment_status' => 'fulfilled', 'fulfilled_at' => now()])->save();
        }
    }

    /** Give back what an order took (order cancelled). */
    public function restore(Order $order): int
    {
        if (! $order->gift_card_id || ! $order->gift_card_total) {
            return 0;
        }
        $spent = -(int) GiftCardTransaction::where('gift_card_id', $order->gift_card_id)->where('order_id', $order->id)->sum('amount');
        if ($spent <= 0) {
            return 0;
        }
        $card = GiftCard::whereKey($order->gift_card_id)->lockForUpdate()->first();
        if (! $card) {
            return 0;
        }
        $card->increment('balance', $spent);
        $this->log($card->refresh(), $spent, 'restored', $order->id);

        return $spent;
    }

    public function adjust(GiftCard $card, int $newBalance, ?string $note): void
    {
        DB::transaction(function () use ($card, $newBalance, $note) {
            $locked = GiftCard::whereKey($card->id)->lockForUpdate()->first();
            $delta = $newBalance - $locked->balance;
            if ($delta === 0) {
                return;
            }
            $locked->update(['balance' => $newBalance]);
            $this->log($locked, $delta, 'adjusted', null, $note);
        });
    }

    public function log(GiftCard $card, int $amount, string $kind, ?int $orderId = null, ?string $note = null): void
    {
        GiftCardTransaction::withoutGlobalScope('store')->forceCreate([
            'store_id' => $card->store_id, 'gift_card_id' => $card->id, 'order_id' => $orderId, 'amount' => $amount,
            'balance_after' => $card->balance, 'kind' => $kind, 'user_id' => $this->userId(),
            'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'created_at' => now(),
        ]);
    }

    private function userId(): ?int
    {
        $u = request()?->user();

        return $u instanceof User ? $u->id : null;
    }
}
