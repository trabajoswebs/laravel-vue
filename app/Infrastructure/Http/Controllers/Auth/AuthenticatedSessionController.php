<?php

namespace App\Infrastructure\Http\Controllers\Auth;

use App\Infrastructure\Http\Controllers\Controller;
use App\Infrastructure\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controlador para la gestión de sesiones de autenticación.
 * 
 * Maneja las operaciones de login/logout de usuarios.
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Muestra la página de login.
     *
     * @param Request $request Solicitud HTTP entrante
     * @return Response Vista de login con datos necesarios
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),  // Verifica si hay ruta de reset de contraseña
            'status' => $request->session()->get('status'),        // Mensaje de estado (por ejemplo, contraseña actualizada)
        ]);
    }

    /**
     * Maneja una solicitud de autenticación entrante.
     *
     * @param LoginRequest $request Solicitud de login validada
     * @return RedirectResponse Redirección a la página de destino o dashboard
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();  // Autentica al usuario con las credenciales validadas

        $request->session()->regenerate();  // Regenera la sesión para seguridad

        return redirect()->intended(route('dashboard', absolute: false));  // Redirige al dashboard o página de destino
    }

    /**
     * Destruye una sesión autenticada.
     *
     * @param Request $request Solicitud HTTP entrante
     * @return RedirectResponse Redirección a la página principal
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();  // Cierra la sesión de autenticación

        $request->session()->invalidate();       // Invalida la sesión actual
        $request->session()->regenerateToken();  // Regenera el token CSRF

        return redirect('/');  // Redirige a la página principal
    }
}
