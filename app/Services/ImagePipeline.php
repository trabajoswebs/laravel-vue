<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * ImagePipeline (versión endurecida)
 *
 * Este servicio se encarga de pre-procesar imágenes subidas para normalizarlas,
 * validarlas, optimizarlas y prepararlas para su almacenamiento o conversión posterior.
 *
 * - Valida tamaño y MIME real (finfo, magic bytes)
 * - Carga con Imagick y sanea: auto-orient (incluye flips), strip EXIF/ICC, normaliza a sRGB
 * - Valida dimensiones y megapíxeles TRAS orientar
 * - Redimensiona manteniendo proporción hasta un máximo (configurable)
 * - Re-encoda (JPEG/WebP/PNG/GIF) con parámetros ajustables (config/image-pipeline.php)
 * - GIF animados: conservar o tomar primer frame (configurable), con límite de frames y truncado real
 * - Escribe un archivo temporal local y devuelve un Value Object con cleanup()
 *
 * Requisitos:
 *  - PHP ext-imagick instalada.
 *  - (Opcional) config/image-pipeline.php para personalizar parámetros.
 *
 * @author Tu Nombre <tu.email@dominio.com>
 */
class ImagePipeline
{
    /** Config/thresholds (con validación de rangos) */
    private int $maxBytes;
    private int $minDimension;
    private float $maxMegapixels;
    private int $maxEdge;
    private int $jpegQuality;
    private int $webpQuality;
    private bool $alphaToWebp;
    private int $jpegProgressiveMin;
    private int $webpMethod;

    /** PNG tuning */
    private int $pngCompressionLevel;
    private int $pngCompressionStrategy;
    private int $pngCompressionFilter;
    private string $pngExcludeChunk;

    /** GIF animados */
    private bool $preserveGifAnimation;
    private int $maxGifFrames;
    private int $gifResizeFilter;

    /** Logging */
    private ?string $logChannel;
    private bool $debug;

    /** MIMEs aceptados estrictos → extensión base */
    private array $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * Constructor del ImagePipeline.
     *
     * Verifica que la extensión Imagick esté disponible, carga la configuración
     * desde config/image-pipeline.php con validación de rangos y establece
     * los canales de logging.
     */
    public function __construct()
    {
        // Verifica que la extensión PHP Imagick esté instalada y disponible
        if (!\extension_loaded('imagick')) {
            throw new RuntimeException('La extensión PHP "imagick" no está disponible.');
        }

        // Carga configuración con validación de rangos para evitar valores tóxicos en ENV
        $this->maxBytes           = $this->cfgInt('image-pipeline.max_bytes', 5 * 1024 * 1024, 1, 50 * 1024 * 1024);
        $this->minDimension       = $this->cfgInt('image-pipeline.min_dimension', 200, 50, 8000);
        $this->maxMegapixels      = $this->cfgFloat('image-pipeline.max_megapixels', 20.0, 0.1, 100.0);
        $this->maxEdge            = $this->cfgInt('image-pipeline.max_edge', 1024, 64, 8192);
        $this->jpegQuality        = $this->cfgInt('image-pipeline.jpeg_quality', 82, 1, 100);
        $this->webpQuality        = $this->cfgInt('image-pipeline.webp_quality', 75, 1, 100);
        $this->alphaToWebp        = (bool) config('image-pipeline.alpha_to_webp', true);
        $this->jpegProgressiveMin = $this->cfgInt('image-pipeline.jpeg_progressive_min', 1200, 64, 10000);
        $this->webpMethod         = $this->cfgInt('image-pipeline.webp_method', 6, 0, 6);

        $this->pngCompressionLevel    = $this->cfgInt('image-pipeline.png_compression_level', 9, 0, 9);
        $this->pngCompressionStrategy = $this->cfgInt('image-pipeline.png_compression_strategy', 1, 0, 4);
        $this->pngCompressionFilter   = $this->cfgInt('image-pipeline.png_compression_filter', 5, 0, 5);
        $this->pngExcludeChunk        = (string) config('image-pipeline.png_exclude_chunk', 'all');

        $this->preserveGifAnimation = (bool) config('image-pipeline.preserve_gif_animation', false);
        $this->maxGifFrames         = $this->cfgInt('image-pipeline.max_gif_frames', 60, 1, 300);
        $this->gifResizeFilter      = $this->cfgInt('image-pipeline.gif_resize_filter', 8, 0, 22); // TRIANGLE por defecto

        $this->logChannel = config('image-pipeline.log_channel');
        $this->debug      = (bool) config('image-pipeline.debug', false);
    }

    /**
     * Procesa un UploadedFile y devuelve un temporal normalizado listo para subir.
     *
     * Este método realiza una serie de validaciones y transformaciones en la imagen:
     * 1. Valida que el archivo sea válido, tenga un tamaño permitido y un MIME aceptado.
     * 2. Carga la imagen con Imagick.
     * 3. Procesa GIFs animados según la configuración (preservar o no).
     * 4. Aplica saneamiento general (orientación, strip, sRGB).
     * 5. Valida dimensiones.
     * 6. Redimensiona si es necesario.
     * 7. Decide el formato de salida y aplica la codificación correspondiente.
     * 8. Escribe el resultado a un archivo temporal.
     *
     * @param  UploadedFile  $file  El archivo de imagen subido por el cliente.
     * @return ImagePipelineResult  Un Value Object con la información del archivo procesado.
     *
     * @throws InvalidArgumentException  Si el archivo no es válido, tiene tamaño incorrecto o MIME no permitido.
     * @throws RuntimeException          Si ocurre un error interno durante el procesamiento.
     */
    public function process(UploadedFile $file): ImagePipelineResult
    {
        // Validación básica de UploadedFile
        if (!$file->isValid()) {
            throw new InvalidArgumentException('El archivo subido no es válido.');
        }

        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > $this->maxBytes) {
            throw new InvalidArgumentException('Tamaño de archivo fuera de límites.');
        }

        $realPath = $file->getRealPath();
        if (!$realPath || !\is_readable($realPath)) {
            throw new InvalidArgumentException('No se pudo leer el archivo temporal.');
        }

        // MIME real por magic bytes (no confiar en extensión)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($realPath);
        if (!isset($this->allowedMimes[$mime])) {
            throw new InvalidArgumentException("Tipo MIME no permitido: {$mime}");
        }

        $img = new \Imagick(); // única referencia controlada en finally
        try {
            // readImage puede lanzar; valid() añade una capa extra
            $img->readImage($realPath);
            if (!$img->valid()) {
                throw new RuntimeException('No se pudo validar la imagen cargada.');
            }

            // Helper para reemplazar instancia sin perder el finally (evita leaks)
            $replace = static function (\Imagick &$ref, \Imagick $new): void {
                $old = $ref;
                $ref = $new;
                $old->clear();
                $old->destroy();
            };

            // GIF animados
            if ($mime === 'image/gif' && $img->getNumberImages() > 1) {
                if (!$this->preserveGifAnimation) {
                    // Mantener sólo primer frame (sin coalesce, más rápido y menos memoria)
                    $img->setFirstIterator();
                    try {
                        $first = clone $img;              // clone puede lanzar en casos raros
                        $first->setImageIterations(1);
                    } catch (\Throwable $e) {
                        $this->log('error', 'image_pipeline_gif_clone_failed', [
                            'error' => (string) Str::of($e->getMessage())->limit(120),
                        ]);
                        throw new RuntimeException('No se pudo extraer el primer frame del GIF.');
                    }
                    $replace($img, $first);
                } else {
                    // Preservar animación: coalesce + saneado por frame con validación
                    $coalesced = $img->coalesceImages();
                    $index = 0;
                    foreach ($coalesced as $frame) {
                        if (!$frame->valid()) {
                            $this->log('warning', 'image_pipeline_gif_invalid_frame', ['index' => $index]);
                            throw new RuntimeException("Frame GIF inválido en índice {$index}.");
                        }
                        $this->autoOrient($frame);
                        $frame->stripImage();
                        $this->toSRGB($frame);
                        $index++;
                    }
                    $replace($img, $coalesced);
                }
            }

            // Para imágenes no-animadas (o GIF reducido a 1 frame), saneamos
            if (!($mime === 'image/gif' && $img->getNumberImages() > 1)) {
                $this->autoOrient($img);
                $img->stripImage();
                $this->toSRGB($img);
            }

            // Validar dimensiones tras orientar
            [$width, $height] = $this->dimensions($img);
            $this->assertDimensions($width, $height);

            // Redimensionar proporcionalmente si excede maxEdge
            $maxWH = \max($width, $height);
            $scale = $maxWH > $this->maxEdge ? $this->maxEdge / $maxWH : 1.0;

            if ($scale < 1.0) {
                $newW = \max(1, (int) \floor($width * $scale));
                $newH = \max(1, (int) \floor($height * $scale));

                if ($mime === 'image/gif' && $img->getNumberImages() > 1) {
                    $count = 0;
                    foreach ($img as $frame) {
                        $count++;
                        if ($count <= $this->maxGifFrames) {
                            $frame->resizeImage($newW, $newH, $this->gifResizeFilter, 1, true);
                            continue;
                        }
                        // Truncar frames extra (de verdad)
                        try {
                            $img->deleteImage(); // elimina frame actual del iterador
                        } catch (\Throwable $e) {
                            $this->log('warning', 'image_pipeline_gif_delete_frame_failed', [
                                'index' => $count - 1,
                                'error' => (string) Str::of($e->getMessage())->limit(120),
                            ]);
                            break;
                        }
                    }
                    $img->setFirstIterator();
                } else {
                    $img->resizeImage($newW, $newH, \Imagick::FILTER_LANCZOS, 1, true);
                }
                $width = $newW;
                $height = $newH;
            }

            // Detección de alpha (evitar romper transparencia al pasar a JPEG)
            $hasAlpha = $this->hasAlphaChannel($img);

            // Decidir formato de salida base
            $targetFormat = match ($mime) {
                'image/gif'  => $this->preserveGifAnimation ? 'gif' : ($hasAlpha ? 'png' : 'jpeg'),
                'image/png'  => $hasAlpha ? ($this->alphaToWebp ? 'webp' : 'png') : 'jpeg',
                'image/webp' => 'webp',
                default      => 'jpeg',
            };

            // Enforce WebP si hay alpha y así se configuró
            if ($hasAlpha && $this->alphaToWebp) {
                $targetFormat = 'webp';
            }

            // Re-encode (parámetros por formato)
            $ext = $this->allowedMimes[$mime]; // base
            if ($targetFormat === 'jpeg') {
                $img->setImageFormat('jpeg');
                $img->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $img->setImageCompressionQuality($this->jpegQuality);
                if (\max($width, $height) >= $this->jpegProgressiveMin) {
                    $img->setInterlaceScheme(\Imagick::INTERLACE_JPEG); // progresivo solo si grande
                }
                $ext = 'jpg';
            } elseif ($targetFormat === 'webp') {
                $img->setImageFormat('webp');
                if (\defined('\Imagick::COMPRESSION_WEBP')) {
                    $img->setImageCompression(\Imagick::COMPRESSION_WEBP);
                }
                $img->setOption('webp:method', (string) $this->webpMethod);
                $img->setOption('webp:thread-level', '1');
                $img->setImageCompressionQuality($this->webpQuality);
                $ext = 'webp';
            } elseif ($targetFormat === 'png') {
                $img->setImageFormat('png');
                $img->setOption('png:compression-filter', (string) $this->pngCompressionFilter);
                $img->setOption('png:compression-level', (string) $this->pngCompressionLevel);
                $img->setOption('png:compression-strategy', (string) $this->pngCompressionStrategy);
                $img->setOption('png:exclude-chunk', $this->pngExcludeChunk);
                $ext = 'png';
            } else { // 'gif' (preservando animación)
                $img->setImageFormat('gif');
                $ext = 'gif';
            }

            // Escribir a temporal con verificación estricta
            $tmpPath = $this->tempFilePath($ext);

            $ok = true;
            if ($mime === 'image/gif' && $img->getNumberImages() > 1 && $targetFormat === 'gif') {
                $ok = $img->writeImages($tmpPath, true);
            } else {
                $ok = $img->writeImage($tmpPath);
            }

            if (!$ok) {
                if (!@unlink($tmpPath)) {
                    $this->log('warning', 'image_pipeline_tmp_unlink_failed', ['path' => basename($tmpPath)]);
                }
                throw new RuntimeException('Error al escribir la imagen procesada.');
            }

            // Validación del resultado; limpiar si es inválido
            $bytes = \filesize($tmpPath);
            if ($bytes === false || $bytes <= 0) {
                if (!@unlink($tmpPath)) {
                    $this->log('warning', 'image_pipeline_tmp_unlink_failed', ['path' => basename($tmpPath)]);
                }
                throw new RuntimeException('El archivo temporal resultante es inválido.');
            }

            $hash   = \hash_file('sha1', $tmpPath) ?: \bin2hex(\random_bytes(8));
            $outMime= $this->mimeFromExtension($ext);

            return new ImagePipelineResult(
                path: $tmpPath,
                mime: $outMime,
                extension: $ext,
                width: $width,
                height: $height,
                bytes: (int) $bytes,
                contentHash: $hash,
            );
        } catch (\Throwable $e) {
            $this->log('error', 'image_pipeline_failed', [
                'error' => (string) Str::of($e->getMessage())->limit(160),
                'mime'  => $mime ?? null,
            ]);
        } finally {
            $img->clear();
            $img->destroy();
        }
    }

    /**
     * Corrige la orientación de la imagen basándose en EXIF.
     *
     * Aplica autoOrient si está disponible, o implementa la lógica manualmente
     * para corregir la orientación, incluyendo flips horizontales y verticales.
     *
     * @param  \Imagick  $im  Instancia de Imagick a la que se aplicará la orientación.
     * @return void
     */
    private function autoOrient(\Imagick $im): void
    {
        if (\method_exists($im, 'autoOrient')) {
            $im->autoOrient();
            return;
        }

        $o = $im->getImageOrientation();
        switch ($o) {
            case \Imagick::ORIENTATION_TOPRIGHT:     $im->flopImage(); break; // espejo horizontal
            case \Imagick::ORIENTATION_BOTTOMRIGHT:  $im->rotateImage('#000', 180); break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:   $im->flipImage(); break; // espejo vertical
            case \Imagick::ORIENTATION_LEFTTOP:      $im->flopImage(); $im->rotateImage('#000', 90); break;
            case \Imagick::ORIENTATION_RIGHTTOP:     $im->rotateImage('#000', 90); break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM:  $im->flopImage(); $im->rotateImage('#000', -90); break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:   $im->rotateImage('#000', -90); break;
            default: break; // TOPLEFT
        }
        $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

    /**
     * Normaliza el espacio de color de la imagen a sRGB.
     *
     * Intenta convertir el espacio de color a sRGB. Si falla, lo registra en debug.
     * Si la imagen era CMYK, lo registra como notice.
     *
     * @param  \Imagick  $im  Instancia de Imagick a la que se aplicará la conversión.
     * @return void
     */
    private function toSRGB(\Imagick $im): void
    {
        try {
            if (\method_exists($im, 'getImageColorspace') && $im->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
                $this->log('notice', 'image_pipeline_cmyk_to_srgb', []);
            }
            $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
        } catch (\Throwable $e) {
            $this->log('debug', 'image_pipeline_srgb_failed', ['error' => (string) Str::of($e->getMessage())->limit(120)]);
        }
    }

    /**
     * Obtiene las dimensiones de la imagen (ancho, alto).
     *
     * Si la imagen es animada, se posiciona en el primer frame antes de leer las dimensiones.
     *
     * @param  \Imagick  $im  Instancia de Imagick de la que se leerán las dimensiones.
     * @return array<int, int>  Array con [ancho, alto].
     */
    private function dimensions(\Imagick $im): array
    {
        if ($im->getNumberImages() > 1) {
            $im->setFirstIterator();
        }
        return [(int) $im->getImageWidth(), (int) $im->getImageHeight()];
    }

    /**
     * Valida las dimensiones mínimas y el límite de megapíxeles.
     *
     * Lanza una excepción si las dimensiones son menores al mínimo o si
     * el total de megapíxeles supera el límite configurado.
     *
     * @param  int  $width   Ancho de la imagen.
     * @param  int  $height  Alto de la imagen.
     * @return void
     *
     * @throws InvalidArgumentException  Si las dimensiones no cumplen con los límites.
     */
    private function assertDimensions(int $width, int $height): void
    {
        if ($width < $this->minDimension || $height < $this->minDimension) {
            throw new InvalidArgumentException('Dimensiones mínimas no alcanzadas.');
        }

        $mp = ($width * $height) / 1_000_000;
        if ($mp > $this->maxMegapixels) {
            throw new InvalidArgumentException('La imagen supera el límite de megapíxeles permitido.');
        }
    }

    /**
     * Detecta si la imagen tiene canal alfa (transparencia).
     *
     * Para imágenes animadas, verifica si *cualquier* frame tiene canal alfa.
     *
     * @param  \Imagick  $im  Instancia de Imagick a la que se le verificará el canal alfa.
     * @return bool  True si la imagen (o algún frame) tiene canal alfa, false en caso contrario.
     */
    private function hasAlphaChannel(\Imagick $im): bool
    {
        if ($im->getNumberImages() > 1) {
            foreach ($im as $frame) {
                if (\method_exists($frame, 'getImageAlphaChannel') && $frame->getImageAlphaChannel()) {
                    $im->setFirstIterator();
                    return true;
                }
            }
            $im->setFirstIterator();
            return false;
        }
        return \method_exists($im, 'getImageAlphaChannel') && $im->getImageAlphaChannel();
    }

    /**
     * Crea una ruta temporal única para el archivo procesado.
     *
     * @param  string  $ext  Extensión del archivo (por ejemplo, 'jpg', 'png').
     * @return string  Ruta completa al archivo temporal.
     */
    private function tempFilePath(string $ext): string
    {
        $base = \rtrim(\sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $name = 'img_norm_' . \bin2hex(\random_bytes(6)) . '.' . \ltrim($ext, '.');
        return $base . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Mapea una extensión de archivo a su tipo MIME estándar.
     *
     * @param  string  $ext  Extensión del archivo (por ejemplo, 'jpg', 'png').
     * @return string  Tipo MIME correspondiente.
     */
    private function mimeFromExtension(string $ext): string
    {
        return match (strtolower($ext)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            default       => 'application/octet-stream',
        };
    }

    /**
     * Lee un valor entero de la configuración con validación de rangos.
     *
     * @param  string      $key      Clave de configuración.
     * @param  int         $default  Valor por defecto si la clave no existe o es inválida.
     * @param  int         $min      Valor mínimo permitido.
     * @param  int|null    $max      Valor máximo permitido (null para ilimitado).
     * @return int         Valor configurado o valor por defecto si no cumple con los rangos.
     */
    private function cfgInt(string $key, int $default, int $min, ?int $max): int
    {
        $v = config($key);
        if (!\is_int($v)) return $default;
        if ($v < $min)    return $default;
        if ($max !== null && $v > $max) return $default;
        return $v;
    }

    /**
     * Lee un valor flotante de la configuración con validación de rangos.
     *
     * @param  string      $key      Clave de configuración.
     * @param  float       $default  Valor por defecto si la clave no existe o es inválida.
     * @param  float       $min      Valor mínimo permitido.
     * @param  float|null  $max      Valor máximo permitido (null para ilimitado).
     * @return float       Valor configurado o valor por defecto si no cumple con los rangos.
     */
    private function cfgFloat(string $key, float $default, float $min, ?float $max): float
    {
        $v = config($key);
        if (!\is_numeric($v)) return $default;
        $v = (float) $v;
        if ($v < $min)    return $default;
        if ($max !== null && $v > $max) return $default;
        return $v;
    }

    /**
     * Maneja el logging con canal configurable y sanitización de mensajes.
     *
     * @param  string  $level    Nivel de log ('info', 'error', 'debug', etc.).
     * @param  string  $msg      Mensaje a registrar.
     * @param  array   $context  Contexto adicional para el log.
     * @return void
     */
    private function log(string $level, string $msg, array $context): void
    {
        // Sanitiza cadenas largas en el contexto si no está en modo debug
        $context = $this->debug ? $context : \collect($context)->map(function ($val) {
            return \is_string($val) ? (string) Str::of($val)->limit(160) : $val;
        })->all();

        if ($this->logChannel) {
            Log::channel($this->logChannel)->{$level}($msg, $context);
        } else {
            Log::{$level}($msg, $context);
        }
    }
}

/**
 * Value Object del resultado del pipeline.
 * Incluye cleanup() y destructor (silencioso) como red de seguridad.
 *
 * Este Value Object encapsula la información del archivo de imagen procesado
 * y proporciona métodos para limpiar el archivo temporal.
 *
 * @author Tu Nombre <tu.email@dominio.com>
 */
class ImagePipelineResult
{
    /**
     * Crea una nueva instancia de ImagePipelineResult.
     *
     * @param  string  $path          Ruta al archivo temporal procesado.
     * @param  string  $mime          Tipo MIME del archivo procesado.
     * @param  string  $extension     Extensión del archivo procesado.
     * @param  int     $width         Ancho de la imagen procesada.
     * @param  int     $height        Alto de la imagen procesada.
     * @param  int     $bytes         Tamaño en bytes del archivo procesado.
     * @param  string  $contentHash   Hash SHA1 del contenido del archivo procesado.
     */
    public function __construct(
        public string $path,
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
        public int $bytes,
        public string $contentHash,
    ) {}

    /**
     * Borra el archivo temporal (loggea si falla, menos en destructor).
     *
     * Este método debe llamarse explícitamente para limpiar el archivo temporal
     * generado durante el procesamiento de la imagen.
     *
     * @return void
     */
    public function cleanup(): void
    {
        if (!@unlink($this->path)) {
            Log::notice('image_pipeline_cleanup_failed', [
                'path' => basename($this->path),
            ]);
        }
    }

    /**
     * Silencioso: evita ruido si el consumidor olvidó llamar cleanup().
     *
     * Este destructor actúa como una red de seguridad para eliminar el archivo
     * temporal si el método cleanup() no fue llamado explícitamente.
     *
     * @return void
     */
    public function __destruct()
    {
        @unlink($this->path);
    }
}