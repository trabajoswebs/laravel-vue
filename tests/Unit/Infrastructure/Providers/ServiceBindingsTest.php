<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers;

use App\Support\Contracts\ClockInterface;
use App\Support\Contracts\TenantContextInterface;
use App\Application\User\Contracts\UserRepository as UserRepositoryContract;
use App\Application\Uploads\Contracts\UploadRepositoryInterface;
use App\Infrastructure\Shared\Adapters\LaravelClock;
use App\Infrastructure\Tenancy\TenantContext;
use App\Infrastructure\User\Adapters\EloquentUserRepository;
use App\Infrastructure\Uploads\Core\Repositories\EloquentUploadRepository;
use Tests\TestCase;

final class ServiceBindingsTest extends TestCase
{
    public function test_core_contracts_resolve_expected_implementations(): void
    {
        $this->assertInstanceOf(LaravelClock::class, $this->app->make(ClockInterface::class));
        $this->assertInstanceOf(TenantContext::class, $this->app->make(TenantContextInterface::class));
        $this->assertInstanceOf(EloquentUserRepository::class, $this->app->make(UserRepositoryContract::class));
        $this->assertInstanceOf(EloquentUploadRepository::class, $this->app->make(UploadRepositoryInterface::class));
    }
}
