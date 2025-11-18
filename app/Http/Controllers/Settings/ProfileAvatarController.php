<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Profile\DeleteAvatar;                 // Ej. clase invocable para eliminar el avatar
use App\Actions\Profile\UpdateAvatar;                 // Ej. clase invocable para actualizar el avatar
use App\Helpers\SecurityHelper;
use App\Http\Controllers\Controller;                  // Ej. base Controller de Laravel
use App\Http\Requests\Settings\UpdateAvatarRequest;   // Ej. valida imagen (mimes, tamaño, dimensiones)
use App\Models\User;                                  // Ej. modelo User
use Illuminate\Auth\Access\AuthorizationException;    // Ej. excepciones de autorización
use Illuminate\Http\JsonResponse;                     // Ej. respuesta JSON
use Illuminate\Http\RedirectResponse;                 // Ej. respuesta redirect
use Illuminate\Http\Request;                          // Ej. request base
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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
     * @param UpdateAvatarRequest $request Request validado (imagen obligatoria y saneada).
     * @param UpdateAvatar $action Action invocable que realiza la operación.
     * @return RedirectResponse|JsonResponse
     *
     * @throws AuthorizationException Si falla la autorización de la Policy.
     */
    public function update(UpdateAvatarRequest $request, UpdateAvatar $action): RedirectResponse|JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        Log::info('Avatar update request received', [
            'user_id' => $authUser?->getKey(),
            'has_file' => $request->hasFile('avatar'),
            'files' => $this->gatherUploadedFileInfo($request),
        ]);

        try {
            $media = $action($authUser, $request->file('avatar'));

            $payload = [
                'message' => __('settings.profile.avatar.updated'),
                'media_id' => $media->id,
                'version' => $media->getCustomProperty('version'),
                'avatar_version' => $authUser->avatar_version ?? null,
            ];

            if ($request->wantsJson()) {
                return response()->json($payload, 200);
            }

            return back()->with('success', $payload['message']);
        } catch (Throwable $e) {
            Log::error('Avatar update failed', [
                'user_id' => $authUser->getKey(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            $errorMessage = __('settings.profile.avatar.update_failed');

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errorMessage,
                    'error' => config('app.debug') ? $e->getMessage() : null,
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
     * @param Request $request Request simple (no requiere body).
     * @param DeleteAvatar $action Action invocable que realiza la eliminación.
     * @return RedirectResponse|JsonResponse
     *
     * @throws AuthorizationException Si falla la autorización de la Policy.
     */
    public function destroy(Request $request, DeleteAvatar $action): RedirectResponse|JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $this->authorize('deleteAvatar', $authUser);

        try {
            $deleted = $action($authUser);

            $message = $deleted
                ? __('settings.profile.avatar.deleted')
                : __('settings.profile.avatar.nothing_to_delete');

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'deleted' => $deleted,
                ], 200);
            }

            return back()->with('success', $message);
        } catch (Throwable $e) {
            Log::error('Avatar delete failed', [
                'user_id' => $authUser->getKey(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            $errorMessage = __('settings.profile.avatar.delete_failed');

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errorMessage,
                    'error' => config('app.debug') ? $e->getMessage() : null,
                ], 422);
            }

            return back()->withErrors(['avatar' => $errorMessage]);
        }
    }

    /**
     * @param Request $request
     * @return array<int, array<string, mixed>>
     */
    private function gatherUploadedFileInfo(Request $request): array
    {
        $files = [];

        foreach ($request->allFiles() as $field => $uploaded) {
            $entries = is_iterable($uploaded) ? $uploaded : [$uploaded];

            foreach ($entries as $entry) {
                if (!$entry instanceof UploadedFile) {
                    continue;
                }

                $files[] = [
                    'field' => $field,
                    'size' => $entry->getSize(),
                    'mime' => $entry->getClientMimeType(),
                    'extension' => $entry->getClientOriginalExtension(),
                    'summary' => SecurityHelper::sanitizeFilename($entry->getClientOriginalName()), // Ej: "../secret.png" -> "secret.png"
                ];
            }
        }

        return $files;
    }
}
