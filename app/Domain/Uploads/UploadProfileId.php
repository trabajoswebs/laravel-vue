<?php // Value Object para identificar perfiles de subida

declare(strict_types=1); // Tipado estricto para consistencia

namespace App\Domain\Uploads; // Namespace de objetos de dominio de uploads

/**
 * Identificador de perfil de subida (ej. avatar_image).
 */
final class UploadProfileId // VO simple que envuelve el ID de perfil
{
    /**
     * Crea un nuevo identificador.
     *
     * @param string $value Valor del identificador (ejemplo: 'avatar_image')
     */
    public function __construct(private readonly string $value) // Guarda el ID de perfil (ej. avatar_image)
    {
        if ($value === '') { // Valida que no sea vacío
            throw new \InvalidArgumentException('UploadProfileId no puede estar vacío'); // Lanza error si es inválido
        }
    }

    /**
     * Devuelve el valor subyacente del identificador.
     *
     * @return string ID del perfil de subida
     */
    public function value(): string // Obtiene el ID crudo
    {
        return $this->value; // Retorna el string almacenado
    }

    /**
     * String casting.
     *
     * @return string
     */
    public function __toString(): string // Permite usar el VO como string
    {
        return $this->value; // Devuelve el valor del identificador
    }
}
