<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Controllers\Settings;

use App\Application\User\Actions\DeleteAvatar;       // Ej. clase invocable para eliminar el avatar
use App\Application\User\Actions\UpdateAvatar;       // Ej. clase invocable para actualizar el avatar
use App\Support\Security\SecurityHelper;
use App\Http\Controllers\Controller;                  // Ej. base Controller de Laravel
use App\Modules\Uploads\Requests\Settings\UpdateAvatarRequest;   // Ej. valida imagen (mimes, tamaño, dimensiones)
use App\Modules\Uploads\Requests\HttpUploadedMedia;
use App\Support\Security\Exceptions\AntivirusException;
use App\Models\User;                                  // Ej. modelo User
use App\Infrastructure\Uploads\Pipeline\Exceptions\NormalizationFailedException;
use App\Infrastructure\Uploads\Pipeline\Exceptions\QuarantineException;
use App\Infrastructure\Uploads\Pipeline\Exceptions\ScanFailedException;
use App\Infrastructure\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Infrastructure\Uploads\Pipeline\Exceptions\VirusDetectedException;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use Illuminate\Auth\Access\AuthorizationException;    // Ej. excepciones de autorización
use Illuminate\Http\JsonResponse;                     // Ej. respuesta JSON
use Illuminate\Http\RedirectResponse;                 // Ej. respuesta redirect
use Illuminate\Http\Request;                          // Ej. request base
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Redis;
use App\Support\Logging\SecurityLogger;
use Throwable;                                        // Ej. captura de errores
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Modelo Media de Spatie // Ej.: media->getUrl()

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

        if (!$authUser) {
            throw new AuthorizationException('Authenticated user required to update avatar.');
        }

        $this->authorize('updateAvatar', $authUser);

        // Registra información sobre la solicitud de actualización de avatar
        SecurityLogger::info('avatar.update_request_received', [
            'user_id' => $authUser?->getKey(),
            'has_file' => $request->hasFile('avatar'),
            'files' => $this->gatherUploadedFileInfo($request),
        ]);

        try {
            // Encola el procesamiento completo de avatar
            $result = $action($authUser, new HttpUploadedMedia($request->file('avatar'))); // Ejecuta reemplazo vía orquestador

            // Prepara respuesta ligera indicando procesamiento completado
            $payload = [
                'message' => __('settings.profile.avatar.updated'),
                'status' => $result->new->status,
                'correlation_id' => $result->new->correlationId,
                'quarantine_id' => null,
            ];

            $feedback = $this->buildAvatarUploadFeedback($request->file('avatar'), $payload['message']);

            $avatarData = $this->buildAvatarResponseData($authUser); // Añade URLs del avatar para respuestas JSON // Ej: avatar_url/avatar_thumb_url/version

            return $this->respondWithSuccess(
                $request,
                $payload,
                $feedback['description'],
                $feedback['event'],
                $avatarData
            );
        } catch (VirusDetectedException $e) {
            SecurityLogger::warning('avatar.upload_rejected', [
                'user_id' => $authUser->getKey(),
                'reason' => 'virus_detected',
                'error' => $e->getMessage(),
            ]);

            return $this->respondValidationFailure($request, __('media.uploads.scan_blocked'));
        } catch (UploadValidationException $e) {
            $isAntivirusFailure = $e->getPrevious() instanceof AntivirusException;

            SecurityLogger::warning('avatar.upload_rejected', [
                'user_id' => $authUser->getKey(),
                'reason' => 'validation_failed',
                'error' => $e->getMessage(),
                'antivirus_fail_closed' => $isAntivirusFailure,
            ]);

            $message = $isAntivirusFailure
                ? __('media.uploads.scan_unavailable')
                : __('media.uploads.invalid_image');

            return $this->respondValidationFailure($request, $message);
        } catch (NormalizationFailedException $e) {
            SecurityLogger::error('avatar.upload_normalization_failed', [
                'user_id' => $authUser->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $this->respondServerFailure($request);
        } catch (ScanFailedException|QuarantineException $e) {
            // Registra un error si falla la infraestructura de escaneo o cuarentena
            SecurityLogger::error('avatar.upload_infrastructure_error', [
                'user_id' => $authUser->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $this->respondServerFailure($request);
        } catch (Throwable $e) {
            // Registra el error en caso de fallo
            SecurityLogger::error('avatar.update_failed', [
                'user_id' => $authUser->getKey(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->respondServerFailure($request);
        }
    }

    /**
     * Devuelve el estado del último upload de avatar para el usuario autenticado.
     * Permite polling en cliente usando correlation_id y/o quarantine_id.
     */
    public function status(Request $request, QuarantineManager $quarantine): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        if (!$authUser) {
            throw new AuthorizationException('Authenticated user required to query avatar status.');
        }

        $correlationId = trim((string) $request->query('correlation_id', ''));
        $quarantineId = trim((string) $request->query('quarantine_id', ''));

        $collection = app(\App\Infrastructure\Uploads\Profiles\AvatarProfile::class)->collection();

        // Busca media ya persistido con correlación o quarantine_id.
        $media = $authUser->getMedia($collection)
            ->first(function ($item) use ($correlationId, $quarantineId) {
                $corr = (string) ($item->getCustomProperty('correlation_id') ?? '');
                $qId = (string) ($item->getCustomProperty('quarantine_id') ?? '');

                return ($correlationId !== '' && $corr === $correlationId)
                    || ($quarantineId !== '' && $qId === $quarantineId);
            });

        if ($media) {
            return response()->json([
                'status' => 'completed',
                'media_id' => (string) $media->getKey(),
                'version' => $media->getCustomProperty('version'),
                'url' => $media->getUrl(),
            ]);
        }

        if ($quarantineId !== '') {
            $token = $quarantine->resolveToken($quarantineId);
            if ($token) {
                try {
                    $state = $quarantine->getState($token);
                    $status = match ($state) {
                        \App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineState::INFECTED => 'infected',
                        \App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineState::FAILED => 'failed',
                        default => 'processing',
                    };

                    return response()->json(['status' => $status]);
                } catch (\Throwable $e) {
                    SecurityLogger::warning('avatar.status.quarantine_lookup_failed', [
                        'user_id' => $authUser->getKey(),
                        'quarantine_id' => $quarantineId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json(['status' => 'processing']);
    }

    /**
     * Endpoint API: estado de una subida de avatar por upload_uuid.
     *
     * Devuelve 200 con status (completed|processing|superseded|failed) y media_id/latest_media_id.
     */
    public function uploadStatus(Request $request, string $uploadUuid): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        if (!$authUser) {
            throw new AuthorizationException('Authenticated user required to query avatar upload.');
        }

        $collection = app(\App\Infrastructure\Uploads\Profiles\AvatarProfile::class)->collection();
        $current = $authUser->getFirstMedia($collection);

        $requested = $authUser->getMedia($collection)
            ->first(function ($media) use ($uploadUuid) {
                return (string) ($media->getCustomProperty('upload_uuid') ?? '') === $uploadUuid;
            });

        if ($requested) {
            if ($current && $current->getKey() === $requested->getKey()) {
                return response()->json([
                    'status' => 'completed',
                    'media_id' => (string) $requested->getKey(),
                    'updated_at' => $requested->updated_at,
                ]);
            }

            return response()->json([
                'status' => 'superseded',
                'media_id' => (string) $requested->getKey(),
                'latest_media_id' => $current?->getKey(),
                'updated_at' => $requested->updated_at,
            ]);
        }

        $tenantId = tenant()?->getKey() ?? $authUser->tenant_id ?? $authUser->getKey();
        $last = $this->readLastUploadPayload($tenantId, $authUser->getKey());

        if ($last && ($last['upload_uuid'] ?? null) !== $uploadUuid) {
            return response()->json([
                'status' => 'superseded',
                'latest_media_id' => $last['media_id'] ?? null,
                'media_id' => null,
                'updated_at' => $last['updated_at'] ?? null,
            ]);
        }

        if ($last && ($last['upload_uuid'] ?? null) === $uploadUuid) {
            return response()->json([
                'status' => 'processing',
                'media_id' => $last['media_id'] ?? null,
                'updated_at' => $last['updated_at'] ?? null,
            ]);
        }

        return response()->json([
            'status' => 'failed',
            'message' => __('image-pipeline.validation.avatar_not_found'),
        ]);
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
                $this->buildEventFlash($message, $description),
                $this->buildAvatarResponseData($authUser) // Devuelve avatar vacío/actual tras DELETE // Ej.: avatar_url null
            );
        } catch (Throwable $e) {
            // Registra el error en caso de fallo
            SecurityLogger::error('avatar.delete_failed', [
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
     * Respuesta estándar para errores de validación de upload.
     *
     * @param Request $request La solicitud HTTP.
     * @param string $message Mensaje de error.
     * @return RedirectResponse|JsonResponse
     */
    private function respondValidationFailure(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
            ], 422);
        }

        return back()
            ->withErrors(['avatar' => $message])
            ->with('error', $message);
    }

    /**
     * Respuesta estándar para fallos internos del upload.
     *
     * @param Request $request La solicitud HTTP.
     * @return RedirectResponse|JsonResponse
     */
    private function respondServerFailure(Request $request): RedirectResponse|JsonResponse
    {
        $message = __('settings.profile.avatar.update_failed');

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
            ], 500);
        }

        return back()
            ->withErrors(['avatar' => $message])
            ->with('error', $message);
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
    private function respondWithSuccess(Request $request, array $payload, ?string $description = null, ?array $event = null, array $jsonExtras = []): RedirectResponse|JsonResponse
    {
        if ($description !== null) {
            $payload['description'] = $description;
        }

        if ($event !== null) {
            $payload['event'] = $event;
        }

        if ($request->wantsJson()) {
            $merged = array_merge($payload, $jsonExtras); // Fusiona extras solo para JSON // Ej: avatar_url/avatar_thumb_url
            return response()->json($merged, 200);
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
     * Construye datos de avatar (URL original + thumb + versión) para respuestas JSON inmediatas.
     *
     * @param User $user Usuario autenticado.
     * @return array<string, string|null>
     */
    private function buildAvatarResponseData(User $user): array
    {
        $collection = app(\App\Infrastructure\Uploads\Profiles\AvatarProfile::class)->collection(); // Colección de avatar // Ej: 'avatar'
        $media = $user->fresh()?->getFirstMedia($collection); // Refresca usuario y obtiene media // Ej: avatar actual

        if (!$media) { // Si no hay avatar // Ej: después de borrar
            return [
                'avatar_url' => null,
                'avatar_thumb_url' => null,
                'avatar_version' => null,
                'media_id' => null,
                'upload_uuid' => null,
            ];
        }

        $avatarUrl = $this->safeMediaUrl($media); // URL original segura // Ej: /media/tenants/1/.../v123.jpg
        $thumbUrl = $this->safeConversionUrl($media, 'thumb') ?? null; // URL thumb si existe // Ej: /media/...-thumb.webp

        $version = $media->getCustomProperty('version')
            ?? ($media->updated_at ? (string) $media->updated_at->timestamp : null); // Versionado para cache-busting // Ej: 1700000000

        return [
            'avatar_url' => $avatarUrl,
            'avatar_thumb_url' => $thumbUrl ?? $avatarUrl, // Fallback al original si no hay thumb // Ej: evita 404
            'avatar_version' => $version,
            'media_id' => (string) $media->getKey(),
            'upload_uuid' => (string) ($media->getCustomProperty('upload_uuid') ?? ''),
        ];
    }

    /**
     * Obtiene URL segura del media, manejando excepciones.
     */
    private function safeMediaUrl(Media $media): ?string
    {
        try {
            return $media->getUrl(); // Usa UrlGenerator tenant-aware // Ej: /media/tenants/...
        } catch (\Throwable) {
            return null; // Devuelve null si falla // Ej: conversión rota
        }
    }

    /**
     * Obtiene URL de una conversión si existe, evitando excepciones/404 prematuros.
     */
    private function safeConversionUrl(Media $media, string $conversion): ?string
    {
        try {
            if ($media->hasGeneratedConversion($conversion)) { // Verifica flag de Spatie // Ej: true si ya se generó
                return $media->getUrl($conversion); // Devuelve URL de conversión // Ej: thumb
            }
        } catch (\Throwable) {
            // Silencia errores y devuelve null para evitar 404 en UI
        }

        return null; // Conversión no lista o inexistente // Ej: null
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

    private function readLastUploadPayload(int|string $tenantId, int|string $userId): ?array
    {
        $key = sprintf('ppam:avatar:last:%s:%s', $tenantId, $userId);
        $raw = Redis::get($key);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
