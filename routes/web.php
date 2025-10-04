<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\LanguageController;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

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
        ->where('locale', '[a-z]{2}(-[A-Z]{2})?')
        ->middleware(['auth', 'throttle:30,1']); // guest + 30 cambios por minuto máximo
    
    // Obtener idioma actual
    Route::get('current', [LanguageController::class, 'getCurrentLanguage'])
        ->name('current')
        ->middleware(['auth', 'throttle:120,1']); // guest + 120 consultas por minuto máximo
    
});
