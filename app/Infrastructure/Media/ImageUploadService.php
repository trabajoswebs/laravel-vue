<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

// Importamos las clases necesarias para el servicio de subida de imágenes
use App\Infrastructure\Media\Upload\Contracts\UploadPipeline; // Contrato para el pipeline de subida
use App\Infrastructure\Media\Upload\Contracts\UploadService; // Contrato para el servicio de subida
use App\Infrastructure\Media\Upload\Exceptions\UploadValidationException; // Excepción para validación de subida
use App\Infrastructure\Media\Upload\Scanning\ScanCoordinator; // Coordinador de escaneo
use App\Infrastructure\Media\Upload\Support\ImageUploadReporter; // Reporter para subidas de imágenes
use App\Infrastructure\Media\Upload\Support\QuarantineManager; // Gestor de cuarentena
use App\Domain\User\Contracts\MediaOwner; // Contrato para modelos que poseen media
use App\Domain\Media\ImageProfile; // Perfil de imagen para subida
use Illuminate\Http\UploadedFile; // Clase para manejar archivos subidos
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Modelo de media de Spatie

/**
 * Servicio de subida y procesamiento seguro de imágenes.
 *
 * Orquesta validación, cuarentena, escaneo, normalización y adjunto del media.
 */
final class ImageUploadService
{
    /**
     * Constructor del servicio de subida de imágenes.
     *
     * @param UploadPipeline $pipeline Pipeline para procesar imágenes
     * @param UploadService $uploadService Servicio para adjuntar archivos
     * @param ScanCoordinator $scanCoordinator Coordinador para escaneo de seguridad
     * @param QuarantineManager $quarantineManager Gestor para manejo de cuarentena
     * @param ImageUploadReporter $reporter Reporter para eventos de subida
     */
    public function __construct(
        private readonly UploadPipeline $pipeline, // Pipeline para procesar imágenes
        private readonly UploadService $uploadService, // Servicio para adjuntar archivos
        private readonly ScanCoordinator $scanCoordinator, // Coordinador para escaneo
        private readonly QuarantineManager $quarantineManager, // Gestor de cuarentena
        private readonly ImageUploadReporter $reporter, // Reporter para eventos
    ) {}

    /**
     * Sube, procesa y adjunta un archivo de imagen a un modelo MediaOwner.
     *
     * @param MediaOwner $owner Modelo que poseerá el media
     * @param UploadedFile $file Archivo de imagen original subido
     * @param ImageProfile $profile Perfil de imagen (colección, disco, singleFile, etc.)
     * @return Media Media de Spatie creado/actualizado
     * @throws \Throwable Para cualquier error durante el proceso
     */
    public function upload(MediaOwner $owner, UploadedFile $file, ImageProfile $profile): Media
    {
        // Validamos que el archivo subido sea válido
        if (!$file->isValid()) {
            throw new UploadValidationException(__('media.uploads.invalid_image'));
        }

        // Validamos el tipo MIME del archivo
        $this->quarantineManager->validateMimeType($file);

        $collection     = $profile->collection(); // Colección donde se guardará el archivo
        $disk           = $profile->disk(); // Disco donde se guardará
        $processedFile  = $file; // Archivo procesado (inicialmente el original)
        $quarantinePath = null; // Ruta en cuarentena (inicialmente vacía)
        $artifact       = null; // Artefacto resultante del pipeline

        try {
            // Si el escaneo está habilitado
            if ($this->scanCoordinator->enabled()) {
                // Verificamos que el escaneo esté disponible
                $this->scanCoordinator->assertAvailable();
                // Creamos una copia en cuarentena
                [$processedFile, $quarantinePath] = $this->quarantineManager->duplicate($file);
                // Escaneamos el archivo en cuarentena
                $this->scanCoordinator->scan($processedFile, $quarantinePath);
            }

            // Procesamos el archivo a través del pipeline
            $artifact = $this->pipeline->process($processedFile);

            // Adjuntamos el artefacto al modelo usando Media Library
            return $this->uploadService->attach(
                $owner,
                $artifact,
                $collection,
                $disk,
                $profile->isSingleFile(), // Indica si es un archivo único (reemplaza el anterior)
            );
        } catch (\Throwable $exception) {
            // Si ocurre un error, reportamos el fallo
            $this->reporter->report('image_upload.failed', $exception, [
                'model'      => $owner::class,
                'model_id'   => $owner->getKey(),
                'collection' => $collection,
                'disk'       => $disk,
                'file'       => $this->buildFileContext($file),
            ]);

            throw $exception;
        } finally {
            // En cualquier caso, limpiamos los recursos
            if ($quarantinePath !== null) {
                // Eliminamos el archivo de cuarentena
                $this->quarantineManager->delete($quarantinePath);
            }

            // Limpiamos el artefacto temporal
            $this->quarantineManager->cleanupArtifact($artifact);
        }
    }

    /**
     * Construye el contexto del archivo para logging y reportes.
     *
     * @param UploadedFile $file Archivo subido
     * @return array<string,mixed> Contexto del archivo
     */
    private function buildFileContext(UploadedFile $file): array
    {
        // Creamos el contexto básico con información del archivo
        $context = [
            'extension' => $file->getClientOriginalExtension(), // Extensión original
            'size'      => $file->getSize(), // Tamaño en bytes
            'mime'      => $file->getMimeType(), // Tipo MIME
        ];

        // En modo debug, añadimos hash del nombre para identificación
        if (config('app.debug', false)) {
            $context['name_hash'] = hash('sha256', (string) $file->getClientOriginalName());
        }

        return $context;
    }
}
