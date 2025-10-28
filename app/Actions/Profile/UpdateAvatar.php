<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Events\User\AvatarUpdated;
use App\Models\User;
use App\Support\Media\Profiles\AvatarProfile;
use App\Support\Media\Services\MediaReplacementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class UpdateAvatar
{
    public function __construct(
        private readonly MediaReplacementService $replacement,
        private readonly AvatarProfile $profile,
    ) {}

    public function __invoke(User $user, UploadedFile $file): Media
    {
        $collection = $this->profile->collection();

        return DB::transaction(function () use ($user, $file, $collection): Media {
            $locked   = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $oldMedia = $locked->getFirstMedia($collection);

            $result = $this->replacement->replaceWithSnapshot($locked, $file, $this->profile);
            $media = tap($result->media)->refresh();

            $version = rescue(
                fn () => filled($value = $media->getCustomProperty('version')) ? (string) $value : null,
                null
            );

            if (method_exists($locked, 'getMediaVersionColumn') && ($column = $locked->getMediaVersionColumn($collection))) {
                $locked->{$column} = $version;
                $locked->save();
            }

            Log::info('avatar.updated', [
                'user_id'      => $locked->getKey(),
                'collection'   => $collection,
                'media_id'     => $media->id,
                'replaced_id'  => $oldMedia?->id,
                'version'      => $version,
            ]);

            DB::afterCommit(function () use ($locked, $media, $oldMedia, $collection, $version) {
                if (!class_exists(AvatarUpdated::class)) {
                    return;
                }

                event(new AvatarUpdated(
                    user: $locked,
                    newMedia: $media,
                    oldMedia: $oldMedia,
                    version: $version,
                    collection: $collection,
                    url: $media->getUrl()
                ));
            });

            return $media;
        });
    }
}
