<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Support;

use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use Illuminate\Contracts\Debug\ExceptionHandler;
use App\Support\Logging\SecurityLogger;
/**
 * Centraliza logging y reporting de errores del flujo de subida.
 */
final class ImageUploadReporter
{
    public function __construct(
        private readonly ExceptionHandler $exceptions,
        private readonly MediaLogSanitizer $sanitizer,
    ) {}

    /**
     * @param array<string,mixed> $context
     */
    public function report(string $message, \Throwable $exception, array $context = [], string $level = 'error'): void
    {
        $logContext = $this->sanitizer->safeContext(array_merge([
            'exception'       => $this->sanitizer->safeException($exception),
            'exception_class' => $exception::class,
        ], $context));

        if (config('app.debug', false)) {
            $logContext['trace'] = $exception->getTraceAsString();
        }

        SecurityLogger::log($level, $message, $logContext);
        $this->exceptions->report($exception);
    }
}
