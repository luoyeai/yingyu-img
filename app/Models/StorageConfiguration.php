<?php

namespace App\Models;

use Database\Factories\StorageConfigurationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageConfiguration extends Model
{
    /** @use HasFactory<StorageConfigurationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'enabled' => 'boolean', 'path_style' => 'boolean'];
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class)->withTrashed();
    }

    public function objectKey(string $path): string
    {
        return ($this->prefix === '' ? '' : trim($this->prefix, '/').'/').$path;
    }

    public function publicUrl(string $path): string
    {
        if ($this->driver === 'local') {
            return rtrim(config('vault.public_url') ?: config('app.url'), '/').'/media/'.$path;
        }

        return rtrim($this->public_url, '/').'/'.$this->objectKey($path);
    }
}
