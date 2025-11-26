<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Upload\Core;

/**
 * Acceso abstracto a la cuarentena de archivos subidos.
 *
 * Contrato general:
 *  - put()/putStream() devuelven un identificador opaco (ruta o ID) que
 *    debe usarse posteriormente en delete()/promote().
 *  - Las implementaciones deben confinar las operaciones a su propio
 *    backend (disco local, S3 privado, etc.) sin permitir traversal.
 */
interface QuarantineRepository
{
    /**
     * Guardar bytes sin procesar en la cuarentena.
     *
     * @param  string $bytes  Contenido del archivo.
     * @return string         Identificador para recuperar/promover más tarde.
     */
    public function put(string $bytes): string;

    /**
     * Guarda un recurso de streaming en la cuarentena.
     *
     * @param  resource $stream  Recurso de lectura abierto desde el archivo subido.
     * @return string            Identificador para recuperar/promover más tarde.
     */
    public function putStream($stream): string;

    /**
     * Eliminar un artefacto de cuarentena.
     *
     * Contrato:
     *  - Si el identificador no existe, la operación debe ser silenciosa.
     *  - Si el backend de almacenamiento falla, puede lanzar QuarantineException
     *    u otra RuntimeException específica de la implementación.
     *
     * @param  string $path Identificador retornado por put()/putStream().
     */
    public function delete(string $path): void;

    /**
     * Promocionar un artefacto validado hacia almacenamiento definitivo.
     *
     * @param  string               $path      Identificador retornado por put()/putStream().
     * @param  array<string,mixed>  $metadata  Datos adicionales requeridos por la implementación
     *                                         (por ejemplo, ruta destino).
     * @return string                          Identificador o ruta del artefacto promovido.
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
