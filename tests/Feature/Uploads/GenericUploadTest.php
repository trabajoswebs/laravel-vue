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
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 content', 'application/pdf'),
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
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 content', 'application/pdf'),
            ])
            ->assertCreated()
            ->json('id');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->patch(route('uploads.update', ['uploadId' => $store]), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->createWithContent('doc2.pdf', '%PDF-1.4 content v2', 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'profile_id', 'status', 'correlation_id']);
    }

    public function test_delete_document_upload_succeeds(): void
    {
        Storage::fake('public');
        [$user, $tenant] = $this->makeTenantUser();

        $uploadId = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 content', 'application/pdf'),
            ])
            ->assertCreated()
            ->json('id');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->delete(route('uploads.destroy', ['uploadId' => $uploadId]))
            ->assertNoContent();
    }

    public function test_cross_tenant_operations_are_forbidden(): void
    {
        Storage::fake('public');
        [$owner, $tenant] = $this->makeTenantUser();
        $foreign = User::factory()->create(['current_tenant_id' => null]);

        $uploadId = $this->actingAs($owner)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 content', 'application/pdf'),
            ])
            ->json('id');

        $this->actingAs($foreign)
            ->withSession(['tenant_id' => null])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 content', 'application/pdf'),
            ])
            ->assertStatus(403);

        $this->actingAs($foreign)
            ->withSession(['tenant_id' => null])
            ->withHeader('Accept', 'application/json')
            ->patch(route('uploads.update', ['uploadId' => $uploadId]), [
                'profile_id' => 'document_pdf',
                'file' => UploadedFile::fake()->createWithContent('doc2.pdf', '%PDF-1.4 content v2', 'application/pdf'),
            ])
            ->assertStatus(403);

        $this->actingAs($foreign)
            ->withSession(['tenant_id' => null])
            ->withHeader('Accept', 'application/json')
            ->delete(route('uploads.destroy', ['uploadId' => $uploadId]))
            ->assertStatus(403);
    }

    public function test_secret_upload_download_is_forbidden(): void
    {
        Storage::fake('public');
        [$user, $tenant] = $this->makeTenantUser();

        $uploadId = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'certificate_secret',
                'file' => UploadedFile::fake()->createWithContent('cert.p12', 'secret-bytes', 'application/octet-stream'),
            ])
            ->json('id');

        Log::spy();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('uploads.download', ['uploadId' => $uploadId]))
            ->assertStatus(403);
    }
}
