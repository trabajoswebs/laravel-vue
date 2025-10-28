<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Media\Contracts\MediaOwner;
use App\Support\Media\ImageProfile;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Normaliza y adjunta imágenes a modelos que implementan Media Library.
 * 
 * Este servicio actúa como intermediario entre la subida de archivos y la biblioteca
 * de medios. Procesa la imagen usando el ImagePipeline, la adjunta al modelo
 * correspondiente y maneja limpieza y errores.
 * 
 * @example
 * $service = new ImageUploadService($pipeline, $exceptionHandler);
 * $media = $service->upload($user, $uploadedFile, $imageProfile);
 * echo $media->getUrl(); // URL de la imagen procesada
 */
final class ImageUploadService
{
    public function __construct(
        private readonly ImagePipeline $pipeline,
        private readonly ExceptionHandler $exceptions,
    ) {}

    /**
     * Sube, procesa y adjunta un archivo de imagen a un modelo MediaOwner.
     * 
     * 1. Procesa la imagen con el pipeline.
     * 2. Limpia la colección si el perfil es de archivo único.
     * 3. Genera un nombre de archivo único basado en el hash.
     * 4. Agrega la imagen al modelo usando Spatie Media Library.
     * 5. Guarda metadatos personalizados (hash, dimensiones, etc.).
     * 6. Limpia el archivo temporal al finalizar.
     * 
     * @param MediaOwner $owner Modelo al que se adjunta la imagen.
     * @param UploadedFile $file Archivo de imagen subido.
     * @param ImageProfile $profile Perfil de imagen que define colección, disco, etc.
     * @return Media Instancia del modelo Media recién creado.
     * @throws InvalidArgumentException Si el archivo no es válido.
     * @throws \Throwable Si ocurre un error durante el procesamiento o la subida.
     */
    public function upload(MediaOwner $owner, UploadedFile $file, ImageProfile $profile): Media
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('Archivo de imagen inválido.');
        }

        $collection = $profile->collection();
        $disk = $profile->disk();
        $result = null;

        try {
            // Procesa la imagen
            $result = $this->pipeline->process($file);

            // Genera un nombre de archivo único
            $target = sprintf('%s-%s.%s', $collection, $result->contentHash(), $result->extension());

            // Prepara el adder de Spatie Media Library
            $safeFilename = str_replace('"', "'", basename($target));
            $headers = [
                'ACL' => 'private',
                'ContentType' => $result->mime(),
                'ContentDisposition' => sprintf('inline; filename="%s"', $safeFilename),
            ];

            $adder = $owner->addMedia($result->path())
                ->usingFileName($target)
                ->addCustomHeaders($headers)
                ->withCustomProperties([
                    'version'     => $result->contentHash(),
                    'uploaded_at' => now()->toIso8601String(),
                    'mime_type'   => $result->mime(),
                    'width'       => $result->width(),
                    'height'      => $result->height(),
                ]);

            if ($profile->isSingleFile() && \method_exists($adder, 'singleFile')) {
                $adder->singleFile();
            }

            // Adjunta la imagen a la colección (y disco) correspondiente
            $media = filled($disk)
                ? $adder->toMediaCollection($collection, $disk)
                : $adder->toMediaCollection($collection);

            return $media;
        } catch (\Throwable $exception) {
            // Registra el error y lo relanza
            $this->report('image_upload.failed', $exception, [
                'model'      => $owner::class,
                'model_id'   => $owner->getKey(),
                'collection' => $collection,
                'disk'       => $disk,
                'file'       => [
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                ],
            ]);

            throw $exception;
        } finally {
            // Asegura la limpieza del archivo temporal
            if ($result !== null) {
                try {
                    $result->cleanup();
                } catch (\Throwable $cleanupException) {
                    $this->report(
                        'image_upload.cleanup_failed',
                        $cleanupException,
                        [
                            'model'      => $owner::class,
                            'model_id'   => $owner->getKey(),
                            'collection' => $collection,
                        ],
                        'warning'
                    );
                }
            }
        }
    }

    /**
     * Registra un error en el log y lo reporta al manejador de excepciones.
     *
     * @param string $message Mensaje descriptivo del evento.
     * @param \Throwable $exception Excepción ocurrida.
     * @param array $context Información adicional para el log.
     * @param string $level Nivel de log ('error', 'warning', etc.).
     */
    private function report(
        string $message,
        \Throwable $exception,
        array $context = [],
        string $level = 'error'
    ): void {
        Log::log($level, $message, array_merge([
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ], $context));

        $this->exceptions->report($exception);
    }
}
