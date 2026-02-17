<?php

namespace App\Support\Localization;

// Importamos las clases necesarias
use App\Support\Security\SecurityHelper; // Helper para sanitización de seguridad
use Illuminate\Support\Facades\Log; // Facade para registrar logs
use Illuminate\Support\Facades\Cache; // Facade para manejar la cache
use JsonException; // Excepción para errores JSON

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
     * Este método limpia y formatea un locale para que cumpla con el estándar
     * de dos letras para el idioma seguido opcionalmente de un guión y dos letras para la región.
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
        // Elimina caracteres no permitidos (solo letras, números, guiones bajos y guiones)
        $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($locale));
        if ($clean === '' || $clean === null) {
            return '';
        }

        // Normalizar underscore a guion
        $clean = str_replace('_', '-', $clean);

        // Dividimos en partes (idioma y región)
        $parts = explode('-', $clean, 2);
        $lang = strtolower($parts[0] ?? ''); // Idioma en minúsculas
        $region = isset($parts[1]) ? strtoupper($parts[1]) : null; // Región en mayúsculas

        // Devuelve formato idioma-REGIÓN o solo idioma si no hay región
        return $region ? "{$lang}-{$region}" : $lang;
    }

    /**
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

        // Coincidencia exacta con un locale soportado
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
            $userLocale = $user->locale ?? $user->language ?? $user->preferred_language ?? null;

            if (is_string($userLocale) && self::validateLocale($userLocale)) {
                return self::normalizeToPrimaryLocale($userLocale, $supportedLocales);
            }
        }

        // 2. Sesión
        if ($sessionLocale = $request->session()->get('locale')) {
            if (is_string($sessionLocale) && self::validateLocale($sessionLocale)) {
                return self::normalizeToPrimaryLocale($sessionLocale, $supportedLocales);
            }
        }

        // 3. Cookie
        if ($cookieLocale = $request->cookie('locale')) {
            if (is_string($cookieLocale) && self::validateLocale($cookieLocale)) {
                return self::normalizeToPrimaryLocale($cookieLocale, $supportedLocales);
            }
        }

        // 4. Accept-Language
        $browserLocale = $request->getPreferredLanguage($supportedLocales);
        if ($browserLocale && self::validateLocale($browserLocale)) {
            return self::normalizeToPrimaryLocale($browserLocale, $supportedLocales);
        }

        // 5. fallback
        return config('locales.fallback', config('app.locale', 'es'));
    }

    /**
     * Comprueba si el store de cache actual soporta tags.
     *
     * Algunos drivers de cache (como Redis) soportan tags para agrupar claves,
     * lo que facilita la limpieza de grupos de entradas de cache.
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
     * Carga traducciones desde archivos y las cachea.
     *
     * Sanea el locale antes de cargar y aplica sanitización a las traducciones.
     * Utiliza cache para mejorar el rendimiento.
     *
     * @param string $locale Locale para cargar.
     * @return array Traducciones cargadas y sanitizadas.
     */
    public static function loadTranslations(string $locale): array
    {
        $sanitizedInput = self::sanitizeLocale($locale);

        // Coherencia: locale vacío → se trata como "sin locale", sin log de warning
        if ($sanitizedInput === '' || !self::validateLocale($locale)) {
            if (trim($locale) !== '') {
                Log::warning("Requested invalid locale: {$locale}");
            }

            $fallback = config('locales.fallback', config('app.locale', 'es'));
            $san = self::sanitizeLocale($fallback) ?: 'es';
        } else {
            $san = $sanitizedInput;
        }

        // Generamos la clave de cache
        $cacheKey = "translations:{$san}";

        // Configuramos el tiempo de vida de la cache (en minutos)
        $hours = (int) config('locales.cache_hours', 6);
        $ttlMinutes = max(1, $hours * 60);
        $expiresAt = now()->addMinutes($ttlMinutes);

        // Función para cargar y sanitizar traducciones
        $loader = function () use ($san) {
            $translations = self::loadTranslationFiles($san);
            return self::sanitizeTranslations($translations);
        };

        $useTags = self::supportsCacheTags();

        if ($useTags) {
            return Cache::tags(['translations'])->remember($cacheKey, $expiresAt, $loader);
        }

        return Cache::remember($cacheKey, $expiresAt, $loader);
    }

    /**
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
        $baseDir = realpath(lang_path($locale)) ?: null;

        foreach ($files as $file) {
            $basename = basename((string) $file);
            if ($basename === '') {
                continue;
            }

            $path = lang_path("{$locale}/{$basename}.php");
            $realPath = realpath($path);

            if ($realPath === false) {
                continue;
            }

            // Mitigar path traversal: asegurar que el archivo está dentro de lang/$locale
            if ($baseDir !== null) {
                $normalizedBase = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                $normalizedReal = rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

                if (!str_starts_with($normalizedReal, $normalizedBase)) {
                    Log::warning('Translation file outside locale directory skipped', [
                        'locale' => $locale,
                        'file'   => $file,
                        'path'   => $realPath,
                    ]);

                    continue;
                }
            }

            try {
                /** @var mixed $arr */
                $arr = require $realPath;
                if (is_array($arr)) {
                    $translations[$basename] = $arr;
                }
            } catch (\Throwable $e) {
                Log::warning("Translation file error: {$realPath}", ['error' => $e->getMessage()]);
            }
        }

        // JSON plano (puede sobrescribir claves)
        $jsonPath = lang_path("{$locale}.json");
        $jsonRealPath = realpath($jsonPath);

        if ($jsonRealPath !== false && is_file($jsonRealPath)) {
            try {
                $content = file_get_contents($jsonRealPath);

                if ($content === false) {
                    Log::warning("Failed to read JSON translations: {$jsonRealPath}");
                } else {
                    $maxDepth = (int) config('locales.json.max_depth', 8);

                    $json = json_decode($content, true, $maxDepth, JSON_THROW_ON_ERROR);
                    if (is_array($json)) {
                        if (self::validateJsonStructure($json)) {
                            $translations = array_replace_recursive($translations, $json);
                        } else {
                            Log::warning("Invalid translation JSON structure: {$jsonRealPath}");
                        }
                    } else {
                        Log::warning("Invalid JSON structure (not array) in translations: {$jsonRealPath}");
                    }
                }
            } catch (JsonException $e) {
                Log::warning("Invalid JSON in translations: {$jsonRealPath}", ['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                Log::warning("Failed to load JSON translations: {$jsonRealPath}", ['error' => $e->getMessage()]);
            }
        }

        return $translations;
    }

    /**
     * Valida la estructura de un array JSON de traducciones.
     *
     * Impone límites en profundidad, longitud de claves y número total de claves.
     * Bloquea contenido potencialmente peligroso como etiquetas <script>,
     * iframes y atributos on*.
     *
     * @param array     $json     Array a validar.
     * @param int       $depth    Profundidad actual (para recursión).
     * @param array<int,int>|null $counter Contador de claves (para recursión).
     * @return bool True si la estructura es válida.
     */
    protected static function validateJsonStructure(array $json, int $depth = 0, ?array &$counter = null): bool
    {
        // Obtenemos límites de configuración
        $maxDepth  = (int) config('locales.json.max_depth', 8);
        $maxKeyLen = (int) config('locales.json.max_key_length', 250);
        $maxKeys   = (int) config('locales.json.max_total_keys', 2000);

        // Inicializamos el contador si es la primera llamada
        if ($counter === null) {
            $counter = ['keys' => 0];
        }

        $depth++;
        if ($depth > $maxDepth) {
            return false;
        }

        foreach ($json as $k => $v) {
            // Validamos que la clave sea string y no exceda la longitud
            if (!is_string($k) || mb_strlen($k) > $maxKeyLen) {
                return false;
            }

            $counter['keys']++;
            if ($counter['keys'] > $maxKeys) {
                return false;
            }

            // Si es array, validamos recursivamente
            if (is_array($v)) {
                if (!self::validateJsonStructure($v, $depth, $counter)) {
                    return false;
                }
                // Si es escalar, validamos que sea de tipo permitido
            } elseif (!is_string($v) && !is_numeric($v) && !is_bool($v)) {
                return false;
            } else {
                // Si es string, verificamos contenido peligroso
                if (is_string($v)) {
                    $value = $v;

                    // Heurística XSS: bloquear <script>, <iframe> y atributos on*
                    if (
                        preg_match('/<\s*script\b/i', $value) ||
                        preg_match('/<\s*iframe\b/i', $value) ||
                        preg_match('/on\w+\s*=/i', $value)
                    ) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
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
        $allowHtml   = (bool) config('locales.allow_html', false);
        $allowedTags = (string) config('locales.allowed_html_tags', '');

        return self::walkTranslations($translations, $allowHtml, $allowedTags);
    }

    /**
     * Limpiar cache de traducciones (usa tags si están soportados)
     *
     * @return array Resultado de la operación.
     */
    public static function clearTranslationCache(): array
    {
        $supported = config('locales.supported', ['es', 'en']);
        $cleared   = [];

        try {
            if (self::supportsCacheTags()) {
                Cache::tags(['translations'])->flush();

                return [
                    'success' => true,
                    'method'  => 'tags',
                    'cleared' => ['all'],
                ];
            }

            foreach ($supported as $loc) {
                $key = "translations:{$loc}";
                Cache::forget($key);
                $cleared[] = $key;
            }

            return [
                'success' => true,
                'method'  => 'individual',
                'cleared' => $cleared,
            ];
        } catch (\Throwable $e) {
            Log::error('Failed clearing translation cache', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtiene metadatos simples de un idioma (nombre, bandera, etc.).
     *
     * @param string $locale Locale para obtener metadatos.
     * @return array Metadatos del idioma.
     */
    public static function getLanguageMetadata(string $locale): array
    {
        $san = self::sanitizeLocale($locale);

        return config("locales.metadata.{$san}", [
            'name'        => $san,
            'native_name' => $san,
            'flag'        => '🌐',
            'direction'   => 'ltr',
        ]);
    }

    // ---------------------------------------------------------------------
    // Helpers privados
    // ---------------------------------------------------------------------

    /**
     * Normaliza un locale válido a uno soportado (manteniendo subtags o
     * recortando a primary si es necesario).
     *
     * @param string $locale
     * @param array  $supportedLocales
     * @return string
     */
    private static function normalizeToPrimaryLocale(string $locale, array $supportedLocales): string
    {
        $sanitized = self::sanitizeLocale($locale);

        if ($sanitized === '') {
            return $sanitized;
        }

        // Si el locale ya está en soportados, devolverlo
        if (in_array($sanitized, $supportedLocales, true)) {
            return $sanitized;
        }

        // Si tiene región (contiene '-'), intentar solo el idioma
        if (strpos($sanitized, '-') !== false) {
            [$primary] = explode('-', $sanitized, 2);
            if (in_array($primary, $supportedLocales, true)) {
                return $primary;
            }
        }

        // Si no se puede normalizar, devolver el original sanitizado
        return $sanitized;
    }

    /**
     * Sanitiza una cadena de traducción individual según la config de HTML.
     *
     * @param string $value Cadena a sanitizar
     * @param bool $allowHtml Si se permite HTML
     * @param string $allowedTags Tags HTML permitidas
     * @return string Cadena sanitizada
     */
    private static function sanitizeTranslationString(string $value, bool $allowHtml, string $allowedTags): string
    {
        $value = trim($value);

        if ($allowHtml && $allowedTags !== '') {
            try {
                // Usamos el helper de seguridad si está disponible
                return SecurityHelper::sanitizeTranslationContent($value);
            } catch (\Throwable $e) {
                // Fallback: sanitización manual
                $clean = strip_tags($value, $allowedTags); // Elimina tags no permitidos
                $clean = preg_replace(
                    '/\s*on\w+\s*=\s*(?:\'[^\']*\'|"[^"]*"|[^\s>]+)/iu',
                    '',
                    $clean
                ); // Elimina atributos on*
                $clean = preg_replace(
                    '/\b(href|src)\s*=\s*(["\']?)\s*javascript\s*:/iu',
                    '$1=$2#',
                    $clean
                ); // Bloquea javascript: en href/src

                return $clean;
            }
        }

        // Escape completo si HTML no permitido
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Recorre recursivamente la estructura de traducciones aplicando sanitización.
     *
     * @param mixed $item Elemento a procesar
     * @return mixed Elemento procesado
     */
    private static function walkTranslations(mixed $item, bool $allowHtml, string $allowedTags): mixed
    {
        // Si es array, procesamos cada elemento recursivamente
        if (is_array($item)) {
            $res = [];
            foreach ($item as $k => $v) {
                $res[$k] = self::walkTranslations($v, $allowHtml, $allowedTags);
            }

            return $res;
        }

        // Si es string, aplicamos sanitización específica
        if (is_string($item)) {
            return self::sanitizeTranslationString($item, $allowHtml, $allowedTags);
        }

        // Si es un tipo escalar, lo dejamos como está
        if (is_int($item) || is_float($item) || is_bool($item) || $item === null) {
            return $item;
        }

        // Si es otro tipo, lo convertimos a JSON y sanitizamos
        $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = '';
        }

        return self::sanitizeTranslationString($encoded, $allowHtml, $allowedTags);
    }
}
