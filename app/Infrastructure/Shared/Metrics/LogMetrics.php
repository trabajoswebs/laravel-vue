<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Metrics;

use App\Application\Shared\Contracts\MetricsInterface;
use Illuminate\Support\Facades\Log;

/**
 * Implementación mínima que emite métricas a logs estructurados.
 */
final class LogMetrics implements MetricsInterface
{
    /** @inheritDoc */
    public function increment(string $metric, array $tags = [], float $value = 1.0): void
    {
        Log::info('metrics.increment', [
            'metric' => $metric,
            'value' => $value,
            'tags' => $this->sanitizeTags($tags),
        ]);
    }

    /** @inheritDoc */
    public function timing(string $metric, float $milliseconds, array $tags = []): void
    {
        Log::info('metrics.timing', [
            'metric' => $metric,
            'value_ms' => round($milliseconds, 3),
            'tags' => $this->sanitizeTags($tags),
        ]);
    }

    /**
     * Normaliza etiquetas para mantener logs manejables.
     *
     * @param array<string,mixed> $tags
     * @return array<string,string|int|float|null>
     */
    private function sanitizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $normalized[$key] = $value;
                continue;
            }

            $normalized[$key] = is_object($value)
                ? get_debug_type($value)
                : json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $normalized;
    }
}
