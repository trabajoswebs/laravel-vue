<?php

namespace App\Providers;

use App\Observers\MediaObserver;
use App\Services\ImagePipeline;
use App\Services\Upload\Contracts\UploadPipeline;
use App\Services\Upload\Contracts\UploadService;
use App\Services\Upload\Core\LocalQuarantineRepository;
use App\Services\Upload\Core\QuarantineRepository;
use App\Services\Upload\DefaultUploadService;
use App\Services\Upload\ImageUploadPipelineAdapter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Proveedor de servicios principal de la aplicación.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra servicios de la aplicación.
     */
    public function register(): void
    {
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

        $this->app->singleton(UploadPipeline::class, static function ($app): UploadPipeline {
            $workingDirectory = storage_path('app/uploads/tmp');

            return new ImageUploadPipelineAdapter(
                $app->make(ImagePipeline::class),
                $workingDirectory
            );
        });

        $this->app->singleton(UploadService::class, DefaultUploadService::class);
    }

    /**
     * Inicializa servicios de la aplicación.
     */
    public function boot(): void
    {
        // Previene la carga perezosa en producción
        if (app()->environment('production')) {
            Model::preventLazyLoading();
        }

        // Registra el observador para el modelo Media
        Media::observe(MediaObserver::class);

        // Configura Vite para usar un nonce de CSP si está disponible
        $this->app->afterResolving(Vite::class, function (Vite $vite, $app): void {
            $nonce = null;

            if ($app->bound('request')) {
                $request = $app['request'];

                if ($request && isset($request->attributes)) {
                    $nonce = $request->attributes->get('csp-nonce');
                }
            }

            if (is_string($nonce) && $nonce !== '') {
                $vite->useCspNonce($nonce);
            }
        });
    }
}
