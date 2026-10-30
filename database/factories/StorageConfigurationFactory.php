<?php

namespace Database\Factories;

use App\Models\StorageConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorageConfiguration>
 */
class StorageConfigurationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(), 'driver' => 's3', 'region' => 'us-east-1',
            'endpoint' => 'https://s3.example.invalid', 'bucket' => 'fixture-bucket', 'prefix' => 'fixture',
            'public_url' => 'https://cdn.example.invalid', 'path_style' => true, 'enabled' => true,
            'credentials' => ['key' => 'fixture-key', 'secret' => 'fixture-secret'],
        ];
    }
}
