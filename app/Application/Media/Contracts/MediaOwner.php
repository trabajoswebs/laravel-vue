<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

/**
 * Contrato para modelos que "poseen" medios gestionados por Spatie Media Library v11.
 *
 * ✨ Propósito:
 * - Permitir tipado fuerte en servicios de soporte (ej. collectors, cleaners).
 * - Desacoplar lógica de implementaciones concretas (como `User`, `Post`, etc.).
 * - Exponer explícitamente los métodos que los servicios externos usan.
 *
 * 📌 Compatibilidad:
 * - Compatible con Spatie Media Library v11+ (donde `getMedia()` devuelve `Collection`).
 */
interface MediaOwner
{
    /**
     * Identificador primario del propietario.
     */
    public function getKey();

    /**
     * Mappea colecciones de Spatie Media Library a columnas de versión.
     *
     * @param string $collection Nombre de la colección de medios (ej: 'avatar').
     * @return string|null Nombre de la columna asociada para cache busting.
     */
    public function getMediaVersionColumn(string $collection): ?string;
}
