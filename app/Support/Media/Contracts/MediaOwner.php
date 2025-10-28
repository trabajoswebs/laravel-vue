<?php

declare(strict_types=1);

namespace App\Support\Media\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;

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
 * - Extiende `HasMedia` para mantener toda la funcionalidad base.
 */
interface MediaOwner extends HasMedia
{

    /**
     * Relación polimórfica subyacente que enlaza el modelo con sus medios.
     *
     * Útil para consultas avanzadas o eager loading.
     *
     * @return MorphMany Relación Eloquent de tipo `morphMany`.
     */
    public function media(): MorphMany;
}
