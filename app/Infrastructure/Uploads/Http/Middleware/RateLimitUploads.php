<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware que aplica un límite de tasa para subidas de imágenes.
 *
 * Este middleware previene la sobrecarga de servicios costosos (como escáneres de virus)
 * limitando el número de subidas permitidas por usuario/IP en un período de tiempo.
 * Utiliza el `RateLimiter` de Laravel para gestionar los intentos.
 */
class RateLimitUploads
{
    /**
     * Maneja una solicitud entrante para aplicar el límite de tasa.
     *
     * @param Request $request La solicitud HTTP entrante.
     * @param Closure(Request): Response $next La función para llamar al siguiente middleware.
     * @return Response La respuesta HTTP, o una respuesta de error 429 si se excede el límite.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Obtiene la configuración del límite de tasa.
        $config = (array) config('image-pipeline.rate_limit', []);
        $maxAttempts = (int) ($config['max_attempts'] ?? 10);    // Intentos máximos permitidos.
        $decaySeconds = (int) ($config['decay_seconds'] ?? 60); // Tiempo en segundos antes de que los intentos expiren.

        // Si no hay límite configurado, permite la solicitud.
        if ($maxAttempts <= 0) {
            return $next($request);
        }

        // Genera una clave única para el usuario o IP.
        $userId = optional($request->user())->getAuthIdentifier();
        $key = 'img_upload:' . ($userId !== null ? $userId : 'guest:' . $request->ip());

        // Verifica si se han excedido los intentos permitidos.
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            // Mensaje de error para el límite de tasa.
            $message = __('validation.custom.image.rate_limit') ?: __('errors.rate_limit_wait', ['seconds' => $decaySeconds]);

            if ($request->expectsJson()) {
                // Si la solicitud espera JSON, responde con un JSON y código 429.
                return new JsonResponse([
                    'message' => $message,
                    'errors' => [
                        'upload' => [$message],
                    ],
                ], 429);
            }

            // Si no, lanza una excepción de validación con código 429.
            $exception = ValidationException::withMessages(['upload' => $message]);
            $exception->status = 429;

            throw $exception;
        }

        // Registra el intento actual.
        RateLimiter::hit($key, $decaySeconds);

        // Permite que la solicitud continúe.
        return $next($request);
    }
}
