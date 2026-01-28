<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Listeners;

use App\Application\User\Events\AvatarUpdated; // Evento de aplicación al actualizar avatar; ej. user_id=1
use App\Infrastructure\Uploads\Pipeline\Jobs\PostProcessAvatarMedia; // Job que post-procesa avatar; ej. optimiza imagen
use Illuminate\Support\Facades\Log; // Logger para dejar trazas; ej. warning si falta tenant
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Modelo Media de Spatie; ej. media_id=5

/**
 * Reacciona a la actualización de avatar lanzando el post-procesado de media.
 */
final class OnAvatarUpdated
{
    public function handle(AvatarUpdated $event): void
    {
        $media = Media::query()->find($event->newMediaId); // Busca el media actualizado; ej. retorna Media #5

        if ($media === null) { // Si no existe el media, no podemos seguir
            Log::warning('avatar.updated.media_missing', [ // Loguea warning para trazar el fallo
                'media_id' => $event->newMediaId, // Ej. media_id inexistente
                'user_id' => $event->userId, // Ej. user 1
            ]);
            return; // Detiene el listener para evitar encolar sin media
        }

        $tenantId = $media->getCustomProperty('tenant_id') ?? tenant()?->getKey(); // Resuelve tenant: primero custom_properties, luego helper; ej. 3

        if ($tenantId === null) { // Si no se pudo resolver tenant, abortar
            Log::warning('avatar.updated.missing_tenant', [ // Deja traza de contexto
                'media_id' => $event->newMediaId, // Ej. 5
                'user_id' => $event->userId, // Ej. 1
                'collection' => $event->collection, // Ej. avatar
                'correlation_id' => $event->version, // Ej. v1
            ]);
            return; // No encola job sin tenant para evitar fugas cross-tenant
        }

        PostProcessAvatarMedia::dispatchFor(
            media: $media, // Pasa el modelo Media; ej. Media #5
            tenantId: $tenantId, // Incluye tenantId en payload; ej. 3
            conversions: [], // Conversions a procesar (vacío usa defaults); ej. []
            collection: $event->collection, // Colección origen; ej. avatar
            correlationId: $event->version, // Usa versión como correlación; ej. v1
        );
    }
}
