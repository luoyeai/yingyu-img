<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function value(string $key, mixed $default = null): mixed
    {
        return static::find($key)?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function uploadOptions(): array
    {
        return array_replace(['quality' => 80, 'lossless' => false, 'max_edge' => 0, 'keep_original' => false, 'max_pixels' => 40000000, 'max_file_mb' => 20, 'batch_limit' => 50], static::value('upload', []));
    }
}
