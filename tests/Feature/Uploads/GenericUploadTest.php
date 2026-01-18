<?php

declare(strict_types=1);

namespace Tests\Feature\Uploads;

use App\Infrastructure\Models\User;
use App\Infrastructure\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class GenericUploadTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantUser(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'name' => 'Tenant',
            'owner_user_id' => $user->id,
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        return [$user, $tenant];
    }

    public function test_store_document_upload_succeeds(): void
    {
        Storage::fake('public');
        [$user, $tenant] = $this->makeTenantUser();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'profile_id', 'status', 'correlation_id']);
    }

    public function test_replace_document_upload_succeeds(): void
    {
        Storage::fake('public');
        [$user, $tenant] = $this->makeTenantUser();

        $store = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('id');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->patchJson(route('uploads.update', ['uploadId' => $store]), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->create('doc2.pdf', 12, 'application/pdf'),
            ])
            ->assertOk()
            ->assertJsonStructure(['id', 'profile_id', 'status', 'correlation_id']);
    }

    public function test_delete_document_upload_succeeds(): void
    {
        Storage::fake('public');
        [$user, $tenant] = $this->makeTenantUser();

        $uploadId = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('id');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->deleteJson(route('uploads.destroy', ['uploadId' => $uploadId]))
            ->assertNoContent();
    }

    public function test_cross_tenant_operations_are_forbidden(): void
    {
        Storage::fake('public');
        [$owner, $tenant] = $this->makeTenantUser();
        $foreign = User::factory()->create(['current_tenant_id' => null]);

        $uploadId = $this->actingAs($owner)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ])
            ->json('id');

        $this->actingAs($foreign)
            ->withSession(['tenant_id' => null])
            ->postJson(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(403);

        $this->actingAs($foreign)
            ->withSession(['tenant_id' => null])
            ->patchJson(route('uploads.update', ['uploadId' => $uploadId]), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->create('doc2.pdf', 12, 'application/pdf'),
            ])
            ->assertStatus(403);

        $this->actingAs($foreign)
            ->withSession(['tenant_id' => null])
            ->deleteJson(route('uploads.destroy', ['uploadId' => $uploadId]))
            ->assertStatus(403);
    }

    public function test_secret_upload_download_is_forbidden(): void
    {
        Storage::fake('public');
        [$user, $tenant] = $this->makeTenantUser();

        $uploadId = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('uploads.store'), [
                'profile_id' => 'certificate_secret',
                'file' => UploadedFile::fake()->create('cert.p12', 5, 'application/octet-stream'),
            ])
            ->json('id');

        Log::spy();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('uploads.download', ['uploadId' => $uploadId]))
            ->assertStatus(403);
    }
}
