<?php

declare(strict_types=1);

namespace App\Support\Media\Jobs;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob as BasePerformConversionsJob;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Variante defensiva del job de conversions.
 *
 * Si el registro Media fue eliminado (p. ej. porque el usuario subió otro avatar),
 * el job se descarta silenciosamente para evitar recrear carpetas huérfanas.
 */
class PerformConversionsJob extends BasePerformConversionsJob
{
    public function __construct(
        ConversionCollection $conversions,
        Media $media,
        bool $onlyMissing = false,
    ) {
        parent::__construct($conversions, $media, $onlyMissing);
    }

    public function handle(FileManipulator $fileManipulator): bool
    {
        try {
            $freshMedia = Media::query()->find($this->media->getKey());

            if ($freshMedia === null) {
                Log::notice('media.conversions.skipped_missing', [
                    'media_id' => $this->media->getKey(),
                    'collection' => $this->media->collection_name,
                ]);

                return true;
            }

            if ($this->conversions->isEmpty()) {
                Log::info('media.conversions.no_conversions', [
                    'media_id' => $freshMedia->getKey(),
                    'collection' => $freshMedia->collection_name,
                ]);

                return true;
            }

            // Sustituye la instancia serializada por la fresca para garantizar paths coherentes.
            $this->media = $freshMedia;

            $fileManipulator->performConversions(
                $this->conversions,
                $this->media,
                $this->onlyMissing
            );

            return true;
        } catch (\Throwable $exception) {
            $this->report($exception);

            $this->release(30);

            return false;
        }
    }

    private function report(\Throwable $exception): void
    {
        Log::error('media.conversions.failed', [
            'media_id' => $this->media->getKey(),
            'collection' => $this->media->collection_name ?? null,
            'message' => $exception->getMessage(),
        ]);

        app(ExceptionHandler::class)->report($exception);
    }
}
