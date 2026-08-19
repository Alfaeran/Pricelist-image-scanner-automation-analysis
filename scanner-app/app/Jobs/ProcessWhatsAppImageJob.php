<?php

namespace App\Jobs;

use App\Models\ApiKey;
use App\Models\ExtractedPackage;
use App\Models\Pricelist;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessageLog;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Process an image received via WhatsApp.
 *
 * Flow:
 * 1. Download media from WhatsApp (if not already downloaded)
 * 2. Create Pricelist record
 * 3. Send image to FastAPI for Gemini extraction
 * 4. Store results
 * 5. Send results summary back to user via WhatsApp
 */
class ProcessWhatsAppImageJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600;
    public $tries = 2;
    public $backoff = [15, 60];

    public function __construct(
        public int $conversationId,
        public string $mediaPath,
        public ?string $caption = null,
        public bool $isAppend = false,
    ) {}

    public function handle(): void
    {
        $wa = app(WhatsAppServiceInterface::class);
        $conversation = WhatsAppConversation::find($this->conversationId);

        if (!$conversation) {
            Log::warning("ProcessWhatsAppImageJob: conversation {$this->conversationId} not found");
            return;
        }

        $phone = $conversation->phone_number;

        try {
            // 1. Get or Create a Pricelist record
            $filename = basename($this->mediaPath);

            if ($this->isAppend && $conversation->pricelist_id) {
                $pricelist = Pricelist::find($conversation->pricelist_id);
            }

            if (!isset($pricelist) || !$pricelist) {
                $sessionName = "WA: " . now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');
                $pricelist = Pricelist::create([
                    'filename' => $sessionName,
                    'status' => 'processing',
                ]);
                $conversation->update(['pricelist_id' => $pricelist->id]);
            } else {
                $pricelist->update(['status' => 'processing']);
            }

            // 2. Get active API keys
            $activeKeys = ApiKey::where('is_active', true)->get();
            if ($activeKeys->isEmpty()) {
                $wa->sendTextMessage($phone, "❌ Tidak ada API Key yang aktif. Silakan konfigurasi API Key di dashboard terlebih dahulu.");
                $pricelist->update(['status' => 'failed', 'error_message' => 'No active API keys']);
                return;
            }

            $apiKeysString = implode(',', $activeKeys->pluck('key')->toArray());

            // Collect model priorities
            $supportedModelsPool = [];
            foreach ($activeKeys as $keyModel) {
                if (is_array($keyModel->supported_models)) {
                    $supportedModelsPool = array_merge($supportedModelsPool, $keyModel->supported_models);
                }
            }
            $supportedModelsPool = array_unique($supportedModelsPool);

            $priority = [
                'gemini-3.1-flash-lite',
                'gemini-3.5-flash',
                'gemini-2.0-flash',
                'gemini-1.5-flash',
                'gemini-1.5-flash-8b',
                'gemini-1.5-pro',
            ];

            $finalModels = [];
            foreach ($priority as $m) {
                if (in_array($m, $supportedModelsPool)) {
                    $finalModels[] = $m;
                }
            }
            if (empty($finalModels)) {
                $finalModels = !empty($supportedModelsPool) ? $supportedModelsPool : $priority;
            }
            $modelsString = implode(',', $finalModels);

            // 3. Send to FastAPI for extraction
            $fullPath = Storage::disk('public')->path($this->mediaPath);

            if (!file_exists($fullPath)) {
                throw new \RuntimeException("Media file not found at: {$fullPath}");
            }

            $response = Http::timeout(300)
                ->attach('files', fopen($fullPath, 'r'), $filename)
                ->post(env('FASTAPI_URL', 'http://127.0.0.1:8091') . '/api/extract', [
                    'api_keys' => $apiKeysString,
                    'model' => $modelsString,
                    'prompt' => $this->caption,
                    'pricelist_id' => $pricelist->id,
                ]);

            if (!$response->successful()) {
                $errorDetail = $response->json('detail') ?? $response->body();
                throw new \RuntimeException("FastAPI error ({$response->status()}): {$errorDetail}");
            }

            $data = $response->json('data');

            if (!is_array($data) || empty($data)) {
                $wa->sendTextMessage($phone, "⚠️ Tidak dapat mengekstrak data dari gambar tersebut. Pastikan gambar berisi tabel harga/pricelist yang jelas.");
                $pricelist->update(['status' => 'failed', 'error_message' => 'Empty extraction result']);
                return;
            }

            // 4. Store extracted packages
            DB::transaction(function () use ($data, $pricelist) {
                foreach ($data as $pkg) {
                    $price = (int) round((float) ($pkg['price'] ?? 0));
                    $gb = (float) ($pkg['gb'] ?? 0);
                    $days = (int) round((float) ($pkg['days'] ?? 0));

                    $isRejected = ($price <= 0 || $gb <= 0 || $days <= 0);
                    $rawYield = $pkg['yield_val'] ?? ($gb > 0 ? $price / $gb : 0);
                    $yield_val = (int) round((float) $rawYield);
                    $isAnomaly = ($price <= 0 || $gb <= 0 || $yield_val > 50000);

                    $category = $isRejected ? 'REJECTED' : $this->categorize($days, $price);

                    ExtractedPackage::create([
                        'pricelist_id' => $pricelist->id,
                        'provider' => $pkg['provider'] ?? 'UNKNOWN',
                        'package_name' => $pkg['package_name'] ?? null,
                        'price' => $price,
                        'gb' => $gb,
                        'days' => $days,
                        'yield_val' => $yield_val,
                        'is_anomaly' => $isAnomaly,
                        'category' => $category,
                        'product_type' => $pkg['product_type'] ?? null,
                        'image_timestamp' => $pkg['image_timestamp'] ?? null,
                        'image_location' => $pkg['image_location'] ?? null,
                        'image_filename' => $pkg['image_filename'] ?? null,
                    ]);
                }
            });

            $pricelist->update(['status' => 'processed']);

            // 5. Build and send results summary via WhatsApp
            $packages = $pricelist->packages()->where('category', '!=', 'REJECTED')->get();
            $summary = $this->buildResultsSummary($packages);

            $wa->sendTextMessage($phone, $summary);

            // Log outgoing message
            WhatsAppMessageLog::logOutgoing($this->conversationId, $summary);

        } catch (\Exception $e) {
            Log::error("ProcessWhatsAppImageJob failed: " . $e->getMessage());

            $wa->sendTextMessage(
                $phone,
                "❌ Terjadi kesalahan saat memproses gambar: " . substr($e->getMessage(), 0, 200)
            );

            WhatsAppMessageLog::logOutgoing(
                $this->conversationId,
                "❌ Error: " . substr($e->getMessage(), 0, 200),
                'failed'
            );

            if (isset($pricelist)) {
                $pricelist->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Build a human-readable summary of extraction results for WhatsApp.
     */
    private function buildResultsSummary($packages): string
    {
        if ($packages->isEmpty()) {
            return "⚠️ Tidak ada paket yang berhasil diekstrak dari gambar.";
        }

        $totalPackages = $packages->count();
        $providers = $packages->groupBy('provider');

        $lines = [
            "📊 *Hasil Scan Pricelist*",
            "━━━━━━━━━━━━━━━━━━",
            "📦 Total: {$totalPackages} paket",
            "",
        ];

        foreach ($providers as $provider => $pkgs) {
            $count = $pkgs->count();
            $minPrice = number_format($pkgs->min('price'), 0, ',', '.');
            $maxPrice = number_format($pkgs->max('price'), 0, ',', '.');
            $avgYield = round($pkgs->avg('yield_val'), 2);

            $lines[] = "🏢 *{$provider}* ({$count} paket)";
            $lines[] = "   💰 Harga: Rp {$minPrice} - Rp {$maxPrice}";
            $lines[] = "   📈 Avg Yield: {$avgYield}";
            $lines[] = "";
        }

        // Find cheapest per GB
        $cheapest = $packages->sortBy('yield_val')->first();
        if ($cheapest) {
            $cheapestPrice = number_format($cheapest->price, 0, ',', '.');
            $lines[] = "🏆 *Paling Worth It:*";
            $lines[] = "   {$cheapest->provider} - {$cheapest->package_name}";
            $lines[] = "   {$cheapest->gb}GB / {$cheapest->days} hari = Rp {$cheapestPrice}";
            $lines[] = "   (Yield: {$cheapest->yield_val})";
        }

        $lines[] = "";
        $lines[] = "💬 Kirim pertanyaan untuk analisis lebih lanjut!";
        $lines[] = "📝 Ketik *help* untuk bantuan.";

        return implode("\n", $lines);
    }

    /**
     * Categorize package by duration and price.
     */
    private function categorize(int $days, int $price): string
    {
        if ($days <= 7) return 'Harian (Sachet)';
        if ($days <= 15) return 'Mingguan';
        if ($price > 100000) return 'Bulanan (Premium/Jumbo)';
        return 'Bulanan (Standar)';
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessWhatsAppImageJob permanently failed: " . $exception->getMessage());

        try {
            $wa = app(WhatsAppServiceInterface::class);
            $conversation = WhatsAppConversation::find($this->conversationId);
            if ($conversation) {
                $wa->sendTextMessage(
                    $conversation->phone_number,
                    "❌ Gagal memproses gambar setelah beberapa percobaan. Silakan coba kirim ulang."
                );
            }
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp failure notification: " . $e->getMessage());
        }
    }
}
