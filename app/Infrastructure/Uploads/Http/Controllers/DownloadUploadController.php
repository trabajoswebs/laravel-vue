<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Controllers;

use App\Application\Shared\Contracts\TenantContextInterface;
use App\Infrastructure\Http\Controllers\Controller;
use App\Infrastructure\Uploads\Core\Models\Upload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la descarga de archivos subidos.
 *
 * Este controlador maneja las solicitudes de descarga de archivos almacenados en el sistema,
 * aplicando controles de acceso multi-inquilino y seguridad contra descarga de archivos sensibles.
 */
final class DownloadUploadController extends Controller
{
    /**
     * Constructor del controlador.
     *
     * @param TenantContextInterface $tenantContext Contexto del inquilino actual para aplicar controles de acceso
     */
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {}

    /**
     * Maneja la solicitud de descarga de un archivo específico.
     *
     * Este método procesa la petición para descargar un archivo identificado por su ID,
     * verifica que el usuario tenga permiso para accederlo (basado en el inquilino),
     * y devuelve el archivo para su descarga o redirección según el tipo de almacenamiento.
     *
     * @param Request $request Solicitud HTTP entrante que puede contener información del usuario autenticado
     * @param string $uploadId Identificador único del archivo a descargar
     * @return Response Respuesta HTTP con el archivo adjunto o redirección a URL temporal
     */
    public function __invoke(Request $request, string $uploadId): Response
    {
        // Obtiene el ID del inquilino del contexto actual
        try {
            $tenantId = $this->tenantContext->requireTenantId();
        } catch (\RuntimeException) {
            abort(403, 'Forbidden');
        }

        // Busca el archivo en la base de datos filtrando por inquilino y ID
        $upload = Upload::query()
            ->forTenant($tenantId)
            ->whereKey($uploadId)
            ->first();

        if (!$upload) {
            throw new NotFoundHttpException();
        }

        // Log y bloqueo explícito de secretos antes de la policy para auditoría
        if ($upload->profile_id === 'certificate_secret') {
            Log::warning('secret_download_attempt', [
                'tenant_id' => (string) $tenantId,
                'upload_id' => (string) $upload->getKey(),
                'user_id' => (string) ($request->user()?->getKey() ?? ''),
            ]);

            abort(403, 'Forbidden');
        }

        // Autoriza mediante policy (verifica tenant y perfiles permitidos)
        try {
            $this->authorize('download', $upload);
        } catch (AuthorizationException $e) {
            abort(403, 'Forbidden');
        }

        // Obtiene la configuración de disco y ruta del archivo desde la base de datos
        $disk = (string) $upload->disk;
        $path = (string) $upload->path;

        // Obtiene la instancia del sistema de archivos para el disco especificado
        $storage = Storage::disk($disk);

        // Verifica que el archivo exista físicamente en el sistema de almacenamiento
        if (!$storage->exists($path)) {
            throw new NotFoundHttpException();
        }

        // Determina el tipo MIME del archivo, usando uno genérico si no está definido
        $mime = $upload->mime ?: 'application/octet-stream';

        // Mejor: usa el nombre original si existe; fallback al basename.
        // Establece el nombre del archivo para la descarga, priorizando el nombre original
        $filename = $upload->original_name ?: basename($path);

        // Obtiene el driver de almacenamiento configurado para este disco
        $driver = (string) config("filesystems.disks.{$disk}.driver");

        // Para almacenamiento S3, genera una URL temporal firmada para descarga segura
        if ($driver === 's3' && method_exists($storage, 'temporaryUrl')) {
            // Genera URL temporal válida por 2 minutos con encabezados de contenido
            $url = $storage->temporaryUrl($path, now()->addMinutes(2), [
                'ResponseContentType' => $mime,
                'ResponseContentDisposition' => "attachment; filename=\"{$filename}\"",
            ]);

            // Redirige al cliente a la URL temporal generada por S3
            return redirect()->away($url);
        }

        // Para otros sistemas de archivos, envía directamente el archivo como respuesta
        return $storage->download($path, $filename, [
            // Establece encabezados HTTP para controlar cómo se maneja el archivo
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Content-Type-Options' => 'nosniff', // Previene sniffing de tipo MIME por navegadores
        ]);
    }
}
