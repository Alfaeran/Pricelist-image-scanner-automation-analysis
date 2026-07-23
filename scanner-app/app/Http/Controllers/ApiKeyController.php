<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function index()
    {
        $keys = ApiKey::all()->map(function ($key) {
            return [
                'id' => $key->id,
                'key_masked' => substr($key->key, 0, 8) . '...' . substr($key->key, -4),
                'is_active' => $key->is_active,
                'cooldown_until' => $key->cooldown_until,
                'usage_count' => $key->usage_count,
                'supported_models' => $key->supported_models,
                'created_at' => $key->created_at,
            ];
        });

        return response()->json($keys);
    }

    public function store(Request $request)
    {
        $request->validate([
            'key' => 'required|string|unique:api_keys,key',
        ]);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)->post(env('FASTAPI_URL', 'http://127.0.0.1:8001') . '/api/keys/check', [
                'api_key' => $request->key
            ]);

            $result = $response->json();
            if (!$response->successful() || ($result['status'] ?? '') !== 'success') {
                return response()->json(['message' => 'API Key tidak valid atau Google Gemini API error: ' . ($result['message'] ?? 'Unknown error')], 400);
            }

            ApiKey::create([
                'key' => $request->key,
                'is_active' => true,
                'supported_models' => $result['supported_models'] ?? []
            ]);

            return response()->json(['message' => 'API Key berhasil ditambahkan. Mode didukung: ' . count($result['supported_models'] ?? [])]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal terhubung ke layanan FastAPI untuk memvalidasi API Key.'], 500);
        }
    }

    public function destroy(ApiKey $apiKey)
    {
        $apiKey->delete();
        return response()->json(['message' => 'API Key berhasil dihapus.']);
    }

    public function toggle(ApiKey $apiKey)
    {
        $apiKey->update(['is_active' => !$apiKey->is_active]);
        return response()->json(['message' => 'Status API Key diperbarui.']);
    }
}
