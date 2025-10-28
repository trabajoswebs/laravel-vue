<?php

declare(strict_types=1);

namespace App\Services\ImagePipeline;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Logger centrado para el pipeline que respeta canal y truncado configurable.
 * 
 * Esta clase proporciona un mecanismo centralizado para registrar eventos
 * del pipeline de imágenes. Permite especificar un canal de log personalizado
 * y truncar mensajes largos para evitar logs excesivamente grandes.
 * Si el modo debug está deshabilitado, trunca los valores de contexto de tipo string.
 * 
 * @example
 * $logger = new PipelineLogger('image-processing', true);
 * $logger->log('info', 'Imagen procesada', ['width' => 800, 'height' => 600]);
 * $shortMessage = $logger->limit('Este es un mensaje muy largo...');
 */
final class PipelineLogger
{
    public const MESSAGE_MAX_LENGTH = 160;

    public function __construct(
        private readonly ?string $channel,
        private readonly bool $debug,
    ) {}

    /**
     * Registra un mensaje en el log con el nivel, mensaje y contexto especificados.
     * 
     * Si el modo debug está deshabilitado, trunca los valores de tipo string
     * en el array de contexto para evitar datos excesivos en los logs.
     * Envía el log al canal configurado o al logger por defecto.
     * 
     * @param string $level Nivel de log (e.g., 'debug', 'info', 'warning', 'error').
     * @param string $message Mensaje a registrar.
     * @param array $context Datos adicionales para el log.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->debug) {
            foreach ($context as $key => $value) {
                if (\is_string($value)) {
                    $context[$key] = $this->limit($value);
                }
            }
        }

        if ($this->channel) {
            Log::channel($this->channel)->{$level}($message, $context);
            return;
        }

        Log::{$level}($message, $context);
    }

    /**
     * Trunca un mensaje de texto a la longitud máxima configurada.
     * 
     * @param string|null $message Mensaje a truncar.
     * @return string|null Mensaje truncado o null si la entrada es null.
     * @example limit('Hola mundo') -> 'Hola mundo'
     * @example limit('Este es un mensaje muy largo...') -> 'Este es un mensaje muy largo... [continúa]'
     */
    public function limit(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return (string) Str::of($message)->limit(self::MESSAGE_MAX_LENGTH);
    }
}
