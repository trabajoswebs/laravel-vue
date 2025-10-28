<?php

namespace App\Services;

use App\Helpers\SecurityHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;


/**
 * Servicio para la gestión de traducciones con funcionalidades de saneamiento,
 * validación, detección de idioma y cache.
 * 
 * Este servicio centraliza la lógica de manejo de locales y traducciones,
 * aplicando validaciones de seguridad y optimizando el rendimiento con cache.
 * 
 * @example
 * $locale = TranslationService::sanitizeLocale('es_es');
 * $valid = TranslationService::validateLocale('es-ES');
 * $detected = TranslationService::detectUserLocale($request);
 * $translations = TranslationService::loadTranslations('es');
 */
class TranslationService
{
    /**
     * Normaliza un locale a formato estándar (idioma-REGIÓN).
     * 
     * Ejemplos:
     * - 'es-es' -> 'es-ES'
     * - 'ES' -> 'es'
     * - 'es_es' -> 'es-ES'
     * 
     * @param string $locale Locale a normalizar.
     * @return string Locale normalizado o cadena vacía si inválido.

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

     * Valida si un locale es soportado por la aplicación.
     * 
     * Acepta subtags (por ejemplo, 'es-ES' es válido si 'es' está en soportados).
     * 
     * @param string $locale Locale a validar.
     * @return bool True si es válido y está soportado.

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

     * Detecta el idioma preferido del usuario en orden de prioridad:
     * 1. Usuario autenticado (campo locale, language o preferred_language)
     * 2. Sesión ('locale')
     * 3. Cookie ('locale')
     * 4. Cabecera Accept-Language del navegador
     * 5. Valor por defecto (fallback)
     * 
     * @param \Illuminate\Http\Request $request Request actual.
     * @return string Locale detectado.

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

     * Comprueba si el store de cache actual soporta tags.
     * 
     * @return bool True si los tags son soportados.

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

     * Carga traducciones desde archivos y las cachea.
     * 
     * Sanea el locale antes de cargar y aplica sanitización a las traducciones.
     * 
     * @param string $locale Locale para cargar.
     * @return array Traducciones cargadas y sanitizadas.

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

     * Carga archivos de traducción PHP y JSON.
     * 
     * Los archivos PHP se cargan desde `lang/{locale}/{file}.php`.
     * El archivo JSON se carga desde `lang/{locale}.json` y tiene prioridad.
     * 
     * @param string $locale Locale para cargar.
     * @return array Traducciones combinadas.

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
     * Valida la estructura de un array JSON de traducciones.
     * 
     * Impone límites en profundidad, longitud de claves y número total de claves.
     * Bloquea contenido potencialmente peligroso como etiquetas <script>.
     * 
     * @param array $json Array a validar.
     * @param int $depth Profundidad actual (para recursión).
     * @param array|null &$counter Contador de claves (para recursión).
     * @return bool True si la estructura es válida.
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
     * Sanitiza recursivamente un array de traducciones.
     * 
     * Si está permitido HTML (config locales.allow_html), aplica sanitización
     * segura (SecurityHelper o heurísticos). Si no, escapa todo.
     * 
     * @param array $translations Traducciones a sanitizar.
     * @return array Traducciones sanitizadas.
     */
    protected static function sanitizeTranslations(array $translations): array
    {
        $allowHtml = (bool) config('locales.allow_html', false);
        $allowedTags = (string) config('locales.allowed_html_tags', '');

        $sanitizeString = function (string $value) use ($allowHtml, $allowedTags) {
            $value = trim($value);
            if ($allowHtml && $allowedTags !== '') {
                // Usar HTMLPurifier específico para traducciones
                try {
                    return SecurityHelper::sanitizeTranslationContent($value);
                } catch (\Exception $e) {
                    // Fallback a sanitización básica si HTMLPurifier falla
                    $clean = strip_tags($value, $allowedTags);
                    $clean = preg_replace('/\s*on\w+\s*=\s*(?:\'[^\']*\'|"[^"]*"|[^\s>]+)/iu', '', $clean);
                    $clean = preg_replace('/\b(href|src)\s*=\s*(["\']?)\s*javascript\s*:/iu', '$1=$2#', $clean);
                    return $clean;
                }
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

            if (is_string($item)) {
                return $sanitizeString($item);
            }

            if (is_int($item) || is_float($item) || is_bool($item) || $item === null) {
                return $item;
            }

            // Otros tipos: serializar de forma segura
            $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $encoded = '';
            }

            return $sanitizeString($encoded);
        };

        return $walker($translations);
    }

    /**
     * Limpiar cache de traducciones (usa tags si están soportados)

     * Limpia la cache de todas las traducciones.
     * 
     * Usa tags si están disponibles, sino borra claves individuales.
     * 
     * @return array Resultado de la operación.
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
     * Obtiene metadatos simples de un idioma (nombre, bandera, etc.).
     * 
     * @param string $locale Locale para obtener metadatos.
     * @return array Metadatos del idioma.
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
