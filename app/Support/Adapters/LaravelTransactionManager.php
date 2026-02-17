<?php

declare(strict_types=1);

namespace App\Support\Adapters;

use App\Support\Contracts\TransactionManagerInterface;
use Illuminate\Support\Facades\DB;

final class LaravelTransactionManager implements TransactionManagerInterface
{
    /**
     * Ejecuta un callback dentro de una transacción de base de datos.
     *
     * @param callable $callback Función a ejecutar dentro de la transacción
     * @return mixed Resultado del callback
     */
    public function transactional(callable $callback)
    {
        return DB::transaction($callback);
    }

    /**
     * Registra un callback para ejecutarse después de que la transacción actual se confirme.
     *
     * @param callable $callback Función a ejecutar después del commit
     */
    public function afterCommit(callable $callback): void
    {
        DB::afterCommit($callback);
    }
}
