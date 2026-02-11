<?php

namespace App\Infrastructure\Http\Middleware;

use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de refuerzo de cabeceras de seguridad HTTP con CSP dinámico.
 * 
 * Genera y aplica Content Security Policy (CSP) adaptado al entorno de ejecución,
 * permitiendo tooling de desarrollo (Vite, HMR) en local mientras endurece la
 * configuración en producción con nonces criptográficos y políticas restrictivas.
 * 
 * Características principales:
 * - CSP diferenciado por entorno (desarrollo vs producción)
 * - Generación de nonce único por request para scripts/estilos inline
 * - Validación estricta de URLs para prevenir inyección de dominios
 * - Headers modernos: HSTS, CORP, COOP, COEP, Permissions-Policy
 * - Fallback seguro ante errores (CSP mínimo garantizado)
 * - Logging condicional para debugging sin overhead en producción
 * - Configuración externalizada y flexible
 * 
 * Configuración requerida en config/security.php:
 * ```php
 * return [
 *     'csp' => [
 *         'development' => [...], // Directivas CSP para desarrollo
 *         'production' => [...],  // Directivas CSP para producción
 *         'report_uri' => 'https://...', // Endpoint para reportes CSP
 *         'report_to' => 'csp-endpoint', // Grupo de reporting
 *     ],
 *     'security_headers' => [
 *         'enable_hsts' => true,
 *         'hsts_max_age' => 31536000,
 *         'enable_frame_options' => true,
 *         'enable_content_type_options' => true,
 *         'enable_xss_protection' => false,
 *         'referrer_policy' => 'strict-origin-when-cross-origin',
 *         'permissions_policy' => [...], // Array o string
 *         'enable_corp' => true,
 *         'corp_policy' => 'same-origin',
 *         'enable_coop' => true,
 *         'coop_policy' => 'same-origin',
 *         'enable_coep' => false,
 *         'coep_policy' => 'require-corp',
 *     ],
 *     'debug_csp' => false, // Habilitar logging detallado
 * ];
 * ```
 * 
 * Variables de entorno requeridas:
 * - APP_FRONTEND_URL: URL del frontend externo (si aplica)
 * - VITE_DEV_SERVER_URL: URL del servidor Vite en desarrollo
 * - APP_URL: URL base de la aplicación
 * 
 * Uso del nonce en templates Blade:
 * ```blade
 * <script nonce="{{ request()->attributes->get('csp-nonce') }}">
 *     // Tu código inline aquí
 * </script>
 * ```
 * 
 * @package App\Http\Middleware
 * @author  Tu Nombre
 * @version 2.0.0
 * @since   1.0.0
 * 
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Permissions-Policy
 * @see https://owasp.org/www-project-secure-headers/
 */
class SecurityHeaders
{
    private ?MediaSecurityLogger $securityLogger = null;
    private ?MediaLogSanitizer $logSanitizer = null;

    /**
     * Esquemas URI permitidos al validar URLs de configuración.
     * 
     * En entornos locales se permite http/ws para tooling (Vite, HMR).
     * En producción solo se aceptan https/wss para evitar downgrades.
     * Previene inyección de esquemas peligrosos como file://, data://, javascript:
     * 
     * @var array<int, string>
     */
    private const ALLOWED_DEV_SCHEMES = ['http', 'https', 'ws', 'wss'];

    /**
     * Esquemas permitidos cuando la aplicación corre en producción.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PROD_SCHEMES = ['https', 'wss'];

    /**
     * Directivas por defecto para Permissions-Policy cuando no hay configuración.
     * 
     * Política altamente restrictiva que desactiva todas las APIs sensibles
     * del navegador por defecto. Configurar explícitamente en config/security.php
     * para habilitar funcionalidades específicas si la aplicación las requiere.
     * 
     * @var array<int, string>
     * 
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Permissions-Policy
     */
    private const DEFAULT_PERMISSIONS_POLICY = [
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

    /**
     * Content Security Policy mínima aplicada como fallback en caso de error.
     * 
     * Garantiza protección básica incluso si falla la generación del CSP completo.
     * Solo permite recursos del mismo origen (same-origin), bloqueando cualquier
     * contenido externo o inline no autorizado.
     * 
     * @var string
     */
    private const FALLBACK_CSP = "default-src 'self';";

    /**
     * Procesa la request aplicando headers de seguridad y CSP a la response.
     * 
     * Flujo de ejecución:
     * 1. Ejecuta siguiente middleware/controlador para obtener response
     * 2. Carga configuración y detecta entorno (dev/prod)
     * 3. Valida URLs de configuración (frontend, vite, app)
     * 4. Genera nonce criptográfico único
     * 5. Construye CSP apropiado al entorno (con try-catch)
     * 6. Aplica CSP al header de response
     * 7. Almacena nonce en request attributes para templates
     * 8. Aplica headers adicionales de seguridad (con try-catch)
     * 9. Registra debug info si está habilitado
     * 10. Retorna response con todos los headers aplicados
     * 
     * Manejo de errores:
     * - Si falla CSP: aplica FALLBACK_CSP y logea error
     * - Si fallan otros headers: logea error pero continúa
     * - Nunca falla completamente, siempre retorna response
     * 
     * @param Request $request Petición HTTP entrante
     * @param Closure $next Siguiente middleware en el stack
     * @return Response Respuesta HTTP con headers de seguridad aplicados
     * 
     * @throws \Throwable Capturado internamente, nunca propaga
     * 
     * @example
     * // El middleware se registra globalmente en Kernel.php:
     * protected $middleware = [
     *     \App\Http\Middleware\SecurityHeaders::class,
     * ];
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $securityConfig = $this->securityConfig();
        $isDev = app()->environment(['local', 'development']);
        $frontendUrl = $this->validateUrl((string) config('security.frontend_url', 'http://localhost:3000'));
        $viteUrl = $this->validateUrl((string) config('vite.dev_server_url', 'http://127.0.0.1:5174'));
        $appUrl = $this->validateUrl(config('app.url'));
        $nonce = $this->generateNonce();

        try {
            $csp = $this->buildCSP($isDev, $frontendUrl, $viteUrl, $appUrl, $nonce);
            $response->headers->set('Content-Security-Policy', $csp);
        } catch (\Throwable $exception) {
            $context = $this->requestLogContext($request, $response, [
                'result' => 'fallback_applied',
                'reason' => 'build_csp_failed',
                'environment' => app()->environment(),
                'exception' => $exception,
            ]);
            $this->securityLogger()->error('http.security_headers.misconfigured', $context);
            $csp = self::FALLBACK_CSP;
            $response->headers->set('Content-Security-Policy', $csp);
        }

        $request->attributes->set('csp-nonce', $nonce);

        try {
            $this->applySecurityHeaders($response, $request, $securityConfig);
        } catch (\Throwable $exception) {
            $this->securityLogger()->error('http.security_headers.misconfigured', $this->requestLogContext($request, $response, [
                'reason' => 'apply_security_headers_failed',
                'environment' => app()->environment(),
                'exception' => $exception,
            ]));
        }

        $this->applyReportToHeader($response);

        $this->logDebug('http.security_headers.applied', $this->requestLogContext($request, $response, [
            'environment' => app()->environment(),
            'csp_hash' => $this->logSanitizer()->hashPath($csp),
            'headers_hash' => $this->logSanitizer()->hashPath(
                json_encode($this->extractSecurityHeaders($response), JSON_UNESCAPED_SLASHES) ?: '{}'
            ),
        ]));

        return $response;
    }

    /**
     * Valida y normaliza una URL de configuración.
     * 
     * Proceso de validación:
     * 1. Verifica que la URL no esté vacía
     * 2. Parsea la URL con parse_url()
     * 3. Valida que tenga esquema válido (http, https, ws, wss)
     * 4. Reconstruye URL limpia con scheme + host + port (si existe)
     * 5. Descarta path, query, fragment para seguridad
     * 
     * URLs inválidas se rechazan y se registra warning para investigación.
     * 
     * @param string|null $url URL a validar (puede ser null)
     * @return string|null URL normalizada o null si es inválida
     * 
     * @example
     * validateUrl('https://example.com:3000/path?query=1#hash')
     * // Retorna: 'https://example.com:3000'
     * 
     * validateUrl('javascript:alert(1)') // Retorna: null (esquema inválido)
     * validateUrl('https://[malformed') // Retorna: null (parse error)
     */
    private function validateUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $parsedUrl = parse_url($url);

        $allowedSchemes = $this->allowedSchemes();

        if ($parsedUrl === false || !isset($parsedUrl['scheme']) || !in_array($parsedUrl['scheme'], $allowedSchemes, true)) {
            $this->securityLogger()->warning('http.security_headers.misconfigured', [
                'reason' => 'invalid_url',
                'parsed' => $parsedUrl,
                'allowed_schemes' => $allowedSchemes,
                'url' => $url,
            ]);
            return null;
        }

        $cleanUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        if (isset($parsedUrl['port'])) {
            $cleanUrl .= ':' . $parsedUrl['port'];
        }

        return $cleanUrl;
    }

    /**
     * Determina los esquemas permitidos según el entorno actual.
     */
    private function allowedSchemes(): array
    {
        if (app()->environment(['local', 'development', 'testing'])) {
            return self::ALLOWED_DEV_SCHEMES;
        }

        return self::ALLOWED_PROD_SCHEMES;
    }

    /**
     * Construye la política CSP apropiada según el entorno de ejecución.
     * 
     * Delega la generación a métodos especializados:
     * - Desarrollo: buildDevelopmentCSP() - Permite unsafe-eval, unsafe-inline, HMR
     * - Producción: buildProductionCSP() - Restrictivo, usa nonces, sin unsafe-*
     * 
     * @param bool $isDev True si está en entorno local/development
     * @param string|null $frontendUrl URL del frontend externo
     * @param string|null $viteUrl URL del servidor Vite
     * @param string|null $appUrl URL base de la aplicación
     * @param string $nonce Nonce único generado para esta request
     * @return string Cadena CSP formateada lista para el header
     * 
     * @throws \Throwable Si falla la construcción del CSP
     * 
     * @see buildDevelopmentCSP()
     * @see buildProductionCSP()
     */
    private function buildCSP(bool $isDev, ?string $frontendUrl, ?string $viteUrl, ?string $appUrl, string $nonce): string
    {
        if ($isDev) {
            return $this->buildDevelopmentCSP($frontendUrl, $viteUrl, $appUrl);
        }

        return $this->buildProductionCSP($appUrl, $nonce);
    }

    /**
     * Genera CSP permisivo para desarrollo local con tooling (Vite, HMR, etc).
     * 
     * Características del CSP de desarrollo:
     * - Permite 'unsafe-inline' y 'unsafe-eval' para HMR de Vite
     * - Incluye URLs dinámicas del frontend y Vite server
     * - Permite websockets (ws:, wss:) para hot reload
     * - Permite 'https:' en img-src para imágenes externas
     * - Incluye CDNs de fuentes (Google Fonts, Bunny Fonts)
     * 
     * ⚠️ ADVERTENCIA: Este CSP NO es seguro para producción.
     * Los directives 'unsafe-inline' y 'unsafe-eval' permiten XSS.
     * 
     * @param string|null $frontendUrl URL del frontend en desarrollo
     * @param string|null $viteUrl URL del servidor Vite (HMR)
     * @param string|null $appUrl URL base de la aplicación
     * @return string CSP formateado para desarrollo
     * 
     * @see https://vitejs.dev/guide/backend-integration.html
     */
    private function buildDevelopmentCSP(?string $frontendUrl, ?string $viteUrl, ?string $appUrl): string
    {
        $directives = config('security.csp.development', []);
        $viteUrls = $this->getValidatedViteUrls();
        $runtimeUrls = array_filter([$frontendUrl, $viteUrl, $appUrl]);

        if (!is_array($directives) || empty($directives)) {
            $directives = [
                'default-src' => ["'self'"],
                'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
                'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com', 'https://fonts.bunny.net'],
                'img-src' => ["'self'", 'data:', 'https:'],
                'font-src' => ["'self'", 'https://fonts.gstatic.com', 'https://fonts.bunny.net'],
                'connect-src' => ["'self'", 'ws:', 'wss:'],
                'frame-ancestors' => ["'none'"],
                'base-uri' => ["'self'"],
                'form-action' => ["'self'"],
            ];
        }

        $directives = $this->appendRuntimeSources($directives, [
            'default-src' => $runtimeUrls,
            'script-src' => array_merge($runtimeUrls, $viteUrls, ["'unsafe-inline'", "'unsafe-eval'"]),
            'style-src' => array_merge($runtimeUrls, $viteUrls),
            'img-src' => $runtimeUrls,
            'font-src' => $runtimeUrls,
            'connect-src' => array_merge($runtimeUrls, $viteUrls, ['ws:', 'wss:']),
        ]);

        return $this->directivesToString($directives);
    }

    /**
     * Genera CSP restrictivo para producción con nonces y sin unsafe-*.
     * 
     * Características del CSP de producción:
     * - Scripts/estilos inline requieren nonce único
     * - NO permite 'unsafe-inline' ni 'unsafe-eval'
     * - Bloquea object-src (previene Flash, Java applets)
     * - frame-ancestors 'none' (previene clickjacking)
     * - upgrade-insecure-requests (convierte HTTP → HTTPS)
     * - report-uri y report-to configurables para monitoreo
     * 
     * Los scripts/estilos inline deben usar el nonce:
     * `<script nonce="{{ request()->attributes->get('csp-nonce') }}">`
     * 
     * @param string|null $appUrl URL base de la aplicación
     * @param string $nonce Nonce criptográfico único para esta request
     * @return string CSP formateado para producción
     * 
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/script-src#unsafe_inline_script
     */
    private function buildProductionCSP(?string $appUrl, string $nonce): string
    {
        $directives = config('security.csp.production', []);

        if (!is_array($directives) || empty($directives)) {
            $appDomain = $this->extractHost($appUrl);

            $directives = [
                'default-src' => ["'self'"],
                'script-src' => ["'self'", "'nonce-{$nonce}'"],
                'style-src' => ["'self'", "'nonce-{$nonce}'", 'https://fonts.googleapis.com', 'https://fonts.bunny.net'],
                'img-src' => array_filter(["'self'", 'data:', $appDomain]),
                'font-src' => ["'self'", 'https://fonts.gstatic.com', 'https://fonts.bunny.net'],
                'connect-src' => array_filter(["'self'", $appUrl]),
                'frame-ancestors' => ["'none'"],
                'base-uri' => ["'self'"],
                'form-action' => ["'self'"],
                'object-src' => ["'none'"],
                'report-uri' => array_filter([config('security.csp.report_uri')]),
                'report-to' => array_filter([config('security.csp.report_to')]),
                'upgrade-insecure-requests' => [],
            ];
        }

        $directives = $this->replaceNoncePlaceholders($directives, $nonce);

        $directives = $this->appendRuntimeSources($directives, [
            'connect-src' => array_filter([$appUrl]),
        ]);

        return $this->directivesToString($directives);
    }

    /**
     * Obtiene y valida URLs del servidor Vite desde configuración.
     * 
     * Lee el array de URLs desde config('vite.dev_server_urls') y valida
     * cada una con validateUrl(). URLs inválidas se descartan silenciosamente.
     * 
     * Útil para configuraciones con múltiples instancias de Vite o
     * servidores de desarrollo adicionales.
     * 
     * @return array<int, string> Array de URLs validadas
     * 
     * @see validateUrl()
     * 
     * @example
     * // En config/vite.php:
     * 'dev_server_urls' => [
     *     'http://localhost:5173',
     *     'http://localhost:5174',
     * ]
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

    /**
     * Convierte array de directivas CSP en string formateado para el header.
     * 
     * Formato de salida:
     * "directive1 source1 source2; directive2 source3; directive3;"
     * 
     * Procesamiento:
     * 1. Itera sobre cada directiva
     * 2. Envuelve sources en array con Arr::wrap()
     * 3. Filtra sources vacíos y no-string
     * 4. Elimina duplicados con array_unique()
     * 5. Une sources con espacios
     * 6. Termina cada directiva con '; '
     * 7. Trim final del string resultante
     * 
     * Directivas sin sources (como upgrade-insecure-requests) se incluyen
     * solo con el nombre y ';'.
     * 
     * @param array<string, mixed> $directives Array asociativo directiva => sources
     * @return string CSP formateado listo para Content-Security-Policy header
     * 
     * @example
     * directivesToString([
     *     'default-src' => ["'self'"],
     *     'script-src' => ["'self'", "'nonce-abc123'"],
     *     'upgrade-insecure-requests' => []
     * ])
     * // Retorna: "default-src 'self'; script-src 'self' 'nonce-abc123'; upgrade-insecure-requests;"
     */
    private function directivesToString(array $directives): string
    {
        $csp = '';

        foreach ($directives as $directive => $sources) {
            $sources = Arr::wrap($sources);

            if (empty($sources)) {
                $csp .= "{$directive}; ";
                continue;
            }

            $filteredSources = array_filter(array_unique($sources), function ($source) {
                return !empty($source) && is_string($source);
            });

            if (!empty($filteredSources)) {
                $csp .= "{$directive} " . implode(' ', $filteredSources) . "; ";
            } else {
                $csp .= "{$directive}; ";
            }
        }

        return rtrim($csp);
    }

    /**
     * Fusiona sources obtenidos en runtime con las directivas base de configuración.
     * 
     * Permite agregar URLs dinámicas (frontend, vite, app) a directivas existentes
     * sin sobrescribir la configuración base. Útil cuando las URLs vienen de
     * variables de entorno o se determinan en runtime.
     * 
     * Proceso:
     * 1. Itera sobre runtime sources por directiva
     * 2. Filtra valores vacíos/null
     * 3. Obtiene sources existentes de la directiva
     * 4. Merge + elimina duplicados con array_unique()
     * 5. Reindexa con array_values()
     * 
     * @param array<string, array<int, string>> $directives Directivas base
     * @param array<string, array<int, string>> $runtimeSources Sources a agregar
     * @return array<string, array<int, string>> Directivas con sources fusionados
     * 
     * @example
     * appendRuntimeSources(
     *     ['script-src' => ["'self'"]],
     *     ['script-src' => ['https://cdn.example.com']]
     * )
     * // Retorna: ['script-src' => ["'self'", 'https://cdn.example.com']]
     */
    private function appendRuntimeSources(array $directives, array $runtimeSources): array
    {
        foreach ($runtimeSources as $directive => $sources) {
            $sources = array_filter(Arr::wrap($sources));

            if (empty($sources)) {
                continue;
            }

            $existing = Arr::wrap($directives[$directive] ?? []);
            $directives[$directive] = array_values(array_unique(array_merge($existing, $sources)));
        }

        return $directives;
    }

    /**
     * Extrae y normaliza el host (scheme + host + port) de una URL.
     * 
     * Similar a validateUrl() pero más permisivo:
     * - No valida esquemas (asume ya validada)
     * - Retorna null si falta scheme o host
     * - Incluye puerto si existe
     * 
     * Usado para generar sources de CSP a partir de URLs completas.
     * 
     * @param string|null $url URL completa
     * @return string|null Host normalizado o null si es inválida
     * 
     * @example
     * extractHost('https://example.com:8080/path?query')
     * // Retorna: 'https://example.com:8080'
     * 
     * extractHost('https://[malformed') // Retorna: null
     */
    private function extractHost(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $parsed = parse_url($url);

        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return null;
        }

        $sanitized = $parsed['scheme'] . '://' . $parsed['host'];

        if (isset($parsed['port'])) {
            $sanitized .= ':' . $parsed['port'];
        }

        return $sanitized;
    }

    /**
     * Reemplaza placeholders de nonce en directivas con el valor real.
     * 
     * Permite definir directivas en configuración con placeholders '{nonce}'
     * que se sustituyen dinámicamente en cada request:
     * 
     * ```php
     * 'script-src' => ["'self'", "'nonce-{nonce}'"]
     * ```
     * 
     * Soporta tanto arrays de sources como strings individuales.
     * Recorre recursivamente todos los valores reemplazando '{nonce}'.
     * 
     * @param array<string, mixed> $directives Directivas con placeholders
     * @param string $nonce Valor del nonce a insertar
     * @return array<string, mixed> Directivas con nonces reales
     * 
     * @example
     * replaceNoncePlaceholders(
     *     ['script-src' => ["'nonce-{nonce}'"]],
     *     'abc123'
     * )
     * // Retorna: ['script-src' => ["'nonce-abc123'"]]
     */
    private function replaceNoncePlaceholders(array $directives, string $nonce): array
    {
        foreach ($directives as $directive => $sources) {
            if (is_array($sources)) {
                $directives[$directive] = array_map(function ($value) use ($nonce) {
                    return is_string($value) ? str_replace('{nonce}', $nonce, $value) : $value;
                }, $sources);
            } elseif (is_string($sources)) {
                $directives[$directive] = str_replace('{nonce}', $nonce, $sources);
            }
        }

        return $directives;
    }

    /**
     * Aplica headers HTTP de seguridad adicionales según configuración.
     * 
     * Headers aplicados (configurables individualmente):
     * 
     * - **Strict-Transport-Security (HSTS)**: Solo en HTTPS, fuerza conexiones seguras
     * - **X-Frame-Options**: Previene clickjacking (DENY por defecto)
     * - **X-Content-Type-Options**: Previene MIME sniffing (nosniff)
     * - **X-XSS-Protection**: Desactivado (0) por defecto (CSP es superior)
     * - **Referrer-Policy**: Controla información de referrer enviada
     * - **Permissions-Policy**: Controla acceso a APIs del navegador
     * - **Cross-Origin-Resource-Policy (CORP)**: Controla carga de recursos
     * - **Cross-Origin-Opener-Policy (COOP)**: Aislamiento de ventanas
     * - **Cross-Origin-Embedder-Policy (COEP)**: Requerimientos de CORP
     * 
     * Todos los headers son opcionales y se aplican solo si están habilitados
     * en la configuración (enable_* flags).
     * 
     * @param Response $response Respuesta HTTP donde aplicar headers
     * @param Request $request Request HTTP para verificar isSecure()
     * @param array<string, mixed> $securityConfig Configuración de security_headers
     * @return void
     * 
     * @throws \Throwable Nunca lanza, pero documenta posibles excepciones internas
     * 
     * @see https://owasp.org/www-project-secure-headers/
     */
    private function applySecurityHeaders(Response $response, Request $request, array $securityConfig): void
    {
        if ($request->isSecure() && ($securityConfig['enable_hsts'] ?? true)) {
            $hstsMaxAge = $securityConfig['hsts_max_age'] ?? 31536000;
            $response->headers->set('Strict-Transport-Security', "max-age={$hstsMaxAge}; includeSubDomains; preload");
        }

        if ($securityConfig['enable_frame_options'] ?? true) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        if ($securityConfig['enable_content_type_options'] ?? true) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        if ($securityConfig['enable_xss_protection'] ?? false) {
            $response->headers->set('X-XSS-Protection', '0');
        }

        $referrerPolicy = $securityConfig['referrer_policy'] ?? 'strict-origin-when-cross-origin';
        $response->headers->set('Referrer-Policy', $referrerPolicy);

        $response->headers->set('Permissions-Policy', $this->permissionsPolicy($securityConfig));

        if ($securityConfig['enable_corp'] ?? true) {
            $corpPolicy = $securityConfig['corp_policy'] ?? 'same-origin';
            $response->headers->set('Cross-Origin-Resource-Policy', $corpPolicy);
        }

        if ($securityConfig['enable_coop'] ?? true) {
            $coopPolicy = $securityConfig['coop_policy'] ?? 'same-origin';
            $response->headers->set('Cross-Origin-Opener-Policy', $coopPolicy);
        }

        if ($securityConfig['enable_coep'] ?? false) {
            $coepPolicy = $securityConfig['coep_policy'] ?? 'require-corp';
            $response->headers->set('Cross-Origin-Embedder-Policy', $coepPolicy);
        }
    }

    /**
     * Configura el header Report-To para que coincida con la directiva CSP report-to.
     *
     * Requiere al menos un grupo (security.csp.report_to) y una lista de endpoints
     * válidos. Si no se proporciona una lista explícita, se utiliza como fallback
     * el valor de security.csp.report_uri.
     */
    private function applyReportToHeader(Response $response): void
    {
        $group = config('security.csp.report_to');

        if (empty($group)) {
            return;
        }

        $endpoints = $this->reportToEndpoints();

        if (empty($endpoints)) {
            return;
        }

        $payload = [
            'group' => $group,
            'max_age' => (int) config('security.csp.report_to_max_age', 10886400),
            'endpoints' => $endpoints,
        ];

        if (config('security.csp.report_to_include_subdomains')) {
            $payload['include_subdomains'] = true;
        }

        $response->headers->set('Report-To', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Construye el valor del header Permissions-Policy con configuración flexible.
     * 
     * Busca la configuración en este orden de prioridad:
     * 1. $securityConfig['permissions_policy'] (pasado como parámetro)
     * 2. config('security.security_headers.permissions_policy')
     * 3. DEFAULT_PERMISSIONS_POLICY (constante de clase)
     * 
     * Formatos soportados:
     * - **String**: Se usa directamente como valor del header
     * - **Array**: Se une con ', ' como separador
     * - **Vacío/null**: Se usa la política restrictiva por defecto
     * 
     * La política por defecto bloquea todas las APIs sensibles del navegador
     * (geolocalización, cámara, micrófono, pagos, etc.). Personaliza en
     * config/security.php según las necesidades de tu aplicación.
     * 
     * @param array<string, mixed> $securityConfig Configuración de security_headers
     * @return string Valor formateado para el header Permissions-Policy
     * 
     * @example
     * // Configuración como string:
     * 'permissions_policy' => 'camera=(self), microphone=(self)'
     * 
     * // Configuración como array:
     * 'permissions_policy' => [
     *     'camera=(self)',
     *     'microphone=(self)',
     *     'geolocation=()'
     * ]
     * 
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Permissions-Policy
     */
    private function permissionsPolicy(array $securityConfig): string
    {
        $configuredPolicy = $securityConfig['permissions_policy'] ?? config('security.security_headers.permissions_policy');

        if (is_string($configuredPolicy) && $configuredPolicy !== '') {
            return $configuredPolicy;
        }

        if (is_array($configuredPolicy) && !empty($configuredPolicy)) {
            return implode(', ', $configuredPolicy);
        }

        return implode(', ', self::DEFAULT_PERMISSIONS_POLICY);
    }

    /**
     * Normaliza los endpoints configurados para Report-To.
     *
     * Acepta arrays o strings (separados por espacios o comas) desde la
     * configuración. Filtra URLs inválidas y obliga a usar HTTPS salvo en
     * entornos locales, donde se permite HTTP para pruebas.
     *
     * @return array<int, array<string, string>>
     */
    private function reportToEndpoints(): array
    {
        $configuredEndpoints = config('security.csp.report_to_endpoints');
        $endpoints = array_filter(array_map('trim', Arr::wrap($configuredEndpoints)));

        if (empty($endpoints)) {
            $fallback = config('security.csp.report_uri');

            if (is_string($fallback) && $fallback !== '') {
                $endpoints[] = trim($fallback);
            }
        }

        $normalized = [];

        foreach ($endpoints as $endpoint) {
            if (!is_string($endpoint) || $endpoint === '') {
                continue;
            }

            $url = trim($endpoint);

            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                $this->securityLogger()->warning('http.security_headers.misconfigured', [
                    'reason' => 'report_to_invalid_endpoint',
                    'url' => $url,
                ]);
                continue;
            }

            $scheme = parse_url($url, PHP_URL_SCHEME);
            $isLocal = app()->environment(['local', 'development']);
            $allowedSchemes = $isLocal ? ['https', 'http'] : ['https'];

            if (!in_array($scheme, $allowedSchemes, true)) {
                $this->securityLogger()->warning('http.security_headers.misconfigured', [
                    'reason' => 'report_to_unsupported_scheme',
                    'url' => $url,
                    'scheme' => $scheme,
                    'environment' => app()->environment(),
                ]);
                continue;
            }

            $normalized[] = ['url' => $url];
        }

        return $normalized;
    }

    /**
     * Genera un nonce criptográficamente seguro para CSP.
     * 
     * Utiliza random_bytes() para generar 16 bytes aleatorios (128 bits de entropía)
     * y los codifica en Base64, resultando en un string de 24 caracteres.
     * 
     * El nonce debe ser:
     * - Único por request
     * - Impredecible (criptográficamente seguro)
     * - Incluido en scripts/estilos inline que deben ejecutarse
     * 
     * Uso en templates Blade:
     * ```blade
     * <script nonce="{{ request()->attributes->get('csp-nonce') }}">
     *     console.log('Script inline permitido');
     * </script>
     * ```
     * 
     * @return string Nonce hexadecimal de 32 caracteres
     * 
     * @throws \Exception Si no hay suficiente entropía en el sistema
     * 
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/script-src#nonce
     */
    private function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * Obtiene la configuración de headers de seguridad desde config/security.php.
     * 
     * Centraliza el acceso a la configuración para facilitar caching futuro
     * si se detectan problemas de performance por lecturas repetidas de config.
     * 
     * Estructura esperada en config/security.php:
     * ```php
     * 'security_headers' => [
     *     'enable_hsts' => true,
     *     'hsts_max_age' => 31536000,
     *     'enable_frame_options' => true,
     *     'enable_content_type_options' => true,
     *     'enable_xss_protection' => false,
     *     'referrer_policy' => 'strict-origin-when-cross-origin',
     *     'permissions_policy' => [...],
     *     'enable_corp' => true,
     *     'corp_policy' => 'same-origin',
     *     'enable_coop' => true,
     *     'coop_policy' => 'same-origin',
     *     'enable_coep' => false,
     *     'coep_policy' => 'require-corp',
     * ]
     * ```
     * 
     * @return array<string, mixed> Configuración de security_headers o array vacío
     * 
     * @todo Considerar cachear resultado si se detectan problemas de performance
     */
    private function securityConfig(): array
    {
        return config('security.security_headers', []);
    }

    /**
     * Verifica si el modo debug de CSP está habilitado en configuración.
     * 
     * Cuando está activo (security.debug_csp = true), el middleware registra
     * información detallada sobre:
     * - CSP generado completo
     * - Todos los security headers aplicados
     * - Request ID y URL
     * - Entorno de ejecución
     * 
     * ⚠️ **Desactivar en producción** para evitar logs excesivos.
     * Solo habilitar temporalmente para debugging de problemas de CSP.
     * 
     * @return bool True si debug está habilitado, false en caso contrario
     * 
     * @see logDebug()
     */
    private function isDebugEnabled(): bool
    {
        return (bool) config('security.debug_csp', false);
    }

    /**
     * Registra mensajes de debug solo cuando el modo debug CSP está activo.
     * 
     * Wrapper alrededor del logger seguro que verifica isDebugEnabled() antes
     * de escribir, evitando overhead de logging en producción.
     * 
     * Los logs de debug incluyen contexto rico para facilitar troubleshooting:
     * - CSP completo generado
     * - Headers de seguridad aplicados
     * - Request ID para correlación
     * - URL de la petición
     * - Entorno de ejecución
     * 
     * @param string $event Evento de log
     * @param array<string, mixed> $context Datos contextuales adicionales
     * @return void
     * 
     * @example
     * $this->logDebug('CSP applied', [
     *     'csp' => $cspString,
     *     'nonce' => $nonce,
     *     'environment' => 'production'
     * ]);
     */
    private function logDebug(string $event, array $context = []): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $this->securityLogger()->debug($event, $context);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function requestLogContext(Request $request, Response $response, array $extra = []): array
    {
        $context = [
            'route_name' => $request->route()?->getName(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'correlation_id' => (string) ($request->headers->get('X-Request-ID') ?? ''),
            'tenant_id' => $request->attributes->get('tenant_id'),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ];

        return array_merge($context, $extra);
    }

    private function securityLogger(): MediaSecurityLogger
    {
        return $this->securityLogger ??= app(MediaSecurityLogger::class);
    }

    private function logSanitizer(): MediaLogSanitizer
    {
        return $this->logSanitizer ??= app(MediaLogSanitizer::class);
    }

    /**
     * Extrae headers de seguridad relevantes de la response para logging.
     * 
     * Filtra solo los headers relacionados con seguridad para incluir en logs
     * de debug, evitando registrar todos los headers HTTP (que incluirían
     * cookies, cache-control, etc.).
     * 
     * Headers monitoreados:
     * - content-security-policy
     * - strict-transport-security
     * - x-frame-options
     * - x-content-type-options
     * - x-xss-protection
     * - referrer-policy
     * - permissions-policy
     * - cross-origin-resource-policy
     * - cross-origin-opener-policy
     * - cross-origin-embedder-policy
     * 
     * Útil para:
     * - Verificar que headers se aplicaron correctamente
     * - Debugging de problemas de CSP
     * - Auditoría de configuración de seguridad
     * - Monitoreo de cambios en políticas
     * 
     * @param Response $response Response HTTP con headers aplicados
     * @return array<string, mixed> Array asociativo header => valor
     * 
     * @example
     * // Retorno típico:
     * [
     *     'content-security-policy' => "default-src 'self'; ...",
     *     'x-frame-options' => 'DENY',
     *     'referrer-policy' => 'strict-origin-when-cross-origin',
     *     ...
     * ]
     */
    private function extractSecurityHeaders(Response $response): array
    {
        $relevant = [
            'content-security-policy',
            'report-to',
            'strict-transport-security',
            'x-frame-options',
            'x-content-type-options',
            'x-xss-protection',
            'referrer-policy',
            'permissions-policy',
            'cross-origin-resource-policy',
            'cross-origin-opener-policy',
            'cross-origin-embedder-policy',
        ];

        $headers = [];

        foreach ($relevant as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = $response->headers->get($name);
            }
        }

        return $headers;
    }
}
