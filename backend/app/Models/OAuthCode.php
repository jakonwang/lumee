<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OAuthCode extends Model
{
    protected $table = 'oauth_codes';

    protected $fillable = ['app_id', 'store_id', 'user_id', 'code_hash', 'scopes', 'redirect_uri', 'code_challenge', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
