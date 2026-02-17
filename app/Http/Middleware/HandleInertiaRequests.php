<?php

// Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Security\SecurityHelper;
use App\Infrastructure\Localization\TranslationService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Inertia\Middleware;
use JsonException;
use Tighten\Ziggy\Ziggy;

/**
 * Middleware de Inertia que centraliza los datos compartidos con la capa Vue.
 * Se encarga de exponer información global, traducciones y estados de sesión
 * en un formato seguro y consistente para el frontend.
 */
class HandleInertiaRequests extends Middleware
{
    private const LARGE_TRANSLATION_THRESHOLD = 50_000;

    /**
     * Vista raíz que Inertia debe renderizar.
     */
    protected $rootView = 'app';

    /**
     * Prepara y comparte la información global disponible para cada petición Inertia.
     * Incluye datos del usuario, estado de UI, traducciones e información de rutas.
     *
     * @param Request $request La solicitud HTTP actual.
     * @return array Los datos que se compartirán con el frontend.
     */
    public function share(Request $request): array
    {
        try {
            $locale = $this->resolveLocale($request);
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
                // Flash payload que Vue/Inertia muestra (strings sanitizadas).
                'flash' => array_filter([
                    'success' => $this->sanitizeFlashMessage($request, 'success'),
                    'message' => $this->sanitizeFlashMessage($request, 'message'),
                    'warning' => $this->sanitizeFlashMessage($request, 'warning'),
                    'error'   => $this->sanitizeFlashMessage($request, 'error'),
                    'description' => $this->sanitizeFlashMessage($request, 'description'),
                    'event'   => $this->prepareEventFlash($request),
                ]),

            ]);

            // Loguear tamaño en entorno local si procede
            $this->logShareDataSize($data);

            return $data;
        } catch (\Throwable $e) {
            $context = [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'url' => $request->url(),
            ];

            if (config('app.debug', false)) {
                $context['trace'] = $e->getTraceAsString();
            }

            Log::error('Error in HandleInertiaRequests::share', $context);

            return $this->getFallbackShareData($request);
        }
    }

    /**
     * Resuelve el locale del usuario utilizando sesión como caché.
     *
     * @param Request $request La solicitud HTTP actual.
     * @return string El código de idioma resuelto (por ejemplo, 'es', 'en').
     */
    protected function resolveLocale(Request $request): string
    {
        $session = $request->hasSession() ? $request->session() : null;

        if ($session && $session->has('locale')) {
            return (string) $session->get('locale');
        }

        $detected = TranslationService::detectUserLocale($request);

        if ($session) {
            $session->put('locale', $detected);
        }

        return $detected;
    }

    /**
     * Crea una representación segura del usuario autenticado para exponerla al cliente.
     *
     * @param Authenticatable|null $user El usuario autenticado o null si no está logueado.
     * @return array{
     *   id: int|string,
     *   name: string|null,
     *   email: string|null,
     *   avatar: string|null,
     *   locale: string|null,
     *   avatar_url: string|null,
     *   avatar_thumb_url: string|null,
     *   avatar_version: int|string|null
     * }|null
     *
     * Nota: `avatar_url`/`avatar_thumb_url` deben resolverse en O(1) (columnas, caché o eager loading).
     */
    protected function buildSafeUser(?Authenticatable $user): ?array
    {
        if (!$user) {
            return null;
        }

        $this->preloadUserMedia($user);

        $avatarUrl = $this->resolveUserAttribute($user, 'avatar_url');

        $userData = [
            'id' => $this->resolveUserId($user),
            'name' => $this->resolveUserAttribute($user, 'name'),
            'email' => $this->resolveUserAttribute($user, 'email'),
            'locale' => $this->resolveUserAttribute($user, 'locale'),
            'avatar' => $avatarUrl,
            'avatar_url' => $avatarUrl,
            'avatar_thumb_url' => $this->resolveUserAttribute($user, 'avatar_thumb_url'),
            'avatar_version' => $this->resolveUserAttribute($user, 'avatar_version'),
        ];

        return collect($userData)
            ->map(fn($value) => is_string($value) ? SecurityHelper::sanitizePlainText($value) : $value)
            ->toArray();
    }

    /**
     * Obtiene el ID del usuario de forma genérica.
     *
     * @param Authenticatable $user El usuario autenticado.
     * @return mixed El ID del usuario.
     */
    private function resolveUserId(Authenticatable $user): mixed
    {
        if (method_exists($user, 'getKey')) {
            return $user->getKey();
        }

        return $user->getAuthIdentifier();
    }

    /**
     * Obtiene un atributo del usuario de forma genérica.
     *
     * @param Authenticatable $user El usuario autenticado.
     * @param string $attribute El nombre del atributo a obtener.
     * @return mixed El valor del atributo.
     */
    private function resolveUserAttribute(Authenticatable $user, string $attribute): mixed
    {
        try {
            if (method_exists($user, 'getAttribute')) {
                return $user->getAttribute($attribute);
            }

            return $user->{$attribute} ?? null;
        } catch (\Throwable $e) {
            // Si no se puede leer el atributo (ej. falta tenant para avatar), degradar a null
            return null;
        }
    }

    /**
     * Precarga la relación de medios del usuario si es posible.
     *
     * @param Authenticatable $user El usuario autenticado.
     * @return void
     */
    /**
     * Intenta precargar la relación `media` cuando el modelo la expone (Spatie Media Library).
     */
    private function preloadUserMedia(Authenticatable $user): void
    {
        if (!($user instanceof Model) || !method_exists($user, 'loadMissing')) {
            return;
        }

        try {
            $user->loadMissing('media');
        } catch (\Throwable $e) {
            Log::debug('Failed to preload media relation', [
                'error' => $e->getMessage(),
                'user_id' => method_exists($user, 'getKey') ? $user->getKey() : null,
            ]);
        }
    }

    /**
     * Carga las traducciones para el locale solicitado aplicando un fallback seguro.
     *
     * @param string $locale El código de idioma para cargar las traducciones.
     * @return array Las traducciones cargadas.
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
     * @param string $locale El código de idioma actual.
     * @param array $translations Las traducciones cargadas.
     * @return array La estructura de datos de traducciones.
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
     * @param string $locale El código de idioma actual.
     * @return array Los metadatos del idioma.
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
     * @param Request $request La solicitud HTTP actual.
     * @return array La configuración de Ziggy.
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
     * @param Request $request La solicitud HTTP actual.
     * @return array|null Los datos del evento sanitizados o null si no hay evento.
     */
    protected function prepareEventFlash(Request $request): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }

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
            'variant' => $this->sanitizeToastVariant($event['variant'] ?? null),
        ]);

        return $sanitized ?: null;
    }

    /**
     * Obtiene un mensaje flash sanitizado o null si está vacío.
     *
     * @param Request $request La solicitud HTTP actual.
     * @param string $key La clave del mensaje flash.
     * @return string|null El mensaje sanitizado o null.
     */
    protected function sanitizeFlashMessage(Request $request, string $key): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

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
     * @param Request $request La solicitud HTTP actual.
     * @return bool True si la barra lateral debe estar abierta, false en caso contrario.
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
     * Sanitiza y valida el variant de un toast/evento.
     *
     * @param mixed $value El valor del variant a sanitizar.
     * @return string|null El variant sanitizado o null si no es válido.
     */
    protected function sanitizeToastVariant(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        $allowed = ['success', 'warning', 'error', 'info', 'event'];

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    /**
     * Define un payload mínimo y seguro cuando ocurre un error al compartir datos.
     *
     * @param Request $request La solicitud HTTP actual.
     * @return array Los datos de fallback para compartir.
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
                'metadata' => $this->getLanguageMetadata($fallbackLocale),
                'error' => true,
            ],
        ]);
    }

    /**
     * Registra métricas de tamaño del payload compartido para facilitar el debug.
     *
     * @param array $data Los datos compartidos actualmente.
     * @return void
     */
    protected function logShareDataSize(array $data): void
    {
        if (!app()->environment('local') || !config('app.debug')) {
            return;
        }

        try {
            $translationsSize = strlen(json_encode($data['serverTranslations']['messages'] ?? [], JSON_THROW_ON_ERROR));
            $totalSize = strlen(json_encode($data, JSON_THROW_ON_ERROR));

            if ($translationsSize > self::LARGE_TRANSLATION_THRESHOLD) {
                Log::info('Large translation data detected', [
                    'translations_size_bytes' => $translationsSize,
                    'total_size_bytes' => $totalSize,
                    'locale' => $data['serverTranslations']['locale'] ?? 'unknown',
                ]);
            }
        } catch (JsonException $e) {
            Log::warning('Failed to compute share payload size', ['error' => $e->getMessage()]);
        }
    }
}
