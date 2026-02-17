<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\Security\SecurityHelper;
use Illuminate\Support\Facades\Log;

/**
 * Trait que centraliza la sanitización reutilizable para requests y middleware.
 */
trait SanitizesInputs
{
    private const MAX_DEPTH = 10;
    private const ALLOWED_METHODS = [
        'sanitizeUserName',
        'sanitizeUserInput',
        'sanitizeHtml',
        'sanitizeEmail',
        'sanitizeLocale',
        'sanitizePlainText',
        'sanitizeToken',
    ];

    /**
     * Sanitiza en base a la lista blanca y aplica fallback si falla.
     *
     * @param mixed  $value  Valores nulos se devuelven tal cual; booleans se serializan como '1' o '0'.
     * @param string $method
     * @param string $context
     * @return mixed
     */
    private function sanitizeFieldValue(mixed $value, string $method, string $context = '', int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException("Sanitization recursion depth exceeded at depth {$depth} for '{$context}'");
        }

        $normalizedMethod = $this->normalizeSanitizationMethod($method);
        if ($normalizedMethod === null) {
            throw new \InvalidArgumentException("Sanitization method '{$method}' not allowed ({$context})");
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitizedKey = $this->sanitizeArrayKey($key, $context, $depth);
                if (array_key_exists($sanitizedKey, $sanitized)) {
                    $originalPreview = is_string($key)
                        ? mb_substr($key, 0, 50) . (mb_strlen($key) > 50 ? '…' : '')
                        : (string) $key;

                    throw new \RuntimeException(
                        "Array key collision detected for '{$context}' at depth {$depth}: sanitized '{$sanitizedKey}' (original fragment '{$originalPreview}')"
                    );
                }
                $sanitized[$sanitizedKey] = $this->sanitizeFieldValue($item, $normalizedMethod, $context, $depth + 1);
            }

            return $sanitized;
        }

        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_numeric($value) && !is_bool($value)) {
            throw new \InvalidArgumentException("Unsupported value type for sanitization ({$context})");
        }

        $stringValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        return call_user_func([SecurityHelper::class, $normalizedMethod], $stringValue);
    }

    /**
     * Valida y sanitiza claves de arrays para prevenir payloads maliciosos.
     */
    private function sanitizeArrayKey(string|int $key, string $context, int $depth): string|int
    {
        if (is_int($key)) {
            return $key;
        }

        $trimmed = trim($key);
        if ($trimmed === '') {
            throw new \InvalidArgumentException("Empty array key encountered ({$context})");
        }

        try {
            $clean = SecurityHelper::sanitizePlainText($trimmed);
        } catch (\Throwable $e) {
            Log::error('Array key sanitization failed', [
                'context' => $context,
                'error' => $e->getMessage(),
                'depth' => $depth,
            ]);
            throw new \RuntimeException("Failed to sanitize array key for '{$context}'");
        }

        if ($clean === '') {
            throw new \InvalidArgumentException("Array key sanitized to empty string ({$context})");
        }

        if ($clean !== $trimmed) {
            Log::warning('Array key sanitized', [
                'context' => $context,
                'original_key' => mb_substr($trimmed, 0, 100) . (mb_strlen($trimmed) > 100 ? '...' : ''),
                'depth' => $depth,
            ]);
        }

        return $clean;
    }

    /**
     * Fallback cuando la sanitización preferida falla.
     */
    private function sanitizeFallback(string $original): string
    {
        try {
            return SecurityHelper::sanitizePlainText($original);
        } catch (\Throwable $e) {
            Log::warning('Fallback sanitization failed', [
                'context' => 'SanitizesInputs::sanitizeFallback',
                'error' => $e->getMessage(),
                'value_hash' => hash('sha256', $original),
                'length' => strlen($original),
            ]);
            return '';
        }
    }

    /**
     * Devuelve el método normalizado si está permitido; en caso contrario null.
     */
    protected function normalizeSanitizationMethod(string $method): ?string
    {
        $normalizedMethod = lcfirst($method);
        if (!\in_array($normalizedMethod, self::ALLOWED_METHODS, true)) {
            return null;
        }

        if (!method_exists(SecurityHelper::class, $normalizedMethod)) {
            return null;
        }

        return $normalizedMethod;
    }

    /**
     * Indica si un método pertenece a la whitelist de sanitización.
     */
    protected function isSanitizationMethodAllowed(string $method): bool
    {
        return $this->normalizeSanitizationMethod($method) !== null;
    }
}
