<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para el coordinador del ciclo de vida de medios.
namespace App\Application\Media\Coordinators;

// 3. Importaciones de interfaces, DTOs, clases y facades necesarios.
use App\Domain\User\Contracts\MediaOwner;
use App\Application\Media\DTO\CleanupPayload;
use App\Domain\Media\DTO\ReplacementResult;
use App\Domain\Media\ImageProfile;
use App\Application\Media\Services\MediaCleanupScheduler;
use App\Application\Media\Services\MediaReplacementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Coordina el ciclo de vida completo de un archivo multimedia: reemplazo, conversión y limpieza.
 *
 * Esta clase centraliza la lógica de negocio relacionada con la gestión de medios,
 * delegando tareas específicas a otros servicios. Utiliza DTOs para encapsular
 * datos y mantener una comunicación clara y tipada entre los diferentes componentes.
 * Proporciona métodos para reemplazar medios, construir payloads de limpieza y
 * forzar manualmente la limpieza de archivos huérfanos si es necesario.
 */
final class MediaLifecycleCoordinator
{
    /**
     * Constructor que inyecta las dependencias necesarias.
     *
     * @param MediaReplacementService $replacementService Servicio encargado del reemplazo y conversión de medios.
     * @param MediaCleanupScheduler   $cleanupScheduler   Servicio encargado de programar y ejecutar la limpieza de archivos huérfanos.
     */
    public function __construct(
        private readonly MediaReplacementService $replacementService, // 4. Servicio para reemplazar medios.
        private readonly MediaCleanupScheduler $cleanupScheduler,    // 5. Servicio para programar la limpieza de archivos huérfanos.
    ) {}

    /**
     * Reemplaza el medio actual del propietario, aplica conversiones y devuelve un resultado tipado.
     *
     * Este método inicia el proceso de reemplazo de un medio (por ejemplo, una imagen de perfil),
     * delegando la lógica de procesamiento, validación y conversión al servicio de reemplazo.
     *
     * @param MediaOwner   $owner   El modelo que posee el medio (por ejemplo, un usuario).
     * @param UploadedFile $file    El archivo subido por el usuario.
     * @param ImageProfile $profile Perfil de imagen que define las conversiones a aplicar.
     *
     * @return ReplacementResult Resultado del reemplazo, incluyendo el nuevo medio y una instantánea de los artefactos anteriores.
     */
    public function replace(MediaOwner $owner, UploadedFile $file, ImageProfile $profile): ReplacementResult
    {
        // Llama al servicio de reemplazo para ejecutar la lógica de reemplazo y conversión.
        return $this->replacementService->replaceWithSnapshot($owner, $file, $profile);
    }

    /**
     * Ejecuta manualmente la limpieza de archivos huérfanos asociados a un medio específico.
     *
     * Este método es útil en situaciones donde el coordinador no recibe eventos automáticos
     * de limpieza o se requiere forzar la eliminación de archivos antiguos.
     *
     * @param string $mediaId El ID del medio cuyos artefactos antiguos se deben limpiar.
     *
     * @return void
     */
    public function flushPendingCleanup(string $mediaId): void
    {
        try {
            // Intenta ejecutar la limpieza de archivos huérfanos asociados al ID del medio.
            $this->cleanupScheduler->flushExpired($mediaId);
        } catch (\Throwable $e) {
            // Si ocurre un error, lo registra como una advertencia para monitoreo.
            Log::warning('media.lifecycle.flush_failed', [
                'media_id' => $mediaId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Crea un DTO de limpieza a partir de un resultado de reemplazo para programar la limpieza diferida.
     *
     * Este método construye un `CleanupPayload` que puede ser utilizado por el planificador
     * de limpieza para eliminar archivos huérfanos en el futuro.
     *
     * @param ReplacementResult $result El resultado del reemplazo que contiene la instantánea y expectativas.
     *
     * @return CleanupPayload DTO listo para programar la limpieza de artefactos antiguos.
     */
    public function buildCleanupPayload(ReplacementResult $result): CleanupPayload
    {
        // Construye un DTO de limpieza a partir del resultado del reemplazo.
        return CleanupPayload::fromSnapshot($result->snapshot, $result->media, $result->expectations);
    }
}
