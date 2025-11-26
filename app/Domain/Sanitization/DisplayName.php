<?php

declare(strict_types=1);

namespace App\Domain\Sanitization;

use App\Domain\Security\SecurityHelper;

/**
 * Value Object para sanitizar y transportar nombres visibles de usuario.
 *
 * Este Value Object encapsula la lógica de sanitización de nombres visibles de usuario,
 * proporcionando una interfaz consistente para validar, sanitizar y transportar
 * estos valores a través de diferentes capas de la aplicación (middleware, FormRequests,
 * servicios, etc.).
 *
 * La clase permite:
 * - Sanitizar nombres de usuario de forma segura
 * - Mantener el valor original para auditoría/trazabilidad
 * - Proporcionar mensajes de error consistentes cuando la sanitización falla
 * - Evitar la duplicación de lógica de validación entre diferentes componentes
 * - Garantizar que solo se acceda al valor sanitizado cuando es válido
 *
 * @example
 * $displayName = DisplayName::from('John_Doe123');
 * if ($displayName->isValid()) {
 *     $safeName = $displayName->sanitized();
 * } else {
 *     $error = $displayName->errorMessage();
 * }
 */
final class DisplayName
{
    /**
     * Constructor privado del Value Object DisplayName.
     *
     * @param string $original El valor original proporcionado (antes de sanitizar)
     * @param string|null $sanitized El valor después de la sanitización (null si falló)
     * @param string|null $errorMessage Mensaje de error si la sanitización falló (null si tuvo éxito)
     */
    private function __construct(
        private readonly string $original,
        private readonly ?string $sanitized,
        private readonly ?string $errorMessage
    ) {
    }

    /**
     * Crea una instancia de DisplayName a partir de un valor arbitrario.
     *
     * Este método de fábrica convierte el valor de entrada a string y aplica
     * la sanitización correspondiente. Si el valor es nulo o vacío, se considera
     * válido pero sin sanitización necesaria. Si la sanitización falla,
     * se captura la excepción y se almacena el mensaje de error.
     *
     * @param mixed $value Valor a sanitizar (puede ser de cualquier tipo)
     * @return self Nueva instancia de DisplayName con el estado resultante
     */
    public static function from(mixed $value): self
    {
        // Convierte el valor a string si es escalar o nulo, de lo contrario usa string vacío
        $original = is_scalar($value) || $value === null ? (string) $value : '';

        if ($original === '') {
            // Valor vacío se considera válido pero sin sanitización
            return new self('', '', null);
        }

        try {
            // Intenta sanitizar el valor usando la herramienta de seguridad
            $sanitized = SecurityHelper::sanitizeUserName($original);
            return new self($original, $sanitized, null);
        } catch (\Throwable $exception) {
            // Si falla la sanitización, almacena el error original
            return new self($original, null, $exception->getMessage());
        }
    }

    /**
     * Verifica si el nombre visible es válido.
     *
     * Un DisplayName es válido cuando:
     * - La sanitización se completó exitosamente (no es null)
     * - El valor sanitizado no está vacío
     *
     * @return bool true si el valor es válido, false en caso contrario
     */
    public function isValid(): bool
    {
        return $this->sanitized !== null && $this->sanitized !== '';
    }

    /**
     * Obtiene el valor sanitizado, lanzando excepción si no es válido.
     *
     * Este método debe usarse solo cuando se está seguro de que el valor es válido
     * (por ejemplo, después de verificar con isValid()). Si el valor no es válido,
     * se lanza una excepción para prevenir el uso de datos no seguros.
     *
     * @throws \RuntimeException Si se intenta acceder al valor sanitizado cuando no es válido
     * @return string El valor sanitizado y seguro para usar
     */
    public function sanitized(): string
    {
        if (!$this->isValid()) {
            throw new \RuntimeException('Attempted to access sanitized display name when invalid');
        }

        return $this->sanitized;
    }

    /**
     * Obtiene el valor sanitizado o null si no es válido.
     *
     * Método seguro para acceder al valor sanitizado sin lanzar excepciones.
     * Útil cuando se quiere manejar el valor opcionalmente sin interrumpir
     * el flujo de ejecución.
     *
     * @return string|null El valor sanitizado si es válido, null en caso contrario
     */
    public function sanitizedOrNull(): ?string
    {
        return $this->sanitized;
    }

    /**
     * Obtiene el valor original sin procesar.
     *
     * Este método permite acceder al valor tal como fue proporcionado originalmente,
     * útil para propósitos de registro, auditoría o mensajes de error más informativos.
     *
     * @return string El valor original antes de cualquier procesamiento
     */
    public function original(): string
    {
        return $this->original;
    }

    /**
     * Obtiene el mensaje de error si la sanitización falló.
     *
     * Devuelve el mensaje de error capturado durante la sanitización, o null
     * si la operación se completó exitosamente. Útil para proporcionar
     * retroalimentación al usuario o para fines de depuración.
     *
     * @return string|null Mensaje de error si hubo fallo, null si fue exitoso
     */
    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
