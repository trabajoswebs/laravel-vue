<?php

declare(strict_types=1);

namespace App\Services\Upload\Core;

use App\Services\Upload\Exceptions\QuarantineException;
use App\Services\Upload\Exceptions\QuarantineIntegrityException;
use Illuminate\Filesystem\FilesystemAdapter;
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
    private const MAX_BYTES = 25 * 1024 * 1024; // 25MB límite duro

    private readonly string $rootPath;

    public function __construct(
        private readonly FilesystemAdapter $filesystem,
    ) {
        // Obtiene la ruta raíz del disco
        $root = $this->filesystem->path('');
        if (!is_string($root) || $root === '') {
            throw new RuntimeException(__('media.uploads.quarantine_root_missing', ['disk' => $this->filesystem->getConfig()['name'] ?? 'quarantine']));
        }

        // Verifica que el disco sea local
        if (!is_dir($root)) {
            throw new RuntimeException(__('media.uploads.quarantine_local_disk_required'));
        }

        // Normaliza la ruta raíz
        $this->rootPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
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
        // Verifica que el archivo no esté vacío
        $length = strlen($bytes);
        if ($length === 0) {
            throw new RuntimeException(__('media.uploads.quarantine_empty_content'));
        }

        // Verifica que el archivo no exceda el tamaño máximo
        if ($length > self::MAX_BYTES) {
            throw new RuntimeException(__('media.uploads.quarantine_max_size', ['bytes' => self::MAX_BYTES]));
        }

        // Genera una ruta única con reintentos limitados
        $attempts = 0;
        do {
            $relative = $this->generateRelativePath();
            if (++$attempts > self::MAX_GENERATION_ATTEMPTS) {
                throw new RuntimeException(__('media.uploads.quarantine_path_failed', ['attempts' => self::MAX_GENERATION_ATTEMPTS]));
            }
        } while ($this->filesystem->exists($relative));

        // Almacena el archivo en el sistema de archivos
        $stored = $this->filesystem->put($relative, $bytes, [
            'visibility' => 'private',
        ]);

        if ($stored === false) {
            throw new RuntimeException(__('media.uploads.quarantine_persist_failed'));
        }

        // Mitiga condición de carrera: confirma que existe
        if (!$this->filesystem->exists($relative)) {
            throw new RuntimeException(__('media.uploads.quarantine_artifact_missing'));
        }

        // Almacena el hash del archivo para verificación de integridad
        $hash = hash('sha256', $bytes);
        if ($hash === false) {
            throw new RuntimeException(__('media.uploads.quarantine_hash_failed'));
        }

        $this->storeHash($relative, $hash);
        $absolute = $this->absoluteFromRelative($relative);
        logger()->info('quarantine.put', ['path' => $relative]);

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
        // Convierte la ruta absoluta a relativa
        $relative = $this->toRelativePath($path);
        if ($relative === null) {
            logger()->warning('quarantine.delete.outside_root', ['path' => $this->redactPath($path)]);
            return;
        }

        try {
            $deleted = $this->filesystem->delete($relative);
        } catch (\Throwable $e) {
            throw new QuarantineException(__('media.uploads.quarantine_delete_failed', ['error' => $e->getMessage()]));
        }

        if ($deleted === false) {
            throw new QuarantineException(__('media.uploads.quarantine_delete_failed', ['error' => 'delete operation returned false']));
        }

        // Elimina el archivo de hash asociado
        $this->deleteStoredHash($relative);
        // Limpia directorios vacíos
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
     * @throws RuntimeException Cuando el archivo está fuera de la raíz de cuarentena o no existe
     */
    public function promote(string $path, array $metadata = []): string
    {
        // Convierte la ruta absoluta a relativa
        $relative = $this->toRelativePath($path);
        if ($relative === null) {
            throw new QuarantineException(__('media.uploads.quarantine_promote_outside'));
        }

        // Verifica que el archivo exista
        if (!$this->filesystem->exists($relative)) {
            throw new QuarantineException(__('media.uploads.quarantine_promote_missing'));
        }

        // Verifica la integridad del archivo
        $expectedHash = $this->readStoredHash($relative);
        $absolute = $this->absoluteFromRelative($relative);
        $currentHash = hash_file('sha256', $absolute) ?: null;

        if ($expectedHash !== null && $currentHash !== $expectedHash) {
            throw new QuarantineIntegrityException(__('media.uploads.quarantine_promote_integrity'), $expectedHash, $currentHash);
        }

        // Determina el destino final del archivo
        [$destinationRelative, $destinationAbsolute] = $this->determinePromotionTarget($metadata);
        // Asegura que el directorio de destino exista
        $this->ensureDirectoryExists(dirname($destinationAbsolute));

        $temporaryRelative = "{$destinationRelative}.tmp";
        try {
            // Elimina el archivo temporal si existe
            $this->filesystem->delete($temporaryRelative);
            // Mueve el archivo a la ubicación temporal
            $stageMove = $this->filesystem->move($relative, $temporaryRelative);
            if ($stageMove === false) {
                throw new QuarantineException(__('media.uploads.quarantine_promote_move_failed', ['error' => 'staging move returned false']));
            }

            // Mueve el archivo de la ubicación temporal a la final
            $finalMove = $this->filesystem->move($temporaryRelative, $destinationRelative);
            if ($finalMove === false) {
                throw new QuarantineException(__('media.uploads.quarantine_promote_move_failed', ['error' => 'final move returned false']));
            }
        } catch (\Throwable $e) {
            // Si falla, restaura el archivo original
            if ($this->filesystem->exists($temporaryRelative)) {
                $this->filesystem->move($temporaryRelative, $relative);
            }
            throw new QuarantineException(__('media.uploads.quarantine_promote_move_failed', ['error' => $e->getMessage()]));
        }

        // Elimina el archivo de hash asociado al archivo original
        $this->deleteStoredHash($relative);
        logger()->info('quarantine.promote', ['from' => $relative, 'to' => $destinationRelative]);

        return $destinationAbsolute;
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
        $hash = bin2hex(random_bytes(32));
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

            $relativeDir = $this->toRelativePath($directory);
            if ($relativeDir === null) {
                break;
            }

            try {
                $this->filesystem->deleteDirectory($relativeDir);
            } catch (\Throwable $e) {
                logger()->warning('quarantine.cleanup_failed', [
                    'directory' => $this->redactPath($directory),
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            logger()->info('quarantine.cleanup', ['directory' => $this->redactPath($directory)]);
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

        $handle = opendir($path);
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
     * Determina el destino final del archivo promovido.
     *
     * @param array $metadata
     * @return array{0:string,1:string} [relative, absolute]
     */
    private function determinePromotionTarget(array $metadata): array
    {
        $destination = $metadata['destination'] ?? null;
        if (is_string($destination) && $destination !== '') {
            $relative = $this->normalizeDestination($destination);
        } else {
            $relative = $this->sanitizeRelativePath('promoted/' . $this->generateRelativeFilename());
        }

        return [$relative, $this->absoluteFromRelative($relative)];
    }

    /**
     * Normaliza la ruta de destino.
     *
     * @param string $destination Ruta de destino.
     * @return string Ruta relativa normalizada.
     * @throws QuarantineException Si la ruta está fuera de la raíz de cuarentena.
     */
    private function normalizeDestination(string $destination): string
    {
        $destination = str_replace('\\', '/', $destination);

        // Verifica si es una ruta absoluta
        if (str_starts_with($destination, '/') || preg_match('/^[A-Za-z]:\//', $destination) === 1) {
            $relative = $this->toRelativePath($destination);
            if ($relative === null) {
                throw new QuarantineException(__('media.uploads.quarantine_destination_outside'));
            }

            return $relative;
        }

        $sanitized = $this->sanitizeRelativePath($destination);
        return $sanitized;
    }

    /**
     * Genera un nombre de archivo relativo seguro.
     *
     * @return string Nombre de archivo relativo.
     */
    private function generateRelativeFilename(): string
    {
        $hash = bin2hex(random_bytes(32));
        $prefix = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);

        return "{$prefix}/{$hash}.bin";
    }

    /**
     * Asegura que un directorio exista.
     *
     * @param string $absolute Ruta absoluta del directorio.
     * @throws QuarantineException Si el directorio está fuera de la raíz de cuarentena.
     */
    private function ensureDirectoryExists(string $absolute): void
    {
        if (is_dir($absolute)) {
            return;
        }

        $relative = $this->toRelativePath($absolute);
        if ($relative === null) {
            throw new QuarantineException(__('media.uploads.quarantine_directory_outside'));
        }

        $created = $this->filesystem->makeDirectory($relative, 0755, true);
        if ($created === false) {
            throw new QuarantineException(__('media.uploads.quarantine_directory_create_failed'));
        }
    }

    /**
     * Almacena el hash del archivo en un archivo secundario.
     *
     * @param string $relative Ruta relativa del archivo.
     * @param string $hash Hash del archivo.
     */
    private function storeHash(string $relative, string $hash): void
    {
        $this->filesystem->put($this->hashSidecarPath($relative), $hash, ['visibility' => 'private']);
    }

    /**
     * Lee el hash almacenado del archivo.
     *
     * @param string $relative Ruta relativa del archivo.
     * @return string|null Hash del archivo o null si no existe.
     */
    private function readStoredHash(string $relative): ?string
    {
        $hashPath = $this->hashSidecarPath($relative);
        if (!$this->filesystem->exists($hashPath)) {
            return null;
        }

        $hash = $this->filesystem->get($hashPath);

        return is_string($hash) && $hash !== '' ? trim($hash) : null;
    }

    /**
     * Elimina el archivo de hash secundario.
     *
     * @param string $relative Ruta relativa del archivo.
     */
    private function deleteStoredHash(string $relative): void
    {
        $hashPath = $this->hashSidecarPath($relative);
        if ($this->filesystem->exists($hashPath)) {
            $this->filesystem->delete($hashPath);
        }
    }

    /**
     * Obtiene la ruta del archivo de hash secundario.
     *
     * @param string $relative Ruta relativa del archivo.
     * @return string Ruta del archivo de hash.
     */
    private function hashSidecarPath(string $relative): string
    {
        return "{$relative}.sha256";
    }

    /**
     * Sanitiza una ruta relativa.
     *
     * @param string $path Ruta relativa a sanitizar.
     * @return string Ruta relativa sanitizada.
     * @throws QuarantineException Si la ruta es inválida o está fuera de la raíz de cuarentena.
     */
    private function sanitizeRelativePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $segments = array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '' && $segment !== '.');
        if ($segments === []) {
            throw new QuarantineException(__('media.uploads.quarantine_destination_invalid'));
        }

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new QuarantineException(__('media.uploads.quarantine_destination_outside'));
            }
        }

        return implode('/', $segments);
    }

    /**
     * Elimina archivos antiguos de cuarentena.
     *
     * @param int $maxAgeHours Edad máxima en horas.
     * @return int Número de archivos eliminados.
     */
    public function pruneStaleFiles(int $maxAgeHours = 24): int
    {
        $threshold = time() - ($maxAgeHours * 3600);
        $pruned = 0;

        foreach ($this->filesystem->allFiles() as $relative) {
            if (str_ends_with($relative, '.sha256')) {
                continue;
            }

            $absolute = $this->absoluteFromRelative($relative);
            try {
                if ($this->filesystem->lastModified($relative) < $threshold) {
                    $this->delete($absolute);
                    ++$pruned;
                }
            } catch (\Throwable $e) {
                logger()->warning('quarantine.prune_failed', [
                    'path' => $relative,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $pruned;
    }

    /**
     * Elimina archivos de hash huérfanos.
     *
     * @return int Número de archivos de hash eliminados.
     */
    public function cleanupOrphanedSidecars(): int
    {
        $cleaned = 0;

        foreach ($this->filesystem->allFiles() as $relative) {
            if (!str_ends_with($relative, '.sha256')) {
                continue;
            }

            $original = substr($relative, 0, -7);
            if (!$this->filesystem->exists($original)) {
                try {
                    $this->filesystem->delete($relative);
                    ++$cleaned;
                } catch (\Throwable $e) {
                    logger()->warning('quarantine.sidecar_cleanup_failed', [
                        'path' => $relative,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $cleaned;
    }

    /**
     * Redacta rutas absolutas para logging.
     */
    private function redactPath(string $path): string
    {
        $relative = $this->toRelativePath($path);
        if ($relative !== null) {
            return $relative;
        }

        $basename = basename($path);

        return $basename !== '' ? $basename : '[outside-quarantine]';
    }
}
