<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Support;

use App\Modules\Uploads\Pipeline\Contracts\UploadMetadata;
use App\Modules\Uploads\Pipeline\DTO\InternalPipelineResult;
use App\Modules\Uploads\Pipeline\Exceptions\UploadException;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Encapsula la lógica de nombrado y adjunción a Spatie Media Library.
 */
final class MediaAttacher
{
    /**
     * Adjunta el artefacto procesado a un modelo HasMedia.
     *
     * @param HasMediaContract $owner
     * @param InternalPipelineResult $artifact
     * @param string $profile
     * @param string|null $disk
     * @param bool $singleFile
     * @param string|null $correlationId
     */
    public function attach(
        HasMediaContract $owner,
        InternalPipelineResult $artifact,
        string $profile,
        ?string $disk = null,
        bool $singleFile = false,
        ?string $correlationId = null
    ): Media {
        $metadata = $artifact->metadata;
        $fileName = $this->buildFileName($metadata, $profile);

        $headers = [
            'ACL' => 'private',
            'ContentType' => $metadata->mime,
            'ContentDisposition' => sprintf('inline; filename="%s"', $fileName),
        ];

        $adder = $owner->addMedia($artifact->path)
            ->usingFileName($fileName)
            ->addCustomHeaders($headers)
            ->withCustomProperties([
                'version' => $metadata->hash,
                'uploaded_at' => now()->toIso8601String(),
                'mime_type' => $metadata->mime,
                'width' => $metadata->dimensions['width'] ?? null,
                'height' => $metadata->dimensions['height'] ?? null,
                'original_filename' => $metadata->originalFilename,
                'quarantine_id' => $artifact->quarantineId?->identifier(),
                'correlation_id' => $correlationId,
                'headers' => $headers,
                'size' => $artifact->size,
            ]);

        if ($singleFile && method_exists($adder, 'singleFile')) {
            $adder->singleFile();
        }

        try {
            return $disk !== null && $disk !== ''
                ? $adder->toMediaCollection($profile, $disk)
                : $adder->toMediaCollection($profile);
        } catch (\Throwable $exception) {
            throw UploadException::fromThrowable('Unable to attach upload to media collection.', $exception);
        }
    }

    private function buildFileName(UploadMetadata $metadata, string $profile): string
    {
        $safeProfile = $this->sanitizeProfile($profile);
        $extension = $this->sanitizeExtension($metadata->extension ?? 'bin');
        $identifier = $metadata->hash ?? $this->generateSecureIdentifier();
        $randomSuffix = substr(Str::uuid()->toString(), 0, 8);

        return sprintf('%s-%s-%s.%s', $safeProfile, $identifier, $randomSuffix, $extension);
    }

    private function sanitizeProfile(string $profile): string
    {
        $normalized = strtolower($profile);
        $normalized = preg_replace('/[^a-z0-9_-]/', '-', $normalized) ?? 'upload';
        $normalized = trim($normalized, '-_');
        if ($normalized === '') {
            $normalized = 'upload';
        }

        return substr($normalized, 0, 40);
    }

    private function sanitizeExtension(string $extension): string
    {
        $clean = strtolower($extension);
        $clean = preg_replace('/[^a-z0-9]/', '', $clean) ?? 'bin';

        return $clean === '' ? 'bin' : substr($clean, 0, 10);
    }

    private function generateSecureIdentifier(): string
    {
        return bin2hex(random_bytes(16));
    }
}
