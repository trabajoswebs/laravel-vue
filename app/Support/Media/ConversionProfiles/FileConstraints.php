<?php

declare(strict_types=1);

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * FileConstraints
 *
 * Esta clase define y gestiona los límites globales para archivos de imagen.
 * Proporciona métodos para validar archivos subidos y dimensiones,
 * basándose en configuraciones definidas en config/image-pipeline.php
 * con clamps defensivos para evitar valores tóxicos.
 *
 * Límites globales para imágenes:
 *  - Tamaño máx. de archivo (bytes)
 *  - Dimensiones mín/máx (ancho/alto)
 *  - Máximo de megapíxeles
 *
 * Lee valores de config/image-pipeline.php con clamps defensivos.
 * Pensado como utilidad sin estado (Support), para usar en FormRequests,
 * Actions o Services antes de procesar/adjuntar.
 *
 * @author Tu Nombre <tu.email@dominio.com>
 */
final class FileConstraints
{
    /** @var int bytes */
    public readonly int $maxBytes;

    /** @var int píxeles */
    public readonly int $minDimension;

    /** @var int píxeles */
    public readonly int $maxDimension;

    /** @var float megapíxeles */
    public readonly float $maxMegapixels;

    /**
     * Constructor de FileConstraints.
     *
     * Inicializa las propiedades con valores de configuración o valores por defecto,
     * aplicando clamps defensivos para asegurar que los valores estén dentro de rangos razonables.
     *
     * @param int|null   $maxBytes      Tamaño máximo del archivo en bytes (sobreescribe config).
     * @param int|null   $minDimension  Dimensión mínima permitida en píxeles (sobreescribe config).
     * @param int|null   $maxDimension  Dimensión máxima permitida en píxeles (sobreescribe config).
     * @param float|null $maxMegapixels  Límite máximo de megapíxeles (sobreescribe config).
     */
    public function __construct(
        ?int $maxBytes = null,
        ?int $minDimension = null,
        ?int $maxDimension = null,
        ?float $maxMegapixels = null,
    ) {
        // Defaults seguros + clamps
        $this->maxBytes      = $this->cfgInt('image-pipeline.max_bytes',       $maxBytes,      1,             50 * 1024 * 1024);
        $this->minDimension  = $this->cfgInt('image-pipeline.min_dimension',   $minDimension,  16,            8000);
        $this->maxDimension  = $this->cfgInt('image-pipeline.max_edge',        $maxDimension,  $this->minDimension, 8192);
        $this->maxMegapixels = $this->cfgFloat('image-pipeline.max_megapixels',$maxMegapixels, 0.1,           100.0);
    }

    /**
     * Valida un UploadedFile a nivel de constraints básicos (peso y MIME si quieres).
     * NO abre la imagen. Úsalo en FormRequest o antes de pipelines pesados.
     *
     * @param  UploadedFile  $file  Archivo subido a validar.
     * @return void
     *
     * @throws InvalidArgumentException  Si el archivo no es válido, tiene tamaño incorrecto o no se puede leer.
     */
    public function validateUploadedFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('El archivo subido no es válido.');
        }

        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > $this->maxBytes) {
            throw new InvalidArgumentException("Tamaño de archivo fuera de límites (max {$this->maxBytes} bytes).");
        }

        $realPath = $file->getRealPath();
        if (!$realPath || !\is_readable($realPath)) {
            throw new InvalidArgumentException('No se pudo leer el archivo temporal.');
        }
    }

    /**
     * Valida dimensiones (min/max) y megapíxeles.
     * Pasa el width/height una vez conocidos (p.ej. con getimagesize() o Imagick).
     *
     * @param  int  $width   Ancho de la imagen.
     * @param  int  $height  Alto de la imagen.
     * @return void
     *
     * @throws InvalidArgumentException  Si las dimensiones no cumplen con los límites configurados.
     */
    public function assertDimensions(int $width, int $height): void
    {
        if ($width < $this->minDimension || $height < $this->minDimension) {
            throw new InvalidArgumentException(
                "Dimensiones mínimas no alcanzadas ({$this->minDimension}x{$this->minDimension})."
            );
        }

        if ($width > $this->maxDimension || $height > $this->maxDimension) {
            throw new InvalidArgumentException(
                "Dimensión máxima excedida (máx borde {$this->maxDimension}px)."
            );
        }

        $mp = ($width * $height) / 1_000_000;
        if ($mp > $this->maxMegapixels) {
            throw new InvalidArgumentException(
                "La imagen supera el límite de megapíxeles permitido ({$this->maxMegapixels} MP)."
            );
        }
    }

    /**
     * Obtiene dimensiones rápidas usando getimagesize() (barato) y valida.
     * Útil cuando no quieres abrir aún con Imagick.
     *
     * @param  UploadedFile  $file  Archivo subido del que obtener dimensiones.
     * @return array{0:int,1:int} [width, height]  Ancho y alto de la imagen.
     *
     * @throws InvalidArgumentException  Si no se pueden detectar dimensiones o no cumplen con los límites.
     */
    public function probeAndAssert(UploadedFile $file): array
    {
        $this->validateUploadedFile($file);

        $path = $file->getRealPath();
        $info = @\getimagesize($path);
        if (!$info || !isset($info[0], $info[1])) {
            throw new InvalidArgumentException('No se pudieron detectar dimensiones de la imagen.');
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];

        $this->assertDimensions($width, $height);

        return [$width, $height];
    }

    /**
     * Helper para decidir si conviene redimensionar según maxDimension.
     *
     * @param  int  $width   Ancho de la imagen.
     * @param  int  $height  Alto de la imagen.
     * @return bool          True si alguna dimensión excede el límite máximo.
     */
    public function needsResize(int $width, int $height): bool
    {
        return \max($width, $height) > $this->maxDimension;
    }

    /**
     * Calcula nuevas dimensiones manteniendo proporción para encajar en maxDimension.
     * Si ya encaja, devuelve las originales.
     *
     * @param  int  $width   Ancho original de la imagen.
     * @param  int  $height  Alto original de la imagen.
     * @return array{0:int,1:int} [newWidth, newHeight]  Nuevas dimensiones calculadas.
     */
    public function resizedToFit(int $width, int $height): array
    {
        $maxEdge = \max($width, $height);
        if ($maxEdge <= $this->maxDimension) {
            return [$width, $height];
        }

        $scale = $this->maxDimension / $maxEdge;
        $newW  = \max(1, (int) \floor($width * $scale));
        $newH  = \max(1, (int) \floor($height * $scale));

        return [$newW, $newH];
    }

    // -------------------------------------------------
    // Config helpers (con clamps defensivos)
    // -------------------------------------------------

    /**
     * Lee un valor entero de la configuración con clamps defensivos.
     *
     * @param  string  $key       Clave de configuración.
     * @param  int|null $override  Valor sobrescrito (si se proporciona).
     * @param  int      $min       Valor mínimo permitido.
     * @param  int      $max       Valor máximo permitido.
     * @return int                Valor configurado o sobrescrito, dentro de los límites.
     */
    private function cfgInt(string $key, ?int $override, int $min, int $max): int
    {
        if (\is_int($override)) {
            return \max($min, \min($max, $override));
        }
        $v = config($key);
        if (!\is_int($v)) {
            // defaults razonables por clave
            $defaults = [
                'image-pipeline.max_bytes'      => 5 * 1024 * 1024,
                'image-pipeline.min_dimension'  => 200,
                'image-pipeline.max_edge'       => 1024,
            ];
            $v = $defaults[$key] ?? $min;
        }
        return \max($min, \min($max, (int) $v));
    }

    /**
     * Lee un valor flotante de la configuración con clamps defensivos.
     *
     * @param  string   $key       Clave de configuración.
     * @param  float|null $override  Valor sobrescrito (si se proporciona).
     * @param  float    $min       Valor mínimo permitido.
     * @param  float    $max       Valor máximo permitido.
     * @return float               Valor configurado o sobrescrito, dentro de los límites.
     */
    private function cfgFloat(string $key, ?float $override, float $min, float $max): float
    {
        if (\is_float($override)) {
            return \max($min, \min($max, $override));
        }
        $v = config($key);
        if (!\is_numeric($v)) {
            $defaults = [
                'image-pipeline.max_megapixels' => 20.0,
            ];
            $v = $defaults[$key] ?? $min;
        }
        $v = (float) $v;
        return \max($min, \min($max, $v));
    }
}