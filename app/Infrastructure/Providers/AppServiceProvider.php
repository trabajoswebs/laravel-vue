<?php

namespace App\Infrastructure\Providers;

use App\Application\Media\Contracts\MediaArtifactCollector as MediaArtifactCollectorContract;
use App\Application\Media\Contracts\MediaCleanupScheduler as MediaCleanupSchedulerContract;
use App\Application\Media\Contracts\MediaProfile;
use App\Application\Media\Contracts\MediaUploader as MediaUploaderContract;
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
use App\Infrastructure\Media\Observers\MediaObserver;
use App\Infrastructure\Media\ImagePipeline;
use App\Infrastructure\Media\MediaArtifactCollector;
use App\Infrastructure\Media\Profiles\AvatarProfile;
use App\Infrastructure\Media\Services\MediaCleanupScheduler;
use App\Infrastructure\Media\Security\MagicBytesValidator;
use App\Infrastructure\Media\Security\YaraRuleManager;
use App\Infrastructure\Media\Security\GitYaraRuleManager;
use App\Infrastructure\Media\Security\Upload\UploadSecurityLogger;
use App\Infrastructure\Media\Upload\Contracts\UploadPipeline;
use App\Infrastructure\Media\Upload\Contracts\UploadService;
use App\Infrastructure\Media\Upload\Core\LocalQuarantineRepository;
use App\Infrastructure\Media\Upload\Core\QuarantineRepository;
use App\Infrastructure\Media\Upload\DefaultUploadPipeline;
use App\Infrastructure\Media\Upload\DefaultUploadService;
use App\Infrastructure\Media\Upload\ImageUploadPipelineAdapter;
use App\Infrastructure\Shared\Adapters\LaravelAsyncJobDispatcher;
use App\Infrastructure\Shared\Adapters\LaravelClock;
use App\Infrastructure\Shared\Adapters\LaravelEventBus;
use App\Infrastructure\Shared\Adapters\LaravelLogger;
use App\Infrastructure\Shared\Adapters\LaravelTransactionManager;
use App\Infrastructure\Shared\Metrics\LogMetrics;
use App\Infrastructure\User\Adapters\EloquentUserAvatarRepository;
use App\Infrastructure\User\Adapters\EloquentUserRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
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
     * Configura los bindings del contenedor de dependencias para:
     * - Repositorios de medios y usuarios
     * - Servicios de subida y procesamiento de archivos
     * - Adaptadores de pipelines de subida
     * - Repositorios de cuarentena
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
        $this->app->singleton(YaraRuleManager::class, static function (): YaraRuleManager {
            $config = (array) config('image-pipeline.scan.yara', []);

            return new GitYaraRuleManager(
                (string) ($config['rules_path'] ?? base_path('security/yara/images.yar')),
                (string) ($config['rules_hash_file'] ?? base_path('security/yara/rules.sha256')),
                is_string($config['version_file'] ?? null) ? $config['version_file'] : null,
            );
        });

        // Registra el repositorio de cuarentena como un singleton
        $this->app->singleton(QuarantineRepository::class, static function (): QuarantineRepository {
            $configuredDisk = config('media.quarantine.disk', 'quarantine');

            try {
                $filesystem = Storage::disk($configuredDisk);
            } catch (InvalidArgumentException $exception) {
                Log::warning('quarantine.disk.invalid', [
                    'disk' => $configuredDisk,
                    'error' => $exception->getMessage(),
                ]);
                $filesystem = Storage::disk('quarantine');
            }

            return new LocalQuarantineRepository($filesystem);
        });

        // Registra el adaptador de pipeline de subida de imágenes
        $this->app->singleton(ImageUploadPipelineAdapter::class, static function ($app): ImageUploadPipelineAdapter {
            $workingDirectory = storage_path('app/uploads/tmp');

            return new ImageUploadPipelineAdapter(
                $app->make(ImagePipeline::class),  // Pipeline de procesamiento de imágenes
                $workingDirectory                  // Directorio temporal para subidas
            );
        });

        // Todas las subidas deben pasar por MediaUploader → DefaultUploadService → DefaultUploadPipeline.
        $this->app->singleton(UploadPipeline::class, static function ($app): UploadPipeline {
            $workingDirectory = storage_path('app/uploads/tmp');

            return new DefaultUploadPipeline(
                $workingDirectory,
                $app->make(ImageUploadPipelineAdapter::class),
                $app->make(MagicBytesValidator::class),
                $app->make(UploadSecurityLogger::class),
                $app->make(MetricsInterface::class),
            );
        });

        // Registra servicios de subida y procesamiento de medios
        $this->app->singleton(UploadService::class, DefaultUploadService::class);
        $this->app->singleton(MediaUploaderContract::class, DefaultUploadService::class);
        $this->app->singleton(MediaArtifactCollectorContract::class, MediaArtifactCollector::class);
        $this->app->singleton(MediaCleanupSchedulerContract::class, MediaCleanupScheduler::class);
        $this->app->singleton(MediaProfile::class, AvatarProfile::class);

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
