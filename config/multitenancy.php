<?php

use App\Models\Tenant; // Modelo tenant propio; ej. Tenant::find(1)
use App\Modules\Tenancy\TenantFinder\AuthUserTenantFinder; // Finder basado en usuario autenticado; ej. user->current_tenant_id
use Spatie\Multitenancy\Actions\ForgetCurrentTenantAction; // Limpia tenant actual; ej. después de job
use Spatie\Multitenancy\Actions\MakeQueueTenantAwareAction; // Añade tenantId al payload de jobs; ej. tenantId=3
use Spatie\Multitenancy\Actions\MakeTenantCurrentAction; // Fija tenant actual; ej. antes de ejecutar job
use Spatie\Multitenancy\Actions\MigrateTenantAction; // Migra tenant; ej. tenants:migrate

return [
    // Modelo que representa un tenant; debe extender Spatie\Multitenancy\Models\Tenant o implementar IsTenant.
    'tenant_model' => Tenant::class, // Ej.: App\Models\Tenant

    // Columna que identifica al tenant (PK).
    'id_column_name' => 'id', // Ej.: id autoincremental

    // Columna dominio (no usada en single-database, pero se declara).
    'domain_column_name' => 'domain', // Ej.: null en este proyecto

    // TenantFinder responsable de fijar el tenant actual en cada request.
    'tenant_finder' => AuthUserTenantFinder::class, // Ej.: resuelve por user->current_tenant_id

    // Campos válidos para tenant:artisan.
    'tenant_artisan_search_fields' => ['id', 'name'], // Ej.: tenants:artisan 1

    // Tasks al hacer makeCurrent() (vacío porque es single-db sin cambio de conexión).
    'switch_tenant_tasks' => [
        // \Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask::class, // Ej.: si hubiera conexiones separadas
    ], // Ej.: []

    // Parámetros para tasks anteriores (no usados aquí).
    'switch_tenant_tasks_params' => [
    ], // Ej.: []

    // Cola para tasks de switch (null = cola por defecto).
    'switch_tenant_tasks_queue' => null, // Ej.: null

    // Namespace para rutas tenant (no usado).
    'tenant_route_namespace' => null, // Ej.: null

    // Acciones que se aplican a jobs encolados.
    'queued_actions' => [
        MakeQueueTenantAwareAction::class, // Inyecta tenantId en payload de jobs; ej.: payload['tenantId']=3
    ], // Ej.: [MakeQueueTenantAwareAction::class]

    // Todas las colas son tenant-aware por defecto.
    'queues_are_tenant_aware_by_default' => true, // Ej.: añade tenantId salvo exclusión

    // Jobs tenant-aware explícitos (aunque no implementen la interfaz TenantAware).
    'tenant_aware_jobs' => [
        \App\Modules\Uploads\Pipeline\Jobs\PostProcessAvatarMedia::class, // Ej.: media=5 tenant=3
        \App\Modules\Uploads\Pipeline\Jobs\PerformConversionsJob::class, // Ej.: conversions bajo tenant=3
    ], // Ej.: [...]

    // Jobs marcados como no tenant-aware (vacío aquí).
    'not_tenant_aware_jobs' => [
    ], // Ej.: []

    // Acciones core del paquete (se pueden sobreescribir con clases propias).
    'actions' => [
        'make_tenant_current_action' => MakeTenantCurrentAction::class, // Ej.: fija tenant antes de job
        'forget_current_tenant_action' => ForgetCurrentTenantAction::class, // Ej.: limpia tenant tras job
        'make_queue_tenant_aware_action' => MakeQueueTenantAwareAction::class, // Ej.: adjunta tenantId a payload
        'migrate_tenant' => MigrateTenantAction::class, // Ej.: tenants:migrate
    ], // Ej.: acciones oficiales

    // Clave usada para el tenant en contexto (payload de jobs).
    'current_tenant_context_key' => 'tenantId', // Ej.: payload['tenantId']=3

    // Clave usada para enlazar tenant en el contenedor.
    'current_tenant_container_key' => 'currentTenant', // Ej.: app('currentTenant')

    // Clave personalizada para middlewares propios (no es parte del paquete, pero la usamos en ResolveTenant).
    'current_tenant_id_key' => 'current_tenant_id', // Ej.: users.current_tenant_id=3

    // Clave de URL (no usada en este proyecto).
    'current_tenant_url_key' => 'tenant', // Ej.: /?tenant=3

    // Scope global opcional (no aplicado aquí).
    'global_scope' => 'tenant_id', // Ej.: tenant_id

    // Modelos que auto-setean tenant_id (vacío).
    'add_current_tenant_id_to_models' => [
    ], // Ej.: []

    // Modelos con faceta tenant en Scout (no usado).
    'add_tenant_facet_to_models' => [
    ], // Ej.: []

    // Conexiones de base de datos (single-db => mismas conexiones).
    'tenant_database_connection_name' => env('DB_CONNECTION', 'mysql'), // Ej.: mysql
    'landlord_database_connection_name' => env('DB_CONNECTION', 'mysql'), // Ej.: mysql
    'queue_database_connection_name' => env('DB_CONNECTION', 'mysql'), // Ej.: mysql
];
