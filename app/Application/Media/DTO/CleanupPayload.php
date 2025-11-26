<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para el DTO (Data Transfer Object) de limpieza de medios.
namespace App\Application\Media\DTO;

// 3. Importación de la clase Media de la librería Spatie.
use App\Domain\Media\DTO\ConversionExpectations;
use App\Domain\Media\DTO\ReplacementSnapshot;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Payload listo para programar cleanup tras conversions.
 *
 * @immutable
 */
final class CleanupPayload
{
    /**
     * Constructor privado para evitar instanciación directa desde fuera de la clase.
     *
     * @param Media $triggerMedia      Media que disparará el cleanup cuando termine conversions.
     * @param array<string,list<array{dir:string,mediaId:string}>> $artifacts Agrupados por disco.
     * @param array<int,string> $preserveIds IDs de medias que no deben eliminarse.
     * @param ConversionExpectations $expectations Conversions esperadas del media nuevo.
     * @param array<int,string> $originMediaIds IDs de medias reemplazadas.
     */
    private function __construct(
        public readonly Media $triggerMedia,
        public readonly array $artifacts,
        public readonly array $preserveIds,
        public readonly ConversionExpectations $expectations,
        public readonly array $originMediaIds,
    ) {}

    /**
     * Crea una instancia vacía de CleanupPayload.
     * Útil cuando no hay artefactos antiguos que limpiar, pero se necesita un payload para el proceso.
     *
     * @param Media $triggerMedia El modelo Media que activa la limpieza.
     * @return self Nueva instancia vacía.
     */
    public static function empty(Media $triggerMedia): self
    {
        return new self(
            $triggerMedia,
            [], // No hay artefactos.
            [(string) $triggerMedia->getKey()], // El triggerMedia no se debe limpiar.
            ConversionExpectations::empty(), // No hay conversiones esperadas.
            [] // No hay medias de origen.
        );
    }

    /**
     * Crea una instancia de CleanupPayload a partir de una instantánea de reemplazo.
     *
     * @param ReplacementSnapshot $snapshot Instantánea que contiene información de los medios reemplazados y sus artefactos.
     * @param Media $triggerMedia El modelo Media que activa la limpieza.
     * @param ConversionExpectations $expectations Las conversiones esperadas del nuevo medio.
     * @return self Nueva instancia con los datos de la instantánea.
     */
    public static function fromSnapshot(
        ReplacementSnapshot $snapshot,
        Media $triggerMedia,
        ConversionExpectations $expectations
    ): self {
        $aggregated = []; // Array para agrupar artefactos por disco.
        $origins = [];    // Array para almacenar IDs de medios reemplazados (evitar duplicados).

        // Itera sobre los ítems de la instantánea.
        foreach ($snapshot->items as $item) {
            // Si el ítem no tiene artefactos, no hay nada que procesar.
            if (!$item->hasArtifacts()) {
                continue;
            }

            // Obtiene el ID del modelo Media del ítem actual.
            $mediaId = (string) $item->media->getKey();
            // Registra el ID como una media de origen.
            $origins[$mediaId] = true;

            // Itera sobre los artefactos del ítem, agrupados por disco.
            foreach ($item->artifacts as $disk => $paths) {
                // Itera sobre las rutas de los artefactos en el disco actual.
                foreach ($paths as $path) {
                    // Agrega el directorio y el ID del medio a la agrupación por disco.
                    $aggregated[$disk][] = [
                        'dir'     => $path,      // Directorio del artefacto.
                        'mediaId' => $mediaId,   // ID del medio al que pertenece.
                    ];
                }
            }
        }

        // El ID del triggerMedia se debe preservar, ya que es el nuevo archivo.
        $preserve = [(string) $triggerMedia->getKey()];

        // Retorna una nueva instancia con los datos procesados.
        return new self(
            $triggerMedia,
            self::deduplicateArtifacts($aggregated), // Elimina duplicados antes de asignar.
            $preserve,
            $expectations,
            array_keys($origins) // Devuelve solo los IDs de los medios de origen.
        );
    }

    /**
     * Elimina entradas duplicadas de artefactos agrupados por disco.
     *
     * @param array<string,list<array{dir:string,mediaId:string}>> $artifacts Artefactos agrupados por disco.
     * @return array<string,list<array{dir:string,mediaId:string}>> Artefactos sin duplicados.
     */
    private static function deduplicateArtifacts(array $artifacts): array
    {
        // Itera sobre cada disco en los artefactos.
        foreach ($artifacts as $disk => $entries) {
            $seen = [];   // Para rastrear entradas ya vistas.
            $deduped = []; // Array temporal para almacenar entradas únicas.

            // Itera sobre cada entrada de artefacto en el disco actual.
            foreach ($entries as $entry) {
                // Verifica que la entrada tenga las claves esperadas.
                if (!isset($entry['dir'], $entry['mediaId'])) {
                    continue; // Salta entradas inválidas.
                }

                // Crea una clave única combinando directorio e ID del medio.
                $key = $entry['dir'] . '|' . $entry['mediaId'];

                // Si la clave ya fue vista, es un duplicado.
                if (isset($seen[$key])) {
                    continue;
                }

                // Marca la clave como vista y añade la entrada a la lista sin duplicados.
                $seen[$key] = true;
                $deduped[] = [
                    'dir'     => (string) $entry['dir'],      // Asegura tipo string.
                    'mediaId' => (string) $entry['mediaId'],  // Asegura tipo string.
                ];
            }

            // Si no hay entradas únicas en este disco, lo elimina del array final.
            if ($deduped === []) {
                unset($artifacts[$disk]);
                continue;
            }

            // Reemplaza las entradas del disco con la versión sin duplicados.
            $artifacts[$disk] = $deduped;
        }

        return $artifacts;
    }

    /**
     * Verifica si el payload tiene artefactos para limpiar.
     *
     * @return bool Verdadero si hay artefactos, falso en caso contrario.
     */
    public function hasArtifacts(): bool
    {
        return $this->artifacts !== [];
    }
}
