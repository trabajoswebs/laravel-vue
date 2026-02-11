<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Quarantine;

use App\Infrastructure\Uploads\Pipeline\Quarantine\LocalQuarantineRepository;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineState;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class LocalQuarantineRepositoryTest extends TestCase
{
    public function test_resolve_token_by_identifier_rejects_traversal(): void
    {
        Storage::fake('quarantine');
        $repository = new LocalQuarantineRepository(Storage::disk('quarantine'));

        self::assertNull($repository->resolveTokenByIdentifier('../escape.bin'));
        self::assertNull($repository->resolveTokenByIdentifier('safe/../../escape.bin'));
    }

    public function test_transition_does_not_recreate_missing_artifact(): void
    {
        Storage::fake('quarantine');
        $repository = new LocalQuarantineRepository(Storage::disk('quarantine'));
        $token = $repository->put('payload');

        @unlink($token->path);
        $repository->transition($token, QuarantineState::PENDING, QuarantineState::FAILED);

        self::assertFileDoesNotExist($token->path);
        self::assertSame(QuarantineState::FAILED, $repository->getState($token));
    }
}
