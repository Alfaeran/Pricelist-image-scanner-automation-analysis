<?php

namespace App\Http\Controllers;

use App\Models\Pricelist;
use App\Models\ExtractedPackage;
use App\Jobs\ProcessPricelistJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ScannerController extends Controller
{
    public function index()
    {
        return Inertia::render('Scanner/Index', [
            'pricelists' => Pricelist::with(['packages', 'chatMessages'])->latest()->take(20)->get()
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'pricelist_id' => 'nullable|exists:pricelists,id',
            'message' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'file|max:102400|mimes:jpeg,png,jpg,webp,pdf,zip',
        ]);

        if (!$request->hasFile('images') && !$request->filled('message')) {
            return back()->withErrors(['error' => 'Pesan atau file harus diisi.']);
        }

        if (!\App\Models\ApiKey::where('is_active', true)->exists()) {
            return back()->withErrors(['error' => 'API Key Gemini belum diatur atau tidak valid. Silakan masukkan API Key terlebih dahulu di sebelah kiri.']);
        }

        $files = $request->file('images') ?? [];
        $paths = [];
        $originalNames = [];

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            if (Pricelist::where('filename', 'LIKE', '%' . $name . '%')->exists()) {
                return back()->withErrors(['error' => "File '{$name}' sudah pernah diunggah (Terdeteksi duplikat). Harap ubah nama file jika ini adalah dataset baru."]);
            }
            $originalNames[] = $name;
            $paths[] = $file->storeAs('pricelists', uniqid() . '_' . $name, 'public');
        }

        // 1. Get or Create Pricelist (Session)
        if ($request->filled('pricelist_id')) {
            $pricelist = Pricelist::find($request->pricelist_id);
            if (count($paths) > 0) {
                $pricelist->update(['status' => 'pending']);
            }
        } else {
            $title = count($originalNames) > 0 ? $originalNames[0] . (count($originalNames) > 1 ? ' + ' . (count($originalNames)-1) . ' lainnya' : '') : 'Chat Session';
            $pricelist = Pricelist::create([
                'filename' => $title,
                'status' => count($paths) > 0 ? 'pending' : 'processed'
            ]);
        }

        // 2. Save the initial user message if exists or if there are files
        $messageContent = $request->input('message') ?: (count($paths) > 0 ? 'Tolong scan gambar ini.' : '');
        $pricelist->chatMessages()->create([
            'role' => 'user',
            'content' => $messageContent,
            'attachments' => count($paths) > 0 ? $paths : null,
        ]);

        // 3. Dispatch Background Job if there are images
        if (count($paths) > 0) {
            $isAppend = $request->boolean('is_append', false);
            ProcessPricelistJob::dispatch($pricelist->id, $paths, $isAppend);
        } else {
            // If just text, we redirect back. The frontend will hit ChatController separately or we can just let it be.
            // Actually, if it's just text, the frontend should just use ChatController directly to get the AI response synchronously.
        }

        return redirect()->back();
    }

    public function destroy(Pricelist $pricelist)
    {
        foreach ($pricelist->chatMessages as $msg) {
            if ($msg->attachments) {
                foreach ($msg->attachments as $path) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
                }
            }
        }
        
        $pricelist->chatMessages()->delete();
        $pricelist->packages()->delete();
        $pricelist->delete();

        return redirect()->back()->with('success', 'Sesi berhasil dihapus.');
    }

    public function export(Pricelist $pricelist)
    {
        // Guard: ensure pricelist has been processed
        if ($pricelist->status !== 'processed') {
            return back()->withErrors(['error' => 'Data belum selesai diproses. Status saat ini: ' . $pricelist->status]);
        }

        $packages = $pricelist->packages;

        if (!$packages || $packages->isEmpty()) {
            return back()->withErrors(['error' => 'Tidak ada data paket untuk diekspor.']);
        }

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

        try {
            $response = Http::timeout(60)->post(env('FASTAPI_URL', 'http://127.0.0.1:8001') . '/api/export', [
                'packages' => $payload
            ]);

            if ($response->successful()) {
                return response($response->body(), 200, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="Rekap_Harga_' . $pricelist->id . '.xlsx"'
                ]);
            }

            Log::error('Excel export failed: ' . $response->body());
            return back()->withErrors(['error' => 'Gagal membuat file Excel. Server Python tidak merespons dengan benar.']);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Excel export connection error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Tidak bisa terhubung ke server Python (FastAPI). Pastikan container fastapi berjalan.']);
        } catch (\Exception $e) {
            Log::error('Excel export error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Terjadi kesalahan: ' . $e->getMessage()]);
        }
    }

    public function exportCsv(Pricelist $pricelist)
    {
        if ($pricelist->status !== 'processed') {
            return back()->withErrors(['error' => 'Data belum selesai diproses. Status saat ini: ' . $pricelist->status]);
        }

        $packages = $pricelist->packages;

        if (!$packages || $packages->isEmpty()) {
            return back()->withErrors(['error' => 'Tidak ada data paket untuk diekspor.']);
        }

        $filename = "Rekap_Harga_" . $pricelist->id . ".csv";

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['Provider', 'Package Name', 'Price', 'GB', 'Days', 'Price/GB', 'Category', 'Product Type'];

        $callback = function() use($packages, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($packages as $pkg) {
                $price_per_gb = $pkg->gb > 0 ? ceil($pkg->price / $pkg->gb) : 0;
                $row = [
                    $pkg->provider,
                    $pkg->package_name ?? '',
                    $pkg->price,
                    $pkg->gb,
                    $pkg->days,
                    $price_per_gb,
                    $pkg->category,
                    $pkg->product_type ?? ''
                ];
                fputcsv($file, $row);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function insights(Pricelist $pricelist)
    {
        if ($pricelist->status !== 'processed') {
            return response()->json(['error' => 'Data belum selesai diproses.'], 400);
        }

        $packages = $pricelist->packages;

        if (!$packages || $packages->isEmpty()) {
            return response()->json(['error' => 'Tidak ada data paket untuk dianalisis.'], 400);
        }

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

        try {
            $response = Http::timeout(60)->post(env('FASTAPI_URL', 'http://127.0.0.1:8001') . '/api/insights', [
                'packages' => $payload
            ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json(['error' => 'Gagal mengambil insight dari server Python.'], 500);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
        } catch (\Illuminate\Client\ConnectionException $e) {
            return response()->json(['error' => 'Tidak bisa terhubung ke server Python (FastAPI).'], 503);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }

    public function retry(Pricelist $pricelist)
    {
        if ($pricelist->status !== 'failed') {
            return back()->withErrors(['error' => 'Hanya scan yang gagal yang bisa diulang.']);
        }

        $firstMessage = $pricelist->chatMessages()->whereNotNull('attachments')->first();
        
        if (!$firstMessage || empty($firstMessage->attachments)) {
            return back()->withErrors(['error' => 'Tidak ada file gambar yang ditemukan untuk diulang.']);
        }

        $pricelist->update([
            'status' => 'pending',
            'error_message' => null
        ]);

        ProcessPricelistJob::dispatch($pricelist->id, $firstMessage->attachments);

        return redirect()->back();
    }

    public function updateMessage(Request $request, Pricelist $pricelist, \App\Models\ChatMessage $chatMessage)
    {
        $request->validate([
            'content' => 'required|string'
        ]);

        if ($chatMessage->pricelist_id !== $pricelist->id) {
            abort(403);
        }

        $chatMessage->update(['content' => $request->input('content')]);

        return redirect()->back();
    }

    public function rename(Request $request, Pricelist $pricelist)
    {
        $request->validate([
            'filename' => 'required|string|max:255'
        ]);

        $pricelist->update([
            'filename' => $request->filename
        ]);

        return back();
    }

    public function cancel(Pricelist $pricelist)
    {
        if (in_array($pricelist->status, ['pending', 'processing', 'Mengekstrak data dari gambar...', 'Menyusun insight & benchmarking...'])) {
            $pricelist->update([
                'status' => 'cancelled',
                'error_message' => 'Proses dibatalkan oleh pengguna.'
            ]);
            return back()->with('success', 'Proses berhasil dibatalkan.');
        }

        return back()->withErrors(['error' => 'Sesi ini sudah tidak dapat dibatalkan.']);
    }

    public function updateStatus(Request $request, Pricelist $pricelist)
    {
        $request->validate(['status' => 'required|string']);
        $pricelist->update(['status' => $request->status]);
        return response()->json(['success' => true]);
    }

    public function updatePackages(Request $request, Pricelist $pricelist)
    {
        $request->validate([
            'packages' => 'required|array',
            'packages.*.provider' => 'required|string',
            'packages.*.package_name' => 'nullable|string',
            'packages.*.price' => 'required|numeric',
            'packages.*.gb' => 'required|numeric',
            'packages.*.days' => 'required|integer',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($pricelist, $request) {
            $pricelist->packages()->delete();
            foreach ($request->packages as $pkg) {
                $price = (int) $pkg['price'];
                $gb = (float) $pkg['gb'];
                $days = (int) $pkg['days'];
                
                $yield = $gb > 0 ? ceil($price / $gb) : 0;
                
                $category = 'Bulanan (Standar)';
                if ($days <= 7) $category = 'Harian (Sachet)';
                elseif ($days <= 15) $category = 'Mingguan';
                elseif ($price > 100000) $category = 'Bulanan (Premium/Jumbo)';

                \App\Models\ExtractedPackage::create([
                    'pricelist_id' => $pricelist->id,
                    'provider' => $pkg['provider'],
                    'package_name' => $pkg['package_name'] ?? null,
                    'price' => $price,
                    'gb' => $gb,
                    'days' => $days,
                    'yield_val' => $yield,
                    'category' => $category,
                    'image_timestamp' => $pkg['image_timestamp'] ?? null,
                    'image_location' => $pkg['image_location'] ?? null,
                ]);
            }
        });

        return back()->with('success', 'Data berhasil diperbarui berdasarkan validasi manual.');
    }
}
