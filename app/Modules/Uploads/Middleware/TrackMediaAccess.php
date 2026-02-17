<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Middleware;

use App\Infrastructure\Uploads\Pipeline\Security\Upload\UploadSecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registra accesos exitosos a medios estáticos (avatares, etc.).
 */
final class TrackMediaAccess
{
    public function __construct(private readonly UploadSecurityLogger $logger)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 400) {
            $media = $request->route('media');
            if ($media instanceof Media) {
                $this->logger->accessed([
                    'media_id' => (string) $media->getKey(),
                    'user_id' => optional($request->user())->getAuthIdentifier(),
                    'ip_hash' => $this->hashValue((string) ($request->ip() ?? '')),
                    'user_agent_hash' => $this->hashValue((string) $request->header('User-Agent')),
                    'correlation_id' => $media->getCustomProperty('correlation_id'),
                ]);
            }
        }

        return $response;
    }

    private function hashValue(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $key = (string) config('app.key', 'app');
        return substr(hash_hmac('sha256', $trimmed, $key), 0, 16);
    }
}
