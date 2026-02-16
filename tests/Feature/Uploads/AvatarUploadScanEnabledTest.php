<?php

declare(strict_types=1);

namespace Tests\Feature\Uploads;

use App\Application\Uploads\Actions\UploadFile;
use App\Domain\Uploads\UploadProfileId;
use App\Models\User;
use App\Models\Tenant;
use App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry;
use App\Infrastructure\Uploads\Http\Requests\HttpUploadedMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AvatarUploadScanEnabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('quarantine');
        $scannerBin = storage_path('framework/testing/bin/mock-clam-scan.sh');
        $scannerDir = dirname($scannerBin);
        if (!is_dir($scannerDir)) {
            mkdir($scannerDir, 0755, true);
        }
        file_put_contents($scannerBin, "#!/usr/bin/env sh\nexit 0\n");
        chmod($scannerBin, 0755);

        config([
            'uploads.virus_scanning.enabled' => true,
            'uploads.private_disk' => 'public',
            'image-pipeline.scan.enabled' => true,
            'image-pipeline.scan.strict' => false,
            'image-pipeline.scan.handlers' => [\App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\ClamAvScanner::class],
            // en test aceptamos cualquier base para evitar falsos positivos con discos fake.
            'image-pipeline.scan.allowed_base_path' => '/',
            // binario mock determinista que siempre devuelve 0.
            'image-pipeline.scan.bin_allowlist' => [$scannerBin],
            'image-pipeline.scan.clamav.binary' => $scannerBin,
            'image-pipeline.scan.clamav.arguments' => '',
            'image-pipeline.scan.yara.binary' => '/bin/true',
            'image-pipeline.scan.yara.rules_path' => base_path('security/yara/images.yar'),
        ]);

        // crea un archivo de reglas mínimo para YARA cuando no exista
        if (!is_dir(base_path('security/yara'))) {
            mkdir(base_path('security/yara'), 0755, true);
        }
        if (!is_file(base_path('security/yara/images.yar'))) {
            file_put_contents(base_path('security/yara/images.yar'), "rule allow_all { condition: true }");
        }
    }

    public function test_avatar_upload_passes_with_scan_enabled(): void
    {
        [$user, $tenant] = $this->makeTenantUser();
        $this->actingAs($user);
        $tenant->makeCurrent();

        /** @var UploadProfileRegistry $profiles */
        $profiles = $this->app->make(UploadProfileRegistry::class);
        $profile = $profiles->get(new UploadProfileId('avatar_image'));

        /** @var UploadFile $upload */
        $upload = $this->app->make(UploadFile::class);

        $file = UploadedFile::fake()->image('avatar.avif', 256, 256)->size(1024);

        $result = $upload(
            $profile,
            $user,
            new HttpUploadedMedia($file),
            $user->id,
            'cid-avatar-scan',
            [],
        );

        $this->assertSame('avatar_image', $result->profileId);
        $this->assertSame('stored', $result->status);
    }

    /**
     * @return array{User,Tenant}
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
}
