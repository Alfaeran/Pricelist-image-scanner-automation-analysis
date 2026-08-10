<?php

namespace App\Jobs;

use App\Models\ApiKey;
use App\Models\LearnedPattern;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrainWorkerModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $provider;
    protected $mismatchContext;

    /**
     * Create a new job instance.
     */
    public function __construct(string $provider, array $mismatches)
    {
        $this->provider = $provider;
        
        // Convert the array of mismatches to a string context
        $context = "";
        foreach ($mismatches as $mismatch) {
            $context .= "- AI extracted: " . json_encode($mismatch['ai_data']) . "\n";
            $context .= "  Ground Truth (Correct): " . json_encode($mismatch['ground_truth']) . "\n";
        }
        $this->mismatchContext = $context;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // 1. Get an active API key
        $apiKeyRecord = ApiKey::where('is_active', true)->first();
        if (!$apiKeyRecord) {
            Log::warning("TrainWorkerModelJob: No active API key found.");
            return;
        }

        $apiKey = $apiKeyRecord->key;

        // 2. Prepare the prompt for the Teacher Model
        $prompt = "Anda adalah AI Teacher Model yang bertugas mengajarkan AI Worker (Data Extractor) agar tidak mengulangi kesalahan yang sama saat mengekstrak data dari brosur/gambar.\n\n";
        $prompt .= "Provider: {$this->provider}\n";
        $prompt .= "Berikut adalah daftar kesalahan (mismatch) yang dilakukan AI Worker baru-baru ini dibandingkan dengan data Ground Truth yang benar:\n";
        $prompt .= $this->mismatchContext . "\n";
        $prompt .= "Tugas Anda: Buatlah 1 atau 2 kalimat instruksi/aturan TEGAS yang akan ditambahkan ke prompt AI Worker. Aturan ini harus fokus untuk memperbaiki kesalahan di atas. Jangan beri salam atau teks tambahan, HANYA kembalikan kalimat aturannya saja. Contoh: 'Untuk provider X, harga 45K harus dibaca sebagai 10GB, BUKAN 280GB.'";

        // 3. Call Gemini REST API
        try {
            $response = Http::timeout(30)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.0
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $ruleText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

                if (!empty($ruleText)) {
                    // 4. Save to Database
                    LearnedPattern::create([
                        'provider' => $this->provider,
                        'mismatch_context' => $this->mismatchContext,
                        'rule_text' => trim($ruleText),
                        'is_active' => true
                    ]);
                    
                    Log::info("TrainWorkerModelJob: Successfully learned pattern for {$this->provider}. Rule: {$ruleText}");
                }
            } else {
                Log::error("TrainWorkerModelJob: API call failed.", $response->json());
            }
        } catch (\Exception $e) {
            Log::error("TrainWorkerModelJob Exception: " . $e->getMessage());
        }
    }
}
