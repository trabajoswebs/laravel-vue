<?php

declare(strict_types=1);

namespace Tests\Feature\Uploads;

use App\Infrastructure\Models\User;
use App\Infrastructure\Tenancy\Models\Tenant;
use App\Application\Uploads\Actions\UploadFile;
use App\Domain\Uploads\UploadProfileId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry;
use App\Infrastructure\Uploads\Http\Requests\HttpUploadedMedia;
use Tests\TestCase;

final class GenericUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutExceptionHandling();
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

    /**
     * @return array{User, Tenant}
     */
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

    private function fakePdf(string $name = 'doc.pdf', int $bytes = 2048): UploadedFile
    {
        $payload = "%PDF-1.4\n" . str_repeat('A', max(0, $bytes - 9));

        return UploadedFile::fake()->createWithContent($name, $payload, 'application/pdf');
    }

    public function test_store_document_upload_succeeds(): void
    {
        [$user, $tenant] = $this->makeTenantUser();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => $this->fakePdf('doc.pdf', 2048),
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'profile_id', 'status', 'correlation_id']);
    }

    public function test_replace_document_upload_succeeds(): void
    {
        [$user, $tenant] = $this->makeTenantUser();

        $store = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => $this->fakePdf('doc.pdf', 2048),
            ])
            ->assertCreated()
            ->json('id');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->patch(route('uploads.update', ['uploadId' => $store]), [
                'profile_id' => 'document_pdf',
                'file' => $this->fakePdf('doc2.pdf', 3072),
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'profile_id', 'status', 'correlation_id']);
    }

    public function test_delete_document_upload_succeeds(): void
    {
        [$user, $tenant] = $this->makeTenantUser();

        $uploadId = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => $this->fakePdf('doc.pdf', 2048),
            ])
            ->assertCreated()
            ->json('id');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->delete(route('uploads.destroy', ['uploadId' => $uploadId]))
            ->assertNoContent();
    }

    /**
     * Assert that cross-tenant operations (store, update, delete) are forbidden for other tenants.
     *
     * @return void
     */
    public function test_cross_tenant_operations_are_forbidden(): void
    {
        [$owner, $tenant] = $this->makeTenantUser();
        /** @var User $foreign */
        $foreign = User::factory()->create(['current_tenant_id' => null]);
        $otherTenant = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Other',
            'owner_user_id' => $owner->id,
        ]);
        // Marca al foreign con un tenant que no le pertenece para asegurar 403
        $foreign->forceFill(['current_tenant_id' => $otherTenant->id])->save();

        $uploadId = $this->actingAs($owner)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => $this->fakePdf('doc.pdf', 2048),
            ])
            ->json('id');

        $this->actingAs($foreign)
            ->withHeader('Accept', 'application/json')
            ->withExceptionHandling()
            ->post(route('uploads.store'), [
                'profile_id' => 'document_pdf',
                'file' => $this->fakePdf('doc.pdf', 2048),
            ])
            ->assertStatus(403);

        $this->actingAs($foreign)
            ->withHeader('Accept', 'application/json')
            ->withExceptionHandling()
            ->patch(route('uploads.update', ['uploadId' => $uploadId]), [
                'profile_id' => 'document_pdf',
                'file' => $this->fakePdf('doc2.pdf', 3072),
            ])
            ->assertStatus(403);

        $this->actingAs($foreign)
            ->withHeader('Accept', 'application/json')
            ->withExceptionHandling()
            ->delete(route('uploads.destroy', ['uploadId' => $uploadId]))
            ->assertStatus(403);
    }

    public function test_secret_upload_download_is_forbidden(): void
    {
        [$user, $tenant] = $this->makeTenantUser();

        $storeResponse = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Accept', 'application/json')
            ->post(route('uploads.store'), [
                'profile_id' => 'certificate_secret',
                // Cabecera DER tipo PKCS#12 (0x30...) para pasar validación de firma mínima.
                'file' => UploadedFile::fake()->createWithContent('cert.p12', "\x30\x82\x04\x00" . random_bytes(2044))->mimeType('application/octet-stream'),
            ]);

        $storeResponse->assertCreated();
        $uploadId = $storeResponse->json('id');
        $this->assertIsString($uploadId);
        $this->assertNotSame('', $uploadId);

        Log::spy();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('uploads.download', ['uploadId' => $uploadId]))
            ->assertStatus(403);
    }

    public function test_upload_action_returns_application_dto(): void
    {
        [$user, $tenant] = $this->makeTenantUser();
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        /** @var UploadProfileRegistry $profiles */
        $profiles = $this->app->make(UploadProfileRegistry::class);
        $profile = $profiles->get(new UploadProfileId('document_pdf'));

        /** @var UploadFile $upload */
        $upload = $this->app->make(UploadFile::class);

        $result = $upload(
            $profile,
            $user,
            new HttpUploadedMedia($this->fakePdf('doc.pdf', 2048)),
            null,
            'cid-integration',
            [],
        );

        $this->assertInstanceOf(\App\Application\Uploads\DTO\UploadResult::class, $result);
        $this->assertSame('document_pdf', $result->profileId);
        $this->assertSame('cid-integration', $result->correlationId);
    }
}
