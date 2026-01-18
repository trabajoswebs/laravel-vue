<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline;

use App\Infrastructure\Uploads\Core\Contracts\MediaOwner;
use App\Infrastructure\Uploads\Core\Contracts\MediaProfile;
use App\Infrastructure\Uploads\Core\Contracts\MediaUploader;
use App\Infrastructure\Uploads\Core\Contracts\UploadedMedia;
use App\Infrastructure\Uploads\Core\DTO\QueuedUploadResult;
use App\Application\Shared\Contracts\AsyncJobDispatcherInterface;
use App\Infrastructure\Uploads\Core\Contracts\MediaResource;
use App\Infrastructure\Uploads\Core\Adapters\SpatieMediaResource;
use App\Infrastructure\Uploads\Pipeline\Contracts\UploadMetadata;
use App\Infrastructure\Uploads\Pipeline\Contracts\UploadPipeline;
use App\Infrastructure\Uploads\Pipeline\Contracts\UploadResult;
use App\Infrastructure\Uploads\Pipeline\Contracts\UploadService;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineState;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineToken;
use App\Infrastructure\Uploads\Pipeline\Exceptions\UploadException;
use App\Infrastructure\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Infrastructure\Uploads\Pipeline\Exceptions\VirusDetectedException;
use App\Infrastructure\Uploads\Pipeline\Scanning\ScanCoordinatorInterface;
use App\Infrastructure\Uploads\Pipeline\Jobs\ProcessUploadJob;
use App\Infrastructure\Uploads\Pipeline\Security\Upload\UploadSecurityLogger;
use App\Infrastructure\Uploads\Pipeline\Support\ImageUploadReporter;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Punto de entrada único para subidas de media.
 *
 * Orquesta validación, cuarentena, escaneo, normalización y adjunto
 * usando DefaultUploadPipeline + ImagePipeline cuando el perfil lo requiere.
 */
final class DefaultUploadService implements UploadService, MediaUploader
{
    /**
     * Constructor del servicio de subida.
     * 
     * @param UploadPipeline $pipeline Pipeline para procesamiento de archivos
     * @param QuarantineManager $quarantineManager Gestor de cuarentena
     * @param ScanCoordinatorInterface $scanCoordinator Coordinador de escaneo antivirus
     * @param ImageUploadReporter $reporter Servicio para reportar eventos
     * @param UploadSecurityLogger $securityLogger Logger centralizado de seguridad
     * @param AsyncJobDispatcherInterface $jobs Dispatcher de jobs para procesamiento en cola
     */
    public function __construct(
        private readonly UploadPipeline $pipeline,
        private readonly QuarantineManager $quarantineManager,
        private readonly ScanCoordinatorInterface $scanCoordinator,
        private readonly ImageUploadReporter $reporter,
        private readonly UploadSecurityLogger $securityLogger,
        private readonly AsyncJobDispatcherInterface $jobs,
    ) {}

    /**
     * @inheritDoc
     * 
     * Fase HTTP: valida de forma ligera, coloca en cuarentena y encola el job.
     * El pipeline completo (AV + normalización + persistencia) se ejecuta en ProcessUploadJob.
     */
    public function upload(
        MediaOwner $owner,
        UploadedMedia $file,
        MediaProfile $profile,
        ?string $correlationId = null
    ): QueuedUploadResult {
        $correlation = $this->resolveCorrelationId($correlationId);
        $uploadedFile = $this->unwrap($file);

        $logContext = [
            'correlation_id' => $correlation,
            'profile' => $profile->collection(),
            'user_id' => $owner->getKey(),
            'filename' => $this->sanitizeFilenameForLog($uploadedFile->getClientOriginalName()),
            'size' => $uploadedFile->getSize(),
            'mime' => $uploadedFile->getMimeType(),
        ];

        $this->assertConstraints($uploadedFile, $profile);
        [$quarantinedFile, $token] = $this->quarantineManager->duplicate(
            $uploadedFile,
            $profile,
            $correlation
        );
        $this->securityLogger->quarantined($logContext + ['quarantine_id' => $token?->identifier()]);

        $this->jobs->dispatch(new ProcessUploadJob(
            token: $token,
            ownerId: (string) $owner->getKey(),
            profileClass: $profile::class,
            correlationId: $correlation,
            originalName: $uploadedFile->getClientOriginalName(),
            clientMime: $uploadedFile->getClientMimeType() ?? $uploadedFile->getMimeType(),
        ));

        return new QueuedUploadResult(
            status: 'processing',
            correlationId: $correlation,
            quarantineId: $token->identifier(),
            ownerId: $owner->getKey(),
            profile: $profile->collection(),
        );
    }

    /**
     * @inheritDoc
     *
     * Mantiene la ruta síncrona para usos que requieren respuesta inmediata.
     */
    public function uploadSync(
        MediaOwner $owner,
        UploadedMedia $file,
        MediaProfile $profile,
        ?string $correlationId = null
    ): MediaResource {
        $correlation = $this->resolveCorrelationId($correlationId);
        $uploadedFile = $this->unwrap($file);

        [$quarantinedFile, $token] = $this->quarantineManager->duplicate(
            $uploadedFile,
            $profile,
            $correlation
        );

        return $this->processQuarantined($owner, $quarantinedFile, $token, $profile, $correlation);
    }

    /**
     * Ejecuta el pipeline completo sobre un artefacto ya en cuarentena.
     *
     * @param MediaOwner $owner Propietario del media
     * @param UploadedFile $quarantinedFile Archivo ya duplicado en cuarentena
     * @param QuarantineToken $token Token de cuarentena asociado
     * @param MediaProfile $profile Perfil de media
     * @param string $correlationId Correlation ID propagado
     * @return MediaResource Media persistido tras procesar
     */
    public function processQuarantined(
        MediaOwner $owner,
        UploadedFile $quarantinedFile,
        QuarantineToken $token,
        MediaProfile $profile,
        string $correlationId
    ): MediaResource {
        $logContext = [
            'correlation_id' => $correlationId,
            'profile' => $profile->collection(),
            'user_id' => $owner->getKey(),
            'filename' => $this->sanitizeFilenameForLog($quarantinedFile->getClientOriginalName()),
            'size' => $quarantinedFile->getSize(),
            'mime' => $quarantinedFile->getMimeType(),
        ];

        $this->securityLogger->started($logContext);

        $artifact = null;
        $currentState = QuarantineState::PENDING;
        $promoted = false;

        try {
            $this->securityLogger->quarantined($logContext + ['quarantine_id' => $token->identifier()]);
            $this->assertConstraints($quarantinedFile, $profile);

            // Si se requiere antivirus, escanea el archivo
            if ($profile->usesAntivirus()) {
                $this->transitionWithLogging(
                    $token,
                    $currentState,
                    QuarantineState::SCANNING,
                    $logContext,
                    ['correlation_id' => $correlationId]
                );
                $currentState = QuarantineState::SCANNING;

                $this->securityLogger->scanStarted($logContext + ['quarantine_id' => $token->identifier()]);
                $this->scanCoordinator->scan($quarantinedFile, $token->path, $logContext);
                $this->securityLogger->scanPassed($logContext + ['quarantine_id' => $token->identifier()]);

                $this->transitionWithLogging(
                    $token,
                    QuarantineState::SCANNING,
                    QuarantineState::CLEAN,
                    $logContext,
                    ['correlation_id' => $correlationId]
                );
                $currentState = QuarantineState::CLEAN;
            } else {
                // Si no se requiere antivirus, marca como limpio
                $this->transitionWithLogging(
                    $token,
                    $currentState,
                    QuarantineState::CLEAN,
                    [
                        'correlation_id' => $correlationId,
                        'profile' => $profile->collection(),
                        'user_id' => $owner->getKey(),
                    ],
                    [
                        'correlation_id' => $correlationId,
                        'metadata' => ['scan' => 'disabled'],
                    ]
                );
                $currentState = QuarantineState::CLEAN;
            }

            // Procesa el archivo a través del pipeline
            $artifact = $this->pipeline->process($quarantinedFile, $profile, $correlationId);
            $artifact = new UploadResult(
                $artifact->path,
                $artifact->size,
                $artifact->metadata,
                $token
            );

            // Adjunta el archivo procesado al modelo propietario
            $media = $this->attach(
                $this->assertMediaOwner($owner),
                $artifact,
                $profile->collection(),
                $profile->disk(),
                $profile->isSingleFile()
            );
            $this->securityLogger->persisted($logContext + [
                'quarantine_id' => $token->identifier(),
                'media_id' => $media->getKey(),
                'hash' => $artifact->metadata->hash,
            ]);

            // Marca el archivo como promovido
            $this->transitionWithLogging(
                $token,
                $currentState,
                QuarantineState::PROMOTED,
                $logContext,
                ['correlation_id' => $correlationId]
            );
            $currentState = QuarantineState::PROMOTED;
            $promoted = true;

            return new SpatieMediaResource($media);
        } catch (VirusDetectedException $exception) {
            // Si se detecta virus, marca como infectado
            $this->safeTransition($token, $currentState, QuarantineState::INFECTED, $logContext, $exception);
            $this->securityLogger->virusDetected($logContext + ['error' => $exception->getMessage()]);
            $this->reporter->report('upload.virus_detected', $exception, $logContext, 'warning');
            throw $exception;
        } catch (\Throwable $exception) {
            // En caso de cualquier otro error, marca como fallido
            $this->safeTransition($token, $currentState, QuarantineState::FAILED, $logContext, $exception);
            $this->reporter->report('image_upload.failed', $exception, $logContext);
            $this->securityLogger->validationFailed($logContext + ['error' => $exception->getMessage()]);
            throw $exception;
        } finally {
            // Limpia el artefacto de cuarentena
            $removeQuarantine = $promoted;
            $this->quarantineManager->cleanupArtifact($artifact, $removeQuarantine);
        }
    }

    /**
     * Persiste un archivo en cuarentena sin contexto de perfil (uso legado).
     *
     * @param UploadedFile $file Archivo subido.
     * @return string Ruta del artefacto en cuarentena.
     */
    public function storeToQuarantine(UploadedFile $file): string
    {
        $token = $this->quarantineManager->duplicate($file, null, null)[1] ?? null;

        return $token instanceof QuarantineToken ? $token->path : '';
    }

    /**
     * Método legado para compatibilidad. El escaneo real se ejecuta vía ScanCoordinator.
     *
     * @param string $bytes Contenido ya leído (no usado).
     */
    public function scan(string $bytes): void
    {
        // No-op: la ruta moderna pasa por ScanCoordinator con cuarentena.
    }

    /**
     * Método legado para compatibilidad. La validación se hace vía FileConstraints/QuarantineManager.
     *
     * @param string $bytes Contenido ya leído (no usado).
     */
    public function validate(string $bytes): void
    {
        // No-op intencional.
    }

    /**
     * Método legado para compatibilidad. La normalización se delega al pipeline.
     *
     * @param string $bytes Contenido ya leído (no usado).
     * @return string Bytes sin modificar.
     */
    public function normalize(string $bytes): string
    {
        return $bytes;
    }

    /**
     * Adjunta el artefacto procesado a un modelo HasMedia.
     *
     * @param HasMediaContract $owner Entidad propietaria del archivo.
     * @param UploadResult $artifact Resultado del proceso de subida.
     * @param string $profile Perfil de la colección de medios.
     * @param string|null $disk Disco opcional para la colección.
     * @param bool $singleFile Indicador de colección de archivo único.
     * @return Media Media adjuntado.
     */
    public function attach(
        HasMediaContract $owner,
        UploadResult $artifact,
        string $profile,
        ?string $disk = null,
        bool $singleFile = false
    ): Media {
        $metadata = $artifact->metadata;

        // Genera el nombre del archivo
        $fileName = $this->buildFileName($metadata, $profile);

        // Crea headers para el archivo
        $headers = [
            'ACL' => 'private',
            'ContentType' => $metadata->mime,
            'ContentDisposition' => sprintf('inline; filename="%s"', $fileName),
        ];

        // Configura el adder de medios
        $adder = $owner->addMedia($artifact->path)
            ->usingFileName($fileName)
            ->addCustomHeaders($headers)
            ->withCustomProperties([
                'version' => $metadata->hash,
                'uploaded_at' => now()->toIso8601String(),
                'mime_type' => $metadata->mime,
                'width' => $metadata->dimensions['width'] ?? null,
                'height' => $metadata->dimensions['height'] ?? null,
                'original_filename' => $metadata->originalFilename,
                'quarantine_id' => $artifact->quarantineId?->identifier(),
                'correlation_id' => $logContext['correlation_id'] ?? null,
                'headers' => $headers,
                'size' => $artifact->size,
            ]);

        // Si es archivo único, aplica la configuración
        if ($singleFile && method_exists($adder, 'singleFile')) {
            $adder->singleFile();
        }

        try {
            // Adjunta el archivo a la colección de medios
            $media = $disk !== null && $disk !== ''
                ? $adder->toMediaCollection($profile, $disk)
                : $adder->toMediaCollection($profile);

            return $media;
        } catch (\Throwable $exception) {
            throw UploadException::fromThrowable('Unable to attach upload to media collection.', $exception);
        }
    }

    /**
     * Garantiza que el owner implementa HasMedia.
     * 
     * @param MediaOwner $owner Propietario del archivo
     * @return HasMediaContract Instancia que implementa HasMedia
     */
    private function assertMediaOwner(MediaOwner $owner): HasMediaContract
    {
        if ($owner instanceof HasMediaContract) {
            return $owner;
        }

        throw new UploadValidationException('Media owner must implement Spatie\\MediaLibrary\\HasMedia');
    }

    /**
     * Sanitiza y genera el nombre del archivo a adjuntar.
     * 
     * @param UploadMetadata $metadata Metadatos del archivo
     * @param string $profile Perfil de la colección
     * @return string Nombre del archivo generado
     */
    private function buildFileName(UploadMetadata $metadata, string $profile): string
    {
        $safeProfile = $this->sanitizeProfile($profile);
        $extension = $this->sanitizeExtension($metadata->extension ?? 'bin');
        $identifier = $metadata->hash ?? $this->generateSecureIdentifier();
        $randomSuffix = substr(Str::uuid()->toString(), 0, 8);

        return sprintf('%s-%s-%s.%s', $safeProfile, $identifier, $randomSuffix, $extension);
    }

    /**
     * Normaliza el nombre del perfil para generar el filename.
     * 
     * @param string $profile Nombre del perfil
     * @return string Nombre del perfil sanitizado
     */
    private function sanitizeProfile(string $profile): string
    {
        $normalized = strtolower($profile);
        $normalized = preg_replace('/[^a-z0-9_-]/', '-', $normalized) ?? 'upload';
        $normalized = trim($normalized, '-_');
        if ($normalized === '') {
            $normalized = 'upload';
        }

        return substr($normalized, 0, 40);
    }

    /**
     * Sanitiza la extensión.
     * 
     * @param string $extension Extensión original
     * @return string Extensión sanitizada
     */
    private function sanitizeExtension(string $extension): string
    {
        $clean = strtolower($extension);
        $clean = preg_replace('/[^a-z0-9]/', '', $clean) ?? 'bin';

        return $clean === '' ? 'bin' : substr($clean, 0, 10);
    }

    /**
     * Genera identificador aleatorio seguro.
     * 
     * @return string Identificador único
     */
    private function generateSecureIdentifier(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Convierte UploadedMedia en UploadedFile.
     * 
     * @param UploadedMedia $file Archivo subido
     * @return UploadedFile Instancia de UploadedFile
     */
    private function unwrap(UploadedMedia $file): UploadedFile
    {
        $raw = $file->raw();

        if ($raw instanceof UploadedFile) {
            return $raw;
        }

        throw new UploadValidationException('Invalid uploaded media adapter');
    }

    /**
     * Garantiza que el archivo cumple los límites del perfil.
     * 
     * @param UploadedFile $file Archivo subido
     * @param MediaProfile $profile Perfil de validación
     */
    private function assertConstraints(UploadedFile $file, MediaProfile $profile): void
    {
        $constraints = $profile->fileConstraints();

        try {
            $constraints->assertValidUpload($file);
        } catch (\InvalidArgumentException $exception) {
            throw new UploadValidationException($exception->getMessage(), previous: $exception);
        }
        $this->quarantineManager->validateMimeType($file, $profile);
    }

    /**
     * Resuelve o genera correlation_id.
     * 
     * @param string|null $correlationId ID de correlación opcional
     * @return string ID de correlación resuelto
     */
    private function resolveCorrelationId(?string $correlationId): string
    {
        $candidate = $correlationId !== null ? trim((string) $correlationId) : '';

        return $candidate !== '' ? $candidate : (string) Str::uuid();
    }

    /**
     * Sanitiza nombres antes de usarlos en logs para evitar caracteres peligrosos.
     */
    private function sanitizeFilenameForLog(?string $name): ?string
    {
        if (!is_string($name) || $name === '') {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? $name;
        return substr($clean, 0, 200);
    }

    /**
     * Intenta transicionar de forma segura sin romper flujo de errores.
     * 
     * @param QuarantineToken|null $token Token de cuarentena
     * @param QuarantineState $from Estado actual
     * @param QuarantineState $to Estado destino
     * @param array<string,mixed> $context Contexto de logging
     * @param \Throwable|null $exception Excepción opcional
     */
    private function safeTransition(
        ?QuarantineToken $token,
        QuarantineState $from,
        QuarantineState $to,
        array $context = [],
        ?\Throwable $exception = null
    ): void {
        if (! $token instanceof QuarantineToken) {
            return;
        }

        try {
            $this->transitionWithLogging(
                $token,
                $from,
                $to,
                $context,
                [
                    'correlation_id' => $context['correlation_id'] ?? null,
                    'metadata' => [
                        'exception' => $exception?->getMessage(),
                    ],
                ]
            );
        } catch (\Throwable) {
            // Ya se registró el error dentro de transitionWithLogging.
        }
    }

    /**
     * Registra un intento de transición con contexto y metadata adicional.
     */
    private function transitionWithLogging(
        QuarantineToken $token,
        QuarantineState $from,
        QuarantineState $to,
        array $context,
        array $metadata = []
    ): void {
        try {
            $this->quarantineManager->transition($token, $from, $to, $metadata);
            Log::info('quarantine.transitioned', array_merge($context, [
                'from' => $from->value,
                'to' => $to->value,
                'quarantine_id' => $token->identifier(),
            ], $metadata));
        } catch (\Throwable $transitionException) {
            Log::warning('quarantine.transition_failed', array_merge($context, [
                'from' => $from->value,
                'to' => $to->value,
                'quarantine_id' => $token->identifier(),
                'error' => $transitionException->getMessage(),
            ], $metadata));

            throw $transitionException;
        }
    }
}
