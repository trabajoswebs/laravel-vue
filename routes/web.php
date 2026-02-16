<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\LanguageController;
use App\Infrastructure\Uploads\Http\Controllers\Media\ShowAvatar;
use App\Infrastructure\Uploads\Http\Controllers\DownloadUploadController; // Controlador de descargas
use App\Http\Controllers\Health\HealthController;
use App\Infrastructure\Uploads\Http\Controllers\Media\ShowMediaController;
use App\Infrastructure\Uploads\Http\Controllers\UploadController;

// Ruta principal del sitio
Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// Ruta del dashboard protegida por autenticación
Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Grupo de rutas para avatares firmados y con tenant
Route::middleware(['tenant', 'signed', 'throttle:media-serving'])->group(function (): void {
    // Muestra avatar firmado con control de acceso y rate limiting
    Route::get('media/avatar/{media}', ShowAvatar::class)
        ->middleware(['media.access']) // Middleware de auditoría de acceso a media
        ->name('media.avatar.show')   // Nombre de ruta para generación de URLs
        ->whereNumber('media');       // Asegura que el ID sea numérico
});


// Grupo de rutas para media protegido con autenticación y tenant
Route::middleware(['auth', 'tenant'])->group(function (): void { // Grupo con auth+tenant para media protegido // Ej: /media/tenants/1/...
    // Sirve archivos de media con control de tenant y seguridad
    Route::get('media/{path}', ShowMediaController::class) // Sirve media local sin storage:link // Ej: /media/tenants/1/users/2/avatar.jpg
        ->where('path', '.*') // Acepta paths profundos con slashes // Ej: conversions/thumb.webp
        ->middleware('throttle:media-serving') // Rate limit dedicado para servir media
        ->name('media.show'); // Nombre usado por UrlGenerator tenant-aware // Ej: route('media.show', ['path'=>...])
});

// Grupo de rutas para descargas de archivos
Route::middleware(['auth', 'tenant'])->group(function (): void {
    // Descarga archivos subidos que no son imágenes
    Route::get('uploads/{uploadId}', DownloadUploadController::class) // Descarga de uploads no imagen
        ->whereUuid('uploadId') // Asegura que el ID sea UUID
        ->name('uploads.download'); // Nombre de la ruta
});

// Grupo de rutas para operaciones CRUD de uploads
Route::middleware(['auth', 'tenant'])->group(function (): void {
    // Almacena nuevos uploads
    Route::post('uploads', [UploadController::class, 'store'])
        ->middleware('rate.uploads')
        ->name('uploads.store');
    
    // Actualiza información de un upload existente
    Route::patch('uploads/{uploadId}', [UploadController::class, 'update'])
        ->middleware('rate.uploads')
        ->whereUuid('uploadId') // Asegura que el ID sea UUID
        ->name('uploads.update');
    
    // Elimina un upload
    Route::delete('uploads/{uploadId}', [UploadController::class, 'destroy'])
        ->middleware('rate.uploads')
        ->whereUuid('uploadId') // Asegura que el ID sea UUID
        ->name('uploads.destroy');
});

// Ruta para health check del pipeline de uploads
Route::get('health/upload-pipeline', [HealthController::class, 'uploadPipeline'])
    ->middleware(['auth', 'throttle:15,1']) // Limita a 15 peticiones por minuto
    ->name('health.upload-pipeline');

// Ruta de test para entorno local
if (app()->environment('local')) {
    Route::get('test-flash-event', function () {
        return back()->with('event', [
            'title' => 'Evento de prueba',
            'description' => 'Este toast viene del flash.event configurado en HandleInertiaRequests.',
        ]);
    })->middleware(['auth']);
}

// Incluye rutas adicionales
require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';

// Rutas para el manejo de idiomas (NO requieren autenticación)
Route::prefix('language')->name('language.')->group(function () {

    // Cambiar idioma del usuario
    Route::post('change/{locale}', [LanguageController::class, 'changeLanguage'])
        ->name('change')
        ->middleware(['auth', 'throttle:30,1']); // 30 cambios por minuto máximo

    // Obtener idioma actual
    Route::get('current', [LanguageController::class, 'getCurrentLanguage'])
        ->name('current')
        ->middleware(['auth', 'throttle:120,1']); // 120 consultas por minuto máximo

});
