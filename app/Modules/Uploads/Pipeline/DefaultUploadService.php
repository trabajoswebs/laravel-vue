<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline;

use App\Modules\Uploads\Contracts\MediaOwner;
use App\Modules\Uploads\Contracts\MediaProfile;
use App\Modules\Uploads\Contracts\MediaUploader;
use App\Modules\Uploads\Contracts\UploadedMedia;
use App\Modules\Uploads\DTO\QueuedUploadResult;
use App\Support\Contracts\AsyncJobDispatcherInterface;
use App\Modules\Uploads\Contracts\MediaResource;
use App\Modules\Uploads\Adapters\SpatieMediaResource;
use App\Modules\Uploads\Pipeline\Contracts\UploadPipeline;
use App\Modules\Uploads\Pipeline\DTO\InternalPipelineResult;
use App\Modules\Uploads\Pipeline\Contracts\UploadService;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineState;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineToken;
use App\Support\Security\Exceptions\AntivirusException;
use App\Modules\Uploads\Pipeline\Exceptions\ScanFailedException;
use App\Modules\Uploads\Pipeline\Exceptions\UploadException;
use App\Modules\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Modules\Uploads\Pipeline\Exceptions\VirusDetectedException;
use App\Modules\Uploads\Pipeline\Scanning\ScanCoordinatorInterface;
use App\Modules\Uploads\Pipeline\Jobs\ProcessUploadJob;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use App\Infrastructure\Uploads\Pipeline\Security\Upload\UploadSecurityLogger;
use App\Infrastructure\Uploads\Pipeline\Support\ImageUploadReporter;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use App\Infrastructure\Uploads\Pipeline\Support\MediaAttacher;
use Illuminate\Http\UploadedFile;
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
     * Errores AV transitorios que merecen reintento de job.
     *
     * @var list<string>
     */
    private const RETRYABLE_ANTIVIRUS_REASONS = [
        'timeout',
        'process_timeout',
        'unreachable',
        'connection_refused',
        'process_exception',
        'process_failed',
    ];

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
        private readonly MediaSecurityLogger $mediaLogger,
        private readonly AsyncJobDispatcherInterface $jobs,
        private readonly MediaAttacher $attacher = new MediaAttacher(),
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
        $ownerKey = $this->resolveOwnerKey($owner);
        $uploadedFile = $this->unwrap($file);

        $logContext = $this->buildLogContext($correlation, $profile, $ownerKey, $uploadedFile);

        $this->assertConstraints($uploadedFile, $profile);
        [, $token] = $this->quarantineManager->duplicate(
            $uploadedFile,
            $profile,
            $correlation
        );
        $this->securityLogger->quarantined($logContext + ['quarantine_id' => $token?->identifier()]);

        try {
            $this->jobs->dispatch(new ProcessUploadJob(
                token: $token,
                ownerId: (string) $ownerKey,
                profileClass: $profile::class,
                correlationId: $correlation,
                originalName: $uploadedFile->getClientOriginalName(),
                clientMime: $uploadedFile->getClientMimeType() ?? $uploadedFile->getMimeType(),
                tenantId: $this->resolveOwnerTenantId($owner),
            ));
        } catch (\Throwable $exception) {
            $queueConnection = (string) config('queue.default', '');
            $queueDriver = (string) config("queue.connections.{$queueConnection}.driver", $queueConnection);
            if ($queueDriver === 'sync') {
                throw $exception;
            }

            $this->quarantineManager->delete($token);
            throw UploadException::fromThrowable('Unable to enqueue upload processing job.', $exception);
        }

        return new QueuedUploadResult(
            status: 'processing',
            correlationId: $correlation,
            quarantineId: $token->identifier(),
            ownerId: $ownerKey,
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
     * @param bool $preserveRetryableFailure Si true, preserva cuarentena en fallos reintentables
     * @param int|null $retryAttempt Número de intento actual cuando se ejecuta desde cola
     * @return MediaResource Media persistido tras procesar
     */
    public function processQuarantined(
        MediaOwner $owner,
        UploadedFile $quarantinedFile,
        QuarantineToken $token,
        MediaProfile $profile,
        string $correlationId,
        bool $preserveRetryableFailure = false,
        ?int $retryAttempt = null,
    ): MediaResource {
        $ownerKey = $this->resolveOwnerKey($owner);
        $logContext = $this->buildLogContext($correlationId, $profile, $ownerKey, $quarantinedFile);

        $this->securityLogger->started($logContext);

        $artifact = null;
        $currentState = $this->resolveCurrentState($token);
        $removeQuarantine = true;

        try {
            $this->securityLogger->quarantined($logContext + ['quarantine_id' => $token->identifier()]);
            $this->assertConstraints($quarantinedFile, $profile);

            $currentState = $this->transitionToCleanState(
                token: $token,
                profile: $profile,
                file: $quarantinedFile,
                currentState: $currentState,
                logContext: $logContext,
                correlationId: $correlationId,
            );

            // Procesa el archivo a través del pipeline
            $artifact = $this->pipeline->process($quarantinedFile, $profile, $correlationId);
            $artifact = new InternalPipelineResult(
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
                $profile->isSingleFile(),
                $correlationId
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
            return new SpatieMediaResource($media);
        } catch (VirusDetectedException $exception) {
            // Si se detecta virus, marca como infectado
            $this->safeTransition($token, $currentState, QuarantineState::INFECTED, $logContext, $exception);
            $this->securityLogger->virusDetected($logContext + ['error' => $exception->getMessage()]);
            $this->reporter->report('upload.virus_detected', $exception, $logContext, 'warning');
            throw $exception;
        } catch (\Throwable $exception) {
            $isRetryableFailure = $preserveRetryableFailure && $this->isRetryableProcessingException($exception);
            if ($isRetryableFailure) {
                $removeQuarantine = false;
                $this->recordRetryableFailure($token, $currentState, $logContext, $exception, $retryAttempt);
                $this->reporter->report('image_upload.retryable_failure', $exception, $logContext, 'warning');
                $this->securityLogger->validationFailed($logContext + [
                    'error' => $exception->getMessage(),
                    'retryable' => true,
                    'retry_count' => $retryAttempt,
                ]);
            } else {
                // En caso de error terminal, marca como fallido.
                $this->safeTransition($token, $currentState, QuarantineState::FAILED, $logContext, $exception);
                $this->reporter->report('image_upload.failed', $exception, $logContext);
                $this->securityLogger->validationFailed($logContext + ['error' => $exception->getMessage()]);
            }

            throw $exception;
        } finally {
            $this->quarantineManager->cleanupArtifact($artifact, $removeQuarantine);
            if ($artifact === null && $removeQuarantine) {
                $this->quarantineManager->delete($token);
            }
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
     * Adjunta el artefacto procesado a un modelo HasMedia.
     *
     * @param HasMediaContract $owner Entidad propietaria del archivo.
     * @param InternalPipelineResult $artifact Resultado del proceso de subida.
     * @param string $profile Perfil de la colección de medios.
     * @param string|null $disk Disco opcional para la colección.
     * @param bool $singleFile Indicador de colección de archivo único.
     * @return Media Media adjuntado.
     */
    public function attach(
        HasMediaContract $owner,
        InternalPipelineResult $artifact,
        string $profile,
        ?string $disk = null,
        bool $singleFile = false,
        ?string $correlationId = null
    ): Media {
        return $this->attacher->attach($owner, $artifact, $profile, $disk, $singleFile, $correlationId);
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
     * @return int|string
     */
    private function resolveOwnerKey(MediaOwner $owner): int|string
    {
        if (!method_exists($owner, 'getKey')) {
            throw new UploadValidationException('Media owner key is unavailable');
        }

        $key = $owner->getKey();
        if (is_int($key)) {
            return $key;
        }

        if (is_string($key)) {
            $trimmed = trim($key);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        throw new UploadValidationException('Media owner key is unavailable');
    }

    /**
     * @return int|string|null
     */
    private function resolveOwnerTenantId(MediaOwner $owner): int|string|null
    {
        if (method_exists($owner, 'getCurrentTenantId')) {
            $tenantId = $owner->getCurrentTenantId();
            if (is_int($tenantId) && $tenantId > 0) {
                return $tenantId;
            }
            if (is_string($tenantId) && trim($tenantId) !== '') {
                return trim($tenantId);
            }
        }

        if (property_exists($owner, 'current_tenant_id')) {
            $tenantId = $owner->current_tenant_id;
            if (is_int($tenantId) && $tenantId > 0) {
                return $tenantId;
            }
            if (is_string($tenantId) && trim($tenantId) !== '') {
                return trim($tenantId);
            }
        }

        $currentTenant = function_exists('tenant') ? tenant() : null;
        if ($currentTenant !== null && method_exists($currentTenant, 'getKey')) {
            $tenantId = $currentTenant->getKey();
            if (is_int($tenantId) && $tenantId > 0) {
                return $tenantId;
            }
            if (is_string($tenantId) && trim($tenantId) !== '') {
                return trim($tenantId);
            }
        }

        return null;
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

    private function buildLogContext(
        string $correlationId,
        MediaProfile $profile,
        int|string $ownerKey,
        UploadedFile $file
    ): array {
        return [
            'correlation_id' => $correlationId,
            'profile' => $profile->collection(),
            'user_id' => $ownerKey,
            'filename' => $this->sanitizeFilenameForLog($file->getClientOriginalName()),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ];
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
            $this->mediaLogger->debug('media.security.quarantine_transitioned', array_merge($context, [
                'from' => $from->value,
                'to' => $to->value,
                'quarantine_id' => $token->identifier(),
            ], $metadata));
        } catch (\Throwable $transitionException) {
            $this->mediaLogger->warning('media.security.quarantine_transition_failed', array_merge($context, [
                'from' => $from->value,
                'to' => $to->value,
                'quarantine_id' => $token->identifier(),
                'error' => $transitionException->getMessage(),
            ], $metadata));

            throw $transitionException;
        }
    }

    private function resolveCurrentState(QuarantineToken $token): QuarantineState
    {
        try {
            return $this->quarantineManager->getState($token);
        } catch (\Throwable) {
            return QuarantineState::PENDING;
        }
    }

    /**
     * Garantiza estado CLEAN con reentrada segura de reintentos.
     *
     * @param array<string,mixed> $logContext
     */
    private function transitionToCleanState(
        QuarantineToken $token,
        MediaProfile $profile,
        UploadedFile $file,
        QuarantineState $currentState,
        array $logContext,
        string $correlationId
    ): QuarantineState {
        if (in_array($currentState, [QuarantineState::PROMOTED, QuarantineState::INFECTED, QuarantineState::EXPIRED], true)) {
            throw new UploadValidationException('Quarantine artifact is in terminal state.');
        }

        if ($profile->usesAntivirus()) {
            if ($currentState !== QuarantineState::CLEAN) {
                if ($currentState !== QuarantineState::SCANNING) {
                    $this->transitionWithLogging(
                        $token,
                        $currentState,
                        QuarantineState::SCANNING,
                        $logContext,
                        ['correlation_id' => $correlationId]
                    );
                    $currentState = QuarantineState::SCANNING;
                }

                $this->securityLogger->scanStarted($logContext + ['quarantine_id' => $token->identifier()]);
                $this->scanCoordinator->scan($file, $token->path, $logContext);
                $this->securityLogger->scanPassed($logContext + ['quarantine_id' => $token->identifier()]);

                $this->transitionWithLogging(
                    $token,
                    QuarantineState::SCANNING,
                    QuarantineState::CLEAN,
                    $logContext,
                    ['correlation_id' => $correlationId]
                );
            }

            return QuarantineState::CLEAN;
        }

        if ($currentState !== QuarantineState::CLEAN) {
            $this->transitionWithLogging(
                $token,
                $currentState,
                QuarantineState::CLEAN,
                [
                    'correlation_id' => $correlationId,
                    'profile' => $profile->collection(),
                    'user_id' => $logContext['user_id'] ?? null,
                ],
                [
                    'correlation_id' => $correlationId,
                    'metadata' => ['scan' => 'disabled'],
                ]
            );
        }

        return QuarantineState::CLEAN;
    }

    private function isRetryableProcessingException(\Throwable $exception): bool
    {
        if ($exception instanceof ScanFailedException) {
            return true;
        }

        if ($exception instanceof UploadValidationException) {
            $antivirus = $this->findAntivirusException($exception);
            if (! $antivirus instanceof AntivirusException) {
                return false;
            }

            return in_array(
                strtolower(trim($antivirus->reason())),
                self::RETRYABLE_ANTIVIRUS_REASONS,
                true
            );
        }

        if ($exception instanceof UploadException) {
            $previous = $exception->getPrevious();
            if ($previous instanceof UploadValidationException || $previous instanceof VirusDetectedException) {
                return false;
            }

            return true;
        }

        return false;
    }

    private function findAntivirusException(\Throwable $exception): ?AntivirusException
    {
        $cursor = $exception;

        while ($cursor !== null) {
            if ($cursor instanceof AntivirusException) {
                return $cursor;
            }

            $cursor = $cursor->getPrevious();
        }

        return null;
    }

    /**
     * Registra el fallo reintentable sin llevar el artefacto a estado terminal.
     *
     * @param array<string,mixed> $context
     */
    private function recordRetryableFailure(
        QuarantineToken $token,
        QuarantineState $currentState,
        array $context,
        \Throwable $exception,
        ?int $retryAttempt,
    ): void {
        $metadata = [
            'correlation_id' => $context['correlation_id'] ?? null,
            'metadata' => [
                'status' => 'retrying',
                'retryable' => true,
                'retry_count' => $retryAttempt,
                'reason' => $exception->getMessage(),
                'exception' => $exception::class,
            ],
        ];

        try {
            // Mantiene el estado actual para preservar el artefacto de cuarentena entre reintentos.
            $this->transitionWithLogging(
                $token,
                $currentState,
                $currentState,
                $context + ['retry_count' => $retryAttempt, 'retryable' => true],
                $metadata,
            );
        } catch (\Throwable) {
            // Best-effort: no bloquea la propagación del error reintentable.
        }
    }
}
