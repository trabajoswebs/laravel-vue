<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Contracts;

/**
 * Puerto para servicios de subida de media (sin exponer infraestructura).
 */
interface MediaUploader
{
    public function upload(MediaOwner $owner, UploadedMedia $file, MediaProfile $profile, ?string $correlationId = null);

    public function uploadSync(MediaOwner $owner, UploadedMedia $file, MediaProfile $profile, ?string $correlationId = null): MediaResource;
}
