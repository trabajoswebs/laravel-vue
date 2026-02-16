<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Scanning;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Adaptador de cache de Laravel para el circuito de escaneo.
 */
final class LaravelCacheScanCircuitStore implements ScanCircuitStoreInterface
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($key, $default);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->cache->put($key, $value, $ttlSeconds);
    }

    public function increment(string $key, int $by = 1, int $ttlSeconds = 0): int
    {
        $value = (int) $this->cache->increment($key, $by);
        if ($ttlSeconds > 0) {
            $this->cache->put($key, $value, $ttlSeconds);
        }

        return $value;
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }
}
