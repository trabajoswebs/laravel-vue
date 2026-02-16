<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Requests;

use App\Domain\Uploads\UploadKind;
use App\Domain\Uploads\UploadProfile;
use App\Domain\Uploads\UploadProfileId;
use App\Infrastructure\Http\Requests\Concerns\SanitizesInputs;
use App\Infrastructure\Models\User;
use App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry;
use App\Infrastructure\Uploads\Http\Requests\Concerns\UsesDocumentValidation;
use App\Infrastructure\Uploads\Http\Requests\Concerns\UsesImageValidation;
use App\Infrastructure\Uploads\Http\Requests\Concerns\UsesOwnerIdValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Clase de solicitud para reemplazar uploads existentes.
 * 
 * Esta clase maneja la validación y autorización de solicitudes para reemplazar archivos,
 * permitiendo diferentes tipos de validación según el perfil de upload especificado.
 * A diferencia de StoreUploadRequest, incluye campos adicionales como correlation_id y meta.
 * 
 * @package App\Infrastructure\Uploads\Http\Requests
 */
final class ReplaceUploadRequest extends FormRequest
{
    // Traits para funcionalidades adicionales
    use UsesImageValidation;
    use UsesDocumentValidation;
    use UsesOwnerIdValidation;
    use SanitizesInputs;

    /**
     * Perfil de upload cacheado para evitar múltiples búsquedas
     *
     * @var UploadProfile|null
     */
    private ?UploadProfile $profile = null;

    /**
     * Determina si el usuario está autorizado para realizar esta solicitud.
     * 
     * Verifica que el usuario autenticado sea una instancia válida de User.
     *
     * @return bool True si el usuario está autorizado, false en caso contrario
     */
    public function authorize(): bool
    {
        // Solo permite a usuarios autenticados (instancia de User) realizar esta acción
        return $this->user() instanceof User;
    }

    /**
     * Define las reglas de validación para los atributos de la solicitud.
     * 
     * Las reglas varían dinámicamente según el perfil de upload proporcionado.
     * Incluye campos adicionales como correlation_id y meta para soportar metadatos avanzados.
     * El profile_id es opcional pero debe existir si se proporciona.
     *
     * @return array<string, mixed> Array asociativo con las reglas de validación
     */
    public function rules(): array
    {
        // Regla personalizada para validar el profile_id (opcional pero debe existir si se proporciona)
        $profileRule = function (string $attribute, mixed $value, callable $fail): void {
            if (!is_string($value) || trim($value) === '') {
                return;
            }

            try {
                $this->profile();
            } catch (\Throwable) {
                // Falla la validación si el perfil no existe
                $fail(__('validation.exists', ['attribute' => $attribute]));
            }
        };

        // Obtiene el perfil de forma segura (sin lanzar excepciones)
        $profile = $this->safeProfile();

        // Retorna las reglas filtradas (elimina valores nulos)
        return array_filter([
            // ID del perfil es opcional pero debe ser una cadena válida si se proporciona
            'profile_id' => ['sometimes', 'string', $profileRule],
            // Reglas para el archivo dependen del tipo de perfil
            'file' => $profile ? $this->fileRulesForProfile($profile) : ['required', 'file'],
            // ID del propietario validado según modo configurado (int|uuid|ulid)
            'owner_id' => $this->ownerIdRules(),

            // Campo adicional para correlación de solicitudes
            'correlation_id' => ['nullable', 'string', 'max:64', 'alpha_dash'],
            // Campo opcional para metadatos estructurados
            'meta' => ['sometimes', 'array'],
            // Reglas específicas para la nota dentro de meta
            'meta.note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * Obtiene las reglas de validación específicas para un archivo según el tipo de perfil.
     * 
     * Dependiendo del tipo de upload (imagen o documento), aplica diferentes reglas de validación.
     *
     * @param UploadProfile $profile El perfil de upload para determinar las reglas
     * @return array<int,mixed> Array con las reglas de validación específicas
     */
    private function fileRulesForProfile(UploadProfile $profile): array
    {
        // Aplica reglas diferentes según el tipo de upload
        return match ($profile->kind) {
            // Si es imagen, usa reglas de imagen
            UploadKind::IMAGE => $this->imageRules('file'),
            // Para otros tipos, usa reglas de documento
            default => $this->documentRules('file', $profile),
        };
    }

    /**
     * Obtiene el perfil de upload basado en el ID proporcionado.
     * 
     * Realiza una búsqueda en el registro de perfiles y cachea el resultado.
     * Lanza una excepción de validación si el perfil no existe o no se proporciona.
     *
     * @return UploadProfile El perfil de upload encontrado
     * @throws ValidationException Si el perfil no existe o no se proporciona
     */
    public function profile(): UploadProfile
    {
        // Retorna el perfil cacheado si ya fue obtenido previamente
        if ($this->profile instanceof UploadProfile) {
            return $this->profile;
        }

        // Obtiene el valor del input profile_id
        $value = $this->input('profile_id');

        if (!is_string($value) || trim($value) === '') {
            // Lanza excepción si no se proporciona el profile_id cuando se intenta acceder
            throw ValidationException::withMessages([
                'profile_id' => ['profile_id es requerido para validar por perfil.'],
            ]);
        }

        try {
            // Busca el perfil en el registro usando el ID
            $this->profile = app(UploadProfileRegistry::class)->get(new UploadProfileId(trim($value)));
        } catch (\Throwable) {
            // Lanza excepción de validación si el perfil no se encuentra
            throw ValidationException::withMessages([
                'profile_id' => __('validation.exists', ['attribute' => 'profile_id']),
            ]);
        }

        return $this->profile;
    }

    /**
     * Obtiene el perfil de upload de forma segura (sin lanzar excepciones).
     * 
     * Utilizado para obtener el perfil sin interrumpir el flujo normal de validación.
     *
     * @return UploadProfile|null El perfil de upload o null si no existe o hay error
     */
    private function safeProfile(): ?UploadProfile
    {
        try {
            // Intenta obtener el perfil normalmente
            return $this->profile();
        } catch (\Throwable) {
            // Retorna null si ocurre cualquier error
            return null;
        }
    }

    /**
     * Obtiene el ID de correlación de la solicitud.
     * 
     * El ID de correlación se utiliza para rastrear solicitudes relacionadas.
     *
     * @return string|null El ID de correlación o null si no se proporcionó
     */
    public function correlationId(): ?string
    {
        // Obtiene el valor del campo correlation_id
        $value = $this->input('correlation_id');
        // Retorna el valor como string o null si está vacío
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Obtiene los metadatos estructurados de la solicitud.
     * 
     * Extrae y procesa los datos del campo meta, asegurando que sean arrays válidos
     * y filtrando los valores nulos.
     *
     * @return array<string, mixed> Array con los metadatos procesados
     */
    public function meta(): array
    {
        // Obtiene el campo meta con un valor por defecto de array vacío
        $meta = $this->input('meta', []);

        if (!is_array($meta)) {
            // Asegura que siempre devuelva un array
            return [];
        }

        // Filtra y procesa los metadatos disponibles
        return array_filter([
            // Procesa la nota dentro de meta, asegurando que sea string
            'note' => is_string($meta['note'] ?? null) ? (string) $meta['note'] : null,
        ], static fn($v) => $v !== null);
    }
}
