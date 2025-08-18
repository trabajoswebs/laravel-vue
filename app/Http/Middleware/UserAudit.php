<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UserAudit
{
    private array $criticalActions;
    private array $excludedRoutes;
    private array $sensitiveFields;
    private float $sampleRate;
    private string $defaultChannel;
    private string $securityChannel;

    public function __construct()
    {
        // Validar que los valores críticos estén disponibles
        $this->criticalActions  = config('audit.critical_actions', []);
        $this->excludedRoutes   = config('audit.excluded_routes', []);
        $this->sensitiveFields  = config('audit.sensitive_fields', []);
        
        // Validar sample_rate
        $sampleRate = config('audit.sample_rate', 0.01);
        $this->sampleRate = max(0, min(1, (float) $sampleRate)); // Entre 0 y 1
        
        $this->defaultChannel   = config('audit.channels.default', 'daily');
        $this->securityChannel  = config('audit.channels.security', 'security');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (Auth::check() && $this->shouldAudit($request, $response)) {
            $this->logUserAction($request, $response);
        }

        return $response;
    }

    protected function shouldAudit(Request $request, Response $response): bool
    {
        $routeSignature = strtoupper($request->method()) . ':' . $request->path();

        if ($this->matchesPattern($routeSignature, $this->excludedRoutes)) {
            return false;
        }

        if ($this->matchesPattern($routeSignature, $this->criticalActions)) {
            return true;
        }

        if ($response->getStatusCode() >= 400) {
            return true;
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return true;
        }

        if ($request->method() === 'GET') {
            return $this->shouldSample($request);
        }

        return false;
    }

    protected function shouldSample(Request $request): bool
    {
        return mt_rand() / mt_getrandmax() < $this->sampleRate;
    }

    protected function matchesPattern(string $route, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $route)) {
                return true;
            }
        }
        return false;
    }

    protected function logUserAction(Request $request, Response $response): void
    {
        $user = Auth::user();
        $statusCode = $response->getStatusCode();
        $level = $this->getLogLevel($request, $statusCode);

        $auditData = [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'empresa_id' => $user->empresa_id ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent(), 0, 200),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route' => $request->route()?->getName(),
            'status_code' => $statusCode,
            'timestamp' => now()->toISOString(),
            'request_id' => $request->header('X-Request-ID') ?: uniqid(),
        ];

        if ($this->shouldIncludeRequestData($request, $statusCode)) {
            $auditData['request_data'] = $this->sanitizeRequestData($request);
        }

        Log::channel($this->defaultChannel)->log($level, 'User audit log', $auditData);

        if ($this->isCriticalAction($request)) {
            Log::channel($this->securityChannel)->warning('Critical user action', $auditData);
        }
    }

    protected function getLogLevel(Request $request, int $statusCode): string
    {
        if ($statusCode >= 500) return 'error';
        if ($statusCode >= 400) return 'warning';
        if ($this->isCriticalAction($request)) return 'warning';
        return 'info';
    }

    protected function isCriticalAction(Request $request): bool
    {
        $routeSignature = strtoupper($request->method()) . ':' . $request->path();
        return $this->matchesPattern($routeSignature, $this->criticalActions);
    }

    protected function shouldIncludeRequestData(Request $request, int $statusCode): bool
    {
        return $statusCode >= 400 || $this->isCriticalAction($request);
    }

    protected function sanitizeRequestData(Request $request): array
    {
        $data = $request->all();

        foreach ($this->sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '[REDACTED]';
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value) && count($value) > 10) {
                $data[$key] = array_slice($value, 0, 10) + ['...' => 'truncated'];
            }
            if (is_string($value) && strlen($value) > 500) {
                $data[$key] = substr($value, 0, 500) . '... [truncated]';
            }
        }

        return $data;
    }
}
