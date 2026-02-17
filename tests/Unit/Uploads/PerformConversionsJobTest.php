<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads;

use App\Models\User;
use App\Models\Tenant;
use App\Modules\Uploads\Pipeline\Jobs\PerformConversionsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

final class PerformConversionsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sets_tenant_id_from_media(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'owner_user_id' => $user->getKey(),
        ]);

        $media = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getKey(),
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'collection_name' => 'avatar',
            'name' => 'avatar',
            'file_name' => 'avatar.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['tenant_id' => $tenant->getKey()],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);

        $job = new PerformConversionsJob(new ConversionCollection(), $media, false);

        $this->assertSame($tenant->getKey(), $job->tenantId);
    }

    public function test_handle_restores_previous_tenant_context_after_execution(): void
    {
        $user = User::factory()->create();

        $tenantA = Tenant::query()->create([
            'name' => 'Tenant A',
            'owner_user_id' => $user->getKey(),
        ]);
        $tenantB = Tenant::query()->create([
            'name' => 'Tenant B',
            'owner_user_id' => $user->getKey(),
        ]);

        $media = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getKey(),
            'uuid' => '77777777-7777-7777-7777-777777777777',
            'collection_name' => 'avatar',
            'name' => 'avatar',
            'file_name' => 'avatar.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['tenant_id' => $tenantB->getKey()],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);

        $tenantA->makeCurrent();
        $job = new PerformConversionsJob(new ConversionCollection(), $media, false);
        $fileManipulator = $this->createMock(\Spatie\MediaLibrary\Conversions\FileManipulator::class);

        $job->handle($fileManipulator);

        $this->assertSame($tenantA->getKey(), tenant()?->getKey());
    }
}
