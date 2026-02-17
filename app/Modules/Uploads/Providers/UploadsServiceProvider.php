<?php // Service provider para registrar perfiles de upload

declare(strict_types=1); // Tipado estricto

namespace App\Modules\Uploads\Providers; // Namespace del provider de uploads

use App\Modules\Uploads\Contracts\MediaArtifactCollector as MediaArtifactCollectorContract; // Contrato colector
use App\Modules\Uploads\Contracts\MediaCleanupScheduler as MediaCleanupSchedulerContract; // Contrato cleanup
use App\Modules\Uploads\Contracts\MediaUploader as MediaUploaderContract; // Contrato de uploader
use App\Application\Uploads\Contracts\OwnerIdNormalizerInterface;
use App\Application\Uploads\Contracts\UploadStorageInterface;
use App\Modules\Uploads\Adapters\LaravelUploadStorage;
use App\Infrastructure\Uploads\Core\Orchestrators\DocumentUploadGuard;
use App\Infrastructure\Uploads\Core\Orchestrators\MediaProfileResolver;
use App\Modules\Uploads\Services\ConfigurableOwnerIdNormalizer;
use App\Modules\Uploads\Services\MediaReplacementService; // Servicio de reemplazo
use App\Support\Contracts\MetricsInterface; // Métricas
use App\Infrastructure\Uploads\Core\Orchestrators\DefaultUploadOrchestrator; // Orquestador por defecto
use App\Modules\Uploads\Registry\UploadProfileRegistry; // Registro de perfiles
use App\Modules\Uploads\Repositories\EloquentUploadRepository; // Repo Eloquent de uploads
use App\Infrastructure\Uploads\Profiles\AvatarImageProfile; // Perfil avatar
use App\Infrastructure\Uploads\Profiles\CertificateSecretProfile; // Perfil certificados
use App\Infrastructure\Uploads\Profiles\DocumentPdfProfile; // Perfil PDF
use App\Infrastructure\Uploads\Profiles\GalleryImageProfile; // Perfil galería
use App\Infrastructure\Uploads\Profiles\ImportCsvProfile; // Perfil CSV
use App\Infrastructure\Uploads\Profiles\SpreadsheetXlsxProfile; // Perfil XLSX
use App\Modules\Uploads\Pipeline\Contracts\UploadPipeline; // Contrato pipeline
use App\Modules\Uploads\Pipeline\Contracts\UploadService; // Contrato servicio upload
use App\Modules\Uploads\Pipeline\DefaultUploadPipeline; // Pipeline por defecto
use App\Modules\Uploads\Pipeline\DefaultUploadService; // Servicio por defecto
use App\Modules\Uploads\Pipeline\ImageUploadPipelineAdapter; // Adaptador pipeline imagen
use App\Modules\Uploads\Pipeline\Image\ImagePipeline; // Pipeline de imagen
use App\Infrastructure\Uploads\Pipeline\Quarantine\LocalQuarantineRepository; // Repo de cuarentena
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineRepository; // Contrato de cuarentena
use App\Infrastructure\Uploads\Pipeline\Scanning\GitYaraRuleManager; // Gestor Yara git
use App\Infrastructure\Uploads\Pipeline\Scanning\ScanCoordinator; // Coordinador AV
use App\Infrastructure\Uploads\Pipeline\Scanning\ScanCoordinatorInterface; // Contrato coordinador AV
use App\Infrastructure\Uploads\Pipeline\Scanning\ScanCircuitStoreInterface;
use App\Infrastructure\Uploads\Pipeline\Scanning\LaravelCacheScanCircuitStore;
use App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\ClamAvScanner;
use App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\YaraScanner;
use App\Infrastructure\Uploads\Pipeline\Scanning\YaraRuleManager; // Gestor de reglas Yara
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use App\Support\Contracts\LoggerInterface as AppLoggerInterface;
use App\Infrastructure\Uploads\Pipeline\Security\MagicBytesValidator; // Validador de magic bytes
use App\Infrastructure\Uploads\Pipeline\Services\MediaCleanupScheduler; // Scheduler de cleanup
use App\Infrastructure\Uploads\Pipeline\Support\MediaArtifactCollector; // Colector de artefactos
use App\Application\Uploads\Contracts\UploadOrchestratorInterface; // Contrato de orquestador
use App\Application\Uploads\Contracts\UploadRepositoryInterface; // Contrato de repositorio
use Illuminate\Support\ServiceProvider; // Base ServiceProvider
use App\Support\Logging\SecurityLogger;
// Logger
use Illuminate\Support\Facades\Storage; // Storage
use InvalidArgumentException; // Excepciones de config

/**
 * Registra perfiles de upload y su registro centralizado.
 */
class UploadsServiceProvider extends ServiceProvider // Provider de uploads
{
    /**
     * Registra bindings de perfiles de upload.
     */
    public function register(): void // Define registro de perfiles
    {
        $this->app->singleton(UploadRepositoryInterface::class, EloquentUploadRepository::class); // Binding repositorio uploads
        $this->app->singleton(UploadOrchestratorInterface::class, DefaultUploadOrchestrator::class); // Binding orquestador
        $this->app->singleton(OwnerIdNormalizerInterface::class, ConfigurableOwnerIdNormalizer::class);
        $this->app->singleton(UploadStorageInterface::class, LaravelUploadStorage::class);
        $this->app->singleton(DocumentUploadGuard::class);
        $this->app->singleton(MediaProfileResolver::class);

        $this->app->singleton(UploadProfileRegistry::class, function ($app): UploadProfileRegistry { // Crea registro único
            $profiles = [
                'avatar_image' => $app->make(AvatarImageProfile::class), // Perfil avatar
                'gallery_image' => $app->make(GalleryImageProfile::class), // Perfil galería
                'document_pdf' => $app->make(DocumentPdfProfile::class), // Perfil PDF
                'spreadsheet_xlsx' => $app->make(SpreadsheetXlsxProfile::class), // Perfil XLSX
                'import_csv' => $app->make(ImportCsvProfile::class), // Perfil CSV import
                'certificate_secret' => $app->make(CertificateSecretProfile::class), // Perfil certificados
            ]; // Fin del array de perfiles

            return new UploadProfileRegistry($profiles); // Devuelve registro inicializado
        });

        $this->app->singleton(YaraRuleManager::class, static function (): YaraRuleManager {
            $config = (array) config('image-pipeline.scan.yara', []);

            return new GitYaraRuleManager(
                (string) ($config['rules_path'] ?? base_path('security/yara/images.yar')),
                (string) ($config['rules_hash_file'] ?? base_path('security/yara/rules.sha256')),
                is_string($config['version_file'] ?? null) ? $config['version_file'] : null,
            );
        });

        $this->app->singleton(QuarantineRepository::class, static function (): QuarantineRepository {
            $configuredDisk = config('media.quarantine.disk', 'quarantine');

            try {
                $filesystem = Storage::disk($configuredDisk);
            } catch (InvalidArgumentException $exception) {
                SecurityLogger::warning('quarantine.disk.invalid', [
                    'disk' => $configuredDisk,
                    'error' => $exception->getMessage(),
                ]);
                $filesystem = Storage::disk('quarantine');
            }

            return new LocalQuarantineRepository($filesystem);
        });

        $this->app->singleton(ImageUploadPipelineAdapter::class, static function ($app): ImageUploadPipelineAdapter {
            $workingDirectory = storage_path('app/uploads/tmp');

            return new ImageUploadPipelineAdapter(
                $app->make(ImagePipeline::class),  // Pipeline de procesamiento de imágenes
                $workingDirectory                  // Directorio temporal para subidas
            );
        });

        $this->app->singleton(UploadPipeline::class, static function ($app): UploadPipeline {
            $workingDirectory = storage_path('app/uploads/tmp');

            return new DefaultUploadPipeline(
                $workingDirectory,
                $app->make(ImageUploadPipelineAdapter::class),
                $app->make(MagicBytesValidator::class),
                $app->make(AppLoggerInterface::class),
                $app->make(MetricsInterface::class),
            );
        });

        $this->app->singleton(ScanCircuitStoreInterface::class, static fn($app): ScanCircuitStoreInterface => new LaravelCacheScanCircuitStore(
            $app->make(CacheRepository::class)
        ));

        $this->app->singleton(ScanCoordinator::class, static function ($app): ScanCoordinator {
            return new ScanCoordinator(
                scannerRegistry: [
                    ClamAvScanner::class => $app->make(ClamAvScanner::class),
                    YaraScanner::class => $app->make(YaraScanner::class),
                ],
                scanConfig: (array) config('image-pipeline.scan', []),
                circuitStore: $app->make(ScanCircuitStoreInterface::class),
                logger: $app->make(LoggerInterface::class),
                config: $app->make(ConfigRepository::class),
            );
        });
        $this->app->singleton(ScanCoordinatorInterface::class, static fn ($app): ScanCoordinatorInterface => $app->make(ScanCoordinator::class));
        $this->app->alias(ScanCoordinator::class, ScanCoordinatorInterface::class);

        $this->app->singleton(UploadService::class, DefaultUploadService::class);
        $this->app->singleton(MediaUploaderContract::class, DefaultUploadService::class);
        $this->app->singleton(MediaArtifactCollectorContract::class, MediaArtifactCollector::class);
        $this->app->singleton(MediaCleanupSchedulerContract::class, MediaCleanupScheduler::class);
        $this->app->singleton(MediaReplacementService::class, MediaReplacementService::class);
    }
}
