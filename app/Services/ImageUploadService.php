<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Media\ImageProfile;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Servicio para subir una imagen normalizada a una colección determinada por un perfil.
 *
 * Responsabilidades:
 * - Ejecutar ImagePipeline para normalizar (auto-orient, strip EXIF/ICC, sRGB, re-encode).
 * - Nombrar archivo en base a {collection}-{sha1}.{ext}.
 * - Adjuntar a Media Library con propiedades personalizadas (version, mime, dims).
 * - Respetar el disco definido por el perfil.
 *
 * No gestiona: locks/transacciones, policies, ni eventos de dominio (delegar en Actions).
 */
final class ImageUploadService
{
    /**
     * Sube una imagen normalizada a la colección indicada por el perfil.
     *
     * @param  HasMedia&InteractsWithMedia  $owner    Modelo destino que implementa Media Library.
     * @param  UploadedFile                 $file     Archivo de imagen subido.
     * @param  ImageProfile                 $profile  Perfil que define colección, disco y conversions.
     * @return Media                                   Media recién creado.
     *
     * @throws InvalidArgumentException si el archivo no es válido.
     */
    public function upload(HasMedia&InteractsWithMedia $owner, UploadedFile $file, ImageProfile $profile): Media
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('Archivo inválido.');
        }

        /** @var ImagePipeline $pipeline */
        $pipeline = app(ImagePipeline::class);
        $res = $pipeline->process($file);

        try {
            $collection = $profile->collection();
            $target = $collection . '-' . $res->contentHash . '.' . $res->extension;
            $adder = $owner->addMedia($res->path)
                ->usingFileName($target)
                ->withCustomProperties([
                    'version'     => $res->contentHash,
                    'uploaded_at' => now()->toIso8601String(),
                    'mime_type'   => $res->mime,
                    'width'       => $res->width,
                    'height'      => $res->height,
                ]);

            $disk = $profile->disk();
            $media = is_string($disk) && $disk !== ''
                ? $adder->toMediaCollection($collection, $disk)
                : $adder->toMediaCollection($collection);

            return $media;
        } finally {
            $res->cleanup();
        }
    }
}

