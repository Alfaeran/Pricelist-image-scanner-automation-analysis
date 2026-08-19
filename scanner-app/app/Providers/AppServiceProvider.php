<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound here rather than in NativeAppServiceProvider: that class is
        // owned by NativePHP and is no longer a Laravel service provider, so
        // its register() is never called.
        $this->app->singleton(\App\Services\ProcessManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
