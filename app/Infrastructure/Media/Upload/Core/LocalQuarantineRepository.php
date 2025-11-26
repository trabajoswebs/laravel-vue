<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Upload\Core;

// Importamos las clases necesarias
use App\Infrastructure\Media\Upload\Exceptions\QuarantineException; // Excepción para fallos en cuarentena
use App\Infrastructure\Media\Upload\Exceptions\QuarantineIntegrityException; // Excepción para fallos de integridad
use Illuminate\Filesystem\FilesystemAdapter; // Adaptador de sistema de archivos de Laravel
use RuntimeException; // Excepción estándar de PHP

/**
 * Implementación en sistema de archivos local del Repositorio de Cuarentena.
 *
 * Características:
 *  - Confinamiento estricto bajo un directorio raíz local.
 *  - Rutas impredecibles, particionadas (2/2 + hash.bin).
 *  - Límite "duro" de tamaño configurable para artefactos.
 *  - Integridad mediante sidecar .sha256.
 *  - Limpieza de artefactos antiguos y sidecars huérfanos.
 */
final class LocalQuarantineRepository implements QuarantineRepository
{
    // Número máximo de intentos para generar una ruta única
    private const MAX_GENERATION_ATTEMPTS = 5;

    /**
     * Límite máximo de bytes para un artefacto en cuarentena.
     * Se inicializa desde config:
     *  - image-pipeline.quarantine_max_size
     *  - fallback: image-pipeline.max_upload_size
     *  - fallback final: 25MB
     */
    private int $maxBytes;

    /**
     * Ruta absoluta normalizada a la raíz del disco de cuarentena.
     */
    private readonly string $rootPath;

    /**
     * Constructor del repositorio de cuarentena local.
     *
     * @param FilesystemAdapter $filesystem Adaptador de sistema de archivos para cuarentena
     */
    public function __construct(
        private readonly FilesystemAdapter $filesystem, // Adaptador de sistema de archivos
    ) {
        // Obtenemos la ruta raíz del disco de cuarentena
        $root = $this->filesystem->path('');
        if (! is_string($root) || $root === '') {
            throw new RuntimeException(__('media.uploads.quarantine_root_missing', [
                'disk' => $this->filesystem->getConfig()['name'] ?? 'quarantine',
            ]));
        }

        // Verificamos que sea un directorio
        if (! is_dir($root)) {
            throw new RuntimeException(__('media.uploads.quarantine_local_disk_required'));
        }

        // Normaliza la ruta raíz y la deja sin separador final.
        $this->rootPath = rtrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root),
            DIRECTORY_SEPARATOR,
        );

        // Configuramos el tamaño máximo permitido para archivos en cuarentena
        $configuredMax = (int) config(
            'image-pipeline.quarantine_max_size',
            (int) config('image-pipeline.max_upload_size', 25 * 1024 * 1024),
        );

        $this->maxBytes = $configuredMax > 0
            ? $configuredMax
            : 25 * 1024 * 1024; // Fallback a 25MB
    }

    /**
     * Almacena bytes en cuarentena y devuelve la ruta local absoluta.
     *
     * @param string $bytes Bytes a almacenar
     * @return string Ruta absoluta al archivo en cuarentena
     * @throws RuntimeException Si hay un error al almacenar
     */
    public function put(string $bytes): string
    {
        $length = strlen($bytes);

        // Verificamos que no esté vacío
        if ($length === 0) {
            throw new RuntimeException(__('media.uploads.quarantine_empty_content'));
        }

        // Verificamos que no exceda el tamaño máximo
        if ($length > $this->maxBytes) {
            throw new RuntimeException(__('media.uploads.quarantine_max_size', ['bytes' => $this->maxBytes]));
        }

        // Generamos una ruta única para el archivo
        $relative = $this->generateUniqueRelativePath();

        // Almacenamos el archivo en el sistema de archivos
        $stored = $this->filesystem->put($relative, $bytes, [
            'visibility' => 'private', // Asegura que el archivo sea privado
        ]);

        if ($stored === false) {
            throw new RuntimeException(__('media.uploads.quarantine_persist_failed'));
        }

        // Verificamos que el archivo exista después de almacenarlo
        if (! $this->filesystem->exists($relative)) {
            throw new RuntimeException(__('media.uploads.quarantine_artifact_missing'));
        }

        // Calculamos el hash SHA256 del contenido
        $hash = hash('sha256', $bytes);
        if ($hash === false) {
            throw new RuntimeException(__('media.uploads.quarantine_hash_failed'));
        }

        // Almacenamos el hash en un archivo secundario
        $this->storeHash($relative, $hash);

        // Convertimos la ruta relativa a absoluta
        $absolute = $this->absoluteFromRelative($relative);

        // Registramos la operación
        logger()->info('quarantine.put', ['path' => $relative]);

        return $absolute;
    }

    /**
     * Almacena un stream en cuarentena sin cargar todos los bytes en memoria.
     *
     * @param  resource  $stream  Recurso abierto para lectura.
     * @return string   Ruta absoluta al archivo en cuarentena.
     *
     * @throws RuntimeException
     */
    public function putStream($stream): string
    {
        // Verificamos que sea un recurso válido
        if (! is_resource($stream)) {
            throw new RuntimeException('Stream resource required for quarantine storage.');
        }

        // Obtenemos metadatos del stream
        $metadata = stream_get_meta_data($stream);

        // Si hay URI, aseguramos que esté al inicio
        if (isset($metadata['uri']) && is_string($metadata['uri']) && $metadata['uri'] !== '') {
            // Asegurarse de empezar al inicio del stream.
            rewind($stream);
        }

        // Obtenemos el tamaño del stream
        $stats  = fstat($stream) ?: [];
        $length = $stats['size'] ?? null;

        // Validamos tamaño
        if ($length !== null && $length <= 0) {
            throw new RuntimeException(__('media.uploads.quarantine_empty_content'));
        }

        if ($length !== null && $length > $this->maxBytes) {
            throw new RuntimeException(__('media.uploads.quarantine_max_size', ['bytes' => $this->maxBytes]));
        }

        // Generamos una ruta única
        $relative = $this->generateUniqueRelativePath();
        $absolute = $this->absoluteFromRelative($relative);
        $this->ensureDirectoryExists(dirname($absolute));

        $bytesPersisted = $this->persistStreamToDisk($stream, $absolute);

        // Verificamos que exista el archivo
        if (! $this->filesystem->exists($relative)) {
            throw new RuntimeException(__('media.uploads.quarantine_artifact_missing'));
        }

        // Calculamos el hash del archivo recién creado
        $hash = hash_file('sha256', $absolute);
        if ($hash === false) {
            $this->filesystem->delete($relative);
            throw new RuntimeException(__('media.uploads.quarantine_hash_failed'));
        }

        // Almacenamos el hash
        $this->storeHash($relative, $hash);

        // Registramos la operación
        logger()->info('quarantine.put_stream', [
            'path' => $relative,
            'bytes' => $bytesPersisted,
        ]);

        return $absolute;
    }

    /**
     * Elimina un artefacto de cuarentena.
     *
     * Contrato:
     *  - Si la ruta no pertenece a la cuarentena, se loguea y se retorna sin lanzar excepción.
     *  - Si el archivo no existe, la operación es silenciosa.
     *  - Si el backend de almacenamiento falla, se lanza QuarantineException.
     *
     * @param string $path Ruta absoluta del archivo a eliminar
     */
    public function delete(string $path): void
    {
        // Convertimos la ruta absoluta a relativa
        $relative = $this->toRelativePath($path);

        if ($relative === null) {
            // Si la ruta está fuera de la raíz de cuarentena, registramos advertencia
            logger()->warning('quarantine.delete.outside_root', [
                'path' => $this->redactPath($path),
            ]);

            return;
        }

        try {
            // Intentamos eliminar el archivo
            $deleted = $this->filesystem->delete($relative);
        } catch (\Throwable $e) {
            // Si falla la eliminación, lanzamos excepción específica
            throw new QuarantineException(__('media.uploads.quarantine_delete_failed', [
                'error' => $e->getMessage(),
            ]));
        }

        if ($deleted === false) {
            // Si la operación devuelve false, lanzamos excepción
            throw new QuarantineException(__('media.uploads.quarantine_delete_failed', [
                'error' => 'delete operation returned false',
            ]));
        }

        // Eliminamos el archivo de hash secundario
        $this->deleteStoredHash($relative);
        // Limpiamos directorios vacíos si es necesario
        $this->cleanupEmptyDirectories($relative);

        // Registramos la operación
        logger()->info('quarantine.delete', ['path' => $relative]);
    }

    /**
     * Valida y promueve un artefacto desde cuarentena a su destino definitivo.
     *
     * @param  string               $path      Ruta absoluta del artefacto en cuarentena.
     * @param  array<string,mixed>  $metadata  Puede incluir 'destination' (relativa o absoluta).
     * @return string                           Ruta absoluta del archivo promovido.
     *
     * @throws QuarantineException
     */
    public function promote(string $path, array $metadata = []): string
    {
        // Convertimos la ruta absoluta a relativa
        $relative = $this->toRelativePath($path);

        if ($relative === null) {
            throw new QuarantineException(__('media.uploads.quarantine_promote_outside'));
        }

        // Verificamos que el archivo exista
        if (! $this->filesystem->exists($relative)) {
            throw new QuarantineException(__('media.uploads.quarantine_promote_missing'));
        }

        // Verificamos la integridad del archivo comparando hashes
        $expectedHash = $this->readStoredHash($relative);
        $absolute     = $this->absoluteFromRelative($relative);
        $currentHash  = hash_file('sha256', $absolute) ?: null;

        if ($expectedHash !== null && $currentHash !== $expectedHash) {
            // Si los hashes no coinciden, lanzamos excepción de integridad
            throw new QuarantineIntegrityException(
                __('media.uploads.quarantine_promote_integrity'),
                $expectedHash,
                $currentHash,
            );
        }

        // Determinamos el destino final
        [$destinationRelative, $destinationAbsolute] = $this->determinePromotionTarget($metadata);

        // Aseguramos que el directorio destino exista
        $this->ensureDirectoryExists(dirname($destinationAbsolute));

        // Creamos un archivo temporal para la operación de movimiento
        $temporaryRelative = "{$destinationRelative}.tmp";

        try {
            // Limpia cualquier archivo temporal previo.
            $this->filesystem->delete($temporaryRelative);

            // Movemos el archivo a temporal
            $stageMove = $this->filesystem->move($relative, $temporaryRelative);
            if ($stageMove === false) {
                throw new QuarantineException(__('media.uploads.quarantine_promote_move_failed', [
                    'error' => 'staging move returned false',
                ]));
            }

            // Movemos de temporal a destino final
            $finalMove = $this->filesystem->move($temporaryRelative, $destinationRelative);
            if ($finalMove === false) {
                throw new QuarantineException(__('media.uploads.quarantine_promote_move_failed', [
                    'error' => 'final move returned false',
                ]));
            }
        } catch (\Throwable $e) {
            // Si falla, restauramos el archivo original
            if ($this->filesystem->exists($temporaryRelative)) {
                $this->filesystem->move($temporaryRelative, $relative);
            }

            throw new QuarantineException(__('media.uploads.quarantine_promote_move_failed', [
                'error' => $e->getMessage(),
            ]));
        }

        // Eliminamos el archivo de hash del archivo original en cuarentena
        $this->deleteStoredHash($relative);

        // Registramos la operación
        logger()->info('quarantine.promote', [
            'from' => $relative,
            'to'   => $destinationRelative,
        ]);

        return $destinationAbsolute;
    }

    /**
     * Genera una ruta relativa única con particionado 2/2 + hash.bin.
     *
     * @return string Ruta relativa única
     * @throws RuntimeException Si no puede generar una ruta única después de varios intentos
     */
    private function generateUniqueRelativePath(): string
    {
        $attempts = 0;

        do {
            // Generamos una ruta particionada
            $relative = $this->generatePartitionedRelativePath();

            // Incrementamos intentos y verificamos límite
            if (++$attempts > self::MAX_GENERATION_ATTEMPTS) {
                throw new RuntimeException(__('media.uploads.quarantine_path_failed', [
                    'attempts' => self::MAX_GENERATION_ATTEMPTS,
                ]));
            }
        } while ($this->filesystem->exists($relative)); // Repetir mientras exista

        return $relative;
    }

    /**
     * Genera una ruta relativa particionada (ab/cd/hash.bin).
     *
     * @param string $baseDir Directorio base opcional
     * @return string Ruta relativa particionada
     */
    private function generatePartitionedRelativePath(string $baseDir = ''): string
    {
        // Generamos un hash aleatorio de 64 caracteres hexadecimales
        $hash   = bin2hex(random_bytes(32));
        // Tomamos los primeros 4 caracteres y los particionamos como ab/cd
        $prefix = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        // Creamos la ruta final como ab/cd/hash.bin
        $path   = "{$prefix}/{$hash}.bin";

        // Si hay directorio base, lo añadimos
        if ($baseDir !== '') {
            $baseDir = trim(str_replace('\\', '/', $baseDir), '/');
            $path    = "{$baseDir}/{$path}";
        }

        return $path;
    }

    /**
     * Convierte ruta absoluta bajo raíz a ruta relativa al disco.
     *
     * @param string $path Ruta absoluta a convertir
     * @return string|null  Ruta relativa si está dentro de la cuarentena, null si está fuera.
     */
    private function toRelativePath(string $path): ?string
    {
        // Normalizamos la ruta a formato Unix
        $normalizedPath = str_replace('\\', '/', $path);

        // Normalizamos la raíz
        $normalizedRoot = str_replace('\\', '/', $this->rootPath);
        if (! str_ends_with($normalizedRoot, '/')) {
            $normalizedRoot .= '/';
        }

        // Verificamos que la ruta esté dentro de la raíz
        if (str_starts_with($normalizedPath, $normalizedRoot)) {
            // Extraemos la parte relativa
            $relative = substr($normalizedPath, strlen($normalizedRoot));

            // Dividimos en segmentos y eliminamos vacíos y '.'
            $segments = array_filter(
                explode('/', $relative),
                static fn(string $s): bool => $s !== '' && $s !== '.',
            );

            // Si hay '..' en los segmentos, está fuera de la raíz
            if (in_array('..', $segments, true)) {
                return null;
            }

            // Reconstruimos la ruta relativa
            return implode('/', $segments);
        }

        return null;
    }

    /**
     * Arma la ruta absoluta local desde la ruta relativa.
     *
     * @param string $relative Ruta relativa
     * @return string Ruta absoluta completa
     */
    private function absoluteFromRelative(string $relative): string
    {
        // Eliminamos separadores iniciales
        $relative = ltrim($relative, '/\\');

        // Combinamos la raíz con la ruta relativa usando el separador del sistema
        return $this->rootPath . DIRECTORY_SEPARATOR . str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            $relative,
        );
    }

    /**
     * Limpia directorios huérfanos generados para la ruta relativa dada.
     *
     * @param string $relative Ruta relativa del archivo
     */
    private function cleanupEmptyDirectories(string $relative): void
    {
        // Convertimos a ruta absoluta
        $absolute       = $this->absoluteFromRelative($relative);
        $directory      = dirname($absolute);
        $normalizedRoot = str_replace('\\', '/', $this->rootPath);

        // Subimos por el árbol de directorios
        while (is_string($directory) && $directory !== '' && $directory !== DIRECTORY_SEPARATOR) {
            $normalizedDirectory = str_replace('\\', '/', $directory);

            // Si no está dentro de la raíz, paramos
            if (! str_starts_with($normalizedDirectory, $normalizedRoot)) {
                break;
            }

            // Si llegamos a la raíz, paramos
            if ($normalizedDirectory === $normalizedRoot) {
                break;
            }

            // Si el directorio no está vacío, paramos
            if (! $this->isDirectoryEmpty($directory)) {
                break;
            }

            // Convertimos directorio a ruta relativa
            $relativeDir = $this->toRelativePath($directory);
            if ($relativeDir === null) {
                break;
            }

            try {
                // Eliminamos el directorio vacío
                $this->filesystem->deleteDirectory($relativeDir);
            } catch (\Throwable $e) {
                // Si falla, registramos advertencia y paramos
                logger()->warning('quarantine.cleanup_failed', [
                    'directory' => $this->redactPath($directory),
                    'error'     => $e->getMessage(),
                ]);

                break;
            }

            // Registramos la operación
            logger()->info('quarantine.cleanup', [
                'directory' => $this->redactPath($directory),
            ]);

            // Subimos al directorio padre
            $directory = dirname($directory);
        }
    }

    /**
     * Determina si un directorio está vacío.
     *
     * @param string $path Ruta del directorio
     * @return bool True si está vacío, false si no o si hay error
     */
    private function isDirectoryEmpty(string $path): bool
    {
        // Verificamos que sea un directorio
        if (! is_dir($path)) {
            return false;
        }

        // Abrimos el directorio
        $handle = opendir($path);
        if ($handle === false) {
            return false;
        }

        // Leemos entradas
        while (($entry = readdir($handle)) !== false) {
            // Saltamos . y ..
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            closedir($handle);

            return false; // Si encontramos algo más que . o .., no está vacío
        }

        closedir($handle);

        return true; // Si solo encontramos . y .., está vacío
    }

    /**
     * Determina el destino final del archivo promovido.
     *
     * @param  array<string,mixed> $metadata Metadatos que pueden contener destino
     * @return array{0:string,1:string} [relative, absolute] Rutas relativa y absoluta
     */
    private function determinePromotionTarget(array $metadata): array
    {
        $destination = $metadata['destination'] ?? null;

        if (is_string($destination) && $destination !== '') {
            // Si hay destino específico, lo normalizamos
            $relative = $this->normalizeDestination($destination);
        } else {
            // Si no hay destino, creamos uno en directorio 'promoted'
            $relative = $this->generatePartitionedRelativePath('promoted');
        }

        return [$relative, $this->absoluteFromRelative($relative)];
    }

    /**
     * Normaliza la ruta de destino.
     *
     * @param string $destination Ruta de destino a normalizar
     * @return string Ruta relativa normalizada
     * @throws QuarantineException Si la ruta está fuera de la raíz de cuarentena
     */
    private function normalizeDestination(string $destination): string
    {
        $destination = str_replace('\\', '/', $destination);

        // Ruta absoluta.
        if (str_starts_with($destination, '/') || preg_match('/^[A-Za-z]:\//', $destination) === 1) {
            // Si es ruta absoluta, convertimos a relativa
            $relative = $this->toRelativePath($destination);

            if ($relative === null) {
                throw new QuarantineException(__('media.uploads.quarantine_destination_outside'));
            }

            return $relative;
        }

        // Si es ruta relativa, la sanitizamos
        return $this->sanitizeRelativePath($destination);
    }

    /**
     * Asegura que un directorio exista bajo la raíz de cuarentena.
     *
     * @param string $absolute Ruta absoluta del directorio
     * @throws QuarantineException Si el directorio está fuera de la raíz
     */
    private function ensureDirectoryExists(string $absolute): void
    {
        // Si ya existe, no hacemos nada
        if (is_dir($absolute)) {
            return;
        }

        // Convertimos a ruta relativa
        $relative = $this->toRelativePath($absolute);
        if ($relative === null) {
            throw new QuarantineException(__('media.uploads.quarantine_directory_outside'));
        }

        // Creamos el directorio recursivamente
        $created = $this->filesystem->makeDirectory($relative, 0755, true);
        if ($created === false) {
            throw new QuarantineException(__('media.uploads.quarantine_directory_create_failed'));
        }
    }

    /**
     * Almacena el hash del archivo en un archivo secundario.
     *
     * @param string $relative Ruta relativa del archivo
     * @param string $hash Hash SHA256 del archivo
     */
    private function storeHash(string $relative, string $hash): void
    {
        // Almacenamos el hash en un archivo .sha256 con visibilidad privada
        $this->filesystem->put(
            $this->hashSidecarPath($relative),
            $hash,
            ['visibility' => 'private'],
        );
    }

    /**
     * Lee el hash almacenado del archivo.
     *
     * @param string $relative Ruta relativa del archivo
     * @return string|null Hash almacenado o null si no existe
     */
    private function readStoredHash(string $relative): ?string
    {
        $hashPath = $this->hashSidecarPath($relative);

        if (! $this->filesystem->exists($hashPath)) {
            return null;
        }

        $hash = $this->filesystem->get($hashPath);

        return is_string($hash) && $hash !== '' ? trim($hash) : null;
    }

    /**
     * Elimina el archivo de hash secundario.
     *
     * @param string $relative Ruta relativa del archivo
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
     * @param string $relative Ruta relativa del archivo
     * @return string Ruta del archivo .sha256
     */
    private function hashSidecarPath(string $relative): string
    {
        return "{$relative}.sha256";
    }

    /**
     * Sanitiza una ruta relativa.
     *
     * @param string $path Ruta a sanitizar
     * @return string Ruta sanitizada
     * @throws QuarantineException Si la ruta intenta escapar de la raíz
     */
    private function sanitizeRelativePath(string $path): string
    {
        // Normalizamos y limpiamos la ruta
        $path     = ltrim(str_replace('\\', '/', $path), '/');
        $segments = array_filter(
            explode('/', $path),
            static fn(string $segment): bool => $segment !== '' && $segment !== '.',
        );

        if ($segments === []) {
            throw new QuarantineException(__('media.uploads.quarantine_destination_invalid'));
        }

        // Verificamos que no haya intentos de escapar con '../'
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
     * @param int $maxAgeHours Edad máxima en horas
     * @return int Número de archivos eliminados.
     */
    public function pruneStaleFiles(int $maxAgeHours = 24): int
    {
        // Calculamos el umbral de tiempo
        $threshold = time() - ($maxAgeHours * 3600);
        $pruned    = 0;

        // Iteramos sobre todos los archivos
        foreach ($this->filesystem->allFiles() as $relative) {
            // Saltamos archivos de hash
            if (str_ends_with($relative, '.sha256')) {
                continue;
            }

            $absolute = $this->absoluteFromRelative($relative);

            try {
                // Si el archivo es más antiguo que el umbral, lo eliminamos
                if ($this->filesystem->lastModified($relative) < $threshold) {
                    $this->delete($absolute);
                    ++$pruned;
                }
            } catch (\Throwable $e) {
                // Si falla, registramos advertencia
                logger()->warning('quarantine.prune_failed', [
                    'path'  => $relative,
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

        // Iteramos sobre todos los archivos
        foreach ($this->filesystem->allFiles() as $relative) {
            // Solo procesamos archivos .sha256
            if (! str_ends_with($relative, '.sha256')) {
                continue;
            }

            // Obtenemos la ruta del archivo original
            $original = substr($relative, 0, -7);

            // Si el archivo original no existe, eliminamos el hash
            if (! $this->filesystem->exists($original)) {
                try {
                    $this->filesystem->delete($relative);
                    ++$cleaned;
                } catch (\Throwable $e) {
                    // Si falla la eliminación, registramos advertencia
                    logger()->warning('quarantine.sidecar_cleanup_failed', [
                        'path'  => $relative,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $cleaned;
    }

    /**
     * Redacta rutas absolutas para logging.
     *
     * - Si la ruta pertenece a cuarentena → se usa la relativa.
     * - Si no → sólo se muestra el basename.
     *
     * @param string $path Ruta a redactar
     * @return string Ruta redactada
     */
    private function redactPath(string $path): string
    {
        // Convertimos a ruta relativa
        $relative = $this->toRelativePath($path);

        if ($relative !== null) {
            // Si está dentro de cuarentena, usamos la ruta relativa
            return $relative;
        }

        // Si no está dentro, solo mostramos el nombre base
        $basename = basename($path);

        return $basename !== '' ? $basename : '[outside-quarantine]';
    }

    /**
     * Persiste un stream en disco aplicando el límite de tamaño configurado.
     *
     * @param resource $stream Recurso a copiar.
     * @param string $absolute Ruta absoluta donde copiar el stream.
     * @return int Bytes persistidos.
     */
    private function persistStreamToDisk($stream, string $absolute): int
    {
        $handle = fopen($absolute, 'xb');
        if ($handle === false) {
            throw new RuntimeException(__('media.uploads.quarantine_persist_failed'));
        }

        $bytesCopied = 0;
        $chunkSize = 1_048_576; // 1MB
        $emptyReads = 0;
        $maxEmptyReads = 1024;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, $chunkSize);
                if ($chunk === false) {
                    throw new RuntimeException(__('media.uploads.quarantine_persist_failed'));
                }

                if ($chunk === '') {
                    $emptyReads++;
                    $meta = stream_get_meta_data($stream);
                    if (! empty($meta['timed_out']) || $emptyReads >= $maxEmptyReads) {
                        throw new RuntimeException(__('media.uploads.quarantine_persist_failed'));
                    }

                    usleep(10_000);
                    continue;
                }

                $emptyReads = 0;

                $bytesCopied += strlen($chunk);
                if ($bytesCopied > $this->maxBytes) {
                    throw new RuntimeException(__('media.uploads.quarantine_max_size', ['bytes' => $this->maxBytes]));
                }

                $written = fwrite($handle, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new RuntimeException(__('media.uploads.quarantine_persist_failed'));
                }
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($absolute);
            throw $exception;
        }

        fclose($handle);

        if ($bytesCopied === 0) {
            @unlink($absolute);
            throw new RuntimeException(__('media.uploads.quarantine_empty_content'));
        }

        @chmod($absolute, 0600);

        return $bytesCopied;
    }
}
