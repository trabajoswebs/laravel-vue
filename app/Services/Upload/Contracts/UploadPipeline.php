<?php

declare(strict_types=1);

namespace App\Services\Upload\Contracts;

use Illuminate\Http\UploadedFile;
use SplFileObject;

/**
 * Contrato para pipelines de análisis/normalización de subidas.
 *
 * Orquesta el flujo completo a través de un único punto de entrada que acepta
 * distintas fuentes sin obligar a cargar el archivo entero en memoria.
 */
interface UploadPipeline
{
    /**
     * Procesa un artefacto recibido desde cualquier fuente soportada.
     *
     * @param  UploadedFile|SplFileObject|string  $source  Fuente del archivo (UploadedFile, stream o ruta absoluta).
     * @return UploadResult
     *
     * @throws \App\Services\Upload\Exceptions\UploadException
     */
    public function process(UploadedFile|SplFileObject|string $source): UploadResult;
}
