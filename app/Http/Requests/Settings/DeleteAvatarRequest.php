<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Auth\Authenticatable;
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
        $auth = $this->user();
        $routeUser = $this->route('user');

        // Si no hay usuario autenticado o el parámetro 'user' no está en la ruta, denegar acceso.
        if (!$auth || !$routeUser) {
            return false;
        }

        // Si el usuario de la ruta ya es una instancia de Authenticatable, comparar directamente.
        if ($routeUser instanceof Authenticatable) {
            return $auth->is($routeUser);
        }

        // Extraer el ID del usuario de la ruta (puede ser un modelo, un array o un entero).
        $routeUserId = (int) data_get($routeUser, 'id', $routeUser);
        // Comparar el ID del usuario autenticado con el ID del usuario de la ruta.
        return (int) $auth->getAuthIdentifier() === $routeUserId;
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