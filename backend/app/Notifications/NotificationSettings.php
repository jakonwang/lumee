<?php

namespace App\Notifications;

use App\Models\Store;

/**
 * Per-store email settings, kept in stores.settings['notifications'].
 * Emails are sent from the platform address (good SPF/DKIM deliverability) with the
 * store's name as the sender name, and replies go to the store's contact email.
 */
final class NotificationSettings
{
    /** type => [audience, label, description] */
    public const TYPES = [
        'order_confirmation' => ['customer', '订单确认', '买家下单（银行转账）或付款成功（银行卡）后发送'],
        'payment_received' => ['customer', '收款确认', '银行转账订单被标记为已付款后发送'],
        'shipping_confirmation' => ['customer', '发货通知', '订单发货后发送，包含物流单号'],
        'pickup_ready' => ['customer', '自提通知', '到店自提订单备好后发送（点「发货」时），包含自提地址和营业时间'],
        'payment_reminder' => ['customer', '未付款提醒', '买家去在线付款后没有完成，下单 1 小时后提醒一次，附「完成付款」链接（24 小时后仍未付款的订单会自动取消）'],
        'order_cancelled' => ['customer', '订单取消', '取消订单时可选择发送，包含退款说明'],
        'refund_notification' => ['customer', '退款通知', '退款时可选择发送，告知退款金额'],
        'delivery_confirmation' => ['customer', '签收通知', '包裹显示已签收时发送（需要平台开启物流跟踪）'],
        'review_request' => ['customer', '邀请评价', '发货几天后邀请买家给买到的商品写评价（天数在「商品 → 评论 → 评论设置」里修改）'],
        'merchant_new_order' => ['merchant', '新订单提醒', '有新订单时通知店铺员工'],
    ];

    public function __construct(private Store $store) {}

    public static function for(Store $store): self
    {
        return new self($store);
    }

    public function all(): array
    {
        $saved = $this->store->settings['notifications'] ?? [];
        $owner = $this->store->owner?->email;

        return [
            'sender_name' => $saved['sender_name'] ?? $this->store->name,
            'reply_to' => $saved['reply_to'] ?? $owner,
            'merchant_email' => $saved['merchant_email'] ?? $owner,
            'enabled' => array_merge(
                array_fill_keys(array_keys(self::TYPES), true),
                array_intersect_key($saved['enabled'] ?? [], self::TYPES),
            ),
        ];
    }

    public function enabled(string $type): bool
    {
        return (bool) ($this->all()['enabled'][$type] ?? false);
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function save(array $data): void
    {
        $settings = $this->store->settings ?? [];
        $settings['notifications'] = [
            'sender_name' => $data['sender_name'],
            'reply_to' => $data['reply_to'],
            'merchant_email' => $data['merchant_email'],
            'enabled' => array_map('boolval', array_intersect_key($data['enabled'] ?? [], self::TYPES)),
        ];
        $this->store->settings = $settings;
        $this->store->save();
    }
}
