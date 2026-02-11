<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Infrastructure\Uploads\Pipeline\Health\UploadPipelineHealthCheck;
use App\Infrastructure\Uploads\Pipeline\Scanning\YaraRuleManager;
use App\Infrastructure\Uploads\Profiles\AvatarProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantTestHelpers;
use Tests\TestCase;

final class UploadPipelineHealthEndpointTest extends TestCase
{
    use RefreshDatabase;
    use TenantTestHelpers;

    public function test_upload_pipeline_endpoint_returns_ok_when_all_checks_pass(): void
    {
        [$user, $tenant] = $this->makeTenantUser('Health Tenant');

        Storage::fake('quarantine');
        Storage::fake('public');
        config()->set('media.quarantine.disk', 'quarantine');
        config()->set('image-pipeline.scan.clamav.binary', '/bin/true');

        $yara = new class implements YaraRuleManager {
            public function getRuleFiles(): array { return []; }
            public function getCurrentVersion(): string { return 'test-version'; }
            public function validateIntegrity(): void {}
            public function getExpectedHash(): string { return 'hash'; }
        };

        $healthCheck = new UploadPipelineHealthCheck(
            filesystems: $this->app->make('filesystem'),
            queues: $this->app->make('queue'),
            avatarProfile: $this->app->make(AvatarProfile::class),
            yaraRules: $yara,
        );

        $this->app->instance(UploadPipelineHealthCheck::class, $healthCheck);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->getKey()])
            ->getJson(route('health.upload-pipeline'))
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'checks' => [
                    'quarantine' => ['ok' => true],
                    'clamav' => ['ok' => true],
                    'yara' => ['ok' => true],
                    'storage' => ['ok' => true],
                    'queue' => ['ok' => true],
                ],
            ]);
    }
}
