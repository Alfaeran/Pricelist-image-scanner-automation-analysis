<?php

namespace App\Http\Controllers;

use App\Models\Pricelist;
use App\Models\ChatMessage;
use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function store(Request $request, Pricelist $pricelist)
    {
        $request->validate([
            'message' => 'required|string|max:1000'
        ]);

        if ($pricelist->status !== 'processed') {
            // Simpan pesan user ke database
            $userMsg = ChatMessage::create([
                'pricelist_id' => $pricelist->id,
                'role' => 'user',
                'content' => $request->message
            ]);

            // Kembalikan respon bahwa pesan masuk antrean
            return response()->json([
                'user_message' => $userMsg,
                'assistant_message' => [
                    'id' => time(),
                    'role' => 'assistant',
                    'content' => '⏳ *Permintaan masuk ke antrean. Saya akan membalas pesan ini setelah proses ekstraksi gambar selesai.*'
                ]
            ]);
        }

        // Save User Message
        $userMsg = ChatMessage::create([
            'pricelist_id' => $pricelist->id,
            'role' => 'user',
            'content' => $request->message
        ]);

        // Get context (extracted packages)
        $packages = $pricelist->packages;
        $payload = $packages->map(function ($pkg) {
            return [
                'provider' => $pkg->provider,
                'price' => (int) $pkg->price,
                'gb' => (float) $pkg->gb,
                'days' => (int) $pkg->days,
                'yield_val' => (float) $pkg->yield_val,
                'category' => $pkg->category,
            ];
        })->toArray();

        // Get all active API keys
        $activeKeysData = ApiKey::where('is_active', true)->get();
        if ($activeKeysData->isEmpty()) {
            return response()->json(['message' => 'Tidak ada API Key yang aktif. Silakan tambahkan di menu samping.'], 500);
        }
        $apiKeysString = implode(',', $activeKeysData->pluck('key')->toArray());

        // Increment usage count on the first key for telemetry
        $firstKey = $activeKeysData->first();
        if ($firstKey) $firstKey->increment('usage_count');

        // Dynamic model selection based on supported_models
        $supportedModelsPool = [];
        foreach ($activeKeysData as $keyModel) {
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
            'gemini-1.5-pro'
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

        // Context payload is already prepared above

        // Forward to FastAPI
        try {
            $response = Http::timeout(60)->post(env('FASTAPI_URL', 'http://127.0.0.1:8001') . '/api/chat', [
                'message' => $request->message,
                'packages' => $payload,
                'api_keys' => $apiKeysString,
                'model' => $modelsString
            ]);

            if ($response->successful()) {
                $data = $response->json('data');
                
                // Save AI response
                $aiMsg = ChatMessage::create([
                    'pricelist_id' => $pricelist->id,
                    'role' => 'assistant',
                    'content' => $data['text'],
                    'chart_config' => $data['chart_config'] ?? null
                ]);

                return response()->json([
                    'user_message' => $userMsg,
                    'assistant_message' => $aiMsg
                ]);
            }

            return response()->json(['error' => 'Gagal mendapatkan respon dari AI.'], 500);

        } catch (\Exception $e) {
            Log::error("Chat API Error: " . $e->getMessage());
            return response()->json(['error' => 'Terjadi kesalahan koneksi ke AI server.'], 500);
        }
    }

    public function destroyChart(Pricelist $pricelist, ChatMessage $chatMessage)
    {
        if ($chatMessage->pricelist_id !== $pricelist->id) {
            abort(403, 'Unauthorized action.');
        }

        $chatMessage->update(['chart_config' => null]);
        return response()->json(['message' => 'Chart berhasil dihapus']);
    }
}
