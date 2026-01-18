<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\DTO;

/**
 * Snapshot sencillo de artefactos previos al reemplazo.
 */
final class MediaReplacementSnapshot
{
    /**
     * @param array<int, MediaReplacementItemSnapshot> $items
     */
    public function __construct(
        public readonly array $items
    ) {
    }

    /**
     * @param array<int, MediaReplacementItemSnapshot> $items
     */
    public static function fromItems(array $items): self
    {
        $valid = array_filter($items, static fn($item) => $item instanceof MediaReplacementItemSnapshot);

        return new self(array_values($valid));
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return array<string,list<string>>
     */
    public function toArtifacts(): array
    {
        $result = [];
        foreach ($this->items as $item) {
            $result[$item->disk] = $item->paths;
        }

        return $result;
    }
}
