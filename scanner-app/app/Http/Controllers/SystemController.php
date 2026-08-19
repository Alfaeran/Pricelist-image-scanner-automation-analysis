<?php

namespace App\Http\Controllers;

use App\Services\ProcessManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Status and control for the background services the desktop app manages.
 */
class SystemController extends Controller
{
    /**
     * Processes the UI is allowed to restart. Queue workers are NativePHP's
     * to manage, so they are deliberately not in this list.
     */
    private const RESTARTABLE = ['fastapi'];

    public function health(): JsonResponse
    {
        return response()->json([
            'version' => config('nativephp.version'),
            'fastapi' => $this->fastApiStatus(),
            'queue' => $this->queueStatus(),
        ]);
    }

    public function restart(Request $request): JsonResponse
    {
        $process = (string) $request->input('process', '');

        if (!in_array($process, self::RESTARTABLE, true)) {
            return response()->json([
                'ok' => false,
                'message' => "Unknown process '{$process}'.",
            ], 422);
        }

        $restarted = app(ProcessManager::class)->restart($process);

        // restart() only knows about processes this PHP process started. Outside
        // the packaged desktop app - php artisan serve, Docker - nothing was
        // started here, so say so rather than reporting a success that did not
        // happen.
        if (!$restarted) {
            return response()->json([
                'ok' => false,
                'message' => "{$process} is not managed by this instance, so it cannot be restarted from here.",
            ], 409);
        }

        return response()->json(['ok' => true, 'message' => "Restarted {$process}."]);
    }

    /**
     * Ask FastAPI directly instead of trusting our own bookkeeping: it is the
     * only answer that stays true when the engine was started outside the app
     * or died without telling us.
     */
    private function fastApiStatus(): array
    {
        $port = (int) config('nativephp.fastapi_port', 8091);
        $base = rtrim((string) env('FASTAPI_URL', "http://127.0.0.1:{$port}"), '/');

        try {
            $response = Http::timeout(2)->get("{$base}/api/health");

            return [
                'running' => $response->successful(),
                'port' => $port,
                'detail' => $response->successful()
                    ? 'Engine responding.'
                    : "Engine returned HTTP {$response->status()}.",
            ];
        } catch (\Throwable $e) {
            return [
                'running' => false,
                'port' => $port,
                'detail' => 'Engine not reachable.',
            ];
        }
    }

    /**
     * NativePHP owns the queue workers, so there is no process of ours to
     * inspect. The pending backlog is the honest signal we do have.
     */
    private function queueStatus(): array
    {
        try {
            $pending = DB::table('jobs')->count();
        } catch (\Throwable $e) {
            return ['pending' => null, 'detail' => 'Queue table unavailable.'];
        }

        return [
            'pending' => $pending,
            'detail' => $pending === 0
                ? 'No jobs waiting.'
                : "{$pending} job(s) waiting.",
        ];
    }
}
