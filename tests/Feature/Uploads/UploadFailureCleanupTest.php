<?php

namespace Tests\Feature\Uploads;

use App\Application\Uploads\Contracts\UploadOrchestratorInterface;
use App\Domain\Uploads\UploadProfileId;
use App\Infrastructure\Uploads\Http\Requests\HttpUploadedMedia;
use App\Infrastructure\Uploads\Pipeline\Quarantine\LocalQuarantineRepository;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Infrastructure\Uploads\Pipeline\Scanning\ScanCoordinatorInterface;
use App\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class UploadFailureCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_quarantine_is_cleaned_on_scan_failure(): void
    {
        // Arrange: fake disks and bindings for quarantine and scanner failure
        Storage::fake('quarantine');
        Storage::fake('public');
        config(['uploads.virus_scanning.enabled' => true]);
        $this->app->singleton(QuarantineRepository::class, fn () => new LocalQuarantineRepository(Storage::disk('quarantine')));
        $this->app->singleton(ScanCoordinatorInterface::class, fn () => new class implements ScanCoordinatorInterface {
            public function enabled(): bool { return true; }
            public function scan(\Illuminate\Http\UploadedFile $file, string $path, array $context = []): void
            {
                throw new RuntimeException('scan failed');
            }
        });

        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant Cleanup',
            'owner_user_id' => $user->id,
        ]);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        $file = UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\nbody");
        $orchestrator = $this->app->make(UploadOrchestratorInterface::class);
        $profile = $this->app->make(\App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry::class)->get(new UploadProfileId('document_pdf'));

        // Act: expect failure and cleanup
        $this->expectException(RuntimeException::class);
        $orchestrator->upload($profile, $user, new HttpUploadedMedia($file));

        // Assert: quarantine disk has no leftover files
        $this->assertCount(0, Storage::disk('quarantine')->allFiles());
    }
}
