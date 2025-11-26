<?php

declare(strict_types=1);

namespace App\Application\Media\Services;

use App\Application\User\Jobs\CleanupMediaArtifactsJob;
use App\Infrastructure\Media\ImageUploadService;
use App\Domain\User\Contracts\MediaOwner;
use App\Application\Media\DTO\CleanupPayload;
use App\Domain\Media\DTO\ConversionExpectations;
use App\Domain\Media\DTO\ReplacementResult;
use App\Domain\Media\DTO\ReplacementSnapshot;
use App\Domain\Media\ImageProfile;
use App\Infrastructure\Media\MediaArtifactCollector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Servicio encargado de orquestar la sustitución de un archivo multimedia (media) de un solo archivo.
 *
 * Este servicio se encarga de todo el proceso de reemplazo de un archivo multimedia en una colección
 * que solo permite un archivo (single-file). Esto implica:
 * 1. Recolectar los archivos o directorios residuales (artefactos) del archivo anterior (por ejemplo,
 *    versiones generadas como miniaturas).
 * 2. Subir el nuevo archivo.
 * 3. Programar una tarea (job) para eliminar los artefactos del archivo anterior, una vez que la
 *    operación en la base de datos haya sido completada exitosamente.
 */
final class MediaReplacementService
{
    /**
     * Constructor del servicio.
     *
     * @param ImageUploadService $uploader Servicio encargado de la lógica de subida de imágenes.
     * @param MediaArtifactCollector $collector Servicio encargado de recolectar artefactos residuales.
     */
    public function __construct(
        private readonly ImageUploadService $uploader,
        private readonly MediaArtifactCollector $collector,
        private readonly MediaCleanupScheduler $cleanupScheduler,
    ) {}

    /**
     * Reemplaza el archivo multimedia actual asociado al propietario con un nuevo archivo.
     *
     * Si el perfil de imagen indica que es un archivo único (`isSingleFile`), se recolectan
     * los artefactos del archivo anterior antes de subir el nuevo. Luego, se sube el nuevo
     * archivo y, si había artefactos, se encola un job para limpiarlos.
     *
     * @param MediaOwner $owner Modelo que posee el archivo multimedia (por ejemplo, un User o Post).
     * @param UploadedFile $file Archivo subido que reemplazará al actual.
     * @param ImageProfile $profile Perfil de imagen que define cómo se debe procesar el archivo.
     *
     * @return Media Instancia del nuevo archivo multimedia subido.
     */
    public function replace(MediaOwner $owner, UploadedFile $file, ImageProfile $profile): Media
    {
        return $this->replaceWithSnapshot($owner, $file, $profile)->media;
    }

    /**
     * Ejecuta el reemplazo y devuelve DTO con snapshot + conversions esperadas.
     */
    public function replaceWithSnapshot(
        MediaOwner $owner,
        UploadedFile $file,
        ImageProfile $profile
    ): ReplacementResult {
        $snapshot = $profile->isSingleFile()
            ? ReplacementSnapshot::fromLegacy($this->collector->collect($owner, $profile->collection()))
            : ReplacementSnapshot::empty();

        $media = $this->uploader->upload($owner, $file, $profile);
        $expectations = $this->prepareConversions($media, $profile);

        if (!$snapshot->isEmpty()) {
            $this->scheduleCleanupAfterCommit($snapshot, $media, $expectations);
        }

        return ReplacementResult::make($media, $snapshot, $expectations);
    }

    /**
     * Normaliza conversions y marca cleanup pendiente.
     */
    private function prepareConversions(Media $media, ImageProfile $profile): ConversionExpectations
    {
        $expectations = ConversionExpectations::fromList($profile->conversions());
        try {
            $this->cleanupScheduler->flagPendingConversions($media, $expectations->names);
        } catch (\Throwable $exception) {
            Log::warning('media.cleanup.flag_failed', [
                'media_id' => $media->getKey(),
                'error'    => $exception->getMessage(),
            ]);
        }

        return $expectations;
    }

    /**
     * Encola el job `CleanupMediaArtifactsJob` para eliminar los artefactos recolectados.
     *
     * Este método se ejecuta solo después de que la transacción de base de datos haya sido
     * confirmada exitosamente (`DB::afterCommit`), asegurando que la base de datos refleje
     * el nuevo estado antes de intentar limpiar archivos residuales.
     *
     * @param ReplacementSnapshot $snapshot Artefactos del media anterior agrupados por disco.
     * @param Media $newMedia Media recién creado que dispara el cleanup tras sus conversions.
     * @param ConversionExpectations $expectations Conversions esperadas para el nuevo media.
     */
    private function scheduleCleanupAfterCommit(
        ReplacementSnapshot $snapshot,
        Media $newMedia,
        ConversionExpectations $expectations
    ): void {
        if ($snapshot->isEmpty()) {
            return;
        }

        DB::afterCommit(function () use ($snapshot, $newMedia, $expectations) {
            $payload = CleanupPayload::fromSnapshot($snapshot, $newMedia, $expectations);

            if (!$payload->hasArtifacts()) {
                return;
            }

            try {
                $this->cleanupScheduler->scheduleCleanup(
                    $payload->triggerMedia,
                    $payload->artifacts,
                    $payload->preserveIds,
                    $payload->expectations->names
                );
            } catch (\Throwable $exception) {
                Log::error('media.cleanup.schedule_failed', [
                    'media_id' => $newMedia->getKey(),
                    'error'    => $exception->getMessage(),
                ]);

                try {
                    CleanupMediaArtifactsJob::dispatch(
                        $payload->artifacts,
                        $payload->preserveIds
                    );

                    Log::notice('media.cleanup.degraded_dispatch', [
                        'media_id'  => $newMedia->getKey(),
                        'reason'    => 'scheduler_unavailable',
                        'artifacts' => array_keys($payload->artifacts),
                    ]);
                } catch (\Throwable $dispatchException) {
                    Log::critical('media.cleanup.degraded_dispatch_failed', [
                        'media_id' => $newMedia->getKey(),
                        'error'    => $dispatchException->getMessage(),
                    ]);
                }
            }
        });
    }
}
