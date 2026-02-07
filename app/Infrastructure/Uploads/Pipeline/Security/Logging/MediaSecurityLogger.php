<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Security\Logging;

use Illuminate\Support\Facades\Log;

final class MediaSecurityLogger
{
    public function __construct(
        private readonly MediaLogSanitizer $sanitizer,
    ) {}

    /**
     * @param array<string,mixed> $context
     */
    public function debug(string $event, array $context = []): void
    {
        $this->write('debug', $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function critical(string $event, array $context = []): void
    {
        $this->write('critical', $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function write(string $level, string $event, array $context): void
    {
        $safeContext = $this->sanitizer->safeContext($context);
        $channel = (string) config('media-serving.security_log_channel', 'stack');

        try {
            Log::channel($channel)->{$level}($event, $safeContext);
        } catch (\Throwable) {
            Log::debug($event, $safeContext);
        }
    }
}
