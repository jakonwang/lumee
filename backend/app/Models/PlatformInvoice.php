<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** What a merchant pays Lumée (subscription charges, monthly transaction fees). Amounts in USD cents. */
class PlatformInvoice extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'number', 'kind', 'description', 'amount', 'currency', 'status',
        'period_start', 'period_end', 'lines', 'provider_ref', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'lines' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /** LM-2026-000123 — sequential across the platform. */
    public static function nextNumber(): string
    {
        $last = DB::table('platform_invoices')->lockForUpdate()->max('id') ?? 0;

        return 'LM-'.now()->format('Y').'-'.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'kind' => $this->kind,
            'description' => $this->description,
            'amount' => \App\Support\Money::format($this->amount),
            'currency' => $this->currency,
            'status' => $this->status,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'lines' => $this->lines ?? [],
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
