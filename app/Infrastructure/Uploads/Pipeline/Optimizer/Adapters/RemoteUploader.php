<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Optimizer\Adapters;

use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

/**
 * Servicio para subir archivos optimizados a un sistema de archivos remoto.
 *
 * Esta clase facilita la subida de archivos desde una ubicación local a un disco remoto
 * (por ejemplo, S3, FTP, etc.) usando streams para una transferencia eficiente en memoria.
 * Se basa en la abstracción de sistema de archivos de Laravel (`FilesystemAdapter`).
 */
final class RemoteUploader
{
    /**
     * Constructor de la clase.
     *
     * @param FilesystemAdapter $disk Instancia del adaptador de sistema de archivos remoto.
     */
    public function __construct(
        private readonly FilesystemAdapter $disk,
    ) {}

    /**
     * Sube un archivo local a una ubicación remota usando un stream.
     *
     * Abre el archivo local en modo binario de solo lectura y lo sube al disco remoto
     * usando el manejador (handle) del archivo para evitar cargarlo en memoria.
     *
     * @param string $relativePath Ruta de destino en el sistema de archivos remoto.
     * @param string $localPath    Ruta del archivo local a subir.
     * @param array<string, mixed> $options Opciones adicionales para la operación de subida (p. ej. cabeceras, metadatos).
     *
     * @throws RuntimeException Si ocurre un error al abrir el archivo local o al subirlo remotamente.
     *
     * @return void
     */
    public function upload(string $relativePath, string $localPath, array $options = []): void
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
            throw new RuntimeException('relative_path_invalid');
        }

        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('tmp_reopen_failed');
        }

        try {
            $result = $this->disk->put($relativePath, $handle, $options);
        } finally {
            fclose($handle);
        }

        if ($result === false) {
            throw new RuntimeException('remote_put_failed');
        }
    }
}
