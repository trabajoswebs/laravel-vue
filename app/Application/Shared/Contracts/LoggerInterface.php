<?php

declare(strict_types=1);

namespace App\Application\Shared\Contracts;

/**
 * Interfaz para servicios de logging que siguen el estándar PSR-3.
 */
interface LoggerInterface
{
    /**
     * Registra un mensaje de nivel debug.
     *
     * @param string $message Mensaje a registrar
     * @param array $context Datos adicionales para contexto
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Registra un mensaje de nivel info.
     *
     * @param string $message Mensaje a registrar
     * @param array $context Datos adicionales para contexto
     */
    public function info(string $message, array $context = []): void;

    /**
     * Registra un mensaje de nivel notice.
     *
     * @param string $message Mensaje a registrar
     * @param array $context Datos adicionales para contexto
     */
    public function notice(string $message, array $context = []): void;

    /**
     * Registra un mensaje de nivel warning.
     *
     * @param string $message Mensaje a registrar
     * @param array $context Datos adicionales para contexto
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Registra un mensaje de nivel error.
     *
     * @param string $message Mensaje a registrar
     * @param array $context Datos adicionales para contexto
     */
    public function error(string $message, array $context = []): void;

    /**
     * Registra un mensaje de nivel critical.
     *
     * @param string $message Mensaje a registrar
     * @param array $context Datos adicionales para contexto
     */
    public function critical(string $message, array $context = []): void;

    /**
     * Registra un mensaje de nivel alert.
     *
     * @param string $message Mensaje a registrar
     * @param array $context Datos adicionales para contexto
     */
    public function alert(string $message, array $context = []): void;
}
