<?php

declare(strict_types=1);

namespace App\Support\Media\DTO;

use Carbon\CarbonImmutable;

/**
 * Payload durable que se serializa en MediaCleanupState::payload.
 *
 * Centraliza la estructura que describe artefactos pendientes, media a preservar
 * y conversions esperadas; evita depender de arrays anónimos en el scheduler.
 */
final class CleanupStatePayload
{
    /**
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int,string> $preserve
     * @param array<int,string> $conversions
     * @param array<int,string> $origins
     */
    private function __construct(
        public readonly array $artifacts,
        public readonly array $preserve,
        public readonly array $conversions,
        public readonly array $origins,
        public readonly CarbonImmutable $queuedAt,
    ) {
    }

    /**
     * Crea un payload a partir de sus colecciones normalizadas.
     *
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int,string> $preserve
     * @param array<int,string> $conversions
     * @param array<int,string> $origins
     */
    public static function make(
        array $artifacts,
        array $preserve,
        array $conversions,
        array $origins,
        ?CarbonImmutable $queuedAt = null,
    ): self {
        return new self(
            $artifacts,
            $preserve,
            $conversions,
            $origins,
            $queuedAt ?? now()->toImmutable(),
        );
    }

    /**
     * Hidrata el payload desde el array almacenado en base de datos.
     */
    public static function fromArray(?array $payload): ?self
    {
        if (!is_array($payload) || $payload === []) {
            return null;
        }

        return new self(
            is_array($payload['artifacts'] ?? null) ? $payload['artifacts'] : [],
            self::stringifyList($payload['preserve'] ?? []),
            self::stringifyList($payload['conversions'] ?? []),
            self::stringifyList($payload['origins'] ?? []),
            isset($payload['queued_at'])
                ? CarbonImmutable::parse((string) $payload['queued_at'])
                : now()->toImmutable(),
        );
    }

    /**
     * Serializa el payload para almacenarlo en la columna JSON.
     */
    public function toArray(): array
    {
        return [
            'artifacts'   => $this->artifacts,
            'preserve'    => $this->preserve,
            'conversions' => $this->conversions,
            'origins'     => $this->origins,
            'queued_at'   => $this->queuedAt->toIso8601String(), // Ej: "2024-01-01T12:30:00Z"
        ];
    }

    public function hasArtifacts(): bool
    {
        return $this->artifacts !== [];
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private static function stringifyList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if ($item === null) {
                continue;
            }

            $string = trim((string) $item);
            if ($string === '') {
                continue;
            }

            $normalized[] = $string;
        }

        return array_values(array_unique($normalized));
    }
}
