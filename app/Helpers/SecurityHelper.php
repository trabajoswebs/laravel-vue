<?php

declare(strict_types=1);

namespace App\Helpers;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\Log;

/**
 * Conjunto de métodos utilitarios para sanitizar, validar y proteger datos.
 *
 * Esta clase centraliza las operaciones de seguridad comunes, como la limpieza de
 * entradas de usuario, la validación de formatos (email, nombre, etc.), la
 * sanitización de HTML, la prevención de divulgación de información sensible
 * en logs, y la validación de URLs. Utiliza bibliotecas como HTMLPurifier
 * para la limpieza de HTML y constantes para definir límites de seguridad.
 * Todos los métodos son estáticos para un acceso directo y sin necesidad de instanciar.
 */
final class SecurityHelper
{
    private function __construct()
    {
    }

    // Configuración de límites de seguridad
    private const MAX_INPUT_LENGTH = 10000;
    private const MAX_NAME_LENGTH = 100;
    private const MAX_EMAIL_LENGTH = 255;
    private const MAX_ERROR_MESSAGE_LENGTH = 500;
    private const MAX_LOCALE_LENGTH = 10;

    /** @var array<string, HTMLPurifier> */
    private static array $purifierCache = [];

    // Patrones reutilizables
    private const INVISIBLE_CHARS_REGEX = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';
    private const USERNAME_REGEX = '/^[\p{L}\p{M}\p{Zs}\-\.\']+$/u';
    private const LOCALE_REGEX = '/^[a-z]{2}(-[a-z]{2})?$/';
    private const SANITIZE_PATH_SEGMENT = '/^[\p{L}\p{N}\-_\.]+$/u';
    private const ON_EVENT_ATTRS_REGEX = '/\s*on\w+\s*=\s*(?:"(?:[^"]*)"|\'(?:[^\']*)\'|[^>\s]+)/i';
    private const SCRIPT_TAG_REGEX = '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi';
    private const IFRAME_TAG_REGEX = '/<iframe\b[^>]*>(.*?)<\/iframe>/mi';
    private const TOKEN_ALLOWED_REGEX = '/[^a-zA-Z0-9\-_\.]/';
    private const EMAIL_BAD_CHARS_REGEX = '/[<>"\'\\\\]/';
    private const PATH_LEAK_REGEX = '/\/[a-zA-Z0-9_\-\/\.]+\.(php|js|css|html)/';
    private const IPV4_REGEX = '/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/';
    private const IPV6_REGEX = '/\b(?:[a-f0-9]{1,4}:){1,7}(?:[a-f0-9]{1,4}|:)\b/i';
    private const EMAIL_LEAK_REGEX = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
    private const CRLF_REGEX = '/(?:\r|\n|%0d|%0a)/i';
    private const MAX_URL_LENGTH = 4096;

    // Patrones de contenido sensible
    private const SENSITIVE_PATTERNS = [
        '/password/i',
        '/token/i',
        '/key/i',
        '/secret/i',
        '/database/i',
        '/connection/i',
        '/api[_\-]?key/i',
        '/auth[_\-]?token/i',
        '/bearer\s+[a-zA-Z0-9\-_.]+/i',
        '/mysql/i',
        '/postgresql/i',
    ];

    /** @var string[] */
    private const MALICIOUS_PATTERNS = [
        self::SCRIPT_TAG_REGEX,
        '/\bjavascript:/i',
        '/\bvbscript:/i',
        '/\bon\w+\s*=/i',
        '/<iframe\b/i',
        '/<object\b/i',
        '/<embed\b/i',
        '/<applet\b/i',
        '/expression\s*\(/i',
        '/import\s+["\']javascript:/i',
        '/\bsrcdoc\s*=/i',
        '/\bdata:\s*(?:text\/html|image\/svg\+xml|application\/(?:x-)?javascript)/i',
    ];

    /**
     * Obtiene o crea una instancia de HTMLPurifier configurada según un perfil.
     *
     * Este método implementa un patrón de caché simple para reutilizar instancias
     * de HTMLPurifier, lo que mejora el rendimiento al evitar recrear la
     * configuración en cada llamada.
     *
     * @param string $profile Perfil de configuración de HTMLPurifier (e.g., 'default', 'strict', 'translations').
     *                        Si el perfil no existe, se usa 'default'.
     * @return HTMLPurifier Instancia configurada de HTMLPurifier.
     */
    private static function getHtmlPurifier(string $profile = 'default'): HTMLPurifier
    {
        if (isset(self::$purifierCache[$profile])) {
            return self::$purifierCache[$profile]; // Reutiliza instancias
        }

        // Ej: obtiene configuración según perfil dinámico, si no existe usa la "default"
        $settings = config('htmlpurifier.' . $profile);
        if (!is_array($settings)) {
            $settings = config('htmlpurifier.default', []);
        }

        $config = HTMLPurifier_Config::createDefault();
        // Ej: crea objeto base con configuración por defecto de HTMLPurifier

        foreach ($settings as $key => $value) {
            try {
                $config->set($key, $value); // Aplica restricciones de etiquetas y atributos
            } catch (\Throwable $e) {
                Log::warning('Invalid HTMLPurifier setting ignored', self::sanitizeForLogging([
                    'profile' => $profile,
                    'setting' => $key,
                    'message' => $e->getMessage(),
                ]));
            }
        }

        $purifier = new HTMLPurifier($config);
        return self::$purifierCache[$profile] = $purifier; // Cachea instancia por perfil
    }

    /**
     * Limpia el caché interno de instancias de HTMLPurifier.
     *
     * Útil en pruebas o cuando se ajusta la configuración en tiempo de ejecución.
     */
    public static function resetPurifierCache(): void
    {
        self::$purifierCache = [];
    }

    /**
     * Sanitiza contenido HTML usando HTMLPurifier.
     *
     * Este método limpia el HTML de entradas potencialmente peligrosas
     * (scripts, iframes, etc.) según la configuración del perfil especificado.
     *
     * @param string|null $content Contenido HTML a limpiar.
     * @param string $config (Opcional) Nombre del perfil de configuración de HTMLPurifier.
     * @return string HTML limpio y seguro para mostrar.
     */
    public static function sanitizeHtml(?string $content, string $config = 'default'): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        try {
            $purifier = self::getHtmlPurifier($config); // Ej: HTMLPurifier listo
            return $purifier->purify($content); // Ej: "<script>x</script>" => ""
        } catch (\Exception $e) {
            Log::warning('HTMLPurifier failed, using fallback sanitization', self::sanitizeForLogging([
                'error' => $e->getMessage(), // Ej: "Undefined method"
                'content_length' => mb_strlen($content, 'UTF-8'), // Ej: 42
                'config' => $config // Ej: "default"
            ]));

            return self::sanitizeUserInput($content); // Ej: fallback seguro
        }
    }

    /**
     * Sanitiza entrada de usuario para texto plano.
     *
     * Limpia cadenas de texto de caracteres peligrosos o invisibles,
     * y aplica codificación HTML para prevenir XSS en contextos de texto plano.
     * Trunca la entrada si excede el límite definido.
     *
     * @param string|null $input Texto del usuario a limpiar.
     * @return string Texto limpio y seguro.
     */
    public static function sanitizeUserInput(?string $input): string
    {
        if ($input === null || $input === '') {
            return ''; // Ej: "" si input vacío
        }

        $input = trim($input); // Ej: " hola " => "hola"
        if ($input === '') {
            return '';
        }

        $input = self::normalizeUnicode($input);
        $decoded = html_entity_decode($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); // Evita doble escapado
        $escaped = htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); // Ej: "<b>" => "&lt;b&gt;"
        $sanitized = preg_replace(self::INVISIBLE_CHARS_REGEX, '', $escaped) ?? '';

        return mb_substr($sanitized, 0, self::MAX_INPUT_LENGTH, 'UTF-8'); // Ej: corta a 10000 caracteres
    }

    /**
     * Sanitiza texto plano preservando caracteres comunes (&, tildes, etc.).
     *
     * Similar a `sanitizeUserInput`, pero elimina también etiquetas HTML.
     * Útil para textos que deben ser almacenados como texto plano sin formato.
     *
     * @param string|null $input Texto del usuario a limpiar.
     * @return string Texto limpio y seguro para persistencia.
     */
    public static function sanitizePlainText(?string $input): string
    {
        if ($input === null || $input === '') {
            return '';
        }

        $input = trim($input);
        if ($input === '') {
            return '';
        }

        $input = self::normalizeUnicode($input);
        $decoded = html_entity_decode($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $stripped = strip_tags($decoded);
        $clean = preg_replace(self::INVISIBLE_CHARS_REGEX, '', $stripped) ?? '';

        return mb_substr($clean, 0, self::MAX_INPUT_LENGTH, 'UTF-8');
    }

    /**
     * Sanitiza contenido para salida segura (previene XSS).
     *
     * Realiza una limpieza preliminar de elementos HTML comunes y peligrosos,
     * y luego aplica `HTMLPurifier` para un filtrado más robusto.
     *
     * @param string|null $output HTML potencialmente inseguro.
     * @return string HTML seguro para mostrar al usuario.
     */
    public static function sanitizeOutput(?string $output): string
    {
        if ($output === null || $output === '') {
            return ''; // Ej: "" si output vacío
        }

        $clean = preg_replace(self::SCRIPT_TAG_REGEX, '', $output) ?? '';
        $clean = preg_replace(self::IFRAME_TAG_REGEX, '', $clean) ?? '';
        $clean = preg_replace(self::ON_EVENT_ATTRS_REGEX, '', $clean) ?? '';

        try {
            $purifier = self::getHtmlPurifier('default'); // Ej: crea purificador
            $clean = $purifier->purify($clean); // Ej: limpia onclick
        } catch (\Throwable $e) {
            Log::warning('Purifier unavailable in sanitizeOutput', self::sanitizeForLogging([
                'error' => $e->getMessage(),
            ]));

            return self::sanitizeUserInput($clean); // Fallback a texto escapado
        }

        return $clean; // Devuelve HTML permitido por configuración
    }

    /**
     * Sanitiza array de datos para JSON.
     *
     * Recursivamente limpia todos los valores de tipo string dentro de un array,
     * ideal para preparar datos que se enviarán como respuesta JSON.
     *
     * @param array $data Array de datos de entrada.
     * @return array Array con valores de string sanitizados.
     */
    public static function sanitizeForJson(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return self::sanitizePlainText($value);
            }
            if (is_array($value)) {
                return self::sanitizeForJson($value); // Ej: ["<b>hola</b>"] => ["<b>hola</b>"]
            }
            return $value; // Ej: 123 => 123
        }, $data);
    }

    /**
     * Sanitiza contenido específico para traducciones.
     *
     * Utiliza un perfil específico de `HTMLPurifier` (esperado como un servicio de Laravel)
     * para limpiar textos traducibles, permitiendo un control más fino sobre el HTML permitido.
     *
     * @param string|null $content Texto traducible a limpiar.
     * @return string Texto limpio.
     */
    public static function sanitizeTranslationContent(?string $content): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        try {
            $purifier = app('htmlpurifier.translations'); // Ej: instancia configurada para traducciones
            return $purifier->purify($content); // Ej: "<script>x</script>" => ""
        } catch (\Exception $e) {
            Log::warning('Translation sanitization failed, using fallback', self::sanitizeForLogging([
                'error' => $e->getMessage(), // Ej: "Service not found"
                'content_length' => mb_strlen($content, 'UTF-8') // Ej: 42
            ]));

            return self::sanitizeUserInput($content); // Ej: fallback básico
        }
    }

    /**
     * Sanitiza nombre de usuario (más estricto).
     *
     * Valida que el nombre contenga solo caracteres alfabéticos, espacios, guiones y apóstrofos.
     * Lanza una excepción si el nombre no cumple con los criterios.
     *
     * @param string|null $name Nombre a validar y limpiar.
     * @return string Nombre seguro y formateado.
     * @throws \InvalidArgumentException Si el nombre es inválido.
     */
    public static function sanitizeUserName(?string $name): string
    {
        if ($name === null || $name === '') {
            return ''; // Ej: "" si name vacío
        }

        $name = mb_substr(trim($name), 0, self::MAX_NAME_LENGTH, 'UTF-8'); // Ej: "   Juan   " => "Juan"

        if (!preg_match(self::USERNAME_REGEX, $name)) {
            throw new \InvalidArgumentException('Nombre contiene caracteres no válidos'); // Ej: "Juan123" => excepción
        }

        return preg_replace('/\s+/', ' ', $name) ?? $name; // Ej: "Juan   Pérez" => "Juan Pérez"
    }

    /**
     * Sanitiza email (validación básica adicional).
     *
     * Valida el formato del email usando `filter_var` y verifica que no contenga
     * caracteres peligrosos. Lanza una excepción si el email no es válido.
     *
     * @param string $email Correo a validar y limpiar.
     * @return string Email válido y seguro.
     * @throws \InvalidArgumentException Si el email es inválido.
     */
    public static function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            throw new \InvalidArgumentException('Email no puede estar vacío'); // Ej: "" => excepción
        }

        if (mb_strlen($email, 'UTF-8') > self::MAX_EMAIL_LENGTH) {
            throw new \InvalidArgumentException('Email demasiado largo'); // Ej: 300 chars => excepción
        }

        if (preg_match(self::EMAIL_BAD_CHARS_REGEX, $email)) {
            throw new \InvalidArgumentException('Email contiene caracteres no válidos'); // Ej: "pepe<@mail.com"
        }

        if (!str_contains($email, '@')) {
            throw new \InvalidArgumentException('Formato de email inválido'); // Ej: "pepe@" => excepción
        }

        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || $domain === '') {
            throw new \InvalidArgumentException('Formato de email inválido');
        }

        $domain = mb_strtolower($domain, 'UTF-8'); // Solo normaliza dominio

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/u', $domain)) {
            $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT);
            if ($asciiDomain === false) {
                throw new \InvalidArgumentException('Formato de email inválido');
            }
            $domain = $asciiDomain;
        }

        $normalizedEmail = $local . '@' . $domain;

        if (strlen($normalizedEmail) > self::MAX_EMAIL_LENGTH) {
            throw new \InvalidArgumentException('Email demasiado largo');
        }

        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Formato de email inválido'); // Ej: "pepe@" => excepción
        }

        return $normalizedEmail; // Ej: "pepe@mail.com"
    }

    /**
     * Sanitiza mensajes de error para mostrar al usuario.
     *
     * Limpia el mensaje de errores de código HTML, lo trunca si es muy largo,
     * y reemplaza patrones sensibles (como contraseñas, claves) por `[REDACTED]`.
     *
     * @param string|null $message Mensaje de error crudo.
     * @return string Mensaje de error limpio y seguro para mostrar.
     */
    public static function sanitizeErrorMessage(?string $message): string
    {
        if ($message === null || $message === '') {
            return ''; // Ej: "" si vacío
        }

        $sanitized = self::sanitizeUserInput($message); // Ej: "<b>Error</b>" => "<b>Error</b>"
        $sanitized = mb_substr($sanitized, 0, self::MAX_ERROR_MESSAGE_LENGTH, 'UTF-8'); // Ej: corta a 500 chars

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $sanitized = preg_replace($pattern, '[REDACTED]', $sanitized, -1) ?? $sanitized; // Evita null por regex inválido
        }

        $sanitized = preg_replace(self::PATH_LEAK_REGEX, '[PATH]', $sanitized) ?? $sanitized; // Ej: "/var/www/app.php" => "[PATH]"
        $sanitized = preg_replace(self::IPV4_REGEX, '[IP]', $sanitized) ?? $sanitized; // Ej: "192.168.1.1" => "[IP]"
        $sanitized = preg_replace(self::IPV6_REGEX, '[IP]', $sanitized) ?? $sanitized; // Ej: "[2001:db8::1]" => "[IP]"
        $sanitized = preg_replace(self::EMAIL_LEAK_REGEX, '[EMAIL]', $sanitized) ?? $sanitized; // Ej: "user@mail.com" => "[EMAIL]"

        return $sanitized;
    }

    /**
     * Valida y sanitiza locale.
     *
     * Asegura que la cadena de idioma sea válida y segura para su uso en la aplicación.
     * Si no es válida, devuelve el locale por defecto de la aplicación.
     *
     * @param string|null $locale Cadena de idioma (e.g., 'es', 'en_US').
     * @return string Locale válido y seguro.
     */
    public static function sanitizeLocale(?string $locale): string
    {
        $fallback = (string) config('app.locale', 'en');

        if ($locale === null || $locale === '') {
            return $fallback; // Ej: "" => "en"
        }

        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($locale)) ?? ''; // Ej: "es_ES!" => "es_ES"

        if ($sanitized === '') {
            return $fallback; // Ej: "!@#" => "en"
        }

        $sanitized = mb_strtolower($sanitized, 'UTF-8'); // Ej: "ES" => "es"
        $sanitized = str_replace('_', '-', $sanitized); // Ej: "es_ES" => "es-es"
        $sanitized = mb_substr($sanitized, 0, self::MAX_LOCALE_LENGTH, 'UTF-8'); // Ej: "es-mx-variant" => "es-mx"

        if (!preg_match(self::LOCALE_REGEX, $sanitized)) {
            return $fallback; // Ej: "zzz" => "en"
        }

        return $sanitized; // Ej: "es-es"
    }

    /**
     * Sanitiza URL para uso en CSP u otros contextos seguros.
     *
     * Valida que la URL sea absoluta, use un esquema seguro (http/https),
     * no apunte a direcciones IP privadas/reservadas ni a hosts bloqueados,
     * y no use puertos no estándar.
     *
     * @param string|null $url URL candidata a validar.
     * @return string|null URL válida y segura, o null si es inválida.
     */
    public static function sanitizeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > self::MAX_URL_LENGTH) {
            return null;
        }

        if (preg_match(self::CRLF_REGEX, $trimmed)) {
            return null;
        }

        $parsed = @parse_url($trimmed);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return null;
        }

        $scheme = mb_strtolower((string) $parsed['scheme'], 'UTF-8');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (!empty($parsed['user']) || !empty($parsed['pass'])) {
            return null;
        }

        $host = rtrim((string) $parsed['host'], '.');
        $normalizedHost = mb_strtolower($host, 'UTF-8');
        if ($normalizedHost === '') {
            return null;
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/u', $normalizedHost)) {
            $asciiHost = idn_to_ascii($normalizedHost, IDNA_DEFAULT);
            if ($asciiHost === false) {
                return null;
            }

            $normalizedHost = mb_strtolower(rtrim($asciiHost, '.'), 'UTF-8');
        }

        $hostForValidation = trim($normalizedHost, '[]');
        if ($hostForValidation === '' || $hostForValidation === 'localhost') {
            return null;
        }

        if (filter_var($hostForValidation, FILTER_VALIDATE_IP)) {
            if (!filter_var($hostForValidation, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        } else {
            $resolvedIps = self::resolveHostIps($normalizedHost);
            if ($resolvedIps === []) {
                return null;
            }

            foreach ($resolvedIps as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return null;
                }
            }
        }

        $allowedPorts = (array) config('security.allowed_ports', [80, 443]);
        if (isset($parsed['port']) && !in_array((int) $parsed['port'], $allowedPorts, true)) {
            return null;
        }

        if (!empty($parsed['path'])) {
            $segments = array_map('rawurlencode', array_map('urldecode', explode('/', $parsed['path'])));
            $parsed['path'] = preg_replace('#/+#', '/', implode('/', $segments));
        }

        $parsed['scheme'] = $scheme;
        $parsed['host'] = $normalizedHost;

        $cleanUrl = self::rebuildUrlFromParts($parsed);

        return $cleanUrl !== '' ? $cleanUrl : null;
    }

    /**
     * Sanitiza rutas de archivo y previene path traversal.
     *
     * Valida que la ruta no contenga segmentos como `..` o `.` que podrían
     * permitir acceder a directorios fuera de un path permitido.
     * Lanza una excepción si se detectan segmentos no permitidos.
     *
     * @param string|null $path Ruta de archivo a limpiar.
     * @return string Ruta limpia y segura.
     * @throws \InvalidArgumentException Cuando se detectan segmentos no permitidos.
     */
    public static function sanitizeFilePath(?string $path): string
    {
        if ($path === null) {
            return ''; // Ej: "" => ""
        }

        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $path = self::normalizeUnicode($path);
        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('/[^\p{L}\p{N}\-_\.\/]/u', '', $normalized) ?? '';
        $normalized = ltrim($normalized, '/');

        $segments = array_values(array_filter(explode('/', $normalized), static function ($segment) {
            return $segment !== '';
        }));

        if (empty($segments)) {
            return '';
        }

        $cleanSegments = [];
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Ruta contiene segmentos no permitidos');
            }

            if (!preg_match(self::SANITIZE_PATH_SEGMENT, $segment)) {
                throw new \InvalidArgumentException('Segmento de ruta inválido');
            }

            $cleanSegments[] = $segment;
        }

        return implode('/', $cleanSegments); // Ej: "uploads/file"
    }

    /**
     * Valida y sanitiza tokens o hashes.
     *
     * Limpia el token de caracteres no alfanuméricos ni guiones, puntos o subrayados.
     * Lanza una excepción si el token es inválido o vacío.
     *
     * @param string|null $token Token a limpiar.
     * @return string Token válido y seguro.
     * @throws \InvalidArgumentException Si el token es inválido.
     */
    public static function sanitizeToken(?string $token): string
    {
        if ($token === null || $token === '') {
            throw new \InvalidArgumentException('Token no puede estar vacío'); // Ej: "" => excepción
        }

        $sanitized = preg_replace(self::TOKEN_ALLOWED_REGEX, '', $token) ?? ''; // Ej: "abc$%123" => "abc123"

        if ($sanitized === '') {
            throw new \InvalidArgumentException('Token contiene caracteres no válidos'); // Ej: "&&&" => excepción
        }

        return mb_substr($sanitized, 0, 255, 'UTF-8'); // Ej: token largo => se corta a 255
    }

    /**
     * Sanitiza datos antes de loguearlos (oculta información sensible).
     *
     * Reemplaza valores de claves conocidas (como 'password', 'token', etc.)
     * por `[REDACTED]` para prevenir la divulgación accidental de datos sensibles en los logs.
     * Trunca cadenas largas.
     *
     * @param array $data Array de datos a limpiar para logging.
     * @return array Array con datos sensibles redactados.
     */
    public static function sanitizeForLogging(array $data): array
    {
        static $configuredSensitive = null;

        if ($configuredSensitive === null) {
            $configuredSensitive = [];

            foreach ((array) config('audit.sensitive_fields', []) as $field) {
                if (is_string($field) && $field !== '') {
                    $configuredSensitive[] = strtolower($field);
                }
            }
        }

        $defaultSensitive = [
            'password',
            'password_confirmation',
            'token',
            'api_key',
            'secret',
            'private_key',
            'auth_token',
            'bearer_token',
            'session_id',
            'csrf_token',
            'remember_token'
        ];

        $sensitiveKeys = array_unique(array_merge($defaultSensitive, $configuredSensitive));

        $sanitized = []; // Ej: resultado final inicializado

        foreach ($data as $key => $value) {
            $lowerKey = is_string($key) ? strtolower($key) : (string) $key;

            if (in_array($lowerKey, $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]'; // Ej: "password" => "[REDACTED]"
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeForLogging($value); // Ej: array anidado => recursivo
                continue;
            }

            if (is_string($value)) {
                $length = mb_strlen($value, 'UTF-8');
                $sanitized[$key] = $length > 200 ? mb_substr($value, 0, 200, 'UTF-8') . '...' : $value; // Ej: string largo => truncado
                continue;
            }

            $sanitized[$key] = $value; // Ej: int/boolean => sin cambios
        }

        return $sanitized; // Ej: datos listos para log seguro
    }

    /**
     * Sanitiza nombres de archivo para logging evitando inyecciones.
     *
     * Elimina saltos de línea, reemplaza separadores de ruta y limita la longitud.
     * @param string $filename Nombre original subido por el usuario
     * @param int $maxLength
     * @return string Nombre seguro
     */
    public static function sanitizeFilename(string $filename, int $maxLength = self::MAX_NAME_LENGTH): string
    {
        $clean = preg_replace(self::CRLF_REGEX, '', $filename); // Ej: "name\r\n" -> "name"
        $clean = str_replace(['\\', '/'], '_', $clean); // Ej: "../secret.png" -> "__secret.png"
        $clean = trim($clean);
        $clean = preg_replace('/[^A-Za-z0-9_\-\.]/u', '_', $clean) ?? '';

        if ($clean === '') {
            return 'file';
        }

        return mb_substr($clean, 0, $maxLength, 'UTF-8'); // Ej: nombre largo -> truncado
    }

    /**
     * Genera hash seguro de una IP para logging sin exponer la real.
     *
     * Utiliza SHA256 con una sal para generar un hash irreversible de la IP,
     * útil para auditoría o correlación de eventos sin comprometer la privacidad.
     *
     * @param string|null $ip Dirección IP a hashear.
     * @return string Hash parcial de la IP.
     */
    public static function hashIp(?string $ip): string
    {
        if ($ip === null || trim($ip) === '') {
            return 'unknown'; // Ej: "" => "unknown"
        }

        $salt = (string) config('app.key', 'default_salt'); // Ej: obtiene clave del .env
        $normalizedIp = trim($ip);

        return substr(hash('sha256', $normalizedIp . $salt), 0, 12); // Ej: "192.168.1.1" => "a94f6d3bc12f"
    }

    /**
     * Reconstruye un URL desde sus partes normalizadas.
     *
     * Toma un array de partes (como el devuelto por `parse_url`) y las
     * vuelve a unir en una URL válida y bien formada.
     *
     * @param array $parts Partes del URL (scheme, host, path, etc.).
     * @return string URL reconstruida.
     */
    private static function rebuildUrlFromParts(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $host = $parts['host'] ?? '';
        $hostForUrl = $host;
        if ($hostForUrl !== '' && str_contains($hostForUrl, ':') && $hostForUrl[0] !== '[') {
            $hostForUrl = '[' . $hostForUrl . ']';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $hostForUrl . $port . $path . $query . $fragment;
    }

    /**
     * Normaliza cadenas Unicode para evitar representaciones canónicas ambiguas.
     */
    private static function normalizeUnicode(string $s): string
    {
        return class_exists(\Normalizer::class) ? \Normalizer::normalize($s, \Normalizer::FORM_C) ?? $s : $s;
    }

    /**
     * Resuelve registros DNS a IPs válidas evitando loops de CNAME.
     *
     * Utiliza `dns_get_record` para obtener direcciones IP de un host,
     * manejando recursivamente los registros CNAME para evitar bucles infinitos.
     *
     * @param string $host Host a resolver.
     * @param array<string, bool> $visited (Interno) Hosts ya visitados para evitar ciclos.
     * @return array<int, string> Lista de direcciones IP públicas resueltas.
     */
    private static function resolveHostIps(string $host, array $visited = []): array
    {
        $host = rtrim($host, '.');
        if ($host === '' || isset($visited[$host])) {
            return [];
        }

        $visited[$host] = true;

        if (!function_exists('dns_get_record')) {
            $ip = gethostbyname($host);
            if ($ip === $host) {
                return [];
            }

            return [$ip];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA + DNS_CNAME);
        if ($records === false || empty($records)) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
                continue;
            }

            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
                continue;
            }

            if (($record['type'] ?? null) === 'CNAME' && !empty($record['target'])) {
                $target = rtrim($record['target'], '.');
                $ips = array_merge($ips, self::resolveHostIps($target, $visited));
            }
        }

        $ips = array_values(array_unique($ips));

        return array_values(array_filter($ips, static function (string $ip): bool {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }));
    }

    /**
     * Verifica si un string contiene contenido peligroso (XSS, JS, etc.).
     *
     * Utiliza una lista de patrones comunes para detectar código HTML/JS potencialmente malicioso.
     * No es un sustituto de `HTMLPurifier`, sino una verificación rápida.
     *
     * @param string $content Texto a analizar.
     * @return bool `true` si se detecta contenido malicioso, `false` en caso contrario.
     */
    public static function containsMaliciousContent(string $content): bool
    {
        foreach (self::MALICIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                return true; // Ej: "<script>alert(1)</script>" => true
            }
        }

        return false; // Ej: "hola mundo" => false
    }
}
