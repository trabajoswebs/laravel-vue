<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Valida cabeceras asociadas a la entrega de avatares (S3 u otros discos).
 *
 * Espera:
 * - `ACL`: debe existir y ser `private` para evitar accesos públicos inesperados.
 * - `ContentType`: debe estar presente para que el navegador renderice la imagen correctamente.
 *
 * Todos los métodos son estáticos para facilitar su reutilización sin necesidad de inyectar dependencias.
 */
final class AvatarHeaderInspector
{
    private const EXPECTED_ACL = 'private';
    private const HEADER_ACL = 'ACL';
    private const HEADER_CONTENT_TYPE = 'ContentType';
    private const ISSUE_ACL_UNEXPECTED = 'acl_unexpected';
    private const ISSUE_ACL_MISSING = 'acl_missing';
    private const ISSUE_CONTENT_TYPE_MISSING = 'content_type_missing';

    private function __construct()
    {
    }

    /**
     * Inspecciona las cabeceras y devuelve issues detectados.
     *
     * @param array<string,mixed> $headers Cabeceras emitidas por el almacenamiento (S3, disk, etc.).
     * @return array<int,array<string,string>> Lista de issues detectados con metadata.
     */
    public static function detectIssues(array $headers): array
    {
        $issues = [];

        $acl = self::extractHeader($headers, self::HEADER_ACL);
        if ($acl === null) {
            $issues[] = [
                'type' => self::ISSUE_ACL_MISSING,
            ];
        } elseif (strtolower($acl) !== self::EXPECTED_ACL) {
            $issues[] = [
                'type' => self::ISSUE_ACL_UNEXPECTED,
                'expected' => self::EXPECTED_ACL,
                'received' => $acl,
            ];
        }

        $contentType = self::extractHeader($headers, self::HEADER_CONTENT_TYPE);
        if ($contentType === null) {
            $issues[] = [
                'type' => self::ISSUE_CONTENT_TYPE_MISSING,
            ];
        }

        return $issues;
    }

    /**
     * Resumen rápido para saber si existen issues detectadas.
     *
     * @param array<string,mixed> $headers
     * @return bool
     */
    public static function hasIssues(array $headers): bool
    {
        $acl = self::extractHeader($headers, self::HEADER_ACL);
        if ($acl === null || strtolower($acl) !== self::EXPECTED_ACL) {
            return true;
        }

        return self::extractHeader($headers, self::HEADER_CONTENT_TYPE) === null;
    }

    /**
     * Normaliza el valor de una cabecera buscando en mayúsculas o minúsculas.
     *
     * @param array<string,mixed> $headers
     * @param string $key
     * @return string|null
     */
    private static function extractHeader(array $headers, string $key): ?string
    {
        foreach ($headers as $headerKey => $value) {
            if (is_string($headerKey) && strcasecmp($headerKey, $key) === 0) {
                return is_string($value) && $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
