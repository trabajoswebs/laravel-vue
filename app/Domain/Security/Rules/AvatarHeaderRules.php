<?php

declare(strict_types=1);

namespace App\Domain\Security\Rules;

use InvalidArgumentException;

/**
 * Reglas de seguridad para validar headers de avatares.
 * 
 * Verifica que los headers de almacenamiento (como S3) cumplan con los requisitos
 * de seguridad esperados para avatares de usuarios.
 */
final class AvatarHeaderRules
{
    private const HEADER_ACL_CANDIDATES = ['ACL', 'x-amz-acl', 'X-Amz-Acl']; // Posibles headers ACL
    private const HEADER_CONTENT_TYPE_CANDIDATES = ['ContentType', 'Content-Type']; // Posibles headers Content-Type
    private const DEFAULT_ALLOWED_CONTENT_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    public const ISSUE_ACL_UNEXPECTED = 'acl_unexpected';     // Problema: ACL no es el esperado
    public const ISSUE_ACL_MISSING = 'acl_missing';           // Problema: Header ACL ausente
    public const ISSUE_CONTENT_TYPE_UNSAFE = 'content_type_unsafe'; // Problema: Content-Type no permitido

    /**
     * Constructor de la clase.
     *
     * @param string $expectedAcl Valor ACL esperado (normalizado a lowercase/trim)
     * @param string[] $allowedContentTypes Lista de tipos MIME seguros (lowercase)
     */
    public function __construct(
        string $expectedAcl = 'private',
        array $allowedContentTypes = self::DEFAULT_ALLOWED_CONTENT_TYPES,
    ) {
        $normalizedTypes = array_values(array_filter(array_map(
            static fn($type) => is_string($type) ? strtolower(trim($type)) : '',
            $allowedContentTypes
        )));

        if ($normalizedTypes === []) {
            throw new InvalidArgumentException('allowedContentTypes cannot be empty');
        }

        $this->expectedAcl = strtolower(trim($expectedAcl));
        $this->allowedContentTypes = $normalizedTypes;
    }

    private readonly string $expectedAcl;
    /** @var string[] */
    private readonly array $allowedContentTypes;

    /**
     * Detecta problemas de seguridad en los headers de un avatar.
     *
     * @param array<string,mixed> $headers Headers a analizar
     * @return array<int,array{type:string,details:array<string,mixed>}> Lista de problemas encontrados con detalles
     */
    public function detectIssues(array $headers): array
    {
        $issues = [];

        $acl = $this->extractHeader($headers, self::HEADER_ACL_CANDIDATES);
        if ($acl === null) {
            $issues[] = ['type' => self::ISSUE_ACL_MISSING, 'details' => []];
        } elseif ($acl['normalized'] !== $this->expectedAcl) {
            $issues[] = [
                'type' => self::ISSUE_ACL_UNEXPECTED,
                'details' => [
                    'expected' => $this->expectedAcl,
                    'received' => $acl['original'],
                    'received_normalized' => $acl['normalized'],
                ],
            ];
        }

        $contentType = $this->extractHeader($headers, self::HEADER_CONTENT_TYPE_CANDIDATES);
        if ($contentType !== null && !$this->isAllowedContentType($contentType['normalized'])) {
            $issues[] = [
                'type' => self::ISSUE_CONTENT_TYPE_UNSAFE,
                'details' => [
                    'received' => $contentType['original'],
                    'received_normalized' => $contentType['normalized'],
                ],
            ];
        }

        return $issues;
    }

    /**
     * Verifica si los headers contienen problemas de seguridad.
     *
     * @param array<string,mixed> $headers Headers a analizar
     * @return bool True si hay problemas de seguridad, false en caso contrario
     */
    public function hasIssues(array $headers): bool
    {
        return $this->detectIssues($headers) !== [];
    }

    /**
     * Extrae un header específico de la lista de headers.
     *
     * @param array<string,mixed> $headers Headers a analizar
     * @param string[] $candidates Nombres de header a buscar (case-insensitive)
     * @return array{original:string,normalized:string}|null Array con header original y normalizado, o null si no encontrado
     */
    private function extractHeader(array $headers, array $candidates): ?array
    {
        foreach ($headers as $headerKey => $value) {
            if (!is_string($headerKey)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (strcasecmp($headerKey, $candidate) === 0) {
                    if (!is_string($value)) {
                        return null;
                    }

                    $normalized = strtolower(trim($value));
                    if ($normalized === '') {
                        return null;
                    }

                    return [
                        'original' => $value,
                        'normalized' => $normalized,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Verifica si un tipo de contenido es permitido.
     *
     * @param string $contentType Tipo de contenido a verificar
     * @return bool True si es permitido, false en caso contrario
     */
    private function isAllowedContentType(string $contentType): bool
    {
        return in_array($contentType, $this->allowedContentTypes, true);
    }
}
