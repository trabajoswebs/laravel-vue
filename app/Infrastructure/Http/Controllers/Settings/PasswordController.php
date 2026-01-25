<?php

namespace App\Infrastructure\Http\Controllers\Settings;

use App\Infrastructure\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PasswordController extends Controller
{
    
    /**
     * Muestra la página de configuración de la contraseña del usuario.
     */
    public function edit(Request $request): Response
    {
        $this->authorize('view', $request->user());

        return Inertia::render('settings/Password');
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('update', $request->user());

        $passwordRule = Password::defaults();
        if (!app()->environment('testing')) {
            $passwordRule->uncompromised();
        }

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', $passwordRule, 'confirmed'],
        ]);

        try {
            $request->user()->update([
                'password' => Hash::make($validated['password']),
            ]);

            return back()->with(['success' => __('auth.update.success')]);
        } catch (Throwable $e) {
            Log::warning('Password update failed', [
                'user_id' => $request->user()?->getKey(),
                'error' => $e->getMessage(),
                'metric' => 'password.update.failed',
            ]);

            return back()->withErrors([
                'password' => __('auth.update.failed'),
            ]);
        }
    }
}
