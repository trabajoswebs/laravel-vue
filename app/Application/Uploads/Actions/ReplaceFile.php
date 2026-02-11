<?php

declare(strict_types=1);

namespace App\Application\Uploads\Actions;

use App\Application\Uploads\DTO\ReplacementResult;
use App\Application\Uploads\DTO\UploadResult;
use App\Domain\Uploads\UploadProfile;
use App\Infrastructure\Models\User;
use App\Infrastructure\Uploads\Core\Contracts\UploadedMedia;
use App\Infrastructure\Uploads\Core\Models\Upload;
use Illuminate\Support\Facades\Storage;
use App\Support\Logging\SecurityLogger;

/**
 * Acción de aplicación para reemplazar archivos.
 *
 * Si se proporciona un Upload existente, lo elimina (best-effort) tras crear el nuevo.
 * Si no se proporciona, actúa como un simple wrapper de UploadFile y devuelve el nuevo resultado.
 */
final class ReplaceFile
{
    public function __construct(private readonly UploadFile $uploadFile)
    {
    }

    /**
     * Reemplaza un archivo existente con uno nuevo (o simplemente crea uno nuevo si no hay previo).
     */
    public function __invoke(
        UploadProfile $profile,
        User $user,
        UploadedMedia $media,
        mixed $ownerId = null,
        ?string $correlationId = null,
        array $meta = [],
        ?Upload $replacing = null,
    ): ReplacementResult {
        $previous = $replacing ? $this->toUploadResult($replacing) : null;

        $new = ($this->uploadFile)(
            $profile,
            $user,
            $media,
            $ownerId,
            $correlationId,
            $meta,
        );

        if ($replacing) {
            $this->deletePreviousFile($replacing);
            $replacing->delete();
        }

        return new ReplacementResult($new, $previous);
    }

    private function deletePreviousFile(Upload $upload): void
    {
        try {
            $disk = (string) $upload->disk;
            $path = (string) $upload->path;

            if ($disk !== '' && $path !== '' && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (\Throwable $e) {
            SecurityLogger::warning('uploads.replace.delete_old_failed', [
                'upload_id' => (string) $upload->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function toUploadResult(Upload $upload): UploadResult
    {
        return new UploadResult(
            id: (string) $upload->getKey(),
            tenantId: (string) $upload->tenant_id,
            profileId: (string) $upload->profile_id,
            disk: (string) $upload->disk,
            path: (string) $upload->path,
            mime: (string) ($upload->mime ?? 'application/octet-stream'),
            size: (int) ($upload->size ?? 0),
            checksum: $upload->checksum ? (string) $upload->checksum : null,
            status: (string) ($upload->status ?? 'stored'),
            correlationId: $upload->correlation_id ? (string) $upload->correlation_id : null,
        );
    }
}
