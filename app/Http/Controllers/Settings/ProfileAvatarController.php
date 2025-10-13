<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Profile\DeleteAvatar;                 // Ej. clase invocable para eliminar el avatar
use App\Actions\Profile\UpdateAvatar;                 // Ej. clase invocable para actualizar el avatar
use App\Http\Controllers\Controller;                  // Ej. base Controller de Laravel
use App\Http\Requests\Settings\UpdateAvatarRequest;   // Ej. valida imagen (mimes, tamaño, dimensiones)
use App\Models\User;                                  // Ej. modelo User
use Illuminate\Auth\Access\AuthorizationException;    // Ej. excepciones de autorización
use Illuminate\Http\JsonResponse;                     // Ej. respuesta JSON
use Illuminate\Http\RedirectResponse;                 // Ej. respuesta redirect
use Illuminate\Http\Request;                          // Ej. request base
use Throwable;                                        // Ej. captura de errores

/**
 * Controlador de gestión del avatar en el área de perfil.
 *
 * Orquesta la actualización y eliminación del avatar delegando la lógica
 * de negocio a las Actions invocables `UpdateAvatar` y `DeleteAvatar`.
 *
 * Reglas clave:
 * - Autoriza mediante Policy: `UserPolicy@updateAvatar`.
 * - Responde con redirect+flash o JSON según el tipo de petición.
 * - No acepta un "target user" desde el cliente: actúa SIEMPRE sobre el usuario autenticado.
 */
class ProfileAvatarController extends Controller
{
    /**
     * Actualiza el avatar del usuario autenticado.
     *
     * Flujo:
     * 1) Autoriza contra `updateAvatar($authUser, $authUser)`.
     * 2) Valida la imagen vía `UpdateAvatarRequest`.
     * 3) Invoca la Action `UpdateAvatar` (transacción + lock + hash/version + evento).
     * 4) Devuelve redirect con flash o JSON (para Inertia/AJAX).
     *
     * @param  UpdateAvatarRequest  $request  Request validado (imagen obligatoria y saneada).
     * @param  UpdateAvatar         $action   Action invocable que realiza la operación.
     * @return RedirectResponse|JsonResponse
     *
     * @throws AuthorizationException Si falla la autorización de la Policy.
     */
    public function update(UpdateAvatarRequest $request, UpdateAvatar $action): RedirectResponse|JsonResponse
    {
        // 1) Usuario autenticado (siempre el propio; no aceptar "user_id" del cliente)
        /** @var User $authUser */
        $authUser = $request->user(); // Ej. instancia de User autenticado

        // 2) Autorización explícita: `update` sobre sí mismo
        $this->authorize('updateAvatar', $authUser); // Ej. true si es dueño o super-admin

        try {
            // 3) Ejecuta la Action invocable (__invoke) con User + UploadedFile
            $media = $action($authUser, $request->file('avatar')); // Ej. retorna instancia Media

            // 4) Mensaje de éxito + datos útiles para el front (version/hash para cache-busting)
            $payload = [
                'message'        => __('settings.profile.avatar.updated'),
                'media_id'       => $media->id,                              // Ej. 123
                'version'        => $media->getCustomProperty('version'),    // Ej. sha1 hash
                'avatar_version' => $authUser->avatar_version ?? null,       // Ej. redundante si guardas versión en users
            ];

            // 5) Si la petición espera JSON (XHR/Fetch/Inertia), responde JSON
            if ($request->wantsJson()) {
                return response()->json($payload, 200);
            }

            // 6) Si no, redirige atrás con flash
            return back()->with('success', $payload['message']);
        } catch (Throwable $e) {
            // Manejo de error uniforme
            $errorMessage = __('settings.profile.avatar.update_failed');
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errorMessage,
                    'error'   => config('app.debug') ? $e->getMessage() : null,
                ], 422);
            }
            return back()->withErrors(['avatar' => $errorMessage]);
        }
    }

    /**
     * Elimina el avatar del usuario autenticado (idempotente).
     *
     * Flujo:
     * 1) Autoriza contra `updateAvatar($authUser, $authUser)`.
     * 2) Invoca `DeleteAvatar` (transacción + lock + borrado S3/DB + evento).
     * 3) Devuelve redirect con flash o JSON, indicando si había o no avatar.
     *
     * @param  Request       $request  Request simple (no requiere body).
     * @param  DeleteAvatar  $action   Action invocable que realiza la eliminación.
     * @return RedirectResponse|JsonResponse
     *
     * @throws AuthorizationException Si falla la autorización de la Policy.
     */
    public function destroy(Request $request, DeleteAvatar $action): RedirectResponse|JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user(); // Ej. usuario autenticado

        // Autoriza eliminación del propio avatar
        $this->authorize('deleteAvatar', $authUser); // Ej. true si es dueño o super-admin

        try {
            // Ejecuta la Action invocable (__invoke). Retorna true si había avatar y se eliminó.
            $deleted = $action($authUser); // Ej. bool

            $message = $deleted
                ? __('settings.profile.avatar.deleted')
                : __('settings.profile.avatar.nothing_to_delete');

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'deleted' => $deleted, // Ej. true/false
                ], 200);
            }

            return back()->with('success', $message);
        } catch (Throwable $e) {
            $errorMessage = __('settings.profile.avatar.delete_failed');
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errorMessage,
                    'error'   => config('app.debug') ? $e->getMessage() : null,
                ], 422);
            }
            return back()->withErrors(['avatar' => $errorMessage]);
        }
    }
}
