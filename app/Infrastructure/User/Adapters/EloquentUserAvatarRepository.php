<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Adapters;

use App\Application\Media\Contracts\MediaArtifactCollector;
use App\Application\Media\Contracts\MediaOwner;
use App\Application\Media\Contracts\MediaProfile;
use App\Application\Media\Contracts\UploadedMedia;
use App\Application\Media\Handlers\MediaReplacementService;
use App\Application\User\Contracts\UserAvatarRepository;
use App\Application\User\DTO\AvatarDeletionResult;
use App\Application\User\DTO\AvatarUpdateResult;
use App\Domain\Media\Contracts\MediaResource;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class EloquentUserAvatarRepository implements UserAvatarRepository
{
    public function __construct(
        private readonly MediaReplacementService $replacement,        // Servicio para reemplazo de medios
        private readonly MediaArtifactCollector $artifactCollector,  // Servicio para recolección de artefactos
    ) {}

    /**
     * Reemplaza el avatar del usuario y devuelve datos normalizados del resultado.
     *
     * @param MediaOwner $user Usuario que posee el avatar
     * @param UploadedMedia $file Archivo subido por el usuario
     * @param MediaProfile $profile Perfil de configuración para el avatar
     * @param string $uploadUuid Identificador único de la subida
     * @return AvatarUpdateResult Resultado con información sobre la operación de actualización
     */
    public function replaceAvatar(MediaOwner $user, UploadedMedia $file, MediaProfile $profile, string $uploadUuid): AvatarUpdateResult
    {
        $collection = $profile->collection();    // Nombre de la colección (ej: 'avatar')
        $disk = $profile->disk();               // Disco de almacenamiento configurado
        $owner = $this->assertMediaCapable($user);
        $oldMedia = $owner->getFirstMedia($collection);  // Avatar anterior (si existe)

        $result = $this->replacement->replaceWithSnapshot($user, $file, $profile, $uploadUuid);  // Reemplaza el avatar
        $media = $this->unwrap($result->media);          // Obtiene instancia de Media de Spatie
        $media = tap($media)->refresh();                 // Refresca para obtener datos actualizados

        $media->setCustomProperty('upload_uuid', $uploadUuid);  // Guarda UUID de subida como propiedad personalizada
        $media->save();

        $version = rescue(
            static fn() => filled($value = $media->getCustomProperty('version')) ? (string) $value : null,
            null
        );  // Obtiene versión/hash del avatar

        return new AvatarUpdateResult(
            newMedia: $media,                   // Nuevo avatar
            oldMedia: $oldMedia,                // Avatar anterior
            version: $version,                  // Versión/hash del avatar
            collection: $collection,            // Colección usada
            disk: $disk,                       // Disco de almacenamiento
            headers: (array) $media->getCustomProperty('headers', []),  // Cabeceras asociadas
            uploadUuid: $uploadUuid,            // UUID de la subida
            url: $media->getUrl(),              // URL pública del avatar
            mediaId: (string) $media->getKey(), // ID del nuevo media
            oldMediaId: $oldMedia ? (string) $oldMedia->getKey() : null,  // ID del avatar anterior
        );
    }

    /**
     * Elimina el avatar actual (si existe) y devuelve datos para cleanup.
     *
     * @param MediaOwner $user Usuario del que se eliminará el avatar
     * @return AvatarDeletionResult Resultado con información sobre la operación de eliminación
     */
    public function deleteAvatar(MediaOwner $user): AvatarDeletionResult
    {
        $media = $user->getFirstMedia('avatar');  // Obtiene avatar actual
        if (!$media instanceof Media) {
            return new AvatarDeletionResult(false, null, []);  // No había avatar para eliminar
        }

        $mediaId = (string) $media->getKey();                    // ID del avatar
        $cleanupArtifacts = $this->artifactsForCleanup($user, $media);  // Artículos a limpiar

        try {
            $media->delete();  // Elimina el avatar
        } catch (\Throwable $e) {
            Log::error('Error eliminando media (S3/DB)', [
                'user_id'  => $user->getKey(),
                'media_id' => $mediaId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;  // Relanza la excepción
        }

        return new AvatarDeletionResult(true, $mediaId, $cleanupArtifacts, $media);
    }

    /**
     * Convierte un MediaResource en una instancia de Media de Spatie.
     *
     * @param MediaResource $resource Recurso multimedia a convertir
     * @return Media Instancia de Media de Spatie
     */
    private function unwrap(MediaResource $resource): Media
    {
        $raw = $resource->raw();
        if ($raw instanceof Media) {
            return $raw;
        }

        throw new \InvalidArgumentException('Expected Spatie Media resource');
    }

    /**
     * Obtiene los artefactos multimedia asociados al usuario y media para limpieza.
     *
     * @param MediaOwner $owner Usuario propietario
     * @param Media $media Media del que se buscarán artefactos
     * @return array<string,list<array{dir:string,mediaId:string}>> Artículos a limpiar agrupados por disco
     */
    private function artifactsForCleanup(MediaOwner $owner, Media $media): array
    {
        $entries = $this->artifactCollector->collectDetailed($owner, $media->collection_name ?? 'avatar');
        $targetId = (string) $media->getKey();  // ID del media objetivo
        $artifacts = [];

        foreach ($entries as $entry) {
            $entryMedia = $entry['media'] ?? null;
            if ($entryMedia instanceof MediaResource) {
                $entryMedia = $entryMedia->raw();
            }

            if (!$entryMedia instanceof Media || (string) $entryMedia->getKey() !== $targetId) {
                continue;  // No es el media que buscamos
            }

            if (!isset($entry['disks']) || !is_array($entry['disks'])) {
                continue;  // No tiene información de discos
            }

            foreach ($entry['disks'] as $disk => $types) {
                if (!is_string($disk) || $disk === '' || !is_array($types)) {
                    continue;  // Disco inválido
                }

                foreach (['original', 'conversions', 'responsive'] as $type) {
                    $path = $types[$type]['path'] ?? null;
                    if (!is_string($path) || $path === '') {
                        continue;  // Ruta inválida
                    }

                    $artifacts[$disk][] = [
                        'dir'     => $path,      // Directorio del artefacto
                        'mediaId' => $targetId,  // ID del media asociado
                    ];
                }
            }
        }

        // Elimina duplicados y limpia entradas vacías
        foreach ($artifacts as $disk => $items) {
            $seen = [];
            $deduped = [];

            foreach ($items as $item) {
                if (!isset($item['dir']) || !is_string($item['dir'])) {
                    continue;
                }

                $key = $item['dir'] . '|' . ($item['mediaId'] ?? '');
                if (isset($seen[$key])) {
                    continue;  // Duplicado
                }

                $seen[$key] = true;
                $deduped[] = $item;
            }

            if ($deduped === []) {
                unset($artifacts[$disk]);
                continue;
            }

            $artifacts[$disk] = $deduped;
        }

        return $artifacts;
    }

    /**
     * Garantiza que el owner soporta operaciones de Media Library.
     */
    private function assertMediaCapable(MediaOwner $owner): HasMedia
    {
        if (!$owner instanceof HasMedia) {
            throw new \InvalidArgumentException('Media owner must implement Spatie\\MediaLibrary\\HasMedia');
        }

        return $owner;
    }

}
