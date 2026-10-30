<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Image extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['processing' => 'array', 'disabled_at' => 'datetime', 'storage_configuration_id' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function storageConfiguration(): BelongsTo
    {
        return $this->belongsTo(StorageConfiguration::class);
    }

    public function getUrlAttribute(): string
    {
        return $this->storageConfiguration?->publicUrl($this->path) ?? rtrim(config('vault.public_url') ?: config('app.url'), '/').'/media/'.$this->path;
    }

    public function getPreviewUrlAttribute(): string
    {
        return route('images.preview', $this->id);
    }
}
