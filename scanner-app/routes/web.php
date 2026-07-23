<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScannerController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return redirect('/scanner');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\ChatController;

Route::get('/phpinfo', function () {
    return phpinfo();
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/scanner', [ScannerController::class, 'index'])->name('scanner.index');
    Route::post('/scanner', [ScannerController::class, 'store'])->name('scanner.store');
    Route::delete('/scanner/{pricelist}', [ScannerController::class, 'destroy'])->name('scanner.destroy');
    Route::get('/scanner/{pricelist}/export', [ScannerController::class, 'export'])->name('scanner.export');
    Route::get('/scanner/{pricelist}/insights', [ScannerController::class, 'insights'])->name('scanner.insights');
    Route::post('/scanner/{pricelist}/chat', [ChatController::class, 'store'])->name('scanner.chat');
    Route::post('/scanner/{pricelist}/retry', [ScannerController::class, 'retry'])->name('scanner.retry');
    Route::post('/scanner/{pricelist}/cancel', [ScannerController::class, 'cancel'])->name('scanner.cancel');
    Route::put('/scanner/{pricelist}/rename', [ScannerController::class, 'rename'])->name('scanner.rename');
    Route::put('/scanner/{pricelist}/message/{chatMessage}', [ScannerController::class, 'updateMessage'])->name('scanner.message.update');
    Route::put('/scanner/{pricelist}/packages', [ScannerController::class, 'updatePackages'])->name('scanner.packages.update');

    Route::post('/api/scanner/{pricelist}/status', [ScannerController::class, 'updateStatus'])->name('scanner.status.update')->withoutMiddleware(['auth', 'verified']);

    // API Key management
    Route::get('/api/keys', [ApiKeyController::class, 'index'])->name('apikeys.index');
    Route::post('/api/keys', [ApiKeyController::class, 'store'])->name('apikeys.store');
    Route::delete('/api/keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('apikeys.destroy');
    Route::post('/api/keys/{apiKey}/toggle', [ApiKeyController::class, 'toggle'])->name('apikeys.toggle');
});

require __DIR__ . '/auth.php';
