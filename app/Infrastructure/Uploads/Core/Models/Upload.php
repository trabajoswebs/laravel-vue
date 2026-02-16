<?php // Modelo Eloquent para uploads no imagen

declare(strict_types=1); // Tipado estricto

namespace App\Infrastructure\Uploads\Core\Models; // Namespace del modelo Upload

use Illuminate\Database\Eloquent\Builder; // Builder para scopes
use Illuminate\Database\Eloquent\Factories\HasFactory; // Trait de factories
use Illuminate\Database\Eloquent\Model; // Modelo base Eloquent
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Relación belongsTo

/**
 * Representa un upload persistido fuera de Media Library.
 */
class Upload extends Model // Modelo Upload para documentos
{
    use HasFactory; // Habilita factories

    /**
     * La tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'uploads'; // Nombre de la tabla

    /**
     * Indica que la clave primaria no es incremental.
     *
     * @var bool
     */
    public $incrementing = false; // Usa UUID en lugar de incremento

    /**
     * Tipo de la clave primaria.
     *
     * @var string
     */
    protected $keyType = 'string'; // UUID como string

    /**
     * Atributos asignables masivamente.
     *
     * @var array<int, string>
     */
    protected $fillable = [ // Campos que se pueden asignar
        'id', // UUID del upload
        'tenant_id', // Tenant propietario
        'owner_type', // Tipo de owner (morph)
        'owner_id', // ID de owner
        'profile_id', // Perfil de upload usado
        'disk', // Disco de almacenamiento
        'path', // Path relativo
        'mime', // MIME real
        'size', // Tamaño en bytes
        'checksum', // Checksum opcional
        'original_name', // Nombre original opcional
        'visibility', // Visibilidad (private)
        'created_by_user_id', // Usuario que subió
    ];

    /**
     * Scope para filtrar por tenant.
     *
     * @param Builder $query Query base
     * @param int|string $tenantId ID del tenant
     * @return Builder Query filtrada
     */
    public function scopeForTenant(Builder $query, int|string $tenantId): Builder // Aplica filtro por tenant_id
    {
        return $query->where('tenant_id', $tenantId); // Devuelve query filtrada
    }

    /**
     * Relación con el usuario creador.
     *
     * @return BelongsTo
     */
    public function creator(): BelongsTo // Usuario que subió el archivo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_user_id'); // FK hacia users
    }
}
