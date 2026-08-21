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
    'version' => env('NATIVEPHP_APP_VERSION', env('NATIVEPHP_VERSION', '1.0.0')),

    // Author information
    'author' => env('NATIVEPHP_APP_AUTHOR', env('NATIVEPHP_AUTHOR', 'Pricelist Scanner Team')),

    'copyright' => env('NATIVEPHP_APP_COPYRIGHT'),

    'website' => env('NATIVEPHP_APP_WEBSITE'),

    // Application description
    'description' => 'Sistem otomasi ekstraksi dan analisis pricelist menggunakan AI',

    /*
    |--------------------------------------------------------------------------
    | Service Provider
    |--------------------------------------------------------------------------
    |
    | NativePHP boots this provider inside the packaged app. Without it the
    | app starts with no window and no managed subprocesses.
    |
    */

    'provider' => \App\Providers\NativeAppServiceProvider::class,

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

        // Resolved even when disabled, so it must name a real provider with a
        // driver - a bare url key fails the build with "Driver [null]".
        'default' => env('NATIVEPHP_UPDATER_PROVIDER', 'github'),

        'providers' => [
            'github' => [
                'driver' => 'github',
                'repo' => env('GITHUB_REPO'),
                'owner' => env('GITHUB_OWNER'),
                'token' => env('GITHUB_TOKEN'),
                'vPrefixedTagName' => env('GITHUB_V_PREFIXED_TAG_NAME', true),
                'private' => env('GITHUB_PRIVATE', false),
                'autoupdate_token' => env('GITHUB_AUTOUPDATE_TOKEN'),
                'channel' => env('GITHUB_CHANNEL', 'latest'),
                'releaseType' => env('GITHUB_RELEASE_TYPE', 'draft'),
            ],
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Python / FastAPI Configuration
    |--------------------------------------------------------------------------
    |
    | These settings control how the embedded FastAPI subprocess is started.
    | The ProcessManager reads these values to spawn the AI pipeline.
    |
    */

    // Fallback interpreter, used only when no bundled venv is found below.
    'python_path' => env('NATIVEPHP_PYTHON_PATH', 'python'),

    /*
    | Interpreters shipped inside the app, tried before falling back to a
    | system Python. scanner-app/venv is bundled into the installer with the
    | pipeline's dependencies already installed, so a normal user never has to
    | install Python themselves. Set NATIVEPHP_PYTHON_PATH to override.
    */
    'python_venv' => [
        // The embedded runtime comes first on purpose. venv/Scripts/python.exe
        // is only a shim that reads pyvenv.cfg to find a real interpreter on
        // the machine that built it, so on a user's PC it resolves to nothing
        // and the app quietly falls back to a system Python. app:embed-python
        // puts a full interpreter here, which is what makes the installer
        // work without asking the user to install Python at all.
        base_path('venv/runtime/python.exe'),   // Windows, embedded
        base_path('venv/Scripts/python.exe'),   // Windows, developer venv
        base_path('venv/bin/python'),           // macOS / Linux
    ],

    'fastapi_port' => (int) env('NATIVEPHP_FASTAPI_PORT', 8091),

    /*
    | Where the Python pipeline lives, relative to the Laravel app.
    |
    | In the repo it sits next to scanner-app as ../src. The packaged app only
    | ships the Laravel directory, so `app:bundle-python` copies it to
    | resources/python during prebuild and ProcessManager prefers that copy.
    */
    'python_source' => [
        base_path('resources/python'),
        base_path('../src'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Packaging
    |--------------------------------------------------------------------------
    */

    // Secrets that must never end up inside a shipped installer.
    //
    // The bundle ships an unencrypted .env, so anything left here is readable
    // by whoever installs the app. These app-specific keys are credentials for
    // OUR accounts, not the user's, so they are stripped. Gemini keys are not
    // listed because they live in the api_keys table, entered per install.
    'cleanup_env_keys' => [
        // APP_DEBUG=true is what we want locally and exactly what we do not
        // want shipped. With debug on, NativePHP skips rewriteStoragePath()
        // and points rewriteDatabase() at a database INSIDE the install
        // folder, so every update wipes the user's data - and stack traces
        // get shown to end users. Stripping these two lets Laravel fall back
        // to its own defaults (production / false), which is correct here.
        'APP_ENV',
        'APP_DEBUG',
        'TELESCOPE_ENABLED',
        'WHATSAPP_*',
        'MAIL_PASSWORD',
        'MAIL_USERNAME',
        'REDIS_PASSWORD',
        '*_TOKEN',
        '*_API_KEY',
        '*_PASSWORD',
        'AWS_*',
        'AZURE_*',
        'GITHUB_*',
        'DO_SPACES_*',
        '*_SECRET',
        'BIFROST_*',
        'NATIVEPHP_UPDATER_PATH',
        'NATIVEPHP_APPLE_ID',
        'NATIVEPHP_APPLE_ID_PASS',
        'NATIVEPHP_APPLE_TEAM_ID',
        'NATIVEPHP_AZURE_PUBLISHER_NAME',
        'NATIVEPHP_AZURE_ENDPOINT',
        'NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME',
        'NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME',
    ],

    // Removed from the bundle before packaging.
    'cleanup_exclude_files' => [
        'build',
        'temp',
        'content',
        'node_modules',
        '*/tests',
    ],

    // Ship the Python pipeline and a freshly built frontend with the app.
    'prebuild' => [
        'npm run build',
        'php artisan app:bundle-python',
        'php artisan app:embed-python',
    ],

    'postbuild' => [
        //
    ],

    'nsis' => [
        'delete_app_data_on_uninstall' => env('NATIVEPHP_NSIS_DELETE_APP_DATA', false),
    ],

    'binary_path' => env('NATIVEPHP_PHP_BINARY_PATH', null),

    /*
    | Queue workers NativePHP starts for us. ProcessManager deliberately does
    | not also start one - two workers on the same table would race for jobs.
    */
    'queue_workers' => [
        'default' => [
            'queues' => ['default'],
            'memory_limit' => 128,
            'timeout' => 120,
            'sleep' => 3,
        ],
    ],
];
