<?php

namespace App\Providers;

use App\Application\Shared\Contracts\AsyncJobDispatcherInterface;
use App\Application\Shared\Contracts\ClockInterface;
use App\Application\Shared\Contracts\EventBusInterface;
use App\Application\Shared\Contracts\MetricsInterface;
use App\Application\Shared\Contracts\LoggerInterface;
use App\Application\Shared\Contracts\TransactionManagerInterface;
use App\Domain\Security\Rules\AvatarHeaderRules;
use App\Domain\Security\Rules\RateLimitSignatureRules;
use App\Application\User\Contracts\UserAvatarRepository as UserAvatarRepositoryContract;
use App\Application\User\Contracts\UserRepository as UserRepositoryContract;
use App\Infrastructure\Uploads\Pipeline\Observers\MediaObserver;
use App\Infrastructure\Shared\Adapters\LaravelAsyncJobDispatcher;
use App\Infrastructure\Shared\Adapters\LaravelClock;
use App\Infrastructure\Shared\Adapters\LaravelEventBus;
use App\Infrastructure\Shared\Adapters\LaravelLogger;
use App\Infrastructure\Shared\Adapters\LaravelTransactionManager;
use App\Infrastructure\Shared\Metrics\LogMetrics;
use App\Support\Logging\SecurityLogger;
use App\Infrastructure\User\Adapters\EloquentUserAvatarRepository;
use App\Infrastructure\User\Adapters\EloquentUserRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Translation\Events\TranslationMissing;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Proveedor de servicios principal de la aplicación.
 * 
 * Registra bindings de dependencias y configura componentes de la aplicación.
 * Centraliza la inyección de dependencias para el sistema de medios y usuarios.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra servicios de la aplicación.
     * 
     * Configura los bindings del contenedor de dependencias para
     * servicios compartidos y repositorios de usuarios.
     */
    public function register(): void
    {
        // Adaptadores compartidos
        $this->app->bind(ClockInterface::class, LaravelClock::class);
        $this->app->bind(LoggerInterface::class, LaravelLogger::class);
        $this->app->bind(TransactionManagerInterface::class, LaravelTransactionManager::class);
        $this->app->bind(EventBusInterface::class, LaravelEventBus::class);
        $this->app->bind(AsyncJobDispatcherInterface::class, LaravelAsyncJobDispatcher::class);
        $this->app->singleton(AvatarHeaderRules::class, AvatarHeaderRules::class);
        $this->app->singleton(RateLimitSignatureRules::class, RateLimitSignatureRules::class);
        $this->app->singleton(MetricsInterface::class, LogMetrics::class);
        // Registra repositorios de usuarios
        $this->app->singleton(UserRepositoryContract::class, EloquentUserRepository::class);
        $this->app->singleton(UserAvatarRepositoryContract::class, EloquentUserAvatarRepository::class);
    }

    /**
     * Inicializa servicios de la aplicación.
     * 
     * Configura comportamientos globales y registra observadores de modelos.
     * Configura seguridad y optimizaciones de rendimiento.
     */
    public function boot(): void
    {
        // Previene la carga perezosa en producción para evitar N+1 queries
        if (app()->environment('production')) {
            Model::preventLazyLoading();
        }

        // En testing, cualquier traducción faltante debe fallar las pruebas; en otros entornos se registra.
        Event::listen(TranslationMissing::class, static function (TranslationMissing $event): void {
            if (app()->environment('testing')) {
                throw new \RuntimeException("Missing translation [{$event->key}] for locale [{$event->locale}]");
            }

            SecurityLogger::warning('translation_missing', [
                'key' => $event->key,
                'locale' => $event->locale,
                'namespace' => $event->namespace,
            ]);
        });

        RateLimiter::for('media-serving', static function (Request $request): array|Limit {
            $userId = $request->user()?->getAuthIdentifier();
            $ip = (string) $request->ip();

            if ($userId !== null) {
                return Limit::perMinute(300)->by('media-user:' . $userId);
            }

            return Limit::perMinute(120)->by('media-ip:' . $ip);
        });

        // Registra el observador para el modelo Media para limpieza automática de artefactos
        Media::observe(MediaObserver::class);

        // Configura Vite para usar un nonce de CSP si está disponible para seguridad
        $this->app->afterResolving(Vite::class, function (Vite $vite, $app): void {
            $nonce = null;

            if ($app->bound('request')) {
                $request = $app['request'];

                if ($request && isset($request->attributes)) {
                    $nonce = $request->attributes->get('csp-nonce');  // Nonce de Content Security Policy
                }
            }

            if (is_string($nonce) && $nonce !== '') {
                $vite->useCspNonce($nonce);  // Aplica nonce a scripts generados por Vite
            }
        });
    }
}
