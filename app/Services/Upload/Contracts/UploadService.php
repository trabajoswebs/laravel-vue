<?php

declare(strict_types=1);

namespace App\Services\Upload\Contracts;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;

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
     * Ejecutar análisis de seguridad sobre el contenido sin confiar en la extensión.
     *
     * Recibe el contenido crudo (bytes) para permitir inspecciones en memoria.
     */
    public function scan(string $bytes): void;

    /**
     * Validar restricciones lógicas (tamaño, tipo, dimensiones, etc.).
     */
    public function validate(string $bytes): void;

    /**
     * Normalizar/conversión opcional para mitigar polyglots o metadata sensible.
     *
     * Debe devolver los bytes resultantes o lanzar excepción ante fallo.
     */
    public function normalize(string $bytes): string;

    /**
     * Asociar el archivo resultante al modelo dueño.
     *
     * La implementación decide si utiliza perfiles/conversiones.
     */
    public function attach(HasMediaContract $owner, string $profile): void;
}
