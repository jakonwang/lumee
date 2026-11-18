<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use BelongsToStore;

    /** scope => [label, description] */
    public const SCOPES = [
        'read_products' => ['读取商品', '商品、规格、库存、分类'],
        'write_products' => ['管理商品', '创建、修改、删除商品'],
        'read_orders' => ['读取订单', '订单、商品明细、收货地址'],
        'write_orders' => ['处理订单', '标记发货、填写物流单号'],
        'read_customers' => ['读取客户', '客户名单、邮箱、消费记录'],
        'write_inventory' => ['修改库存', '调整规格库存数量'],
    ];

    protected $fillable = ['name', 'scopes'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** Create a token; returns [model, plaintext]. The plaintext is never stored. */
    public static function issue(Store $store, string $name, array $scopes, ?int $userId): array
    {
        $plain = 'lm_'.Str::random(40);
        $token = new self(['name' => $name, 'scopes' => array_values(array_intersect(array_keys(self::SCOPES), $scopes))]);
        $token->forceFill([
            'store_id' => $store->id,
            'token_prefix' => substr($plain, 0, 10),
            'token_hash' => hash('sha256', $plain),
            'created_by' => $userId,
        ])->save();

        return [$token, $plain];
    }

    public static function findActive(string $plain): ?self
    {
        return self::withoutGlobalScope('store')->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
