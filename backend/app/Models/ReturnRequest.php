<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's request to send items back (see App\Returns\Returns). */
class ReturnRequest extends Model
{
    use BelongsToStore;

    public const REASONS = [
        'size' => 'Size or fit',
        'damaged' => 'Damaged or defective',
        'not_as_described' => 'Not as described',
        'wrong_item' => 'Wrong item received',
        'changed_mind' => 'Changed my mind',
        'other' => 'Other',
    ];

    /** The same reasons in the merchant's wording (admin + merchant email). */
    public const ADMIN_REASONS = [
        'size' => '尺码或版型不合适', 'damaged' => '商品损坏或有质量问题', 'not_as_described' => '与描述不符',
        'wrong_item' => '发错商品', 'changed_mind' => '不想要了', 'other' => '其他',
    ];

    public const STATUSES = ['requested', 'approved', 'declined', 'completed'];

    protected $guarded = ['id', 'store_id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'decided_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
