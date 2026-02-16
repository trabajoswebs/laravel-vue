<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Scanning;

use Psr\Log\LoggerInterface;

final class ScanCircuitBreaker
{
    public const DEFAULT_CACHE_KEY     = 'image_scan:circuit_failures';
    public const DEFAULT_MAX_FAILURES  = 5;
    public const DEFAULT_DECAY_SECONDS = 900;

    public function __construct(
        private readonly ScanCircuitStoreInterface $store,
        private readonly LoggerInterface $logger,
        private readonly string $cacheKey = self::DEFAULT_CACHE_KEY,
        private readonly int $maxFailures = self::DEFAULT_MAX_FAILURES,
        private readonly int $decaySeconds = self::DEFAULT_DECAY_SECONDS,
    ) {}

    public function isOpen(): bool
    {
        return (int) $this->store->get($this->cacheKey, 0) >= $this->maxFailures;
    }

    public function recordFailure(): void
    {
        $failures = (int) $this->store->increment($this->cacheKey, 1, $this->decaySeconds);
        $this->store->put($this->cacheKey, $failures, $this->decaySeconds);
    }

    public function reset(): void
    {
        $this->store->forget($this->cacheKey);
    }

    public function getMaxFailures(): int
    {
        return $this->maxFailures;
    }
}
