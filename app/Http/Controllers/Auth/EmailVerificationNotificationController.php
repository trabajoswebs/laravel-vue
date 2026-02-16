<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Controlador para notificaciones de verificación de email.
 * 
 * Maneja el envío de correos de verificación de email
 * a usuarios que aún no han confirmado su dirección.
 */
class EmailVerificationNotificationController extends Controller
{
    /**
     * Envía una nueva notificación de verificación de email.
     *
     * Si el usuario ya tiene email verificado, lo redirige al dashboard.
     * De lo contrario, envía un nuevo enlace de verificación.
     *
     * @param Request $request Solicitud HTTP entrante
     * @return RedirectResponse Redirección con mensaje de confirmación
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));  // Usuario ya verificado
        }

        $request->user()->sendEmailVerificationNotification();  // Envía correo de verificación

        return back()->with('status', 'verification-link-sent')           // Mensaje flash de éxito
            ->with('status_message', __('auth.verification.sent'));       // Mensaje traducido
    }
}
