<?php

namespace App\Http\Controllers;

use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class LanguageController extends Controller
{
    /**
     * Cambia el idioma del usuario con validación y rate limiting.
     */
    public function changeLanguage(Request $request, string $locale)
    {     
        // Validación de entrada
        $validator = Validator::make(['locale' => $locale], [
            'locale' => 'required|string|min:2|max:10|regex:/^[a-zA-Z0-9_\-]+$/'
        ]);        
        
        if ($validator->fails()) {
            return $this->handleError(
                'Invalid locale format', 
                $validator->errors()->first(), 
                $request,
                400
            );
        }

        // Rate limiting por usuario/ip
        $rateLimitKey = 'language-change:' . ($request->user()?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return $this->handleError(
                'Too many language changes. Please wait before trying again.',
                "Rate limit exceeded. Try again in {$seconds} seconds.",
                $request,
                429
            );
        }

        // Hit rate limiter (decay 5 minutos)
        RateLimiter::hit($rateLimitKey, 300);

        $sanitizedLocale = TranslationService::sanitizeLocale($locale);

        if (!TranslationService::validateLocale($sanitizedLocale)) {
            return $this->handleError(
                'Unsupported language',
                trans('language.unsupported_language', ['locale' => $sanitizedLocale]),
                $request,
                400
            );
        }

        try {
            // Persistir sesión y cookie (separado de la transacción DB)
            $this->persistLanguageChange($request, $sanitizedLocale);

            // Aplicar en tiempo de ejecución
            App::setLocale($sanitizedLocale);

            Log::info('Language changed successfully', [
                'locale' => $sanitizedLocale,
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Clear rate limit on success
            RateLimiter::clear($rateLimitKey);

            // Responder con datos actualizados
            return $this->handleSuccess(
                'Language changed successfully',
                trans('language.changed_successfully'),
                $request,
                [
                    'locale' => $sanitizedLocale,
                    'serverTranslations' => $this->getServerTranslations($sanitizedLocale)
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Error changing language', [
                'locale' => $sanitizedLocale,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->handleError(
                'Language change failed',
                trans('language.change_error'),
                $request,
                500
            );
        }
    }

    /**
     * Devuelve información del idioma actual
     */
    public function getCurrentLanguage(Request $request)
    {
        try {
            $locale = TranslationService::detectUserLocale($request);
            $metadata = TranslationService::getLanguageMetadata($locale);

            $data = [
                'locale' => $locale,
                'fallbackLocale' => config('locales.fallback', 'en'),
                'supported' => config('locales.supported', ['es', 'en']),
                'metadata' => $metadata,
                'detection_source' => $this->getDetectionSource($request, $locale),
                'serverTranslations' => $this->getServerTranslations($locale)
            ];

            return $this->handleSuccess(
                'Current language retrieved successfully',
                trans('language.current_language_retrieved_successfully'),
                $request,
                $data
            );
        } catch (\Throwable $e) {
            Log::error('Error getting current language', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return $this->handleError(
                'Error detecting current language',
                trans('language.detection_error'),
                $request,
                500
            );
        }
    }

    /**
     * Limpia la caché de traducciones (solo en local/testing y con permisos)
     */
    public function clearTranslationCache(Request $request)
    {
        if (!app()->environment('local', 'testing')) {
            Log::warning('Unauthorized cache clear attempt', [
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'environment' => app()->environment(),
            ]);

            return $this->handleError(
                'Operation not allowed',
                'Cache clearing only available in development environments',
                $request,
                403
            );
        }

        if ($request->user() && method_exists($request->user(), 'can')) {
            if (!$request->user()->can('clear-translation-cache')) {
                return $this->handleError(
                    'Insufficient permissions',
                    'You do not have permission to clear translation cache',
                    $request,
                    403
                );
            }
        }

        try {
            $result = TranslationService::clearTranslationCache();

            Log::info('Translation cache cleared', [
                'user_id' => $request->user()?->id,
                'result' => $result,
            ]);

            return $this->handleSuccess(
                'Cache cleared successfully',
                trans('language.cache_cleared'),
                $request,
                ['method' => $result['method'], 'cleared' => $result['cleared']]
            );
        } catch (\Throwable $e) {
            Log::error('Error clearing translation cache', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return $this->handleError(
                'Cache clear failed',
                trans('language.cache_clear_error'),
                $request,
                500
            );
        }
    }

    /**
     * Obtiene las traducciones del servidor para el locale dado
     */
    protected function getServerTranslations(string $locale): array
    {
        try {
            $messages = $this->loadTranslationMessages($locale);
            
            return [
                'locale' => $locale,
                'fallbackLocale' => config('locales.fallback', 'en'),
                'messages' => $messages,
                'supported' => config('locales.supported', ['es', 'en']),
                'metadata' => TranslationService::getLanguageMetadata($locale),
            ];
        } catch (\Throwable $e) {
            Log::warning('Failed to get server translations', [
                'locale' => $locale,
                'error' => $e->getMessage()
            ]);
            
            return [
                'locale' => $locale,
                'fallbackLocale' => config('locales.fallback', 'en'),
                'messages' => [],
                'supported' => config('locales.supported', ['es', 'en']),
                'metadata' => TranslationService::getLanguageMetadata($locale),
                'error' => true
            ];
        }
    }

    /**
     * Carga los mensajes de traducción para un locale específico
     */
    protected function loadTranslationMessages(string $locale): array
    {
        try {
            // Define qué traducciones necesitas en el frontend
            $frontendNamespaces = [
                'language',     // Mensajes del sistema de idiomas
                'auth',        // Autenticación (opcional)
                'validation',  // Validaciones (opcional)
                'passwords',   // Reset de passwords (opcional)
                'pagination',  // Paginación (opcional)
                // Agrega más según necesites
            ];

            $messages = [];
            
            foreach ($frontendNamespaces as $namespace) {
                $translations = $this->getNamespaceTranslations($namespace, $locale);
                if (!empty($translations)) {
                    $messages[$namespace] = $translations;
                }
            }

            return $messages;

        } catch (\Throwable $e) {
            Log::warning('Error loading translation messages', [
                'locale' => $locale,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Obtiene traducciones de un namespace específico
     */
    protected function getNamespaceTranslations(string $namespace, string $locale): array
    {
        try {
            // Cambiar temporalmente el locale
            $originalLocale = App::getLocale();
            App::setLocale($locale);

            // Obtener las traducciones del namespace
            $translations = trans($namespace);
            
            // Restaurar el locale original
            App::setLocale($originalLocale);

            // Solo devolver si es un array válido y no es la clave original
            return is_array($translations) ? $translations : [];

        } catch (\Throwable $e) {
            Log::debug('Failed to get namespace translations', [
                'namespace' => $namespace,
                'locale' => $locale,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Persiste la elección de idioma (sesión/cookie y DB update separado)
     */
    protected function persistLanguageChange(Request $request, string $locale): void
    {
        // Guardar en sesión y cookie (no transaccional)
        Session::put('locale', $locale);
        Cookie::queue('locale', $locale, 60 * 24 * 365 * 2);

        // Guardar en usuario con transacción DB solo si hay cambios en BD
        if ($user = $request->user()) {
            try {
                DB::transaction(function () use ($user, $locale) {
                    $this->updateUserLanguage($user, $locale);
                });
            } catch (\Throwable $e) {
                // Log pero no fallar la operación completa
                Log::warning('Failed to update user language in database', [
                    'user_id' => $user->id,
                    'locale' => $locale,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Actualiza el idioma del usuario (no falla si falla)
     */
    protected function updateUserLanguage($user, string $locale): void
    {
        try {
            $fieldToUpdate = collect(['locale', 'language', 'preferred_language'])
                ->first(fn ($field) => $user->getConnection()
                    ->getSchemaBuilder()
                    ->hasColumn($user->getTable(), $field));

            if ($fieldToUpdate) {
                $previous = $user->getOriginal($fieldToUpdate);

                if ($previous !== $locale) {
                    // Actualizar solo el campo detectado
                    $user->update([$fieldToUpdate => $locale]);

                    Log::info('User language preference updated', [
                        'user_id' => $user->id,
                        'field' => $fieldToUpdate,
                        'previous' => $previous,
                        'new' => $locale,
                    ]);
                }
            } else {
                Log::debug('No language field found in user model', [
                    'user_id' => $user->id,
                    'available_fields' => array_keys($user->getAttributes()),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to update user language preference', [
                'user_id' => $user->id ?? 'unknown',
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);
            
            // Re-throw para que la transacción falle si es necesario
            throw $e;
        }
    }

    protected function getDetectionSource(Request $request, string $detectedLocale): string
    {
        try {
            if ($user = $request->user()) {
                $userLocale = $user->locale ?? $user->language ?? $user->preferred_language;
                if ($userLocale && TranslationService::sanitizeLocale($userLocale) === $detectedLocale) {
                    return trans('language.detection_source_user');
                }
            }

            if ($sessionLocale = $request->session()->get('locale')) {
                if (TranslationService::sanitizeLocale($sessionLocale) === $detectedLocale) {
                    return trans('language.detection_source_session');
                }
            }

            if ($cookieLocale = $request->cookie('locale')) {
                if (TranslationService::sanitizeLocale($cookieLocale) === $detectedLocale) {
                    return trans('language.detection_source_cookie');
                }
            }

            $browserLocale = $request->getPreferredLanguage(config('locales.supported', []));
            if ($browserLocale && TranslationService::sanitizeLocale($browserLocale) === $detectedLocale) {
                return trans('language.detection_source_browser');
            }

            return trans('language.detection_source_default');
        } catch (\Throwable $e) {
            Log::warning('Error determining detection source', ['error' => $e->getMessage()]);
            return 'unknown';
        }
    }

    /**
     * Maneja respuestas exitosas de forma consistente
     */
    protected function handleSuccess(string $logMessage, string $userMessage, Request $request, array $data = [])
    {
        // Si es una petición Inertia
        if ($request->header('X-Inertia')) {
            return Redirect::back()->with([
                'success' => true,
                'message' => $userMessage,
                ...$data
            ]);
        }

        // Si es API JSON
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $userMessage,
                'data' => $data
            ], 200);
        }

        // Fallback para requests normales
        return Redirect::back()
            ->with('success', $userMessage)
            ->with($data);
    }

    /**
     * Maneja errores de forma consistente
     */
    protected function handleError(string $logMessage, string $userMessage, Request $request, int $status = 400)
    {
        // Sanitizar mensajes de usuario
        $sanitizedMessage = strip_tags($userMessage);
        $sanitizedMessage = substr($sanitizedMessage, 0, 500); // Limitar longitud
        
        // Si es Inertia
        if ($request->header('X-Inertia')) {
            return Redirect::back()->with([
                'error' => $sanitizedMessage,
                'success' => false,
                'message' => $sanitizedMessage // Para consistencia
            ]);
        }

        // Si es JSON
        if ($request->wantsJson()) {
            return response()->json([
                'success' => false,
                'message' => $sanitizedMessage,
                'error' => $logMessage
            ], $status);
        }

        // Fallback normal
        return Redirect::back()
            ->with('error', $sanitizedMessage)
            ->with('success', false)
            ->withInput()
            ->setStatusCode($status);
    }
}