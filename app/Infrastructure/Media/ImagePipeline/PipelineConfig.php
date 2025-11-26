<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\ImagePipeline;

use App\Domain\User\ConversionProfiles\FileConstraints;

/**
 * Encapsula los parámetros configurables del pipeline para facilitar su reutilización.
 * 
 * Esta clase centraliza todas las configuraciones necesarias para el procesamiento
 * de imágenes, como límites de tamaño, calidad, formatos permitidos y disponibilidad
 * de bibliotecas externas como Imagick o GD.
 * 
 * @example
 * $config = PipelineConfig::fromConstraints($fileConstraints);
 * echo $config->jpegQuality; // 82 (o valor de config)
 * echo $config->imagickAvailable; // true o false
 */
final class PipelineConfig
{
    /**
     * @param array<string,string> $allowedMimes Mapa de MIME types a extensiones permitidas (e.g., ['image/jpeg' => 'jpg']).
     */
    public function __construct(
        public readonly int $maxBytes,
        public readonly int $minDimension,
        public readonly float $maxMegapixels,
        public readonly int $maxEdge,
        public readonly int $jpegQuality,
        public readonly int $webpQuality,
        public readonly bool $alphaToWebp,
        public readonly int $jpegProgressiveMin,
        public readonly int $webpMethod,
        public readonly int $pngCompressionLevel,
        public readonly int $pngCompressionStrategy,
        public readonly int $pngCompressionFilter,
        public readonly string $pngExcludeChunk,
        public readonly bool $preserveGifAnimation,
        public readonly int $maxGifFrames,
        public readonly int $gifResizeFilter,
        public readonly ?string $logChannel,
        public readonly bool $debug,
        public readonly array $allowedMimes,
        public readonly bool $imagickAvailable,
        public readonly bool $gdAvailable,
    ) {}

    /**
     * Crea una instancia de PipelineConfig a partir de FileConstraints y la configuración de la aplicación.
     * 
     * Detecta automáticamente si las extensiones Imagick y GD están disponibles.
     * 
     * @param FileConstraints $constraints Objeto con límites básicos (tamaño, dimensiones).
     * @return self Nueva instancia de PipelineConfig.
     */
    public static function fromConstraints(FileConstraints $constraints): self
    {
        $imagickAvailable = \extension_loaded('imagick');
        $gdAvailable = \extension_loaded('gd');

        return new self(
            maxBytes: $constraints->maxBytes,
            minDimension: $constraints->minDimension,
            maxMegapixels: $constraints->maxMegapixels,
            maxEdge: $constraints->maxDimension,
            jpegQuality: self::cfgInt('image-pipeline.jpeg_quality', 82, 1, 100),
            webpQuality: self::cfgInt('image-pipeline.webp_quality', 75, 1, 100),
            alphaToWebp: (bool) config('image-pipeline.alpha_to_webp', true),
            jpegProgressiveMin: self::cfgInt('image-pipeline.jpeg_progressive_min', 1200, 64, 10000),
            webpMethod: self::cfgInt('image-pipeline.webp_method', 6, 0, 6),
            pngCompressionLevel: self::cfgInt('image-pipeline.png_compression_level', 9, 0, 9),
            pngCompressionStrategy: self::cfgInt('image-pipeline.png_compression_strategy', 1, 0, 4),
            pngCompressionFilter: self::cfgInt('image-pipeline.png_compression_filter', 5, 0, 5),
            pngExcludeChunk: (string) config('image-pipeline.png_exclude_chunk', 'all'),
            preserveGifAnimation: (bool) config('image-pipeline.preserve_gif_animation', false),
            maxGifFrames: self::cfgInt('image-pipeline.max_gif_frames', 60, 1, 300),
            gifResizeFilter: self::cfgInt('image-pipeline.gif_resize_filter', 8, 0, 22),
            logChannel: config('image-pipeline.log_channel'),
            debug: (bool) config('image-pipeline.debug', false),
            allowedMimes: $constraints->allowedMimeMap(),
            imagickAvailable: $imagickAvailable,
            gdAvailable: $gdAvailable,
        );
    }

    /**
     * Obtiene un valor entero de la configuración de Laravel, validando sus límites.
     * 
     * Si el valor no es un entero o está fuera de los límites, devuelve el valor por defecto.
     * 
     * @param string $key Clave de la configuración (e.g., 'image-pipeline.jpeg_quality').
     * @param int $default Valor por defecto si no se encuentra o es inválido.
     * @param int $min Valor mínimo permitido.
     * @param int|null $max Valor máximo permitido (null para ilimitado).
     * @return int Valor de configuración validado o el valor por defecto.
     * @example cfgInt('image.quality', 80, 1, 100) -> 85 (si config es 85) | 80 (si config es 150)
     */
    private static function cfgInt(string $key, int $default, int $min, ?int $max): int
    {
        $value = config($key);
        if (!\is_int($value)) {
            return $default;
        }
        if ($value < $min) {
            return $default;
        }
        if ($max !== null && $value > $max) {
            return $default;
        }

        return $value;
    }
}
