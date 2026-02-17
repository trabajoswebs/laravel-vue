<?php

declare(strict_types=1);

namespace App\Modules\Uploads\DTO;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class CleanupStatePayload
{
    /**
     * @param array<string,list<string>> $artifacts
     * @param array<int,string> $preserveMediaIds
     * @param array<int,string> $conversions
     * @param array<int,string> $origins
     */
    public function __construct(
        public readonly array $artifacts,
        public readonly array $preserveMediaIds,
        public readonly array $conversions,
        public readonly array $origins,
        public readonly CarbonInterface $queuedAt,
    ) {
    }

    /**
     * @param array<string,list<string>> $artifacts
     * @param array<int,string> $preserveMediaIds
     * @param array<int,string> $conversions
     * @param array<int,string> $origins
     */
    public static function make(
        array $artifacts,
        array $preserveMediaIds,
        array $conversions,
        array $origins
    ): self {
        return new self(
            $artifacts,
            $preserveMediaIds,
            $conversions,
            $origins,
            CarbonImmutable::now(),
        );
    }

    public static function fromArray(?array $payload): ?self
    {
        if (!is_array($payload)) {
            return null;
        }

        $artifacts = isset($payload['artifacts']) && is_array($payload['artifacts']) ? $payload['artifacts'] : [];
        $preserve = isset($payload['preserve_media_ids']) && is_array($payload['preserve_media_ids']) ? $payload['preserve_media_ids'] : [];
        $conversions = isset($payload['conversions']) && is_array($payload['conversions']) ? $payload['conversions'] : [];
        $origins = isset($payload['origins']) && is_array($payload['origins']) ? $payload['origins'] : [];
        $queuedAt = isset($payload['queued_at']) ? CarbonImmutable::parse($payload['queued_at']) : CarbonImmutable::now();

        return new self(
            $artifacts,
            array_values(array_map('strval', $preserve)),
            array_values(array_map('strval', $conversions)),
            array_values(array_map('strval', $origins)),
            $queuedAt
        );
    }

    public function toArray(): array
    {
        return [
            'artifacts' => $this->artifacts,
            'preserve_media_ids' => $this->preserveMediaIds,
            'conversions' => $this->conversions,
            'origins' => $this->origins,
            'queued_at' => $this->queuedAt->toIso8601String(),
        ];
    }
}
