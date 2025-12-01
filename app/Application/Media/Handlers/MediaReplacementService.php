<?php

declare(strict_types=1);

namespace App\Application\Media\Handlers;

use App\Application\Media\Contracts\MediaArtifactCollector;
use App\Application\Media\Contracts\MediaCleanupScheduler;
use App\Application\Media\Contracts\MediaUploader;
use App\Application\Media\DTO\CleanupPayload;
use App\Application\Media\DTO\MediaReplacementResult;
use App\Application\Shared\Contracts\AsyncJobDispatcherInterface;
use App\Application\Shared\Contracts\LoggerInterface;
use App\Application\Shared\Contracts\TransactionManagerInterface;
use App\Domain\Media\DTO\ConversionExpectations;
use App\Domain\Media\DTO\MediaReplacementItemSnapshot;
use App\Domain\Media\DTO\MediaReplacementSnapshot;
use App\Application\Media\Contracts\MediaOwner;
use App\Domain\Media\Contracts\MediaResource;
use App\Application\Media\Contracts\MediaProfile;
use App\Application\Media\Contracts\UploadedMedia;
use App\Application\User\Jobs\CleanupMediaArtifacts;

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
     * @param MediaUploader $uploader Servicio encargado de la lógica de subida de imágenes.
     * @param MediaArtifactCollector $collector Servicio encargado de recolectar artefactos residuales.
     * @param MediaCleanupScheduler $cleanupScheduler Servicio para programar limpieza de artefactos.
     * @param LoggerInterface $logger Servicio de logging para eventos del sistema.
     */
    public function __construct(
        private readonly MediaUploader $uploader,
        private readonly MediaArtifactCollector $collector,
        private readonly MediaCleanupScheduler $cleanupScheduler,
        private readonly LoggerInterface $logger,
        private readonly AsyncJobDispatcherInterface $jobs,
        private readonly TransactionManagerInterface $transactions,
    ) {}

    /**
     * Reemplaza el archivo multimedia actual asociado al propietario con un nuevo archivo.
     *
     * Si el perfil de imagen indica que es un archivo único (`isSingleFile`), se recolectan
     * los artefactos del archivo anterior antes de subir el nuevo. Luego, se sube el nuevo
     * archivo y, si había artefactos, se encola un job para limpiarlos.
     *
     * @param MediaOwner $owner Modelo que posee el archivo multimedia (por ejemplo, un User o Post).
     * @param UploadedMedia $file Archivo subido que reemplazará al actual.
     * @param MediaProfile $profile Perfil de imagen que define cómo se debe procesar el archivo.
     * @param string|null $correlationId Identificador de correlación para trazabilidad.
     *
     * @return MediaResource Instancia del nuevo archivo multimedia subido.
     */
    public function replace(
        MediaOwner $owner,
        UploadedMedia $file,
        MediaProfile $profile,
        ?string $correlationId = null
    ): MediaResource
    {
        return $this->replaceWithSnapshot($owner, $file, $profile, $correlationId)->media;
    }

    /**
     * Ejecuta el reemplazo y devuelve DTO con snapshot + conversions esperadas.
     *
     * @param MediaOwner $owner Modelo propietario del media
     * @param UploadedMedia $file Archivo a subir
     * @param MediaProfile $profile Perfil de configuración del media
     * @param string|null $correlationId Identificador de correlación opcional.
     * @return MediaReplacementResult Resultado con el nuevo media y snapshot del anterior
     */
    public function replaceWithSnapshot(
        MediaOwner $owner,
        UploadedMedia $file,
        MediaProfile $profile,
        ?string $correlationId = null
    ): MediaReplacementResult {
        $snapshot = $profile->isSingleFile()
            ? $this->snapshotFromCollector($owner, $profile)
            : MediaReplacementSnapshot::empty();

        $media = $this->uploader->uploadSync($owner, $file, $profile, $correlationId);
        $expectations = $this->prepareConversions($media, $profile);

        if (!$snapshot->isEmpty()) {
            $this->scheduleCleanupAfterCommit($snapshot, $media, $expectations);
        }

        return MediaReplacementResult::make($media, $snapshot, $expectations);
    }

    /**
     * Normaliza conversions y marca cleanup pendiente.
     *
     * @param MediaResource $media Media recién creado
     * @param MediaProfile $profile Perfil de configuración
     * @return ConversionExpectations Expectativas de conversiones
     */
    private function prepareConversions(MediaResource $media, MediaProfile $profile): ConversionExpectations
    {
        $expectations = ConversionExpectations::fromList($profile->conversions());
        try {
            $this->cleanupScheduler->flagPendingConversions($media, $expectations->names);
        } catch (\Throwable $exception) {
            $this->logger->warning('media.cleanup.flag_failed', [
                'media_id' => $media->getKey(),
                'error'    => $exception->getMessage(),
            ]);
        }

        return $expectations;
    }

    /**
     * Encola la orden de cleanup para eliminar los artefactos recolectados.
     *
     * Este método se ejecuta solo después de que la transacción de base de datos haya sido
     * confirmada exitosamente (`DB::afterCommit`), asegurando que la base de datos refleje
     * el nuevo estado antes de intentar limpiar archivos residuales.
     *
     * @param MediaReplacementSnapshot $snapshot Artefactos del media anterior agrupados por disco.
     * @param MediaResource $newMedia Media recién creado que dispara el cleanup tras sus conversions.
     * @param ConversionExpectations $expectations Conversions esperadas para el nuevo media.
     */
    private function scheduleCleanupAfterCommit(
        MediaReplacementSnapshot $snapshot,
        MediaResource $newMedia,
        ConversionExpectations $expectations
    ): void {
        if ($snapshot->isEmpty()) {
            return;
        }

        $this->transactions->afterCommit(function () use ($snapshot, $newMedia, $expectations) {
            $payload = CleanupPayload::fromSnapshot(
                $snapshot,
                $this->toDomainSnapshotItem($newMedia),
                $expectations
            );

            if (!$payload->hasArtifacts()) {
                return;
            }

            try {
                $this->cleanupScheduler->scheduleCleanup(
                    $newMedia,
                    $payload->artifacts,
                    $payload->preserveIds,
                    $payload->expectations->names
                );
            } catch (\Throwable $exception) {
                $this->logger->error('media.cleanup.schedule_failed', [
                    'media_id' => $newMedia->getKey(),
                    'error'    => $exception->getMessage(),
                ]);

                try {
                    $this->jobs->dispatch(
                        new CleanupMediaArtifacts($payload->artifacts, $payload->preserveIds)
                    );

                    $this->logger->notice('media.cleanup.degraded_dispatch', [
                        'media_id'  => $newMedia->getKey(),
                        'reason'    => 'scheduler_unavailable',
                        'artifacts' => array_keys($payload->artifacts),
                    ]);
                } catch (\Throwable $dispatchException) {
                    $this->logger->critical('media.cleanup.degraded_dispatch_failed', [
                        'media_id' => $newMedia->getKey(),
                        'error'    => $dispatchException->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Convierte un MediaResource en un MediaReplacementItemSnapshot.
     *
     * @param MediaResource $media Media a convertir
     * @return MediaReplacementItemSnapshot Snapshot del media
     */
    private function toDomainSnapshotItem(MediaResource $media): MediaReplacementItemSnapshot
    {
        return MediaReplacementItemSnapshot::make(
            (string) $media->getKey(),
            $media->collectionName(),
            $media->disk(),
            $media->fileName(),
            []
        );
    }

    /**
     * Crea un snapshot de media anterior desde el recolector de artefactos.
     *
     * @param MediaOwner $owner Propietario del media
     * @param MediaProfile $profile Perfil de configuración
     * @return MediaReplacementSnapshot Snapshot del media anterior
     */
    private function snapshotFromCollector(MediaOwner $owner, MediaProfile $profile): MediaReplacementSnapshot
    {
        $items = [];

        foreach ($this->collector->collect($owner, $profile->collection()) as $entry) {
            $media = $entry['media'] ?? null;
            $artifacts = $entry['artifacts'] ?? [];

            if (!$media instanceof MediaResource || !is_array($artifacts)) {
                continue;
            }

            $items[] = MediaReplacementItemSnapshot::make(
                (string) $media->getKey(),
                $media->collectionName(),
                $media->disk(),
                $media->fileName(),
                $artifacts
            );
        }

        return MediaReplacementSnapshot::fromItems($items);
    }
}
