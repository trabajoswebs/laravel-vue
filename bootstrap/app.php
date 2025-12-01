<?php

// Importaciones de clases y namespaces necesarios para la configuración de la aplicación.
use App\Infrastructure\Security\SecurityHelper;
use App\Infrastructure\Http\Middleware\HandleAppearance;
use App\Infrastructure\Http\Middleware\HandleInertiaRequests;
use App\Infrastructure\Http\Middleware\PreventBruteForce;
use App\Infrastructure\Http\Middleware\RateLimitUploads;
use App\Infrastructure\Http\Middleware\SanitizeInput;
use App\Infrastructure\Http\Middleware\SecurityHeaders;
use App\Infrastructure\Http\Middleware\UserAudit;
use App\Infrastructure\Http\Middleware\TrustProxies;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response; //OJO: es la interfaz base que implementan todas las respuestas en Laravel
//(Illuminate\Http\Response, Illuminate\Http\JsonResponse, Illuminate\Http\RedirectResponse, etc.).
//Así evitamos errores de tipado cuando el callback recibe un JsonResponse o un RedirectResponse
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Configura y crea la instancia de la aplicación Laravel.
 *
 * Este archivo define:
 * - Las rutas de la aplicación (web, consola, salud).
 * - Los middleware globales y web.
 * - La lógica para manejar y enriquecer el contexto de las excepciones.
 * - La lógica para renderizar respuestas de error personalizadas, especialmente para solicitudes JSON.
 * - La lógica para responder a códigos de estado específicos (como 419 o 429).
 *
 * @return Application Instancia configurada de la aplicación Laravel.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Define las rutas principales de la aplicación.
        web: __DIR__ . '/../routes/web.php',      // Rutas web (controladores HTTP).
        commands: __DIR__ . '/../routes/console.php', // Comandos de Artisan.
        health: '/up',                            // Endpoint de estado de salud de la aplicación.
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Middleware globales (se ejecutan en todas las solicitudes).
        $middleware->prepend(TrustProxies::class); // Debe estar al inicio para confiar en encabezados de proxies.

        // Cookies que no deben ser encriptadas.
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Middleware web (se ejecutan solo en rutas web).
        $middleware->web(append: [
            HandleAppearance::class,              // Maneja la apariencia del usuario (tema claro/oscuro).
            SanitizeInput::class,                 // Sanitiza la entrada del usuario lo antes posible.
            HandleInertiaRequests::class,         // Maneja las solicitudes de Inertia.js.
            AddLinkHeadersForPreloadedAssets::class, // Agrega encabezados para pre-carga de assets.
            SecurityHeaders::class,               // Aplica encabezados de seguridad HTTP.
            PreventBruteForce::class,             // Prevención de ataques de fuerza bruta.
            UserAudit::class,                     // Registra auditoría de usuario al final del ciclo.
        ]);

        // Aliases de middleware usados en rutas.
        $middleware->alias([
            'auth' => Authenticate::class,
            'throttle' => ThrottleRequests::class,
            'rate.uploads' => RateLimitUploads::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Enriquece el contexto de los logs de errores con información útil.
        $exceptions->context(function (Throwable $e, array $context) {
            try {
                // Obtiene la solicitud actual desde el contenedor de servicios.
                $request = app()->bound('request') ? app('request') : null;
            } catch (Throwable) {
                // En caso de error al obtener la solicitud, se ignora.
                $request = null;
            }

            // Solo enriquece el contexto si hay una solicitud válida.
            if (! $request instanceof HttpRequest) {
                return $context;
            }

            // Genera o recupera un ID único para la solicitud/error si no existe.
            $errorId = $request->attributes->get('error_id');
            if (! $errorId) {
                $errorId = Str::uuid()->toString();
                $request->attributes->set('error_id', $errorId);
            }

            // Devuelve el contexto enriquecido.
            return array_filter([
                'error_id' => $errorId,
                'route' => optional($request->route())->getName(), // Nombre de la ruta, si aplica.
                'url' => $request->fullUrl(),                      // URL completa de la solicitud.
                'method' => $request->method(),                    // Método HTTP (GET, POST, etc.).
                'user_id' => optional($request->user())->id,      // ID del usuario autenticado, si aplica.
                // Hash de IP para correlacionar incidentes sin almacenar direcciones originales.
                'ip_hash' => SecurityHelper::hashIp((string) $request->ip()),
            ]) + $context; // Combina el contexto enriquecido con el original.
        });

        // Personaliza la respuesta de error para solicitudes que esperan JSON.
        $exceptions->render(function (Throwable $e, HttpRequest $request) {
            // Si la solicitud no espera JSON, Laravel manejará la respuesta normalmente.
            if (! $request->expectsJson()) {
                return null;
            }

            // Determina el código de estado HTTP.
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $status = $status >= 400 ? $status : 500; // Asegura que sea al menos 400.

            // Obtiene o genera un ID de error para la solicitud.
            $errorId = $request->attributes->get('error_id') ?? Str::uuid()->toString();
            $request->attributes->set('error_id', $errorId);

            // Devuelve una respuesta JSON con el mensaje de error y el ID.
            return new JsonResponse([
                'message' => trans('errors.unhandled_exception'), // Mensaje traducido de error genérico.
                'error_id' => $errorId,
            ], $status);
        });

        // Maneja respuestas específicas para códigos de estado comunes.
        $exceptions->respond(function (Response $response, Throwable $e, HttpRequest $request): Response|RedirectResponse {
            // Redirige con mensaje si la página expiró (419).
            if ($response->getStatusCode() === 419) {
                return back()->with([
                    'message' => trans('errors.page_expired'),
                ]);
            }

            // Maneja el límite de tasa (429).
            if ($response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS) {
                $message = trans('errors.rate_limit_exceeded'); // Mensaje traducido de límite excedido.

                // Si la solicitud espera JSON, responde con JSON.
                if ($request->expectsJson()) {
                    return new JsonResponse([
                        'message' => $message,
                        'retry_after' => $response->headers->get('Retry-After'), // Tiempo para reintentar.
                    ], Response::HTTP_TOO_MANY_REQUESTS, $response->headers->all());
                }

                // Si no, redirige con el mensaje de error.
                return back()->withInput()
                    ->with('error', $message);
            }

            // Agrega el ID de error como encabezado si es un error 5xx.
            if ($request->attributes->has('error_id') && $response->getStatusCode() >= 500) {
                $response->headers->set('X-Error-Id', $request->attributes->get('error_id'));
            }

            // Devuelve la respuesta original (posiblemente modificada).
            return $response;
        });
    })
    ->create();
