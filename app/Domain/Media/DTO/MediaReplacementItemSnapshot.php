<?php

declare(strict_types=1);

namespace App\Domain\Media\DTO;

/**
 * Representa los datos mínimos de un media antes de ser reemplazado.
 *
 * Agnóstico de framework: no depende de Spatie ni de Eloquent.
 */
final class MediaReplacementItemSnapshot
{
    private function __construct(
        public readonly string $id,                    // ID único del media (ej: "42")
        public readonly ?string $collectionName,      // Nombre de la colección (ej: "avatar", null si no aplica)
        public readonly ?string $disk,                // Nombre del disco de almacenamiento (ej: "public", null si no aplica)
        public readonly ?string $fileName,            // Nombre del archivo (ej: "avatar.jpg", null si no aplica)
        /** @var array<string,list<string>> */
        public readonly array $artifacts,             // Artefactos asociados por disco
    ) {}

    /**
     * Crea una instancia con valores específicos.
     *
     * @param string $id ID único del media
     * @param string|null $collectionName Nombre de la colección
     * @param string|null $disk Nombre del disco de almacenamiento
     * @param string|null $fileName Nombre del archivo
     * @return self Nueva instancia
     */
    public static function make(
        string $id,
        ?string $collectionName = null,
        ?string $disk = null,
        ?string $fileName = null,
        array $artifacts = []
    ): self {
        return new self(
            $id,
            self::sanitizeNullableString($collectionName),
            self::sanitizeNullableString($disk),
            self::sanitizeNullableString($fileName),
            self::normalizeArtifacts($artifacts),
        );
    }

    /**
     * Crea una instancia vacía con valores por defecto.
     *
     * @return self Nueva instancia vacía
     */
    public static function empty(): self
    {
        return new self('', null, null, null, []);
    }

    /**
     * Obtiene el ID único del media.
     *
     * @return string ID del media
     */
    public function getKey(): string
    {
        return $this->id;
    }

    /**
     * Obtiene el nombre de la colección del media.
     *
     * @return string|null Nombre de la colección o null si no está definido
     */
    public function collection(): ?string
    {
        return $this->collectionName;
    }

    /**
     * Obtiene el nombre del disco de almacenamiento del media.
     *
     * @return string|null Nombre del disco o null si no está definido
     */
    public function storageDisk(): ?string
    {
        return $this->disk;
    }

    /**
     * Obtiene el nombre del archivo del media.
     *
     * @return string|null Nombre del archivo o null si no está definido
     */
    public function fileName(): ?string
    {
        return $this->fileName;
    }

    /**
     * @return array<string,list<string>>
     */
    public function artifacts(): array
    {
        return $this->artifacts;
    }

    public function hasArtifacts(): bool
    {
        return $this->artifacts !== [];
    }

    /**
     * Limpia y normaliza una cadena nullable.
     *
     * Convierte cadenas vacías en null y elimina espacios en blanco.
     *
     * @param string|null $value Valor a limpiar
     * @return string|null Valor limpio o null si era vacío
     */
    private static function sanitizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string,list<string>> $artifacts
     * @return array<string,list<string>>
     */
    private static function normalizeArtifacts(array $artifacts): array
    {
        $normalized = [];

        foreach ($artifacts as $disk => $paths) {
            if (!is_string($disk) || $disk === '' || !is_array($paths)) {
                continue;
            }

            $cleanPaths = array_values(array_filter(
                array_map(
                    static fn ($path) => is_string($path) ? trim($path) : null,
                    $paths
                ),
                static fn (?string $path) => $path !== null && $path !== ''
            ));

            if ($cleanPaths === []) {
                continue;
            }

            $normalized[$disk] = $cleanPaths;
        }

        return $normalized;
    }
}
