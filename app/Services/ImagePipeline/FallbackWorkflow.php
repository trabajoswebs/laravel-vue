<?php

declare(strict_types=1);

namespace App\Services\ImagePipeline;

use App\Services\ImagePipelineResult;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

/**
 * Implementa el flujo alternativo basado en GD/operaciones nativas cuando Imagick no está disponible.
 * 
 * Este servicio se encarga de procesar imágenes subidas, verificando dimensiones y tamaño,
 * y redimensionándolas si es necesario, usando funciones nativas de PHP y GD como respaldo.
 * 
 * @example
 * $workflow = new FallbackWorkflow($config, $artifacts, $logger);
 * $result = $workflow->process($uploadedFile, [
 *     'size' => 1024000,
 *     'real_path' => '/tmp/phpXXXXXX',
 *     'mime' => 'image/jpeg'
 * ]);
 * 
 * @see ImagePipelineResult
 */
final class FallbackWorkflow
{
    public function __construct(
        private readonly PipelineConfig $config,
        private readonly PipelineArtifacts $artifacts,
        private readonly PipelineLogger $logger,
    ) {}

    /**
     * Procesa una imagen subida y devuelve un objeto ImagePipelineResult.
     * 
     * Realiza las siguientes operaciones:
     * 1. Valida el archivo y obtiene sus dimensiones.
     * 2. Verifica que las dimensiones y megapíxeles estén dentro de los límites configurados.
     * 3. Determina dimensiones objetivo y re-encodea con GD (siempre limpia metadata).
     * 4. Calcula el hash del contenido y devuelve un objeto con la información procesada.
     * 
     * @param UploadedFile $file El archivo de imagen subido.
     * @param array{size:int, real_path:string, mime:string, width?:int, height?:int} $descriptor Información del archivo.
     * @return ImagePipelineResult Objeto con la información procesada de la imagen.
     * @throws InvalidArgumentException Si la imagen no se puede cargar, es demasiado pequeña o excede los megapíxeles.
     * @throws RuntimeException Si falla el procesamiento o la creación del archivo temporal.
     */
    public function process(UploadedFile $file, array $descriptor): ImagePipelineResult
    {
        $realPath = $descriptor['real_path'];
        $mime = $descriptor['mime'];

        // Valida el archivo de imagen
        $imageInfo = @\getimagesize($realPath);
        if ($imageInfo === false) {
            throw new InvalidArgumentException(__('image-pipeline.image_load_failed'));
        }

        $originalWidth = (int) ($descriptor['width'] ?? ($imageInfo[0] ?? 0));
        $originalHeight = (int) ($descriptor['height'] ?? ($imageInfo[1] ?? 0));

        if ($originalWidth < $this->config->minDimension || $originalHeight < $this->config->minDimension) {
            throw new InvalidArgumentException(__('image-pipeline.dimensions_too_small'));
        }

        $megapixels = ($originalWidth * $originalHeight) / 1_000_000;
        if ($megapixels > $this->config->maxMegapixels) {
            throw new InvalidArgumentException(__('image-pipeline.megapixels_exceeded'));
        }

        // Prepara el archivo temporal y determina la extensión
        $extension = $this->resolveExtension($file, $mime);
        $tempPath = $this->artifacts->tempFilePath($extension);

        $targetDimensions = $this->shouldResizeFallback($originalWidth, $originalHeight)
            ? $this->scaledDimensions($originalWidth, $originalHeight, $this->config->maxEdge)
            : ['width' => $originalWidth, 'height' => $originalHeight];

        $scaledWidth = $targetDimensions['width'];
        $scaledHeight = $targetDimensions['height'];

        if (!$this->canResizeWithGd($mime)) {
            $this->logger->log('error', 'image_pipeline_fallback_encoder_unavailable', [
                'mime' => $mime,
                'gd_available' => $this->config->gdAvailable,
            ]);
            $this->artifacts->safeUnlink($tempPath);
            throw new RuntimeException(__('image-pipeline.processing_failed'));
        }

        if (!$this->resizeWithGd($realPath, $tempPath, $mime, $scaledWidth, $scaledHeight)) {
            $this->logger->log('error', 'image_pipeline_fallback_reencode_failed', [
                'mime' => $mime,
                'width' => $originalWidth,
                'height' => $originalHeight,
                'target_width' => $scaledWidth,
                'target_height' => $scaledHeight,
            ]);
            $this->artifacts->safeUnlink($tempPath);
            throw new RuntimeException(__('image-pipeline.processing_failed'));
        }

        // Obtiene el tamaño final del archivo
        $bytes = @\filesize($tempPath);
        if (!\is_int($bytes) || $bytes <= 0) {
            $this->artifacts->safeUnlink($tempPath);
            throw new RuntimeException(__('image-pipeline.temp_file_invalid'));
        }

        // Calcula el hash y registra el uso del fallback
        $hash = $this->artifacts->computeContentHash($tempPath);

        $this->logger->log('notice', 'image_pipeline.fallback_used', [
            'mime' => $mime,
            'extension' => $extension,
            'width' => $scaledWidth,
            'height' => $scaledHeight,
        ]);

        return new ImagePipelineResult(
            path: $tempPath,
            mime: $mime,
            extension: $extension,
            width: $scaledWidth,
            height: $scaledHeight,
            bytes: (int) $bytes,
            contentHash: $hash,
        );
    }

    /**
     * Determina la extensión correcta para el archivo de salida.
     * Prioriza la configuración de mimes permitidos, luego intenta obtenerla del nombre original,
     * y finalmente la adivina según el tipo MIME.
     *
     * @param UploadedFile $file El archivo subido.
     * @param string $mime El tipo MIME de la imagen.
     * @return string La extensión del archivo (e.g., 'jpg', 'png').
     */
    private function resolveExtension(UploadedFile $file, string $mime): string
    {
        $extension = $this->config->allowedMimes[$mime] ?? \strtolower(
            (string) \pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION)
        );

        if ($extension !== '') {
            return $extension;
        }

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };
    }

    /**
     * Verifica si la imagen necesita ser redimensionada según la dimensión máxima permitida.
     *
     * @param int $width Ancho original.
     * @param int $height Alto original.
     * @return bool True si la imagen es demasiado grande y debe redimensionarse.
     */
    private function shouldResizeFallback(int $width, int $height): bool
    {
        return $width > $this->config->maxEdge || $height > $this->config->maxEdge;
    }

    /**
     * Calcula las nuevas dimensiones manteniendo la proporción, asegurando que el lado más largo
     * no exceda el valor $maxEdge.
     *
     * @param int $width Ancho original.
     * @param int $height Alto original.
     * @param int $maxEdge Dimensión máxima permitida para el lado más largo.
     * @return array{width:int,height:int} Nuevo ancho y alto calculados.
     * @example scaledDimensions(2000, 1000, 800) -> ['width' => 800, 'height' => 400]
     */
    private function scaledDimensions(int $width, int $height, int $maxEdge): array
    {
        $scale = $maxEdge / (float) \max($width, $height);
        if ($scale >= 1.0) {
            return ['width' => $width, 'height' => $height];
        }

        return [
            'width'  => \max(1, (int) \floor($width * $scale)),
            'height' => \max(1, (int) \floor($height * $scale)),
        ];
    }

    /**
     * Comprueba si GD puede manipular el tipo MIME especificado.
     * Revisa si GD está disponible y si existen las funciones necesarias para el formato.
     *
     * @param string $mime Tipo MIME de la imagen.
     * @return bool True si GD puede manejar este formato.
     */
    private function canResizeWithGd(string $mime): bool
    {
        if (!$this->config->gdAvailable || !\function_exists('imagecreatetruecolor')) {
            return false;
        }

        return match ($mime) {
            'image/jpeg' => \function_exists('imagecreatefromjpeg') && \function_exists('imagejpeg'),
            'image/png'  => \function_exists('imagecreatefrompng') && \function_exists('imagepng'),
            'image/webp' => \function_exists('imagecreatefromwebp') && \function_exists('imagewebp'),
            'image/gif'  => \function_exists('imagecreatefromgif') && \function_exists('imagegif'),
            default      => false,
        };
    }

    /**
     * Redimensiona una imagen usando funciones GD.
     * Maneja transparencias para PNG, WebP y GIF.
     * Devuelve true si la operación fue exitosa.
     *
     * @param string $source Ruta del archivo original.
     * @param string $target Ruta donde se guardará la imagen redimensionada.
     * @param string $mime Tipo MIME de la imagen.
     * @param int $targetWidth Ancho deseado.
     * @param int $targetHeight Alto deseado.
     * @return bool True si se redimensionó y guardó correctamente.
     */
    private function resizeWithGd(string $source, string $target, string $mime, int $targetWidth, int $targetHeight): bool
    {
        // Mapea las funciones de creación y guardado según el tipo MIME
        $create = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png'  => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            'image/gif'  => 'imagecreatefromgif',
            default      => null,
        };

        $save = match ($mime) {
            'image/jpeg' => fn($img, $path) => \imagejpeg($img, $path, $this->config->jpegQuality),
            'image/png'  => fn($img, $path) => \imagepng($img, $path, \min(9, $this->config->pngCompressionLevel)),
            'image/webp' => fn($img, $path) => \imagewebp($img, $path, $this->config->webpQuality),
            'image/gif'  => fn($img, $path) => \imagegif($img, $path),
            default      => null,
        };

        if ($create === null || $save === null) {
            return false;
        }

        try {
            $src = @$create($source);
            if (!$src) {
                return false;
            }

            $srcWidth = \imagesx($src);
            $srcHeight = \imagesy($src);
            if ($srcWidth <= 0 || $srcHeight <= 0) {
                \imagedestroy($src);
                return false;
            }

            $dst = \imagecreatetruecolor($targetWidth, $targetHeight);
            if (!$dst) {
                \imagedestroy($src);
                return false;
            }

            // Configura transparencia para PNG y WebP
            if (\in_array($mime, ['image/png', 'image/webp'], true)) {
                \imagealphablending($dst, false);
                \imagesavealpha($dst, true);
            }

            // Maneja transparencia para GIF
            if ($mime === 'image/gif') {
                $transparentIndex = \imagecolortransparent($src);
                if ($transparentIndex >= 0) {
                    $transparentColor = \imagecolorsforindex($src, $transparentIndex);
                    $allocated = \imagecolorallocatealpha(
                        $dst,
                        $transparentColor['red'],
                        $transparentColor['green'],
                        $transparentColor['blue'],
                        127
                    );
                    \imagefilledrectangle($dst, 0, 0, $targetWidth, $targetHeight, $allocated);
                    \imagecolortransparent($dst, $allocated);
                }
            }

            // Copia y redimensiona la imagen
            if (!\imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcWidth, $srcHeight)) {
                \imagedestroy($src);
                \imagedestroy($dst);
                return false;
            }

            $saved = $save($dst, $target);
            \imagedestroy($src);
            \imagedestroy($dst);

            if (!$saved) {
                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            $this->logger->log('warning', 'image_pipeline_gd_resize_exception', [
                'error' => $this->logger->limit($exception->getMessage()),
            ]);

            return false;
        }
    }
}
