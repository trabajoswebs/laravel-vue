<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para componentes del pipeline de imágenes.
namespace App\Services\ImagePipeline;

/**
 * Excepción específica del pipeline con información sobre recuperabilidad.
 */
final class ImageProcessingException extends \RuntimeException
{
    /**
     * Constructor de la excepción.
     *
     * @param string $reason Una descripción corta del motivo del error (p. ej. 'invalid_format').
     * @param string $message Mensaje descriptivo del error (opcional, se usará $reason si está vacío).
     * @param bool $recoverable Indica si este error es recuperable (por ejemplo, usando un flujo alternativo).
     * @param array<string,mixed> $context Información adicional contextual para debugging.
     * @param \Throwable|null $previous La excepción anterior en la cadena (opcional).
     */
    public function __construct(
        private readonly string $reason,        // 3. Razón del error (por ejemplo, 'invalid_format').
        string $message = '',                   // 4. Mensaje de error descriptivo.
        private readonly bool $recoverable = false, // 5. Indica si el error es recuperable.
        private readonly array $context = [],   // 6. Información adicional contextual.
        ?\Throwable $previous = null,           // 7. Excepción anterior en la cadena (opcional).
    ) {
        // 8. Llama al constructor de la clase padre (\RuntimeException) con el mensaje y la excepción anterior.
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    /**
     * Devuelve la razón del error.
     *
     * @return string Razón del error.
     */
    public function reason(): string
    {
        return $this->reason; // 9. Devuelve el valor almacenado en la propiedad $reason.
    }

    /**
     * Indica si el error es recuperable.
     *
     * @return bool Verdadero si es recuperable, falso en caso contrario.
     */
    public function isRecoverable(): bool
    {
        return $this->recoverable; // 10. Devuelve el valor almacenado en la propiedad $recoverable.
    }

    /**
     * Devuelve la información contextual del error.
     *
     * @return array<string,mixed> Información adicional.
     */
    public function context(): array
    {
        return $this->context; // 11. Devuelve el valor almacenado en la propiedad $context.
    }
}
