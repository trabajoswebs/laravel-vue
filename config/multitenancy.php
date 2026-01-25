<?php // Configuración mínima de multi-tenant single-database

declare(strict_types=1); // Habilita tipado estricto

use App\Infrastructure\Tenancy\Models\Tenant; // Modelo Tenant propio
use App\Infrastructure\Tenancy\TenantFinder\AuthUserTenantFinder; // TenantFinder basado en usuario autenticado

return [
    'tenant_model' => Tenant::class, // Modelo de tenant que usará el paquete
    'id_column_name' => 'id', // Columna PK usada para resolver tenants
    'tenant_finder' => AuthUserTenantFinder::class, // Finder que resuelve tenant desde el usuario autenticado
    'tenant_artisan_search_fields' => ['id', 'name'], // Campos permitidos para buscar tenant vía CLI
    'current_tenant_id_key' => 'tenant_id', // Clave de sesión usada para validar integridad del tenant
    'landlord_database_connection_name' => env('DB_CONNECTION', 'mysql'), // Conexión a usar para datos centrales
    'tenant_database_connection_name' => env('DB_CONNECTION', 'mysql'), // Conexión a usar para datos de tenant (misma BD)
    'queue_database_connection_name' => env('DB_CONNECTION', 'mysql'), // Conexión para trabajos de cola tenant-aware
    'switch_tenant_tasks' => [], // Sin tareas de cambio de conexión porque es single-database
    'switch_tenant_tasks_params' => [], // Sin parámetros adicionales para tareas de cambio
    // El resto de opciones del paquete Spatie se omiten porque no se usa el package completo
    'middleware' => [],
    'actions' => [],
    'add_current_tenant_id_to_models' => [],
];
