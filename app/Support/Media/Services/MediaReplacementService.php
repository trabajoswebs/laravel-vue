<?php

declare(strict_types=1);

namespace App\Support\Media\Services;

use App\Services\ImageUploadService;
use App\Support\Media\Contracts\MediaOwner;
use App\Support\Media\ImageProfile;
use App\Support\Media\MediaArtifactCollector;
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
        // Obtener el nombre de la colección multimedia donde se almacenará el archivo.
        $collection = $profile->collection();

        // Recolectar artefactos residuales solo si es una colección de archivo único.
        $snapshot = $profile->isSingleFile()
            ? $this->collector->collect($owner, $collection) // Recolecta artefactos del archivo actual
            : [];

        // Subir el nuevo archivo multimedia usando el servicio de subida.
        $media = $this->uploader->upload($owner, $file, $profile);
        $conversions = $this->prepareConversions($media, $profile);

        // Si se recolectaron artefactos del archivo anterior, encolar un job para limpiarlos.
        if ($snapshot !== []) {
            $this->scheduleCleanupAfterCommit($snapshot, $media, $conversions); // Espera conversions del nuevo media antes de limpiar
        }

        // Devolver la instancia del nuevo archivo multimedia.
        return $media;
    }

    /**
     * Normaliza conversions y marca cleanup pendiente.
     */
    private function prepareConversions(Media $media, ImageProfile $profile): array
    {
        $conversions = $this->normalizeConversions($profile->conversions());
        $this->cleanupScheduler->flagPendingConversions($media, $conversions);

        return $conversions;
    }

    /**
     * Encola el job `CleanupMediaArtifactsJob` para eliminar los artefactos recolectados.
     *
     * Este método se ejecuta solo después de que la transacción de base de datos haya sido
     * confirmada exitosamente (`DB::afterCommit`), asegurando que la base de datos refleje
     * el nuevo estado antes de intentar limpiar archivos residuales.
     *
     * @param array<int, array{media: Media, artifacts: array<string, list<string>>}> $snapshot
     *      Resultado de la recolección de artefactos.
     * @param Media $newMedia Media recién creado que dispara el cleanup tras sus conversions.
     */
    private function scheduleCleanupAfterCommit(array $snapshot, Media $newMedia, array $conversions): void
    {
        // Si no hay artefactos recolectados, no hay nada que limpiar.
        if ($snapshot === []) {
            return;
        }

        // Ejecutar la lógica de limpieza solo después de que la transacción haya sido confirmada.
        DB::afterCommit(function () use ($snapshot, $newMedia, $conversions) {
            $preserve = [(string) $newMedia->getKey()];

            foreach ($snapshot as $entry) {
                if (
                    !isset($entry['media'], $entry['artifacts']) ||
                    !$entry['media'] instanceof Media ||
                    !is_array($entry['artifacts'])
                ) {
                    continue;
                }

                $media = $entry['media'];
                $mediaId = (string) $media->getKey();
                $formatted = $this->formatArtifactsPerMedia($entry['artifacts'], $mediaId);

                if ($formatted === []) {
                    continue;
                }

                try {
                    $this->cleanupScheduler->scheduleCleanup($media, $formatted, $preserve, $conversions);
                } catch (\Throwable $exception) {
                    Log::error('media.cleanup.schedule_failed', [
                        'media_id' => $mediaId,
                        'error'    => $exception->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * @param array<int,string> $conversions
     * @return array<int,string>
     */
    private function normalizeConversions(array $conversions): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($value) => is_string($value) ? trim($value) : null, $conversions),
            static fn ($value) => is_string($value) && $value !== ''
        )));
    }

    /**
     * @param array<string, mixed> $sources
     * @return array<string, list<array{dir:string,mediaId:string}>>
     */
    private function formatArtifactsPerMedia(array $sources, string $mediaId): array
    {
        $grouped = [];

        foreach ($sources as $disk => $paths) {
            if (!is_string($disk) || $disk === '' || !is_array($paths)) {
                continue;
            }

            foreach ($paths as $path) {
                if (!is_string($path)) {
                    continue;
                }

                $clean = trim($path);
                if ($clean === '') {
                    continue;
                }

                $grouped[$disk][] = [
                    'dir'     => $clean,
                    'mediaId' => $mediaId,
                ];
            }
        }

        foreach ($grouped as $disk => $entries) {
            $seen = [];
            $deduped = [];

            foreach ($entries as $item) {
                $key = $item['dir'] . '|' . $item['mediaId'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $deduped[] = $item;
            }

            $grouped[$disk] = $deduped;
        }

        return array_filter(
            $grouped,
            static fn ($items) => is_array($items) && $items !== []
        );
    }
}
