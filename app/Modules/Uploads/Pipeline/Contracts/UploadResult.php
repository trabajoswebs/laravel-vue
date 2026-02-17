<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Contracts;

use App\Modules\Uploads\Pipeline\DTO\InternalPipelineResult;

/**
 * @deprecated Usar App\Modules\Uploads\Pipeline\DTO\InternalPipelineResult.
 *
 * Shim para compatibilidad con jobs serializados que aún referencian el FQCN anterior.
 */
final class UploadResult extends InternalPipelineResult
{
}
