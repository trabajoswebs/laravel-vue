<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para el coordinador del ciclo de vida de medios.
namespace App\Support\Media;

// 3. Importaciones de interfaces, DTOs, clases y facades necesarios.
use App\Support\Media\Contracts\MediaOwner;
use App\Support\Media\DTO\CleanupPayload;
use App\Support\Media\DTO\ReplacementResult;
use App\Support\Media\ImageProfile;
use App\Support\Media\Services\MediaCleanupScheduler;
use App\Support\Media\Services\MediaReplacementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Coordina el ciclo de vida replace→convert→cleanup de un Media.
 *
 * Mantiene los servicios existentes pero encapsula el flujo principal con DTOs tipados.
 */
final class MediaLifecycleCoordinator
{
    /**
     * Constructor que inyecta las dependencias necesarias.
     *
     * @param MediaReplacementService $replacementService Servicio para reemplazar medios.
     * @param MediaCleanupScheduler $cleanupScheduler Servicio para programar la limpieza de archivos huérfanos.
     */
    public function __construct(
        private readonly MediaReplacementService $replacementService, // 4. Servicio para reemplazar medios.
        private readonly MediaCleanupScheduler $cleanupScheduler,    // 5. Servicio para programar la limpieza de archivos huérfanos.
    ) {}

    /**
     * Reemplaza el media, registra conversions y entrega el snapshot tipado.
     *
     * @param MediaOwner $owner El modelo que posee el medio (por ejemplo, un usuario).
     * @param UploadedFile $file El archivo subido por el usuario.
     * @param ImageProfile $profile Perfil de imagen que define las conversiones a aplicar.
     * @return ReplacementResult Resultado del reemplazo, incluyendo el nuevo medio y la instantánea de artefactos anteriores.
     */
    public function replace(MediaOwner $owner, UploadedFile $file, ImageProfile $profile): ReplacementResult
    {
        // Llama al servicio de reemplazo para ejecutar la lógica de reemplazo y conversión.
        return $this->replacementService->replaceWithSnapshot($owner, $file, $profile);
    }

    /**
     * Permite ejecutar cleanup manualmente cuando el coordinador no recibe eventos.
     *
     * @param string $mediaId El ID del medio cuyos artefactos antiguos se deben limpiar.
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
     * Crea un payload de cleanup para programar la limpieza diferida.
     *
     * @param ReplacementResult $result El resultado del reemplazo que contiene la instantánea y expectativas.
     * @return CleanupPayload DTO listo para programar la limpieza de artefactos antiguos.
     */
    public function buildCleanupPayload(ReplacementResult $result): CleanupPayload
    {
        // Construye un DTO de limpieza a partir del resultado del reemplazo.
        return CleanupPayload::fromSnapshot($result->snapshot, $result->media, $result->expectations);
    }
}
