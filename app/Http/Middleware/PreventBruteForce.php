<?php 

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PreventBruteForce
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $limits = $this->getLimitsForRoute($request);
        $key = $this->resolveRequestSignature($request, $limits['scope']);

        if (RateLimiter::tooManyAttempts($key, $limits['max_attempts'])) {
            $this->logBruteForceAttempt($request, $key);
            return $this->createRateLimitResponse($request, $key);
        }

        RateLimiter::hit($key, $limits['decay_minutes'] * 60);

        return $next($request);
    }

    /**
     * Get rate limits based on route type.
     */
    protected function getLimitsForRoute(Request $request): array
    {
        if ($this->isAuthRoute($request)) {
            return [
                'max_attempts' => 5,
                'decay_minutes' => 15,
                'scope' => 'auth'
            ];
        }

        if ($request->is('api/*')) {
            return [
                'max_attempts' => 60,
                'decay_minutes' => 1,
                'scope' => 'api'
            ];
        }

        return [
            'max_attempts' => 100,
            'decay_minutes' => 1,
            'scope' => 'general'
        ];
    }

    /**
     * Check if request is for authentication routes.
     */
    protected function isAuthRoute(Request $request): bool
    {
        return $request->is('login') ||
               $request->is('register') ||
               $request->is('password/*') ||
               $request->is('forgot-password') ||
               $request->is('reset-password');
    }

    /**
     * Resolve request signature based on scope.
     * FIXED: Replaced SHA-1 with more secure hashing
     */
    protected function resolveRequestSignature(Request $request, string $scope): string
    {
        $baseSignature = $request->ip();

        if ($scope === 'auth') {
            $baseSignature .= '|' . $request->userAgent();

            // Si es login, añade también el email (evita que un atacante bloquee toda la IP)
            if ($request->is('login') && $request->filled('email')) {
                $baseSignature .= '|' . strtolower($request->input('email'));
            }
        }

        // SECURITY FIX: Use SHA-256 instead of SHA-1
        return $scope . ':' . hash('sha256', $baseSignature);
        
        // Alternative secure options:
        // return $scope . ':' . hash('sha3-256', $baseSignature);
        // return $scope . ':' . hash('blake2b', $baseSignature);
    }

    /**
     * Log brute force attempt with detailed information.
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
            'referer' => $request->header('Referer')
        ]);
    }

    /**
     * Create appropriate rate limit response for Inertia/JSON/normal requests.
     */
    protected function createRateLimitResponse(Request $request, string $key): Response
    {
        $retryAfter = RateLimiter::availableIn($key);

        // Usa traducción (resources/lang/es.json) -> "rate_limit" => "Demasiados intentos. Intenta de nuevo en :seconds segundos."
        $message = __('rate_limit', ['seconds' => $retryAfter]);

        // Petición Inertia: usar redirect normal (302) y flash para no romper la navegación
        if ($request->header('X-Inertia')) {
            return redirect()->back()
                ->with('error', $message)
                ->withInput();
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $message,
                'retry_after' => $retryAfter,
                'error_code' => 'RATE_LIMIT_EXCEEDED'
            ], 429);
        }

        return redirect()->back()
            ->with('error', $message)
            ->withInput();
    }
}