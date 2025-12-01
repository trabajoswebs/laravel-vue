<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Perfil de media agnóstico de infraestructura.
 */
interface MediaProfile
{
    /**
     * Obtiene el nombre de la colección de medios.
     *
     * @return string Nombre de la colección
     */
    public function collection(): string;

    /**
     * Obtiene el nombre del disco de almacenamiento.
     *
     * @return string|null Nombre del disco o null si no está configurado
     */
    public function disk(): ?string;

    /**
     * Obtiene la lista de conversiones definidas para este perfil.
     *
     * @return array<int,string> Nombres de las conversiones
     */
    public function conversions(): array;

    /**
     * Indica si este perfil permite un solo archivo (reemplazo).
     *
     * @return bool True si es un archivo único, false si permite múltiples
     */
    public function isSingleFile(): bool;

    /**
     * Obtiene las restricciones de archivo aplicables a este perfil.
     */
    public function fileConstraints(): FileConstraints;

    /**
     * Nombre del campo de formulario esperado.
     */
    public function fieldName(): string;

    /**
     * Indica si el binario requiere aspecto cuadrado duro.
     */
    public function requiresSquare(): bool;

    /**
     * Registra las conversions declaradas en el modelo propietario.
     *
     * @param MediaOwner $model Modelo que posee el media.
     * @param Media|null $media Instancia del media cuando exista.
     */
    public function applyConversions(MediaOwner $model, ?Media $media = null): void;

    /**
     * Indica si el perfil debe usar cuarentena.
     */
    public function usesQuarantine(): bool;

    /**
     * Indica si el perfil requiere escaneo antivirus/YARA.
     */
    public function usesAntivirus(): bool;

    /**
     * Indica si el perfil exige normalización de imagen.
     */
    public function requiresImageNormalization(): bool;

    /**
     * TTL en horas para estados pendientes/limpios antes de promoción.
     */
    public function getQuarantineTtlHours(): int;

    /**
     * TTL en horas para estados fallidos/ infectados.
     */
    public function getFailedTtlHours(): int;
}
