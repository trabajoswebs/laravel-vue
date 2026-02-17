<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Scanning;

use App\Support\Security\Exceptions\AntivirusException;
use App\Infrastructure\Uploads\Pipeline\Scanning\ScanCoordinator;
use ReflectionClass;
use Tests\TestCase;

final class ScanCoordinatorClassificationTest extends TestCase
{
    public function test_timeout_reason_is_retryable_infra_timeout(): void
    {
        $coordinator = $this->coordinatorWithoutConstructor();

        $classification = $this->invokePrivate(
            $coordinator,
            'classifyAntivirusFailure',
            [new AntivirusException('clamav', 'timeout')]
        );

        $this->assertSame(['error_type' => 'infra_timeout', 'retryable' => true], $classification);
    }

    public function test_binary_missing_reason_is_non_retryable_infra_config(): void
    {
        $coordinator = $this->coordinatorWithoutConstructor();

        $classification = $this->invokePrivate(
            $coordinator,
            'classifyAntivirusFailure',
            [new AntivirusException('clamav', 'binary_missing')]
        );

        $this->assertSame(['error_type' => 'infra_config', 'retryable' => false], $classification);
    }

    private function coordinatorWithoutConstructor(): ScanCoordinator
    {
        $reflection = new ReflectionClass(ScanCoordinator::class);

        /** @var ScanCoordinator $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        return $instance;
    }

    /**
     * @param array<int,mixed> $arguments
     */
    private function invokePrivate(object $object, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}

