<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        $isDev = app()->environment(['local', 'development']);
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $appUrl = config('app.url');
        $nonce = $this->generateNonce();
        
        // Construir CSP
        $csp = $this->buildCSP($isDev, $frontendUrl, $appUrl, $nonce);
        $response->headers->set('Content-Security-Policy', $csp);
        
        // Guardar nonce para uso en vistas
        $request->attributes->set('csp-nonce', $nonce);
        
        // Aplicar otras cabeceras de seguridad
        $this->applySecurityHeaders($response, $request);
        
        return $response;
    }
    
    private function buildCSP(bool $isDev, string $frontendUrl, string $appUrl, string $nonce): string
    {
        // Configuración base desde config/security.php
        $cspConfig = config('security.csp', []);
        
        if ($isDev) {
            // CSP permisivo para desarrollo
            return $this->buildDevelopmentCSP($frontendUrl, $appUrl, $cspConfig);
        }
        
        // CSP estricto para producción
        return $this->buildProductionCSP($appUrl, $nonce, $cspConfig);
    }
    
    private function buildDevelopmentCSP(string $frontendUrl, string $appUrl, array $cspConfig): string
    {
        $defaultDirectives = [
            'default-src' => ["'self'", $frontendUrl],
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", $frontendUrl],
            'style-src' => ["'self'", "'unsafe-inline'", $frontendUrl, 'https://fonts.googleapis.com'],
            'img-src' => ["'self'", 'data:', 'https:', $frontendUrl],
            'font-src' => ["'self'", 'https://fonts.gstatic.com', $frontendUrl],
            'connect-src' => ["'self'", $frontendUrl, $appUrl, 'ws:', 'wss:'],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"]
        ];
        
        // Merge con configuración personalizada
        $directives = array_merge($defaultDirectives, $cspConfig);
        
        return $this->directivesToString($directives);
    }
    
    private function buildProductionCSP(string $appUrl, string $nonce, array $cspConfig): string
    {
        $defaultDirectives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'"],
            'style-src' => ["'self'", "'nonce-{$nonce}'", 'https://fonts.googleapis.com'],
            'img-src' => ["'self'", 'data:', 'https:'],
            'font-src' => ["'self'", 'https://fonts.gstatic.com'],
            'connect-src' => ["'self'", $appUrl],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'upgrade-insecure-requests' => []
        ];
        
        // Merge con configuración personalizada
        $directives = array_merge($defaultDirectives, $cspConfig);
        
        return $this->directivesToString($directives);
    }
    
    private function directivesToString(array $directives): string
    {
        $csp = '';
        
        foreach ($directives as $directive => $sources) {
            // Asegurar que sources sea siempre un array
            if (!is_array($sources)) {
                $sources = [$sources];
            }
            
            if (empty($sources)) {
                // Directiva sin fuentes (como upgrade-insecure-requests)
                $csp .= "{$directive}; ";
            } else {
                // Filtrar valores vacíos y convertir a string
                $filteredSources = array_filter($sources, function($source) {
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
        
        // HSTS solo en HTTPS
        if ($request->isSecure() && ($securityConfig['enable_hsts'] ?? true)) {
            $response->headers->set(
                'Strict-Transport-Security', 
                'max-age=31536000; includeSubDomains; preload'
            );
        }
        
        if ($securityConfig['enable_frame_options'] ?? true) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        
        if ($securityConfig['enable_content_type_options'] ?? true) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }
        
        if ($securityConfig['enable_xss_protection'] ?? true) {
            $response->headers->set('X-XSS-Protection', '1; mode=block');
        }
        
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', $this->permissionsPolicy());
    }
    
    private function permissionsPolicy(): string
    {
        $policies = [
            'geolocation=()', 'microphone=()', 'camera=()', 'payment=()', 'usb=()',
            'magnetometer=()', 'gyroscope=()', 'accelerometer=()', 'ambient-light-sensor=()',
            'autoplay=()', 'battery=()', 'display-capture=()', 'document-domain=()',
            'encrypted-media=()', 'execution-while-not-rendered=()', 'execution-while-out-of-viewport=()',
            'fullscreen=()', 'picture-in-picture=()'
        ];
        
        return implode(', ', $policies);
    }
    
    private function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }
}