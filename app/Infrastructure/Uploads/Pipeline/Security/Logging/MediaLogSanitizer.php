<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Security\Logging;

final class MediaLogSanitizer
{
    private const HASH_LENGTH = 16;

    public function hashPath(string $path): string
    {
        return $this->hashValue($path);
    }

    public function hashName(string $name): string
    {
        return $this->hashValue($name);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function safeContext(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            $safeKey = is_string($key) ? $key : (string) $key;
            $lowerKey = strtolower($safeKey);

            if ($this->isDangerousPathKey($lowerKey)) {
                $safe['path_hash'] = $this->hashAny($value);
                continue;
            }

            if ($this->isDangerousNameKey($lowerKey)) {
                $safe['name_hash'] = $this->hashAny($value);
                continue;
            }

            if ($this->isDangerousUrlKey($lowerKey)) {
                $safe['url_hash'] = $this->hashAny($value);
                continue;
            }

            if ($this->isDangerousTokenKey($lowerKey)) {
                $safe['token_hash'] = $this->hashAny($value);
                continue;
            }

            if ($this->isDangerousHeadersKey($lowerKey)) {
                $safe['headers_hash'] = $this->hashAny($value);
                continue;
            }

            if ($this->isDangerousTraceKey($lowerKey)) {
                $safe['trace_hash'] = $this->hashAny($value);
                continue;
            }

            if ($value instanceof \Throwable) {
                $safe[$safeKey] = $this->safeException($value);
                continue;
            }

            if (is_array($value)) {
                /** @var array<string,mixed> $value */
                $safe[$safeKey] = $this->safeContext($value);
                continue;
            }

            if (is_string($value) && $this->shouldNormalizeMessage($lowerKey)) {
                $safe[$safeKey] = $this->normalizeMessage($value);
                continue;
            }

            $safe[$safeKey] = $value;
        }

        return $safe;
    }

    /**
     * @return array{class:string,code:int|string,message:string}
     */
    public function safeException(\Throwable $e): array
    {
        return [
            'class' => $e::class,
            'code' => is_int($e->getCode()) || is_string($e->getCode()) ? $e->getCode() : 0,
            'message' => $this->normalizeMessage($e->getMessage()),
        ];
    }

    private function isDangerousPathKey(string $key): bool
    {
        if (in_array($key, ['path', 'file', 'from', 'to', 'destination', 'source', 'directory', 'lock'], true)) {
            return true;
        }

        return str_ends_with($key, '_path')
            || str_ends_with($key, '_file')
            || str_ends_with($key, '_dir')
            || str_ends_with($key, '_directory');
    }

    private function isDangerousNameKey(string $key): bool
    {
        if (in_array($key, ['name', 'filename', 'file_name', 'original_name', 'original_filename'], true)) {
            return true;
        }

        return str_ends_with($key, '_filename');
    }

    private function isDangerousUrlKey(string $key): bool
    {
        return str_contains($key, 'url');
    }

    private function isDangerousTokenKey(string $key): bool
    {
        return str_contains($key, 'token')
            || str_contains($key, 'quarantine_id');
    }

    private function isDangerousHeadersKey(string $key): bool
    {
        return str_contains($key, 'header');
    }

    private function isDangerousTraceKey(string $key): bool
    {
        return str_contains($key, 'trace');
    }

    private function shouldNormalizeMessage(string $key): bool
    {
        return $key === 'error' || $key === 'message' || str_ends_with($key, '_message');
    }

    private function hashAny(mixed $value): string
    {
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $this->hashValue($encoded === false ? '[array]' : $encoded);
        }

        if (is_object($value)) {
            return $this->hashValue(get_debug_type($value));
        }

        if (is_bool($value)) {
            return $this->hashValue($value ? 'true' : 'false');
        }

        if ($value === null) {
            return $this->hashValue('null');
        }

        return $this->hashValue((string) $value);
    }

    private function hashValue(string $value): string
    {
        return substr(hash('sha256', $value), 0, self::HASH_LENGTH);
    }

    private function normalizeMessage(string $message): string
    {
        $normalized = preg_replace('#https?://[^\s"]+#i', '[url]', $message) ?? $message;
        $normalized = preg_replace('#(?:[A-Za-z]:\\\\|/)[^\s"]+#', '[path]', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b[a-f0-9]{24,}\b/i', '[token]', $normalized) ?? $normalized;

        return mb_substr($normalized, 0, 200, 'UTF-8');
    }
}
