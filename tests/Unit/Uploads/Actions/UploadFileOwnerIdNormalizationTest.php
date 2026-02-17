<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Actions;

use App\Application\Uploads\Actions\UploadFile;
use App\Application\Uploads\Contracts\OwnerIdNormalizerInterface;
use App\Application\Uploads\Contracts\UploadOrchestratorInterface;
use App\Application\Uploads\DTO\UploadResult;
use App\Application\Uploads\Exceptions\InvalidOwnerIdException;
use App\Domain\Uploads\UploadProfile;
use App\Models\User;
use App\Infrastructure\Uploads\Core\Services\ConfigurableOwnerIdNormalizer;
use App\Modules\Uploads\Contracts\UploadedMedia;
use Tests\TestCase;

final class UploadFileOwnerIdNormalizationTest extends TestCase
{
    public function test_non_integral_float_owner_id_throws_in_int_mode(): void
    {
        config()->set('uploads.owner_id.mode', 'int');

        $orchestrator = $this->createMock(UploadOrchestratorInterface::class);
        $orchestrator->expects($this->never())->method('upload');

        $action = new UploadFile($orchestrator, $this->normalizer());

        $this->expectException(InvalidOwnerIdException::class);

        $action(
            $this->createMock(UploadProfile::class),
            new User(),
            $this->createMock(UploadedMedia::class),
            7.25,
        );
    }

    public function test_integral_float_owner_id_is_rejected_in_int_mode(): void
    {
        config()->set('uploads.owner_id.mode', 'int');

        $orchestrator = $this->createMock(UploadOrchestratorInterface::class);
        $orchestrator->expects($this->never())->method('upload');

        $action = new UploadFile($orchestrator, $this->normalizer());
        $this->expectException(InvalidOwnerIdException::class);

        $action(
            $this->createMock(UploadProfile::class),
            new User(),
            $this->createMock(UploadedMedia::class),
            7.0,
        );
    }

    public function test_numeric_string_owner_id_is_normalized_to_integer_in_int_mode(): void
    {
        config()->set('uploads.owner_id.mode', 'int');

        $expected = new UploadResult('1', 1, 'p', 'public', 'x', 'text/plain', 10, null, 'stored', null);

        $orchestrator = $this->createMock(UploadOrchestratorInterface::class);
        $orchestrator->expects($this->once())
            ->method('upload')
            ->with(
                $this->isInstanceOf(UploadProfile::class),
                $this->isInstanceOf(User::class),
                $this->isInstanceOf(UploadedMedia::class),
                $this->identicalTo(7),
                null,
                [],
            )
            ->willReturn($expected);

        $action = new UploadFile($orchestrator, $this->normalizer());

        $result = $action(
            $this->createMock(UploadProfile::class),
            new User(),
            $this->createMock(UploadedMedia::class),
            '7',
        );

        $this->assertSame($expected, $result);
    }

    public function test_uuid_mode_accepts_only_valid_uuid(): void
    {
        config()->set('uploads.owner_id.mode', 'uuid');

        $expected = new UploadResult('1', 1, 'p', 'public', 'x', 'text/plain', 10, null, 'stored', null);
        $ownerId = '550e8400-e29b-41d4-a716-446655440000';

        $orchestrator = $this->createMock(UploadOrchestratorInterface::class);
        $orchestrator->expects($this->once())
            ->method('upload')
            ->with(
                $this->isInstanceOf(UploadProfile::class),
                $this->isInstanceOf(User::class),
                $this->isInstanceOf(UploadedMedia::class),
                $this->identicalTo($ownerId),
                null,
                [],
            )
            ->willReturn($expected);

        $action = new UploadFile($orchestrator, $this->normalizer());
        $result = $action(
            $this->createMock(UploadProfile::class),
            new User(),
            $this->createMock(UploadedMedia::class),
            $ownerId,
        );

        $this->assertSame($expected, $result);
    }

    public function test_ulid_mode_rejects_invalid_string(): void
    {
        config()->set('uploads.owner_id.mode', 'ulid');

        $orchestrator = $this->createMock(UploadOrchestratorInterface::class);
        $orchestrator->expects($this->never())->method('upload');

        $action = new UploadFile($orchestrator, $this->normalizer());
        $this->expectException(InvalidOwnerIdException::class);

        $action(
            $this->createMock(UploadProfile::class),
            new User(),
            $this->createMock(UploadedMedia::class),
            'not-an-ulid',
        );
    }

    private function normalizer(): OwnerIdNormalizerInterface
    {
        return new ConfigurableOwnerIdNormalizer();
    }
}
