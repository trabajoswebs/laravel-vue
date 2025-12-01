<?php

namespace App\Infrastructure\Http\Controllers\Auth;

use App\Infrastructure\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controlador para confirmación de contraseña.
 * 
 * Requiere que el usuario confirme su contraseña antes de acceder
 * a operaciones sensibles protegidas por seguridad adicional.
 */
class ConfirmablePasswordController extends Controller
{
    /**
     * Muestra la página de confirmación de contraseña.
     *
     * @return Response Vista de confirmación de contraseña
     */
    public function show(): Response
    {
        return Inertia::render('auth/ConfirmPassword');
    }

    /**
     * Confirma la contraseña del usuario.
     *
     * Valida las credenciales del usuario y marca la contraseña como confirmada
     * en la sesión para operaciones sensibles posteriores.
     *
     * @param Request $request Solicitud HTTP con la contraseña
     * @return RedirectResponse Redirección a la página de destino o dashboard
     */
    public function store(Request $request): RedirectResponse
    {
        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,     // Email del usuario autenticado
            'password' => $request->password,      // Contraseña proporcionada
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.login.password'),  // Mensaje de error de validación
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());  // Marca confirmación en sesión

        return redirect()->intended(route('dashboard', absolute: false));  // Redirige al dashboard o página de destino
    }
}
