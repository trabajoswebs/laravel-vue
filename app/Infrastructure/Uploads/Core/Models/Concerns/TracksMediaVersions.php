<?php

// 1. Declaración de strict_types
declare(strict_types=1);

// 2. Espacio de nombres (namespace)
namespace App\Infrastructure\Uploads\Core\Models\Concerns;

/**
 * Proporciona utilidades para mapear colecciones de Media Library a columnas de versión.
 */
trait TracksMediaVersions
{
    /**
     * Devuelve un array asociativo collection => column.
     *
     * @return array<string,string>
     */
    protected function mediaVersionColumns(): array
    {
        return [
            'avatar' => 'avatar_version',
        ];
    }

    /**
     * Obtiene el nombre de la columna asociada a una colección de medios.
     *
     * @param string $collection Nombre de la colección de medios.
     * @return string|null Nombre de la columna, o null si no existe mapeo.
     */
    public function getMediaVersionColumn(string $collection): ?string
    {
        return $this->mediaVersionColumns()[$collection] ?? null;
    }
}
