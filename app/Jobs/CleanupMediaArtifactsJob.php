<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteDirectory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Job que limpia directorios residuales (artefactos) de medios eliminados.
 *
 * Este job se encarga de eliminar directorios de medios que ya no son necesarios,
 * como archivos originales, conversiones o imágenes responsivas, que quedaron
 * tras la eliminación de un medio en la base de datos.
 *
 * ✨ Características principales:
 * - **Seguro**: Revalida la existencia del Media justo antes de borrar (mitiga race condition).
 * - **Idempotente**: Si el directorio no existe, no falla; si ya se borró, lo registra como "missing".
 * - **Observabilidad**: Métricas de ejecución (deleted, missing, exists, preserved, skipped_invalid, errors).
 * - **Escalable**: Límite de artefactos por disco y cola dedicada para medios.
 * - **Robusto**: Maneja errores de disco, rutas inválidas y verifica existencia de medios.
 * - **Configurable**: Usa cola dedicada y se ejecuta tras commits de base de datos.
 *
 * Ejemplo de uso:
 *   CleanupMediaArtifactsJob::dispatch([
 *       'public' => [
 *           ['dir' => '123/conversions', 'mediaId' => '123'],
 *           ['dir' => '456/responsive-images', 'mediaId' => '456'],
 *       ],
 *       's3'     => ['789'], // Formato legacy soportado como fallback
 *   ], preserveMediaIds: ['456']);
 */
final class CleanupMediaArtifactsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Límite de artefactos por disco para evitar sobrecargas accidentales.
     * Si un disco tiene más de este número de directorios para limpiar,
     * el job lanza una excepción y se cancela.
     */
    private const MAX_ARTIFACTS_PER_DISK = 1000;

    // Configuración de la cola para este job
    public int $tries = 3;              // Número de reintentos si falla
    public int $timeout = 120;          // Tiempo máximo de ejecución en segundos
    public int $maxExceptions = 3;      // Máximo de excepciones antes de marcar como fallido

    /** @var array<string,bool> Cache de existencia de medias por ciclo de ejecución. */
    private array $mediaExistsCache = [];

    /**
     * @param array<string, list<string|array{dir:string,mediaId?:string|null}>> $artifacts
     *        Mapa disco => lista de artefactos (string legacy o array enriquecida).
     * @param array<int|string> $preserveMediaIds IDs de medios a preservar.
     */
    public function __construct(
        private readonly array $artifacts,
        private readonly array $preserveMediaIds = [],
    ) {
        // Valida que no se exceda el límite de artefactos por disco
        if ($this->exceedsArtifactLimit($artifacts)) {
            throw new \InvalidArgumentException('Too many artifacts to process safely.');
        }

        // Cola y consistencia transaccional (ejecuta tras commit de BD).
        $this->onQueue(config('queue.aliases.media', 'media'));
        $this->afterCommit();
    }

    /**
     * Procesa la limpieza de directorios residuales con métricas y hardening.
     *
     * Este método:
     * - Itera por cada disco y directorios asociados.
     * - Verifica que el disco sea válido y esté configurado.
     * - Normaliza las rutas y las asocia al media original cuando está disponible.
     * - Elimina solo si no hay medios asociados ni está en la lista de preservación.
     * - Revalida la existencia del Media justo antes de borrar (mitiga race condition).
     * - Acumula métricas de ejecución y registra un log resumen al final.
     */
    public function handle(): void
    {
        $t0 = microtime(true); // Marca de inicio para calcular duración

        // Métricas agregadas de resultado
        $stats = [
            'deleted'         => 0,  // directorios eliminados
            'missing'         => 0,  // ya no existían
            'exists'          => 0,  // media reapareció/existe → no borrar
            'preserved'       => 0,  // media en lista de preservación
            'skipped_invalid' => 0,  // path inválido / vacío / traversal
            'errors'          => 0,  // excepciones al borrar
        ];

        $preserve = $this->normalizePreserveIds($this->preserveMediaIds);

        foreach ($this->artifacts as $disk => $directories) {
            // 0) Valida que el disco exista en config/filesystems.php
            if (!$this->isValidDisk($disk)) {
                Log::warning('cleanup_media_artifacts_invalid_disk', ['disk' => (string) $disk]);
                continue;
            }

            // 1) Obtiene adapter del disco
            try {
                $fs = Storage::disk((string) $disk);
            } catch (\Throwable $e) {
                Log::error('cleanup_media_artifacts_disk_unavailable', [
                    'disk'  => (string) $disk,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            // 2) Verifica tipo del adapter (útil para el analizador estático e IDEs)
            if (!$fs instanceof FilesystemAdapter) {
                Log::warning('cleanup_media_artifacts_unexpected_fs_instance', [
                    'disk'  => (string) $disk,
                    'class' => is_object($fs) ? get_class($fs) : gettype($fs),
                ]);
                continue;
            }

            $entries = $this->normalizeArtifactEntries(is_array($directories) ? $directories : []);
            if ($entries === []) {
                continue;
            }

            foreach ($entries as $item) {
                $directory = $item['dir'];
                $mediaId   = $item['mediaId'] ?? null;

                if (
                    !is_string($directory) &&
                    !$directory instanceof \Stringable &&
                    !is_int($directory) &&
                    !is_float($directory)
                ) {
                    $stats['skipped_invalid']++;
                    continue;
                }

                // Respeta lista de preservación
                if ($mediaId !== null && in_array($mediaId, $preserve, true)) {
                    $stats['preserved']++;
                    continue;
                }

                $result = $this->deleteDirectorySafely(
                    $fs,
                    (string) $disk,
                    (string) $directory,
                    $mediaId
                );
                $stats[$result] = ($stats[$result] ?? 0) + 1;
            }
        }

        // 6) Log resumen con métricas y duración
        Log::info('cleanup_media_artifacts_completed', [
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000), // Duración en milisegundos
            'stats'       => $stats,                                      // Métricas de ejecución
            'disks'       => array_keys($this->artifacts),               // Discos procesados
        ]);
    }

    /**
     * Normaliza los artefactos recibidos (strings legacy o arrays enriquecidas).
     *
     * @param list<mixed> $directories
     * @return list<array{dir:string,mediaId:?string}>
     */
    private function normalizeArtifactEntries(array $directories): array
    {
        $normalized = [];

        foreach ($directories as $entry) {
            $directory = null;
            $mediaId = null;

            if (is_array($entry)) {
                $directory = $entry['dir'] ?? null;
                if (array_key_exists('mediaId', $entry) && $entry['mediaId'] !== null && $entry['mediaId'] !== '') {
                    $mediaId = (string) $entry['mediaId'];
                }
            } elseif (is_string($entry)) {
                $directory = $entry;
            } else {
                continue;
            }

            $clean = $this->sanitizeDirectory($directory);
            if ($clean === null) {
                continue;
            }

            if ($mediaId === null) {
                $fallback = $this->extractBaseId($clean);
                if ($fallback !== null) {
                    $mediaId = (string) $fallback;
                }
            }

            $key = $clean . '|' . ($mediaId ?? '');
            if (isset($normalized[$key])) {
                continue;
            }

            $normalized[$key] = [
                'dir' => $clean,
                'mediaId' => $mediaId,
            ];
        }

        return array_values($normalized);
    }

    /**
     * Sanitiza directorios recortando espacios y separadores redundantes.
     */
    private function sanitizeDirectory(mixed $directory): ?string
    {
        if ($directory instanceof \Stringable) {
            $directory = (string) $directory;
        } elseif (is_int($directory) || is_float($directory)) {
            $directory = (string) $directory;
        } elseif (!is_string($directory)) {
            return null;
        }

        $trimmed = trim($directory, " \t\n\r\0\x0B");
        $trimmed = trim($trimmed, "/\\");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Normaliza los IDs de preservación a strings no vacías y únicos.
     *
     * @param array<int|string> $ids
     * @return list<string>
     */
    private function normalizePreserveIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if ($id === null) {
                continue;
            }

            $stringId = trim((string) $id);
            if ($stringId === '') {
                continue;
            }

            $normalized[] = $stringId;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Extrae el ID numérico base de un directorio (primer segmento del path).
     *
     * Por ejemplo:
     * - "123/conversions" → 123
     * - "456/responsive-images" → 456
     * - "invalid/path" → null
     *
     * También previene inyecciones de path traversal o caracteres inválidos.
     *
     * @return int|null ID numérico base o null si no es válido
     */
    private function extractBaseId(string $directory): ?int
    {
        // Validaciones de seguridad para evitar inyecciones de path
        if (
            $directory === '' ||
            str_contains($directory, '..') || // Evita ../ traversal
            str_contains($directory, '\\') || // Evita backslashes
            str_starts_with($directory, '/')  // Evita paths absolutos
        ) {
            return null;
        }

        // Divide el path por '/' y toma el primer segmento
        $parts = explode('/', $directory);
        $base = $parts[0] ?? '';

        // Verifica que sea un número
        if ($base === '' || !ctype_digit($base)) {
            return null;
        }

        return (int) $base;
    }

    /**
     * Verifica si un disco es válido según la configuración de filesystems de Laravel.
     *
     * @param mixed $disk Nombre del disco a verificar
     * @return bool True si el disco está definido en config/filesystems.php
     */
    private function isValidDisk(mixed $disk): bool
    {
        // Debe ser un string no vacío
        if (!is_string($disk) || $disk === '') {
            return false;
        }

        // Obtiene los discos configurados en config/filesystems.php
        $configured = array_keys((array) config('filesystems.disks', []));

        // Verifica si el disco está en la lista de configurados
        return in_array($disk, $configured, true);
    }

    /**
     * Elimina un directorio de forma **segura**, revalidando la existencia del Media
     * justo antes de borrar para mitigar la condición de carrera.
     *
     * Retorna un código de estado para métricas:
     * - 'deleted'         → borrado exitoso
     * - 'missing'         → no existía al intentar borrar
     * - 'exists'          → el Media asociado existe/reapareció → no borrar
     * - 'skipped_invalid' → path inválido o potencial traversal
     * - 'errors'          → excepción al borrar
     *
     * @param FilesystemAdapter $storage Instancia del filesystem para el disco
     * @param string            $disk    Nombre del disco (para logs)
     * @param string            $directory Directorio a eliminar (ya normalizado)
     * @param string|null       $mediaId  ID explícito del media asociado, si se conoce
     *
     * @return string Código de estado para métricas
     */
    private function deleteDirectorySafely(
        FilesystemAdapter $storage,
        string $disk,
        string $directory,
        ?string $mediaId = null
    ): string {
        $normalized = $this->sanitizeDirectory($directory);
        if ($normalized === null) {
            return 'skipped_invalid';
        }

        if (str_contains($normalized, '..') || str_contains($normalized, '\\') || str_starts_with($normalized, '/')) {
            Log::warning('cleanup_media_artifacts_invalid_dir', [
                'disk'      => $disk,
                'directory' => $normalized,
            ]);
            return 'skipped_invalid';
        }

        $candidateId = $mediaId;
        if ($candidateId === null) {
            $baseId = $this->extractBaseId($normalized);
            if ($baseId !== null) {
                $candidateId = (string) $baseId;
            }
        }

        if ($candidateId !== null && $this->mediaExists($candidateId, includeTrashed: true)) {
            Log::info('cleanup_media_artifacts_media_exists_on_delete', [
                'disk'      => $disk,
                'directory' => $normalized,
                'media_id'  => $candidateId,
            ]);
            return 'exists';
        }

        $directoryExists = null;

        if (method_exists($storage, 'directoryExists')) {
            try {
                $directoryExists = $storage->directoryExists($normalized);
            } catch (\Throwable $checkError) {
                Log::notice('cleanup_media_artifacts_directory_check_failed', [
                    'disk'      => $disk,
                    'directory' => $normalized,
                    'error'     => $checkError->getMessage(),
                ]);
                $directoryExists = null;
            }
        }

        try {
            $storage->deleteDirectory($normalized);

            if ($directoryExists === false) {
                Log::debug('cleanup_media_artifacts_dir_missing', [
                    'disk'      => $disk,
                    'directory' => $normalized,
                ]);
                return 'missing';
            }

            Log::info('cleanup_media_artifacts_dir_deleted', [
                'disk'      => $disk,
                'directory' => $normalized,
            ]);
            return 'deleted';
        } catch (UnableToDeleteDirectory $exception) {
            if ($directoryExists === false) {
                Log::debug('cleanup_media_artifacts_dir_missing', [
                    'disk'      => $disk,
                    'directory' => $normalized,
                ]);
                return 'missing';
            }

            Log::warning('cleanup_media_artifacts_failed', [
                'disk'      => $disk,
                'directory' => $normalized,
                'error'     => $exception->getMessage(),
            ]);
            return 'errors';
        } catch (\Throwable $exception) {
            Log::warning('cleanup_media_artifacts_failed', [
                'disk'      => $disk,
                'directory' => $normalized,
                'error'     => $exception->getMessage(),
            ]);
            return 'errors';
        }
    }

    /**
     * Verifica si se excede el límite de artefactos por disco.
     *
     * @param array<string,mixed> $artifacts Mapa disco => [directorios]
     * @return bool True si algún disco tiene más artefactos que el límite
     */
    private function exceedsArtifactLimit(array $artifacts): bool
    {
        foreach ($artifacts as $directories) {
            // Verifica que sea un array y cuente sus elementos
            if (is_array($directories) && count($directories) > self::MAX_ARTIFACTS_PER_DISK) {
                return true; // Límite excedido
            }
        }

        return false; // Ningún disco excede el límite
    }

    /**
     * Verifica si un Media existe en la base de datos.
     *
     * Si el modelo usa SoftDeletes y $includeTrashed=true, incluye borrados lógicamente.
     *
     * @param int|string $id ID del medio a verificar
     * @param bool $includeTrashed Si true, incluye medios borrados lógicamente (con SoftDeletes)
     * @return bool True si el medio existe
     */
    private function mediaExists(int|string $id, bool $includeTrashed = true): bool
    {
        $key = ($includeTrashed ? 'with:' : 'without:') . (string) $id;

        if (array_key_exists($key, $this->mediaExistsCache)) {
            return $this->mediaExistsCache[$key];
        }

        $query = Media::query();

        if ($includeTrashed && in_array(SoftDeletes::class, class_uses_recursive(Media::class), true)) {
            $query->withTrashed();
        }

        $exists = $query->whereKey($id)->exists();
        $this->mediaExistsCache[$key] = $exists;

        return $exists;
    }
}
