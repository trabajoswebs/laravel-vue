<?php

declare(strict_types=1);

namespace App\Services\Upload\Core;

/**
 * Acceso abstracto a la cuarentena de archivos subidos.
 *
 * No impone detalles de almacenamiento para permitir distintas
 * implementaciones (disco local, S3 privado, base de datos, etc.).
 */
interface QuarantineRepository
{
    /**
     * Guardar bytes sin procesar en la cuarentena.
     *
     * @return string Ruta o identificador para recuperar el archivo luego.
     */
    public function put(string $bytes): string;

    /**
     * Eliminar un artefacto de cuarentena (fallar silenciosamente si no existe).
     */
    public function delete(string $path): void;

    /**
     * Promocionar un artefacto validado hacia almacenamiento definitivo.
     *
     * @param  string $path      Identificador retornado por put().
     * @param  array  $metadata  Datos adicionales requeridos por la implementación.
     */
    public function promote(string $path, array $metadata = []): string;

    /**
     * Eliminar artefactos antiguos (TTL en horas) y devolver cantidad de archivos removidos.
     */
    public function pruneStaleFiles(int $maxAgeHours = 24): int;

    /**
     * Eliminar archivos sidecar huérfanos (ej. hashes) y devolver la cantidad limpiada.
     */
    public function cleanupOrphanedSidecars(): int;
}
