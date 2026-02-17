<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Contracts;

use App\Modules\Uploads\Contracts\MediaProfile;
use App\Modules\Uploads\Pipeline\DTO\InternalPipelineResult;
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
     * @param  MediaProfile $profile Perfil de configuración que define normalización y restricciones.
     * @param  string $correlationId Identificador de correlación para trazabilidad.
     * @return InternalPipelineResult
     *
     * @throws \App\Modules\Uploads\Pipeline\Exceptions\UploadException
     */
    public function process(UploadedFile|SplFileObject|string $source, MediaProfile $profile, string $correlationId): InternalPipelineResult;
}
