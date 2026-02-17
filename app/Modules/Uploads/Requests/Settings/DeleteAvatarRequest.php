<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Clase de solicitud para validar la eliminación del avatar de un usuario.
 *
 * Esta solicitud se encarga de autorizar que el usuario autenticado pueda eliminar
 * el avatar del usuario especificado en la ruta. No requiere validación de datos
 * en el cuerpo de la solicitud, ya que la operación no necesita payload adicional.
 */
class DeleteAvatarRequest extends FormRequest
{
    /**
     * Determina si el usuario está autorizado para realizar esta solicitud.
     *
     * La autorización se concede únicamente si el usuario autenticado coincide
     * con el usuario cuyo avatar se desea eliminar (identificado en la ruta).
     *
     * @return bool true si el usuario está autorizado, false en caso contrario.
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user') ?? $actor;

        if (!($actor instanceof User) || !($target instanceof User)) {
            return false;
        }

        return $actor->can('deleteAvatar', $target);
    }

    /**
     * Obtiene las reglas de validación que se aplican a la solicitud.
     *
     * Dado que la eliminación del avatar no requiere datos en el cuerpo de la solicitud,
     * este método devuelve un array vacío.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     *     Array asociativo vacío, ya que no hay reglas de validación necesarias.
     */
    public function rules(): array
    {
        return [];
    }
}
