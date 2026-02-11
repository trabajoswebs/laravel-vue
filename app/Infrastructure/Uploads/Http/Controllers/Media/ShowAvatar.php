<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Controllers\Media;

use App\Infrastructure\Http\Controllers\Controller; // Base controller
use App\Infrastructure\Uploads\Http\Support\MediaServingResponder;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use App\Infrastructure\Uploads\Profiles\AvatarProfile; // Perfil avatar
use App\Infrastructure\Models\User; // Modelo User
use Illuminate\Http\Request; // Request HTTP
use Illuminate\Support\Facades\Auth; // Facade Auth
use Illuminate\Support\Facades\Gate; // Facade Gate
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
     * @param MediaServingResponder $responder Service for serving media files
     */
    public function __construct(
        private readonly AvatarProfile $profile,
        private readonly MediaServingResponder $responder,
        private readonly MediaSecurityLogger $securityLogger,
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
        // Verifica si el serving firmado está habilitado en la configuración
        if (!config('media.signed_serve.enabled', false)) {
            throw new NotFoundHttpException(__('media.errors.signed_serve_disabled')); // 404 para ocultar existencia
        }

        // Obtiene el usuario autenticado
        $actor = Auth::user();

        if (!$actor instanceof User) { // Si no hay usuario válido
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Restringe acceso
        }

        // Obtiene el propietario del media
        $owner = $media->model;

        if (!$owner instanceof User) { // Valida que el owner sea User
            throw new NotFoundHttpException(__('media.errors.not_avatar_collection')); // Si no, no es avatar esperado
        }

        // Verifica que ambos usuarios pertenezcan al mismo tenant
        $this->assertSameTenant($actor, $owner);
        // Verifica autorización antes de validar la firma
        $this->authorizeViewing($actor, $owner);

        // Valida la firma de la URL firmada
        if (!$request->hasValidSignature()) {
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Firma inválida
        }

        // Resuelve y valida el tipo de conversión solicitado
        $conversion = $this->resolveConversion($request);
        // Verifica que el media pertenece a la colección de avatar
        $this->assertBelongsToAvatarCollection($media);
        // Verifica que la conversión existe y obtiene los detalles de almacenamiento
        [$disk, $relativePath] = $this->assertConversionExists($media, $conversion);

        // Refuerzo de seguridad: normaliza la ruta y previene traversals
        $relativePath = $this->sanitizePath(
            $relativePath,
            $actor->current_tenant_id,
            $owner->getAuthIdentifier()
        );
        
        $driver  = (string) config("filesystems.disks.{$disk}.driver");

        // Detecta tipo MIME con fallback a la ruta
        $mimeByPath = $this->guessMimeByPathname($relativePath)
            ?? $media->mime_type
            ?? 'application/octet-stream';

        try {
            // Sirve el archivo usando el responder adecuado
            return $this->responder->serve($disk, $relativePath, [
                'Content-Type' => $mimeByPath,
                'Content-Disposition' => 'inline; filename="' . $this->safeInlineFilename($media->file_name) . '"',
            ]);
        } catch (\Throwable $e) {
            if ($driver === 's3') {
                // Nunca hacer streaming en S3: loguea y devuelve 404
                $this->securityLogger->warning('media.pipeline.failed', [
                    'reason'  => 'avatar_signed_url_failed',
                    'media_id' => $media->id,
                    'actor_id' => $actor->getAuthIdentifier(),
                    'tenant_id' => $actor->current_tenant_id,
                    'conversion' => $conversion,
                    'exception_class' => $e::class,
                    'message' => $e->getMessage(),
                    'disk'    => $disk,
                    'path_hash' => substr(hash('sha256', $relativePath), 0, 16),
                ]);
            } else {
                // Maneja condición de carrera donde el archivo desapareció entre exists() y response()
                if (config('app.debug')) {
                    report($e);
                } else {
                    $this->securityLogger->warning('media.pipeline.failed', [
                        'reason'  => 'avatar_local_file_serve_failed',
                        'media_id' => $media->id,
                        'actor_id' => $actor->getAuthIdentifier(),
                        'tenant_id' => $actor->current_tenant_id,
                        'conversion' => $conversion,
                        'exception_class' => $e::class,
                        'message' => $e->getMessage(),
                        'path_hash' => substr(hash('sha256', $relativePath), 0, 16),
                    ]);
                }
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
        // Obtiene el tipo de conversión de la query string, por defecto 'thumb'
        $conversion = strtolower((string) $request->query('c', 'thumb'));

        // Verifica que la conversión esté en la lista permitida
        if (!in_array($conversion, self::ALLOWED_CONVERSIONS, true)) { // Conversión no permitida
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
        // Obtiene el nombre de la colección del perfil de avatar
        $collection = $this->profile->collection();

        // Verifica que el nombre de la colección del media coincida
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
        // Determina el disco de conversiones o fallback al disco principal
        $disk = $media->conversions_disk
            ?: (string) config('media-library.conversions_disk', '')
            ?: $media->disk;
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');
        $isRemote = $driver !== 'local';
        $relativePath = '';

        try {
            // Intenta obtener la ruta relativa de la conversión
            $relativePath = $media->getPathRelativeToRoot($conversion);
        } catch (\Throwable) {
            // Si falla, se asigna cadena vacía
            $relativePath = '';
        }

        // En remoto evitamos exists() extra; el responder/driver validará durante el serve().
        if ($relativePath !== '' && ($isRemote || Storage::disk($disk)->exists($relativePath))) {
            return [$disk, $relativePath];
        }

        // Fallback al archivo original si la conversión no existe o no está lista aún.
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
     * Expected strict format:
     * - tenants/{tenantId}/users/{ownerId}/avatars/{filename}
     * - tenants/{tenantId}/users/{ownerId}/avatars/{uuid}/{filename}
     * - tenants/{tenantId}/users/{ownerId}/avatars/{uuid}/conversions/{filename}
     *
     * @param string $path The path to sanitize
     * @return string The sanitized path
     * @throws NotFoundHttpException When path contains traversal attempts
     */
    private function sanitizePath(string $path, int|string|null $tenantId, int|string|null $ownerId): string
    {
        $decoded = $path;
        for ($i = 0; $i < 3; $i++) {
            if (preg_match('/%2f|%5c/i', $decoded) === 1) {
                throw new NotFoundHttpException(__('media.errors.invalid_path')); // 404 uniforme para evitar bypass por encoded separators
            }

            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        // Normaliza los separadores de directorio a '/'
        $path = str_replace('\\', '/', $decoded);
        // Filtra los segmentos de la ruta, eliminando vacíos y '.'
        $segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== '' && $s !== '.'));

        // Bloquea intentos de traversals con '../'
        if (in_array('..', $segments, true)) {
            throw new NotFoundHttpException(__('media.errors.invalid_path')); // 404 para ocultar
        }

        // Reconstruye la ruta limpia
        $clean = implode('/', $segments);

        $expectedPrefix = $this->tenantPrefix($tenantId);
        if ($expectedPrefix === '' || !str_starts_with($clean, $expectedPrefix)) {
            throw new NotFoundHttpException(__('media.errors.invalid_path')); // 404 uniforme para paths inválidos
        }

        if (! $this->isOwnerAvatarPath($clean, (string) $ownerId)) {
            throw new NotFoundHttpException(__('media.errors.invalid_path')); // 404 uniforme para paths inválidos
        }

        return $clean; // Devuelve path sanitizado
    }

    private function tenantPrefix(int|string|null $tenantId): string
    {
        if ($tenantId === null || (is_string($tenantId) && trim($tenantId) === '')) {
            return '';
        }

        return 'tenants/' . (string) $tenantId . '/';
    }

    /**
     * Verifica que actor y owner compartan tenant o tengan override.
     * 
     * @param User $actor Usuario autenticado
     * @param User $owner Propietario del media
     */
    private function assertSameTenant(User $actor, User $owner): void // Garantiza pertenencia a tenant
    {
        // Obtiene los IDs de tenant del actor y del owner
        $actorTenant = $actor->current_tenant_id; // Tenant del actor
        $ownerTenant = $owner->current_tenant_id; // Tenant del owner

        if ($actorTenant === null || $ownerTenant === null) { // Si falta tenant
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Bloquea acceso
        }

        // Valida que el actor pertenezca a su tenant actual
        if (!Gate::forUser($actor)->allows('use-current-tenant', $actor)) {
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Bloquea acceso
        }

        // Verifica que ambos usuarios pertenezcan al mismo tenant
        if ((string) $actorTenant !== (string) $ownerTenant) { // Si tenants difieren
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Bloquea acceso cross-tenant
        }
    }

    /**
     * Autoriza la visualización del avatar vía policy de usuario.
     * 
     * @param User $actor Usuario autenticado
     * @param User $owner Propietario del media
     */
    private function authorizeViewing(User $actor, User $owner): void // Verifica policy de usuario
    {
        // Verifica si el actor tiene permiso para ver al owner usando la policy
        if (!Gate::forUser($actor)->allows('view', $owner)) { // Usa policy User@view
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature')); // Bloquea acceso
        }
    }

    /**
     * Guess MIME type by file extension using Symfony MimeTypes.
     *
     * @param string $pathname The file path/name
     * @return string|null Detected MIME type or null if unknown
     */
    private function guessMimeByPathname(string $pathname): ?string
    {
        // Obtiene la extensión del archivo y la convierte a minúsculas
        $extension = strtolower((string) pathinfo($pathname, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        // Crea instancia del detector de MIME types de Symfony
        $mimeTypes = new MimeTypes();
        // Obtiene los tipos MIME posibles para la extensión
        $candidates = $mimeTypes->getMimeTypes($extension);
        // Toma el primer candidato
        $detected = $candidates[0] ?? null;

        return is_string($detected) && $detected !== '' ? $detected : null;
    }

    private function safeInlineFilename(?string $fileName): string
    {
        $fallback = 'avatar';
        $name = is_string($fileName) ? basename($fileName) : $fallback;
        $name = str_replace(["\r", "\n", '"'], '', $name);

        return $name !== '' ? $name : $fallback;
    }

    private function isOwnerAvatarPath(string $cleanPath, string $ownerId): bool
    {
        if ($ownerId === '') {
            return false;
        }

        $parts = explode('/', $cleanPath);
        if (count($parts) < 6) {
            return false;
        }

        if (
            $parts[0] !== 'tenants'
            || $parts[2] !== 'users'
            || $parts[3] !== $ownerId
            || $parts[4] !== 'avatars'
        ) {
            return false;
        }

        $tail = array_slice($parts, 5);
        if ($tail === []) {
            return false;
        }

        // tenants/{t}/users/{u}/avatars/{file}
        if (count($tail) === 1) {
            return $tail[0] !== '' && $tail[0] !== 'conversions';
        }

        // tenants/{t}/users/{u}/avatars/{uuid}/{file}
        if (count($tail) === 2) {
            return $tail[0] !== '' && $tail[0] !== 'conversions' && $tail[1] !== '';
        }

        // tenants/{t}/users/{u}/avatars/{uuid}/conversions/{file}
        if (count($tail) === 3) {
            return $tail[0] !== '' && $tail[0] !== 'conversions' && $tail[1] === 'conversions' && $tail[2] !== '';
        }

        return false;
    }
}
