<?php // Modelo Tenant compatible con Spatie (single-database)

declare(strict_types=1); // Tipado estricto // Ej.: evita coerciones silenciosas

namespace App\Infrastructure\Tenancy\Models; // Namespace del modelo // Ej.: App\...\Tenant

use App\Infrastructure\Models\User; // Modelo User // Ej.: $tenant->users()
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Relación BelongsTo // Ej.: owner()
use Illuminate\Database\Eloquent\Relations\BelongsToMany; // Relación BelongsToMany // Ej.: users()
use Spatie\Multitenancy\Models\Tenant as SpatieTenant; // Base Spatie (trae makeCurrent/checkCurrent/etc.) // Ej.: $tenant->makeCurrent()

/**
 * Tenant single-db, extendiendo el Tenant de Spatie para no romper su API interna.
 */
final class Tenant extends SpatieTenant // Extiende SpatieTenant // Ej.: Tenant::checkCurrent()
{
    protected $guarded = []; // Sin fillables estrictos (ya validas antes) // Ej.: mass-assign controlado

    /**
     * Usuarios pertenecientes al tenant (pivot tenant_user).
     */
    public function users(): BelongsToMany // Relación M:N // Ej.: $tenant->users()->exists()
    {
        return $this->belongsToMany(User::class)->withTimestamps(); // Pivot con timestamps // Ej.: created_at/updated_at
    }

    /**
     * Usuario propietario del tenant.
     */
    public function owner(): BelongsTo // Relación 1:N inversa // Ej.: $tenant->owner->id
    {
        return $this->belongsTo(User::class, 'owner_user_id'); // FK owner_user_id // Ej.: owner_user_id = 2
    }
}
