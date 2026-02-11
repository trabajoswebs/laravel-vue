<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Infrastructure\Models\User;
use App\Infrastructure\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

final class AvatarStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_status_reports_superseded(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'owner_user_id' => $user->getKey(),
        ]);

        $tenant->makeCurrent();
        $this->actingAs($user);
        $this->withoutMiddleware(); // Desactiva middleware (tenant/auth/csrf) para aislar la lógica del endpoint

        $mediaOld = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getKey(),
            'uuid' => Str::uuid()->toString(),
            'collection_name' => 'avatar',
            'name' => 'avatar-old',
            'file_name' => 'avatar-old.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['upload_uuid' => 'upload-old'],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 2,
        ]);

        $mediaNew = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getKey(),
            'uuid' => Str::uuid()->toString(),
            'collection_name' => 'avatar',
            'name' => 'avatar-new',
            'file_name' => 'avatar-new.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['upload_uuid' => 'upload-new'],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);

        $response = $this->getJson('/api/avatar/uploads/upload-old/status');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'superseded',
                'latest_media_id' => $mediaNew->getKey(),
            ]);
    }

    public function test_upload_status_reports_processing_when_latest_payload_matches_requested_uuid(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'owner_user_id' => $user->getKey(),
        ]);

        $tenant->makeCurrent();
        $this->actingAs($user);
        $this->withoutMiddleware();

        Redis::shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'upload_uuid' => 'upload-pending',
                'media_id' => '123',
                'updated_at' => now()->toIso8601String(),
            ]));

        $this->getJson('/api/avatar/uploads/upload-pending/status')
            ->assertOk()
            ->assertJsonPath('status', 'processing');
    }

    public function test_upload_status_reports_failed_when_no_media_and_no_last_payload(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'owner_user_id' => $user->getKey(),
        ]);

        $tenant->makeCurrent();
        $this->actingAs($user);
        $this->withoutMiddleware();

        Redis::shouldReceive('get')->once()->andReturn(null);

        $this->getJson('/api/avatar/uploads/upload-missing/status')
            ->assertOk()
            ->assertJsonPath('status', 'failed');
    }
}
