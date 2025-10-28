<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para el DTO (Data Transfer Object) de expectativas de conversión de medios.
namespace App\Support\Media\DTO;

/**
 * Conversions esperadas para un media, evitando arrays sin tipar.
 *
 * @immutable
 */
final class ConversionExpectations
{
    /**
     * Constructor privado para evitar instanciación directa desde fuera de la clase.
     *
     * @param array<int,string> $names Lista de nombres de conversiones esperadas.
     */
    private function __construct(
        public readonly array $names, // 3. Propiedad pública e inmutable que almacena los nombres.
    ) {}

    /**
     * Crea una instancia de ConversionExpectations a partir de un array sin tipar.
     * Filtra, limpia y deduplica los valores para garantizar solo cadenas válidas.
     *
     * @param array<int|string,mixed> $raw Array de valores sin procesar (p. ej., de una configuración o entrada externa).
     * @return self Nueva instancia con los nombres de conversiones limpios y únicos.
     */
    public static function fromList(array $raw): self
    {
        // 4. Proceso de transformación del array:
        $names = array_values( // Reindexa el array resultante para que las claves sean numéricas y consecutivas.
            array_unique(      // Elimina duplicados.
                array_filter(  // Filtra valores que no son cadenas válidas o vacías.
                    array_map( // Transforma cada valor a string (o null si no lo es) y lo recorta.
                        static fn($value) => is_string($value) ? trim($value) : null,
                        $raw
                    ),
                    static fn(?string $value) => $value !== null && $value !== '' // Filtra null y cadenas vacías.
                )
            )
        );

        return new self($names);
    }

    /**
     * Crea una instancia vacía de ConversionExpectations.
     *
     * @return self Nueva instancia sin nombres de conversiones.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Verifica si no hay conversiones esperadas.
     *
     * @return bool Verdadero si la lista de nombres está vacía, falso en caso contrario.
     */
    public function isEmpty(): bool
    {
        return $this->names === [];
    }
}
