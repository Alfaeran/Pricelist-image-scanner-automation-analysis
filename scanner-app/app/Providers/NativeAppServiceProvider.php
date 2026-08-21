<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

/**
 * Entry point for the desktop app.
 *
 * This is NOT a Laravel service provider. NativePHP resolves the class named by
 * config('nativephp.provider') and calls boot() exactly once, when the desktop
 * app reports itself booted. Registering it in bootstrap/providers.php would
 * make Laravel call boot() on every request and open a window each time.
 */
class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Prepare the app and put a window on screen.
     *
     * Nothing is visible until Window::open() is called - Electron will happily
     * run with no window at all.
     */
    public function boot(): void
    {
        $this->ensureSqliteDatabase();
        $this->ensureBaselineProducts();
        $this->startManagedProcesses();

        $window = (array) config('nativephp.window', []);

        Window::open()
            ->title((string) config('app.name'))
            ->width($window['width'] ?? 1400)
            ->height($window['height'] ?? 900)
            ->minWidth($window['min_width'] ?? 1024)
            ->minHeight($window['min_height'] ?? 700)
            ->resizable($window['resizable'] ?? true);
    }

    /**
     * php.ini directives for the bundled PHP runtime. Brochure scans are
     * image-heavy, and the defaults are too small for a multi-image upload.
     */
    public function phpIni(): array
    {
        return [
            'upload_max_filesize' => '64M',
            'post_max_size' => '64M',
            'memory_limit' => '512M',
            'max_execution_time' => '300',
        ];
    }

    /**
     * Ensure the SQLite database file exists.
     */
    protected function ensureSqliteDatabase(): void
    {
        // Not connections.sqlite: NativeServiceProvider rewrites
        // database.default to 'nativephp' at runtime, so that is the file the
        // app actually reads and writes. Migrating the other one leaves the
        // real database a schema behind.
        $connection = config('database.default');
        $dbPath = config("database.connections.{$connection}.database");

        if (!$dbPath) {
            return;
        }

        if (!file_exists($dbPath)) {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            touch($dbPath);
            Log::info("[NativeApp] Created SQLite database at: {$dbPath}");
        }

        $this->runPendingMigrations($connection);
    }

    /**
     * Bring the SQLite schema up to date.
     *
     * A freshly created database file has no tables at all, and an existing one
     * can be a schema behind after the user installs an app update. Both leave
     * the app querying tables that do not exist, so migrate before serving.
     */
    protected function runPendingMigrations(string $connection): void
    {
        try {
            if (!Schema::hasTable('migrations')) {
                $pending = true;
            } else {
                // Migrations are only ever added, so more files than recorded
                // rows means there is something left to run.
                $applied = DB::table('migrations')->count();
                $onDisk = count(glob(database_path('migrations') . DIRECTORY_SEPARATOR . '*.php'));
                $pending = $onDisk > $applied;
            }

            if ($pending) {
                // Telescope's migration ignores database.default and reads its
                // own config, which is baked from DB_CONNECTION ('sqlite') and
                // points at a file the installer does not ship. Redirect it at
                // the real connection or the whole migration run dies on it.
                config(['telescope.storage.database.connection' => $connection]);

                // --database is not optional here. Artisan::call() boots a fresh
                // kernel that never runs NativeServiceProvider::boot(), so
                // database.default reverts to the .env value ('sqlite') and the
                // migration targets a file that does not exist inside the
                // installer. Naming the connection keeps it on the real database.
                Artisan::call('migrate', [
                    '--force' => true,
                    '--database' => $connection,
                ]);
                Log::info('[NativeApp] Applied pending migrations: ' . trim(Artisan::output()));
            }
        } catch (\Throwable $e) {
            Log::error('[NativeApp] Failed to migrate database: ' . $e->getMessage());
        }
    }

    /**
     * Seed the baseline catalogue on first run.
     *
     * ProcessPricelistJob compares every extracted package against
     * baseline_products to decide "new product" and "price changed". The web
     * app filled that table by running baseline:import-csv from a terminal;
     * the desktop app has no terminal, so an empty table would silently mark
     * nothing as changed. Only seeds when empty - never overwrites edits the
     * user has made through the Baseline Products screen.
     */
    protected function ensureBaselineProducts(): void
    {
        try {
            if (!Schema::hasTable('baseline_products')) {
                return;
            }

            if (DB::table('baseline_products')->exists()) {
                return;
            }

            if (!file_exists(base_path('List produk.csv'))) {
                Log::warning('[NativeApp] baseline_products is empty and List produk.csv is missing; change detection will be inactive.');

                return;
            }

            Artisan::call('baseline:import-csv');
            Log::info('[NativeApp] Seeded baseline products: ' . trim(Artisan::output()));
        } catch (\Throwable $e) {
            Log::error('[NativeApp] Failed to seed baseline products: ' . $e->getMessage());
        }
    }

    /**
     * Start managed background processes (FastAPI, Queue Worker).
     */
    protected function startManagedProcesses(): void
    {
        try {
            /** @var \App\Services\ProcessManager $pm */
            $pm = app(\App\Services\ProcessManager::class);
            $pm->startAll();
        } catch (\Throwable $e) {
            Log::error("[NativeApp] Failed to start managed processes: " . $e->getMessage());
        }
    }
}
