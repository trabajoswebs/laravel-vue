<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\DTO;

use App\Domain\Media\DTO\MediaReplacementItemSnapshot;
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
     * @param MediaReplacementItemSnapshot $media Datos mínimos del media asociado a los artefactos.
     * @param array<string,list<string>> $artifacts Array asociativo donde las claves son nombres de disco
     *                                             y los valores son listas de rutas de artefactos.
     */
    private function __construct(
        public readonly MediaReplacementItemSnapshot $media,
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
        return self::fromMedia($media, $artifacts);
    }

    /**
     * Construye el item a partir de un modelo Media concreto.
     *
     * @param Media $media
     * @param array<string,list<string>> $artifacts
     */
    public static function fromMedia(Media $media, array $artifacts): self
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

        return new self(
            MediaReplacementItemSnapshot::make(
                (string) $media->getKey(),
                is_string($media->collection_name ?? null) ? $media->collection_name : null,
                is_string($media->disk ?? null) ? $media->disk : null,
                is_string($media->file_name ?? null) ? $media->file_name : null,
                $normalized,
            ),
            $normalized
        );
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

    public function domainItemSnapshot(): MediaReplacementItemSnapshot
    {
        return $this->media;
    }
}
