<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Orchestrators;

use App\Domain\Uploads\UploadProfile;
use App\Infrastructure\Uploads\Core\Contracts\MediaProfile;
use App\Infrastructure\Uploads\Profiles\AvatarProfile;
use App\Infrastructure\Uploads\Profiles\GalleryProfile;
use InvalidArgumentException;

final class MediaProfileResolver
{
    public function __construct(
        private readonly AvatarProfile $avatarProfile,
        private readonly GalleryProfile $galleryProfile,
    ) {}

    public function resolve(UploadProfile $profile): MediaProfile
    {
        return match ((string) $profile->id) {
            'avatar_image' => $this->avatarProfile,
            'gallery_image' => $this->galleryProfile,
            default => throw new InvalidArgumentException('Perfil de imagen no soportado: ' . (string) $profile->id),
        };
    }
}

