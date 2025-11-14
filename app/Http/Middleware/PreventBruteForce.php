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

    private const API_PREFIX = 'api/*';
    private const REQUIRED_CONFIG_KEYS = [
        'login_max_attempts',
        'login_decay_minutes',
        'api_requests_per_minute',
        'general_requests_per_minute',
    ];

    private const DEFAULT_LIMITS = [
        'login_max_attempts' => 5,
        'login_decay_minutes' => 15,
        'api_requests_per_minute' => 60,
        'general_requests_per_minute' => 100,
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
        $config = config('security.rate_limiting', []);
        if ($config === []) {
            Log::warning('PreventBruteForce: missing security.rate_limiting config, falling back to defaults');
        }

        $this->rateLimitConfig = array_merge(self::DEFAULT_LIMITS, $config);

        $this->validateRateLimitConfig();
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
     * @param Request $request Solicitud HTTP entrante. Nota: incluso para métodos SAFE se
     *                        verifica el límite; la diferencia es que no consumen crédito.
     * @param Closure $next    Siguiente manejador del pipeline de middleware.
     * @return Response        Respuesta HTTP (posible 429 si superó el límite).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isAuthRoute = $this->isAuthRoute($request);

        $limits = $this->getLimitsForRoute($request, $isAuthRoute);
        $key = $this->resolveRequestSignature($request, $limits['scope']);

        if (RateLimiter::tooManyAttempts($key, $limits['max_attempts'])) {
            $this->logBruteForceAttempt($request, $key);
            return $this->createRateLimitResponse($request, $key);
        }

        RateLimiter::hit($key, $limits['decay_minutes'] * 60);

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

        if ($request->is(self::API_PREFIX)) {
            $perMinute = (int) ($this->rateLimitConfig['api_requests_per_minute'] ?? 60);
            return [
                'max_attempts' => $perMinute,
                'decay_minutes' => 1,
                'scope' => 'api',
            ];
        }

        $generalPerMinute = (int) ($this->rateLimitConfig['general_requests_per_minute'] ?? 100);
        $routeFingerprint = strtolower($request->method()) . ':' . $this->normalizePathForRateLimit($request);

        return [
            'max_attempts' => $generalPerMinute,
            'decay_minutes' => 1,
            'scope' => 'general:' . $routeFingerprint,
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
        $route = $request->route();
        if ($route === null) {
            return false;
        }

        $name = $route->getName();
        if (is_string($name) && str_starts_with($name, 'auth.')) {
            return true;
        }

        foreach (self::AUTH_ROUTE_PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcula una firma única por alcance para alimentar al rate limiter.
     *
     * Solo usa la IP; incluir emails permitiría enumeración de cuentas.
     */
    protected function resolveRequestSignature(Request $request, string $scope): string
    {
        $ip = $request->ip();
        if (app()->environment('production') && $this->isSuspiciousIp($ip)) {
            Log::critical('Suspicious client IP detected for rate limiter', [
                'ip' => $ip,
                'headers' => $request->headers->all(),
            ]);
        }

        return $scope . ':' . hash('sha256', $ip);
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
            'url' => $request->url(),
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

        if ($request->expectsJson() || $request->is(self::API_PREFIX)) {
            return response()->json([
                'message' => $message,
                'retry_after' => $retryAfter,
                'error_code' => 'RATE_LIMIT_EXCEEDED',
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $response = $this->redirectBackWithError($message, $request);
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }

    /**
     * Genera un redirect con mensaje de error conservando el input previo.
     *
     * @param string $message Mensaje para el usuario (flash key `error`).
     * @return Response       RedirectResponse (subtipo de Response) a la URL previa.
     */
    protected function redirectBackWithError(string $message, Request $request): Response
    {
        $fallback = $this->safePreviousUrl($request);

        return redirect()->back(fallback: $fallback)
            ->with('error', $message)
            ->withInput();
    }

    /**
     * Devuelve una URL previa segura (mismo host) o fallback al home.
     */
    private function safePreviousUrl(Request $request): string
    {
        $previous = url()->previous();
        $appUrl = config('app.url');

        if (!is_string($previous) || $previous === '' || !is_string($appUrl) || $appUrl === '') {
            return url('/');
        }

        $parsedPrevious = parse_url($previous);
        $parsedApp = parse_url($appUrl);
        if ($parsedPrevious === false || $parsedApp === false || $parsedPrevious === null || $parsedApp === null) {
            return url('/');
        }

        $previousHost = $parsedPrevious['host'] ?? null;
        $appHost = $parsedApp['host'] ?? null;
        if ($previousHost === null || $appHost === null || strcasecmp($previousHost, $appHost) !== 0) {
            return url('/');
        }

        $previousScheme = $parsedPrevious['scheme'] ?? null;
        $appScheme = $parsedApp['scheme'] ?? null;
        if ($previousScheme !== null && $appScheme !== null && strcasecmp($previousScheme, $appScheme) !== 0) {
            return url('/');
        }

        $path = $parsedPrevious['path'] ?? '/';
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return url('/');
        }

        return $previous;
    }

    /**
     * Normaliza un path para rate limiting (IDs -> {id}, caótico -> minúsculas).
     */
    private function normalizePathForRateLimit(Request $request): string
    {
        $route = $request->route();
        if ($route && is_string($route->getName()) && $route->getName() !== '') {
            return strtolower($route->getName());
        }

        $path = trim($request->path(), '/');
        if ($path === '') {
            return 'root';
        }

        $segments = array_filter(explode('/', $path), static fn ($segment) => $segment !== '');
        $normalizedSegments = array_map(static function (string $segment): string {
            if (ctype_digit($segment)) {
                return '{id}';
            }

            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment)) {
                return '{uuid}';
            }

            if (preg_match('/^[A-Za-z0-9]{20,}$/', $segment)) {
                return '{hash}';
            }

            return $segment;
        }, $segments);

        return strtolower(implode('/', $normalizedSegments));
    }

    /**
     * Detecta IPs sospechosas en producción (rangos reservados o loopback).
     */
    private function isSuspiciousIp(?string $ip): bool
    {
        if (!is_string($ip) || $ip === '') {
            return true;
        }

        $isValidPublicIp = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $isValidPublicIp === false;
    }

    /**
     * Verifica que existan las claves críticas de configuración; emite warning si se usa default.
     */
    private function validateRateLimitConfig(): void
    {
        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            if (!array_key_exists($key, $this->rateLimitConfig)) {
                Log::warning('PreventBruteForce config key missing, using default', [
                    'config_key' => $key,
                ]);
                $this->rateLimitConfig[$key] = self::DEFAULT_LIMITS[$key];
                continue;
            }

            $value = $this->rateLimitConfig[$key];
            if (!is_int($value) || $value <= 0) {
                Log::warning('PreventBruteForce config key has invalid value, using default', [
                    'config_key' => $key,
                    'value' => $value,
                ]);
                $this->rateLimitConfig[$key] = self::DEFAULT_LIMITS[$key];
            }
        }
    }
}
