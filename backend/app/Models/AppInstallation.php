<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppInstallation extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'app_id', 'scopes', 'installed_by', 'installed_at', 'uninstalled_at'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'installed_at' => 'datetime', 'uninstalled_at' => 'datetime'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function active(): bool
    {
        return $this->uninstalled_at === null;
    }
}
