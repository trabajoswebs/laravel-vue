<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads;

use App\Application\Uploads\DTO\UploadResult as ApplicationUploadResult;
use App\Modules\Uploads\Pipeline\Contracts\UploadMetadata;
use App\Modules\Uploads\Pipeline\DTO\InternalPipelineResult;
use App\Modules\Uploads\Pipeline\Support\PipelineResultMapper;
use PHPUnit\Framework\TestCase;

final class PipelineResultMapperTest extends TestCase
{
    public function test_it_maps_internal_result_to_application_dto(): void
    {
        $mapper = new PipelineResultMapper();
        $metadata = new UploadMetadata(
            mime: 'application/pdf',
            extension: 'pdf',
            hash: 'abc123',
            dimensions: null,
            originalFilename: 'doc.pdf',
        );

        $internal = new InternalPipelineResult(
            path: '/tmp/file.pdf',
            size: 1024,
            metadata: $metadata,
            quarantineId: null,
        );

        $result = $mapper->toApplication(
            result: $internal,
            tenantId: 1,
            profileId: 'document_pdf',
            disk: 'public',
            correlationId: 'cid-123',
            id: 'upload-1',
            status: 'stored',
            pathOverride: 'documents/file.pdf',
        );

        $this->assertInstanceOf(ApplicationUploadResult::class, $result);
        $this->assertSame('upload-1', $result->id);
        $this->assertSame(1, $result->tenantId);
        $this->assertSame('document_pdf', $result->profileId);
        $this->assertSame('public', $result->disk);
        $this->assertSame('documents/file.pdf', $result->path);
        $this->assertSame('application/pdf', $result->mime);
        $this->assertSame(1024, $result->size);
        $this->assertSame('abc123', $result->checksum);
        $this->assertSame('stored', $result->status);
        $this->assertSame('cid-123', $result->correlationId);
    }
}
