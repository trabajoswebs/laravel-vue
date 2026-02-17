<?php // FileNamer para Media Library tenant-first

declare(strict_types=1); // Tipado estricto

namespace App\Modules\Uploads\Paths\MediaLibrary; // Namespace de paths de media

use Spatie\MediaLibrary\Conversions\Conversion; // Conversion de Media Library
use Spatie\MediaLibrary\Support\FileNamer\FileNamer; // Base FileNamer de Spatie

/**
 * Genera nombres de archivo determinísticos con versión/hash.
 */
final class TenantAwareFileNamer extends FileNamer // Extiende FileNamer base
{
    public function originalFileName(string $fileName): string // Nombre base del archivo original (sin extensión)
    {
        // Hash corto y determinístico a partir del nombre saneado para evitar colisiones
        $hash = substr(md5($fileName), 0, 12);

        return 'v' . $hash;
    }

    public function conversionFileName(string $fileName, Conversion $conversion): string // Nombre para conversions
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME) ?: 'file';

        return "{$baseName}-{$conversion->getName()}";
    }

    public function responsiveFileName(string $fileName): string // Nombre base para responsive images
    {
        return pathinfo($fileName, PATHINFO_FILENAME) ?: 'file';
    }
}
