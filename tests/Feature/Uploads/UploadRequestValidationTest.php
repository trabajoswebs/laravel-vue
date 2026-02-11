<?php

declare(strict_types=1);

namespace Tests\Feature\Uploads;

use App\Infrastructure\Models\User;
use App\Infrastructure\Tenancy\Models\Tenant;
use App\Infrastructure\Uploads\Core\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UploadRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('quarantine');
        config()->set('uploads.virus_scanning.enabled', false);
        config()->set('uploads.private_disk', 'public');
        config()->set('image-pipeline.scan.enabled', false);
    }

    public function test_store_rejects_unknown_profile_id(): void
    {
        [$user, $tenant] = $this->makeTenantUser();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->getKey()])
            ->postJson(route('uploads.store'), [
                'profile_id' => 'unknown_profile',
                'file' => UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\nA"),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_id']);
    }

    public function test_replace_rejects_profile_mismatch_for_existing_upload(): void
    {
        [$user, $tenant] = $this->makeTenantUser();

        $path = "tenants/{$tenant->getKey()}/documents/2026/01/file.pdf";
        Storage::disk('public')->put($path, '%PDF-1.4');

        $upload = Upload::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->getKey(),
            'profile_id' => 'document_pdf',
            'disk' => 'public',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 8,
            'checksum' => null,
            'original_name' => 'file.pdf',
            'visibility' => 'private',
            'created_by_user_id' => $user->getKey(),
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->getKey()])
            ->patchJson(route('uploads.update', ['uploadId' => $upload->getKey()]), [
                'profile_id' => 'import_csv',
                'file' => UploadedFile::fake()->createWithContent(
                    'dataset.csv',
                    "name,value\n" . str_repeat("alpha,1\n", 220)
                ),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_id']);
    }

    /**
     * @return array{0:User,1:Tenant}
     */
    private function makeTenantUser(): array
    {
        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = Tenant::query()->create([
            'name' => 'Upload Validation Tenant',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        return [$user, $tenant];
    }
}
