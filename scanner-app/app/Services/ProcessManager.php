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

        // Only FastAPI. Queue workers are started by NativePHP itself from the
        // 'queue_workers' config; starting a second one here would put two
        // workers on the same jobs table.
        $this->startFastApi();
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
            default => false,
        };
    }

    /**
     * Start the FastAPI Python subprocess.
     */
    protected function startFastApi(): bool
    {
        $pythonPath = $this->resolvePythonBinary();
        $port = config('nativephp.fastapi_port', 8091);
        $srcDir = $this->resolvePythonSource();

        if ($srcDir === null) {
            Log::error('[ProcessManager] Could not find fastapi_app.py in any of: '
                . implode(', ', (array) config('nativephp.python_source', [])));
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
     * Pick the Python interpreter to run the pipeline with.
     *
     * The installer ships a venv that already has the pipeline's dependencies,
     * so prefer it: an end user should not have to install Python at all. An
     * explicit NATIVEPHP_PYTHON_PATH still wins, for people pointing the app at
     * their own environment.
     */
    protected function resolvePythonBinary(): string
    {
        $configured = env('NATIVEPHP_PYTHON_PATH');

        if (!empty($configured)) {
            return $configured;
        }

        foreach ((array) config('nativephp.python_venv', []) as $candidate) {
            if (is_file($candidate)) {
                Log::info("[ProcessManager] Using bundled Python: {$candidate}");

                return $candidate;
            }
        }

        return config('nativephp.python_path', 'python');
    }

    /**
     * Locate the Python pipeline.
     *
     * The packaged app only ships the Laravel directory, so the sources are
     * copied into resources/python at build time; in the repo they still live
     * next to it as ../src. Try each candidate in order.
     */
    protected function resolvePythonSource(): ?string
    {
        foreach ((array) config('nativephp.python_source', [base_path('../src')]) as $candidate) {
            if (is_file(rtrim($candidate, '/\\') . DIRECTORY_SEPARATOR . 'fastapi_app.py')) {
                return $candidate;
            }
        }

        return null;
    }
}
