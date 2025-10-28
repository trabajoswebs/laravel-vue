<?php

namespace App\Http\Middleware;

use App\Helpers\SecurityHelper;
use App\Services\TranslationService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

/**
 * Middleware de Inertia que centraliza los datos compartidos con la capa Vue.
 * Se encarga de exponer información global, traducciones y estados de sesión
 * en un formato seguro y consistente para el frontend.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * Vista raíz que Inertia debe renderizar.
     */
    protected $rootView = 'app';

    /**
     * Determina la versión de los assets para el frontend.
     *
     * @param Request $request
     * @return string|null
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Prepara y comparte la información global disponible para cada petición Inertia.
     * Incluye datos del usuario, estado de UI, traducciones e información de rutas.
     *
     * @param Request $request
     * @return array
     */
    public function share(Request $request): array
    {
        try {
            //Detectar idioma
            $locale = TranslationService::detectUserLocale($request);
            // Configurar el locale de Laravel para que las validaciones usen el idioma correcto
            App::setLocale($locale);

            // Preparar payload base (usar array_merge para evitar spread en arrays asociativos)
            $base = parent::share($request);
            
            // Preparar datos seguros
            $translationData = $this->prepareTranslationData(
                $locale,
                $this->loadTranslationsWithFallback($locale)
            );

            $data = array_merge($base, [
                'name' => config('app.name'),
                'auth' => [
                    'user' => $this->buildSafeUser($request->user()),
                ],
                'ziggy' => $this->prepareZiggyData($request),
                'sidebarOpen' => $this->getSidebarState($request),
                'serverTranslations' => $translationData,
                // Exponer flashes para que el frontend (Inertia) pueda mostrar toasts
                'flash' => array_filter([
                    'success' => $this->sanitizeFlashMessage($request, 'success'),
                    'message' => $this->sanitizeFlashMessage($request, 'message'),
                    'warning' => $this->sanitizeFlashMessage($request, 'warning'),
                    'error'   => $this->sanitizeFlashMessage($request, 'error'),
                    'event'   => $this->prepareEventFlash($request),
                ]),

            ]);

            // Loguear tamaño en entorno local si procede
            $this->logShareDataSize($data);

            return $data;
        } catch (\Throwable $e) {
            Log::error('Error in HandleInertiaRequests::share', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'url' => $request->url(),
            ]);

            return $this->getFallbackShareData($request);
        }
    }

    /**
     * Crea una representación segura del usuario autenticado para exponerla al cliente.
     *
     * @param Authenticatable|null $user
     * @return array|null
     */
    protected function buildSafeUser(?Authenticatable $user): ?array
    {
        if (!$user) {
            return null;
        }

        $userData = $user->only(['id', 'name', 'email', 'avatar', 'locale']);

        $avatarUrl = $user->avatar_url ?? null;
        $thumbnailUrl = $user->avatar_thumb_url ?? null;
        $avatarVersion = $user->getAttribute('avatar_version');

        if (is_string($avatarUrl) && $avatarUrl !== '') {
            $userData['avatar'] = $avatarUrl;
        }

        $userData['avatar_url'] = $avatarUrl;
        $userData['avatar_thumb_url'] = $thumbnailUrl;
        $userData['avatar_version'] = $avatarVersion;

        return collect($userData)
            ->map(fn($value) => is_string($value) ? SecurityHelper::sanitizePlainText($value) : $value)
            ->toArray();
    }

    /**
     * Carga las traducciones para el locale solicitado aplicando un fallback seguro.
     *
     * @param string $locale
     * @return array
     */
    protected function loadTranslationsWithFallback(string $locale): array
    {
        try {
            return TranslationService::loadTranslations($locale);
        } catch (\Throwable $e) {
            Log::warning('Failed to load translations, using fallback', [
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);

            // Si el locale no es el fallback, intentar cargar las traducciones del fallback
            $fallbackLocale = config('locales.fallback', 'es');
            if ($locale !== $fallbackLocale) {
                try {
                    return TranslationService::loadTranslations($fallbackLocale);
                } catch (\Throwable $fallbackError) {
                    Log::error('Failed to load fallback translations', [
                        'fallback_locale' => $fallbackLocale,
                        'error' => $fallbackError->getMessage(),
                    ]);
                }
            }

            return [];
        }
    }

    /**
     * Construye la estructura de traducciones que consumirá el frontend.
     *
     * @param string $locale
     * @param array $translations
     * @return array
     */
    protected function prepareTranslationData(string $locale, array $translations): array
    {
        return [
            'locale' => $locale,
            'messages' => $translations,
            'fallbackLocale' => config('locales.fallback', 'es'),
            'supported' => config('locales.supported', ['es', 'en']),
            'metadata' => $this->getLanguageMetadata($locale),
        ];
    }

    /**
     * Obtiene metadatos del idioma activo para enriquecer la capa de presentación.
     *
     * @param string $locale
     * @return array
     */
    protected function getLanguageMetadata(string $locale): array
    {
        try {
            return TranslationService::getLanguageMetadata($locale);
        } catch (\Throwable $e) {
            Log::warning('Failed to get language metadata', [
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);

            return [
                'name' => $locale,
                'native_name' => $locale,
                'flag' => '🌐',
                'direction' => 'ltr'
            ];
        }
    }

    /**
     * Genera la configuración de Ziggy con la URL actual para uso en el cliente.
     *
     * @param Request $request
     * @return array
     */
    protected function prepareZiggyData(Request $request): array
    {
        try {
            $ziggy = (new Ziggy)->toArray();
            $ziggy['location'] = $request->url();
            return $ziggy;
        } catch (\Throwable $e) {
            Log::warning('Failed to prepare Ziggy data', [
                'error' => $e->getMessage(),
                'url' => $request->url(),
            ]);

            return [
                'location' => $request->url(),
                'routes' => [],
            ];
        }
    }

    /**
     * Limpia y normaliza los mensajes flash de eventos que deben mostrarse en UI.
     *
     * @param Request $request
     * @return array|null
     */
    protected function prepareEventFlash(Request $request): ?array
    {
        $event = $request->session()->get('event');

        if (!is_array($event)) {
            return null;
        }

        $sanitized = array_filter([
            'title' => isset($event['title']) && is_string($event['title'])
                ? SecurityHelper::sanitizePlainText($event['title'])
                : null,
            'description' => isset($event['description']) && is_string($event['description'])
                ? SecurityHelper::sanitizePlainText($event['description'])
                : null,
        ]);

        return $sanitized ?: null;
    }

    /**
     * Obtiene un mensaje flash sanitizado o null si está vacío.
     *
     * @param Request $request
     * @param string $key
     * @return string|null
     */
    protected function sanitizeFlashMessage(Request $request, string $key): ?string
    {
        $raw = $request->session()->get($key);

        if ($raw === null) {
            return null;
        }

        $clean = SecurityHelper::sanitizePlainText((string) $raw);

        return $clean === '' ? null : $clean;
    }

    /**
     * Determina si la barra lateral debe mostrarse abierta según la cookie persistida.
     *
     * @param Request $request
     * @return bool
     */
    protected function getSidebarState(Request $request): bool
    {
        if (!$request->hasCookie('sidebar_state')) {
            return true; // Default abierto
        }

        $state = $request->cookie('sidebar_state');
        return in_array($state, ['true', '1', 'on'], true);
    }

    /**
     * Define un payload mínimo y seguro cuando ocurre un error al compartir datos.
     *
     * @param Request $request
     * @return array
     */
    protected function getFallbackShareData(Request $request): array
    {
        $fallbackLocale = config('locales.fallback', 'es');

        return array_merge(parent::share($request), [
            'name' => config('app.name', 'Laravel'),
            'auth' => [
                'user' => null,
            ],
            'ziggy' => [
                'location' => $request->url(),
                'routes' => [],
            ],
            'sidebarOpen' => true,
            'serverTranslations' => [
                'locale' => $fallbackLocale,
                'messages' => [],
                'fallbackLocale' => $fallbackLocale,
                'supported' => config('locales.supported', ['es', 'en']),
                'metadata' => [
                    'name' => $fallbackLocale,
                    'native_name' => $fallbackLocale,
                    'flag' => '🌐',
                    'direction' => 'ltr'
                ],
                'error' => true,
            ],
        ]);
    }

    /**
     * Registra métricas de tamaño del payload compartido para facilitar el debug.
     *
     * @param array $data
     * @return void
     */
    protected function logShareDataSize(array $data): void
    {
        if (app()->environment('local') && config('app.debug')) {
            $translationsSize = strlen(serialize($data['serverTranslations']['messages'] ?? []));
            $totalSize = strlen(serialize($data));

            if ($translationsSize > 50000) { // ~50KB
                Log::info('Large translation data detected', [
                    'translations_size_bytes' => $translationsSize,
                    'total_size_bytes' => $totalSize,
                    'locale' => $data['serverTranslations']['locale'] ?? 'unknown',
                ]);
            }
        }
    }
}
