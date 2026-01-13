<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controllers\Health;

use App\Infrastructure\Http\Controllers\Controller;
use App\Infrastructure\Media\Health\UploadPipelineHealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

final class HealthController extends Controller
{
    public function uploadPipeline(UploadPipelineHealthCheck $healthCheck): JsonResponse
    {
        $report = $healthCheck->run();
        $status = Collection::make($report)->every(static fn ($item) => ($item['ok'] ?? false) === true)
            ? 'ok'
            : 'degraded';

        return response()->json([
            'status' => $status,
            'checks' => $report,
        ]);
    }
}
