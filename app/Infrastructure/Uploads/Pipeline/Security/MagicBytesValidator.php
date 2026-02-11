<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Security;

use App\Infrastructure\Uploads\Core\Contracts\FileConstraints;
use App\Infrastructure\Uploads\Pipeline\Security\Upload\UploadSecurityLogger;
use App\Infrastructure\Uploads\Pipeline\Exceptions\UploadValidationException;

/**
 * Valida las firmas ("magic bytes") de un archivo según las restricciones del perfil.
 *
 * Uso:
 * $validator->validate($path, $constraints, ['correlation_id' => $uuid, 'profile' => 'avatar']);
 */
final class MagicBytesValidator
{
    // Número de bytes a leer para la validación de magic bytes
    private const READ_BYTES = 512;
    private const WEBP_HEX_AT_OFFSET_8 = '57454250';

    /**
     * Constructor del validador de magic bytes.
     * 
     * @param UploadSecurityLogger $logger Servicio para logging de seguridad
     */
    public function __construct(private readonly UploadSecurityLogger $logger) {}

    /**
     * Valida magic bytes y detecta polyglots simples.
     *
     * @param string $path Ruta absoluta del archivo a validar.
     * @param FileConstraints $constraints Restricciones a aplicar.
     * @param array<string,mixed> $context Contexto para logging (correlation_id, profile, user_id, etc.).
     *
     * @throws UploadValidationException Si la validación falla.
     */
    public function validate(string $path, FileConstraints $constraints, array $context = []): void
    {
        // Si no se requiere validación estricta de magic bytes, salimos
        if (! $constraints->enforceStrictMagicBytes) {
            return;
        }

        // Lee los primeros bytes del archivo
        $head = @file_get_contents($path, false, null, 0, self::READ_BYTES);
        if ($head === false || $head === '') {
            throw new UploadValidationException('Unable to read uploaded file for magic bytes validation.');
        }

        // Convierte a representación hexadecimal
        $hexHead = bin2hex($head);
        if ($hexHead === '') {
            throw new UploadValidationException('Unable to compute magic bytes for uploaded file.');
        }

        // Verifica si coincide con alguna firma permitida
        $matchedMime = $this->matchedSignatureMime($hexHead, $constraints->allowedMagicSignatures);
        if ($matchedMime === null) {
            $this->logger->magicbytesFailed($context + ['reason' => 'signature_mismatch']);
            throw new UploadValidationException('Uploaded file format is invalid.');
        }

        $trustedMime = $this->detectTrustedMime($path);
        if ($trustedMime === null) {
            $this->logger->magicbytesFailed($context + ['reason' => 'mime_detection_failed']);
            throw new UploadValidationException('Uploaded file format is invalid.');
        }

        $rawAllowedMimes = $constraints->allowedMimeTypes();
        $allowedMimes = $this->normalizeAllowedMimes($rawAllowedMimes);
        if ($rawAllowedMimes !== [] && $allowedMimes === []) {
            $this->logger->magicbytesFailed($context + [
                'reason' => 'allowed_mime_config_invalid',
            ]);
            throw new UploadValidationException('Uploaded file format is invalid.');
        }

        if ($allowedMimes !== [] && !in_array($trustedMime, $allowedMimes, true)) {
            $this->logger->magicbytesFailed($context + [
                'reason' => 'mime_not_allowed',
                'mime' => $trustedMime,
            ]);
            throw new UploadValidationException('Uploaded file format is invalid.');
        }

        if ($matchedMime !== $trustedMime) {
            $this->logger->magicbytesFailed($context + [
                'reason' => 'signature_mime_mismatch',
                'mime' => $trustedMime,
                'signature_mime' => $matchedMime,
            ]);
            throw new UploadValidationException('Uploaded file format is invalid.');
        }

        // Verifica si hay marcadores de polyglot (cabeceras mixtas)
        if ($constraints->preventPolyglotFiles && $this->hasPolyglotMarkers($head)) {
            $this->logger->magicbytesFailed($context + ['reason' => 'polyglot_detected']);
            throw new UploadValidationException('Uploaded file format is invalid.');
        }
    }

    /**
     * Comprueba si la cabecera coincide con alguna firma permitida.
     * 
     * @param string $hexHead Cabecera hexadecimal del archivo
     * @param FileConstraints $constraints Restricciones de archivo
     * @return bool True si coincide con alguna firma permitida
     */
    private function matchedSignatureMime(string $hexHead, array $allowedSignatures): ?string
    {
        foreach ($allowedSignatures as $signature => $label) {
            $normalizedSignature = strtolower(trim((string) $signature));
            if (! preg_match('/^[0-9a-f]+$/', $normalizedSignature) || (strlen($normalizedSignature) % 2) !== 0) {
                continue;
            }

            $length = strlen($normalizedSignature);
            if ($length === 0) {
                continue;
            }

            if (str_starts_with($hexHead, $normalizedSignature)) {
                // RIFF sin WEBP suele generar falsos positivos para otros contenedores.
                $rawLabel = strtolower(trim((string) $label));
                if ($normalizedSignature === '52494646' && $rawLabel === 'riff') {
                    if (! $this->isWebpContainer($hexHead)) {
                        continue;
                    }
                    return 'image/webp';
                }

                $normalizedMime = MimeNormalizer::normalize((string) $label)
                    ?? $this->normalizeSignatureLabel($rawLabel);
                if ($normalizedMime === null) {
                    continue;
                }

                return $normalizedMime;
            }
        }

        return null;
    }

    /**
     * Detecta polyglots básicos: cabeceras mixtas (<?php + PDF/ZIP, etc.).
     * 
     * @param string $head Cabecera del archivo
     * @return bool True si se detecta un polyglot
     */
    private function hasPolyglotMarkers(string $head): bool
    {
        $lower = strtolower($head);

        // Evita falsos positivos: buscamos tokens PHP explícitos, no cualquier "<?".
        $php = str_contains($lower, '<?php') || str_contains($lower, '<?=');
        $pdf = str_contains($head, '%PDF');
        $zip = str_contains($head, "PK\x03\x04");

        // Si combina PHP con otra cabecera conocida, lo consideramos polyglot.
        if ($php && ($pdf || $zip)) {
            return true;
        }

        return false;
    }

    private function isWebpContainer(string $hexHead): bool
    {
        // "WEBP" aparece a partir del byte 8 (offset hexadecimal 16).
        return strlen($hexHead) >= 24
            && substr($hexHead, 16, strlen(self::WEBP_HEX_AT_OFFSET_8)) === self::WEBP_HEX_AT_OFFSET_8;
    }

    private function detectTrustedMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        return MimeNormalizer::normalize(is_string($mime) ? $mime : null);
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private function normalizeAllowedMimes(array $allowed): array
    {
        $normalized = [];
        foreach ($allowed as $mime) {
            $canonical = MimeNormalizer::normalize($mime);
            if ($canonical !== null) {
                $normalized[] = $canonical;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeSignatureLabel(string $label): ?string
    {
        return match ($label) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'avif' => 'image/avif',
            default => null,
        };
    }
}
