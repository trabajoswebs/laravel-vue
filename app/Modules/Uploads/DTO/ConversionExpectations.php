<?php

declare(strict_types=1);

namespace App\Modules\Uploads\DTO;

final class ConversionExpectations
{
    /**
     * @param array<int,string> $conversions
     */
    public function __construct(
        public readonly array $conversions = [],
    ) {
    }

    /**
     * @param array<int,string> $conversions
     */
    public static function fromArray(array $conversions): self
    {
        $normalized = array_values(array_filter(array_map(static fn($c) => (string) $c, $conversions)));

        return new self($normalized);
    }
}
