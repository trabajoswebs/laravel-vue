<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Controllers;

use App\Application\Uploads\Actions\ReplaceFile;
use App\Application\Uploads\Actions\UploadFile;
use App\Application\Uploads\Exceptions\InvalidOwnerIdException;
use App\Application\Uploads\Exceptions\InvalidUploadFileException;
use App\Domain\Uploads\UploadProfileId;
use App\Infrastructure\Http\Controllers\Controller;
use App\Infrastructure\Uploads\Core\Models\Upload;
use App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry;
use App\Infrastructure\Uploads\Http\Requests\HttpUploadedMedia;
use App\Infrastructure\Uploads\Http\Requests\ReplaceUploadRequest;
use App\Infrastructure\Uploads\Http\Requests\StoreUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use App\Support\Logging\SecurityLogger;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Controlador para gestionar operaciones CRUD de uploads.
 * 
 * Proporciona endpoints para crear, actualizar y eliminar archivos subidos,
 * manejando la lógica de negocio a través de acciones de aplicación.
 * 
 * @package App\Infrastructure\Uploads\Http\Controllers
 */
final class UploadController extends Controller
{
    /**
     * Almacena un nuevo archivo subido.
     * 
     * Endpoint: POST /uploads
     * 
     * Este método maneja la creación de nuevos uploads, validando las entradas,
     * autorizando la operación y ejecutando la acción de subida correspondiente.
     *
     * @param StoreUploadRequest $request Solicitud con los datos de validación
     * @param UploadFile $uploadFile Acción de aplicación para subir archivos
     * @return JsonResponse Respuesta JSON con los detalles del upload creado
     */
    public function store(
        StoreUploadRequest $request,
        UploadFile $uploadFile,
    ): JsonResponse {
        // Obtiene el perfil de upload desde la solicitud
        $profile = $request->profile();

        // Autoriza la creación del upload basado en el tipo y perfil
        $this->authorize('create', [Upload::class, (string) $profile->id]);

        try {
            // Ejecuta la acción de subida de archivo con todos los parámetros necesarios
            $result = $uploadFile(
                $profile,
                $request->user(),
                new HttpUploadedMedia($request->file('file')),
                $request->input('owner_id'),
                $request->correlationId(),
                $request->meta(),
            );
        } catch (InvalidOwnerIdException $e) {
            throw ValidationException::withMessages([
                'owner_id' => [$e->getMessage()],
            ]);
        } catch (InvalidUploadFileException $e) {
            throw ValidationException::withMessages([
                'file' => [$e->getMessage()],
            ]);
        } catch (\Throwable $e) {
            // Registra errores durante la subida de archivos
            SecurityLogger::error('uploads.store_failed', [
                'profile' => (string) $profile->id,
                'error' => $e->getMessage(),
            ]);

            // Re-lanza la excepción para que sea manejada por el handler global
            throw $e;
        }

        // Retorna respuesta exitosa con los detalles del upload creado
        return response()->json([
            'id' => $result->id,
            'profile_id' => $result->profileId,
            'status' => $result->status,
            'correlation_id' => $result->correlationId,
        ], HttpStatus::HTTP_CREATED);
    }

    /**
     * Actualiza/reemplaza un archivo subido existente.
     * 
     * Endpoint: PATCH /uploads/{uploadId}
     * 
     * Este método maneja la actualización de uploads existentes, validando que
     * el nuevo perfil coincida con el original y ejecutando la acción de reemplazo.
     *
     * @param ReplaceUploadRequest $request Solicitud con los datos de validación
     * @param string $uploadId ID del upload a reemplazar
     * @param UploadProfileRegistry $profiles Registro de perfiles para obtener el perfil original
     * @param ReplaceFile $replaceFile Acción de aplicación para reemplazar archivos
     * @return JsonResponse Respuesta JSON con los detalles del nuevo upload
     */
    public function update(
        ReplaceUploadRequest $request,
        string $uploadId,
        UploadProfileRegistry $profiles,
        ReplaceFile $replaceFile,
    ): JsonResponse {
        /** @var Upload $upload */
        // Busca el upload existente por su ID
        $upload = Upload::query()->whereKey($uploadId)->firstOrFail();

        // Autoriza la operación de reemplazo basada en pertenencia al tenant y propiedad
        $this->authorize('replace', $upload);

        // Si se proporciona un profile_id, debe coincidir con el del upload original
        $incoming = $request->input('profile_id');
        if (is_string($incoming) && trim($incoming) !== '' && trim($incoming) !== (string) $upload->profile_id) {
            // Retorna error si los perfiles no coinciden
            return response()->json([
                'message' => 'El profile_id no coincide con el upload.',
                'errors' => [
                    'profile_id' => ['El profile_id no coincide con el upload.'],
                ],
            ], HttpStatus::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Obtiene el perfil original del upload
        $profile = $profiles->get(new UploadProfileId((string) $upload->profile_id));

        try {
            // Ejecuta la acción de reemplazo de archivo con todos los parámetros necesarios
            $replacement = $replaceFile(
                $profile,
                $request->user(),
                new HttpUploadedMedia($request->file('file')),
                $request->input('owner_id'),
                $request->correlationId(),
                $request->meta(),
                $upload,
            );
        } catch (InvalidOwnerIdException $e) {
            throw ValidationException::withMessages([
                'owner_id' => [$e->getMessage()],
            ]);
        } catch (InvalidUploadFileException $e) {
            throw ValidationException::withMessages([
                'file' => [$e->getMessage()],
            ]);
        } catch (\Throwable $e) {
            // Registra errores durante la operación de reemplazo
            SecurityLogger::error('uploads.replace_failed', [
                'upload_id' => $uploadId,
                'profile' => (string) $upload->profile_id,
                'error' => $e->getMessage(),
            ]);

            // Re-lanza la excepción para que sea manejada por el handler global
            throw $e;
        }

        // Retorna respuesta exitosa con los detalles del nuevo upload
        return response()->json([
            'id' => $replacement->new->id,
            'profile_id' => $replacement->new->profileId,
            'status' => $replacement->new->status,
            'correlation_id' => $replacement->new->correlationId,
        ], HttpStatus::HTTP_CREATED);
    }

    /**
     * Elimina un archivo subido existente.
     * 
     * Endpoint: DELETE /uploads/{uploadId}
     * 
     * Este método maneja la eliminación de uploads, borrando tanto el archivo físico
     * como el registro de la base de datos.
     *
     * @param string $uploadId ID del upload a eliminar
     * @return Response Respuesta HTTP sin contenido
     */
    public function destroy(string $uploadId): Response
    {
        /** @var Upload $upload */
        // Busca el upload existente por su ID
        $upload = Upload::query()->whereKey($uploadId)->firstOrFail();

        // Autoriza la operación de eliminación basada en pertenencia y propiedad
        $this->authorize('delete', $upload);

        // Borrado best-effort (si falla, aun así borramos el registro).
        try {
            $upload->deleteFileBestEffort();
        } catch (\Throwable) {
            // Si no tienes ese método, elimina esta parte y deja el borrado en el modelo o aquí.
        }

        // Borra el registro del upload de la base de datos
        $upload->delete();

        // Retorna respuesta sin contenido (204 No Content)
        return response()->noContent(); // 204 real sin body
    }
}
