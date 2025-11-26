<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Upload\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;

/**
 * Centraliza logging y reporting de errores del flujo de subida.
 */
final class ImageUploadReporter
{
    public function __construct(
        private readonly ExceptionHandler $exceptions,
    ) {}

    /**
     * @param array<string,mixed> $context
     */
    public function report(string $message, \Throwable $exception, array $context = [], string $level = 'error'): void
    {
        $logContext = array_merge([
            'exception'       => $exception->getMessage(),
            'exception_class' => $exception::class,
        ], $context);

        if (config('app.debug', false)) {
            $logContext['trace'] = $exception->getTraceAsString();
        }

        Log::log($level, $message, $logContext);
        $this->exceptions->report($exception);
    }
}
