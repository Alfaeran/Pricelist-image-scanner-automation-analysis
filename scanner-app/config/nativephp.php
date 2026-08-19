<?php

return [
    /*
    |--------------------------------------------------------------------------
    | NativePHP Application Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the NativePHP desktop application wrapper.
    | This is used when the app is packaged as a standalone desktop app.
    |
    */

    // Unique application identifier (reverse domain notation)
    'app_id' => env('NATIVEPHP_APP_ID', 'com.pricelistscanner.app'),

    // Display name shown in window title and system tray
    'app_name' => env('APP_NAME', 'Pricelist Scanner'),

    // Application version
    'version' => env('NATIVEPHP_VERSION', '1.0.0'),

    // Author information
    'author' => env('NATIVEPHP_AUTHOR', 'Pricelist Scanner Team'),

    // Application description
    'description' => 'Sistem otomasi ekstraksi dan analisis pricelist menggunakan AI',

    /*
    |--------------------------------------------------------------------------
    | Window Configuration
    |--------------------------------------------------------------------------
    */

    'window' => [
        'width' => 1400,
        'height' => 900,
        'min_width' => 1024,
        'min_height' => 700,
        'resizable' => true,
        'title_bar_style' => 'default', // 'default', 'hidden', 'hiddenInset'
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Updater
    |--------------------------------------------------------------------------
    |
    | URL to check for application updates.
    | Set to null to disable auto-updates.
    |
    */

    'updater' => [
        'enabled' => env('NATIVEPHP_UPDATER_ENABLED', false),
        'url' => env('NATIVEPHP_UPDATER_URL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deep Linking
    |--------------------------------------------------------------------------
    |
    | Protocol scheme for deep links (e.g., pricelist-scanner://action)
    |
    */

    'deeplink_scheme' => 'pricelist-scanner',

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | When running as a desktop app, SQLite is used by default.
    | The database file is stored in the user's app data directory.
    |
    */

    'database' => [
        'driver' => 'sqlite',
        'path' => null, // null = NativePHP default location
    ],
];
