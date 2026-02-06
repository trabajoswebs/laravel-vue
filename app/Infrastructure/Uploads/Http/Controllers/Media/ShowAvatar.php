<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Controllers\Media;

use App\Infrastructure\Http\Controllers\Controller; // Base controller
use App\Infrastructure\Uploads\Profiles\AvatarProfile; // Perfil avatar
use App\Infrastructure\Models\User; // Modelo User
use App\Infrastructure\Tenancy\Models\Tenant; // Modelo Tenant
use Illuminate\Contracts\Filesystem\Filesystem; // Contrato Filesystem
use Illuminate\Http\Request; // Request HTTP
use Illuminate\Support\Facades\Auth; // Facade Auth
use Illuminate\Support\Facades\Gate; // Facade Gate
use Illuminate\Support\Facades\Log; // Facade Log
use Illuminate\Support\Facades\Storage; // Facade Storage
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Modelo Media Spatie
use Symfony\Component\HttpFoundation\Response; // Respuesta HTTP
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException; // Excepción 403
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException; // Excepción 404
use Symfony\Component\Mime\MimeTypes; // Detección MIME

/**
 * Controller for serving signed avatar conversions with security hardening.
 * 
 * This controller provides secure, time-limited access to avatar image conversions
 * using signed URLs to prevent unauthorized access and hotlinking.
 * 
 * @package App\Http\Controllers\Media
 */
final class ShowAvatar extends Controller
{
    /**
     * Allowed conversion types for avatar images.
     * 
     * @var string[]
     */
    private const ALLOWED_CONVERSIONS = ['thumb', 'medium', 'large'];

    /**
     * Constructor.
     *
     * @param AvatarProfile $profile The avatar profile containing collection configuration
     */
    public function __construct(
        private readonly AvatarProfile $profile,
    ) {
    }

    /**
     * Serve a generated avatar conversion via signed URL.
     *
     * This method handles secure delivery of avatar images with the following features:
     * - Signed URL validation with expiration
     * - Conversion type validation
     * - Collection ownership verification
     * - Path traversal protection
     * - MIME type detection and security headers
     * - Support for both local and S3 storage drivers
     * - Graceful fallbacks for missing files and errors
     *
     * @param Request $request The incoming HTTP request containing signed parameters
     * @param Media $media The media entity being accessed
     * @return Response HTTP response with the image file or redirect
     * 
     * @throws NotFoundHttpException When signed serving is disabled, conversion is invalid, 
     *                               media doesn't belong to avatar collection, or file is missing
     * @throws AccessDeniedHttpException When URL signature is invalid or expired
     */
    public function __invoke(Request $request, Media $media): Response
    {
        if (!config('media.signed_serve.enabled', false)) { // Si el serving firmado está deshabilitado
            throw new NotFoundHttpException(__('media.errors.signed_serve_disabled')); // 404 para ocultar existencia
        }

        $actor = Auth::user(); // Obtiene usuario autenticado

        if (!$actor instanceof User) { // Si no hay user válido
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Restringe acceso
        }

        $owner = $media->model; // Obtiene propietario del media

        if (!$owner instanceof User) { // Valida que el owner sea User
            throw new NotFoundHttpException(__('media.errors.not_avatar_collection')); // Si no, no es avatar esperado
        }

        $this->assertSameTenant($actor, $owner); // Verifica pertenencia al mismo tenant
        $this->authorizeViewing($actor, $owner); // Verifica policy/tenant antes de firma

        if (!$request->hasValidSignature()) { // Valida firma después de policy
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Firma inválida
        }

        $conversion = $this->resolveConversion($request); // Valida conversión solicitada
        $this->assertBelongsToAvatarCollection($media); // Verifica colección avatar
        [$disk, $relativePath] = $this->assertConversionExists($media, $conversion); // Verifica conversión y obtiene path

        // Security hardening: normalize path and prevent directory traversal
        $relativePath = $this->sanitizePath($relativePath);
        
        /** @var \Illuminate\Filesystem\FilesystemAdapter $adapter */
        $adapter = Storage::disk($disk);
        $driver  = (string) config("filesystems.disks.{$disk}.driver");

        // Detect MIME type with fallbacks
        $mimeByPath = $this->guessMimeByPathname($relativePath)
            ?? $media->mime_type
            ?? 'application/octet-stream';
        $localCacheControl = 'private, max-age=' . $this->localMaxAgeSeconds() . ', must-revalidate';
        $s3CacheControl = 'private, max-age=0, no-store';

        // Handle S3 storage with temporary signed URLs
        if ($driver === 's3') {
            try {
                $ttlSeconds = $this->s3TemporaryUrlTtlSeconds(); // TTL único para URL temporal
                $url = $adapter->temporaryUrl(
                    $relativePath,
                    now()->addSeconds($ttlSeconds), // TTL de URL temporal
                    [
                        'ResponseContentType'  => $mimeByPath,
                        'ResponseCacheControl' => $s3CacheControl,
                    ]
                );
                return redirect()->away($url, 302)->withHeaders([
                    'Cache-Control' => $s3CacheControl,
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            } catch (\Throwable $e) {
                // Nunca hacer streaming en S3: loguea y devuelve 404
                logger()->warning('avatar_signed_url_failed', [
                    'message' => $e->getMessage(),
                    'disk'    => $disk,
                    'path'    => $relativePath,
                ]);
                throw new NotFoundHttpException(__('media.errors.missing_conversion'));
            }
        }

        // Handle local and other filesystem drivers
        $absolutePath = $adapter->path($relativePath);
        $mime = $this->guessMimeLocal($absolutePath) ?? $mimeByPath;

        try {
            $response = response()->file($absolutePath, [
                'Content-Type'            => $mime, // MIME final
                'X-Content-Type-Options'  => 'nosniff', // Evita sniffing
                'Cache-Control'           => $localCacheControl, // Cache local configurable
            ]);
            // BinaryFileResponse marca como public por defecto; forzamos privado.
            $response->setPrivate();
            $response->headers->set('Cache-Control', $localCacheControl);

            return $response;
        } catch (\Throwable $e) {
            // Handle race condition where file disappeared between exists() and file()
            if (config('app.debug')) {
                report($e);
            } else {
                logger()->warning('avatar_local_file_serve_failed', [
                    'message' => $e->getMessage(),
                    'path'    => $absolutePath,
                ]);
            }
            throw new NotFoundHttpException(__('media.errors.missing_conversion'));
        }
    }

    /**
     * Resolve and validate the requested conversion type.
     *
     * @param Request $request The incoming request
     * @return string The validated conversion name
     * @throws NotFoundHttpException When the conversion is not in allowed list
     */
    private function resolveConversion(Request $request): string
    {
        $conversion = strtolower((string) $request->query('c', 'thumb'));

        if (!in_array($conversion, self::ALLOWED_CONVERSIONS, true)) { // Conversion no permitida
            throw new NotFoundHttpException(__('media.errors.invalid_conversion')); // 404 para ocultar
        }

        return $conversion;
    }

    /**
     * Verify that the media belongs to the avatar collection.
     *
     * @param Media $media The media entity to check
     * @throws NotFoundHttpException When media doesn't belong to avatar collection
     */
    private function assertBelongsToAvatarCollection(Media $media): void
    {
        $collection = $this->profile->collection();

        if ($media->collection_name !== $collection) { // Si la colección no coincide
            throw new NotFoundHttpException(__('media.errors.not_avatar_collection')); // 404 para ocultar existencia
        }
    }

    /**
     * Verify that the conversion exists and return storage details.
     *
     * @param Media $media The media entity
     * @param string $conversion The conversion name
     * @return array{0:string,1:string} Array containing [disk_name, relative_path]
     * @throws NotFoundHttpException When conversion doesn't exist
     */
    private function assertConversionExists(Media $media, string $conversion): array
    {
        $disk = $media->conversions_disk
            ?: (string) config('media-library.conversions_disk', '')
            ?: $media->disk;
        $relativePath = '';

        try {
            $relativePath = $media->getPathRelativeToRoot($conversion);
        } catch (\Throwable) {
            $relativePath = '';
        }

        if ($relativePath !== '' && Storage::disk($disk)->exists($relativePath)) {
            return [$disk, $relativePath];
        }

        // Fallback al original si la conversión no existe o no está lista aún.
        $disk = $media->disk;
        try {
            $relativePath = $media->getPathRelativeToRoot();
        } catch (\Throwable) {
            throw new NotFoundHttpException(__('media.errors.missing_conversion'));
        }

        if ($relativePath === '') {
            throw new NotFoundHttpException(__('media.errors.missing_conversion'));
        }

        return [$disk, $relativePath];
    }

    /**
     * Normalize path separators and prevent directory traversal attacks.
     *
     * @param string $path The path to sanitize
     * @return string The sanitized path
     * @throws NotFoundHttpException When path contains traversal attempts
     */
    private function sanitizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path); // Normaliza separadores
        $segments = array_filter(explode('/', $path), static fn($s) => $s !== '' && $s !== '.'); // Limpia segmentos

        if (in_array('..', $segments, true)) { // Bloquea traversal
            throw new NotFoundHttpException(__('media.errors.invalid_path')); // 404 para ocultar
        }

        $clean = implode('/', $segments); // Reconstruye path

        if (!str_contains($clean, $this->profile->collection())) { // Verifica prefijo colección
            throw new AccessDeniedHttpException(__('media.errors.invalid_path')); // Bloquea si no es avatar
        }

        return $clean; // Devuelve path sanitizado
    }

    /**
     * Verifica que actor y owner compartan tenant o tengan override.
     */
    private function assertSameTenant(User $actor, User $owner): void // Garantiza pertenencia a tenant
    {
        $actorTenant = $actor->current_tenant_id; // Tenant del actor
        $ownerTenant = $owner->current_tenant_id; // Tenant del owner

        if ($actorTenant === null || $ownerTenant === null) { // Si falta tenant
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Bloquea acceso
        }

        if (!Gate::forUser($actor)->allows('use-current-tenant', $actor)) { // Valida que el actor pertenece a su tenant
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Bloquea acceso
        }

        if ((string) $actorTenant !== (string) $ownerTenant) { // Si tenants difieren
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Bloquea acceso cross-tenant
        }
    }

    /**
     * Autoriza la visualización del avatar vía policy de usuario.
     */
    private function authorizeViewing(User $actor, User $owner): void // Verifica policy de usuario
    {
        if (!Gate::forUser($actor)->allows('view', $owner)) { // Usa policy User@view
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Bloquea acceso
        }
    }

    /**
     * Stream file content from disk for remote drivers without redirection support.
     *
     * @param Filesystem $adapter The filesystem adapter
     * @param string $relativePath The relative path to the file
     * @param string $mime The MIME type of the file
     * @return Response Streamed response with file content
     */
    private function streamFromDisk(Filesystem $adapter, string $relativePath, string $mime, string $cacheControl): Response
    {
        $size = null;
        try {
            $size = $adapter->size($relativePath);
        } catch (\Throwable) {
            // Optional: continue without content length
        }

        /** @var Filesystem $adapter */
        /** @var string $relativePath */
        return response()->stream(function () use ($adapter, $relativePath): void {
            $stream = $adapter->readStream($relativePath);
            if ($stream === false || !is_resource($stream)) {
                throw new NotFoundHttpException(__('media.errors.missing_conversion'));
            }
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, array_filter([
            'Content-Type'            => $mime,
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => $cacheControl,
            'Content-Length'          => is_int($size) ? (string) $size : null,
        ]));
    }

    /**
     * TTL en segundos para URLs/firmas y caché asociada.
     */
    private function localMaxAgeSeconds(): int
    {
        $seconds = (int) config('media-serving.local_max_age_seconds', 86400);
        $seconds = $seconds > 0 ? $seconds : 86400;

        return $seconds;
    }

    private function s3TemporaryUrlTtlSeconds(): int
    {
        $seconds = (int) config('media-serving.s3_temporary_url_ttl_seconds', 900);
        $seconds = $seconds > 0 ? $seconds : 900;

        return $seconds;
    }

    /**
     * Guess MIME type by file extension using Symfony MimeTypes.
     *
     * @param string $pathname The file path/name
     * @return string|null Detected MIME type or null if unknown
     */
    private function guessMimeByPathname(string $pathname): ?string
    {
        $extension = strtolower((string) pathinfo($pathname, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        $mimeTypes = new MimeTypes();
        $candidates = $mimeTypes->getMimeTypes($extension);
        $detected = $candidates[0] ?? null;

        return is_string($detected) && $detected !== '' ? $detected : null;
    }

    /**
     * Guess MIME type for local files with guaranteed resource cleanup.
     *
     * Uses multiple detection methods in order of reliability:
     * 1. Symfony MimeTypes by extension
     * 2. PHP finfo extension
     * 3. PHP mime_content_type function
     *
     * @param string $absolutePath Absolute path to the local file
     * @return string|null Detected MIME type or null if unknown
     */
    private function guessMimeLocal(string $absolutePath): ?string
    {
        if (!is_file($absolutePath)) {
            return null;
        }

        $mimeTypes = new MimeTypes();
        $detected = $mimeTypes->guessMimeType($absolutePath);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }

        // Use finfo extension if available
        if (function_exists('finfo_open')) {
            $resource = finfo_open(FILEINFO_MIME_TYPE);
            if ($resource) {
                try {
                    $detected = finfo_file($resource, $absolutePath) ?: null;
                } finally {
                    finfo_close($resource);
                }
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        // Fall back to mime_content_type with error suppression
        if (function_exists('mime_content_type')) {
            // Don't use @; install temporary error handler and always restore
            set_error_handler(static fn() => true);
            try {
                $detected = mime_content_type($absolutePath) ?: null;
            } finally {
                restore_error_handler(); // always restore
            }
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return null;
    }
}
