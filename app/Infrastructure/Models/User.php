<?php

declare(strict_types=1);

namespace App\Infrastructure\Models;

use App\Infrastructure\Uploads\Core\Contracts\MediaOwner; // Contrato que marca modelos propietarios de medios
use App\Infrastructure\Uploads\Core\Models\Concerns\TracksMediaVersions; // Trait que versiona media para cache busting
use App\Infrastructure\Uploads\Pipeline\Image\AvatarConversionProfile;
use App\Infrastructure\Uploads\Profiles\AvatarProfile;
use App\Infrastructure\Tenancy\Models\Tenant; // Modelo de tenant para relaciones
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Relación belongsTo para tenant actual
use Illuminate\Database\Eloquent\Relations\BelongsToMany; // Relación belongsToMany para miembros de tenant
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Modelo Eloquent que representa a un usuario del sistema.
 *
 * Este modelo extiende la clase base de autenticación de Laravel y está integrado
 * con Spatie Media Library para gestionar archivos adjuntos, específicamente
 * un avatar de usuario que se almacena en una colección 'avatar' única en S3.
 * Incluye métodos para registrar colecciones y conversiones de medios,
 * así como accessors modernos para obtener las URLs del avatar con cache busting.
 */
class User extends Authenticatable implements HasMedia, MediaOwner
{
    use HasFactory, Notifiable, InteractsWithMedia, TracksMediaVersions;

    /**
     * Atributos que se pueden asignar masivamente.
     *
     * Estos son los campos que están permitidos para asignación masiva
     * a través de métodos como create() o fill().
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',           // Nombre del usuario
        'email',          // Correo electrónico del usuario
        'current_tenant_id', // Tenant actual para sesión tenant-first
        'password',       // Contraseña (hash)
        'avatar_version', // Versión del avatar para cache busting
    ];

    /**
     * Atributos que deben ocultarse para arrays y JSON.
     *
     * Estos campos no se incluirán en las representaciones
     * de array o JSON del modelo, como cuando se convierte a JSON
     * mediante el método toArray() o jsonSerialize().
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',       // No exponer el hash de la contraseña
        'remember_token', // No exponer el token de 'recuérdame'
    ];

    /**
     * Atributos que deben convertirse a tipos nativos.
     *
     * Define cómo ciertos atributos deben ser casteado al acceder a ellos.
     * Por ejemplo, convertir cadenas a objetos DateTime.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'avatar_updated_at' => 'datetime',
            'current_tenant_id' => 'integer', // Garantiza que el tenant_id se trate como entero
        ];
    }

    /**
     * Relación: tenant actual asociado al usuario.
     *
     * @return BelongsTo Relación belongsTo hacia Tenant
     */
    public function currentTenant(): BelongsTo // Devuelve el tenant actual del usuario
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id'); // Usa la FK current_tenant_id
    }

    /**
     * Relación: tenants a los que pertenece el usuario.
     *
     * @return BelongsToMany Relación muchos a muchos
     */
    public function tenants(): BelongsToMany // Devuelve la colección de tenants del usuario
    {
        return $this->belongsToMany(Tenant::class)->withTimestamps(); // Usa la tabla pivote tenant_user
    }

    /**
     * Helper para obtener el tenant_id actual.
     *
     * @return int|null Retorna el id del tenant activo
     */
    public function getCurrentTenantId(): ?int // Devuelve el tenant_id activo o null
    {
        return $this->current_tenant_id; // Lee directamente la columna current_tenant_id
    }

    /**
     * Registra las colecciones de medios disponibles para este modelo.
     *
     * Define una colección 'avatar' que:
     * - Se almacena en el disco 's3' configurado en filesystems.php.
     * - Es de tipo 'singleFile', lo que significa que solo puede haber un archivo
     *   en esta colección, y cada nuevo archivo reemplaza al anterior.
     *
     * @return void
     */
    public function registerMediaCollections(): void
    {
        $avatarDisk = config('image-pipeline.avatar_disk', config('filesystems.default'));
        $conversionsDisk = config('media-library.conversions_disk', $avatarDisk);

        $this->addMediaCollection('avatar')
            ->useDisk($avatarDisk)                      // Disco para originales (p.ej., AVATAR_DISK)
            ->storeConversionsOnDisk($conversionsDisk)  // Disco para conversiones (p.ej., MEDIA_CONVERSIONS_DISK)
            ->singleFile();                             // Garantiza un solo archivo por colección
    }

    /**
     * Registra las conversiones de medios que se aplican al archivo adjunto.
     *
     * Este método se llama automáticamente por Spatie Media Library
     * cada vez que se adjunta un nuevo archivo a una colección registrada.
     * Aquí delegamos la lógica de conversión al AvatarConversionProfile,
     * que define tamaños (thumb, medium, large), formato (WebP) y calidad.
     *
     * @param Media|null $media Instancia del archivo adjunto. Puede ser null si no hay archivo adjunto.
     * @return void
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $profile = app(AvatarProfile::class);
        AvatarConversionProfile::apply($this, $media, $profile);
    }

    /**
     * Accessor para la URL del avatar principal (large o original).
     *
     * Este accessor utiliza la API moderna de Laravel 9+ para definir
     * atributos computados. Devuelve la URL del avatar en tamaño 'large'
     * si ya ha sido generado, de lo contrario devuelve la URL del archivo original.
     * Incluye un parámetro de versión (cache busting) si está disponible.
     *
     * @return Attribute<string|null> Un objeto Attribute que encapsula la lógica de acceso.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            // Función de acceso (getter)
            get: function (): ?string {
                $media = $this->getFirstMedia('avatar');

                if (!$media) {
                    return null;
                }

                return $this->signedAvatarRoute($media, 'large');
            }
        );
    }

    /**
     * Accessor para la URL del avatar en tamaño miniatura (thumb o original).
     *
     * Similar a avatarUrl, pero para la miniatura 'thumb'.
     * Devuelve la URL de la conversión 'thumb' si está disponible,
     * de lo contrario la URL original. Incluye cache busting.
     *
     * @return Attribute<string|null> Un objeto Attribute que encapsula la lógica de acceso.
     */
    protected function avatarThumbUrl(): Attribute
    {
        return Attribute::make(
            // Función de acceso (getter)
            get: function (): ?string {
                $media = $this->getFirstMedia('avatar');

                if (!$media) {
                    return null;
                }

                return $this->signedAvatarRoute($media, 'thumb');
            }
        );
    }

    /**
     * Intenta resolver la URL de una conversión asegurándose de que el archivo exista.
     *
     * @param  Media   $media        Instancia del medio asociado.
     * @param  string  $conversion   Nombre de la conversión (thumb, medium, large, etc.).
     * @return string|null           URL de la conversión o null si no existe.
     */
    protected function resolveConversionUrl(Media $media, string $conversion): ?string
    {
        if ($media->hasGeneratedConversion($conversion)) {
            return $media->getUrl($conversion);
        }

        try {
            $relativePath = $media->getPathRelativeToRoot($conversion);
        } catch (\Throwable $exception) {
            return null;
        }

        if ($relativePath === '') {
            return null;
        }

        $disk = $media->conversions_disk ?: $media->disk;

        if (Storage::disk($disk)->exists($relativePath)) {
            return $media->getUrl($conversion);
        }

        return null;
    }

    /**
     * Agrega el parámetro de versión a la URL del avatar si está disponible.
     *
     * @param  string  $url
     * @param  Media   $media
     * @return string
     */
    protected function appendAvatarVersion(string $url, Media $media): string
    {
        $version = $media->getCustomProperty('version') ?? $this->avatar_version;

        if (!$version) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return "{$url}{$separator}v={$version}";
    }

    /**
     * Genera URL firmada para el avatar (conversión u original) usando la ruta dedicada.
     */
    private function signedAvatarRoute(Media $media, string $conversion): ?string
    {
        $ttlMinutes = (int) config('media-library.temporary_url_default_lifetime', 15);
        $ttlMinutes = $ttlMinutes > 0 ? $ttlMinutes : 15;

        $version = $media->getCustomProperty('version') ?? $this->avatar_version;
        $params = [
            'media' => $media->id,
            'c' => $conversion,
        ];
        if ($version) {
            $params['v'] = $version;
        }

        try {
            return \URL::temporarySignedRoute(
                'media.avatar.show',
                now()->addMinutes($ttlMinutes),
                $params
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resuelve la factory del modelo, independientemente del namespace del modelo.
     */
    protected static function newFactory(): Factory
    {
        return \Database\Factories\UserFactory::new();
    }
}
