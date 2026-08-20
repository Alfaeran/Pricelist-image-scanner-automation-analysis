<?php

namespace App\Http\Controllers;

use App\Models\Pricelist;
use App\Models\ExtractedPackage;
use App\Models\ApiKey;
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
            'pricelists' => Pricelist::with(['packages', 'chatMessages'])->latest()->take(20)->get(),
            'baselineProducts' => \App\Models\BaselineProduct::orderBy('provider')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'pricelist_id' => 'nullable|exists:pricelists,id',
            'message' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'file|max:102400|mimes:jpeg,png,jpg,webp,pdf,zip,csv,txt,xlsx,xls',
            'is_append' => 'boolean',
            'manual_timestamp' => 'nullable|date',
            'locations' => 'nullable|array',
            'locations.*' => 'nullable|string'
        ]);

        if (!$request->hasFile('images') && !$request->filled('message')) {
            return back()->withErrors(['error' => 'Pesan atau file harus diisi.']);
        }

        if (!\App\Models\ApiKey::where('is_active', true)->exists()) {
            return back()->withErrors(['error' => 'API Key Gemini belum diatur atau tidak valid. Silakan masukkan API Key terlebih dahulu di sebelah kiri.']);
        }

        $files = $request->file('images') ?? [];
        $locationsInput = $request->input('locations') ?? [];
        $paths = [];
        $locations = [];
        $originalNames = [];

        foreach ($files as $idx => $file) {
            if (!$file->isValid()) {
                \Log::error('Upload failed with error code: ' . $file->getError() . ' - Message: ' . $file->getErrorMessage());
                return back()->withErrors(['error' => 'Gagal mengupload file: ' . $file->getErrorMessage()]);
            }

            $name = $file->getClientOriginalName();
            $originalNameInfo = pathinfo($name);
            $baseName = $originalNameInfo['filename'];
            $extension = isset($originalNameInfo['extension']) ? '.' . $originalNameInfo['extension'] : '';
            
            $counter = 1;
            $searchName = $name;
            while (Pricelist::where('filename', 'LIKE', '%' . $searchName . '%')->exists()) {
                $searchName = $baseName . '(' . $counter . ')' . $extension;
                $counter++;
            }
            $name = $searchName;

            $originalNames[] = $name;
            $paths[] = $file->storeAs('pricelists', uniqid() . '_' . $name, 'public');
            $locations[] = $locationsInput[$idx] ?? null;
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

        if (count($paths) > 0) {
            $isAppend = $request->boolean('is_append', false);
            $manualTimestamp = $request->input('manual_timestamp');
            ProcessPricelistJob::dispatch($pricelist->id, $paths, $isAppend, $manualTimestamp, $locations);
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

    public function exportTxt(Pricelist $pricelist)
    {
        if ($pricelist->status !== 'processed') {
            return back()->withErrors(['error' => 'Data belum selesai diproses. Status saat ini: ' . $pricelist->status]);
        }

        $packages = $pricelist->packages;

        if (!$packages || $packages->isEmpty()) {
            return back()->withErrors(['error' => 'Tidak ada data paket untuk diekspor.']);
        }

        $filename = "Rekap_Harga_" . $pricelist->id . ".txt";

        $headers = [
            "Content-type"        => "text/plain",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['Provider', 'Package Name', 'Price', 'GB', 'Days', 'Price/GB', 'Category', 'Product Type'];

        $callback = function() use($packages, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns, "\t");

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
                fputcsv($file, $row, "\t");
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
                'package_name' => $pkg->package_name,
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
                
                $yield = $this->calculateYield($pkg['package_name'] ?? '', $gb, $days, $price);
                
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
                    'is_anomaly' => ($price <= 0 || $gb <= 0 || $yield > 50000),
                    'category' => $category,
                    'image_timestamp' => $pkg['image_timestamp'] ?? null,
                    'image_location' => $pkg['image_location'] ?? null,
                ]);
            }
        });

        return back()->with('success', 'Data berhasil diperbarui berdasarkan validasi manual.');
    }

    public function updateSinglePackage(Request $request, \App\Models\ExtractedPackage $package)
    {
        $request->validate([
            'provider' => 'required|string',
            'package_name' => 'nullable|string',
            'price' => 'required|numeric',
            'gb' => 'required|numeric',
            'days' => 'required|integer',
            'image_timestamp' => 'nullable|string',
        ]);

        $price = (int) $request->price;
        $gb = (float) $request->gb;
        $days = (int) $request->days;
        
        $yield = $this->calculateYield($request->package_name ?? '', $gb, $days, $price);
        
        $category = 'Bulanan (Standar)';
        if ($days <= 7) $category = 'Harian (Sachet)';
        elseif ($days <= 15) $category = 'Mingguan';
        elseif ($price > 100000) $category = 'Bulanan (Premium/Jumbo)';

        $package->update([
            'provider' => $request->provider,
            'package_name' => $request->package_name,
            'price' => $price,
            'gb' => $gb,
            'days' => $days,
            'yield_val' => $yield,
            'is_anomaly' => ($price <= 0 || $gb <= 0 || $yield > 50000),
            'category' => $category,
            'image_timestamp' => $request->image_timestamp,
        ]);

        return response()->json(['success' => true, 'package' => $package]);
    }

    public function uploadData(Request $request)
    {
        $request->validate([
            'data_file' => 'required|file|mimes:csv,txt,xlsx,xls|max:102400',
            'manual_timestamp' => 'nullable|date',
            'location' => 'nullable|string'
        ]);

        $manualTimestamp = $request->input('manual_timestamp');
        $location = $request->input('location');
        $file = $request->file('data_file');
        
        $csvData = [];
        $ext = strtolower($file->getClientOriginalExtension());
        
        if ($ext === 'xlsx' || $ext === 'xls') {
            if ($xlsx = \Shuchkin\SimpleXLSX::parse($file->getRealPath())) {
                foreach ($xlsx->rows() as $row) {
                    $csvData[] = $row;
                }
            } else {
                return back()->withErrors(['error' => 'Gagal membaca file Excel: ' . \Shuchkin\SimpleXLSX::parseError()]);
            }
        } else {
            if (($handle = fopen($file->getRealPath(), "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $csvData[] = $data;
                }
                fclose($handle);
            } else {
                return back()->withErrors(['error' => 'Gagal membaca file CSV.']);
            }
        }
        
        $originalName = $file->getClientOriginalName();
        
        \Illuminate\Support\Facades\DB::transaction(function () use ($csvData, $originalName, $manualTimestamp, $location) {
            $pricelist = Pricelist::create([
                'filename' => $originalName,
                'status' => 'processed'
            ]);
            
            $pricelist->chatMessages()->create([
                'role' => 'user',
                'content' => 'Upload data manual: ' . $originalName,
                'attachments' => null,
            ]);

            foreach ($csvData as $i => $row) {
                if ($i < 4) continue; // Skip headers
                if (count($row) < 6) continue;
                
                $provider = strtoupper(trim($row[1]));
                if (!$provider) continue;
                
                // Normalization exactly like compareCsv
                if ($provider == 'SF') $provider = 'SMARTFREN';
                elseif ($provider == 'TSEL') $provider = 'TELKOMSEL';
                elseif ($provider == '3ID') $provider = '3';
                elseif ($provider == 'BYU') $provider = 'BY.U';
                
                $price = (int) preg_replace('/[^\d]/', '', $row[3]);
                
                // Fix GB parsing (comma to dot)
                $gbStr = str_replace(',', '.', $row[4]);
                $gb = (float) preg_replace('/[^\d\.]/', '', $gbStr);
                
                $days = (int) preg_replace('/[^\d]/', '', $row[5]);
                
                // Calculate category and yield
                $yield = $this->calculateYield("Paket " . $provider, $gb, $days, $price);
                
                $category = 'Bulanan (Standar)';
                if ($days <= 7) $category = 'Harian (Sachet)';
                elseif ($days <= 15) $category = 'Mingguan';
                elseif ($price > 100000) $category = 'Bulanan (Premium/Jumbo)';

                // Location filters on /api/trends query circle/region/branch,
                // not image_location, so rows imported here were invisible to
                // every location filter until these were populated too.
                $locDetails = \App\Models\ExtractedPackage::locationDetails($location);

                \App\Models\ExtractedPackage::create([
                    'pricelist_id' => $pricelist->id,
                    'provider' => $provider,
                    'package_name' => "Paket " . $provider,
                    'price' => $price,
                    'gb' => $gb,
                    'days' => $days,
                    'yield_val' => $yield,
                    'is_anomaly' => ($price <= 0 || $gb <= 0 || $yield > 50000),
                    'category' => $category,
                    'product_type' => 'Data',
                    'image_timestamp' => $manualTimestamp,
                    'image_location' => $location,
                    'circle' => $locDetails['circle'],
                    'region' => $locDetails['region'],
                    'branch' => $locDetails['branch'],
                ]);
            }
        });

        return redirect()->back()->with('success', 'Data berhasil diimpor.');
    }

    public function compareCsv(Request $request, Pricelist $pricelist)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt,xlsx,xls'
        ]);

        $file = $request->file('csv_file');
        
        $csvData = [];
        $ext = strtolower($file->getClientOriginalExtension());
        
        if ($ext === 'xlsx' || $ext === 'xls') {
            if ($xlsx = \Shuchkin\SimpleXLSX::parse($file->getRealPath())) {
                foreach ($xlsx->rows() as $row) {
                    $csvData[] = $row;
                }
            } else {
                return response()->json(['success' => false, 'error' => \Shuchkin\SimpleXLSX::parseError()], 400);
            }
        } else {
            if (($handle = fopen($file->getRealPath(), "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $csvData[] = $data;
                }
                fclose($handle);
            }
        }
        
        $csvPackages = [];
        foreach ($csvData as $i => $row) {
            if ($i < 4) continue; // Skip headers
            if (count($row) < 6) continue;
            
            $provider = strtoupper(trim($row[1]));
            if (!$provider) continue;
            
            if ($provider == 'SF') $provider = 'SMARTFREN';
            elseif ($provider == 'TSEL') $provider = 'TELKOMSEL';
            elseif ($provider == '3ID') $provider = '3';
            elseif ($provider == 'BYU') $provider = 'BY.U';
            
            $price = (int) preg_replace('/[^\d]/', '', $row[3]);
            $gbStr = str_replace(',', '.', $row[4]);
            $gb = (float) preg_replace('/[^\d\.]/', '', $gbStr);
            $days = (int) preg_replace('/[^\d]/', '', $row[5]);
            
            $csvPackages[] = [
                'provider' => $provider,
                'price' => $price,
                'gb' => $gb,
                'days' => $days,
            ];
        }

        $dbPackages = $pricelist->packages;
        $results = [];
        $csvUnmatched = $csvPackages;

        foreach ($dbPackages as $dbPkg) {
            $dbProvider = strtoupper(trim($dbPkg->provider));
            if ($dbProvider == 'SF') $dbProvider = 'SMARTFREN';
            elseif ($dbProvider == 'TSEL') $dbProvider = 'TELKOMSEL';
            elseif ($dbProvider == '3ID') $dbProvider = '3';
            elseif ($dbProvider == 'BYU') $dbProvider = 'BY.U';

            $dbPrice = (int) $dbPkg->price;
            $dbGb = (float) $dbPkg->gb;
            $dbDays = (int) $dbPkg->days;

            $foundMatch = false;
            $matchType = 'not_found';
            $matchedCsv = null;
            
            // 1. Exact Match
            foreach ($csvUnmatched as $idx => $csvPkg) {
                if ($dbProvider === $csvPkg['provider'] && 
                    $dbPrice === $csvPkg['price'] && 
                    $dbDays === (int)$csvPkg['days'] &&
                    abs($dbGb - $csvPkg['gb']) < 0.1) {
                    
                    $matchType = 'matched';
                    $matchedCsv = $csvPkg;
                    unset($csvUnmatched[$idx]);
                    $foundMatch = true;
                    break;
                }
            }

            // 2. Relaxed Match (Different Price, but same provider and GB)
            if (!$foundMatch) {
                foreach ($csvUnmatched as $idx => $csvPkg) {
                    if ($dbProvider === $csvPkg['provider'] && 
                        abs($dbGb - $csvPkg['gb']) < 0.1) {
                        
                        $matchType = 'price_mismatch';
                        $matchedCsv = $csvPkg;
                        unset($csvUnmatched[$idx]);
                        $foundMatch = true;
                        break;
                    }
                }
            }

            $results[$dbPkg->id] = [
                'status' => $matchType,
                'expected_price' => $matchedCsv ? $matchedCsv['price'] : null,
                'csv_row' => $matchedCsv
            ];
        }

        return response()->json([
            'success' => true,
            'results' => $results,
            'unmatched_csv' => array_values($csvUnmatched),
            'total_csv' => count($csvPackages),
            'total_db' => count($dbPackages)
        ]);
    }

    public function syncCsv(Request $request, Pricelist $pricelist)
    {
        $updates = $request->input('updates', []);
        $newPackages = $request->input('new_packages', []);
        $mismatchesByProvider = [];

        // Process updates
        foreach ($updates as $update) {
            $pkg = \App\Models\ExtractedPackage::where('pricelist_id', $pricelist->id)
                          ->where('id', $update['id'])
                          ->first();
            if ($pkg) {
                // Record mismatch if there is a significant data correction
                if ($pkg->price != $update['price'] || $pkg->gb != $update['gb'] || $pkg->days != $update['days']) {
                    $mismatchesByProvider[$update['provider']][] = [
                        'ai_data' => [
                            'price' => $pkg->price,
                            'gb' => $pkg->gb,
                            'days' => $pkg->days
                        ],
                        'ground_truth' => [
                            'price' => $update['price'],
                            'gb' => $update['gb'],
                            'days' => $update['days']
                        ]
                    ];
                }

                $pkg->provider = $update['provider'];
                $pkg->price = $update['price'];
                $pkg->gb = $update['gb'];
                $pkg->days = $update['days'];
                
                // Update category based on days and price
                $pkg->category = 'Bulanan (Standar)';
                if ($pkg->days <= 7) {
                    $pkg->category = 'Harian (Sachet)';
                } elseif ($pkg->days <= 15) {
                    $pkg->category = 'Mingguan';
                } elseif ($pkg->price > 100000) {
                    $pkg->category = 'Bulanan (Premium/Jumbo)';
                }
                
                // Recalculate yield
                $price = (int)$pkg->price;
                $gb = (float)$pkg->gb;
                $days = (int)$pkg->days;
                
                $pkg->yield_val = $this->calculateYield($pkg->package_name ?? '', $gb, $days, $price);
                $pkg->is_anomaly = ($price <= 0 || $gb <= 0 || $pkg->yield_val > 50000);

                $pkg->save();
            }
        }

        // Dispatch learning job for each provider that had mismatches
        foreach ($mismatchesByProvider as $provider => $mismatches) {
            \App\Jobs\TrainWorkerModelJob::dispatch($provider, $mismatches);
        }

        // Process new packages
        foreach ($newPackages as $newPkg) {
            $provider = $newPkg['provider'];
            $price = (int)$newPkg['price'];
            $gb = (float)$newPkg['gb'];
            $days = (int)$newPkg['days'];

            $category = 'Bulanan (Standar)';
            if ($days <= 7) {
                $category = 'Harian (Sachet)';
            } elseif ($days <= 15) {
                $category = 'Mingguan';
            } elseif ($price > 100000) {
                $category = 'Bulanan (Premium/Jumbo)';
            }

            \App\Models\ExtractedPackage::create([
                'pricelist_id' => $pricelist->id,
                'provider' => $provider,
                'package_name' => "Paket " . $provider,
                'price' => $price,
                'gb' => $gb,
                'days' => $days,
                'yield_val' => $this->calculateYield("Paket " . $provider, $gb, $days, $price),
                'is_anomaly' => ($price <= 0 || $gb <= 0 || ($gb > 0 ? ceil($price / $gb) : 0) > 50000),
                'category' => $category,
            ]);
        }

        // Retroactively recalculate yield for ALL packages in this pricelist to ensure "unlimited" rule is applied correctly
        $allPackages = \App\Models\ExtractedPackage::where('pricelist_id', $pricelist->id)->get();
        foreach ($allPackages as $pkg) {
            $price = (int)$pkg->price;
            $gb = (float)$pkg->gb;
            $days = (int)$pkg->days;
            
            $pkg->yield_val = $this->calculateYield($pkg->package_name ?? '', $gb, $days, $price);
            $pkg->is_anomaly = ($price <= 0 || $gb <= 0 || $pkg->yield_val > 50000);
            $pkg->save();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Market Trend Data API
     */
    public function trendData(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Effective date of a package: its image_timestamp when that parses as a date,
        // otherwise the pricelist's created_at. image_timestamp is free text extracted
        // from image overlays, so it is regularly unparseable. Filtering and grouping
        // share this expression so the two can never disagree about a package's date.
        $trendDate = "COALESCE(DATE(NULLIF(extracted_packages.image_timestamp, '')), DATE(pricelists.created_at))";

        $query = \App\Models\ExtractedPackage::query()
            ->join('pricelists', 'extracted_packages.pricelist_id', '=', 'pricelists.id')
            ->where('pricelists.status', 'processed')
            ->where('extracted_packages.price', '>', 0)
            ->where('extracted_packages.gb', '>', 0)
            ->where('extracted_packages.yield_val', '>', 0);

        $filenames = $request->input('filenames', []);
        if (is_array($filenames) && count($filenames) > 0) {
            $query->whereIn('pricelists.filename', $filenames);
        }

        $circle = $request->input('circle');
        if ($circle) {
            $query->where('extracted_packages.circle', $circle);
        }

        $region = $request->input('region');
        if ($region) {
            $query->where('extracted_packages.region', $region);
        }

        $branch = $request->input('branch');
        if ($branch) {
            $query->where('extracted_packages.branch', $branch);
        }

        if ($startDate) {
            $query->whereRaw("{$trendDate} >= ?", [$startDate]);
        }
        if ($endDate) {
            $query->whereRaw("{$trendDate} <= ?", [$endDate]);
        }

        $data = $query->selectRaw("
            {$trendDate} as trend_date,
            extracted_packages.provider,
            ROUND(AVG(extracted_packages.price), 0) as avg_price,
            ROUND(AVG(extracted_packages.yield_val), 0) as avg_yield,
            COUNT(*) as count,
            ROUND(AVG(extracted_packages.gb), 1) as avg_gb
        ")
        ->groupBy('trend_date', 'extracted_packages.provider')
        ->orderBy('trend_date')
        ->get();

        if ($data->isEmpty()) {
            return response()->json([
                'labels' => [],
                'providers' => [],
                'kpi' => [
                    'total_packages' => 0,
                    'total_scans' => 0,
                    'avg_price' => 0,
                    'avg_yield' => 0,
                    'most_aggressive' => null,
                ]
            ]);
        }

        // Generate unique labels based on date
        $data->transform(function ($item) {
            $item->label_key = \Carbon\Carbon::parse($item->trend_date)->format('d M Y');
            return $item;
        });

        $labels = $data->pluck('label_key')->unique()->values()->toArray();
        $providerGroups = $data->groupBy('provider');

        $providers = [];
        foreach ($providerGroups as $providerName => $entries) {
            $dateMap = $entries->keyBy('label_key');
            $avgPrices = [];
            $avgYields = [];
            $counts = [];
            $avgGbs = [];

            foreach ($labels as $label) {
                if (isset($dateMap[$label])) {
                    $avgPrices[] = (float) $dateMap[$label]->avg_price;
                    $avgYields[] = (float) $dateMap[$label]->avg_yield;
                    $counts[] = (int) $dateMap[$label]->count;
                    $avgGbs[] = (float) $dateMap[$label]->avg_gb;
                } else {
                    $avgPrices[] = null;
                    $avgYields[] = null;
                    $counts[] = 0;
                    $avgGbs[] = null;
                }
            }

            $providers[$providerName] = [
                'avg_price' => $avgPrices,
                'avg_yield' => $avgYields,
                'count' => $counts,
                'avg_gb' => $avgGbs,
            ];
        }

        $totalPackages = $data->sum('count');
        $overallAvgPrice = round($data->avg('avg_price'), 0);
        $overallAvgYield = round($data->avg('avg_yield'), 0);
        $totalScans = Pricelist::where('status', 'processed')->count();

        $providerAvgYields = [];
        foreach ($providerGroups as $providerName => $entries) {
            $providerAvgYields[$providerName] = $entries->avg('avg_yield');
        }
        asort($providerAvgYields);
        $mostAggressive = !empty($providerAvgYields) ? array_key_first($providerAvgYields) : null;

        return response()->json([
            'labels' => $labels,
            'providers' => $providers,
            'kpi' => [
                'total_packages' => $totalPackages,
                'total_scans' => $totalScans,
                'avg_price' => $overallAvgPrice,
                'avg_yield' => $overallAvgYield,
                'most_aggressive' => $mostAggressive,
            ]
        ]);
    }

    public function aiInsight(Request $request)
    {
        $packages = $request->input('packages');

        if (!$packages || empty($packages)) {
            return response()->json(['error' => 'Data paket kosong.'], 400);
        }

        // Get all active API keys
        $activeKeysData = ApiKey::where('is_active', true)->get();
        if ($activeKeysData->isEmpty()) {
            return response()->json(['error' => 'Tidak ada API Key yang aktif. Silakan tambahkan di menu samping.'], 500);
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

        // Ensure numbers are properly typed for FastAPI validation
        $formattedPackages = collect($packages)->map(function ($pkg) {
            return [
                'provider' => (string) ($pkg['provider'] ?? ''),
                'package_name' => (string) ($pkg['package_name'] ?? ''),
                'price' => (int) ($pkg['price'] ?? 0),
                'gb' => (float) ($pkg['gb'] ?? 0),
                'days' => (int) ($pkg['days'] ?? 0),
                'yield_val' => (float) ($pkg['yield_val'] ?? 0),
                'category' => (string) ($pkg['category'] ?? ''),
            ];
        })->toArray();

        // Hardcoded Strategy Prompt
        $prompt = "Tolong analisis ringkasan data paket internet berikut secara komprehensif.\n" .
                  "Berikan insight mendalam mengenai provider mana yang unggul di kategori mana (harga termurah, kuota terbesar, masa aktif terlama, dan efisiensi / yield terbaik).\n" .
                  "Kemudian, berikan saran strategis khusus yang *actionable* untuk provider IM3 dan 3 (Tri) agar mereka bisa memenangkan persaingan pasar melawan provider lain yang ada di data ini.\n" .
                  "Format respons menggunakan Markdown yang rapi dan profesional.";

        try {
            $response = Http::timeout(120)->post(env('FASTAPI_URL', 'http://127.0.0.1:8001') . '/api/chat', [
                'message' => $prompt,
                'packages' => $formattedPackages,
                'api_keys' => $apiKeysString,
                'model' => $modelsString
            ]);

            if ($response->successful()) {
                return response()->json(['insight' => $response->json('data')['text']]);
            }

            $errorMessage = $response->json('detail') ?: ('Gagal mendapatkan insight dari server AI (HTTP ' . $response->status() . '): ' . $response->body());
            \Illuminate\Support\Facades\Log::error('AI Insight FastAPI Error: ' . $response->body());
            return response()->json(['error' => $errorMessage], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 500);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'Tidak bisa terhubung ke server AI Python (FastAPI).'], 503);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Terjadi kesalahan internal: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Ambang batas yield (Rp/GB) yang masih wajar untuk paket kuota kecil dengan masa aktif
     * cukup panjang. Di atas ini, kombinasi "GB kecil + masa aktif panjang + harga segini"
     * jauh lebih masuk akal dibaca sebagai kuota HARIAN (FUP), bukan kuota total.
     * DEFAULT — validasi ulang terhadap dataset riil sebelum dipakai di produksi.
     */
    private const MAX_PLAUSIBLE_YIELD_SMALL_LONG_VALIDITY = 30000; // Rp/GB

    /**
     * Ambang batas bawah untuk anomali sebaliknya pada paket masa aktif pendek (sachet/harian):
     * yield yang mustahil murah biasanya berarti GB yang tercatat juga sebenarnya kuota harian.
     */
    private const MIN_PLAUSIBLE_YIELD_SHORT_VALIDITY = 50; // Rp/GB

    /**
     * Multi-rule unlimited package detection.
     */
    private function isUnlimitedPackage(string $packageName, float $gb, int $days, int $price): bool
    {
        $nameLower = strtolower($packageName);

        // Rule 1: By Name (keyword matching)
        if (str_contains($nameLower, 'unlimited') || str_contains($nameLower, 'unli') ||
            str_contains($nameLower, 'tanpa batas') || str_contains($nameLower, 'nonstop') ||
            str_contains($nameLower, 'fup')) {
            return true;
        }

        // Rule 2 (DIPERBAIKI): Deteksi FUP harian berdasarkan anomali yield.
        // Dulu arah perbandingannya terbalik (< 500, gak pernah kena di data nyata).
        // Sekarang: masa aktif >= 7 hari + GB kecil + naive yield JAUH LEBIH MAHAL
        // dari batas wajar => kemungkinan besar kuota harian, bukan total.
        if ($days >= 7 && $gb > 0 && $gb <= 5 && $price > 0) {
            $naiveYield = $price / $gb;
            if ($naiveYield > self::MAX_PLAUSIBLE_YIELD_SMALL_LONG_VALIDITY) {
                return true;
            }
        }

        // Rule 3: Anomali sebaliknya untuk paket sachet/harian (masa aktif pendek) —
        // yield yang mustahil murah mengindikasikan GB yang tercatat bukan kuota total.
        if ($days <= 7 && $gb > 0 && $price > 0) {
            $naiveYield = $price / $gb;
            if ($naiveYield < self::MIN_PLAUSIBLE_YIELD_SHORT_VALIDITY) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate yield intelligently, handling unlimited packages.
     */
    public function calculateYield(string $packageName, float $gb, int $days, int $price): float
    {
        if ($gb <= 0 || $price <= 0) return 0;
        
        if ($this->isUnlimitedPackage($packageName, $gb, $days, $price)) {
            if ($gb <= 5 && $days > 1) {
                return ceil($price / ($gb * $days));
            }
            return ceil($price / $gb);
        }
        
        return ceil($price / $gb);
    }
}
