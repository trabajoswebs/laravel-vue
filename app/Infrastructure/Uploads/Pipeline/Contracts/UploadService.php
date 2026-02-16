<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Contracts;

use App\Infrastructure\Uploads\Pipeline\DTO\InternalPipelineResult;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Contrato de alto nivel para servicios de subida.
 *
 * Expone pasos individuales que una implementación concreta
 * puede ejecutar para manejar subidas de imágenes o documentos.
 */
interface UploadService
{
    /**
     * Persistir temporalmente el archivo recibido en cuarentena.
     *
     * Debe devolver una ruta o identificador que permita recuperarlo luego.
     */
    public function storeToQuarantine(UploadedFile $file): string;

    /**
     * Asociar el artefacto resultante al modelo dueño utilizando un perfil concreto.
     *
     * @param HasMediaContract $owner    Modelo que recibirá el media.
     * @param InternalPipelineResult     $artifact Artefacto ya normalizado con metadata.
     * @param string           $profile  Perfil/collection de Media Library.
     * @param string|null      $disk     Disco opcional para la colección.
     * @param bool             $singleFile Indica si la colección es de archivo único.
     */
    public function attach(
        HasMediaContract $owner,
        InternalPipelineResult $artifact,
        string $profile,
        ?string $disk = null,
        bool $singleFile = false
    ): Media;
}
