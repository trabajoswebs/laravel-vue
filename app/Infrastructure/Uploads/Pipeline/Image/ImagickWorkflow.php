<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para componentes del pipeline de imágenes.
namespace App\Infrastructure\Uploads\Pipeline\Image;

// 3. Importaciones de clases y facades necesarios.
use Imagick;
use Throwable;
use App\Infrastructure\Uploads\Pipeline\Image\ImagePipelineResult;
use App\Infrastructure\Uploads\Pipeline\Image\ImageProcessingException;

/**
 * Encapsula el flujo basado en Imagick para normalizar imágenes.
 * 
 * Este servicio se encarga de procesar imágenes usando la biblioteca Imagick,
 * lo cual es el flujo principal si está disponible. Realiza operaciones como
 * normalización de orientación, manejo de GIFs animados, verificación de dimensiones,
 * redimensión y conversión de formato según sea necesario.
 * 
 * @example
 * $workflow = new ImagickWorkflow($config, $artifacts, $logger);
 * $result = $workflow->process([
 *     'size' => 1024000,
 *     'real_path' => '/tmp/phpXXXXXX',
 *     'mime' => 'image/jpeg'
 * ]);
 * 
 * @see ImagePipelineResult
 */
final class ImagickWorkflow
{
    /**
     * Constructor del servicio.
     *
     * @param PipelineConfig $config Configuración del pipeline.
     * @param PipelineArtifacts $artifacts Servicio para manejar archivos temporales y artefactos.
     * @param PipelineLogger $logger Servicio para registrar logs.
     */
    public function __construct(
        private readonly PipelineConfig $config,       // 4. Configuración del pipeline.
        private readonly PipelineArtifacts $artifacts, // 5. Servicio para manejar archivos temporales.
        private readonly PipelineLogger $logger,       // 6. Servicio para registrar logs.
    ) {}

    /**
     * Procesa una imagen usando Imagick y devuelve un objeto ImagePipelineResult.
     * 
     * El flujo de procesamiento es el siguiente:
     * 1. Lee la imagen con Imagick.
     * 2. Normaliza GIFs animados (coalesce o extrae primer frame).
     * 3. Sanitiza imágenes estáticas (orientación, metadata, color).
     * 4. Verifica dimensiones mínimas y máximas en megapíxeles.
     * 5. Redimensiona si excede el tamaño máximo permitido.
     * 6. Determina el formato de salida (JPEG, PNG, WebP, GIF) basado en transparencia y configuración.
     * 7. Prepara la imagen para su codificación con calidad y opciones específicas.
     * 8. Escribe la imagen resultante en un archivo temporal.
     * 9. Calcula el hash y devuelve un objeto ImagePipelineResult.
     * 
     * @param array{size:int, real_path:string, mime:string, width?:int, height?:int} $descriptor Información del archivo de imagen.
     * @return ImagePipelineResult Objeto con la información procesada de la imagen.
     * @throws ImageProcessingException Si ocurre un error durante el procesamiento.
     */
    public function process(array $descriptor): ImagePipelineResult
    {
        $mime = $descriptor['mime'];
        // 7. Lee la imagen original con Imagick.
        $imagick = $this->readImagick($descriptor);

        try {
            // 8. Normaliza GIFs y sanitiza la imagen.
            $imagick = $this->normalizeGifSequence($imagick, $mime);
            $this->sanitizeStaticImage($imagick, $mime);

            // 9. Obtiene y verifica dimensiones.
            [$width, $height] = $this->dimensions($imagick);
            $this->assertDimensions($width, $height);

            // 10. Redimensiona si es necesario.
            [$imagick, $width, $height] = $this->resizeIfNeeded($imagick, $mime, $width, $height);

            // 11. Determina formato de salida basado en transparencia.
            $hasAlpha = $this->hasAlphaChannel($imagick);
            [$format, $extension] = $this->resolveEncoding($mime, $hasAlpha);

            // 12. Prepara y escribe la imagen codificada.
            $this->prepareForEncoding($imagick, $format, $width, $height);
            $tempPath = $this->writeEncodedImage($imagick, $format, $extension);
            $bytes = $this->artifacts->ensureTempFileValid($tempPath);
            $hash = $this->artifacts->computeContentHash($tempPath);
            $outputMime = $this->artifacts->mimeFromExtension($extension);

            // 13. Crea y devuelve el resultado del procesamiento.
            return new ImagePipelineResult(
                path: $tempPath,
                mime: $outputMime,
                extension: $extension,
                width: $width,
                height: $height,
                bytes: $bytes,
                contentHash: $hash,
            );
        } catch (ImageProcessingException $exception) {
            // 14. Registra un error si falla el procesamiento y relanza la excepción.
            $this->logger->log('error', 'image_pipeline_failed', [
                'error' => $this->logger->limit($exception->getMessage()),
                'mime'  => $mime,
                'reason' => $exception->reason(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            // 15. Registra un error inesperado y lanza una excepción recuperable.
            $this->logger->log('error', 'image_pipeline_failed', [
                'error' => $this->logger->limit($exception->getMessage()),
                'mime'  => $mime,
            ]);

            throw new ImageProcessingException(
                reason: 'imagick_unhandled_exception',
                message: $exception->getMessage(),
                recoverable: true,
                context: ['mime' => $mime],
                previous: $exception,
            );
        } finally {
            // 16. Limpia los recursos de Imagick para evitar fugas de memoria.
            $imagick->clear();
            $imagick->destroy();
        }
    }

    /**
     * Lee un archivo de imagen en una instancia de Imagick.
     *
     * @param array{real_path:string, mime:string, width?:int, height?:int, size?:int} $descriptor Ruta y metadata básica.
     * @return Imagick Instancia de Imagick cargada con la imagen.
     * @throws ImageProcessingException Si no se puede leer la imagen.
     */
    private function readImagick(array $descriptor): Imagick
    {
        $realPath = $descriptor['real_path'] ?? null;
        if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
            throw new ImageProcessingException(
                reason: 'imagick_precheck_failed',
                message: __('image-pipeline.image_load_failed'),
                recoverable: true
            );
        }

        $precheck = @getimagesize($realPath);
        if ($precheck === false || !isset($precheck[0], $precheck[1])) {
            throw new ImageProcessingException(
                reason: 'imagick_precheck_failed',
                message: __('image-pipeline.image_load_failed'),
                recoverable: true
            );
        }

        $width = (int) $precheck[0];
        $height = (int) $precheck[1];
        $maxEdge = max($this->config->maxEdge, 1);
        if ($width > $maxEdge || $height > $maxEdge) {
            throw new ImageProcessingException(
                reason: 'dimensions_exceed_limit',
                message: __('image-pipeline.dimensions_too_large'),
                recoverable: false,
                context: ['width' => $width, 'height' => $height, 'max_edge' => $maxEdge]
            );
        }

        $megapixels = ($width * $height) / 1_000_000;
        if ($megapixels > $this->config->maxMegapixels) {
            throw new ImageProcessingException(
                reason: 'megapixels_exceeded',
                message: __('image-pipeline.megapixels_exceeded'),
                recoverable: false,
                context: ['megapixels' => $megapixels]
            );
        }

        $imagick = new Imagick();
        // 17. Aplica límites defensivos de recursos para evitar problemas de memoria.
        $this->applyResourceLimits($imagick, $descriptor);
        // 18. Intenta leer la imagen desde la ruta especificada.
        $imagick->readImage($realPath);

        if ($imagick->valid()) {
            return $imagick;
        }

        // 19. Si la lectura falla, limpia y lanza una excepción.
        $imagick->clear();
        $imagick->destroy();

        throw new ImageProcessingException(
            reason: 'imagick_read_failed',
            message: __('image-pipeline.image_load_failed'),
            recoverable: true
        );
    }

    /**
     * Configura límites defensivos de recursos sobre la instancia de Imagick.
     *
     * @param Imagick $imagick
     * @param array{mime:string,width?:int,height?:int,size?:int} $descriptor
     */
    private function applyResourceLimits(Imagick $imagick, array $descriptor): void
    {
        // 20. Calcula límites basados en la configuración.
        $maxBytes = max($this->config->maxBytes, 1);
        $pixelBudget = (int) ceil(max($this->config->maxMegapixels, 0.1) * 1_000_000);
        $maxEdge = max($this->config->maxEdge, 1);

        $memoryLimit = max(1, (int) config('image-pipeline.resource_limits.imagick.memory_mb', 256)) * 1024 * 1024;
        $mapLimit = max(1, (int) config('image-pipeline.resource_limits.imagick.map_mb', 512)) * 1024 * 1024;
        $threadLimit = max(1, (int) config('image-pipeline.resource_limits.imagick.threads', 2));

        $limitsApplied = 0;
        try {
            if ($imagick->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, $memoryLimit)) {
                $limitsApplied++;
            }
            if ($imagick->setResourceLimit(Imagick::RESOURCETYPE_MAP, $mapLimit)) {
                $limitsApplied++;
            }
            if ($imagick->setResourceLimit(Imagick::RESOURCETYPE_AREA, $pixelBudget)) {
                $limitsApplied++;
            }
            if (defined(Imagick::class . '::RESOURCETYPE_WIDTH')) {
                $resource = constant(Imagick::class . '::RESOURCETYPE_WIDTH');
                if ($imagick->setResourceLimit($resource, $maxEdge)) {
                    $limitsApplied++;
                }
            }
            if (defined(Imagick::class . '::RESOURCETYPE_HEIGHT')) {
                $resource = constant(Imagick::class . '::RESOURCETYPE_HEIGHT');
                if ($imagick->setResourceLimit($resource, $maxEdge)) {
                    $limitsApplied++;
                }
            }
            if (defined(Imagick::class . '::RESOURCETYPE_THREAD')) {
                $resource = constant(Imagick::class . '::RESOURCETYPE_THREAD');
                if ($imagick->setResourceLimit($resource, $threadLimit)) {
                    $limitsApplied++;
                }
            }
        } catch (Throwable $exception) {
            // 22. Registra un error si no se pueden aplicar los límites.
            $this->logger->log('warning', 'image_pipeline_resource_limits_failed', [
                'error' => $this->logger->limit($exception->getMessage()),
            ]);
        }

        if ($limitsApplied === 0) {
            throw new ImageProcessingException(
                reason: 'resource_limits_unavailable',
                message: __('image-pipeline.resource_limits_failed'),
                recoverable: true
            );
        }

        // 23. Establece el tamaño esperado si está disponible en el descriptor.
        if (isset($descriptor['width'], $descriptor['height'])) {
            $imagick->setSize((int) $descriptor['width'], (int) $descriptor['height']);
        }
    }

    /**
     * Normaliza una secuencia de GIF, coalescando si está animado y la configuración lo permite,
     * o extrayendo solo el primer frame.
     *
     * @param Imagick $image Instancia de Imagick.
     * @param string $mime Tipo MIME de la imagen.
     * @return Imagick Nueva instancia de Imagick con la imagen normalizada.
     */
    private function normalizeGifSequence(Imagick $image, string $mime): Imagick
    {
        // 24. Si no es un GIF o no está animado, lo devuelve tal cual.
        if ($mime !== 'image/gif' || $image->getNumberImages() <= 1) {
            return $image;
        }

        $frameCount = $image->getNumberImages();
        if ($frameCount > $this->config->maxGifFrames) {
            throw new ImageProcessingException(
                reason: 'gif_too_many_frames',
                message: __('image-pipeline.gif_too_many_frames', ['max' => $this->config->maxGifFrames]),
                recoverable: false,
                context: ['frames' => $frameCount]
            );
        }

        if ($this->config->preserveGifAnimation) {
            // 25. Si se debe preservar la animación, coalesca y sanitiza los frames.
            $coalesced = $this->sanitizeGifAnimation($image);
            return $this->replaceImagickInstance($image, $coalesced);
        }

        // 26. Si no se debe preservar la animación, extrae solo el primer frame.
        $firstFrame = $this->extractGifFirstFrame($image);
        return $this->replaceImagickInstance($image, $firstFrame);
    }

    /**
     * Extrae solo el primer frame de un GIF animado.
     *
     * @param Imagick $image Instancia de Imagick con el GIF.
     * @return Imagick Nueva instancia de Imagick con solo el primer frame.
     * @throws ImageProcessingException Si falla al clonar el frame.
     */
    private function extractGifFirstFrame(Imagick $image): Imagick
    {
        $image->setFirstIterator();

        try {
            $currentFrame = $image->getImage();
            $single = new Imagick();
            $single->addImage($currentFrame);
            $single->setFormat('gif');
            $single->setImageIterations(1);
            $single->setFirstIterator();

            $currentFrame->clear();
            $currentFrame->destroy();

            return $single;
        } catch (Throwable $exception) {
            // 28. Registra un error si falla el clonado y lanza una excepción recuperable.
            $this->logger->log('error', 'image_pipeline_gif_clone_failed', [
                'error' => $this->logger->limit($exception->getMessage()),
            ]);

            throw new ImageProcessingException(
                reason: 'gif_clone_failed',
                message: __('image-pipeline.gif_clone_failed'),
                recoverable: true,
                previous: $exception,
            );
        }
    }

    /**
     * Coalesca y sanitiza cada frame de un GIF animado.
     *
     * @param Imagick $image Instancia de Imagick con el GIF animado.
     * @return Imagick Nueva instancia de Imagick con los frames coalescados y sanitizados.
     * @throws ImageProcessingException Si un frame es inválido.
     */
    private function sanitizeGifAnimation(Imagick $image): Imagick
    {
        // 29. Coalesca todos los frames en una secuencia.
        $coalesced = $image->coalesceImages();
        $sanitized = new Imagick();
        $sanitized->setFormat('gif');

        $index = 0;

        foreach ($coalesced as $frame) {
            if ($index >= $this->config->maxGifFrames) {
                break;
            }

            if (!$frame->valid()) {
                $message = __('image-pipeline.gif_frame_invalid', ['index' => $index]);
                $this->logger->log('warning', $message, ['index' => $index]);

                throw new ImageProcessingException(
                    reason: 'gif_frame_invalid',
                    message: $message,
                    recoverable: true,
                    context: ['frame_index' => $index],
                );
            }

            // 30. Aplica sanitización a cada frame válido.
            $this->autoOrient($frame);
            $frame->stripImage();
            $this->toSrgb($frame);

            $delay = max(1, $frame->getImageDelay());
            $frame->setImageDelay($delay);

            $frameClone = clone $frame;
            $frameClone->setImageDelay($delay);
            $frameClone->setImageDispose($frame->getImageDispose());

            $sanitized->addImage($frameClone);
            $sanitized->setImageDelay($delay);
            $sanitized->setImageDispose($frameClone->getImageDispose());

            $index++;
        }

        $coalesced->clear();
        $coalesced->destroy();

        $sanitized->setFirstIterator();
        $sanitized->setImageIterations(0);

        return $sanitized;
    }

    /**
     * Aplica sanitización a una imagen estática (no GIF animado).
     * Rotación automática, remoción de metadata y conversión a sRGB.
     *
     * @param Imagick $image Instancia de Imagick.
     * @param string $mime Tipo MIME.
     */
    private function sanitizeStaticImage(Imagick $image, string $mime): void
    {
        // 32. Solo aplica sanitización si no es un GIF animado.
        if ($mime === 'image/gif' && $image->getNumberImages() > 1) {
            return;
        }

        // 33. Aplica rotación automática, remueve metadata y convierte a sRGB.
        $this->autoOrient($image);
        $image->stripImage();
        $this->toSrgb($image);
    }

    /**
     * Redimensiona la imagen si sus dimensiones exceden el tamaño máximo configurado.
     *
     * @param Imagick $image Instancia de Imagick.
     * @param string $mime Tipo MIME.
     * @param int $width Ancho actual.
     * @param int $height Alto actual.
     * @return array{0:Imagick,1:int,2:int} Nueva instancia de Imagick y nuevas dimensiones.
     */
    private function resizeIfNeeded(Imagick $image, string $mime, int $width, int $height): array
    {
        $maxEdge = \max($width, $height);
        // 34. Si no excede el tamaño máximo, devuelve la imagen sin cambios.
        if ($maxEdge <= $this->config->maxEdge) {
            return [$image, $width, $height];
        }

        // 35. Calcula las nuevas dimensiones manteniendo la proporción.
        $scale = $this->config->maxEdge / $maxEdge;
        $newWidth = \max(1, (int) \floor($width * $scale));
        $newHeight = \max(1, (int) \floor($height * $scale));

        if ($mime === 'image/gif' && $image->getNumberImages() > 1) {
            // 36. Si es un GIF animado, redimensiona cada frame.
            $resized = $this->buildLimitedGif($image, $newWidth, $newHeight);
            $image = $this->replaceImagickInstance($image, $resized);
        } else {
            // 37. Si es una imagen estática, redimensiona directamente.
            $image->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1, true);
        }

        return [$image, $newWidth, $newHeight];
    }

    /**
     * Determina el formato y extensión de salida basado en el tipo original y si tiene transparencia.
     *
     * @param string $sourceMime Tipo MIME original.
     * @param bool $hasAlpha Indica si la imagen tiene canal alfa.
     * @return array{0:string,1:string} Formato y extensión para la salida.
     * @example resolveEncoding('image/png', true) -> ['webp', 'webp'] (si alphaToWebp=true)
     * @example resolveEncoding('image/png', false) -> ['jpeg', 'jpg']
     */
    private function resolveEncoding(string $sourceMime, bool $hasAlpha): array
    {
        // 38. Decide el formato de salida basado en el tipo original y la transparencia.
        $format = match ($sourceMime) {
            'image/gif'  => $this->config->preserveGifAnimation ? 'gif' : ($hasAlpha ? 'png' : 'jpeg'),
            'image/png'  => $hasAlpha ? ($this->config->alphaToWebp ? 'webp' : 'png') : 'jpeg',
            'image/webp' => 'webp',
            'image/avif' => $hasAlpha ? 'webp' : 'jpeg',
            default      => 'jpeg',
        };

        // 39. Si tiene transparencia y se permite, convierte a WebP.
        if ($hasAlpha && $this->config->alphaToWebp) {
            $format = 'webp';
        }

        // 40. Decide la extensión basada en el formato o el tipo original.
        $extension = $this->config->allowedMimes[$sourceMime] ?? match ($format) {
            'gif'  => 'gif',
            'png'  => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };

        // 41. Asegura que la extensión para JPEG sea 'jpg'.
        if ($format === 'jpeg') {
            $extension = 'jpg';
        }

        return [$format, $extension];
    }

    /**
     * Prepara la imagen Imagick para ser codificada, aplicando calidad, filtros y formato.
     *
     * @param Imagick $image Instancia de Imagick.
     * @param string $format Formato de salida ('jpeg', 'webp', 'png', 'gif').
     * @param int $width Ancho de la imagen.
     * @param int $height Alto de la imagen.
     */
    private function prepareForEncoding(Imagick $image, string $format, int $width, int $height): void
    {
        // 42. Aplica ajustes específicos según el formato de salida.
        switch ($format) {
            case 'jpeg':
                $image->setImageFormat('jpeg');
                $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality($this->config->jpegQuality);
                // 43. Decide si usar JPEG progresivo basado en el tamaño.
                if (\max($width, $height) >= $this->config->jpegProgressiveMin) {
                    $image->setInterlaceScheme(Imagick::INTERLACE_JPEG);
                } else {
                    $image->setInterlaceScheme(Imagick::INTERLACE_NO);
                }
                break;
            case 'webp':
                $image->setImageFormat('webp');
                $image->setOption('webp:method', (string) $this->config->webpMethod);
                $image->setOption('webp:thread-level', '1');
                $image->setImageCompressionQuality($this->config->webpQuality);
                break;
            case 'png':
                $image->setImageFormat('png');
                $image->setOption('png:compression-filter', (string) $this->config->pngCompressionFilter);
                $image->setOption('png:compression-level', (string) $this->config->pngCompressionLevel);
                $image->setOption('png:compression-strategy', (string) $this->config->pngCompressionStrategy);
                $image->setOption('png:exclude-chunk', $this->config->pngExcludeChunk);
                break;
            case 'gif':
                $image->setImageFormat('gif');
                break;
            default:
                $image->setImageFormat($format);
                break;
        }
    }

    /**
     * Escribe la imagen Imagick en un archivo temporal.
     *
     * @param Imagick $image Instancia de Imagick.
     * @param string $format Formato de imagen.
     * @param string $extension Extensión del archivo.
     * @return string Ruta del archivo temporal generado.
     * @throws ImageProcessingException Si falla al escribir el archivo.
     */
    private function writeEncodedImage(Imagick $image, string $format, string $extension): string
    {
        // 44. Genera una ruta temporal para el archivo.
        $tempPath = $this->artifacts->tempFilePath($extension);
        // 45. Escribe la imagen en el archivo temporal.
        $written = $format === 'gif' && $image->getNumberImages() > 1
            ? $image->writeImages($tempPath, true) // Para GIFs animados
            : $image->writeImage($tempPath);       // Para imágenes estáticas

        if ($written === true) {
            $outputSize = @filesize($tempPath);
            if ($outputSize === false || $outputSize > $this->config->maxBytes) {
                $this->artifacts->safeUnlink($tempPath);

                throw new ImageProcessingException(
                    reason: 'output_too_large',
                    message: __('image-pipeline.output_too_large'),
                    recoverable: false,
                    context: ['size' => $outputSize]
                );
            }

            return $tempPath;
        }

        // 46. Si falla la escritura, elimina el archivo temporal y lanza una excepción.
        $this->artifacts->safeUnlink($tempPath);

        throw new ImageProcessingException(
            reason: 'imagick_write_failed',
            message: __('image-pipeline.processing_failed'),
            recoverable: true
        );
    }

    /**
     * Reemplaza una instancia de Imagick por otra y libera la memoria de la anterior.
     *
     * @param Imagick $current Instancia actual.
     * @param Imagick $replacement Nueva instancia.
     * @return Imagick La nueva instancia.
     */
    private function replaceImagickInstance(Imagick $current, Imagick $replacement): Imagick
    {
        // 47. Libera la memoria de la instancia actual.
        $current->clear();
        $current->destroy();

        return $replacement;
    }

    /**
     * Crea una versión redimensionada de un GIF limitando el número de frames.
     *
     * @param Imagick $src Instancia original con todos los frames.
     * @param int $width Nuevo ancho.
     * @param int $height Nuevo alto.
     * @return Imagick Nueva instancia con los frames limitados y redimensionados.
     */
    private function buildLimitedGif(Imagick $src, int $width, int $height): Imagick
    {
        $limited = new Imagick();
        $limited->setFormat('gif');

        $count = 0;
        foreach ($src as $frame) {
            if ($count >= $this->config->maxGifFrames) {
                break;
            }

            // 48. Clona, redimensiona y agrega cada frame al nuevo GIF.
            $clone = clone $frame;
            $clone->resizeImage($width, $height, $this->config->gifResizeFilter, 1, true);

            $delay = max(1, $clone->getImageDelay());
            $clone->setImageDelay($delay);

            $limited->addImage($clone);
            $limited->setImageDelay($delay);
            $limited->setImageDispose($clone->getImageDispose());

            $count++;
        }

        // 50. Deconstruye las imágenes para crear un GIF optimizado.
        $result = $limited->deconstructImages();
        $result->setFirstIterator();
        $result->setImageIterations(0);

        return $result;
    }

    /**
     * Corrige la orientación de la imagen según el EXIF.
     * Intenta usar el método autoOrient si está disponible, si no, lo hace manualmente.
     *
     * @param Imagick $image Instancia de Imagick.
     */
    private function autoOrient(Imagick $image): void
    {
        if (\method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        } else {
            $orientation = $image->getImageOrientation();
            switch ($orientation) {
                case Imagick::ORIENTATION_TOPRIGHT:
                    $image->flopImage();
                    break;
                case Imagick::ORIENTATION_BOTTOMRIGHT:
                    $image->rotateImage('#000', 180);
                    break;
                case Imagick::ORIENTATION_BOTTOMLEFT:
                    $image->flipImage();
                    break;
                case Imagick::ORIENTATION_LEFTTOP:
                    $image->flopImage();
                    $image->rotateImage('#000', 90);
                    break;
                case Imagick::ORIENTATION_RIGHTTOP:
                    $image->rotateImage('#000', 90);
                    break;
                case Imagick::ORIENTATION_RIGHTBOTTOM:
                    $image->flopImage();
                    $image->rotateImage('#000', -90);
                    break;
                case Imagick::ORIENTATION_LEFTBOTTOM:
                    $image->rotateImage('#000', -90);
                    break;
                default:
                    break;
            }
        }

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    /**
     * Fuerza el espacio de color de la imagen a sRGB.
     * Útil para convertir imágenes CMYK a RGB.
     *
     * @param Imagick $image Instancia de Imagick.
     */
    private function toSrgb(Imagick $image): void
    {
        try {
            if (\method_exists($image, 'getImageColorspace') && $image->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                $this->logger->log('notice', 'image_pipeline_cmyk_to_srgb', []);
                $image->stripImage();
                $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                return;
            }

            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
        } catch (Throwable $exception) {
            // 55. Registra un error si falla la conversión.
            $this->logger->log('debug', 'image_pipeline_srgb_failed', [
                'error' => $this->logger->limit($exception->getMessage()),
            ]);
        }
    }

    /**
     * Obtiene las dimensiones de la primera imagen de la secuencia.
     *
     * @param Imagick $image Instancia de Imagick.
     * @return array{0:int,1:int} Ancho y alto.
     */
    private function dimensions(Imagick $image): array
    {
        if ($image->getNumberImages() > 1) {
            // 56. Si es una secuencia (como un GIF animado), se posiciona en el primer frame.
            $image->setFirstIterator();
        }

        // 57. Obtiene el ancho y alto del frame actual.
        return [(int) $image->getImageWidth(), (int) $image->getImageHeight()];
    }

    /**
     * Verifica que las dimensiones estén dentro de los límites configurados.
     *
     * @param int $width Ancho de la imagen.
     * @param int $height Alto de la imagen.
     * @throws ImageProcessingException Si las dimensiones son inválidas.
     */
    private function assertDimensions(int $width, int $height): void
    {
        // 58. Verifica si las dimensiones son demasiado pequeñas.
        if ($width < $this->config->minDimension || $height < $this->config->minDimension) {
            throw new ImageProcessingException(
                reason: 'dimensions_too_small',
                message: __('image-pipeline.dimensions_too_small'),
                recoverable: false,
                context: ['width' => $width, 'height' => $height],
            );
        }

        // 59. Verifica si las dimensiones exceden el límite de megapíxeles.
        $megapixels = ($width * $height) / 1_000_000;
        if ($megapixels > $this->config->maxMegapixels) {
            throw new ImageProcessingException(
                reason: 'megapixels_exceeded',
                message: __('image-pipeline.megapixels_exceeded'),
                recoverable: false,
                context: ['width' => $width, 'height' => $height, 'megapixels' => $megapixels],
            );
        }
    }

    /**
     * Determina si la imagen tiene un canal alfa (transparencia).
     * Para GIFs animados, revisa cada frame.
     *
     * @param Imagick $image Instancia de Imagick.
     * @return bool True si tiene canal alfa.
     */
    private function hasAlphaChannel(Imagick $image): bool
    {
        if ($image->getNumberImages() <= 1) {
            // 60. Para imágenes estáticas, revisa directamente el canal alfa.
            return \method_exists($image, 'getImageAlphaChannel') && $image->getImageAlphaChannel();
        }

        // 61. Para GIFs animados, revisa cada frame.
        foreach ($image as $frame) {
            if (\method_exists($frame, 'getImageAlphaChannel') && $frame->getImageAlphaChannel()) {
                $image->setFirstIterator();
                return true;
            }
        }

        $image->setFirstIterator();

        return false;
    }
}
