<?php

declare(strict_types=1);

namespace App\Domain\Media\DTO;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * DTO que representa los artefactos asociados a un media específico dentro del snapshot.
 *
 * Esta clase es inmutable y encapsula la información sobre los archivos
 * artefactos generados para un modelo Media específico, organizados por disco.
 *
 * @immutable
 */
final class ReplacementSnapshotItem
{
    /**
     * Constructor privado para crear una instancia de ReplacementSnapshotItem.
     *
     * @param Media $media Instancia del modelo Media asociado a los artefactos.
     * @param array<string,list<string>> $artifacts Array asociativo donde las claves son nombres de disco
     *                                             y los valores son listas de rutas de artefactos.
     */
    private function __construct(
        public readonly Media $media,
        public readonly array $artifacts,
    ) {}

    /**
     * Crea una instancia de ReplacementSnapshotItem desde datos legados.
     *
     * Este método normaliza y valida los datos de artefactos legados,
     * asegurando que cumplan con el formato esperado.
     *
     * @param Media $media Instancia del modelo Media asociado a los artefactos.
     * @param array<string,list<string>> $artifacts Array de artefactos legados que se deben normalizar.
     * @return self Nueva instancia de ReplacementSnapshotItem con los datos normalizados.
     */
    public static function fromLegacy(Media $media, array $artifacts): self
    {
        // Array para almacenar los artefactos normalizados
        $normalized = [];

        // Itera sobre cada disco y sus rutas en los artefactos legados
        foreach ($artifacts as $disk => $paths) {
            // Verifica que el disco sea una cadena válida y no esté vacío, y que las rutas sean un array
            if (!is_string($disk) || $disk === '' || !is_array($paths)) {
                continue; // Salta entradas inválidas
            }

            // Normaliza las rutas: convierte a string, quita espacios, filtra valores nulos o vacíos
            $paths = array_values(array_filter(
                array_map(
                    static fn ($path) => is_string($path) ? trim($path) : null,
                    $paths
                ),
                static fn (?string $path) => $path !== null && $path !== '' // Filtra rutas válidas
            ));

            // Si no quedan rutas válidas, salta esta entrada
            if ($paths === []) {
                continue;
            }

            // Añade las rutas normalizadas al disco correspondiente
            $normalized[$disk] = $paths;
        }

        // Crea y devuelve una nueva instancia con los artefactos normalizados
        return new self($media, $normalized);
    }

    /**
     * Verifica si el item tiene artefactos asociados.
     *
     * @return bool `true` si hay artefactos, `false` en caso contrario.
     */
    public function hasArtifacts(): bool
    {
        // Devuelve true si el array de artefactos no está vacío
        return $this->artifacts !== [];
    }
}