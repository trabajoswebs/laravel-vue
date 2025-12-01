<?php

declare(strict_types=1);

namespace App\Application\Shared\Contracts;

interface TransactionManagerInterface
{
    /**
     * Ejecuta el callback dentro de una transacción.
     *
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    public function transactional(callable $callback);

    /**
     * Registra un callback para ejecutarse después del commit.
     *
     * @param callable(): void $callback
     */
    public function afterCommit(callable $callback): void;
}
