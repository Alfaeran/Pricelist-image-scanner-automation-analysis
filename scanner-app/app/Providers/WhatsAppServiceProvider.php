<?php

namespace App\Providers;

use App\Services\WhatsApp\EvolutionApiService;
use App\Services\WhatsApp\MetaCloudApiService;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use Illuminate\Support\ServiceProvider;

/**
 * WhatsApp Service Provider
 *
 * Binds the WhatsAppServiceInterface to the configured driver implementation.
 * Driver is selected via WHATSAPP_DRIVER env variable:
 * - 'meta_cloud' → MetaCloudApiService (official API)
 * - 'evolution'  → EvolutionApiService (self-hosted)
 */
class WhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Register WhatsApp service binding.
     */
    public function register(): void
    {
        $this->app->singleton(WhatsAppServiceInterface::class, function ($app) {
            $driver = config('services.whatsapp.driver', 'evolution');

            return match ($driver) {
                'meta_cloud' => new MetaCloudApiService(),
                'evolution'  => new EvolutionApiService(),
                default      => new EvolutionApiService(),
            };
        });
    }

    /**
     * Bootstrap WhatsApp services.
     */
    public function boot(): void
    {
        //
    }
}
