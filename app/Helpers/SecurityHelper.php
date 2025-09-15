<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class SecurityHelper
{
    // Configuración de límites de seguridad
    private const MAX_INPUT_LENGTH = 10000;
    private const MAX_NAME_LENGTH = 100;
    private const MAX_EMAIL_LENGTH = 255;
    private const MAX_ERROR_MESSAGE_LENGTH = 500;
    private const MAX_LOCALE_LENGTH = 10;

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
     * Sanitiza contenido HTML usando HTMLPurifier
     */
    public static function sanitizeHtml(string $content, string $config = 'default'): string
    {
        try {
            if (empty($content)) {
                return '';
            }

            $purifier = app("htmlpurifier.{$config}");
            return $purifier->purify($content);
        } catch (\Exception $e) {
            Log::warning('HTMLPurifier failed, using fallback sanitization', [
                'error' => $e->getMessage(),
                'content_length' => strlen($content),
                'config' => $config
            ]);

            // Fallback a sanitización básica
            return self::sanitizeUserInput($content);
        }
    }

    /**
     * Sanitiza entrada de usuario para texto plano
     */
    public static function sanitizeUserInput(string $input): string
    {
        if (empty($input)) {
            return '';
        }

        // Limpiar espacios en blanco al inicio y final
        $input = trim($input);

        // Convertir caracteres especiales a entidades HTML
        $sanitized = htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Remover caracteres de control peligrosos
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $sanitized);

        // Limitar longitud para prevenir ataques de buffer overflow
        return substr($sanitized, 0, self::MAX_INPUT_LENGTH);
    }

    /**
     * Sanitiza contenido para salida segura
     */
    public static function sanitizeOutput(string $output): string
    {
        if (empty($output)) {
            return '';
        }

        // Escapar caracteres especiales para HTML
        $sanitized = htmlspecialchars($output, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Remover posibles scripts inline
        $sanitized = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $sanitized);

        // Remover event handlers
        $sanitized = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/', '', $sanitized);

        return $sanitized;
    }

    /**
     * Sanitiza array de datos para JSON
     */
    public static function sanitizeForJson(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return self::sanitizeUserInput($value);
            }
            if (is_array($value)) {
                return self::sanitizeForJson($value);
            }
            return $value;
        }, $data);
    }

    /**
     * Sanitiza contenido específico para traducciones
     */
    public static function sanitizeTranslationContent(string $content): string
    {
        try {
            if (empty($content)) {
                return '';
            }

            $purifier = app('htmlpurifier.translations');
            return $purifier->purify($content);
        } catch (\Exception $e) {
            Log::warning('Translation sanitization failed, using fallback', [
                'error' => $e->getMessage(),
                'content_length' => strlen($content)
            ]);

            return self::sanitizeUserInput($content);
        }
    }

    /**
     * Sanitiza nombre de usuario (más estricto)
     */
    public static function sanitizeUserName(string $name): string
    {
        if (empty($name)) {
            return '';
        }

        // Permitir solo letras (incluye acentos), números, espacios y algunos caracteres especiales
        $sanitized = preg_replace('/[^a-zA-ZÀ-ÿ\u00f1\u00d1\s\-\.\']/', '', $name);

        // Limpiar espacios múltiples
        $sanitized = preg_replace('/\s+/', ' ', $sanitized);

        // Limpiar espacios al inicio y final
        $sanitized = trim($sanitized);

        // Limitar longitud
        return substr($sanitized, 0, self::MAX_NAME_LENGTH);
    }

    /**
     * Sanitiza email (validación básica adicional)
     */
    public static function sanitizeEmail(string $email): string
    {
        if (empty($email)) {
            throw new \InvalidArgumentException('Email no puede estar vacío');
        }

        $email = trim(strtolower($email));

        // Limitar longitud antes de validar
        if (strlen($email) > self::MAX_EMAIL_LENGTH) {
            throw new \InvalidArgumentException('Email demasiado largo');
        }

        // Validación básica de formato
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Formato de email inválido');
        }

        // Verificación adicional contra patrones peligrosos
        if (preg_match('/[<>"\'\\\]/', $email)) {
            throw new \InvalidArgumentException('Email contiene caracteres no válidos');
        }

        return $email;
    }

    /**
     * Sanitiza mensajes de error para mostrar al usuario
     */
    public static function sanitizeErrorMessage(string $message): string
    {
        if (empty($message)) {
            return '';
        }

        // Sanitizar contenido HTML
        $sanitized = self::sanitizeUserInput($message);

        // Limitar longitud para prevenir ataques
        $sanitized = substr($sanitized, 0, self::MAX_ERROR_MESSAGE_LENGTH);

        // Remover información sensible común
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $sanitized = preg_replace($pattern, '[REDACTED]', $sanitized);
        }

        // Remover rutas de archivos del sistema
        $sanitized = preg_replace('/\/[a-zA-Z0-9_\-\/\.]+\.(php|js|css|html)/', '[PATH]', $sanitized);

        // Remover IPs
        $sanitized = preg_replace('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', '[IP]', $sanitized);

        return $sanitized;
    }

    /**
     * Valida y sanitiza locale
     */
    public static function sanitizeLocale(string $locale): string
    {
        if (empty($locale)) {
            return config('app.locale', 'en'); // Fallback al locale por defecto
        }

        // Solo permitir caracteres alfanuméricos, guiones y guiones bajos
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($locale));

        if (empty($sanitized)) {
            return config('app.locale', 'en');
        }

        // Convertir a minúsculas
        $sanitized = strtolower($sanitized);

        // Normalizar formato (convertir guiones bajos a guiones para BCP 47)
        $sanitized = str_replace('_', '-', $sanitized);

        // Limitar longitud
        $sanitized = substr($sanitized, 0, self::MAX_LOCALE_LENGTH);

        // Validar formato básico (xx o xx-XX)
        if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $sanitized)) {
            return config('app.locale', 'en');
        }

        return $sanitized;
    }

    /**
     * Sanitiza URL para uso en CSP
     */
    public static function sanitizeUrl(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        // Limitar longitud
        if (strlen($url) > 2000) {
            return null;
        }

        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        $allowedSchemes = ['http', 'https', 'ws', 'wss'];
        if (!in_array($parsed['scheme'], $allowedSchemes)) {
            return null;
        }

        // Validar que el host no contenga caracteres peligrosos
        if (preg_match('/[<>"\'\\\]/', $parsed['host'])) {
            return null;
        }

        $cleanUrl = $parsed['scheme'] . '://' . $parsed['host'];

        if (isset($parsed['port']) && is_numeric($parsed['port'])) {
            $port = (int) $parsed['port'];
            if ($port > 0 && $port <= 65535) {
                $cleanUrl .= ':' . $port;
            }
        }

        return $cleanUrl;
    }

    /**
     * Sanitiza parámetros de archivo para prevenir path traversal
     */
    public static function sanitizeFilePath(string $path): string
    {
        if (empty($path)) {
            return '';
        }

        // Remover caracteres peligrosos
        $sanitized = preg_replace('/[^a-zA-Z0-9._\-\/]/', '', $path);

        // Prevenir path traversal
        $sanitized = str_replace(['../', '..\\', '..'], '', $sanitized);

        // Remover barras múltiples
        $sanitized = preg_replace('/\/+/', '/', $sanitized);

        // Remover barra inicial y final
        return trim($sanitized, '/');
    }

    /**
     * Validar y sanitizar tokens/hashes
     */
    public static function sanitizeToken(string $token): string
    {
        if (empty($token)) {
            throw new \InvalidArgumentException('Token no puede estar vacío');
        }

        // Solo permitir caracteres alfanuméricos y algunos especiales comunes en tokens
        $sanitized = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $token);

        if (empty($sanitized)) {
            throw new \InvalidArgumentException('Token contiene caracteres no válidos');
        }

        // Limitar longitud
        return substr($sanitized, 0, 255);
    }

    /**
     * Sanitizar datos para logging (remover información sensible)
     */
    public static function sanitizeForLogging(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            // Claves sensibles que deben ser redactadas
            $sensitiveKeys = [
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

            if (in_array(strtolower($key), $sensitiveKeys)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeForLogging($value);
            } elseif (is_string($value)) {
                // Truncar valores muy largos
                $sanitized[$key] = strlen($value) > 200 ? substr($value, 0, 200) . '...' : $value;
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Generar hash seguro para IPs (para logging sin exponer la IP real)
     */
    public static function hashIp(string $ip): string
    {
        if (empty($ip)) {
            return 'unknown';
        }

        // Usar salt único por aplicación para consistencia
        $salt = config('app.key', 'default_salt');
        return substr(hash('sha256', $ip . $salt), 0, 12);
    }

    /**
     * Verificar si una cadena contiene contenido potencialmente peligroso
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
                return true;
            }
        }

        return false;
    }
}
