<?php // Comando para crear tenants personales en usuarios existentes

declare(strict_types=1); // Activa tipado estricto

namespace App\Console\Commands; // Namespace de comandos de infraestructura

use App\Models\User; // Modelo de usuario
use App\Models\Tenant; // Modelo de tenant
use Illuminate\Console\Command; // Clase base Command
use Illuminate\Support\Facades\DB; // Facade para transacciones/lock

/**
 * Comando idempotente para bootstrap de tenants personales.
 */
class TenancyBootstrapExistingUsers extends Command // Define el comando tenancy:bootstrap-existing-users
{
    /**
     * Nombre y firma del comando de consola.
     *
     * @var string
     */
    protected $signature = 'tenancy:bootstrap-existing-users {--chunk=100 : Tamaño de chunk para procesar usuarios}'; // Permite tunear chunk

    /**
     * Descripción del comando de consola.
     *
     * @var string
     */
    protected $description = 'Crea tenants personales para usuarios sin current_tenant_id de forma idempotente'; // Explica propósito del comando

    /**
     * Ejecuta el comando.
     */
    public function handle(): int // Devuelve código de salida
    {
        $chunkSize = max(10, (int) $this->option('chunk')); // Define chunk mínimo para evitar valores ínfimos
        $created = 0; // Contador de tenants creados
        $attached = 0; // Contador de usuarios actualizados

        User::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($users) use (&$created, &$attached): void { // Procesa en chunks para evitar consumo de memoria
                foreach ($users as $user) { // Itera usuarios en chunk
                    DB::transaction(function () use ($user, &$created, &$attached): void { // Bloquea por usuario para consistencia
                        $fresh = User::query()->lockForUpdate()->find($user->getKey()); // Bloquea el registro actual del usuario

                        if (! $fresh || $fresh->getCurrentTenantId() !== null) { // Si no existe o ya tiene tenant asignado
                            return; // No hace nada
                        }

                        $tenant = Tenant::query()->where('owner_user_id', $fresh->getKey())->first(); // Busca tenant personal existente

                        if (! $tenant) { // Si no hay tenant personal
                            $tenant = Tenant::query()->create([ // Crea tenant nuevo
                                'name' => "{$fresh->name}'s tenant", // Nombre legible basado en usuario
                                'owner_user_id' => $fresh->getKey(), // Propietario es el usuario
                            ]);
                            $created++; // Incrementa contador de creación
                        }

                        $fresh->tenants()->syncWithoutDetaching([$tenant->getKey() => ['role' => 'owner']]); // Asegura pivot role=owner
                        $fresh->forceFill(['current_tenant_id' => $tenant->getKey()])->saveQuietly(); // Marca tenant actual sin disparar eventos
                        $attached++; // Incrementa contador de usuarios actualizados
                    });
                }
            });

        $this->info("Tenants creados: {$created}"); // Reporta tenants nuevos
        $this->info("Usuarios actualizados: {$attached}"); // Reporta usuarios con tenant asignado

        return Command::SUCCESS; // Finaliza con éxito
    }
}
