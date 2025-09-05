<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    /**
     * Normaliza locales:
     * - 'es-es' -> 'es-ES'
     * - 'ES' -> 'es'
     * - 'es_es' -> 'es-ES'
     */
    public static function sanitizeLocale(string $locale): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($locale));
        if ($clean === '') {
            return '';
        }

        // Normalizar underscore a guion
        $clean = str_replace('_', '-', $clean);

        $parts = explode('-', $clean, 2);
        $lang = strtolower($parts[0] ?? '');
        $region = isset($parts[1]) ? strtoupper($parts[1]) : null;

        return $region ? "{$lang}-{$region}" : $lang;
    }

    /**
     * Valida si el locale es soportado (acepta subtags como es-ES -> es)
     */
    public static function validateLocale(string $locale): bool
    {
        $san = self::sanitizeLocale($locale);
        if ($san === '') {
            return false;
        }

        $supported = config('locales.supported', ['es', 'en']);

        // Coincidencia exacta
        if (in_array($san, $supported, true)) {
            return true;
        }

        // Probar primary subtag (es-ES -> es)
        if (strpos($san, '-') !== false) {
            [$primary] = explode('-', $san, 2);
            if (in_array($primary, $supported, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detecta el idioma del usuario en orden de prioridad:
     * user -> session -> cookie -> navegador -> fallback
     */
    public static function detectUserLocale(\Illuminate\Http\Request $request): string
    {
        $supportedLocales = config('locales.supported', ['es', 'en']);

        // 1. Usuario autenticado
        if ($user = $request->user()) {
            $userLocale = $user->locale ?? $user->language ?? $user->preferred_language;
            if ($userLocale && self::validateLocale($userLocale)) {
                $sanitized = self::sanitizeLocale($userLocale);
                if (!in_array($sanitized, $supportedLocales, true) && strpos($sanitized, '-') !== false) {
                    [$primary] = explode('-', $sanitized, 2);
                    return $primary;
                }
                return $sanitized;
            }
        }

        // 2. Sesión
        if ($sessionLocale = $request->session()->get('locale')) {
            if (self::validateLocale($sessionLocale)) {
                $sanitized = self::sanitizeLocale($sessionLocale);
                if (!in_array($sanitized, $supportedLocales, true) && strpos($sanitized, '-') !== false) {
                    [$primary] = explode('-', $sanitized, 2);
                    return $primary;
                }
                return $sanitized;
            }
        }

        // 3. Cookie
        if ($cookieLocale = $request->cookie('locale')) {
            if (self::validateLocale($cookieLocale)) {
                $sanitized = self::sanitizeLocale($cookieLocale);
                if (!in_array($sanitized, $supportedLocales, true) && strpos($sanitized, '-') !== false) {
                    [$primary] = explode('-', $sanitized, 2);
                    return $primary;
                }
                return $sanitized;
            }
        }

        // 4. Accept-Language
        $browserLocale = $request->getPreferredLanguage($supportedLocales);
        if ($browserLocale && self::validateLocale($browserLocale)) {
            $sanitized = self::sanitizeLocale($browserLocale);
            if (!in_array($sanitized, $supportedLocales, true) && strpos($sanitized, '-') !== false) {
                [$primary] = explode('-', $sanitized, 2);
                return $primary;
            }
            return $sanitized;
        }

        // 5. fallback
        return config('locales.fallback', config('app.locale', 'es'));
    }

    /**
     * Comprueba si el store de cache soporta tags correctamente
     */
    protected static function supportsCacheTags(): bool
    {
        try {
            $store = Cache::getStore();
            return method_exists($store, 'tags');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Carga traducciones usando cache; sanea antes de cachear
     */
    public static function loadTranslations(string $locale): array
    {
        $san = self::sanitizeLocale($locale);
        if (!self::validateLocale($san)) {
            Log::warning("Requested invalid locale: {$locale}");
            $san = config('locales.fallback', config('app.locale', 'es'));
        }

        $cacheKey = "translations:{$san}";

        // TTL en minutos (config: locales.cache_hours, fallback 6 horas)
        $hours = (int) config('locales.cache_hours', 6);
        $ttlMinutes = max(1, $hours * 60);
        $expiresAt = now()->addMinutes($ttlMinutes);

        $loader = function () use ($san) {
            $translations = self::loadTranslationFiles($san);
            return self::sanitizeTranslations($translations);
        };

        if (self::supportsCacheTags()) {
            return Cache::tags(['translations'])->remember($cacheKey, $expiresAt, $loader);
        }

        return Cache::remember($cacheKey, $expiresAt, $loader);
    }

    /**
     * Carga los archivos PHP y el JSON; merge sencillo: JSON tiene prioridad (override)
     */
    protected static function loadTranslationFiles(string $locale): array
    {
        $translations = [];

        $files = config('locales.translation_files', []);
        foreach ($files as $file) {
            $path = lang_path("{$locale}/{$file}.php");
            if (is_file($path) && realpath($path) !== false) {
                try {
                    $arr = require $path;
                    if (is_array($arr)) {
                        $translations[$file] = $arr;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Translation file error: {$path}", ['error' => $e->getMessage()]);
                }
            }
        }

        // JSON plano (puede sobrescribir claves)
        $jsonPath = lang_path("{$locale}.json");
        if (is_file($jsonPath) && realpath($jsonPath) !== false) {
            try {
                $content = file_get_contents($jsonPath);
                $json = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                    if (self::validateJsonStructure($json)) {
                        $translations = array_replace_recursive($translations, $json);
                    } else {
                        Log::warning("Invalid translation JSON structure: {$jsonPath}");
                    }
                } else {
                    Log::warning("Invalid JSON in translations: {$jsonPath}", ['error' => json_last_error_msg()]);
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to load JSON translations: {$jsonPath}", ['error' => $e->getMessage()]);
            }
        }

        return $translations;
    }

    /**
     * Validación simple de estructura JSON (profundidad, longitud de keys, total keys)
     */
    protected static function validateJsonStructure(array $json, int $depth = 0, ?array &$counter = null): bool
    {
        $maxDepth = (int) config('locales.json.max_depth', 8);
        $maxKeyLen = (int) config('locales.json.max_key_length', 250);
        $maxKeys = (int) config('locales.json.max_total_keys', 2000);

        if ($counter === null) {
            $counter = ['keys' => 0];
        }

        $depth++;
        if ($depth > $maxDepth) {
            return false;
        }

        foreach ($json as $k => $v) {
            if (!is_string($k) || mb_strlen($k) > $maxKeyLen) {
                return false;
            }

            $counter['keys']++;
            if ($counter['keys'] > $maxKeys) {
                return false;
            }

            if (is_array($v)) {
                if (!self::validateJsonStructure($v, $depth, $counter)) {
                    return false;
                }
            } elseif (!is_string($v) && !is_numeric($v) && !is_bool($v)) {
                return false;
            } else {
                // heurística XSS: bloquear <script>
                if (is_string($v) && preg_match('/<\s*script\b/i', $v)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Sanitiza las traducciones recursivamente:
     * - Por defecto escapa todo (HTML deshabilitado)
     * - Si allow_html = true: permite tags listadas en config (strip_tags) y elimina atributos on* de forma robusta
     *
     * Nota: si necesitas HTML complejo, usa una librería como HTMLPurifier en lugar de heurísticos.
     */
    protected static function sanitizeTranslations(array $translations): array
    {
        $allowHtml = (bool) config('locales.allow_html', false);
        $allowedTags = (string) config('locales.allowed_html_tags', '');

        $sanitizeString = function (string $value) use ($allowHtml, $allowedTags) {
            $value = trim($value);
            if ($allowHtml && $allowedTags !== '') {
                // Permitir solo tags listadas
                $clean = strip_tags($value, $allowedTags);

                // Eliminar atributos on* (onclick, onmouseover...) - patrón más robusto
                $clean = preg_replace('/\s*on\w+\s*=\s*(?:\'[^\']*\'|"[^"]*"|[^\s>]+)/iu', '', $clean);

                // Neutralizar javascript: en href/src (simple heurística)
                $clean = preg_replace('/\b(href|src)\s*=\s*(["\']?)\s*javascript\s*:/iu', '$1=$2#', $clean);

                return $clean;
            }

            // Escape completo si HTML no permitido
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $walker = function ($item) use (&$walker, $sanitizeString) {
            if (is_array($item)) {
                $res = [];
                foreach ($item as $k => $v) {
                    $res[$k] = $walker($v);
                }
                return $res;
            }

            if (is_string($item) || is_numeric($item) || is_bool($item)) {
                return $sanitizeString((string)$item);
            }

            // Otros tipos: serializar de forma segura
            return htmlspecialchars(json_encode($item), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        return $walker($translations);
    }

    /**
     * Limpiar cache de traducciones (usa tags si están soportados)
     */
    public static function clearTranslationCache(): array
    {
        $supported = config('locales.supported', ['es', 'en']);
        $cleared = [];

        try {
            if (self::supportsCacheTags()) {
                Cache::tags(['translations'])->flush();
                return ['success' => true, 'method' => 'tags', 'cleared' => ['all']];
            }

            foreach ($supported as $loc) {
                $key = "translations:{$loc}";
                Cache::forget($key);
                $cleared[] = $key;
            }

            return ['success' => true, 'method' => 'individual', 'cleared' => $cleared];
        } catch (\Throwable $e) {
            Log::error('Failed clearing translation cache', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Metadata simple
     */
    public static function getLanguageMetadata(string $locale): array
    {
        $san = self::sanitizeLocale($locale);
        return config("locales.metadata.{$san}", [
            'name' => $san,
            'native_name' => $san,
            'flag' => '🌐',
            'direction' => 'ltr'
        ]);
    }
}
