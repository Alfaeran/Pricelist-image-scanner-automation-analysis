<?php

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\BaselineProductController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Route;

// Desktop app: there is no landing page or login flow to show. The
// AutoAuthenticateDesktop middleware signs in the local user on every request,
// so go straight to the scanner.
Route::get('/', function () {
    return redirect('/scanner');
});

Route::get('/dashboard', function () {
    return redirect('/scanner');
})->name('dashboard');

Route::get('/scanner', [ScannerController::class, 'index'])->name('scanner.index');
Route::post('/scanner', [ScannerController::class, 'store'])->name('scanner.store');
Route::post('/scanner/upload-data', [ScannerController::class, 'uploadData'])->name('scanner.uploadData');
Route::get('/api/trends', [ScannerController::class, 'trendData'])->name('api.trends');
Route::delete('/scanner/{pricelist}', [ScannerController::class, 'destroy'])->name('scanner.destroy');
Route::get('/scanner/{pricelist}/export', [ScannerController::class, 'export'])->name('scanner.export');
Route::get('/scanner/{pricelist}/export-csv', [ScannerController::class, 'exportCsv'])->name('scanner.exportCsv');
Route::get('/scanner/{pricelist}/export-txt', [ScannerController::class, 'exportTxt'])->name('scanner.exportTxt');
Route::get('/scanner/{pricelist}/insights', [ScannerController::class, 'insights'])->name('scanner.insights');
Route::post('/scanner/{pricelist}/chat', [ChatController::class, 'store'])->name('scanner.chat');
Route::delete('/scanner/{pricelist}/message/{chatMessage}/chart', [ChatController::class, 'destroyChart'])->name('scanner.chat.destroyChart');
Route::post('/scanner/{pricelist}/retry', [ScannerController::class, 'retry'])->name('scanner.retry');
Route::post('/scanner/{pricelist}/cancel', [ScannerController::class, 'cancel'])->name('scanner.cancel');
Route::put('/scanner/{pricelist}/rename', [ScannerController::class, 'rename'])->name('scanner.rename');
Route::put('/scanner/{pricelist}/message/{chatMessage}', [ScannerController::class, 'updateMessage'])->name('scanner.message.update');
Route::put('/scanner/{pricelist}/packages', [ScannerController::class, 'updatePackages'])->name('scanner.packages.update');
Route::put('/scanner/package/{package}', [ScannerController::class, 'updateSinglePackage'])->name('scanner.package.update');
Route::post('/scanner/{pricelist}/compare', [ScannerController::class, 'compareCsv'])->name('scanner.compareCsv');
Route::post('/scanner/{pricelist}/sync-csv', [ScannerController::class, 'syncCsv'])->name('scanner.syncCsv');
Route::post('/api/scanner/{pricelist}/status', [ScannerController::class, 'updateStatus'])->name('scanner.status.update');

// AI Insight
Route::post('/api/scanner/ai-insight', [ScannerController::class, 'aiInsight'])->name('scanner.aiInsight');

// Learned Patterns API for Python
Route::get('/api/learned-patterns', function () {
    $patterns = \App\Models\LearnedPattern::where('is_active', true)
                    ->orderBy('id', 'asc')
                    ->get(['provider', 'rule_text']);
    return response()->json(['status' => 'success', 'data' => $patterns]);
})->name('api.learned-patterns');

// Desktop background services: engine status + restart
Route::get('/api/system/health', [SystemController::class, 'health'])->name('system.health');
Route::post('/api/system/restart', [SystemController::class, 'restart'])->name('system.restart');

// Baseline Products API
Route::get('/api/baseline-products', [BaselineProductController::class, 'index'])->name('baseline.index');
Route::post('/api/baseline-products', [BaselineProductController::class, 'store'])->name('baseline.store');
Route::put('/api/baseline-products/{baselineProduct}', [BaselineProductController::class, 'update'])->name('baseline.update');
Route::delete('/api/baseline-products/{baselineProduct}', [BaselineProductController::class, 'destroy'])->name('baseline.destroy');
Route::post('/api/baseline-products/bulk', [BaselineProductController::class, 'bulkUpdate'])->name('baseline.bulkUpdate');

// API Key management
Route::get('/api/keys', [ApiKeyController::class, 'index'])->name('apikeys.index');
Route::post('/api/keys', [ApiKeyController::class, 'store'])->name('apikeys.store');
Route::delete('/api/keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('apikeys.destroy');
Route::post('/api/keys/{apiKey}/toggle', [ApiKeyController::class, 'toggle'])->name('apikeys.toggle');
