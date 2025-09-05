<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class SecurityHeaders
{
    private const ALLOWED_SCHEMES = ['http', 'https', 'ws', 'wss'];
    
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        $isDev = app()->environment(['local', 'development']);
        $frontendUrl = $this->validateUrl(env('APP_FRONTEND_URL', 'http://localhost:3000'));
        $viteUrl = $this->validateUrl(env('VITE_DEV_SERVER_URL', 'http://127.0.0.1:5174'));
        $appUrl = $this->validateUrl(config('app.url'));
        $nonce = $this->generateNonce();
        
        // Construir CSP
        $csp = $this->buildCSP($isDev, $frontendUrl, $viteUrl, $appUrl, $nonce);
        $response->headers->set('Content-Security-Policy', $csp);
        
        // Guardar nonce para uso en vistas
        $request->attributes->set('csp-nonce', $nonce);
        
        // Aplicar otras cabeceras de seguridad
        $this->applySecurityHeaders($response, $request);
        
        return $response;
    }
    
    /**
     * Valida y limpia URLs para evitar inyección de políticas maliciosas
     */
    private function validateUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }
        
        $parsedUrl = parse_url($url);
        
        if ($parsedUrl === false || 
            !isset($parsedUrl['scheme']) || 
            !in_array($parsedUrl['scheme'], self::ALLOWED_SCHEMES)) {
            
            Log::warning("Invalid URL detected in security middleware: {$url}");
            return null;
        }
        
        // Reconstruir URL limpia (solo esquema, host y puerto)
        $cleanUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        if (isset($parsedUrl['port'])) {
            $cleanUrl .= ':' . $parsedUrl['port'];
        }
        
        return $cleanUrl;
    }
    
    private function buildCSP(bool $isDev, ?string $frontendUrl, ?string $viteUrl, ?string $appUrl, string $nonce): string
    {
        if ($isDev) {
            return $this->buildDevelopmentCSP($frontendUrl, $viteUrl, $appUrl);
        }
        
        return $this->buildProductionCSP($appUrl, $nonce);
    }
    
    private function buildDevelopmentCSP(?string $frontendUrl, ?string $viteUrl, ?string $appUrl): string
    {
        // Obtener URLs de Vite validadas
        $viteUrls = $this->getValidatedViteUrls();
        
        // Filtrar URLs válidas
        $validUrls = array_filter([$frontendUrl, $viteUrl, $appUrl]);
        
        $directives = [
            'default-src' => array_merge(["'self'"], $validUrls),
            'script-src' => array_merge(["'self'", "'unsafe-inline'", "'unsafe-eval'"], $validUrls, $viteUrls),
            'style-src' => array_merge(["'self'", "'unsafe-inline'"], $validUrls, $viteUrls, [
                'https://fonts.googleapis.com', 
                'https://fonts.bunny.net'
            ]),
            'img-src' => array_merge(["'self'", 'data:', 'https:'], $validUrls),
            'font-src' => array_merge(["'self'"], $validUrls, [
                'https://fonts.gstatic.com', 
                'https://fonts.bunny.net'
            ]),
            'connect-src' => array_merge(["'self'"], $validUrls, $viteUrls, ['ws:', 'wss:']),
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"]
        ];
        
        return $this->directivesToString($directives);
    }
    
    private function buildProductionCSP(?string $appUrl, string $nonce): string
    {
        $validUrls = array_filter([$appUrl]);
        
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'"],
            'style-src' => array_merge(["'self'", "'nonce-{$nonce}'"], [
                'https://fonts.googleapis.com', 
                'https://fonts.bunny.net'
            ]),
            'img-src' => ["'self'", 'data:', 'https:'],
            'font-src' => array_merge(["'self'"], [
                'https://fonts.gstatic.com', 
                'https://fonts.bunny.net'
            ]),
            'connect-src' => array_merge(["'self'"], $validUrls),
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'upgrade-insecure-requests' => []
        ];
        
        return $this->directivesToString($directives);
    }
    
    /**
     * Obtiene y valida URLs de Vite desde configuración
     */
    private function getValidatedViteUrls(): array
    {
        $viteUrls = config('vite.dev_server_urls', []);
        $validatedUrls = [];
        
        foreach ($viteUrls as $url) {
            $validatedUrl = $this->validateUrl($url);
            if ($validatedUrl) {
                $validatedUrls[] = $validatedUrl;
            }
        }
        
        return $validatedUrls;
    }
    
    private function directivesToString(array $directives): string
    {
        $csp = '';
        
        foreach ($directives as $directive => $sources) {
            if (!is_array($sources)) {
                $sources = [$sources];
            }
            
            if (empty($sources)) {
                $csp .= "{$directive}; ";
            } else {
                // Filtrar valores vacíos y eliminar duplicados
                $filteredSources = array_filter(array_unique($sources), function($source) {
                    return !empty($source) && is_string($source);
                });
                
                if (!empty($filteredSources)) {
                    $csp .= "{$directive} " . implode(' ', $filteredSources) . "; ";
                } else {
                    $csp .= "{$directive}; ";
                }
            }
        }
        
        return rtrim($csp);
    }
    
    private function applySecurityHeaders(Response $response, Request $request): void
    {
        $securityConfig = config('security.security_headers', []);
        
        // HSTS solo en HTTPS con configuración mejorada
        if ($request->isSecure() && ($securityConfig['enable_hsts'] ?? true)) {
            $hstsMaxAge = $securityConfig['hsts_max_age'] ?? 31536000; // 1 año por defecto
            $response->headers->set(
                'Strict-Transport-Security', 
                "max-age={$hstsMaxAge}; includeSubDomains; preload"
            );
        }
        
        // Headers tradicionales
        if ($securityConfig['enable_frame_options'] ?? true) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        
        if ($securityConfig['enable_content_type_options'] ?? true) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }
        
        // X-XSS-Protection: Header deprecado, deshabilitado por defecto
        // Recomendación: usar CSP en su lugar
        if ($securityConfig['enable_xss_protection'] ?? false) {
            // Usar '0' para deshabilitar el filtro XSS del navegador
            // ya que puede interferir con CSP y crear vulnerabilidades
            $response->headers->set('X-XSS-Protection', '0');
        }
        
        // Referrer Policy
        $referrerPolicy = $securityConfig['referrer_policy'] ?? 'strict-origin-when-cross-origin';
        $response->headers->set('Referrer-Policy', $referrerPolicy);
        
        // Permissions Policy
        $response->headers->set('Permissions-Policy', $this->permissionsPolicy());
        
        // Headers modernos Cross-Origin
        if ($securityConfig['enable_corp'] ?? true) {
            $corpPolicy = $securityConfig['corp_policy'] ?? 'same-origin';
            $response->headers->set('Cross-Origin-Resource-Policy', $corpPolicy);
        }
        
        if ($securityConfig['enable_coop'] ?? true) {
            $coopPolicy = $securityConfig['coop_policy'] ?? 'same-origin';
            $response->headers->set('Cross-Origin-Opener-Policy', $coopPolicy);
        }
        
        // COEP es más restrictivo y puede romper funcionalidad
        // Solo habilitar si se necesita específicamente
        if ($securityConfig['enable_coep'] ?? false) {
            $coepPolicy = $securityConfig['coep_policy'] ?? 'require-corp';
            $response->headers->set('Cross-Origin-Embedder-Policy', $coepPolicy);
        }
    }
    
    private function permissionsPolicy(): string
    {
        // Lista completa y actualizada de políticas de permisos
        $policies = [
            'accelerometer=()',
            'autoplay=()',
            'camera=()',
            'cross-origin-isolated=()',
            'display-capture=()',
            'encrypted-media=()',
            'fullscreen=()',
            'geolocation=()',
            'gyroscope=()',
            'keyboard-map=()',
            'magnetometer=()',
            'microphone=()',
            'midi=()',
            'payment=()',
            'picture-in-picture=()',
            'publickey-credentials-get=()',
            'screen-wake-lock=()',
            'sync-xhr=()',
            'usb=()',
            'web-share=()',
            'xr-spatial-tracking=()'
        ];
        
        return implode(', ', $policies);
    }
    
    private function generateNonce(): string
    {
        // Usar bin2hex para evitar caracteres especiales problemáticos
        return bin2hex(random_bytes(16));
    }
}