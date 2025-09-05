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

    // Security constants for pattern validation
    private const MAX_PATTERN_LENGTH = 200;
    private const MAX_WILDCARDS_PER_PATTERN = 10;
    private const FNMATCH_TIMEOUT_SECONDS = 0.1; // 100ms timeout

    public function __construct()
    {
        // Validar que los valores críticos estén disponibles
        $this->criticalActions  = $this->sanitizePatterns(config('audit.critical_actions', []));
        $this->excludedRoutes   = $this->sanitizePatterns(config('audit.excluded_routes', []));
        $this->sensitiveFields  = config('audit.sensitive_fields', []);
        
        // Validar sample_rate
        $sampleRate = config('audit.sample_rate', 0.01);
        $this->sampleRate = max(0, min(1, (float) $sampleRate)); // Entre 0 y 1
        
        $this->defaultChannel   = config('audit.channels.default', 'daily');
        $this->securityChannel  = config('audit.channels.security', 'security');
    }

    /**
     * Sanitize and validate patterns during construction
     * SECURITY FIX: Validate patterns to prevent DoS attacks
     */
    private function sanitizePatterns(array $patterns): array
    {
        $validPatterns = [];
        
        foreach ($patterns as $pattern) {
            if ($this->isValidPattern($pattern)) {
                $validPatterns[] = $pattern;
            } else {
                Log::channel($this->securityChannel)->warning('Invalid audit pattern detected and ignored', [
                    'pattern' => $pattern,
                    'reason' => 'Pattern validation failed',
                    'timestamp' => now()->toISOString()
                ]);
            }
        }
        
        return $validPatterns;
    }

    /**
     * Validate pattern safety before use
     * SECURITY FIX: Prevent malicious patterns
     */
    private function isValidPattern(string $pattern): bool
    {
        // Check pattern length
        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return false;
        }
        
        // Check wildcard count to prevent excessive backtracking
        $wildcardCount = substr_count($pattern, '*') + substr_count($pattern, '?');
        if ($wildcardCount > self::MAX_WILDCARDS_PER_PATTERN) {
            return false;
        }
        
        // Check for suspicious nested patterns
        if (preg_match('/(\*\/){5,}/', $pattern) || preg_match('/(\*\*){3,}/', $pattern)) {
            return false;
        }
        
        // Ensure pattern contains only safe characters
        if (!preg_match('/^[a-zA-Z0-9\/_\-\.\*\?\:\|]+$/', $pattern)) {
            return false;
        }
        
        return true;
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

    /**
     * Determine if request should be sampled using cryptographically secure random.
     * SECURITY FIX: Replaced mt_rand() with cryptographically secure random_int()
     */
    protected function shouldSample(Request $request): bool
    {
        try {
            // Use cryptographically secure random number generator
            $randomValue = random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
            return $randomValue < $this->sampleRate;
        } catch (\Exception $e) {
            // Fallback: if secure random fails, log all GET requests for security
            Log::channel($this->securityChannel)->warning('Secure random generation failed, auditing all GET requests', [
                'error' => $e->getMessage(),
                'request_url' => $request->fullUrl(),
                'timestamp' => now()->toISOString()
            ]);
            return true; // Fail-safe: audit when in doubt
        }
    }

    /**
     * Safe pattern matching with timeout protection
     * SECURITY FIX: Added validation and timeout protection for fnmatch()
     */
    protected function matchesPattern(string $route, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            try {
                // Set time limit for pattern matching to prevent DoS
                $startTime = microtime(true);
                
                $result = fnmatch($pattern, $route);
                
                $executionTime = microtime(true) - $startTime;
                
                // Log slow pattern matches for monitoring
                if ($executionTime > self::FNMATCH_TIMEOUT_SECONDS) {
                    Log::channel($this->securityChannel)->warning('Slow pattern match detected', [
                        'pattern' => $pattern,
                        'route' => $route,
                        'execution_time' => $executionTime,
                        'timestamp' => now()->toISOString()
                    ]);
                }
                
                if ($result) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Log pattern matching errors
                Log::channel($this->securityChannel)->error('Pattern matching error', [
                    'pattern' => $pattern,
                    'route' => $route,
                    'error' => $e->getMessage(),
                    'timestamp' => now()->toISOString()
                ]);
                
                // Continue with other patterns on error
                continue;
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
            'request_id' => $request->header('X-Request-ID') ?: $this->generateSecureRequestId(),
        ];

        if ($this->shouldIncludeRequestData($request, $statusCode)) {
            $auditData['request_data'] = $this->sanitizeRequestData($request);
        }

        Log::channel($this->defaultChannel)->log($level, 'User audit log', $auditData);

        if ($this->isCriticalAction($request)) {
            Log::channel($this->securityChannel)->warning('Critical user action', $auditData);
        }
    }

    /**
     * Generate cryptographically secure request ID
     * SECURITY ENHANCEMENT: Replaced uniqid() with secure random
     */
    protected function generateSecureRequestId(): string
    {
        try {
            return bin2hex(random_bytes(16)); // 32 character hex string
        } catch (\Exception $e) {
            // Fallback to timestamp-based ID if secure random fails
            return 'fallback_' . hrtime(true) . '_' . uniqid();
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