<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;
use HTMLPurifier;
use HTMLPurifier_Config;

class SecurityHelper
{
    // Configuración de límites de seguridad
    private const MAX_INPUT_LENGTH = 10000;
    private const MAX_NAME_LENGTH = 100;
    private const MAX_EMAIL_LENGTH = 255;
    private const MAX_ERROR_MESSAGE_LENGTH = 500;
    private const MAX_LOCALE_LENGTH = 10;

    /** @var array<string, HTMLPurifier> */
    private static array $purifierCache = [];

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

    /**
     * Crear y configurar instancia de HTMLPurifier usando configuración de config/htmlpurifier.php
     *
     * @param string $profile Perfil de configuración a usar (default, strict, permissive, translations)
     * @return HTMLPurifier Instancia configurada
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
     * Sanitiza contenido HTML usando HTMLPurifier
     *
     * @param string $content Contenido HTML
     * @param string $config Configuración opcional
     * @return string HTML limpio
     */
    public static function sanitizeHtml(string $content, string $config = 'default'): string
    {
        try {
            if (empty($content)) {
                return ''; // Ej: "" si $content = ""
            }

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
     * Sanitiza entrada de usuario para texto plano
     *
     * @param string $input Texto del usuario
     * @return string Texto limpio
     */
    public static function sanitizeUserInput(string $input): string
    {
        if ($input === null || $input === '') {
            return ''; // Ej: "" si input vacío
        }

        $input = trim($input); // Ej: " hola " => "hola"
        $input = html_entity_decode($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); // Evita doble escapado
        $sanitized = htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); // Ej: "<b>" => "&lt;b&gt;"
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $sanitized); // Ej: elimina caracteres invisibles
        return mb_substr($sanitized, 0, self::MAX_INPUT_LENGTH); // Ej: corta a 10000 caracteres
    }

    /**
     * Sanitiza texto plano preservando caracteres comunes (&, tildes, etc.).
     *
     * @param string $input Texto del usuario
     * @return string Texto limpio para persistencia
     */
    public static function sanitizePlainText(string $input): string
    {
        if ($input === null || $input === '') {
            return '';
        }

        $decoded = html_entity_decode(trim($input), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $stripped = strip_tags($decoded);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $stripped);
        return mb_substr($clean, 0, self::MAX_INPUT_LENGTH);
    }

    /**
     * Sanitiza contenido para salida segura (previene XSS)
     *
     * @param string $output HTML potencialmente inseguro
     * @return string HTML seguro
     */
    public static function sanitizeOutput(string $output): string
    {
        if ($output === '') {
            return ''; // Ej: "" si output vacío
        }

        $clean = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $output); // Ej: "<script>1</script>" => ""
        $clean = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/mi', '', $clean); // Ej: "<iframe src='evil'></iframe>" => ""
        $clean = preg_replace('/\s*on\w+\s*=\s*(?:"(?:[^"]*)"|\'(?:[^\']*)\'|[^>\s]+)/i', '', $clean); // Ej: "<a onclick='x'>" => "<a>"

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
     * Sanitiza array de datos para JSON
     *
     * @param array $data Datos de entrada
     * @return array Datos sanitizados
     */
    public static function sanitizeForJson(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return self::sanitizePlainText($value);
            }
            if (is_array($value)) {
                return self::sanitizeForJson($value); // Ej: ["<b>hola</b>"] => ["&lt;b&gt;hola&lt;/b&gt;"]
            }
            return $value; // Ej: 123 => 123
        }, $data);
    }

    /**
     * Sanitiza contenido específico para traducciones
     *
     * @param string $content Texto traducible
     * @return string Texto limpio
     */
    public static function sanitizeTranslationContent(string $content): string
    {
        try {
            if (empty($content)) {
                return ''; // Ej: "" si $content vacío
            }

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
     * Sanitiza nombre de usuario (más estricto)
     *
     * @param string $name Nombre a validar
     * @return string Nombre seguro
     */
    public static function sanitizeUserName(string $name): string
    {
        if (empty($name)) {
            return ''; // Ej: "" si name vacío
        }

        $name = mb_substr(trim($name), 0, self::MAX_NAME_LENGTH); // Ej: "   Juan   " => "Juan"

        if (!preg_match('/^[\p{L}\p{M}\p{Zs}\-\.\']+$/u', $name)) {
            throw new \InvalidArgumentException('Nombre contiene caracteres no válidos'); // Ej: "Juan123" => excepción
        }

        return preg_replace('/\s+/', ' ', $name); // Ej: "Juan   Pérez" => "Juan Pérez"
    }

    /**
     * Sanitiza email (validación básica adicional)
     *
     * @param string $email Correo a validar
     * @return string Email válido
     */
    public static function sanitizeEmail(string $email): string
    {
        if (empty($email)) {
            throw new \InvalidArgumentException('Email no puede estar vacío'); // Ej: "" => excepción
        }

        $email = trim(strtolower($email)); // Ej: " TEST@MAIL.COM " => "test@mail.com"

        if (mb_strlen($email, 'UTF-8') > self::MAX_EMAIL_LENGTH) {
            throw new \InvalidArgumentException('Email demasiado largo'); // Ej: 300 chars => excepción
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Formato de email inválido'); // Ej: "pepe@" => excepción
        }

        if (preg_match('/[<>"\'\\\]/', $email)) {
            throw new \InvalidArgumentException('Email contiene caracteres no válidos'); // Ej: "pepe<@mail.com"
        }

        return $email; // Ej: "pepe@mail.com"
    }

    /**
     * Sanitiza mensajes de error para mostrar al usuario
     *
     * @param string $message Mensaje crudo
     * @return string Mensaje limpio
     */
    public static function sanitizeErrorMessage(string $message): string
    {
        if (empty($message)) {
            return ''; // Ej: "" si vacío
        }

        $sanitized = self::sanitizeUserInput($message); // Ej: "<b>Error</b>" => "&lt;b&gt;Error&lt;/b&gt;"
        $sanitized = mb_substr($sanitized, 0, self::MAX_ERROR_MESSAGE_LENGTH, 'UTF-8'); // Ej: corta a 500 chars

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $sanitized = preg_replace($pattern, '[REDACTED]', $sanitized); // Ej: "password=123" => "[REDACTED]"
        }

        $sanitized = preg_replace('/\/[a-zA-Z0-9_\-\/\.]+\.(php|js|css|html)/', '[PATH]', $sanitized); // Ej: "/var/www/app.php" => "[PATH]"
        $sanitized = preg_replace('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', '[IP]', $sanitized); // Ej: "192.168.1.1" => "[IP]"

        return $sanitized;
    }

    /**
     * Valida y sanitiza locale
     *
     * @param string $locale Cadena de idioma
     * @return string Locale válido
     */
    public static function sanitizeLocale(string $locale): string
    {
        if (empty($locale)) {
            return config('app.locale', 'en'); // Ej: "" => "en"
        }

        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($locale)); // Ej: "es_ES!" => "es_ES"

        if (empty($sanitized)) {
            return config('app.locale', 'en'); // Ej: "!@#" => "en"
        }

        $sanitized = strtolower($sanitized); // Ej: "ES" => "es"
        $sanitized = str_replace('_', '-', $sanitized); // Ej: "es_ES" => "es-es"
        $sanitized = mb_substr($sanitized, 0, self::MAX_LOCALE_LENGTH, 'UTF-8'); // Ej: "es-mx-variant" => "es-mx"

        if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $sanitized)) {
            return config('app.locale', 'en'); // Ej: "zzz" => "en"
        }

        return $sanitized; // Ej: "es-es"
    }

    /**
     * Sanitiza URL para uso en CSP
     *
     * @param string $url URL candidata
     * @return ?string URL válida o null
     */
    public static function sanitizeUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        $cleanUrl = filter_var($trimmed, FILTER_SANITIZE_URL);
        if ($cleanUrl === false || $cleanUrl === '') {
            return null; // Ej: "htp:/mal" => null
        }

        $parsed = parse_url($cleanUrl); // Ej: devuelve ["scheme"=>"https","host"=>"google.com"]
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return null;
        }

        $host = $parsed['host'];
        $normalizedHost = strtolower(rtrim($host, '.'));

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/u', $host)) {
            $asciiHost = idn_to_ascii($host, IDNA_DEFAULT);
            if ($asciiHost === false) {
                return null;
            }

            $normalizedHost = strtolower(rtrim($asciiHost, '.'));
        }

        $parsed['host'] = $normalizedHost;
        $cleanUrl = self::rebuildUrlFromParts($parsed);

        $hostForValidation = trim($normalizedHost, '[]');

        $blockedHosts = ['localhost'];
        if (in_array($hostForValidation, $blockedHosts, true)) {
            return null; // Ej: "localhost" => null
        }

        if (filter_var($hostForValidation, FILTER_VALIDATE_IP)) {
            if (!filter_var($hostForValidation, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null; // Ej: "192.168.1.5" => null
            }
        } else {
            $resolvedIps = self::resolveHostIps($normalizedHost);
            if (empty($resolvedIps)) {
                return null;
            }

            foreach ($resolvedIps as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return null; // Ej: "192.168.1.5" => null
                }
            }
        }

        $allowedSchemes = ['http', 'https'];
        if (!in_array(strtolower($parsed['scheme']), $allowedSchemes, true)) {
            return null; // Ej: "ftp://" => null
        }

        if (isset($parsed['port']) && !in_array((int) $parsed['port'], [80, 443], true)) {
            return null; // Ej: puerto 8080 => null
        }

        return $cleanUrl; // Ej: "https://google.com"
    }




    /**
     * Sanitiza rutas de archivo y previene path traversal
     *
     * @param string $path Ruta de archivo
     * @return string Ruta limpia
     * @throws \InvalidArgumentException Cuando se detectan segmentos no permitidos
     */
    public static function sanitizeFilePath(string $path): string
    {
        if (trim($path) === '') {
            return ''; // Ej: "" => ""
        }

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

            if (!preg_match('/^[\p{L}\p{N}\-_\.]+$/u', $segment)) {
                throw new \InvalidArgumentException('Segmento de ruta inválido');
            }

            $cleanSegments[] = $segment;
        }

        return implode('/', $cleanSegments); // Ej: "uploads/file"
    }

    /**
     * Valida y sanitiza tokens o hashes
     *
     * @param string $token Token a limpiar
     * @return string Token válido
     */
    public static function sanitizeToken(string $token): string
    {
        if (empty($token)) {
            throw new \InvalidArgumentException('Token no puede estar vacío'); // Ej: "" => excepción
        }

        $sanitized = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $token); // Ej: "abc$%123" => "abc123"

        if (empty($sanitized)) {
            throw new \InvalidArgumentException('Token contiene caracteres no válidos'); // Ej: "&&&" => excepción
        }

        return mb_substr($sanitized, 0, 255, 'UTF-8'); // Ej: token largo => se corta a 255
    }

    /**
     * Sanitiza datos antes de loguearlos (oculta información sensible)
     *
     * @param array $data Datos a limpiar
     * @return array Datos limpios
     */
    public static function sanitizeForLogging(array $data): array
    {
        static $configuredSensitive = null;

        if ($configuredSensitive === null) {
            $configuredSensitive = array_map('strtolower', config('audit.sensitive_fields', []));
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
            if (in_array(strtolower($key), $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]'; // Ej: "password" => "[REDACTED]"
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeForLogging($value); // Ej: array anidado => recursivo
            } elseif (is_string($value)) {
                $length = mb_strlen($value, 'UTF-8');
                $sanitized[$key] = $length > 200 ? mb_substr($value, 0, 200, 'UTF-8') . '...' : $value; // Ej: string largo => truncado
            } else {
                $sanitized[$key] = $value; // Ej: int/boolean => sin cambios
            }
        }

        return $sanitized; // Ej: datos listos para log seguro
    }

    /**
     * Genera hash seguro de una IP para logging sin exponer la real
     *
     * @param string $ip Dirección IP
     * @return string Hash parcial
     */
    public static function hashIp(string $ip): string
    {
        if (empty($ip)) {
            return 'unknown'; // Ej: "" => "unknown"
        }

        $salt = config('app.key', 'default_salt'); // Ej: obtiene clave del .env
        return substr(hash('sha256', $ip . $salt), 0, 12); // Ej: "192.168.1.1" => "a94f6d3bc12f"
    }

    /**
     * Reconstruye un URL desde sus partes normalizadas
     *
     * @param array $parts Partes generadas por parse_url
     * @return string URL reconstruida
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
     * Resuelve registros DNS a IPs válidas evitando loops de CNAME
     *
     * @param string $host Host a resolver
     * @param array<string,bool> $visited Hosts ya visitados
     * @return array<int,string> Lista de IPs públicas
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

        return array_values(array_unique($ips));
    }

    /**
     * Verifica si un string contiene contenido peligroso (XSS, JS, etc.)
     *
     * @param string $content Texto a validar
     * @return bool True si se detecta algo malicioso
     */
    public static function containsMaliciousContent(string $content): bool
    {
        $maliciousPatterns = [
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
            '/javascript:/i',
            '/vbscript:/i',
            '/on\w+\s*=/i',
            '/<iframe\b/i',
            '/<object\b/i',
            '/<embed\b/i',
            '/<applet\b/i',
            '/expression\s*\(/i',
            '/import\s+["\']javascript:/i',
        ];

        foreach ($maliciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true; // Ej: "<script>alert(1)</script>" => true
            }
        }

        return false; // Ej: "hola mundo" => false
    }

}
