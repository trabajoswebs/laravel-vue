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
     * @param  array<string,mixed> $metadata Metadatos iniciales (perfil, correlation_id, TTLs, etc.).
     * @return QuarantineToken   Identificador para recuperar/promover más tarde.
     */
    public function put(string $bytes, array $metadata = []): QuarantineToken;

    /**
     * Guarda un recurso de streaming en la cuarentena.
     *
     * @param  resource $stream  Recurso de lectura abierto desde el archivo subido.
     * @param  array<string,mixed> $metadata Metadatos iniciales (perfil, correlation_id, TTLs, etc.).
     * @return QuarantineToken    Identificador para recuperar/promover más tarde.
     */
    public function putStream($stream, array $metadata = []): QuarantineToken;

    /**
     * Eliminar un artefacto de cuarentena.
     *
     * Contrato:
     *  - Si el identificador no existe, la operación debe ser silenciosa.
     *  - Si el backend de almacenamiento falla, puede lanzar QuarantineException
     *    u otra RuntimeException específica de la implementación.
     *
     * @param  QuarantineToken|string $path Identificador retornado por put()/putStream().
     */
    public function delete(QuarantineToken|string $path): void;

    /**
     * Promocionar un artefacto validado hacia almacenamiento definitivo.
     *
    /**
     * Transiciona un artefacto de un estado a otro de forma atómica.
     *
     * @param QuarantineToken $token Token del artefacto en cuarentena.
     * @param QuarantineState $from  Estado esperado actual.
     * @param QuarantineState $to    Estado destino.
     * @param array<string,mixed> $metadata Metadata adicional para sidecar.
     */
    public function transition(
        QuarantineToken $token,
        QuarantineState $from,
        QuarantineState $to,
        array $metadata = []
    ): void;

    /**
     * Obtiene el estado actual de un artefacto.
     *
     * @param QuarantineToken $token Token del artefacto.
     */
    public function getState(QuarantineToken $token): QuarantineState;

    /**
     * Lee el metadata persistido del artefacto en cuarentena.
     *
     * @param QuarantineToken $token Token del artefacto.
     * @return array<string,mixed>
     */
    public function getMetadata(QuarantineToken $token): array;

    /**
     * Reconstruye un token a partir del identificador relativo opaco.
     *
     * @param string $identifier Identificador devuelto por put/putStream
     * @return QuarantineToken|null Token reconstruido o null si no existe
     */
    public function resolveTokenByIdentifier(string $identifier): ?QuarantineToken;

    /**
     * @param  QuarantineToken|string               $path      Identificador retornado por put()/putStream().
     * @param  array<string,mixed>  $metadata  Datos adicionales requeridos por la implementación
     *                                         (por ejemplo, ruta destino).
     * @return string                          Identificador o ruta del artefacto promovido.
     */
    public function promote(QuarantineToken|string $path, array $metadata = []): string;

    /**
     * Eliminar artefactos antiguos (TTL en horas) y devolver cantidad de archivos removidos.
     */
    public function pruneStaleFiles(int $maxAgeHours = 24): int;

    /**
     * Eliminar archivos sidecar huérfanos (ej. hashes) y devolver la cantidad limpiada.
     */
    public function cleanupOrphanedSidecars(): int;
}
