<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware PreventBruteForce
 *
 * Limita los intentos repetidos para mitigar ataques de fuerza bruta.
 *
 * Este middleware aplica límites de peticiones en función del contexto
 * (rutas de autenticación, API o rutas generales) y construye una firma
 * única por solicitud para alimentar el RateLimiter de Laravel.
 *
 * Requisitos y consideraciones:
 * - Configurar correctamente \App\Http\Middleware\TrustProxies para resolver la IP real
 *   detrás de balanceadores o CDNs.
 * - Ajustar umbrales en `config/security.php`.
 */
class PreventBruteForce
{
    /**
     * Métodos HTTP idempotentes que no deberían consumir crédito de rate limit.
     *
     * @var array<int, string>
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Patrones de rutas de autenticación que ya se protegen con límites específicos.
     *
     * @var array<int, string>
     */
    private const AUTH_ROUTE_PATTERNS = [
        'login',
        'register',
        'password/*',
        'forgot-password',
        'reset-password',
    ];

    /**
     * Configuración de rate limiting tomada de `config/security.php`.
     *
     * Claves relevantes:
     *  - login_max_attempts: int
     *  - login_decay_minutes: int
     *  - api_requests_per_minute: int
     *  - general_requests_per_minute: int
     *
     * @var array<string, int|string>
     */
    private array $rateLimitConfig;

    /**
     * Constructor: precarga la configuración de límites desde `config/security.php`.
     */
    public function __construct()
    {
        $this->rateLimitConfig = config('security.rate_limiting', []);
    }

    /**
     * Procesa la petición aplicando límites según la ruta y el contexto.
     *
     * Flujo:
     * 1) Omite rutas de autenticación (ya llevan su propio limiter).
     * 2) Determina límites (máximo, decaimiento y scope).
     * 3) Calcula la clave única para el RateLimiter.
     * 4) Si excede el límite → responde 429 (JSON o redirect con flash).
     * 5) Si procede, consume crédito de rate limit.
     *
     * @param Request $request Solicitud HTTP entrante.
     * @param Closure $next    Siguiente manejador del pipeline de middleware.
     * @return Response        Respuesta HTTP (posible 429 si superó el límite).
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Evitar solapamiento: las rutas de auth usan su propio rate limit (LoginRequest)
        $isAuthRoute = $this->isAuthRoute($request);
        if ($isAuthRoute) {
            return $next($request);
        }

        $limits = $this->getLimitsForRoute($request, $isAuthRoute);
        $key = $this->resolveRequestSignature($request, $limits['scope']);

        if (RateLimiter::tooManyAttempts($key, $limits['max_attempts'])) {
            $this->logBruteForceAttempt($request, $key);
            return $this->createRateLimitResponse($request, $key);
        }

        if ($this->shouldCountAttempt($request)) {
            RateLimiter::hit($key, $limits['decay_minutes'] * 60);
        }

        return $next($request);
    }

    /**
     * Devuelve los límites de peticiones según el tipo de ruta.
     *
     * - Rutas de auth: valores de `login_*`.
     * - API: `api_requests_per_minute` y ventana de 1 minuto.
     * - General: `general_requests_per_minute` y ventana de 1 minuto; el scope
     *   se segmenta por método + path (hash) para granularidad por endpoint.
     *
     * @param Request $request     Petición HTTP actual.
     * @param bool    $isAuthRoute Indica si la ruta es de autenticación.
     * @return array{max_attempts:int, decay_minutes:int, scope:string} Estructura de límites y su ámbito.
     */
    protected function getLimitsForRoute(Request $request, bool $isAuthRoute): array
    {
        if ($isAuthRoute) {
            return [
                'max_attempts' => (int) ($this->rateLimitConfig['login_max_attempts'] ?? 5),
                'decay_minutes' => (int) ($this->rateLimitConfig['login_decay_minutes'] ?? 15),
                'scope' => 'auth',
            ];
        }

        if ($request->is('api/*')) {
            $perMinute = (int) ($this->rateLimitConfig['api_requests_per_minute'] ?? 60);
            return [
                'max_attempts' => $perMinute,
                'decay_minutes' => 1,
                'scope' => 'api',
            ];
        }

        $generalPerMinute = (int) ($this->rateLimitConfig['general_requests_per_minute'] ?? 100);
        $path = trim($request->path(), '/');
        $routeFingerprint = strtolower($request->method()) . ':' . ($path === '' ? 'root' : $path);

        return [
            'max_attempts' => $generalPerMinute,
            'decay_minutes' => 1,
            'scope' => 'general:' . hash('sha256', $routeFingerprint),
        ];
    }

    /**
     * Determina si la petición corresponde a rutas de autenticación.
     *
     * @param Request $request Petición HTTP.
     * @return bool            true si el path coincide con algún patrón de AUTH_ROUTE_PATTERNS.
     */
    protected function isAuthRoute(Request $request): bool
    {
        foreach (self::AUTH_ROUTE_PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcula una firma única por alcance (scope) para alimentar al rate limiter.
     *
     * Regla general:
     * - Base: IP del cliente (según TrustProxies).
     * - Scope `auth`: añade User-Agent y, si es login y viene `email`, lo incorpora para granularidad por cuenta.
     * - El resultado final se hashea (sha256) para no exponer datos crudos en claves.
     *
     * @param Request $request Petición HTTP.
     * @param string  $scope   Alcance lógico ("auth"|"api"|"general:*").
     * @return string          Clave estable para RateLimiter::hit/::tooManyAttempts.
     */
    protected function resolveRequestSignature(Request $request, string $scope): string
    {
        $baseSignature = $request->ip();

        if ($scope === 'auth') {
            $baseSignature .= '|' . $request->userAgent();

            // Si es login y se envía email, se añade para evitar bloquear a toda la IP.
            if ($request->is('login') && $request->filled('email')) {
                $baseSignature .= '|' . strtolower($request->input('email'));
            }
        }

        return $scope . ':' . hash('sha256', $baseSignature);
    }

    /**
     * Registra en logs un intento que superó los límites configurados.
     *
     * Incluye información útil para auditoría/forense: IP, UA, URL completa, método
     * HTTP, clave de rate limit, usuario autenticado (si existe), timestamp e
     * información de cabeceras relacionadas con proxy/referer.
     *
     * @param Request $request Petición HTTP.
     * @param string  $key     Clave de rate limiting agotada.
     * @return void
     */
    protected function logBruteForceAttempt(Request $request, string $key): void
    {
        Log::warning('Rate limit exceeded - Possible brute force attack', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'rate_limit_key' => $key,
            'user_id' => $request->user()?->id,
            'timestamp' => now()->toISOString(),
            'forwarded_for' => $request->header('X-Forwarded-For'),
            'referer' => $request->header('Referer'),
        ]);
    }

    /**
     * Construye la respuesta adecuada cuando se supera el límite de peticiones.
     *
     * - Para clientes que esperan JSON o rutas API: responde 429 con payload útil.
     * - Para clientes web (Blade/Inertia): redirige atrás con mensaje flash `error`.
     *
     * @param Request $request Petición HTTP.
     * @param string  $key     Clave usada para calcular el tiempo de reintento.
     * @return Response        Respuesta 429 (JSON) o RedirectResponse (web) con flash.
     */
    protected function createRateLimitResponse(Request $request, string $key): Response
    {
        $retryAfter = RateLimiter::availableIn($key);

        // Traducción en resources/lang: "rate_limit" => "Demasiados intentos. Intenta de nuevo en :seconds segundos."
        $message = __('rate_limit', ['seconds' => $retryAfter]);

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $message,
                'retry_after' => $retryAfter,
                'error_code' => 'RATE_LIMIT_EXCEEDED',
            ], 429);
        }

        return $this->redirectBackWithError($message);
    }

    /**
     * Indica si la petición debe consumir crédito de rate limit.
     *
     * Por defecto, solo consumen crédito los métodos no idempotentes
     * (p.ej., POST/PUT/PATCH/DELETE), excluyendo los definidos en SAFE_METHODS.
     *
     * @param Request $request Petición HTTP.
     * @return bool            true si debe contarse, false en caso contrario.
     */
    protected function shouldCountAttempt(Request $request): bool
    {
        return !in_array($request->method(), self::SAFE_METHODS, true);
    }

    /**
     * Genera un redirect con mensaje de error conservando el input previo.
     *
     * @param string $message Mensaje para el usuario (flash key `error`).
     * @return Response       RedirectResponse (subtipo de Response) a la URL previa.
     */
    protected function redirectBackWithError(string $message): Response
    {
        return redirect()->back()
            ->with('error', $message)
            ->withInput();
    }
}
