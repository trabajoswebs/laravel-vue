<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Security;

use App\Application\Media\Contracts\FileConstraints;
use App\Infrastructure\Media\Security\Upload\UploadSecurityLogger;
use App\Infrastructure\Media\Upload\Exceptions\UploadValidationException;

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
        if (! $this->matchesAllowedSignature($hexHead, $constraints)) {
            $this->logger->magicbytesFailed($context + ['reason' => 'signature_mismatch']);
            throw new UploadValidationException('Uploaded file does not match allowed magic signatures.');
        }

        // Verifica si hay marcadores de polyglot (cabeceras mixtas)
        if ($constraints->preventPolyglotFiles && $this->hasPolyglotMarkers($head)) {
            $this->logger->magicbytesFailed($context + ['reason' => 'polyglot_detected']);
            throw new UploadValidationException('Polyglot payload detected in uploaded file.');
        }
    }

    /**
     * Comprueba si la cabecera coincide con alguna firma permitida.
     * 
     * @param string $hexHead Cabecera hexadecimal del archivo
     * @param FileConstraints $constraints Restricciones de archivo
     * @return bool True si coincide con alguna firma permitida
     */
    private function matchesAllowedSignature(string $hexHead, FileConstraints $constraints): bool
    {
        foreach ($constraints->allowedMagicSignatures as $signature => $label) {
            $length = strlen($signature);
            if ($length === 0) {
                continue;
            }

            if (str_starts_with($hexHead, $signature)) {
                return true;
            }
        }

        return false;
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

        $php = strpos($lower, '<?') !== false;
        $pdf = str_contains($head, '%PDF');
        $zip = str_contains($head, "PK\x03\x04");

        // Si combina PHP con otra cabecera conocida, lo consideramos polyglot.
        if ($php && ($pdf || $zip)) {
            return true;
        }

        return false;
    }
}
