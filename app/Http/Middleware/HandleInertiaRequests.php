<?php

namespace App\Http\Middleware;

use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        try {
            // Detectar idioma
            $locale = TranslationService::detectUserLocale($request);

            // Configurar el locale de Laravel para que las validaciones usen el idioma correcto
            App::setLocale($locale);

            // Cargar traducciones con fallback robusto
            $translations = $this->loadTranslationsWithFallback($locale);

            // Preparar datos seguros
            $translationData = $this->prepareTranslationData($locale, $translations);

            // Preparar payload base (usar array_merge para evitar spread en arrays asociativos)
            $base = parent::share($request);

            // Exponer solo campos seguros del usuario
            $safeUser = null;
            if ($request->user()) {
                $safeUser = method_exists($request->user(), 'only')
                    ? $request->user()->only(['id', 'name', 'email', 'avatar', 'locale'])
                    : [
                        'id' => $request->user()->id ?? null,
                        'name' => $request->user()->name ?? null,
                    ];
            }

            $data = array_merge($base, [
                'name' => config('app.name'),
                'auth' => [
                    'user' => $safeUser,
                ],
                'ziggy' => $this->prepareZiggyData($request),
                'sidebarOpen' => $this->getSidebarState($request),
                'serverTranslations' => $translationData,
                // Exponer flashes para que el frontend (Inertia) pueda mostrar toasts
                'flash' => [
                    'success' => session('success'),
                    'message' => session('message'),
                    'error' => session('error'),
                ],
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

    protected function loadTranslationsWithFallback(string $locale): array
    {
        try {
            return TranslationService::loadTranslations($locale);
        } catch (\Throwable $e) {
            Log::warning('Failed to load translations, using fallback', [
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);

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

    protected function getSidebarState(Request $request): bool
    {
        if (!$request->hasCookie('sidebar_state')) {
            return true; // Default abierto
        }

        $state = $request->cookie('sidebar_state');
        return in_array($state, ['true', '1', 'on'], true);
    }

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
