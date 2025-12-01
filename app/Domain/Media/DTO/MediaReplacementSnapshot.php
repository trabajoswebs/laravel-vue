<?php

declare(strict_types=1);

namespace App\Domain\Media\DTO;

/**
 * Colección inmutable de medias a reemplazar con sus artefactos asociados.
 */
final class MediaReplacementSnapshot
{
    /**
     * @param array<int, MediaReplacementItemSnapshot> $items Array de snapshots de items de media
     */
    private function __construct(
        public readonly array $items,    // Colección de snapshots de medias individuales
    ) {}

    /**
     * Crea una instancia a partir de un array de snapshots de items de media.
     *
     * @param array<int, MediaReplacementItemSnapshot> $items Array de snapshots de items
     * @return self Nueva instancia con los items filtrados
     */
    public static function fromItems(array $items): self
    {
        return new self(array_values(array_filter(
            $items,
            static fn($item) => $item instanceof MediaReplacementItemSnapshot
        )));
    }

    /**
     * Crea una instancia vacía sin items.
     *
     * @return self Nueva instancia vacía
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Verifica si la colección está vacía.
     *
     * @return bool True si no hay items en la colección, false en caso contrario
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
