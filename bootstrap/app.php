<?php

use App\Helpers\SecurityHelper;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventBruteForce;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UserAudit;
use App\Http\Middleware\TrustProxies;
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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(TrustProxies::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            SanitizeInput::class,                 // cuanto antes mejor
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,               // tras Inertia/Link headers
            PreventBruteForce::class,
            UserAudit::class,                     //al final para registrar lo que pasó
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->context(function (Throwable $e, array $context) {
            try {
                $request = app()->bound('request') ? app('request') : null;
            } catch (Throwable) {
                $request = null;
            }

            if (! $request instanceof HttpRequest) {
                return $context;
            }

            $errorId = $request->attributes->get('error_id');

            if (! $errorId) {
                $errorId = Str::uuid()->toString();
                $request->attributes->set('error_id', $errorId);
            }

            return array_filter([
                'error_id' => $errorId,
                'route' => optional($request->route())->getName(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'user_id' => optional($request->user())->id,
                // Hash de IP para correlacionar incidentes sin almacenar direcciones originales
                'ip_hash' => SecurityHelper::hashIp((string) $request->ip()),
            ]) + $context;
        });

        $exceptions->render(function (Throwable $e, HttpRequest $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $status = $status >= 400 ? $status : 500;

            $errorId = $request->attributes->get('error_id') ?? Str::uuid()->toString();
            $request->attributes->set('error_id', $errorId);

            return new JsonResponse([
                'message' => trans('errors.unhandled_exception'),
                'error_id' => $errorId,
            ], $status);
        });

        $exceptions->respond(function (Response $response, Throwable $e, HttpRequest $request): Response|RedirectResponse {
            if ($response->getStatusCode() === 419) {
                return back()->with([
                    'message' => 'The page expired, please try again.',
                ]);
            }

            if ($response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS) {
                $message = trans('errors.rate_limit_exceeded');

                if ($request->expectsJson()) {
                    return new JsonResponse([
                        'message' => $message,
                        'retry_after' => $response->headers->get('Retry-After'),
                    ], Response::HTTP_TOO_MANY_REQUESTS, $response->headers->all());
                }

                return back()->withInput()
                    ->with('error', $message);
            }

            if ($request->attributes->has('error_id') && $response->getStatusCode() >= 500) {
                $response->headers->set('X-Error-Id', $request->attributes->get('error_id'));
            }

            return $response;
        });
    })
    ->create();
