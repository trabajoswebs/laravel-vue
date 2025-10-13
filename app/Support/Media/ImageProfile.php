<?php

declare(strict_types=1);

namespace App\Support\Media;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Contrato para describir perfiles de imagen por colección.
 *
 * Un perfil encapsula:
 * - Nombre de colección y disco de destino.
 * - Lista de conversions por nombre.
 * - Campo del formulario asociado y requisitos (p. ej., cuadrado).
 * - Aplicación de conversions sobre un modelo HasMedia.
 *
 * Implementaciones típicas: AvatarProfile, GalleryProfile.
 */
interface ImageProfile
{
    /**
     * Nombre de la colección de Media Library (p. ej., "avatar", "gallery").
     */
    public function collection(): string;

    /**
     * Nombre del disco a utilizar (o null para el default).
     */
    public function disk(): ?string;

    /**
     * Nombres de conversions esperadas (p. ej., ['thumb','medium','large']).
     *
     * Se usa como guía; el job intersecta con las conversions registradas.
     *
     * @return array<int,string>
     */
    public function conversions(): array;

    /**
     * Nombre del campo de formulario esperado (p. ej., "avatar" o "image").
     */
    public function fieldName(): string;

    /**
     * Requisito de cuadrado duro (algunas UIs lo exigen para avatares).
     */
    public function requiresSquare(): bool;

    /**
     * Aplica las conversions del perfil a un modelo HasMedia.
     */
    public function applyConversions(HasMedia&InteractsWithMedia $model, ?Media $media = null): void;
}

