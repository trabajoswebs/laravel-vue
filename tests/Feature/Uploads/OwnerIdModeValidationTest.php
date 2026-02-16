<?php

declare(strict_types=1);

namespace Tests\Feature\Uploads;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class OwnerIdModeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('quarantine');

        config([
            'uploads.virus_scanning.enabled' => false,
            'uploads.private_disk' => 'public',
            'image-pipeline.scan.enabled' => false,
        ]);

        $this->app->singleton(
            \App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineRepository::class,
            fn() => new \App\Infrastructure\Uploads\Pipeline\Quarantine\LocalQuarantineRepository(Storage::disk('quarantine'))
        );
    }

    public function test_store_rejects_invalid_owner_id_in_uuid_mode(): void
    {
        config()->set('uploads.owner_id.mode', 'uuid');

        [$user, $tenant] = $this->makeTenantUser();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'import_csv',
                'owner_id' => 'not-a-uuid',
                'file' => $this->fakeCsv(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_id']);
    }

    public function test_store_persists_normalized_uuid_owner_id(): void
    {
        config()->set('uploads.owner_id.mode', 'uuid');

        [$user, $tenant] = $this->makeTenantUser();
        $ownerId = '550E8400-E29B-41D4-A716-446655440000';

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'import_csv',
                'owner_id' => $ownerId,
                'file' => $this->fakeCsv(),
            ])
            ->assertCreated();

        $uploadId = (string) $response->json('id');

        $this->assertDatabaseHas('uploads', [
            'id' => $uploadId,
            'owner_id' => strtolower($ownerId),
            'owner_type' => User::class,
        ]);
    }

    public function test_store_rejects_float_owner_id_in_int_mode(): void
    {
        config()->set('uploads.owner_id.mode', 'int');

        [$user, $tenant] = $this->makeTenantUser();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'import_csv',
                'owner_id' => 7.25,
                'file' => $this->fakeCsv(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_id']);
    }

    public function test_store_persists_normalized_ulid_owner_id(): void
    {
        config()->set('uploads.owner_id.mode', 'ulid');

        [$user, $tenant] = $this->makeTenantUser();
        $ownerId = '01arz3ndektsv4rrffq69g5fav';

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'import_csv',
                'owner_id' => $ownerId,
                'file' => $this->fakeCsv(),
            ])
            ->assertCreated();

        $uploadId = (string) $response->json('id');

        $this->assertDatabaseHas('uploads', [
            'id' => $uploadId,
            'owner_id' => strtoupper($ownerId),
            'owner_type' => User::class,
        ]);
    }

    /**
     * @return array{0:User,1:Tenant}
     */
    private function makeTenantUser(): array
    {
        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = Tenant::query()->create([
            'name' => 'Owner Mode Tenant',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        return [$user, $tenant];
    }

    private function fakeCsv(): UploadedFile
    {
        $payload = "email\nalice@example.com\n";

        return UploadedFile::fake()
            ->createWithContent('dataset.csv', $payload)
            ->mimeType('text/csv');
    }
}
