<?php // Orquestador unificado de uploads por perfil

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Orchestrators;

use App\Application\Uploads\Contracts\UploadOrchestratorInterface;
use App\Application\Uploads\Contracts\UploadRepositoryInterface;
use App\Application\Uploads\Contracts\UploadStorageInterface;
use App\Application\Uploads\DTO\UploadResult;
use App\Support\Contracts\TenantContextInterface;
use App\Support\Enums\Uploads\ScanMode;
use App\Support\Enums\Uploads\UploadKind;
use App\Domain\Uploads\UploadProfile;
use App\Models\User;
use App\Infrastructure\Uploads\Core\Contracts\MediaProfile;
use App\Infrastructure\Uploads\Core\Contracts\UploadedMedia;
use App\Modules\Uploads\Paths\TenantPathGenerator;
use App\Infrastructure\Uploads\Core\Services\MediaReplacementService;
use App\Infrastructure\Uploads\Pipeline\DTO\InternalPipelineResult;
use App\Infrastructure\Uploads\Pipeline\Scanning\ScanCoordinatorInterface;
use App\Infrastructure\Uploads\Pipeline\Contracts\UploadMetadata;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use App\Infrastructure\Uploads\Pipeline\Support\PipelineResultMapper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orquestador de uploads por defecto.
 * 
 * Ejecuta uploads aplicando validación, cuarentena, escaneo antivirus y persistencia
 * con enfoque orientado al tenant. Maneja diferentes tipos de uploads según el perfil.
 * 
 * @package App\Infrastructure\Uploads\Core\Orchestrators
 */
final class DefaultUploadOrchestrator implements UploadOrchestratorInterface
{
    /**
     * Constructor que inyecta todas las dependencias necesarias para el orquestador.
     * 
     * @param TenantContextInterface $tenantContext Contexto del tenant actual
     * @param TenantPathGenerator $paths Generador de rutas específicas por tenant
     * @param QuarantineManager $quarantine Gestor de archivos en cuarentena
     * @param ScanCoordinatorInterface $scanner Coordinador de escaneo antivirus
     * @param UploadRepositoryInterface $uploads Repositorio para persistencia de uploads
     * @param UploadStorageInterface $storage Puerto de almacenamiento para persistencia de artefactos
     * @param MediaReplacementService $mediaReplacement Servicio para manejo de reemplazo de medios
     */
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantPathGenerator $paths,
        private readonly QuarantineManager $quarantine,
        private readonly ScanCoordinatorInterface $scanner,
        private readonly UploadRepositoryInterface $uploads,
        private readonly UploadStorageInterface $storage,
        private readonly MediaReplacementService $mediaReplacement,
        private readonly PipelineResultMapper $resultMapper,
        private readonly MediaProfileResolver $mediaProfileResolver,
        private readonly DocumentUploadGuard $documentGuard,
    ) {}

    /**
     * Sube un archivo según el perfil (creación).
     * 
     * Este método inicia el proceso de subida de archivos, resolviendo el ID de correlación
     * y delegando la operación específica al método de manejo correspondiente.
     *
     * @param UploadProfile $profile Perfil de upload que define las reglas de validación y procesamiento
     * @param User $actor Usuario que realiza la operación de subida
     * @param UploadedMedia $file Archivo subido envuelto en una interfaz común
     * @param int|string|null $ownerId ID del propietario del archivo (opcional)
     * @param string|null $correlationId ID de correlación para rastreo de solicitudes (opcional)
     * @param array<string, mixed> $meta Metadatos adicionales para el upload
     * @return UploadResult Resultado de la operación de subida con información detallada
     */
    public function upload(
        UploadProfile $profile,
        User $actor,
        UploadedMedia $file,
        int|string|null $ownerId = null,
        ?string $correlationId = null,
        array $meta = [],
    ): UploadResult {
        $cid = $this->resolveCorrelationId($correlationId);

        return $this->handleUpload($profile, $actor, $file, $ownerId, $cid, $meta);
    }

    /**
     * Maneja upload según el tipo de perfil.
     * 
     * Decide qué tipo de manejo aplicar según el tipo de upload (imagen o documento).
     *
     * @param UploadProfile $profile Perfil de upload
     * @param User $actor Usuario que realiza la operación
     * @param UploadedMedia $file Archivo subido
     * @param int|string|null $ownerId ID del propietario del archivo
     * @param string $correlationId ID de correlación para rastreo
     * @param array<string, mixed> $meta Metadatos adicionales
     * @return UploadResult Resultado de la operación de subida
     */
    private function handleUpload(
        UploadProfile $profile,
        User $actor,
        UploadedMedia $file,
        int|string|null $ownerId,
        string $correlationId,
        array $meta,
    ): UploadResult {
        return match ($profile->kind) {
            // Si es imagen, usa el manejo específico para imágenes
            UploadKind::IMAGE => $this->handleImage($profile, $actor, $file, $correlationId, $meta),
            // Para otros tipos, usa el manejo de documentos
            default => $this->handleDocument($profile, $actor, $file, $ownerId, $correlationId, $meta),
        };
    }

    /**
     * Maneja imágenes (avatar/galería) usando el pipeline existente (Spatie).
     * 
     * Este método procesa archivos de imagen utilizando el servicio de reemplazo de medios
     * y el sistema de gestión de medios de Spatie.
     *
     * @param UploadProfile $profile Perfil de upload para imágenes
     * @param User $actor Usuario que realiza la operación
     * @param UploadedMedia $file Archivo de imagen subido
     * @param string $correlationId ID de correlación para rastreo
     * @param array<string, mixed> $meta Metadatos adicionales
     * @return UploadResult Resultado de la operación de subida de imagen
     */
    private function handleImage(
        UploadProfile $profile,
        User $actor,
        UploadedMedia $file,
        string $correlationId,
        array $meta,
    ): UploadResult {
        $mediaProfile = $this->resolveMediaProfile($profile);

        // MediaReplacementService debe aceptar UploadedMedia (ideal), si no, tu HttpUploadedMedia ya lo implementa.
        $mediaResult = $this->mediaReplacement->replace($actor, $file, $mediaProfile, $correlationId);

        $raw = $mediaResult->raw();

        if (!method_exists($raw, 'getPathRelativeToRoot')) {
            throw new RuntimeException('Media result is incompatible with tenant-first path requirements');
        }

        $tenantId = $this->tenantContext->requireTenantId();

        // Persistimos correlación y tenant en custom properties.
        $raw->setCustomProperty('tenant_id', $tenantId);
        $raw->setCustomProperty('upload_uuid', $raw->uuid);
        $raw->setCustomProperty('correlation_id', $correlationId);

        // Meta opcional (whitelist en Request; aquí lo dejamos como registro).
        if (!empty($meta)) {
            $raw->setCustomProperty('meta', $meta);
        }

        $raw->save();

        $path = $this->resolveTenantFirstMediaPath($raw, $tenantId);
        $disk = (string) $raw->disk;
        $mime = (string) ($raw->mime_type ?? 'application/octet-stream');
        $size = (int) ($raw->size ?? 0);
        $id = (string) $raw->getKey();
        $checksum = (string) ($raw->getCustomProperty('version') ?? $raw->uuid ?? '');

        return new UploadResult(
            id: $id,
            tenantId: $tenantId,
            profileId: (string) $profile->id,
            disk: $disk,
            path: $path,
            mime: $mime,
            size: $size,
            checksum: $checksum !== '' ? $checksum : null,
            status: 'stored',
            correlationId: $correlationId,
        );
    }

    /**
     * Maneja documentos/CSV/secretos (no imagen).
     * 
     * Este método procesa archivos que no son imágenes, aplicando validación,
     * cuarentena, escaneo antivirus y persistencia segura.
     *
     * @param UploadProfile $profile Perfil de upload para documentos
     * @param User $actor Usuario que realiza la operación
     * @param UploadedMedia $file Archivo de documento subido
     * @param int|string|null $ownerId ID del propietario del archivo
     * @param string $correlationId ID de correlación para rastreo
     * @param array<string, mixed> $meta Metadatos adicionales
     * @return UploadResult Resultado de la operación de subida de documento
     */
    private function handleDocument(
        UploadProfile $profile,
        User $actor,
        UploadedMedia $file,
        int|string|null $ownerId,
        string $correlationId,
        array $meta,
    ): UploadResult {
        $uploaded = $this->unwrap($file);

        $this->validateDocument($profile, $uploaded);

        // Duplicamos a cuarentena usando correlationId real (request o generado).
        [$quarantined, $token] = $this->quarantine->duplicate($uploaded, null, $correlationId, false);

        $tenantId = $this->tenantContext->requireTenantId();
        $disk = (string) $profile->disk;
        $extension = $this->extensionFor($profile, $uploaded);
        $path = $this->paths->generate($profile, $ownerId, $extension);

        $realPath = $quarantined->getRealPath();
        if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
            throw new RuntimeException('No se pudo resolver el archivo en cuarentena');
        }

        $checksum = hash_file('sha256', $realPath) ?: null;
        $finalSize = (int) ($quarantined->getSize() ?? 0);
        $stored = false;

        try {
            if ($profile->scanMode !== ScanMode::DISABLED) {
                $this->scanner->scan($quarantined, $token->path, [
                    'profile' => (string) $profile->id,
                    'correlation_id' => $correlationId,
                ]);
            }

            $handle = fopen($realPath, 'rb');
            if ($handle === false) {
                throw new RuntimeException('No se pudo abrir el archivo en cuarentena');
            }

            try {
                $this->storage->writeStream($disk, $path, $handle);
                $stored = true;
            } finally {
                fclose($handle);
            }

            $remoteSize = $this->storage->size($disk, $path);
            if (is_int($remoteSize) && $remoteSize > 0) {
                $finalSize = $remoteSize;
            }

            $metadata = new UploadMetadata(
                mime: $quarantined->getMimeType() ?? 'application/octet-stream',
                extension: $extension,
                hash: $checksum,
                dimensions: null,
                originalFilename: null,
            );

            $internalResult = new InternalPipelineResult(
                path: $path,
                size: $finalSize > 0 ? $finalSize : (int) $quarantined->getSize(),
                metadata: $metadata,
                quarantineId: $token,
            );

            $result = $this->resultMapper->toApplication(
                result: $internalResult,
                tenantId: $tenantId,
                profileId: (string) $profile->id,
                disk: $disk,
                correlationId: $correlationId,
                id: (string) Str::uuid(),
                status: 'stored',
                pathOverride: $path,
            );

            // Si tu repositorio soporta meta/correlation, extiéndelo; si no, al menos persistimos lo esencial.
            $this->uploads->store($result, $profile, $actor, $ownerId);

            return $result;
        } catch (\Throwable $exception) {
            if ($stored) {
                try {
                    $this->storage->deleteIfExists($disk, $path);
                } catch (\Throwable) {
                    // rollback best-effort
                }
            }
            throw $exception;
        } finally {
            $this->quarantine->delete($token);
        }
    }

    /**
     * Determina la extensión del archivo basándose en el perfil y el archivo original.
     * 
     * @param UploadProfile $profile Perfil de upload que define categorías
     * @param UploadedFile $file Archivo original subido
     * @return string Extensión del archivo determinada
     */
    private function extensionFor(UploadProfile $profile, UploadedFile $file): string
    {
        return $this->documentGuard->extensionFor($profile, $file);
    }

    /**
     * Valida el archivo de documento según las restricciones del perfil.
     * 
     * Realiza validaciones de tamaño, MIME type y firma de archivo.
     *
     * @param UploadProfile $profile Perfil de upload con las reglas de validación
     * @param UploadedFile $file Archivo a validar
     * @throws InvalidArgumentException Si el archivo no cumple con las validaciones
     */
    private function validateDocument(UploadProfile $profile, UploadedFile $file): void
    {
        $this->documentGuard->validate($profile, $file);
    }

    /**
     * Resuelve el perfil de medio específico para imágenes.
     * 
     * @param UploadProfile $profile Perfil de upload original
     * @return MediaProfile Perfil de medio específico para imágenes
     */
    private function resolveMediaProfile(UploadProfile $profile): MediaProfile
    {
        return $this->mediaProfileResolver->resolve($profile);
    }

    private function resolveTenantFirstMediaPath(object $media, int|string $tenantId): string
    {
        if (!method_exists($media, 'getPathRelativeToRoot')) {
            throw new RuntimeException('Media result is incompatible with tenant-first path requirements');
        }

        $rawPath = (string) $media->getPathRelativeToRoot();
        $normalized = str_replace('\\', '/', trim($rawPath));
        $segments = array_values(array_filter(explode('/', $normalized), static fn(string $segment): bool => $segment !== '' && $segment !== '.'));

        if ($segments === [] || in_array('..', $segments, true)) {
            throw new RuntimeException('Invalid media relative path');
        }

        $clean = implode('/', $segments);
        $expectedPrefix = 'tenants/' . (string) $tenantId . '/';
        if (!str_starts_with($clean, $expectedPrefix)) {
            throw new RuntimeException('Media path must be tenant-first');
        }

        return $clean;
    }

    /**
     * Resuelve o genera un ID de correlación.
     * 
     * @param string|null $correlationId ID de correlación proporcionado (opcional)
     * @return string ID de correlación resuelto
     */
    private function resolveCorrelationId(?string $correlationId): string
    {
        $value = is_string($correlationId) ? trim($correlationId) : '';
        return $value !== '' ? $value : (string) Str::uuid();
    }

    /**
     * Convierte el wrapper UploadedMedia a UploadedFile nativo.
     * 
     * @param UploadedMedia $media Wrapper del archivo subido
     * @return UploadedFile Instancia nativa de Laravel UploadedFile
     * @throws InvalidArgumentException Si el wrapper no contiene un UploadedFile válido
     */
    private function unwrap(UploadedMedia $media): UploadedFile
    {
        $raw = $media->raw();

        if (!$raw instanceof UploadedFile) {
            throw new InvalidArgumentException('Uploaded media inválido');
        }

        return $raw;
    }
}
