<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Exceptions;

/**
 * Excepción genérica para errores no clasificados en el flujo de subida.
 */
final class UnexpectedUploadException extends UploadException
{
    // Esta clase no requiere métodos adicionales, ya que extiende UploadException.
}