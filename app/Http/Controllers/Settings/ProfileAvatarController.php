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

        // Registra información sobre la solicitud de actualización de avatar
        Log::info('Avatar update request received', [
            'user_id' => $authUser?->getKey(),
            'has_file' => $request->hasFile('avatar'),
            'files' => $this->gatherUploadedFileInfo($request),
        ]);

        try {
            // Ejecuta la acción de actualización del avatar
            $media = $action($authUser, $request->file('avatar'));

            // Prepara la respuesta con información del avatar actualizado
            $payload = [
                'message' => __('settings.profile.avatar.updated'),
                'media_id' => $media->id,
                'version' => $media->getCustomProperty('version'),
                'avatar_version' => $authUser->avatar_version ?? null,
            ];

            // Genera retroalimentación para el cliente
            $feedback = $this->buildAvatarUploadFeedback($request->file('avatar'), $payload['message']);

            return $this->respondWithSuccess(
                $request,
                $payload,
                $feedback['description'],
                $feedback['event']
            );
        } catch (Throwable $e) {
            // Registra el error en caso de fallo
            Log::error('Avatar update failed', [
                'user_id' => $authUser->getKey(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            $errorMessage = __('settings.profile.avatar.update_failed');

            // Devuelve error en formato JSON o redirect con flash
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errorMessage,
                    'error' => config('app.debug') ? $e->getMessage() : null,
                ], 422);
            }

            return back()
                ->withErrors(['avatar' => $errorMessage])
                ->with('error', $errorMessage);
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

        // Autoriza la eliminación del avatar
        $this->authorize('deleteAvatar', $authUser);

        try {
            // Ejecuta la acción de eliminación del avatar
            $deleted = $action($authUser);

            // Mensajes dependiendo de si se eliminó o no
            $message = $deleted
                ? __('settings.profile.avatar.deleted')
                : __('settings.profile.avatar.nothing_to_delete');

            $description = $deleted
                ? __('settings.profile.avatar.deleted_description')
                : __('settings.profile.avatar.nothing_to_delete_description');

            return $this->respondWithSuccess(
                $request,
                [
                    'message' => $message,
                    'deleted' => $deleted,
                ],
                $description,
                $this->buildEventFlash($message, $description)
            );
        } catch (Throwable $e) {
            // Registra el error en caso de fallo
            Log::error('Avatar delete failed', [
                'user_id' => $authUser->getKey(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            $errorMessage = __('settings.profile.avatar.delete_failed');

            // Devuelve error en formato JSON o redirect con flash
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errorMessage,
                    'error' => config('app.debug') ? $e->getMessage() : null,
                ], 422);
            }

            return back()
                ->withErrors(['avatar' => $errorMessage])
                ->with('error', $errorMessage);
        }
    }

    /**
     * Recopila información sobre los archivos subidos en la solicitud.
     *
     * @param Request $request La solicitud HTTP que contiene los archivos subidos.
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

                // Agrega información del archivo al array
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

    /**
     * Responde con un payload de éxito, ya sea como JSON o redirect con flash.
     *
     * @param Request $request La solicitud HTTP.
     * @param array $payload Datos de éxito.
     * @param string|null $description Descripción opcional del mensaje.
     * @param array|null $event Datos del evento para notificaciones UI.
     * @return RedirectResponse|JsonResponse
     */
    private function respondWithSuccess(Request $request, array $payload, ?string $description = null, ?array $event = null): RedirectResponse|JsonResponse
    {
        if ($description !== null) {
            $payload['description'] = $description;
        }

        if ($event !== null) {
            $payload['event'] = $event;
        }

        if ($request->wantsJson()) {
            return response()->json($payload, 200);
        }

        return back()->with($this->buildFlashData($payload['message'] ?? null, $description, $event));
    }

    /**
     * Construye un array para datos de sesión flash.
     *
     * @param string|null $message Mensaje principal.
     * @param string|null $description Descripción opcional.
     * @param array|null $event Datos del evento.
     * @return array<string, mixed>
     */
    private function buildFlashData(?string $message, ?string $description, ?array $event): array
    {
        $cleanEvent = $event ? array_filter([
            'title' => $event['title'] ?? null,
            'description' => $event['description'] ?? null,
        ], fn ($value) => $value !== null && $value !== '') : null;

        return array_filter([
            'success' => $message,
            'message' => $message,
            'description' => $description,
            'event' => $cleanEvent,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Construye retroalimentación (descripción y evento) para la subida de avatar.
     *
     * @param UploadedFile|null $file El archivo subido.
     * @param string $message Mensaje de éxito principal.
     * @return array{description: string|null, event: array<string, string>|null}
     */
    private function buildAvatarUploadFeedback(?UploadedFile $file, string $message): array
    {
        $description = $this->formatAvatarDescription($file);

        return [
            'description' => $description,
            'event' => $this->buildEventFlash($message, $description),
        ];
    }

    /**
     * Construye un payload estructurado para notificaciones de evento.
     *
     * @param string|null $title Título del evento.
     * @param string|null $description Descripción del evento.
     * @return array<string, string>|null
     */
    private function buildEventFlash(?string $title, ?string $description): ?array
    {
        $normalizedTitle = is_string($title) ? trim($title) : '';
        if ($normalizedTitle === '') {
            return null;
        }

        $payload = ['title' => $normalizedTitle];

        if (is_string($description) && trim($description) !== '') {
            $payload['description'] = trim($description);
        }

        return $payload;
    }

    /**
     * Formatea una descripción legible del avatar subido.
     *
     * @param UploadedFile|null $file El archivo subido.
     * @return string|null
     */
    private function formatAvatarDescription(?UploadedFile $file): ?string
    {
        $filename = $this->resolveUploadedFilename($file);
        if ($filename === null) {
            return null;
        }

        [$width, $height] = $this->readImageDimensions($file);

        if ($width !== null && $height !== null) {
            return __('settings.profile.avatar.updated_details', [
                'filename' => $filename,
                'width' => $width,
                'height' => $height,
            ]);
        }

        return __('settings.profile.avatar.updated_details_simple', [
            'filename' => $filename,
        ]);
    }

    /**
     * Extrae y sanea el nombre original del archivo subido.
     *
     * @param UploadedFile|null $file El archivo subido.
     * @return string|null
     */
    private function resolveUploadedFilename(?UploadedFile $file): ?string
    {
        if (!$file instanceof UploadedFile) {
            return null;
        }

        $originalName = (string) $file->getClientOriginalName();
        if (trim($originalName) === '') {
            return null;
        }

        return SecurityHelper::sanitizeFilename($originalName);
    }

    /**
     * Lee las dimensiones de la imagen desde un archivo subido.
     *
     * @param UploadedFile|null $file El archivo subido.
     * @return array{0:int|null,1:int|null}
     */
    private function readImageDimensions(?UploadedFile $file): array
    {
        if (!$file instanceof UploadedFile) {
            return [null, null];
        }

        $path = $file->getRealPath();
        if (!is_string($path) || $path === '') {
            return [null, null];
        }

        $info = @getimagesize($path);
        if (!is_array($info) || !isset($info[0], $info[1])) {
            return [null, null];
        }

        return [(int) $info[0], (int) $info[1]];
    }
}