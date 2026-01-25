<?php

declare(strict_types=1);

namespace App\Application\Uploads\Actions;

use App\Domain\Uploads\UploadProfile; // Ej.: perfil document_pdf
use App\Infrastructure\Models\User; // Ej.: auth user
use App\Infrastructure\Uploads\Core\Contracts\UploadedMedia; // Ej.: wrapper de media
use App\Application\Uploads\DTO\UploadResult; // Ej.: {id, status, correlationId}
use App\Infrastructure\Uploads\Core\Orchestrators\DefaultUploadOrchestrator; // Ej.: orquestador

/**
 * Acción de aplicación para subir archivos.
 * 
 * Esta clase encapsula la lógica de negocio para subir archivos, delegando
 * la implementación al orquestador correspondiente.
 * 
 * @package App\Application\Uploads\Actions
 */
final class UploadFile
{
    /**
     * Constructor que inyecta el orquestador de uploads.
     * 
     * @param DefaultUploadOrchestrator $orchestrator Orquestador encargado de la lógica de subida
     */
    public function __construct(private readonly DefaultUploadOrchestrator $orchestrator) // Ej.: DI
    {}

    /**
     * Ejecuta el upload (enqueue/pipeline).
     * 
     * Este método invocable maneja la subida de archivos, convirtiendo los parámetros
     * según sea necesario y delegando la operación al orquestador.
     *
     * @param UploadProfile $profile Perfil de upload que define las reglas de validación y procesamiento
     * @param User $user Usuario autenticado que realiza la subida
     * @param UploadedMedia $media Archivo subido envuelto en una interfaz común
     * @param mixed $ownerId ID del propietario del archivo (opcional)
     * @param string|null $correlationId ID de correlación para rastreo de solicitudes (opcional)
     * @param array<string,mixed> $meta Metadatos adicionales para el upload
     * @return UploadResult Resultado de la operación de subida con información del upload
     */
    public function __invoke(
        UploadProfile $profile, // Ej.: "document_pdf"
        User $user, // Ej.: auth user
        UploadedMedia $media, // Ej.: HttpUploadedMedia
        mixed $ownerId = null, // Ej.: 123|null
        ?string $correlationId = null, // Ej.: "req_abc-123"|null
        array $meta = [], // Ej.: ['note' => 'Factura enero']
    ): UploadResult {
        // Si tu orquestador aún no soporta meta/correlation, puedes ignorarlos aquí.
        return $this->orchestrator->upload(
            $profile, // Ej.: perfil
            $user, // Ej.: user
            $media, // Ej.: media
            is_numeric($ownerId) ? (int) $ownerId : null, // Ej.: ownerId int|null
            $correlationId, // Ej.: correlationId|null
            $meta, // Ej.: metadata
        );
    }
}
