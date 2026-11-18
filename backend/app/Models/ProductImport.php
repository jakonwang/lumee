<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class ProductImport extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'user_id', 'filename', 'mode', 'status', 'payload', 'summary', 'results', 'total', 'processed'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'results' => 'array', 'total' => 'integer', 'processed' => 'integer'];
    }

    public function products(): array
    {
        return json_decode($this->payload, true) ?: [];
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'mode' => $this->mode,
            'status' => $this->status,
            'summary' => $this->summary,
            'results' => $this->results,
            'total' => $this->total,
            'processed' => $this->processed,
            'created_at' => $this->created_at,
        ];
    }
}
