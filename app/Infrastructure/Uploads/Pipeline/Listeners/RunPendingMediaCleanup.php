<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Listeners;

use App\Infrastructure\Uploads\Core\Contracts\MediaCleanupScheduler;
use App\Support\Contracts\LoggerInterface;
use App\Infrastructure\Uploads\Core\Adapters\SpatieMediaResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Clase oyente que dispara la limpieza diferida de artefactos cuando Spatie finaliza las conversiones.
 *
 * Este listener escucha eventos de Spatie Media Library relacionados con la finalización
 * de conversiones (ya sea exitosa o fallida) y programa la limpieza de archivos temporales
 * o artefactos asociados con el modelo Media.
 */
final class RunPendingMediaCleanup
{
    /**
     * Lista de discos permitidos para la limpieza de archivos.
     *
     * @var array<int,string>
     */
    private readonly array $allowedDisks;

    /**
     * Constructor del listener.
     *
     * Inicializa el scheduler de limpieza y resuelve los discos permitidos según la configuración.
     *
     * @param MediaCleanupScheduler $scheduler Instancia del servicio encargado de programar la limpieza de archivos.
     */
    public function __construct(
        private readonly MediaCleanupScheduler $scheduler,
        private readonly LoggerInterface $logger,
    ) {
        // Resuelve y almacena la lista de discos permitidos
        $this->allowedDisks = $this->resolveAllowedDisks();
    }

    /**
     * Maneja eventos ConversionHasBeenCompleted/Failed.
     *
     * Este método implementa el contrato del evento de Spatie Media Library (versiones 10 y 11):
     *  - Propiedad pública `media`: instancia de {@see Media}.
     *  - Propiedad opcional `conversionName`: string|null.
     *
     * @param object $event Evento despachado por Spatie Media Library (conversion completada o fallida).
     */
    public function handle(object $event): void
    {
        // Extrae la instancia de Media del evento
        $media = property_exists($event, 'media') ? $event->media : null;

        // Verifica que la instancia de Media sea válida y tenga un ID
        if (!$media instanceof Media || !$media->id) {
            // Registra un debug si no se encuentra una instancia válida de Media
            $this->logger->debug('media_cleanup.listener_missing_media', [
                'event_class' => is_object($event) ? $event::class : gettype($event),
            ]);
            return; // Sale si no hay Media válida
        }

        // Obtiene el disco asociado al archivo Media, usando el disco por defecto si no está definido
        $disk = $media->disk ?? config('filesystems.default');

        // Verifica si el disco está permitido para la limpieza
        if (!$this->isAllowedDisk($disk)) {
            // Registra un warning si el disco no está permitido
            $this->logger->warning('ppam_listener_invalid_disk', [
                'media_id' => $media->id,
                'disk' => $disk,
                'conversion_fired' => property_exists($event, 'conversionName') ? $event->conversionName : null,
                'metric' => 'media.cleanup.invalid_disk',
            ]);
            return; // Sale si el disco no está permitido
        }

        // Programa la limpieza para el modelo Media
        $this->scheduler->handleConversionEvent(new SpatieMediaResource($media));
    }

    /**
     * Obtiene la lista de discos permitidos.
     *
     * @return array<int,string> Array de nombres de discos permitidos.
     */
    private function allowedDisks(): array
    {
        return $this->allowedDisks;
    }

    /**
     * Resuelve la lista de discos permitidos a partir de la configuración.
     *
     * Este método intenta leer la configuración específica para discos permitidos.
     * Si no está definida, devuelve todos los discos configurados en el sistema de archivos.
     *
     * @return array<int,string> Array de nombres de discos permitidos.
     */
    private function resolveAllowedDisks(): array
    {
        // Obtiene la configuración de discos permitidos
        $cfg = config('media.allowed_disks');

        // Si hay una configuración específica de discos permitidos, úsala
        if (is_array($cfg) && $cfg !== []) {
            $normalized = array_values(array_filter(array_map('strval', $cfg), static fn ($disk) => $disk !== ''));
            if ($normalized !== []) {
                return $normalized;
            }
        }

        // Si no hay configuración específica, usa todos los discos del sistema de archivos
        return array_keys((array) config('filesystems.disks', []));
    }

    /**
     * Verifica si un disco está permitido para la limpieza.
     *
     * @param string|null $disk Nombre del disco a verificar.
     * @return bool `true` si el disco está permitido, `false` en caso contrario.
     */
    private function isAllowedDisk(?string $disk): bool
    {
        // Si el disco es null o vacío, se considera permitido por defecto
        if ($disk === null || $disk === '') {
            return true;
        }

        // Verifica si el disco está en la lista de discos permitidos
        return in_array($disk, $this->allowedDisks(), true);
    }
}
