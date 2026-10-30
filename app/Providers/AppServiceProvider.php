<?php

namespace App\Providers;

use App\Services\Installation\InstallationState;
use App\Services\StorageMutex;
use App\Services\Updates\UpdateRunner;
use App\Services\Updates\UpdateState;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StorageMutex::class);
        $this->app->bind(InstallationState::class, fn () => new InstallationState(base_path()));
        $this->app->bind(UpdateState::class, fn () => new UpdateState(base_path()));
        $this->app->bind(UpdateRunner::class, fn () => new UpdateRunner(base_path(), config('database.connections.mysql')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
