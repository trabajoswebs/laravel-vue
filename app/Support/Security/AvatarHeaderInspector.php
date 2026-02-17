<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Domain\Security\Rules\AvatarHeaderRules;
use Illuminate\Http\Request;

/**
 * Inspector de headers para validación de seguridad de avatares.
 * 
 * Utiliza las reglas de dominio para verificar que los headers de respuesta
 * cumplan con los requisitos de seguridad establecidos para avatares.
 */
final class AvatarHeaderInspector
{
    public function __construct(
        private readonly AvatarHeaderRules $rules,  // Reglas de seguridad para validar headers
    ) {}

    /**
     * Inspecciona las cabeceras de un array o de un Request y devuelve issues detectados.
     *
     * @param array<string,mixed>|Request $source Origen de los headers (array o Request)
     * @return array<int,array<string,mixed>> Lista de problemas detectados con detalles
     */
    public function detectIssues(array|Request $source): array
    {
        return $this->rules->detectIssues($this->extractHeaders($source));
    }

    /**
     * Verifica si las cabeceras contienen problemas de seguridad.
     *
     * @param array<string,mixed>|Request $source Origen de los headers (array o Request)
     * @return bool True si hay problemas de seguridad, false en caso contrario
     */
    public function hasIssues(array|Request $source): bool
    {
        return $this->rules->hasIssues($this->extractHeaders($source));
    }

    /**
     * Extrae los headers de un Request o array.
     *
     * @param array<string,mixed>|Request $source Origen de los headers
     * @return array<string,mixed> Headers extraídos
     */
    private function extractHeaders(array|Request $source): array
    {
        if ($source instanceof Request) {
            return $source->headers->all();
        }

        return $source;
    }
}
