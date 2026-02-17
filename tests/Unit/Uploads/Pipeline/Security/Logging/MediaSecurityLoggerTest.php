<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Security\Logging;

use App\Modules\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class MediaSecurityLoggerTest extends TestCase
{
    public function test_logger_enriches_context_with_request_and_upload_identifiers(): void
    {
        Log::spy();
        config()->set('media-serving.security_log_channel', 'stack');
        $this->app['request']->headers->set('X-Request-Id', 'req-123');

        $logger = new MediaSecurityLogger(new MediaLogSanitizer());
        $logger->info('upload.event', [
            'upload_uuid' => 'u-abc',
            'path' => 'tenants/10/private/file.jpg',
        ]);

        Log::shouldHaveReceived('info')->withArgs(function (string $event, array $context): bool {
            return $event === 'upload.event'
                && ($context['request_id'] ?? null) === 'req-123'
                && ($context['upload_id'] ?? null) === 'u-abc'
                && isset($context['path_hash'])
                && !isset($context['path']);
        })->atLeast()->once();
    }

    public function test_logger_does_not_promote_quarantine_token_as_upload_id(): void
    {
        Log::spy();
        config()->set('media-serving.security_log_channel', 'stack');

        $logger = new MediaSecurityLogger(new MediaLogSanitizer());
        $logger->info('upload.event', [
            'quarantine_id' => 'secret-token-value',
        ]);

        Log::shouldHaveReceived('info')->withArgs(function (string $event, array $context): bool {
            return $event === 'upload.event'
                && !isset($context['upload_id'])
                && isset($context['token_hash'])
                && !isset($context['quarantine_id']);
        })->atLeast()->once();
    }
}
