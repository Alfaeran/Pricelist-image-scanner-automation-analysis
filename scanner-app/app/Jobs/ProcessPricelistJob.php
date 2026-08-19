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

    public $timeout = 600;
    public $tries = 3;
    public $backoff = [10, 30, 60];

    public function __construct(
        public int $pricelistId,
        public array $filePaths,
        public bool $isAppend = false,
        public ?string $manualTimestamp = null
    ) {
    }

    public function handle(): void
    {
        $jobStartTime = microtime(true);
        $metrics = [];
        $pricelist = Pricelist::find($this->pricelistId);

        if (!$pricelist) {
            Log::warning("ProcessPricelistJob skipped: pricelist {$this->pricelistId} not found.");
            return;
        }

        try {
            // Check cancellation
            if ($pricelist->fresh()->status === 'cancelled')
                return;

            $pricelist->update(['status' => 'Mengekstrak data dari gambar...']);

            // 1. Get Healthy API Keys
            $activeKeysData = ApiKey::where('is_active', true)->get();
            if ($activeKeysData->isEmpty()) {
                $this->failPermanently("Tidak ada API Key yang aktif. Silakan tambahkan API Key di sidebar.");
                return;
            }
            $apiKeysString = implode(',', $activeKeysData->pluck('key')->toArray());

            // Collect all supported models from active keys
            $supportedModelsPool = [];
            foreach ($activeKeysData as $keyModel) {
                if (is_array($keyModel->supported_models)) {
                    $supportedModelsPool = array_merge($supportedModelsPool, $keyModel->supported_models);
                }
            }
            $supportedModelsPool = array_unique($supportedModelsPool);

            // Our preferred fallback priority
            $priority = [
                'gemini-3.1-flash-lite',
                'gemini-3.5-flash',
                'gemini-2.0-flash',
                'gemini-1.5-flash',
                'gemini-1.5-flash-8b',
                'gemini-1.5-pro'
            ];

            // Filter and sort the models based on our priority
            $finalModels = [];
            foreach ($priority as $m) {
                if (in_array($m, $supportedModelsPool)) {
                    $finalModels[] = $m;
                }
            }

            // Fallback if somehow no standard models are supported
            if (empty($finalModels)) {
                $finalModels = !empty($supportedModelsPool) ? $supportedModelsPool : $priority;
            }
            $modelsString = implode(',', $finalModels);

            // Increment usage count for the first key for telemetry purposes
            $firstKeyModel = ApiKey::where('key', $activeKeysData->first()->key)->first();
            if ($firstKeyModel)
                $firstKeyModel->increment('usage_count');

            // 2. Read Image Files and Call FastAPI
            $extractStart = microtime(true);

            try {
                $request = Http::timeout(300);
                $hasValidImage = false;
                $hasValidData = false;

                foreach ($this->filePaths as $path) {
                    $fullPath = Storage::disk('public')->path($path);
                    if (file_exists($fullPath)) {
                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        if (in_array($ext, ['csv', 'txt', 'xlsx', 'xls'])) {
                            $this->processDataFile($fullPath, $pricelist);
                            $hasValidData = true;
                        } else {
                            $stream = fopen($fullPath, 'r');
                            $request = $request->attach('files', $stream, basename($path));
                            $hasValidImage = true;
                        }
                    }
                }

                if (!$hasValidImage && !$hasValidData) {
                    $this->failPermanently("Tidak ada file gambar atau data yang valid ditemukan di storage.");
                    return;
                }
                // Check cancellation
                if ($pricelist->fresh()->status === 'cancelled')
                    return;

                if ($hasValidImage) {
                    $latestMsg = $pricelist->chatMessages()->whereNotNull('attachments')->latest()->first();
                    $prompt = $latestMsg ? $latestMsg->content : null;

                    $response = $request->post(env('FASTAPI_URL', 'http://127.0.0.1:8001') . '/api/extract', [
                        'api_keys' => $apiKeysString,
                        'model' => $modelsString,
                        'prompt' => $prompt,
                        'pricelist_id' => $pricelist->id,
                        'webhook_url' => route('scanner.status.update', $pricelist->id)
                    ]);
                }
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw new \Exception("Tidak bisa terhubung ke FastAPI microservice. Pastikan container berjalan. Error: " . $e->getMessage());
            }

            $extractEnd = microtime(true);
            $metrics['extract_time'] = round($extractEnd - $extractStart, 2);
            Log::info("Extraction took {$metrics['extract_time']}s for {$pricelist->filename}");

            if ($hasValidImage) {
                if ($response->successful()) {
                    $data = $response->json('data');

                    if (is_array($data) && count($data) > 0) {
                        // Check cancellation
                        if ($pricelist->fresh()->status === 'cancelled')
                            return;

                        DB::transaction(function () use ($data, $pricelist) {
                            if (!$this->isAppend) {
                                $pricelist->packages()->delete();
                            }
                            foreach ($data as $pkg) {
                                $price = (int) round((float) ($pkg['price'] ?? 0));
                                $gb = (float) ($pkg['gb'] ?? 0);
                                $days = (int) round((float) ($pkg['days'] ?? 0));

                                // Skip packages with zero/negative values (OCR errors)
                                $isRejected = ($price <= 0 || $gb <= 0 || $days <= 0);
                                $rawYield = 0;
                                if (isset($pkg['yield_val'])) {
                                    $rawYield = $pkg['yield_val'];
                                } else {
                                    $rawYield = app(\App\Http\Controllers\ScannerController::class)->calculateYield($pkg['package_name'] ?? '', $gb, $days, $price);
                                }
                                $yield_val = (int) round((float) $rawYield);
                                
                                $isAnomaly = ($price <= 0 || $gb <= 0 || $yield_val > 50000);

                                ExtractedPackage::create([
                                    'pricelist_id' => $pricelist->id,
                                    'provider' => $pkg['provider'] ?? 'UNKNOWN',
                                    'package_name' => $pkg['package_name'] ?? null,
                                    'price' => $price,
                                    'gb' => $gb,
                                    'days' => $days,
                                    'yield_val' => $yield_val,
                                    'is_anomaly' => $isAnomaly,
                                    'category' => $isRejected ? 'REJECTED' : $this->categorize((int) $pkg['days'], (int) $pkg['price']),
                                    'product_type' => $pkg['product_type'] ?? null,
                                    'image_timestamp' => $this->manualTimestamp ?? ($pkg['image_timestamp'] ?? null),
                                    'image_location' => $pkg['image_location'] ?? null,
                                    'image_filename' => $pkg['image_filename'] ?? null,
                                ]);
                            }
                        });

                        // Check cancellation
                        if ($pricelist->fresh()->status === 'cancelled')
                            return;

                        $pricelist->update(['status' => 'Menyusun insight & benchmarking...']);

                        try {
                            $payload = $pricelist->packages()->get()->map(function ($p) {
                                return [
                                    'provider' => $p->provider,
                                    'package_name' => $p->package_name,
                                    'price' => (int) $p->price,
                                    'gb' => (float) $p->gb,
                                    'days' => (int) $p->days,
                                    'yield_val' => (float) $p->yield_val,
                                    'category' => $p->category,
                                    'image_timestamp' => $p->image_timestamp,
                                    'image_location' => $p->image_location,
                                    'image_filename' => $p->image_filename,
                                ];
                            })->toArray();

                            $chatStart = microtime(true);
                            $insightResponse = Http::timeout(60)->post(env('FASTAPI_URL', 'http://127.0.0.1:8001') . '/api/chat', [
                                'message' => 'Buatkan benchmarking antar brand dan insight summaries dari data hasil scan ini.',
                                'packages' => $payload,
                                'api_keys' => $apiKeysString,
                                'model' => $modelsString
                            ]);
                            $chatEnd = microtime(true);
                            $metrics['chat_time'] = round($chatEnd - $chatStart, 2);

                            if ($insightResponse->successful()) {
                                $chatData = $insightResponse->json('data');
                                $pricelist->chatMessages()->create([
                                    'role' => 'assistant',
                                    'content' => $chatData['text'],
                                    'chart_config' => $chatData['chart_config'] ?? null
                                ]);
                            }
                        } catch (\Throwable $e) {
                            Log::error("Auto-chat failed: " . $e->getMessage());
                        }

                        // Check cancellation
                        if ($pricelist->fresh()->status === 'cancelled')
                            return;

                        $metrics['total_time'] = round(microtime(true) - $jobStartTime, 2);

                        $pricelist->update([
                            'status' => 'processed',
                            'performance_metrics' => $metrics
                        ]);
                        // Storage::delete($this->filePaths); // Keep files for viewing in UI
                        return;
                    }

                    $pricelist->update(['status' => 'error']);
                    $this->failPermanently("Gemini tidak bisa mengekstrak data dari gambar ini (respons kosong). Pastikan gambar berisi tabel harga.");
                    return;
                }

                // 5. Handle Errors
                $errorMsg = $response->body();
                $statusCode = $response->status();

                $pricelist->update(['status' => 'error']);

                $parsedError = json_decode($errorMsg, true);
                $detail = $parsedError['detail'] ?? $parsedError['message'] ?? $errorMsg;
                if (is_array($detail)) {
                    $detail = json_encode($detail);
                }

                $userFriendlyMessage = "Gagal memproses gambar. ";
                if ($statusCode === 422) {
                    $userFriendlyMessage .= "Konfigurasi API tidak valid atau ada parameter yang kurang.";
                } elseif ($statusCode === 500 || $statusCode === 503) {
                    if (str_contains(strtolower($detail), 'habis')) {
                        $userFriendlyMessage = "Seluruh kuota harian dari API Key Anda telah habis. Silakan tunggu beberapa saat atau tambahkan API Key baru di pengaturan.";
                    } elseif (str_contains(strtolower($detail), 'format tidak didukung')) {
                        $userFriendlyMessage = "Format gambar tidak didukung atau rusak.";
                    } else {
                        $userFriendlyMessage .= "Server AI sedang sibuk atau mengalami kendala internal.";
                    }
                } else {
                    $userFriendlyMessage .= "Kode HTTP {$statusCode}.";
                }

                Log::error("FastAPI Error ($statusCode): $errorMsg");
                $this->failPermanently($userFriendlyMessage);
                return;
            } else {
                // $hasValidImage is false, but we have valid data.
                $metrics['total_time'] = round(microtime(true) - $jobStartTime, 2);
                $pricelist->update([
                    'status' => 'processed',
                    'performance_metrics' => $metrics
                ]);
                return;
            }
        } catch (\Exception $e) {
            Log::error("Scanner Job Error: " . $e->getMessage());
            if ($this->attempts() >= $this->tries) {
                $this->failPermanently($e->getMessage());
            } else {
                $pricelist->update([
                    'status' => 'pending',
                    'error_message' => "Percobaan {$this->attempts()}/{$this->tries}: " . substr($e->getMessage(), 0, 300)
                ]);
                throw $e;
            }
        }
    }


    private function failPermanently(string $message): void
    {
        $pricelist = Pricelist::find($this->pricelistId);

        if (!$pricelist) {
            Log::warning("ProcessPricelistJob could not mark failed: pricelist {$this->pricelistId} not found.");
            return;
        }

        $pricelist->update([
            'status' => 'failed',
            'error_message' => $message
        ]);
        // Storage::delete($this->filePaths); // Keep files for viewing in UI
    }

    private function categorize(int $days, int $price): string
    {
        if ($days <= 7)
            return 'Harian (Sachet)';
        if ($days <= 15)
            return 'Mingguan';
        if ($price > 100000)
            return 'Bulanan (Premium/Jumbo)';
        return 'Bulanan (Standar)';
    }

    private function processDataFile(string $fullPath, Pricelist $pricelist): void
    {
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $csvData = [];
        
        if ($ext === 'xlsx' || $ext === 'xls') {
            if ($xlsx = \Shuchkin\SimpleXLSX::parse($fullPath)) {
                foreach ($xlsx->rows() as $row) {
                    $csvData[] = $row;
                }
            }
        } else {
            if (($handle = fopen($fullPath, "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $csvData[] = $data;
                }
                fclose($handle);
            }
        }

        foreach ($csvData as $i => $row) {
            if ($i < 4) continue; // Skip headers
            if (count($row) < 6) continue;
            
            $provider = strtoupper(trim($row[1]));
            if (!$provider) continue;
            
            if ($provider == 'SF') $provider = 'SMARTFREN';
            elseif ($provider == 'TSEL') $provider = 'TELKOMSEL';
            elseif ($provider == '3ID') $provider = '3';
            elseif ($provider == 'BYU') $provider = 'BY.U';
            
            $price = (int) preg_replace('/[^\d]/', '', $row[3] ?? '');
            $gbStr = str_replace(',', '.', $row[4] ?? '');
            $gb = (float) preg_replace('/[^\d\.]/', '', $gbStr);
            $days = (int) preg_replace('/[^\d]/', '', $row[5] ?? '');
            
            \App\Models\ExtractedPackage::create([
                'pricelist_id' => $pricelist->id,
                'provider' => $provider,
                'package_name' => "Paket " . $provider,
                'price' => $price,
                'gb' => $gb,
                'days' => $days,
                'yield_val' => app(\App\Http\Controllers\ScannerController::class)->calculateYield("Paket " . $provider, $gb, $days, $price),
                'is_anomaly' => ($price <= 0 || $gb <= 0 || (app(\App\Http\Controllers\ScannerController::class)->calculateYield("Paket " . $provider, $gb, $days, $price)) > 50000),
                'category' => $this->categorize($days, $price),
                'product_type' => 'Data',
                'image_timestamp' => $this->manualTimestamp,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Job gagal permanen: " . $exception->getMessage());
        $pricelist = Pricelist::find($this->pricelistId);

        if (!$pricelist) {
            Log::warning("ProcessPricelistJob could not finalize failure: pricelist {$this->pricelistId} not found.");
            return;
        }

        $pricelist->update([
            'status' => 'failed',
            'error_message' => 'Proses gagal (kemungkinan karena melampaui batas waktu/timeout). Silakan ulangi.'
        ]);
    }
}
