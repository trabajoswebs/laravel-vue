<?php // Middleware para resolver y fijar el tenant actual

declare(strict_types=1); // Habilita tipado estricto

namespace App\Infrastructure\Tenancy\Middleware; // Namespace del middleware de tenancy

use App\Infrastructure\Models\User; // Modelo de usuario usado en autenticación
use App\Infrastructure\Tenancy\Models\Tenant; // Modelo Tenant propio
use Closure; // Tipo Closure para la firma handle
use Illuminate\Http\Request; // Request HTTP
use Illuminate\Support\Facades\Auth; // Facade de autenticación
use Illuminate\Support\Facades\DB; // Facade para transacciones
use Symfony\Component\HttpFoundation\Response; // Tipo de respuesta HTTP

/**
 * Resuelve el tenant del usuario autenticado y lo marca como actual.
 */
class ResolveTenant // Middleware invocable por rutas tenant-aware
{
    /**
     * Maneja la solicitud resolviendo el tenant actual.
     *
     * @param Request $request Request entrante
     * @param Closure $next Siguiente middleware
     * @return Response Respuesta HTTP
     */
    public function handle(Request $request, Closure $next): Response // Firma estándar de middleware
    {
        $user = Auth::user(); // Obtiene el usuario autenticado actual

        if (!$user instanceof User) { // Si no hay usuario autenticado válido
            return abort(401, 'Autenticación requerida para resolver tenant'); // Bloquea acceso sin auth
        }

        // Requiere que el usuario tenga un tenant actual ya establecido; no se auto-provisiona en endpoints sensibles
        $tenant = $this->resolveExistingTenant($user); // Obtiene el tenant asociado

        if (! $tenant) { // Si no pudo resolverse un tenant
            return abort(403, 'No se pudo asociar un tenant'); // Bloquea acceso por inconsistencia / falta de membresía
        }

        $this->assertUserBelongsToTenant($user, $tenant); // Verifica pertenencia en tabla pivote

        $tenant->makeCurrent(); // Marca el tenant como actual en Spatie

        $sessionKey = config('multitenancy.current_tenant_id_key', 'tenant_id'); // Obtiene clave de sesión configurada
        $sessionTenantId = $request->session()->get($sessionKey); // Lee tenant almacenado en sesión

        if ($sessionTenantId !== null && (string) $sessionTenantId !== (string) $tenant->getKey()) { // Detecta sesiones alteradas
            return abort(403, 'Sesión con tenant inválido'); // Bloquea si la sesión apunta a otro tenant
        }

        $request->session()->put($sessionKey, $tenant->getKey()); // Guarda tenant_id en sesión para validación

        return $next($request); // Continúa el pipeline con tenant resuelto
    }

    /**
     * Asegura la pertenencia del usuario al tenant seleccionado.
     *
     * @param User $user Usuario autenticado
     * @param Tenant $tenant Tenant resuelto
     */
    private function assertUserBelongsToTenant(User $user, Tenant $tenant): void // Valida relación en tenant_user
    {
        $belongs = $tenant->users()->whereKey($user->getKey())->exists(); // Comprueba existencia en pivote

        if (! $belongs) { // Si no pertenece
            abort(403, 'El usuario no pertenece al tenant actual'); // Bloquea acceso
        }
    }

    /**
     * Resuelve el tenant actual o crea uno personal si falta.
     *
     * @param User $user Usuario autenticado
     * @return Tenant|null Tenant resuelto
     */
    private function resolveOrCreateTenantForUser(User $user): ?Tenant // Determina o crea el tenant del usuario
    {
        if ($user->getCurrentTenantId()) { // Si el usuario ya tiene tenant asignado
            return $this->resolveExistingTenant($user); // Valida y retorna el tenant existente
        }

        $existingTenant = $user->tenants()->first(); // Busca primer tenant asociado

        if ($existingTenant) { // Si ya existe asociación
            $this->refreshUserCurrentTenant($user, $existingTenant); // Actualiza current_tenant_id
            return $existingTenant; // Devuelve tenant encontrado
        }

        return $this->createPersonalTenant($user); // Crea tenant personal cuando no hay ninguno
    }

    /**
     * Resuelve un tenant existente validando pertenencia.
     *
     * @param User $user Usuario autenticado
     * @return Tenant|null Tenant válido o null si no corresponde
     */
    private function resolveExistingTenant(User $user): ?Tenant // Valida tenant_id actual y membresía
    {
        $tenant = Tenant::query()->find($user->getCurrentTenantId()); // Busca tenant por PK

        if (! $tenant) { // Si el tenant referenciado no existe
            return null; // Evita crear otro tenant automáticamente
        }

        $belongs = $tenant->users()->whereKey($user->getKey())->exists(); // Verifica pertenencia

        if (! $belongs) { // Si el usuario no pertenece al tenant referenciado
            return null; // Devuelve null para que el middleware bloquee con 403
        }

        return $tenant; // Devuelve tenant validado
    }

    /**
     * Crea un tenant personal y vincula al usuario como propietario.
     *
     * @param User $user Usuario autenticado
     * @return Tenant Tenant creado
     */
    private function createPersonalTenant(User $user): Tenant // Genera tenant personal del usuario
    {
        return DB::transaction(function () use ($user): Tenant { // Ejecuta creación en transacción
            $tenant = Tenant::query()->create([ // Crea registro del tenant
                'name' => "{$user->name}'s tenant", // Nombre legible basado en usuario
                'owner_user_id' => $user->getKey(), // Define dueño del tenant
            ]); // Fin de creación

            $user->tenants()->syncWithoutDetaching([$tenant->getKey() => ['role' => 'owner']]); // Asegura vínculo en pivote

            $this->refreshUserCurrentTenant($user, $tenant); // Marca tenant como actual en usuario

            return $tenant; // Devuelve tenant recién creado
        }); // Fin de la transacción
    }

    /**
     * Actualiza el campo current_tenant_id de forma atómica.
     *
     * @param User $user Usuario autenticado
     * @param Tenant $tenant Tenant que debe marcarse como actual
     */
    private function refreshUserCurrentTenant(User $user, Tenant $tenant): void // Guarda el tenant actual en el usuario
    {
        $user->forceFill(['current_tenant_id' => $tenant->getKey()])->saveQuietly(); // Persiste current_tenant_id sin eventos
    }
}
