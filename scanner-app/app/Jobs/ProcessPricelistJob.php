<?php

namespace App\Jobs;

use App\Models\ApiKey;
use App\Models\Pricelist;
use App\Models\ExtractedPackage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ProcessPricelistJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 120;
    public $tries = 3; 
    public $backoff = [10, 30, 60]; 

    public function __construct(
        public Pricelist $pricelist,
        public array $filePaths
    ) {}

    public function handle(): void
    {
        $jobStartTime = microtime(true);
        $metrics = [];
        
        try {
            // Check cancellation
            if ($this->pricelist->fresh()->status === 'cancelled') return;
            
            $this->pricelist->update(['status' => 'Mengekstrak data dari gambar...']);

            // 1. Get Healthy API Key
            $apiKeyModel = $this->getHealthyApiKey();

            if (!$apiKeyModel) {
                $totalKeys = ApiKey::where('is_active', true)->count();
                if ($totalKeys > 0) {
                    throw new \Exception("Semua {$totalKeys} API Key sedang dalam masa cooldown. Job akan dicoba ulang secara otomatis setelah cooldown selesai.");
                } else {
                    $this->failPermanently("Tidak ada API Key yang dikonfigurasi. Silakan tambahkan API Key di sidebar.");
                    return;
                }
            }

            $apiKeyModel->increment('usage_count');

            // 2. Read Image Files and Call FastAPI
            $extractStart = microtime(true);
            
            try {
                $request = Http::timeout(90);
                $hasValidFile = false;
                
                foreach ($this->filePaths as $path) {
                    $fileContents = Storage::get($path);
                    if ($fileContents) {
                        $request = $request->attach('files', $fileContents, basename($path));
                        $hasValidFile = true;
                    }
                }
                
                if (!$hasValidFile) {
                    $this->failPermanently("Tidak ada file gambar yang valid ditemukan di storage.");
                    return;
                }

                // Check cancellation
                if ($this->pricelist->fresh()->status === 'cancelled') return;

                $latestMsg = $this->pricelist->chatMessages()->whereNotNull('attachments')->latest()->first();
                $prompt = $latestMsg ? $latestMsg->content : null;

                $response = $request->post(env('FASTAPI_URL', 'http://127.0.0.1:8001') . '/api/extract', [
                    'api_key' => $apiKeyModel->key,
                    'model' => 'gemini-3.1-flash-lite',
                    'prompt' => $prompt,
                ]);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw new \Exception("Tidak bisa terhubung ke FastAPI microservice. Pastikan container berjalan. Error: " . $e->getMessage());
            }

            $extractEnd = microtime(true);
            $metrics['extract_time'] = round($extractEnd - $extractStart, 2);
            Log::info("Extraction took {$metrics['extract_time']}s for {$this->pricelist->filename}");

            if ($response->successful()) {
                $data = $response->json('data');

                if (is_array($data) && count($data) > 0) {
                    // Check cancellation
                    if ($this->pricelist->fresh()->status === 'cancelled') return;

                    DB::transaction(function () use ($data) {
                        $this->pricelist->packages()->delete(); 
                        foreach ($data as $pkg) {
                            if (!isset($pkg['price'], $pkg['gb'], $pkg['days'])) continue;

                            ExtractedPackage::create([
                                'pricelist_id' => $this->pricelist->id,
                                'provider' => $pkg['provider'] ?? 'UNKNOWN',
                                'price' => (int) $pkg['price'],
                                'gb' => (float) $pkg['gb'],
                                'days' => (int) $pkg['days'],
                                'yield_val' => $pkg['gb'] > 0 ? ceil($pkg['price'] / $pkg['gb']) : 0,
                                'category' => $this->categorize((int) $pkg['days'], (int) $pkg['price']),
                                'image_timestamp' => $pkg['image_timestamp'] ?? null,
                                'image_location' => $pkg['image_location'] ?? null,
                            ]);
                        }
                    });

                    // Check cancellation
                    if ($this->pricelist->fresh()->status === 'cancelled') return;

                    $this->pricelist->update(['status' => 'Menyusun insight & benchmarking...']);
                    
                    try {
                        $payload = $this->pricelist->packages()->get()->map(function ($p) {
                            return [
                                'provider' => $p->provider,
                                'price' => (int) $p->price,
                                'gb' => (float) $p->gb,
                                'days' => (int) $p->days,
                                'yield_val' => (float) $p->yield_val,
                                'category' => $p->category,
                                'image_timestamp' => $p->image_timestamp,
                                'image_location' => $p->image_location,
                            ];
                        })->toArray();
                        
                        $chatStart = microtime(true);
                        $insightResponse = Http::timeout(60)->post(env('FASTAPI_URL', 'http://127.0.0.1:8001') . '/api/chat', [
                            'message' => 'Buatkan benchmarking antar brand dan insight summaries dari data hasil scan ini.',
                            'packages' => $payload,
                            'api_key' => $apiKeyModel->key
                        ]);
                        $chatEnd = microtime(true);
                        $metrics['chat_time'] = round($chatEnd - $chatStart, 2);

                        if ($insightResponse->successful()) {
                            $chatData = $insightResponse->json('data');
                            $this->pricelist->chatMessages()->create([
                                'role' => 'assistant',
                                'content' => $chatData['text'],
                                'chart_config' => $chatData['chart_config'] ?? null
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::error("Auto-chat failed: " . $e->getMessage());
                    }

                    // Check cancellation
                    if ($this->pricelist->fresh()->status === 'cancelled') return;

                    $metrics['total_time'] = round(microtime(true) - $jobStartTime, 2);
                    
                    $this->pricelist->update([
                        'status' => 'processed',
                        'performance_metrics' => $metrics
                    ]);
                    Storage::delete($this->filePaths);
                    return;
                }

                $this->failPermanently("Gemini tidak bisa mengekstrak data dari gambar ini (respons kosong). Pastikan gambar berisi tabel harga.");
                return;
            }

            // 5. Handle Errors
            $errorMsg = $response->body();
            $statusCode = $response->status();

            if ($statusCode === 429 || $statusCode === 503 || str_contains($errorMsg, '429') || str_contains($errorMsg, 'RESOURCE_EXHAUSTED')) {
                $apiKeyModel->update(['cooldown_until' => now()->addMinutes(1)]);
                throw new \Exception("Rate limit tercapai. Key #{$apiKeyModel->id} masuk cooldown 1 menit.");
            }

            if ($statusCode === 400 || $statusCode === 401 || $statusCode === 403 || str_contains($errorMsg, 'API_KEY_INVALID')) {
                $apiKeyModel->update(['is_active' => false]);
                throw new \Exception("API Key tidak valid dan telah dinonaktifkan. Mencoba key lain...");
            }

            $this->failPermanently("Gagal dari server Python (HTTP {$statusCode}): " . substr($errorMsg, 0, 300));

        } catch (\Exception $e) {
            Log::error("Scanner Job Error: " . $e->getMessage());
            if ($this->attempts() >= $this->tries) {
                $this->failPermanently($e->getMessage());
            } else {
                $this->pricelist->update([
                    'status' => 'pending',
                    'error_message' => "Percobaan {$this->attempts()}/{$this->tries}: " . substr($e->getMessage(), 0, 300)
                ]);
                throw $e;
            }
        }
    }

    private function getHealthyApiKey(): ?ApiKey
    {
        return ApiKey::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('cooldown_until')
                      ->orWhere('cooldown_until', '<', now());
            })
            ->orderBy('usage_count', 'asc')
            ->first();
    }

    private function failPermanently(string $message): void
    {
        $this->pricelist->update([
            'status' => 'failed',
            'error_message' => $message
        ]);
        Storage::delete($this->filePaths);
    }

    private function categorize(int $days, int $price): string
    {
        if ($days <= 7) return 'Harian (Sachet)';
        if ($days <= 15) return 'Mingguan';
        if ($price > 100000) return 'Bulanan (Premium/Jumbo)';
        return 'Bulanan (Standar)';
    }
}
