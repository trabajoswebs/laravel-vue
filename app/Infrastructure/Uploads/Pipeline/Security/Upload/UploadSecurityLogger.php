<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Security\Upload;

use Illuminate\Support\Facades\Log;

/**
 * Logger centralizado para eventos de seguridad en subidas.
 *
 * Provee métodos semánticos que agregan siempre el correlation_id/contexto mínimo.
 */
final class UploadSecurityLogger
{
    /**
     * Registra el inicio de un proceso de subida.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function started(array $context): void
    {
        Log::info('upload.started', $context);
    }

    /**
     * Registra que un archivo ha sido colocado en cuarentena.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function quarantined(array $context): void
    {
        Log::info('upload.quarantined', $context);
    }

    /**
     * Registra fallo en la validación de magic bytes.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function magicbytesFailed(array $context): void
    {
        Log::warning('upload.magicbytes_failed', $context);
    }

    /**
     * Registra el inicio del escaneo de seguridad.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function scanStarted(array $context): void
    {
        Log::info('upload.scan_started', $context);
    }

    /**
     * Registra que el escaneo de seguridad ha pasado.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function scanPassed(array $context): void
    {
        Log::info('upload.scan_passed', $context);
    }

    /**
     * Registra que el escaneo de seguridad ha fallado.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function scanFailed(array $context): void
    {
        Log::warning('upload.scan_failed', $context);
    }

    /**
     * Registra que se ha detectado un virus en el archivo.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function virusDetected(array $context): void
    {
        Log::error('upload.virus_detected', $context);
    }

    /**
     * Registra que un archivo ha sido normalizado.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function normalized(array $context): void
    {
        Log::info('upload.normalized', $context);
    }

    /**
     * Registra que un archivo ha sido persistido.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function persisted(array $context): void
    {
        Log::info('upload.persisted', $context);
    }

    /**
     * Registra que una validación ha fallado.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function validationFailed(array $context): void
    {
        Log::warning('upload.validation_failed', $context);
    }

    /**
     * Registra accesos a media servido.
     */
    public function accessed(array $context): void
    {
        Log::info('media.accessed', $context);
    }

    /**
     * Registra validación correcta de reglas YARA.
     */
    public function yaraRulesValidated(array $context): void
    {
        Log::info('upload.yara_rules_valid', $context);
    }

    /**
     * Registra fallo de integridad en reglas YARA.
     */
    public function yaraRulesFailed(array $context): void
    {
        Log::critical('upload.yara_rules_failed', $context);
    }
}
