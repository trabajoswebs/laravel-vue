<?php

declare(strict_types=1);

namespace App\Application\Media\DTO;

use App\Application\Shared\Contracts\ClockInterface;
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
     * Constructor privado para evitar instancias directas.
     *
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int,string> $preserve
     * @param array<int,string> $conversions
     * @param array<int,string> $origins
     * @param CarbonImmutable $queuedAt Fecha y hora en que se encoló el estado.
     */
    private function __construct(
        public readonly array $artifacts,
        public readonly array $preserve,
        public readonly array $conversions,
        public readonly array $origins,
        public readonly CarbonImmutable $queuedAt,
    ) {}

    /**
     * Crea un payload a partir de sus colecciones normalizadas.
     *
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts Lista de artefactos pendientes.
     * @param array<int,string> $preserve Lista de IDs de medios que deben preservarse.
     * @param array<int,string> $conversions Lista de rutas o IDs de conversiones esperadas.
     * @param array<int,string> $origins Lista de orígenes de los medios.
     * @param CarbonImmutable|null $queuedAt Fecha y hora en que se encoló el estado. Si es null, se usa la fecha actual.
     * @return self Nueva instancia de CleanupStatePayload.
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
            $queuedAt ?? self::clock()->now(),
        );
    }

    /**
     * Hidrata el payload desde el array almacenado en base de datos.
     *
     * @param array<string, mixed>|null $payload El array serializado desde la base de datos.
     * @return self|null Nueva instancia de CleanupStatePayload o null si el array es vacío o no es válido.
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
                : self::clock()->now(),
        );
    }

    /**
     * Serializa el payload para almacenarlo en la columna JSON.
     *
     * @return array<string, mixed> El array serializado que puede guardarse en base de datos.
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

    /**
     * Verifica si el payload tiene artefactos pendientes.
     *
     * @return bool True si hay artefactos, false en caso contrario.
     */
    public function hasArtifacts(): bool
    {
        return $this->artifacts !== [];
    }

    /**
     * Normaliza una lista de valores a una lista de cadenas únicas y no vacías.
     *
     * @param mixed $value El valor a normalizar.
     * @return array<int,string> La lista normalizada de cadenas.
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

    private static function clock(): ClockInterface
    {
        return app(ClockInterface::class);
    }
}
