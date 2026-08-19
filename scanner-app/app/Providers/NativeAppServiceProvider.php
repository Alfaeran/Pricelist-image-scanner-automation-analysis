<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class NativeAppServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\ProcessManager::class, function ($app) {
            return new \App\Services\ProcessManager();
        });
    }

    /**
     * Bootstrap services.
     *
     * When running as a NativePHP desktop app, this provider automatically
     * starts the FastAPI subprocess and the queue worker so the user doesn't
     * need to manage multiple terminals.
     */
    public function boot(): void
    {
        // Only auto-start subprocesses when running as a native desktop app
        if ($this->isNativeDesktop()) {
            $this->ensureSqliteDatabase();
            $this->startManagedProcesses();
        }
    }

    /**
     * Detect whether we are running inside a NativePHP desktop context.
     */
    protected function isNativeDesktop(): bool
    {
        // NativePHP sets this environment variable when running as desktop
        return env('NATIVEPHP_RUNNING', false)
            || (class_exists(\Native\Laravel\Facades\Window::class) && app()->runningInConsole() === false);
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
