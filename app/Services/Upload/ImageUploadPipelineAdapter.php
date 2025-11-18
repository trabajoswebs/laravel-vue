<?php

declare(strict_types=1);

namespace App\Services\Upload;

use App\Services\ImagePipeline;
use App\Services\Upload\Contracts\UploadMetadata;
use App\Services\Upload\Contracts\UploadPipeline;
use App\Services\Upload\Contracts\UploadResult;
use App\Services\Upload\Exceptions\UploadException;
use Illuminate\Http\UploadedFile;
use SplFileObject;

/**
 * Adaptador que reutiliza el ImagePipeline existente para cumplir con UploadPipeline.
 */
final class ImageUploadPipelineAdapter implements UploadPipeline
{
    private string $workingDirectory;

    public function __construct(
        private readonly ImagePipeline $pipeline,
        string $workingDirectory = '',
    ) {
        // Define el directorio de trabajo
        $directory = $workingDirectory !== ''
            ? $workingDirectory
            : storage_path('app/uploads/tmp');

        // Crea el directorio si no existe
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UploadException('Unable to initialize working directory for uploads.');
        }

        $this->workingDirectory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    /**
     * Procesa el archivo subido y devuelve un resultado de subida.
     *
     * @param UploadedFile|SplFileObject|string $source El archivo a procesar.
     * @return UploadResult El resultado del proceso de subida.
     * @throws UploadException Si ocurre un error durante el proceso.
     */
    public function process(UploadedFile|SplFileObject|string $source): UploadResult
    {
        // Convierte SplFileObject o string a UploadedFile
        if ($source instanceof SplFileObject) {
            $source = $this->uploadedFileFromSpl($source);
        } elseif (is_string($source)) {
            $source = $this->uploadedFileFromPath($source);
        }

        // Verifica que el archivo sea una instancia de UploadedFile
        if (!$source instanceof UploadedFile) {
            throw new UploadException('Image uploads require a valid file source.');
        }

        // Procesa la imagen con el pipeline
        $result = $this->pipeline->process($source);
        // Crea un archivo temporal para almacenar la imagen procesada
        $tempPath = $this->createWorkingFile();

        // Copia la imagen procesada al archivo temporal
        if (!@copy($result->path(), $tempPath)) {
            // Limpia el resultado y el archivo temporal si falla el copiado
            $result->cleanup();
            $this->deleteFileSilently($tempPath);
            throw new UploadException('Unable to persist processed image.');
        }

        // Obtiene el tamaño de la imagen procesada
        $bytes = filesize($tempPath);
        if ($bytes === false) {
            $this->deleteFileSilently($tempPath);
            throw new UploadException('Unable to determine processed image size.');
        }

        // Crea los metadatos del archivo
        $metadata = new UploadMetadata(
            mime: $result->mime(),
            extension: $result->extension(),
            hash: $result->contentHash(),
            dimensions: [
                'width' => $result->width(),
                'height' => $result->height(),
            ],
            originalFilename: $source->getClientOriginalName(),
        );

        // Limpia el resultado del pipeline
        $result->cleanup();

        // Devuelve el resultado de la subida
        return new UploadResult(
            path: $tempPath,
            size: (int) $bytes,
            metadata: $metadata,
        );
    }

    /**
     * Crea un archivo temporal en el directorio de trabajo.
     *
     * @return string Ruta del archivo temporal.
     * @throws UploadException Si no se puede crear el archivo temporal.
     */
    private function createWorkingFile(): string
    {
        $path = tempnam($this->workingDirectory, 'upload_');
        if ($path === false) {
            throw new UploadException('Unable to allocate working file.');
        }

        return $path;
    }

    /**
     * Elimina un archivo de forma silenciosa.
     *
     * @param string|null $path Ruta del archivo a eliminar.
     */
    private function deleteFileSilently(?string $path): void
    {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Convierte un SplFileObject en un UploadedFile.
     *
     * @param SplFileObject $file El archivo SplFileObject.
     * @return UploadedFile El archivo UploadedFile.
     * @throws UploadException Si el archivo no es legible.
     */
    private function uploadedFileFromSpl(SplFileObject $file): UploadedFile
    {
        $path = $file->getRealPath();
        if (!is_string($path) || $path === '') {
            throw new UploadException('SplFileObject must reference a readable file.');
        }

        return new UploadedFile($path, basename($path), null, null, true);
    }

    /**
     * Convierte una ruta de archivo en un UploadedFile.
     *
     * @param string $path La ruta del archivo.
     * @return UploadedFile El archivo UploadedFile.
     * @throws UploadException Si el archivo no es legible.
     */
    private function uploadedFileFromPath(string $path): UploadedFile
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new UploadException('File path is not readable.');
        }

        return new UploadedFile($path, basename($path), null, null, true);
    }
}