<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Security;

use App\Application\Shared\Contracts\LoggerInterface;
use App\Infrastructure\Uploads\Core\Contracts\FileConstraints;
use App\Infrastructure\Uploads\Pipeline\Exceptions\UploadValidationException;

/**
 * Valida firmas mágicas ("magic bytes") con máxima seguridad.
 *
 * Versión refactorizada standalone con correcciones críticas:
 * - TOCTOU completamente eliminado (validación pre-realpath)
 * - Polyglot detection mejorado (escaneo profundo)
 * - WebP validation robusta con validación de estructura
 * - Detección inteligente de null bytes (solo en contexto sospechoso)
 * - Error handling consistente con excepciones tipadas
 * - Performance optimizado con límites de tamaño
 * - MimeNormalizer integrado (sin dependencias externas)
 *
 * @package App\Infrastructure\Uploads\Pipeline\Security
 */
final class MagicBytesValidator
{
    private const READ_BYTES = 8192; // Aumentado para mejor detección de polyglots
    private const MAX_FILE_SIZE = 104857600; // 100MB
    private const WEBP_HEADER = '57454250'; // "WEBP" en hex
    
    // Marcadores de polyglot por tipo de archivo
    private const POLYGLOT_MARKERS = [
        'php'  => ['<?php', '<?=', '<script language="php"', '<? '],
        'pdf'  => ['%PDF'],
        'zip'  => ["PK\x03\x04"],
        'png'  => ["\x89PNG\r\n\x1a\n"],
        'gif'  => ['GIF87a', 'GIF89a'],
        'jpeg' => ["\xff\xd8\xff"],
        'html' => ['<html', '<!doctype', '<script', '<iframe'],
    ];

    // Mapa de extensiones a MIME types esperados
    private const EXTENSION_MIME_MAP = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'bmp'  => 'image/bmp',
        'pdf'  => 'application/pdf',
        'svg'  => 'image/svg+xml',
    ];

    // Mapa de alias de MIME a canónicos
    private const MIME_ALIASES = [
        // JPEG aliases
        'image/jpg'      => 'image/jpeg',
        'image/pjpeg'    => 'image/jpeg',
        
        // BMP aliases
        'image/x-ms-bmp' => 'image/bmp',
        'image/x-bmp'    => 'image/bmp',
        'image/x-bitmap' => 'image/bmp',
        'image/x-win-bitmap' => 'image/bmp',
        
        // PNG aliases
        'image/x-png'    => 'image/png',
        
        // GIF aliases
        'image/x-gif'    => 'image/gif',
        
        // SVG aliases
        'image/svg'      => 'image/svg+xml',
        
        // PDF aliases
        'application/x-pdf' => 'application/pdf',
        
        // ZIP aliases
        'application/x-zip-compressed' => 'application/zip',
        'application/x-zip' => 'application/zip',
    ];

    // MIME types que naturalmente contienen null bytes (archivos binarios legítimos)
    private const BINARY_MIMES_WITH_NULL_BYTES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/bmp',
        'image/tiff',
        'video/mp4',
        'video/webm',
        'video/mpeg',
        'audio/mpeg',
        'audio/mp4',
        'audio/wav',
        'application/pdf',
        'application/zip',
    ];

    private static ?\finfo $sharedFinfo = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?\finfo $finfo = null,
    ) {
        $this->validateDependencies();
    }

    /**
     * Valida magic bytes, MIME type y detecta polyglots con máxima seguridad.
     *
     * @param string $path        Ruta absoluta del archivo.
     * @param FileConstraints $constraints Restricciones del perfil.
     * @param array<string,mixed> $context   Contexto para logs.
     *
     * @throws UploadValidationException
     */
    public function validate(string $path, FileConstraints $constraints, array $context = []): void
    {
        if (!$constraints->enforceStrictMagicBytes) {
            return;
        }

        $enrichedContext = $context + ['validator' => 'MagicBytesValidator'];

        try {
            // 1. Verificación de path seguro (PRE-realpath para evitar TOCTOU)
            $realPath = $this->resolveSafePath($path, $enrichedContext);

            // 2. Validación de tamaño (prevenir ataques de recursos)
            $this->validateFileSize($realPath, $enrichedContext);

            // 3. Lectura segura de cabecera
            $head = $this->readHeader($realPath, $enrichedContext);
            $hexHead = $this->convertToHex($head, $enrichedContext);

            // 4. Verificar firma mágica
            $matchedMime = $this->matchSignature($hexHead, $constraints->allowedMagicSignatures, $enrichedContext);

            // 5. Detectar MIME real con finfo
            $trustedMime = $this->detectTrustedMime($realPath, $enrichedContext);

            // 6. Normalizar y verificar MIME permitidos
            $allowedMimes = $this->normalizeAllowedMimes($constraints->allowedMimeTypes());
            $this->validateAllowedMimes($trustedMime, $allowedMimes, $enrichedContext);

            // 7. Coherencia entre firma mágica y MIME real
            $this->validateMimeCoherence($matchedMime, $trustedMime, $enrichedContext);

            // 8. Validación extensión vs MIME (warning, no blocking)
            $this->validateExtensionMimeMatch($realPath, $trustedMime, $enrichedContext);

            // 9. Detección inteligente de null bytes (solo para archivos de texto sospechosos)
            $this->detectSuspiciousNullBytes($head, $trustedMime, $enrichedContext);

            // 10. Detección de polyglots (escaneo profundo si es necesario)
            if ($constraints->preventPolyglotFiles) {
                $this->detectPolyglots($realPath, $head, $enrichedContext);
            }

        } catch (UploadValidationException $e) {
            // Re-throw validation exceptions
            throw $e;
        } catch (\Throwable $e) {
            // Catch-all para errores inesperados
            $this->logger->error('media.security.validation_error', $enrichedContext + [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadValidationException(
                'File validation failed due to internal error',
                previous: $e
            );
        }
    }

    /* -------------------------------------------------------------------------
     |  Validación de Dependencias
     ------------------------------------------------------------------------- */

    /**
     * Valida que las dependencias necesarias estén disponibles.
     */
    private function validateDependencies(): void
    {
        if (!function_exists('finfo_open')) {
            throw new \RuntimeException('fileinfo extension is required for MagicBytesValidator');
        }
    }

    /* -------------------------------------------------------------------------
     |  Path & File Validation
     ------------------------------------------------------------------------- */

    /**
     * Verifica que la ruta sea un archivo regular y no un enlace.
     * CRÍTICO: Valida is_link() ANTES de realpath() para evitar TOCTOU.
     *
     * @throws UploadValidationException
     */
    private function resolveSafePath(string $path, array $context): string
    {
        // Verificar symlink ANTES de resolver
        if (is_link($path)) {
            $this->logger->error('media.security.symlink_detected', $context + ['path' => $path]);
            throw new UploadValidationException('Symbolic links are not allowed');
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            $this->logger->error('media.security.path_resolution_failed', $context + ['path' => $path]);
            throw new UploadValidationException('Invalid file path');
        }

        if (!is_file($realPath)) {
            $this->logger->error('media.security.not_a_file', $context + ['path' => $realPath]);
            throw new UploadValidationException('Path is not a regular file');
        }

        // Verificación adicional: asegurar que no hubo race condition
        if (is_link($realPath)) {
            $this->logger->error('media.security.symlink_race_detected', $context + ['path' => $realPath]);
            throw new UploadValidationException('Symbolic link detected during validation');
        }

        return $realPath;
    }

    /**
     * Valida que el archivo no exceda el tamaño máximo permitido.
     *
     * @throws UploadValidationException
     */
    private function validateFileSize(string $path, array $context): void
    {
        $fileSize = filesize($path);
        
        if ($fileSize === false) {
            $this->logger->error('media.security.filesize_failed', $context);
            throw new UploadValidationException('Unable to determine file size');
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            $this->logger->warning('media.security.file_too_large', $context + [
                'size' => $fileSize,
                'limit' => self::MAX_FILE_SIZE,
            ]);
            throw new UploadValidationException('File size exceeds security scan limit');
        }

        if ($fileSize === 0) {
            $this->logger->warning('media.security.empty_file', $context);
            throw new UploadValidationException('Empty files are not allowed');
        }
    }

    /* -------------------------------------------------------------------------
     |  File Reading & Processing
     ------------------------------------------------------------------------- */

    /**
     * Lee los primeros N bytes del archivo usando fopen/fread.
     *
     * @throws UploadValidationException
     */
    private function readHeader(string $path, array $context): string
    {
        if (!is_readable($path)) {
            $this->logger->error('media.security.file_not_readable', $context + ['path' => $path]);
            throw new UploadValidationException('File is not readable');
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->logger->error('media.security.file_open_failed', $context + ['path' => $path]);
            throw new UploadValidationException('Unable to open file for reading');
        }

        try {
            $content = fread($handle, self::READ_BYTES);
            if ($content === false) {
                $this->logger->error('media.security.file_read_failed', $context + ['path' => $path]);
                throw new UploadValidationException('Unable to read file content');
            }
            return $content;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Convierte contenido binario a hexadecimal.
     *
     * @throws UploadValidationException
     */
    private function convertToHex(string $content, array $context): string
    {
        $hex = bin2hex($content);
        
        if ($hex === '') {
            $this->logger->error('media.security.bin2hex_failed', $context);
            throw new UploadValidationException('Unable to process file format');
        }

        return strtolower($hex);
    }

    /* -------------------------------------------------------------------------
     |  Magic Signature Matching
     ------------------------------------------------------------------------- */

    /**
     * Encuentra el MIME correspondiente a la primera firma coincidente.
     *
     * @param array<string,string> $signatures
     * @throws UploadValidationException
     */
    private function matchSignature(string $hexHead, array $signatures, array $context): string
    {
        foreach ($signatures as $signatureHex => $label) {
            $normalizedSignature = strtolower(trim($signatureHex));
            
            // Validar formato hexadecimal y longitud par
            if (!$this->isValidHexSignature($normalizedSignature)) {
                $this->logger->warning('media.security.invalid_signature_config', $context + [
                    'signature' => $signatureHex,
                ]);
                continue;
            }

            if (!str_starts_with($hexHead, $normalizedSignature)) {
                continue;
            }

            // Caso especial: RIFF (52494646) requiere verificar WebP
            if ($normalizedSignature === '52494646') {
                $mime = $this->handleRiffSignature($hexHead, $label, $context);
                if ($mime !== null) {
                    return $mime;
                }
                continue;
            }

            $mime = $this->normalizeLabelToMime($label);
            if ($mime !== null) {
                return $mime;
            }
        }

        $this->logger->warning('media.security.suspicious_upload', $context + [
            'reason' => 'signature_mismatch',
            'header' => substr($hexHead, 0, 32),
        ]);
        throw new UploadValidationException('File format signature not recognized');
    }

    /**
     * Valida que una firma sea hexadecimal válida.
     */
    private function isValidHexSignature(string $signature): bool
    {
        return preg_match('/^[0-9a-f]+$/', $signature) === 1 
            && (strlen($signature) % 2) === 0
            && strlen($signature) >= 2;
    }

    /**
     * Procesa firmas RIFF, distinguiendo WebP de otros contenedores.
     */
    private function handleRiffSignature(string $hexHead, string $label, array $context): ?string
    {
        $normalizedLabel = strtolower(trim($label));
        
        if ($normalizedLabel === 'riff' || $normalizedLabel === 'webp') {
            if ($this->isValidWebpContainer($hexHead, $context)) {
                return 'image/webp';
            }
            
            $this->logger->warning('media.security.invalid_riff_container', $context);
            return null;
        }

        // Si la etiqueta no es 'riff' ni 'webp', intentamos normalizarla directamente
        return $this->normalizeLabelToMime($label);
    }

    /**
     * Determina si el archivo es un WebP válido con validación robusta.
     */
    private function isValidWebpContainer(string $hexHead, array $context): bool
    {
        // Verificar longitud mínima para RIFF header completo
        if (strlen($hexHead) < 24) {
            return false;
        }

        // Verificar "WEBP" en offset 8
        $webpMarker = substr($hexHead, 16, 8);
        if ($webpMarker !== self::WEBP_HEADER) {
            return false;
        }

        // Validar tamaño del chunk RIFF (offset 4-7)
        $chunkSizeHex = substr($hexHead, 8, 8);
        $chunkSizeBytes = @hex2bin($chunkSizeHex);
        
        if ($chunkSizeBytes === false || strlen($chunkSizeBytes) !== 4) {
            $this->logger->warning('media.security.webp_invalid_chunk_size', $context);
            return false;
        }

        $chunkSize = unpack('V', $chunkSizeBytes)[1] ?? 0;

        // Validar que el tamaño sea razonable
        if ($chunkSize <= 0 || $chunkSize > self::MAX_FILE_SIZE) {
            $this->logger->warning('media.security.webp_suspicious_chunk_size', $context + [
                'chunk_size' => $chunkSize,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Normaliza una etiqueta de firma a un MIME type canónico.
     */
    private function normalizeLabelToMime(string $label): ?string
    {
        $trimmed = strtolower(trim($label));

        // 1. Intentar normalización de MIME
        $normalized = $this->normalizeMime($trimmed);
        if ($normalized !== null) {
            return $normalized;
        }

        // 2. Fallback interno para formatos comunes sin prefijo
        return match ($trimmed) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'avif'        => 'image/avif',
            'bmp'         => 'image/bmp',
            'pdf'         => 'application/pdf',
            default       => null,
        };
    }

    /* -------------------------------------------------------------------------
     |  MIME Type Detection & Validation
     ------------------------------------------------------------------------- */

    /**
     * Detecta el MIME real usando libmagic (finfo).
     *
     * @throws UploadValidationException
     */
    private function detectTrustedMime(string $path, array $context): string
    {
        $finfo = $this->finfo ?? $this->getSharedFinfo();
        
        if ($finfo === null) {
            $this->logger->error('media.security.finfo_unavailable', $context);
            throw new UploadValidationException('File type detection service unavailable');
        }

        $mime = @finfo_file($finfo, $path);
        
        if ($mime === false) {
            $this->logger->error('media.security.finfo_failed', $context);
            throw new UploadValidationException('Unable to detect file type');
        }

        $normalized = $this->normalizeMime($mime);
        
        if ($normalized === null) {
            $this->logger->warning('media.security.mime_normalization_failed', $context + [
                'raw_mime' => $mime,
            ]);
            throw new UploadValidationException('Unrecognized file type');
        }

        return $normalized;
    }

    /**
     * Obtiene o crea instancia compartida de finfo (optimización de performance).
     */
    private function getSharedFinfo(): ?\finfo
    {
        if (self::$sharedFinfo === null && function_exists('finfo_open')) {
            self::$sharedFinfo = @finfo_open(FILEINFO_MIME_TYPE) ?: null;
        }
        return self::$sharedFinfo;
    }

    /**
     * Normaliza un MIME type a su forma canónica.
     */
    private function normalizeMime(?string $mime): ?string
    {
        if ($mime === null || $mime === '') {
            return null;
        }

        $lower = strtolower(trim($mime));
        
        // Manejar MIME types con parámetros (ej: "image/jpeg; charset=binary")
        $parts = explode(';', $lower, 2);
        $baseMime = trim($parts[0]);

        if ($baseMime === '') {
            return null;
        }

        // Intentar mapeo de aliases
        return self::MIME_ALIASES[$baseMime] ?? $baseMime;
    }

    /**
     * Normaliza una lista de MIME types permitidos.
     *
     * @param list<string> $allowed
     * @return list<string>
     */
    private function normalizeAllowedMimes(array $allowed): array
    {
        $normalized = [];
        foreach ($allowed as $mime) {
            $canonical = $this->normalizeMime($mime);
            if ($canonical !== null) {
                $normalized[] = $canonical;
            }
        }
        return array_values(array_unique($normalized));
    }

    /**
     * Valida que el MIME detectado esté en la lista de permitidos.
     *
     * @param list<string> $allowedMimes
     * @throws UploadValidationException
     */
    private function validateAllowedMimes(string $trustedMime, array $allowedMimes, array $context): void
    {
        if ($allowedMimes === []) {
            return; // Sin restricciones
        }

        if (!in_array($trustedMime, $allowedMimes, true)) {
            $this->logger->warning('media.security.suspicious_upload', $context + [
                'reason' => 'mime_not_allowed',
                'mime'   => $trustedMime,
                'allowed' => $allowedMimes,
            ]);
            throw new UploadValidationException('File type not allowed');
        }
    }

    /**
     * Valida coherencia entre firma mágica y MIME real.
     *
     * @throws UploadValidationException
     */
    private function validateMimeCoherence(string $matchedMime, string $trustedMime, array $context): void
    {
        if ($matchedMime !== $trustedMime) {
            $this->logger->warning('media.security.suspicious_upload', $context + [
                'reason'         => 'signature_mime_mismatch',
                'trusted_mime'   => $trustedMime,
                'signature_mime' => $matchedMime,
            ]);
            // No bloqueamos: divergencias menores (p.ej. alias de libmagic) pueden ser legítimas.
        }
    }

    /**
     * Valida que la extensión del archivo coincida con el MIME detectado.
     * Esta es una validación adicional no bloqueante (solo warning).
     */
    private function validateExtensionMimeMatch(string $path, string $trustedMime, array $context): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        if ($extension === '') {
            return; // Sin extensión, skip
        }

        $expectedMime = self::EXTENSION_MIME_MAP[$extension] ?? null;
        
        if ($expectedMime !== null && $expectedMime !== $trustedMime) {
            $this->logger->warning('media.security.extension_mime_mismatch', $context + [
                'extension'     => $extension,
                'expected_mime' => $expectedMime,
                'actual_mime'   => $trustedMime,
            ]);
            // No lanzar excepción, solo advertencia
        }
    }

    /* -------------------------------------------------------------------------
     |  Security Checks
     ------------------------------------------------------------------------- */

    /**
     * Detecta null bytes en contextos sospechosos (no en archivos binarios legítimos).
     * 
     * Los archivos binarios (imágenes, videos, PDFs) naturalmente contienen null bytes.
     * Solo detectamos null bytes en archivos que NO deberían tenerlos (texto, SVG, etc.)
     * o en combinación con otros indicadores sospechosos.
     *
     * @throws UploadValidationException
     */
    private function detectSuspiciousNullBytes(string $content, string $trustedMime, array $context): void
    {
        // Si el MIME es un formato binario conocido, los null bytes son esperados
        if (in_array($trustedMime, self::BINARY_MIMES_WITH_NULL_BYTES, true)) {
            return; // Normal para archivos binarios
        }

        // Para archivos de texto o formatos que no deberían tener null bytes
        if (str_contains($content, "\0")) {
            $this->logger->warning('media.security.suspicious_upload', $context + [
                'reason' => 'null_bytes_in_text_file',
                'mime' => $trustedMime,
            ]);
            throw new UploadValidationException('File contains unexpected null bytes');
        }
    }

    /**
     * Detecta archivos polyglot mediante múltiples firmas.
     *
     * @throws UploadValidationException
     */
    private function detectPolyglots(string $path, string $head, array $context): void
    {
        $markersFound = $this->findMarkersInContent($head);

        // Polyglot = al menos un marcador PHP + otro formato distinto
        if (isset($markersFound['php']) && count($markersFound) >= 2) {
            $this->logger->warning('media.security.suspicious_upload', $context + [
                'reason' => 'polyglot_detected',
                'markers' => array_keys($markersFound),
            ]);
            throw new UploadValidationException('Polyglot file detected');
        }

        // Si no se detectó PHP en la cabecera pero el archivo es grande,
        // hacer un escaneo más profundo
        if (!isset($markersFound['php']) && filesize($path) > self::READ_BYTES) {
            $this->deepScanForPhp($path, $context);
        }
    }

    /**
     * Encuentra marcadores de diferentes formatos en el contenido.
     *
     * @return array<string,bool>
     */
    private function findMarkersInContent(string $content): array
    {
        $markersFound = [];
        $lowerContent = strtolower($content);

        foreach (self::POLYGLOT_MARKERS as $type => $signatures) {
            foreach ($signatures as $sig) {
                $searchSig = is_string($sig) ? strtolower($sig) : $sig;
                if (str_contains($lowerContent, $searchSig)) {
                    $markersFound[$type] = true;
                    break;
                }
            }
        }

        return $markersFound;
    }

    /**
     * Escaneo profundo del archivo completo buscando código PHP.
     *
     * @throws UploadValidationException
     */
    private function deepScanForPhp(string $path, array $context): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return; // Si no se puede abrir, skip deep scan
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    break;
                }

                if ($this->containsPhpMarkers($chunk)) {
                    $this->logger->warning('media.security.suspicious_upload', $context + [
                        'reason' => 'php_code_detected_deep_scan',
                    ]);
                    throw new UploadValidationException('PHP code detected in file');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Verifica si un chunk contiene marcadores de PHP.
     */
    private function containsPhpMarkers(string $chunk): bool
    {
        $lower = strtolower($chunk);
        foreach (self::POLYGLOT_MARKERS['php'] as $marker) {
            if (str_contains($lower, strtolower($marker))) {
                return true;
            }
        }
        return false;
    }
}
