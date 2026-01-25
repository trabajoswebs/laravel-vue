<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Security\Upload;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Clase encargada de centralizar las trazas de validación para uploads seguros.
 *
 * Esta clase se encarga de anonimizar el nombre del archivo y añadir contexto de usuario
 * antes de delegar en el logger de PSR-3. Asegura que la información sensible como
 * el nombre real del archivo no se revele directamente en los logs, sino que se
 * registra un hash del mismo.
 */
final class UploadValidationLogger
{
    /**
     * Constructor de la clase.
     *
     * @param LoggerInterface $logger Instancia del logger compatible con PSR-3 que se usará para escribir los mensajes.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Registra una advertencia y adjunta metadata (hash del nombre del archivo, ID de usuario) antes de loguear.
     *
     * Este método se encarga de crear un payload estándar con información relevante
     * para la validación de seguridad, como el evento que ocurrió, un hash del nombre
     * del archivo (para anonimizarlo) y el ID del usuario si está disponible.
     * Luego delega la escritura del log al logger inyectado.
     *
     * @param string $event Código del evento que describe la advertencia (por ejemplo, 'image_decode_failed').
     * @param string $filename Nombre original del archivo enviado por el cliente. Se hará un hash de este valor.
     * @param array<string,mixed>|Throwable|null $context Datos adicionales que se adjuntarán al log, o una excepción capturada.
     * @param string|int|null $userId Identificador opcional del usuario que realizó la acción. Puede ser null si no aplica.
     */
    public function warning(
        string $event,
        string $filename,
        array|Throwable|null $context = null,
        string|int|null $userId = null,
    ): void {
        // Crea el payload base con información del evento, hash del nombre y user_id
        $payload = [
            'event' => $event,
            // Genera un hash SHA256 del nombre del archivo para anonimizarlo en los logs
            'file_hash' => hash('sha256', (string) $filename), // Ej: "avatar.png" -> "b6d1..."
            'user_id' => $userId,
        ];

        // Procesa el contexto adicional dependiendo de su tipo
        if ($context instanceof Throwable) {
            // Si el contexto es una excepción, extrae su mensaje
            $payload['exception'] = $context->getMessage();
        } elseif (is_array($context)) {
            // Si el contexto es un array, combínalo con el payload base
            $payload = array_merge($payload, $context);
        }
        // Si $context es null, no se añade nada adicional

        // Registra la advertencia usando el logger PSR-3 con un mensaje genérico y el payload estructurado
        $this->logger->warning('secure_image_validation', $payload);
    }
}
