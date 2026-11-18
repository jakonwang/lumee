<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    use BelongsToStore;

    protected $guarded = ['id'];
}
