<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Support;

use App\Application\Uploads\DTO\UploadResult;
use App\Modules\Uploads\Pipeline\DTO\InternalPipelineResult;
use Illuminate\Support\Str;

/**
 * Traduce resultados internos del pipeline al DTO canónico de aplicación.
 */
final class PipelineResultMapper
{
    public function toApplication(
        InternalPipelineResult $result,
        int|string $tenantId,
        string $profileId,
        string $disk,
        ?string $correlationId = null,
        ?string $id = null,
        string $status = 'stored',
        ?string $pathOverride = null,
    ): UploadResult {
        return new UploadResult(
            id: $id ?? (string) Str::uuid(),
            tenantId: $tenantId,
            profileId: $profileId,
            disk: $disk,
            path: $pathOverride ?? $result->path,
            mime: $result->metadata->mime,
            size: $result->size,
            checksum: $result->metadata->hash,
            status: $status,
            correlationId: $correlationId,
        );
    }
}
