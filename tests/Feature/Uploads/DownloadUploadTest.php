<?php

namespace Tests\Feature\Uploads;

use App\Infrastructure\Uploads\Core\Models\Upload;
use App\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class DownloadUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_download_succeeds_in_same_tenant(): void
    {
        // Arrange: tenant/user, file on disk, and upload row
        Storage::fake('public');
        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant Download',
            'owner_user_id' => $user->id,
        ]);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $path = 'tenants/'.$tenant->id.'/documents/2025/01/file.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4');
        $upload = Upload::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'profile_id' => 'document_pdf',
            'disk' => 'public',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 8,
            'checksum' => null,
            'original_name' => null,
            'visibility' => 'private',
            'created_by_user_id' => $user->id,
        ]);

        // Act: download within the same tenant
        $response = $this->actingAs($user)->get(route('uploads.download', ['uploadId' => $upload->id]));

        // Assert: attachment served with correct headers
        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename="file.pdf"');
    }

    public function test_secret_download_is_forbidden_and_logged(): void
    {
        // Arrange: tenant/user and secret upload entry
        Storage::fake('public');
        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant Secret',
            'owner_user_id' => $user->id,
        ]);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $path = 'tenants/'.$tenant->id.'/secrets/certificates/secret.p12';
        Storage::disk('public')->put($path, 'secret-bytes');
        $upload = Upload::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'profile_id' => 'certificate_secret',
            'disk' => 'public',
            'path' => $path,
            'mime' => 'application/x-pkcs12',
            'size' => 12,
            'checksum' => null,
            'original_name' => null,
            'visibility' => 'private',
            'created_by_user_id' => $user->id,
        ]);
        Log::spy();

        // Act: attempt download of secret
        $response = $this->actingAs($user)->get(route('uploads.download', ['uploadId' => $upload->id]));

        // Assert: forbidden
        $response->assertStatus(403);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message, array $context) use ($upload): bool {
                return $message === 'secret_download_attempt'
                    && isset($context['tenant_id'], $context['upload_id'])
                    && (string) $context['tenant_id'] === (string) $upload->tenant_id
                    && (string) $context['upload_id'] === (string) $upload->getKey();
            });
    }

    public function test_cross_tenant_download_is_forbidden(): void
    {
        // Arrange: two tenants, upload in tenant A, user in tenant B
        Storage::fake('public');
        $ownerA = User::factory()->create(['current_tenant_id' => null]);
        $tenantA = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant A',
            'owner_user_id' => $ownerA->id,
        ]);
        $ownerA->forceFill(['current_tenant_id' => $tenantA->id])->save();
        $ownerA->tenants()->attach($tenantA->id, ['role' => 'owner']);
        $pathA = 'tenants/'.$tenantA->id.'/docs/a.pdf';
        Storage::disk('public')->put($pathA, '%PDF-1.4');
        $uploadA = Upload::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'profile_id' => 'document_pdf',
            'disk' => 'public',
            'path' => $pathA,
            'mime' => 'application/pdf',
            'size' => 8,
            'checksum' => null,
            'original_name' => null,
            'visibility' => 'private',
            'created_by_user_id' => $ownerA->id,
        ]);

        $userB = User::factory()->create(['current_tenant_id' => null]);
        $tenantB = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant B',
            'owner_user_id' => $userB->id,
        ]);
        $userB->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $userB->tenants()->attach($tenantB->id, ['role' => 'owner']);

        // Act: user from another tenant tries to download
        $response = $this->actingAs($userB)
            ->withSession(['tenant_id' => $tenantB->id])
            ->get(route('uploads.download', ['uploadId' => $uploadA->id]));

        // Assert: scoped lookup returns 404 for other tenants
        $response->assertStatus(404);
    }
}
