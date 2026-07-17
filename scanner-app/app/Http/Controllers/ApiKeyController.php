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

        ApiKey::create([
            'key' => $request->key,
            'is_active' => true,
        ]);

        return response()->json(['message' => 'API Key berhasil ditambahkan.']);
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
