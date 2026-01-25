<?php // DTO para resultados de reemplazo de upload

declare(strict_types=1); // Tipado estricto

namespace App\Application\Uploads\DTO; // Namespace de DTOs de uploads

/**
 * Representa un reemplazo de archivo.
 */
final class ReplacementResult // DTO inmutable para reemplazos
{
    /**
     * @param UploadResult $new Nuevo upload
     * @param UploadResult|null $previous Upload previo (si existía)
     */
    public function __construct(
        public readonly UploadResult $new, // Upload resultante
        public readonly ?UploadResult $previous = null, // Upload anterior
    ) {
    }
}
