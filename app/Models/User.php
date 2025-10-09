<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Media\ConversionProfiles\AvatarConversionProfile;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
class User extends Authenticatable implements HasMedia
{
    use HasFactory, Notifiable, InteractsWithMedia;

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
        ];
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
        $this->addMediaCollection('avatar')
            ->useDisk('s3')      // Usa el disco S3 para almacenamiento
            ->singleFile();      // Garantiza un solo archivo por colección
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
        // Aplica las conversiones definidas en el perfil AvatarConversionProfile
        AvatarConversionProfile::apply($this, $media);
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
                // Obtiene el primer archivo adjunto en la colección 'avatar'
                $media = $this->getFirstMedia('avatar');
                
                // Si no hay archivo adjunto, retorna null
                if (!$media) return null;

                // Verifica si la conversión 'large' ya ha sido generada
                // Si no, usa la URL del archivo original como fallback
                $url = $media->hasGeneratedConversion('large')
                    ? $media->getUrl('large')  // URL de la conversión 'large'
                    : $media->getUrl();        // URL del archivo original

                // Obtiene la versión del avatar para cache busting
                // Prioriza la propiedad personalizada del medio, sino usa el campo del modelo
                $version = $media->getCustomProperty('version') 
                           ?? $this->avatar_version;

                // Retorna la URL con el parámetro de versión si existe, sino la URL simple
                return $version ? "{$url}?v={$version}" : $url;
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
                // Obtiene el primer archivo adjunto en la colección 'avatar'
                $media = $this->getFirstMedia('avatar');
                
                // Si no hay archivo adjunto, retorna null
                if (!$media) return null;

                // Verifica si la conversión 'thumb' ya ha sido generada
                // Si no, usa la URL del archivo original como fallback
                $url = $media->hasGeneratedConversion('thumb')
                    ? $media->getUrl('thumb')  // URL de la conversión 'thumb'
                    : $media->getUrl();        // URL del archivo original

                // Obtiene la versión del avatar para cache busting
                // Prioriza la propiedad personalizada del medio, sino usa el campo del modelo
                $version = $media->getCustomProperty('version') 
                           ?? $this->avatar_version;

                // Retorna la URL con el parámetro de versión si existe, sino la URL simple
                return $version ? "{$url}?v={$version}" : $url;
            }
        );
    }
}