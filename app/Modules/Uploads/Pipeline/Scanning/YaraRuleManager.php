<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Scanning;

use App\Infrastructure\Uploads\Pipeline\Security\Exceptions\InvalidRuleException;

/**
 * Define las operaciones para gestionar reglas YARA y su integridad.
 */
interface YaraRuleManager
{
    /**
     * @return list<string> Rutas absolutas de los archivos de reglas disponibles.
     */
    public function getRuleFiles(): array;

    /**
     * Identificador/versionado de las reglas cargadas (hash, tag, etc.).
     */
    public function getCurrentVersion(): string;

    /**
     * Lanza InvalidRuleException si el hash calculado no coincide con el esperado.
     *
     * @throws InvalidRuleException
     */
    public function validateIntegrity(): void;

    /**
     * Hash esperado proveniente del archivo rules.sha256 o configuración.
     */
    public function getExpectedHash(): string;
}
