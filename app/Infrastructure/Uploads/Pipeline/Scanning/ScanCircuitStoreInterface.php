<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Scanning;

/**
 * Puerto para persistir el estado del circuito de escaneo.
 *
 * Permite implementar stores en memoria (tests) o cache distribuida
 * sin acoplar ScanCoordinator a un backend concreto.
 */
interface ScanCircuitStoreInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, int $ttlSeconds): void;

    /**
     * Incrementa y devuelve el contador.
     *
     * @param int $by Paso de incremento (positivo).
     */
    public function increment(string $key, int $by = 1, int $ttlSeconds = 0): int;

    public function forget(string $key): void;
}
