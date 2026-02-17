<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Scanning;

use App\Modules\Uploads\Pipeline\Scanning\ScanCoordinator;
use App\Modules\Uploads\Pipeline\Exceptions\ScanFailedException;
use App\Modules\Uploads\Pipeline\Scanning\ScanCircuitStoreInterface;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\UploadedFile;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class ScanCoordinatorCircuitBreakerTest extends TestCase
{
    public function test_scan_failure_opens_circuit_and_blocks_subsequent_requests(): void
    {
        $store = new FakeCircuitStore();
        $config = new ConfigRepository([]);

        $coordinator = new ScanCoordinator(
            scannerRegistry: [],
            scanConfig: [
                'enabled' => true,
                // Ningún handler registrado -> fallará y marcará el breaker.
                'handlers' => [],
                'circuit_breaker' => [
                    'cache_key' => 'scan_circuit_test',
                    'max_failures' => 1,
                    'decay_seconds' => 60,
                ],
            ],
            circuitStore: $store,
            logger: new NullLogger(),
            config: $config,
        );

        $file = UploadedFile::fake()->create('sample.bin', 4);
        $path = $file->getRealPath();
        $this->assertIsString($path);

        // Primera llamada: sin handlers -> registra fallo y lanza excepción.
        $this->expectException(ScanFailedException::class);
        $coordinator->scan($file, $path, [
            'path' => $path,
            'correlation_id' => 'cid-test',
        ]);

        $this->assertSame(1, $store->get('scan_circuit_test'));

        // Circuit breaker queda abierto; assertAvailable ahora lanza.
        $this->expectException(ScanFailedException::class);
        $coordinator->assertAvailable();
    }
}

final class FakeCircuitStore implements ScanCircuitStoreInterface
{
    /** @var array<string,mixed> */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->data[$key] = $value;
    }

    public function increment(string $key, int $by = 1, int $ttlSeconds = 0): int
    {
        $this->data[$key] = ($this->data[$key] ?? 0) + $by;

        return (int) $this->data[$key];
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }
}
