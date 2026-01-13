<?php

declare(strict_types=1);

namespace App\Application\Shared\Contracts;

/**
 * Abstracción mínima para métricas/observabilidad.
 */
interface MetricsInterface
{
    /**
     * Incrementa un contador.
     *
     * @param string $metric Nombre del contador
     * @param array<string,string|int|float|null> $tags Etiquetas opcionales
     * @param float $value Incremento aplicado (por defecto 1)
     */
    public function increment(string $metric, array $tags = [], float $value = 1.0): void;

    /**
     * Registra una medición de tiempo/duración en milisegundos.
     *
     * @param string $metric Nombre de la métrica
     * @param float $milliseconds Duración en ms
     * @param array<string,string|int|float|null> $tags Etiquetas opcionales
     */
    public function timing(string $metric, float $milliseconds, array $tags = []): void;
}
