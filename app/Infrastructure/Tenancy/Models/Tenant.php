<?php // Modelo Tenant para multi-tenant single-database

declare(strict_types=1); // Fuerza tipado estricto para consistencia

namespace App\Infrastructure\Tenancy\Models; // Namespace de infraestructura de tenancy

use App\Infrastructure\Models\User; // Importa el modelo de usuario
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Importa relación BelongsTo
use Illuminate\Database\Eloquent\Relations\BelongsToMany; // Importa relación BelongsToMany
use Illuminate\Database\Eloquent\Model; // Modelo base de Eloquent

/**
 * Modelo que representa un tenant en la base de datos única.
 */
class Tenant extends Model // Extiende modelo Eloquent estándar
{
    /**
     * Tenant actual en memoria (similar a Spatie\Multitenancy\Models\Tenant).
     */
    private static ?self $current = null;

    /**
     * Atributos protegidos contra asignación masiva.
     *
     * @var array<int, string>
     */
    protected $guarded = []; // Permite asignación masiva controlada por validación previa

    /**
     * Relación: usuarios pertenecientes al tenant.
     *
     * @return BelongsToMany Relación muchos a muchos
     */
    public function users(): BelongsToMany // Devuelve los usuarios miembros
    {
        return $this->belongsToMany(User::class)->withTimestamps(); // Usa tabla pivote tenant_user con timestamps
    }

    /**
     * Relación: usuario propietario del tenant.
     *
     * @return BelongsTo Relación con el dueño
     */
    public function owner(): BelongsTo // Devuelve el dueño del tenant
    {
        return $this->belongsTo(User::class, 'owner_user_id'); // FK owner_user_id en la tabla tenants
    }

    /**
     * Marca este tenant como el actual en contexto de aplicación.
     * Permite mantener compatibilidad con llamadas previas a makeCurrent().
     */
    public function makeCurrent(): void
    {
        self::$current = $this;
    }

    /**
     * Olvida el tenant actual (compatibilidad con ForgetCurrentTenantAction).
     */
    public static function forgetCurrent(): void
    {
        self::$current = null;
    }

    /**
     * Obtiene el tenant actual si fue marcado con makeCurrent().
     */
    public static function current(): ?self
    {
        return self::$current;
    }
}
