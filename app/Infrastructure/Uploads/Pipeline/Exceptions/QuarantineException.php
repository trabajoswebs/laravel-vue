<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Exceptions;

/**
 * Errores relacionados con la cuarentena (persistencia temporal).
 */
class QuarantineException extends UploadException
{
    // Esta clase no requiere métodos adicionales, ya que extiende UploadException.
}