<?php // Contrato para obtener el tenant actual desde Application

declare(strict_types=1); // Habilita tipado estricto

namespace App\Support\Contracts; // Namespace de contratos compartidos

/**
 * Expone información del tenant actual a la capa de aplicación.
 */
interface TenantContextInterface // Define métodos para obtener tenant actual
{
    /**
     * Devuelve el ID del tenant actual o null si no existe.
     *
     * @return int|string|null Identificador del tenant activo
     */
    public function tenantId(): int|string|null; // Permite obtener tenant opcionalmente

    /**
     * Devuelve el ID del tenant y lanza excepción si no existe.
     *
     * @return int|string Identificador del tenant activo
     */
    public function requireTenantId(): int|string; // Obliga a tener un tenant resuelto
}
