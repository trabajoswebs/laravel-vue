<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Infrastructure\Http\Controllers\LanguageController;
use App\Infrastructure\Uploads\Http\Controllers\Media\ShowAvatar;
use App\Infrastructure\Uploads\Http\Controllers\DownloadUploadController; // Controlador de descargas
use App\Infrastructure\Http\Controllers\Health\HealthController;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['tenant', 'signed'])->group(function (): void {
    Route::get('media/avatar/{media}', ShowAvatar::class)
        ->middleware(['throttle:60,1', 'media.access']) // firma + rate limit + auditoría
        ->name('media.avatar.show')
        ->whereNumber('media');
});

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('uploads/{uploadId}', DownloadUploadController::class) // Descarga de uploads no imagen
        ->whereUuid('uploadId') // Usa UUID
        ->name('uploads.download'); // Nombre de ruta
});

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::post('uploads', [\App\Infrastructure\Uploads\Http\Controllers\UploadController::class, 'store'])
        ->name('uploads.store');
    Route::patch('uploads/{uploadId}', [\App\Infrastructure\Uploads\Http\Controllers\UploadController::class, 'update'])
        ->whereUuid('uploadId')
        ->name('uploads.update');
    Route::delete('uploads/{uploadId}', [\App\Infrastructure\Uploads\Http\Controllers\UploadController::class, 'destroy'])
        ->whereUuid('uploadId')
        ->name('uploads.destroy');
});

Route::get('health/upload-pipeline', [HealthController::class, 'uploadPipeline'])
    ->middleware(['auth', 'throttle:15,1'])
    ->name('health.upload-pipeline');

if (app()->environment('local')) {
    Route::get('test-flash-event', function () {
        return back()->with('event', [
            'title' => 'Evento de prueba',
            'description' => 'Este toast viene del flash.event configurado en HandleInertiaRequests.',
        ]);
    })->middleware(['auth']);
}

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

// Rutas para el manejo de idiomas (NO requieren autenticación)
Route::prefix('language')->name('language.')->group(function () {
    
    // Cambiar idioma del usuario
    Route::post('change/{locale}', [LanguageController::class, 'changeLanguage'])
        ->name('change')
        ->middleware(['auth', 'throttle:30,1']); // guest + 30 cambios por minuto máximo
    
    // Obtener idioma actual
    Route::get('current', [LanguageController::class, 'getCurrentLanguage'])
        ->name('current')
        ->middleware(['auth', 'throttle:120,1']); // guest + 120 consultas por minuto máximo
    
});
