<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Modules\Uploads\Controllers\Settings\ProfileAvatarController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('tenant')->group(function () { // Protege rutas con grupo tenant
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Avatar endpoints (actualización y eliminación) protegidos por auth
    Route::patch('settings/avatar', [ProfileAvatarController::class, 'update'])
        ->middleware('rate.uploads')
        ->name('settings.avatar.update');
    Route::delete('settings/avatar', [ProfileAvatarController::class, 'destroy'])
        ->name('settings.avatar.destroy');
    Route::get('settings/avatar/status', [ProfileAvatarController::class, 'status'])
        ->name('settings.avatar.status');
    Route::get('api/avatar/uploads/{uploadUuid}/status', [ProfileAvatarController::class, 'uploadStatus'])
        ->name('settings.avatar.upload-status');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');
});
