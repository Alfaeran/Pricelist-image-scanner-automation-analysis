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
            return response()->json(['error' => 'Data belum diproses.'], 400);
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

        // Get an active API key
        $apiKey = ApiKey::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now());
            })->first();

        if (!$apiKey) {
            return response()->json(['error' => 'Tidak ada API Key yang aktif. Tambahkan di sidebar.'], 503);
        }

        // Forward to FastAPI
        try {
            $response = Http::timeout(60)->post(env('FASTAPI_URL', 'http://127.0.0.1:8001') . '/api/chat', [
                'message' => $request->message,
                'packages' => $payload,
                'api_key' => $apiKey->key
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
}
