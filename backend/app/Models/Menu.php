<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    use BelongsToStore;

    public const HANDLES = ['main' => '主菜单', 'footer' => '页脚菜单'];

    protected $fillable = ['store_id', 'handle', 'title', 'items'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
