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
        $dbPath = config('database.connections.sqlite.database');

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

        $this->runPendingMigrations();
    }

    /**
     * Bring the SQLite schema up to date.
     *
     * A freshly created database file has no tables at all, and an existing one
     * can be a schema behind after the user installs an app update. Both leave
     * the app querying tables that do not exist, so migrate before serving.
     */
    protected function runPendingMigrations(): void
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
                Artisan::call('migrate', ['--force' => true]);
                Log::info('[NativeApp] Applied pending migrations: ' . trim(Artisan::output()));
            }
        } catch (\Throwable $e) {
            Log::error('[NativeApp] Failed to migrate database: ' . $e->getMessage());
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
