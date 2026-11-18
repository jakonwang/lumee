<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailFlowEnrollment extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'email_flow_id', 'customer_id', 'next_position', 'next_send_at', 'status', 'stop_reason', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['next_send_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'next_position' => 'integer'];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(EmailFlow::class, 'email_flow_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
