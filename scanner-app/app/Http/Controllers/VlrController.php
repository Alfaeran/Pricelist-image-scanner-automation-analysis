<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VlrController extends Controller
{
    public function index()
    {
        return Inertia::render('Scanner/VlrChecker');
    }

    public function check(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'provider' => 'required|string'
        ]);

        try {
            $response = Http::timeout(10)->post(env('FASTAPI_URL', 'http://127.0.0.1:8081') . '/api/check-sim-age', [
                'provider' => $request->provider,
                'phone_number' => $request->phone_number
            ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses pengecekan. Response: ' . $response->body()
            ], 500);

        } catch (\Exception $e) {
            Log::error('VLR check connection error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak dapat terhubung ke FastAPI server. ' . $e->getMessage()
            ], 500);
        }
    }
}
