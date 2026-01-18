<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Contracts;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Define límites y mapeos usados por el pipeline de uploads/imágenes.
 *
 * Lee valores desde config('image-pipeline') y expone defaults defensivos
 * para no dejar la whitelist vacía si la config está ausente o malformada.
 */
class FileConstraints
{
    public const MAX_BYTES = 25 * 1024 * 1024;
    public const MIN_WIDTH = 128;
    public const MAX_WIDTH = 16384;
    public const THUMB_WIDTH = 200;
    public const THUMB_HEIGHT = 200;
    public const MEDIUM_WIDTH = 640;
    public const MEDIUM_HEIGHT = 640;
    public const LARGE_WIDTH = 1200;
    public const LARGE_HEIGHT = 1200;
    public const WEBP_QUALITY = 82;

    public readonly int $maxBytes;
    public readonly int $minDimension;
    public readonly int $maxDimension;
    public readonly float $maxMegapixels;
    public readonly ?float $maxDecompressionRatio;
    /** @var array<int,string> */
    public readonly array $allowedExtensions;
    /** @var array<int,string> */
    public readonly array $allowedMimes;
    /** @var array<string,string> */
    public readonly array $mimeToExtension;

    public function __construct()
    {
        $config = (array) config('image-pipeline', []);
        $limits = (array) ($config['limits'] ?? []);

        $this->maxBytes = (int) ($limits['max_bytes'] ?? $config['max_bytes'] ?? 25 * 1024 * 1024);
        $this->minDimension = (int) ($limits['min_dimension'] ?? $config['min_dimension'] ?? 128);
        $this->maxDimension = (int) ($limits['max_edge'] ?? $config['max_edge'] ?? 16384);
        $this->maxMegapixels = (float) ($limits['max_megapixels'] ?? $config['max_megapixels'] ?? 48.0);
        $this->maxDecompressionRatio = isset($config['max_decompression_ratio'])
            ? (float) $config['max_decompression_ratio']
            : (isset($limits['bomb_ratio_threshold']) ? (float) $limits['bomb_ratio_threshold'] : null);

        $defaultExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
        $extConfig = array_values(array_filter(array_map('strtolower', (array) ($config['allowed_extensions'] ?? $defaultExtensions))));
        $this->allowedExtensions = $extConfig === [] ? $defaultExtensions : $extConfig;

        $defaultMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        $mimeConfigRaw = (array) ($config['allowed_mimes'] ?? $defaultMimes);

        $normalizedMimes = [];
        $normalizedMap = [];
        foreach ($mimeConfigRaw as $key => $value) {
            if (is_string($key) && str_contains((string) $key, '/')) {
                $mime = strtolower((string) $key);
                $ext = is_string($value) ? strtolower($value) : null;
                $normalizedMimes[] = $mime;
                if ($ext !== null && $ext !== '') {
                    $normalizedMap[$mime] = $ext;
                }
            } elseif (is_string($value)) {
                $mime = strtolower($value);
                if ($mime !== '') {
                    $normalizedMimes[] = $mime;
                }
            }
        }

        $this->allowedMimes = $normalizedMimes === [] ? $defaultMimes : array_values(array_unique($normalizedMimes));

        $map = (array) ($config['allowed_mime_map'] ?? []);
        foreach ($map as $mime => $ext) {
            if (!is_string($mime) || !is_string($ext) || $mime === '' || $ext === '') {
                continue;
            }
            $normalizedMap[strtolower($mime)] = strtolower($ext);
        }
        $this->mimeToExtension = $normalizedMap;
    }

    /**
     * @return array<int,string>
     */
    public function allowedMimeTypes(): array
    {
        return $this->allowedMimes;
    }

    /**
     * @return array<int,string>
     */
    public function allowedExtensions(): array
    {
        return $this->allowedExtensions;
    }

    /**
     * @return array<string,string>
     */
    public function allowedMimeMap(): array
    {
        return $this->mimeToExtension;
    }

    /**
     * Valida tamaño, extensión y MIME del archivo subido según los límites configurados.
     *
     * @throws InvalidArgumentException
     */
    public function assertValidUpload(UploadedFile $file): void
    {
        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            throw new InvalidArgumentException('Invalid upload size.');
        }
        if ($size > $this->maxBytes) {
            throw new InvalidArgumentException('File exceeds allowed size.');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '' || !in_array($extension, $this->allowedExtensions, true)) {
            throw new InvalidArgumentException('Unsupported file extension.');
        }

        $mime = strtolower((string) $file->getMimeType());
        if ($mime !== '' && !in_array($mime, $this->allowedMimes, true)) {
            throw new InvalidArgumentException('Unsupported MIME type.');
        }
    }

    /**
     * Lee dimensiones y valida tamaño/MIME/megapíxeles/ratio de descompresión.
     *
     * @return array{0:int,1:int}
     */
    public function probeAndAssert(UploadedFile $file): array
    {
        $this->assertValidUpload($file);

        $path = $file->getRealPath();
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            throw new InvalidArgumentException('Uploaded file not readable.');
        }

        $info = @getimagesize($path);
        if (!is_array($info) || !isset($info[0], $info[1])) {
            throw new InvalidArgumentException('Unable to read image dimensions.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException('Invalid image dimensions.');
        }

        if ($width < $this->minDimension || $height < $this->minDimension) {
            throw new InvalidArgumentException('Image below minimum dimensions.');
        }
        if ($width > $this->maxDimension || $height > $this->maxDimension) {
            throw new InvalidArgumentException('Image exceeds maximum dimensions.');
        }

        $megapixels = ($width * $height) / 1_000_000;
        if ($megapixels > $this->maxMegapixels) {
            throw new InvalidArgumentException('Image exceeds maximum megapixels.');
        }

        $detectedMime = isset($info['mime']) ? strtolower((string) $info['mime']) : null;
        if ($detectedMime && !in_array($detectedMime, $this->allowedMimes, true)) {
            throw new InvalidArgumentException('Unsupported image MIME type.');
        }

        if ($this->maxDecompressionRatio !== null) {
            $bits = isset($info['bits']) ? max(1, (int) $info['bits']) : 24;
            $channels = isset($info['channels']) ? max(1, (int) $info['channels']) : 3;
            $estimatedBytes = ($width * $height * $bits * $channels) / 8;
            $size = max(1, (int) ($file->getSize() ?? 0));
            $ratio = $estimatedBytes / $size;
            if ($ratio > $this->maxDecompressionRatio) {
                throw new InvalidArgumentException('Image decompression ratio too high.');
            }
        }

        return [$width, $height];
    }

    public function queueConversionsForAvatar(): bool
    {
        return (bool) config('image-pipeline.avatar_queue_conversions', true);
    }

    public function queueConversionsDefault(): bool
    {
        return (bool) config('image-pipeline.queue_conversions', true);
    }
}
