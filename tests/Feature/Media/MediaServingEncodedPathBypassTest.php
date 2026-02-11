<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantTestHelpers;
use Tests\TestCase;

final class MediaServingEncodedPathBypassTest extends TestCase
{
    use RefreshDatabase;
    use TenantTestHelpers;

    public function test_double_encoded_traversal_segment_is_rejected_even_if_file_exists(): void
    {
        [$user, $tenant] = $this->makeTenantUser('Tenant Encoded 1');

        Storage::fake('local');
        config()->set('image-pipeline.avatar_disk', 'local');
        config()->set('filesystems.default', 'local');

        $literalPath = sprintf(
            'tenants/%s/users/%s/avatars/%%2e%%2e%%2fsecret.txt',
            $tenant->getKey(),
            $user->getKey()
        );
        Storage::disk('local')->put($literalPath, 'secret');

        $this->actingAs($user)
            ->get('/media/' . str_replace('%', '%25', $literalPath))
            ->assertStatus(404);
    }

    public function test_encoded_backslash_in_segment_is_rejected_even_if_file_exists(): void
    {
        [$user, $tenant] = $this->makeTenantUser('Tenant Encoded 2');

        Storage::fake('local');
        config()->set('image-pipeline.avatar_disk', 'local');
        config()->set('filesystems.default', 'local');

        $literalPath = sprintf(
            'tenants/%s/users/%s/avatars/%%5c%%5csecret.txt',
            $tenant->getKey(),
            $user->getKey()
        );
        Storage::disk('local')->put($literalPath, 'secret');

        $this->actingAs($user)
            ->get('/media/' . $literalPath)
            ->assertStatus(404);
    }

    public function test_encoded_forward_slash_inside_segment_is_rejected_even_if_file_exists(): void
    {
        [$user, $tenant] = $this->makeTenantUser('Tenant Encoded 3');

        Storage::fake('local');
        config()->set('image-pipeline.avatar_disk', 'local');
        config()->set('filesystems.default', 'local');

        $literalPath = sprintf(
            'tenants/%s/users/%s/avatars/safe%%2fsecret.txt',
            $tenant->getKey(),
            $user->getKey()
        );
        Storage::disk('local')->put($literalPath, 'secret');

        $this->actingAs($user)
            ->get('/media/' . $literalPath)
            ->assertStatus(404);
    }
}
