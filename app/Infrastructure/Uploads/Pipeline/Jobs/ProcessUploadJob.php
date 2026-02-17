<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Jobs;

use App\Modules\Uploads\Contracts\MediaProfile;
use App\Application\User\Contracts\UserRepository;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineToken;
use App\Infrastructure\Uploads\Pipeline\DefaultUploadService;
use App\Infrastructure\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Infrastructure\Uploads\Pipeline\Exceptions\VirusDetectedException;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineState;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use App\Support\Security\Exceptions\AntivirusException;
use App\Modules\Uploads\Contracts\MediaOwner;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use App\Support\Logging\SecurityLogger;
use App\Support\Contracts\MetricsInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Job que ejecuta el pipeline completo de subida en segundo plano.
 * 
 * Este job procesa archivos que están en cuarentena, realizando validaciones,
 * escaneos de seguridad y adjuntándolos al modelo propietario correspondiente.
 */
final class ProcessUploadJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Reintentos razonables: errores de red/infra pueden reintentarse, virus no.
     */
    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 300;
    public int $uniqueFor = 900;

    /**
     * Constructor del job de procesamiento de subida.
     * 
     * @param QuarantineToken $token Artefacto en cuarentena a procesar
     * @param string $ownerId ID del propietario
     * @param class-string<MediaProfile> $profileClass Clase del perfil a usar
     * @param string $correlationId Correlation ID para trazabilidad
     * @param string|null $originalName Nombre original del fichero
     * @param string|null $clientMime Mime recibido del cliente
     * @param int|string|null $tenantId Tenant esperado para este procesamiento
     */
    public function __construct(
        private readonly QuarantineToken $token,
        private readonly string $ownerId,
        private readonly string $profileClass,
        private readonly string $correlationId,
        private readonly ?string $originalName = null,
        private readonly ?string $clientMime = null,
        private readonly int|string|null $tenantId = null,
    ) {
        $this->onQueue(config('queue.aliases.media', 'media'));
        $this->afterCommit();
    }

    /**
     * Ejecuta el pipeline completo en cola.
     * 
     * @param DefaultUploadService $uploader Servicio de subida
     * @param UserRepository $users Repositorio de usuarios
     * @param MetricsInterface $metrics Servicio de métricas
     * @param QuarantineManager $quarantine Gestor de cuarentena
     */
    public function handle(
        DefaultUploadService $uploader,
        UserRepository $users,
        MetricsInterface $metrics,
        QuarantineManager $quarantine,
    ): void
    {
        $startedAt = microtime(true);
        $resultTag = 'error';
        $profile = app($this->profileClass);
        if (!$profile instanceof MediaProfile) {
            SecurityLogger::error('process_upload.invalid_profile', ['profile' => $this->profileClass]);
            $this->fail(new UploadValidationException('Invalid profile class for queued upload.'));
            return;
        }

        try {
            $owner = $users->lockAndFindById($this->ownerId);
        } catch (ModelNotFoundException) {
            $resultTag = 'stale_owner_missing';
            $metrics->increment('upload.jobs.stale_owner_missing', [
                'profile' => $profile->collection(),
            ]);
            SecurityLogger::info('process_upload.stale_owner_missing', $this->safeContext([
                'correlation_id' => $this->correlationId,
                'token' => $this->token->identifier(),
                'owner_id' => $this->ownerId,
                'profile' => $profile->collection(),
            ]));
            $quarantine->delete($this->token);
            return;
        }

        SecurityLogger::info('process_upload.started', $this->safeContext([
            'correlation_id' => $this->correlationId,
            'token' => $this->token->identifier(),
            'user_id' => $owner->getKey(),
            'profile' => $profile->collection(),
            'tenant_id' => $this->tenantId,
        ]));

        try {
            $this->assertTenantConsistency($owner);

            $upload = new UploadedFile(
                $this->token->path,
                $this->originalName ?? basename($this->token->path),
                $this->clientMime,
                null,
                true
            );

            $uploader->processQuarantined(
                $owner,
                $upload,
                $this->token,
                $profile,
                $this->correlationId,
                true,
                $this->attempts()
            );
            $resultTag = 'success';
            $metrics->increment('upload.jobs.success', $this->metricTags($profile));
        } catch (VirusDetectedException $exception) {
            $resultTag = 'virus';
            $metrics->increment('upload.jobs.virus_detected', $this->metricTags($profile));
            $this->fail($exception);
            return;
        } catch (UploadValidationException $exception) {
            $resultTag = 'failed';
            $metrics->increment('upload.jobs.validation_failed', $this->metricTags($profile));
            if ($this->isRetryableValidationFailure($exception)) {
                $resultTag = 'retryable_failed';
                $metrics->increment('upload.jobs.retryable_failures', $this->metricTags($profile));
                SecurityLogger::warning('process_upload.retrying', $this->safeContext([
                    'correlation_id' => $this->correlationId,
                    'token' => $this->token->identifier(),
                    'profile' => $profile->collection(),
                    'error' => $exception->getMessage(),
                ]));

                throw $exception;
            }

            $this->fail($exception);
            return;
        } catch (Throwable $exception) {
            $metrics->increment('upload.jobs.errors', $this->metricTags($profile));
            throw $exception;
        } finally {
            $metrics->timing('upload.jobs.duration_ms', (microtime(true) - $startedAt) * 1000, [
                'result' => $resultTag,
                'profile' => $profile->collection(),
            ]);
        }
    }

    /**
     * Genera la clave única para evitar jobs duplicados.
     * 
     * @return string Clave única del job
     */
    public function uniqueId(): string
    {
        return 'process-upload:' . $this->token->identifier();
    }

    /**
     * Maneja el fallo del job eliminando el archivo de cuarentena.
     * 
     * @param Throwable|null $exception Excepción que causó el fallo
     */
    public function failed(?Throwable $exception): void
    {
        try {
            $quarantine = app(QuarantineManager::class);
            try {
                $current = $quarantine->getState($this->token);
                if (!in_array($current, [QuarantineState::PROMOTED, QuarantineState::INFECTED, QuarantineState::EXPIRED, QuarantineState::FAILED], true)) {
                    $quarantine->transition(
                        $this->token,
                        $current,
                        QuarantineState::FAILED,
                        [
                            'correlation_id' => $this->correlationId,
                            'metadata' => [
                                'status' => 'terminal_failed',
                                'reason' => $exception?->getMessage(),
                                'attempts' => $this->attempts(),
                            ],
                        ]
                    );
                }
            } catch (Throwable) {
                // Best-effort en transición terminal.
            }
            $quarantine->delete($this->token);
        } catch (Throwable $cleanupError) {
            SecurityLogger::warning('process_upload.failed_cleanup_error', $this->safeContext([
                'correlation_id' => $this->correlationId,
                'token' => $this->token->identifier(),
                'profile' => $this->profileClass,
                'error' => $cleanupError->getMessage(),
            ]));
        }
    }

    /**
     * Obtiene las etiquetas para métricas de telemetría.
     * 
     * @param MediaProfile $profile Perfil de medios
     * @return array<string,string> Etiquetas para métricas
     */
    private function metricTags(MediaProfile $profile): array
    {
        return [
            'profile' => $profile->collection(),
        ];
    }

    /**
     * Sanitiza el contexto para logging seguro.
     * 
     * @param array<string,mixed> $context Contexto a sanear
     * @return array<string,mixed> Contexto saneado
     */
    private function safeContext(array $context): array
    {
        return app(MediaLogSanitizer::class)->safeContext($context);
    }

    /**
     * Determina si un fallo de validación es reintentable.
     * 
     * @param UploadValidationException $exception Excepción de validación
     * @return bool True si el fallo es reintentable
     */
    private function isRetryableValidationFailure(UploadValidationException $exception): bool
    {
        $cursor = $exception;
        while ($cursor !== null) {
            if ($cursor instanceof AntivirusException) {
                return in_array(strtolower(trim($cursor->reason())), [
                    'timeout',
                    'process_timeout',
                    'unreachable',
                    'connection_refused',
                    'process_exception',
                    'process_failed',
                ], true);
            }
            $cursor = $cursor->getPrevious();
        }

        return false;
    }

    private function assertTenantConsistency(MediaOwner $owner): void
    {
        if ($this->tenantId === null || (is_string($this->tenantId) && trim($this->tenantId) === '')) {
            return;
        }

        $ownerTenantId = $this->resolveOwnerTenantId($owner);
        if ($ownerTenantId === null || (string) $ownerTenantId !== (string) $this->tenantId) {
            throw new UploadValidationException('Tenant mismatch for queued upload processing.');
        }
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

        return null;
    }
}
