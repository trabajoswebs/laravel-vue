<?php // Service provider para registrar dependencias de tenancy

declare(strict_types=1); // Habilita tipado estricto

namespace App\Infrastructure\Tenancy\Providers; // Namespace del provider de tenancy

use App\Support\Contracts\TenantContextInterface; // Contrato de contexto de tenant
use App\Infrastructure\Tenancy\TenantContext; // Implementación de contexto de tenant
use Illuminate\Support\ServiceProvider; // Clase base de providers

/**
 * Registra bindings y configuración relacionados con multi-tenancy.
 */
class TenancyServiceProvider extends ServiceProvider // Provider de servicios de tenancy
{
    /**
     * Registra servicios en el contenedor.
     *
     * @return void
     */
    public function register(): void // Vincula interfaces con implementaciones
    {
        $this->app->singleton(TenantContextInterface::class, TenantContext::class); // Binding del contexto de tenant
    }
}
