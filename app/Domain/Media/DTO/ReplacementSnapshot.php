<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para el DTO (Data Transfer Object) de instantánea de reemplazo de medios.
namespace App\Domain\Media\DTO;

// 3. Importación de la clase Media de la librería Spatie.
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Snapshot inmutable de artefactos capturados antes de un reemplazo.
 *
 * Representa los artefactos a limpiar por cada media anterior.
 *
 * @immutable
 */
final class ReplacementSnapshot
{
    /**
     * Constructor privado para evitar instanciación directa desde fuera de la clase.
     *
     * @param array<int, ReplacementSnapshotItem> $items Lista de ítems que contienen información de cada media reemplazado y sus artefactos.
     */
    private function __construct(
        public readonly array $items, // 4. Lista de ítems de instantánea inmutables.
    ) {}

    /**
     * Crea una instancia de ReplacementSnapshot a partir de un array de datos sin tipar (legacy).
     * Filtra y convierte cada entrada en un ReplacementSnapshotItem.
     *
     * @param array<int, array{media: Media, artifacts: array<string,list<string>>}> $raw Array de datos sin procesar.
     * @return self Nueva instancia de ReplacementSnapshot.
     */
    public static function fromLegacy(array $raw): self
    {
        $items = []; // Array temporal para acumular los ítems válidos.

        // Itera sobre cada entrada del array sin procesar.
        foreach ($raw as $entry) {
            // Verifica que la entrada tenga las claves 'media' y 'artifacts', y que sean del tipo correcto.
            if (
                !isset($entry['media'], $entry['artifacts']) || // Comprueba que existan las claves.
                !$entry['media'] instanceof Media ||            // Comprueba que 'media' sea instancia de Media.
                !is_array($entry['artifacts'])                 // Comprueba que 'artifacts' sea un array.
            ) {
                continue; // Si no, ignora esta entrada.
            }

            // Crea un ítem de instantánea a partir de los datos válidos y lo añade a la lista.
            $items[] = ReplacementSnapshotItem::fromLegacy($entry['media'], $entry['artifacts']);
        }

        // Retorna una nueva instancia con la lista de ítems procesados.
        return new self($items);
    }

    /**
     * Crea una instancia vacía de ReplacementSnapshot.
     *
     * @return self Nueva instancia sin ítems.
     */
    public static function empty(): self
    {
        return new self([]); // Retorna una instancia con un array vacío.
    }

    /**
     * Verifica si la instantánea no contiene ningún ítem.
     *
     * @return bool Verdadero si la lista de ítems está vacía, falso en caso contrario.
     */
    public function isEmpty(): bool
    {
        return $this->items === []; // Compara directamente con un array vacío.
    }
}
