<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Support\Media\Profiles\AvatarProfile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;

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
        // Check if signed serving feature is enabled
        if (!config('media.signed_serve.enabled', false)) {
            throw new NotFoundHttpException(__('media.errors.signed_serve_disabled'));
        }

        // Validate the request signature to prevent unauthorized access
        if (!$request->hasValidSignature()) {
            throw new AccessDeniedHttpException(__('media.errors.invalid_signature'));
        }

        // Resolve and validate the requested conversion type
        $conversion = $this->resolveConversion($request);

        // Verify the media belongs to the avatar collection
        $this->assertBelongsToAvatarCollection($media);
        
        // Verify the conversion exists and get storage details
        [$disk, $relativePath] = $this->assertConversionExists($media, $conversion);

        // Security hardening: normalize path and prevent directory traversal
        $relativePath = $this->sanitizePath($relativePath);
        
        /** @var \Illuminate\Filesystem\FilesystemAdapter $adapter */
        $adapter = Storage::disk($disk);
        $driver  = (string) config("filesystems.disks.{$disk}.driver");

        // Detect MIME type with fallbacks
        $mimeByPath = $this->guessMimeByPathname($relativePath)
            ?? $media->mime_type
            ?? 'application/octet-stream';

        // Handle S3 storage with temporary signed URLs
        if ($driver === 's3') {
            try {
                $url = $adapter->temporaryUrl(
                    $relativePath,
                    now()->addMinutes(5),
                    [
                        'ResponseContentType'  => $mimeByPath,
                        'ResponseCacheControl' => 'public, max-age=31536000, immutable',
                    ]
                );
                return redirect()->away($url, 302);
            } catch (\Throwable $e) {
                // Log the failure and fall back to streaming
                if (config('app.debug')) {
                    report($e);
                } else {
                    logger()->warning('avatar_signed_url_failed', [
                        'message' => $e->getMessage(),
                        'disk'    => $disk,
                        'path'    => $relativePath,
                    ]);
                }
                return $this->streamFromDisk($adapter, $relativePath, $mimeByPath);
            }
        }

        // Handle local and other filesystem drivers
        $absolutePath = $adapter->path($relativePath);
        $mime = $this->guessMimeLocal($absolutePath) ?? $mimeByPath;

        try {
            return response()->file($absolutePath, [
                'Content-Type'            => $mime,
                'X-Content-Type-Options'  => 'nosniff',
                'Cache-Control'           => 'public, max-age=31536000, immutable',
            ]);
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

        if (!in_array($conversion, self::ALLOWED_CONVERSIONS, true)) {
            throw new NotFoundHttpException(__('media.errors.invalid_conversion'));
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

        if ($media->collection_name !== $collection) {
            throw new NotFoundHttpException(__('media.errors.not_avatar_collection'));
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
        $disk = $media->conversions_disk ?: $media->disk;

        try {
            $relativePath = $media->getPathRelativeToRoot($conversion);
        } catch (\Throwable) {
            throw new NotFoundHttpException(__('media.errors.missing_conversion'));
        }

        if ($relativePath === '') {
            throw new NotFoundHttpException(__('media.errors.missing_conversion'));
        }

        if (!Storage::disk($disk)->exists($relativePath)) {
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
        $path = str_replace('\\', '/', $path);
        $segments = array_filter(explode('/', $path), static fn($s) => $s !== '' && $s !== '.');

        // Direct blocking of '..' traversal attempts
        if (in_array('..', $segments, true)) {
            throw new NotFoundHttpException(__('media.errors.invalid_path'));
        }

        return implode('/', $segments);
    }

    /**
     * Stream file content from disk for remote drivers without redirection support.
     *
     * @param Filesystem $adapter The filesystem adapter
     * @param string $relativePath The relative path to the file
     * @param string $mime The MIME type of the file
     * @return Response Streamed response with file content
     */
    private function streamFromDisk(Filesystem $adapter, string $relativePath, string $mime): Response
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
            'Cache-Control'           => 'public, max-age=31536000, immutable',
            'Content-Length'          => is_int($size) ? (string) $size : null,
        ]));
    }

    /**
     * Guess MIME type by file extension using Symfony MimeTypes.
     *
     * @param string $pathname The file path/name
     * @return string|null Detected MIME type or null if unknown
     */
    private function guessMimeByPathname(string $pathname): ?string
    {
        $mimeTypes = new MimeTypes();
        $detected = $mimeTypes->guessMimeType($pathname);
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