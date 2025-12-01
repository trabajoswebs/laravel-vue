<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Jobs;

use App\Application\Media\Contracts\MediaProfile;
use App\Application\User\Contracts\UserRepository;
use App\Infrastructure\Media\Upload\Core\QuarantineToken;
use App\Infrastructure\Media\Upload\DefaultUploadService;
use App\Infrastructure\Media\Upload\Exceptions\UploadValidationException;
use App\Infrastructure\Media\Upload\Exceptions\VirusDetectedException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job que ejecuta el pipeline completo de subida en segundo plano.
 */
final class ProcessUploadJob implements ShouldQueue
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

    /**
     * @param QuarantineToken $token Artefacto en cuarentena a procesar
     * @param string $ownerId ID del propietario
     * @param class-string<MediaProfile> $profileClass Clase del perfil a usar
     * @param string $correlationId Correlation ID para trazabilidad
     * @param string|null $originalName Nombre original del fichero
     * @param string|null $clientMime Mime recibido del cliente
     */
    public function __construct(
        private readonly QuarantineToken $token,
        private readonly string $ownerId,
        private readonly string $profileClass,
        private readonly string $correlationId,
        private readonly ?string $originalName = null,
        private readonly ?string $clientMime = null,
    ) {
    }

    /**
     * Ejecuta el pipeline completo en cola.
     */
    public function handle(DefaultUploadService $uploader, UserRepository $users): void
    {
        $profile = app($this->profileClass);
        if (!$profile instanceof MediaProfile) {
            Log::error('process_upload.invalid_profile', ['profile' => $this->profileClass]);
            $this->fail(new UploadValidationException('Invalid profile class for queued upload.'));
            return;
        }

        $owner = $users->lockAndFindById($this->ownerId);

        Log::withContext([
            'correlation_id' => $this->correlationId,
            'quarantine_id' => $this->token->identifier(),
            'user_id' => $owner->getKey(),
            'profile' => $profile->collection(),
        ]);

        $upload = new UploadedFile(
            $this->token->path,
            $this->originalName ?? basename($this->token->path),
            $this->clientMime,
            null,
            true
        );

        try {
            $uploader->processQuarantined($owner, $upload, $this->token, $profile, $this->correlationId);
        } catch (VirusDetectedException $exception) {
            // No reintentamos si hay virus: marcamos fail() para evitar requeues.
            $this->fail($exception);
        } catch (UploadValidationException $exception) {
            // Errores de validación tampoco se reintentan.
            $this->fail($exception);
        } catch (Throwable $exception) {
            // Otros errores se reintentan según configuración de la cola.
            throw $exception;
        }
    }
}
