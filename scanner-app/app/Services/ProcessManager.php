<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Manages background subprocesses for the desktop app:
 * - FastAPI (Python AI pipeline)
 * - Queue Worker (Laravel job processing)
 *
 * All processes are started as non-blocking children and are
 * terminated gracefully when the application shuts down.
 */
class ProcessManager
{
    /** @var Process[] */
    protected array $processes = [];

    protected bool $started = false;

    /**
     * Start all managed processes.
     */
    public function startAll(): void
    {
        if ($this->started) {
            return;
        }

        $this->startFastApi();
        $this->startQueueWorker();
        $this->started = true;

        // Register shutdown handler to clean up processes
        register_shutdown_function([$this, 'stopAll']);

        Log::info('[ProcessManager] All managed processes started.');
    }

    /**
     * Stop all managed processes gracefully.
     */
    public function stopAll(): void
    {
        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                Log::info("[ProcessManager] Stopping {$name}...");
                $process->stop(10); // 10 second graceful timeout
            }
        }

        $this->processes = [];
        $this->started = false;
        Log::info('[ProcessManager] All managed processes stopped.');
    }

    /**
     * Check health of all processes.
     *
     * @return array<string, array{running: bool, pid: ?int}>
     */
    public function health(): array
    {
        $status = [];

        foreach ($this->processes as $name => $process) {
            $status[$name] = [
                'running' => $process->isRunning(),
                'pid' => $process->isRunning() ? $process->getPid() : null,
            ];
        }

        return $status;
    }

    /**
     * Restart a specific process by name.
     */
    public function restart(string $name): bool
    {
        if (!isset($this->processes[$name])) {
            return false;
        }

        $process = $this->processes[$name];
        if ($process->isRunning()) {
            $process->stop(5);
        }

        // Re-start based on the process name
        unset($this->processes[$name]);

        return match ($name) {
            'fastapi' => $this->startFastApi(),
            'queue_worker' => $this->startQueueWorker(),
            default => false,
        };
    }

    /**
     * Start the FastAPI Python subprocess.
     */
    protected function startFastApi(): bool
    {
        $pythonPath = config('nativephp.python_path', 'python');
        $port = config('nativephp.fastapi_port', 8091);
        $srcDir = base_path('../src');

        // Verify the src directory exists
        if (!is_dir($srcDir)) {
            Log::error("[ProcessManager] FastAPI src directory not found: {$srcDir}");
            return false;
        }

        $command = [
            $pythonPath, '-m', 'uvicorn',
            'fastapi_app:app',
            '--host', '127.0.0.1',
            '--port', (string) $port,
        ];

        // The Python side calls Laravel back (extraction status pushes, learned-pattern
        // lookups). Desktop does not serve Laravel on the default port, so it has to be
        // told where to reach us rather than assuming 127.0.0.1:8000.
        $env = [
            'NATIVEPHP_FASTAPI_PORT' => (string) $port,
            'LARAVEL_URL' => rtrim((string) config('app.url'), '/'),
        ];

        $process = new Process($command, $srcDir, $env);
        $process->setTimeout(null); // Run indefinitely
        $process->start(function ($type, $buffer) {
            if ($type === Process::ERR) {
                Log::warning("[FastAPI] {$buffer}");
            } else {
                Log::info("[FastAPI] {$buffer}");
            }
        });

        $this->processes['fastapi'] = $process;
        Log::info("[ProcessManager] FastAPI started on port {$port} (PID: {$process->getPid()})");

        return true;
    }

    /**
     * Start the Laravel Queue Worker subprocess.
     */
    protected function startQueueWorker(): bool
    {
        $phpPath = PHP_BINARY;
        $artisan = base_path('artisan');

        $command = [
            $phpPath, $artisan,
            'queue:work',
            '--tries=3',
            '--timeout=120',
            '--sleep=3',
        ];

        $process = new Process($command);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null);
        $process->start(function ($type, $buffer) {
            if ($type === Process::ERR) {
                Log::warning("[QueueWorker] {$buffer}");
            } else {
                Log::info("[QueueWorker] {$buffer}");
            }
        });

        $this->processes['queue_worker'] = $process;
        Log::info("[ProcessManager] Queue worker started (PID: {$process->getPid()})");

        return true;
    }
}
