<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Security\Upload;

use App\Support\Logging\SecurityLogger;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;

/**
 * Logger centralizado para eventos de seguridad en subidas.
 *
 * Provee métodos semánticos que agregan siempre el correlation_id/contexto mínimo.
 */
final class UploadSecurityLogger
{
    private readonly MediaSecurityLogger $logger;

    public function __construct(
        ?MediaSecurityLogger $logger = null,
    ) {
        $this->logger = $logger ?? app(MediaSecurityLogger::class);
    }

    /**
     * Registra el inicio de un proceso de subida.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function started(array $context): void
    {
        $this->logger->debug('media.pipeline.started', $context);
    }

    /**
     * Registra que un archivo ha sido colocado en cuarentena.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function quarantined(array $context): void
    {
        $this->logger->debug('media.pipeline.quarantined', $context);
    }

    /**
     * Registra fallo en la validación de magic bytes.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function magicbytesFailed(array $context): void
    {
        $this->logger->warning('media.security.suspicious_upload', $context);
    }

    /**
     * Registra el inicio del escaneo de seguridad.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function scanStarted(array $context): void
    {
        $this->logger->debug('media.security.scan_started', $context);
    }

    /**
     * Registra que el escaneo de seguridad ha pasado.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function scanPassed(array $context): void
    {
        $this->logger->debug('media.security.scan_passed', $context);
    }

    /**
     * Registra que el escaneo de seguridad ha fallado.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function scanFailed(array $context): void
    {
        $this->logger->warning('media.security.scan_failed', $context);
    }

    /**
     * Registra que se ha detectado un virus en el archivo.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function virusDetected(array $context): void
    {
        $this->logger->error('media.security.virus_detected', $context);
    }

    /**
     * Registra que un archivo ha sido normalizado.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function normalized(array $context): void
    {
        $this->logger->debug('media.pipeline.normalized', $context);
    }

    /**
     * Registra que un archivo ha sido persistido.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function persisted(array $context): void
    {
        $this->logger->info('media.pipeline.persisted', $context);
    }

    /**
     * Registra que una validación ha fallado.
     *
     * @param array<string,mixed> $context Contexto adicional para el log
     */
    public function validationFailed(array $context): void
    {
        $this->logger->error('media.pipeline.failed', $context);
    }

    /**
     * Registra accesos a media servido.
     */
    public function accessed(array $context): void
    {
        $this->logger->debug('media.accessed', $context);
    }

    /**
     * Registra validación correcta de reglas YARA.
     */
    public function yaraRulesValidated(array $context): void
    {
        $this->logger->debug('media.security.yara_rules_valid', $context);
    }

    /**
     * Registra fallo de integridad en reglas YARA.
     */
    public function yaraRulesFailed(array $context): void
    {
        $this->logger->critical('media.security.yara_rules_failed', $context);
    }
}
