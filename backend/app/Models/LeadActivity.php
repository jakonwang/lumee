<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** Timeline entry on a lead: status change, note, email sent, call made. */
class LeadActivity extends Model
{
    use BelongsToStore;

    protected $fillable = ['lead_id', 'type', 'body', 'user_id'];
}
