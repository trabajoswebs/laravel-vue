<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para el DTO (Data Transfer Object) de resultado de reemplazo de medios.
namespace App\Domain\Media\DTO;

// 3. Importación de la clase Media de la librería Spatie.
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Resultado tipado de un reemplazo de media.
 *
 * Contiene el nuevo media, el snapshot de artefactos previos y las conversions esperadas.
 *
 * @immutable
 */
final class ReplacementResult
{
    /**
     * Constructor privado para evitar instanciación directa desde fuera de la clase.
     *
     * @param Media $media El nuevo modelo Media resultante del reemplazo.
     * @param ReplacementSnapshot $snapshot Instantánea de artefactos anteriores (p. ej., de medios eliminados).
     * @param ConversionExpectations $expectations Las conversiones esperadas para el nuevo medio.
     */
    private function __construct(
        public readonly Media $media,              // 4. El nuevo modelo Media.
        public readonly ReplacementSnapshot $snapshot, // 5. Instantánea de artefactos anteriores.
        public readonly ConversionExpectations $expectations, // 6. Expectativas de conversiones del nuevo medio.
    ) {}

    /**
     * Método de fábrica estática para crear una nueva instancia de ReplacementResult.
     *
     * @param Media $media El nuevo modelo Media resultante del reemplazo.
     * @param ReplacementSnapshot $snapshot Instantánea de artefactos anteriores.
     * @param ConversionExpectations $expectations Las conversiones esperadas para el nuevo medio.
     * @return self Nueva instancia de ReplacementResult.
     */
    public static function make(
        Media $media,
        ReplacementSnapshot $snapshot,
        ConversionExpectations $expectations
    ): self {
        return new self($media, $snapshot, $expectations);
    }

    /**
     * Verifica si el resultado incluye una instantánea no vacía de artefactos anteriores.
     *
     * @return bool Verdadero si hay una instantánea con contenido, falso en caso contrario.
     */
    public function hasSnapshot(): bool
    {
        return !$this->snapshot->isEmpty();
    }
}
