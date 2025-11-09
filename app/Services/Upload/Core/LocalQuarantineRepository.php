<?php

declare(strict_types=1);

namespace App\Services\Upload\Core;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Implementación en sistema de archivos local del Repositorio de Cuarentena.
 * 
 * Proporciona almacenamiento seguro y aislado para archivos subidos durante el escaneo de virus
 * con confinamiento estricto de rutas y requisitos de sistema de archivos local.
 * 
 * @package App\Services\Upload\Core
 */
final class LocalQuarantineRepository implements QuarantineRepository
{
    private const MAX_GENERATION_ATTEMPTS = 5;

    /**
     * El nombre del disco de cuarentena.
     */
    private readonly string $disk;

    /**
     * La ruta raíz para el almacenamiento de cuarentena.
     */
    private readonly string $rootPath;

    /**
     * Constructor.
     *
     * @param string|null $disk El nombre del disco de cuarentena (por defecto config)
     * @throws RuntimeException Cuando el disco configurado no es local o la ruta raíz es inválida
     */
    public function __construct(?string $disk = null)
    {
        $preferredDisk = $disk ?? (string) config('media.quarantine.disk', 'quarantine');
        $resolved = $this->resolveDiskConfiguration($preferredDisk);

        $this->disk = $resolved['disk'];
        $this->rootPath = $resolved['root'];
    }

    /**
     * Almacena bytes en cuarentena y devuelve la ruta local absoluta.
     *
     * Crea una estructura de ruta segura y particionada para prevenir colisiones de nombres de archivo
     * y proporciona verificación de que el archivo se ha persistido exitosamente.
     *
     * @param string $bytes El contenido del archivo como bytes
     * @return string Ruta absoluta al archivo en cuarentena
     * @throws RuntimeException Cuando el archivo no se puede almacenar o desaparece después de la creación
     */
    public function put(string $bytes): string
    {
        if ($bytes === '') {
            throw new RuntimeException(__('media.uploads.quarantine_empty_content'));
        }

        // Genera una ruta única con reintentos limitados
        $attempts = 0;
        do {
            $relative = $this->generateRelativePath();
            if (++$attempts > self::MAX_GENERATION_ATTEMPTS) {
                throw new RuntimeException(__('media.uploads.quarantine_path_failed', ['attempts' => self::MAX_GENERATION_ATTEMPTS]));
            }
        } while (Storage::disk($this->disk)->exists($relative));

        $stored = Storage::disk($this->disk)->put($relative, $bytes, [
            'visibility' => 'private',
        ]);

        if ($stored === false) {
            throw new RuntimeException(__('media.uploads.quarantine_persist_failed'));
        }

        // Mitiga condición de carrera: confirma que existe
        if (!Storage::disk($this->disk)->exists($relative)) {
            throw new RuntimeException(__('media.uploads.quarantine_artifact_missing'));
        }

        $absolute = $this->absoluteFromRelative($relative);
        logger()->info('quarantine.put', ['path' => $relative, 'bytes' => strlen($bytes)]);

        return $absolute;
    }

    /**
     * Elimina un artefacto de cuarentena. Solo permite eliminación dentro de la raíz del disco.
     *
     * Proporciona seguridad asegurando que no se puedan eliminar archivos fuera de la raíz de cuarentena
     * y registra todas las operaciones de eliminación con fines de auditoría.
     *
     * @param string $path La ruta absoluta a eliminar
     * @throws RuntimeException Cuando el archivo está fuera de la raíz de cuarentena o falla la eliminación
     */
    public function delete(string $path): void
    {
        $relative = $this->toRelativePath($path);
        if ($relative === null) {
            logger()->warning('quarantine.delete.outside_root', ['path' => $path]);
            throw new RuntimeException(__('media.uploads.quarantine_delete_outside'));
        }

        try {
            $deleted = Storage::disk($this->disk)->delete($relative);
        } catch (\Throwable $e) {
            throw new RuntimeException(__('media.uploads.quarantine_delete_failed', ['error' => $e->getMessage()]));
        }

        if ($deleted === false) {
            throw new RuntimeException(__('media.uploads.quarantine_delete_failed', ['error' => 'delete operation returned false']));
        }

        $this->cleanupEmptyDirectories($relative);
        logger()->info('quarantine.delete', ['path' => $relative]);
    }

    /**
     * Valida y "promueve" un artefacto que permanece en cuarentena.
     *
     * Este método verifica que el archivo existe dentro de la cuarentena y está listo para procesamiento.
     * En esta implementación, la promoción simplemente devuelve la ruta ya que los archivos se procesan
     * in-situ durante el escaneo.
     *
     * @param string $path La ruta absoluta a promover
     * @param array $metadata Metadatos adicionales para la promoción (sin uso)
     * @return string La ruta del archivo promovido (igual que la entrada)
     * @throws RuntimeException Cuando el archivo está fuera de la cuarentena o no existe
     */
    public function promote(string $path, array $metadata = []): string
    {
        unset($metadata);

        $relative = $this->toRelativePath($path);
        if ($relative === null) {
            throw new RuntimeException(__('media.uploads.quarantine_promote_outside'));
        }

        if (!Storage::disk($this->disk)->exists($relative)) {
            throw new RuntimeException(__('media.uploads.quarantine_promote_missing'));
        }

        logger()->info('quarantine.promote', ['path' => $relative]);

        return $path;
    }

    /**
     * Genera una ruta impredecible con particionado 2/2 + nombre de archivo sha256.
     *
     * Crea una estructura de directorio segura para prevenir adivinanzas de nombres de archivo y
     * distribuye archivos a través de múltiples directorios para rendimiento.
     *
     * @return string Ruta relativa en formato "ab/cd/abcdef...bin"
     */
    private function generateRelativePath(): string
    {
        $hash = hash('sha256', Str::random(40) . microtime(true));
        $prefix = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);

        return "{$prefix}/{$hash}.bin";
    }

    /**
     * Convierte ruta absoluta bajo raíz a ruta relativa al disco; nula si está fuera de la raíz.
     *
     * Proporciona seguridad asegurando que las operaciones solo ocurran dentro del directorio
     * de cuarentena y previene ataques de navegación de directorios.
     *
     * @param string $path Ruta absoluta a convertir
     * @return string|null Ruta relativa si está dentro de la cuarentena, nula si está fuera
     */
    private function toRelativePath(string $path): ?string
    {
        // Normaliza a '/'
        $normalizedPath = str_replace('\\', '/', $path);

        // Raíz normalizada con '/' al final
        $normalizedRoot = str_replace('\\', '/', $this->rootPath);
        if (!str_ends_with($normalizedRoot, '/')) {
            $normalizedRoot .= '/';
        }

        if (str_starts_with($normalizedPath, $normalizedRoot)) {
            $relative = substr($normalizedPath, strlen($normalizedRoot));
            // Limpia '.' y bloquea '..'
            $segments = array_filter(explode('/', $relative), static fn($s) => $s !== '' && $s !== '.');
            if (in_array('..', $segments, true)) {
                return null;
            }
            return implode('/', $segments);
        }

        return null;
    }

    /**
     * Arma la ruta absoluta local desde la ruta relativa.
     *
     * @param string $relative Ruta relativa dentro de la cuarentena
     * @return string Ruta absoluta del sistema de archivos
     */
    private function absoluteFromRelative(string $relative): string
    {
        $relative = ltrim($relative, '/\\');
        return $this->rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * Elimina los directorios huérfanos generados para la ruta relativa dada.
     *
     * @param string $relative Ruta relativa del archivo eliminado
     */
    private function cleanupEmptyDirectories(string $relative): void
    {
        $absolute = $this->absoluteFromRelative($relative);
        $directory = dirname($absolute);
        $normalizedRoot = str_replace('\\', '/', $this->rootPath);

        while (is_string($directory) && $directory !== '' && $directory !== DIRECTORY_SEPARATOR) {
            $normalizedDirectory = str_replace('\\', '/', $directory);

            if (!str_starts_with($normalizedDirectory, $normalizedRoot)) {
                break;
            }

            if ($normalizedDirectory === $normalizedRoot) {
                break;
            }

            if (!$this->isDirectoryEmpty($directory)) {
                break;
            }

            if (!@rmdir($directory)) {
                logger()->warning('quarantine.cleanup_failed', ['directory' => $directory]);
                break;
            }

            logger()->info('quarantine.cleanup', ['directory' => $directory]);
            $directory = dirname($directory);
        }
    }

    /**
     * Determina si un directorio está vacío.
     *
     * @param string $path Ruta del directorio
     * @return bool
     */
    private function isDirectoryEmpty(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $handle = @opendir($path);
        if ($handle === false) {
            return false;
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            closedir($handle);
            return false;
        }

        closedir($handle);
        return true;
    }

    /**
     * Resuelve la configuración válida del disco de cuarentena con retroceso al disco por defecto.
     *
     * @param string $preferred Nombre del disco configurado
     * @return array{disk: string, root: string}
     */
    private function resolveDiskConfiguration(string $preferred): array
    {
        $candidates = array_unique([$preferred, 'quarantine']);
        $preferredFailure = null;

        foreach ($candidates as $candidate) {
            $definition = config("filesystems.disks.{$candidate}");
            if (!is_array($definition)) {
                if ($candidate === $preferred) {
                    logger()->warning('quarantine.disk.undefined', ['disk' => $candidate]);
                    $preferredFailure = $preferredFailure ?? 'missing';
                }
                continue;
            }

            $driver = (string) ($definition['driver'] ?? 'local');
            if ($driver !== 'local') {
                if ($candidate === $preferred) {
                    logger()->warning('quarantine.disk.invalid_driver', ['disk' => $candidate, 'driver' => $driver]);
                    $preferredFailure = 'driver';
                }
                continue;
            }

            $root = $definition['root'] ?? null;
            if (!is_string($root) || $root === '') {
                if ($candidate === $preferred) {
                    logger()->warning('quarantine.disk.missing_root', ['disk' => $candidate]);
                    $preferredFailure = $preferredFailure ?? 'root';
                }
                continue;
            }

            return [
                'disk' => $candidate,
                'root' => rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR),
            ];
        }

        if ($preferredFailure === 'driver') {
            throw new RuntimeException(__('media.uploads.quarantine_local_disk_required'));
        }

        throw new RuntimeException(__('media.uploads.quarantine_root_missing', ['disk' => $preferred]));
    }
}
