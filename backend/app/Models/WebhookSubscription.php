<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookSubscription extends Model
{
    use BelongsToStore;

    /** topic => label */
    public const TOPICS = [
        'orders/create' => '新订单',
        'orders/paid' => '订单已付款',
        'orders/updated' => '订单已修改',
        'orders/fulfilled' => '订单已发货',
        'orders/cancelled' => '订单已取消',
        'orders/refunded' => '订单退款',
        'products/create' => '新建商品',
        'products/update' => '修改商品',
        'products/delete' => '删除商品',
        'customers/create' => '新客户',
        'customers/data_request' => '客户请求导出个人数据',
        'customers/redact' => '客户个人数据已删除',
    ];

    /** Disable after this many failed deliveries in a row (each already retried several times). */
    public const MAX_FAILURES = 10;

    protected $fillable = ['url', 'topics', 'enabled'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['topics' => 'array', 'enabled' => 'boolean', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
