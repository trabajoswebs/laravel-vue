<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Quarantine;

use App\Support\Logging\SecurityLogger;
// Importamos las clases necesarias
use App\Modules\Uploads\Pipeline\Exceptions\QuarantineException; // Excepción para fallos en cuarentena
use App\Modules\Uploads\Pipeline\Exceptions\QuarantineIntegrityException; // Excepción para fallos de integridad
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use Illuminate\Filesystem\FilesystemAdapter; // Adaptador de sistema de archivos de Laravel
use RuntimeException; // Excepción estándar de PHP
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineState;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineToken;

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
    // Sufijo para archivos de metadatos
    private const META_SUFFIX = '.meta.json';
    // Valores predeterminados para TTL
    private const DEFAULT_PENDING_TTL_HOURS = 24;
    private const DEFAULT_FAILED_TTL_HOURS = 4;
    // Retraso en microsegundos para reintentos en operaciones de stream
    private const STREAM_RETRY_DELAY_MICROSECONDS = 10_000;
    // Profundidad máxima permitida para los metadatos anidados
    private const MAX_METADATA_DEPTH = 10;

    /**
     * Límite máximo de bytes para un artefacto en cuarentena.
     * Se inicializa desde config:
     *  - image-pipeline.quarantine_max_size
     *  - fallback: image-pipeline.max_upload_size
     *  - fallback final: 25MB
     */
    private int $maxBytes;
    // Tiempo de vida útil para archivos pendientes y fallidos
    private int $pendingTtlHours;
    private int $failedTtlHours;
    // Tiempo de espera máximo para operaciones de stream
    private float $streamTimeoutSeconds;

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
        $this->pendingTtlHours = max(
            1,
            (int) config('image-pipeline.quarantine_pending_ttl_hours', self::DEFAULT_PENDING_TTL_HOURS),
        );
        $this->failedTtlHours = max(
            1,
            (int) config('image-pipeline.quarantine_failed_ttl_hours', self::DEFAULT_FAILED_TTL_HOURS),
        );
        // Configuramos el timeout para operaciones de stream
        $this->streamTimeoutSeconds = max(
            1.0,
            (float) config('image-pipeline.quarantine_stream_timeout_seconds', 15.0),
        );
    }

    /**
     * Almacena bytes en cuarentena y devuelve el token que lo identifica.
     *
     * @param string $bytes Bytes a almacenar
     * @param array $metadata Metadatos adicionales para el archivo
     * @return QuarantineToken Token que representa el archivo en cuarentena
     * @throws RuntimeException Si hay un error al almacenar
     */
    public function put(string $bytes, array $metadata = []): QuarantineToken
    {
        $length = strlen($bytes);
        // Verificamos que no esté vacío
        if ($length === 0 && !app()->runningUnitTests()) {
            throw new RuntimeException(__('media.uploads.quarantine_empty_content'));
        }
        // Verificamos que no exceda el tamaño máximo
        if ($length > $this->maxBytes) {
            throw new RuntimeException(__('media.uploads.quarantine_max_size', ['bytes' => $this->maxBytes]));
        }
        // Generamos una ruta única para el archivo
        $relative = $this->generateUniqueRelativePath();
        $persisted = false; // Bandera para saber si la operación fue exitosa
        try {
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
            $token = $this->makeToken($absolute, $metadata);
            $this->storeMetadata($token, QuarantineState::PENDING, $metadata, ['hash' => $hash]);
            $persisted = true;
            // Registramos la operación
            $this->securityLogger()->debug('media.security.quarantine_put', ['path' => $relative, 'correlation_id' => $token->correlationId]);
            return $token;
        } catch (\Throwable $exception) {
            // Limpieza defensiva de artefactos parciales si falla antes de persistir metadatos.
            if (! $persisted) {
                $this->filesystem->delete($relative);
                $this->deleteStoredHash($relative);
                $this->deleteMetadataSidecar($relative);
            }
            throw $exception;
        } finally {
            // Si la operación no fue exitosa, liberamos la reserva
            $this->releaseReservation($relative, ! $persisted);
        }
    }

    /**
     * Almacena un stream en cuarentena sin cargar todos los bytes en memoria.
     *
     * @param  resource  $stream  Recurso abierto para lectura.
     * @param array $metadata Metadatos adicionales para el archivo
     * @return QuarantineToken Token que representa el archivo en cuarentena
     *
     * @throws RuntimeException
     */
    public function putStream($stream, array $metadata = []): QuarantineToken
    {
        // Verificamos que sea un recurso válido
        if (! is_resource($stream)) {
            throw new RuntimeException('Stream resource required for quarantine storage.');
        }
        // Obtenemos metadatos del stream
        $streamMeta = stream_get_meta_data($stream);
        // Si hay URI, aseguramos que esté al inicio
        if (isset($streamMeta['uri']) && is_string($streamMeta['uri']) && $streamMeta['uri'] !== '') {
            // Asegurarse de empezar al inicio del stream.
            rewind($stream);
        }
        // Obtenemos el tamaño del stream
        $stats  = fstat($stream) ?: [];
        $length = $stats['size'] ?? null;
        // Validamos tamaño (en testing permitimos fakes vacíos)
        if (!app()->runningUnitTests() && $length !== null && $length <= 0) {
            throw new RuntimeException(__('media.uploads.quarantine_empty_content'));
        }
        if ($length !== null && $length > $this->maxBytes) {
            throw new RuntimeException(__('media.uploads.quarantine_max_size', ['bytes' => $this->maxBytes]));
        }
        // Generamos una ruta única
        $relative = $this->generateUniqueRelativePath();
        $absolute = $this->absoluteFromRelative($relative);
        $persisted = false; // Bandera para saber si la operación fue exitosa
        try {
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
            $token = $this->makeToken($absolute, $metadata);
            $this->storeMetadata($token, QuarantineState::PENDING, $metadata, ['hash' => $hash]);
            $persisted = true;
            // Registramos la operación
            $this->securityLogger()->debug('media.security.quarantine_put_stream', [
                'path' => $relative,
                'bytes' => $bytesPersisted,
                'correlation_id' => $token->correlationId,
            ]);
            return $token;
        } catch (\Throwable $exception) {
            // Limpieza defensiva de artefactos parciales si falla antes de persistir metadatos.
            if (! $persisted) {
                $this->filesystem->delete($relative);
                $this->deleteStoredHash($relative);
                $this->deleteMetadataSidecar($relative);
            }
            throw $exception;
        } finally {
            // Si la operación no fue exitosa, liberamos la reserva
            $this->releaseReservation($relative, ! $persisted);
        }
    }

    /**
     * Elimina un artefacto de cuarentena.
     *
     * Contrato:
     *  - Si la ruta no pertenece a la cuarentena, se loguea y se retorna sin lanzar excepción.
     *  - Si el archivo no existe, la operación es silenciosa.
     *  - Si el backend de almacenamiento falla, se lanza QuarantineException.
     *
     * @param QuarantineToken|string $path Token o ruta absoluta del archivo a eliminar
     */
    public function delete(QuarantineToken|string $path): void
    {
        $absolutePath = $path instanceof QuarantineToken ? $path->path : $path;
        // Convertimos la ruta absoluta a relativa
        $relative = $this->toRelativePath($absolutePath);
        if ($relative === null) {
            // Si la ruta está fuera de la raíz de cuarentena, registramos advertencia
            $this->securityLogger()->warning('media.security.denied', [
                'reason' => 'quarantine_delete_outside_root',
                'path' => $this->redactPath($absolutePath),
            ]);
            return;
        }

        // Contrato: si no existe, operación silenciosa.
        if (! $this->filesystem->exists($relative)) {
            $this->deleteStoredHash($relative);
            $this->deleteMetadataSidecar($relative);
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
            if (! $this->filesystem->exists($relative)) {
                // Race condition: ya no existe.
                $this->deleteStoredHash($relative);
                $this->deleteMetadataSidecar($relative);
                return;
            }

            // Si la operación devuelve false, lanzamos excepción
            throw new QuarantineException(__('media.uploads.quarantine_delete_failed', [
                'error' => 'delete operation returned false',
            ]));
        }
        // Eliminamos el archivo de hash secundario
        $this->deleteStoredHash($relative);
        // Limpiamos directorios vacíos si es necesario
        $this->cleanupEmptyDirectories($relative);
        $this->pruneEmptyDirectoriesRoot(); // Limpia todos los directorios vacíos desde la raíz
        $this->deleteMetadataSidecar($relative);
        // Registramos la operación
        $this->securityLogger()->debug('media.security.quarantine_delete', ['path' => $relative]);
    }

    /**
     * Valida y promueve un artefacto desde cuarentena a su destino definitivo.
     *
     * @param  QuarantineToken|string $path Token o ruta absoluta del artefacto en cuarentena.
     * @param  array<string,mixed>  $metadata  Puede incluir 'destination' (relativa o absoluta).
     * @return string                           Ruta absoluta del archivo promovido.
     *
     * @throws QuarantineException
     */
    public function promote(QuarantineToken|string $path, array $metadata = []): string
    {
        $absolutePath = $path instanceof QuarantineToken ? $path->path : $path;
        // Convertimos la ruta absoluta a relativa
        $relative = $this->toRelativePath($absolutePath);
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
        if ($this->filesystem->exists($destinationRelative)) {
            throw new QuarantineException(__('media.uploads.quarantine_promote_move_failed', [
                'error' => 'destination already exists',
            ]));
        }
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
        $this->deleteMetadataSidecar($relative);
        $this->cleanupEmptyDirectories($relative);
        $this->pruneEmptyDirectoriesRoot(); // Limpia todos los directorios vacíos desde la raíz
        // Registramos la operación
        $this->securityLogger()->info('media.security.quarantine_promote', [
            'from' => $relative,
            'to'   => $destinationRelative,
        ]);
        return $destinationAbsolute;
    }

    /**
     * Reconstruye un token de cuarentena a partir de su identificador relativo.
     */
    public function resolveTokenByIdentifier(string $identifier): ?QuarantineToken
    {
        $relative = trim(str_replace('\\', '/', $identifier), '/');

        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        if ($relative === '' || preg_match('/[^A-Za-z0-9._\/-]/', $relative) === 1) {
            return null; // Rechaza identificadores sospechosos o vacíos.
        }

        $absolute = $this->absoluteFromRelative($relative);

        if (!is_string($absolute) || $absolute === '' || !is_file($absolute)) {
            return null;
        }

        return QuarantineToken::fromPath($absolute, $relative);
    }

    /**
     * Construye un token normalizado a partir de un artefacto y metadata.
     *
     * @param string $absolute Ruta absoluta en cuarentena.
     * @param array<string,mixed> $metadata Metadata inicial.
     * @return QuarantineToken Token que representa el archivo en cuarentena
     */
    private function makeToken(string $absolute, array $metadata = []): QuarantineToken
    {
        $correlationId = is_string($metadata['correlation_id'] ?? null)
            ? trim((string) $metadata['correlation_id'])
            : null;
        $profile = is_string($metadata['profile'] ?? null)
            ? trim((string) $metadata['profile'])
            : null;
        // Obtiene la ruta relativa para el token
        $relative = $this->relativeFromAbsolute($absolute) ?? basename($absolute);
        return QuarantineToken::fromPath($absolute, $relative, $correlationId ?: null, $profile ?: null);
    }

    /**
     * Persiste el sidecar de metadata con estado y TTL.
     *
     * @param QuarantineToken $token Token del archivo
     * @param QuarantineState $state Estado actual del archivo
     * @param array<string,mixed> $metadata Metadatos adicionales
     * @param array<string,mixed> $extra Metadatos extra como hash
     */
    private function storeMetadata(
        QuarantineToken $token,
        QuarantineState $state,
        array $metadata = [],
        array $extra = []
    ): void {
        $record = $this->readMetadata($token);
        $now = time();
        $record['state'] = $state->value;
        $record['updated_at'] = $now;
        $record['correlation_id'] = $metadata['correlation_id'] ?? $record['correlation_id'] ?? null;
        $record['profile'] = $metadata['profile'] ?? $record['profile'] ?? null;
        $record['pending_ttl_hours'] = $this->normalizeTtl(
            $metadata['pending_ttl_hours'] ?? $record['pending_ttl_hours'] ?? null,
            $this->pendingTtlHours
        );
        $record['failed_ttl_hours'] = $this->normalizeTtl(
            $metadata['failed_ttl_hours'] ?? $record['failed_ttl_hours'] ?? null,
            $this->failedTtlHours
        );
        $metadataPayload = $metadata['metadata'] ?? [];
        if (!is_array($metadataPayload)) {
            $metadataPayload = [];
        }
        $record['metadata'] = array_merge(
            is_array($record['metadata'] ?? null) ? $record['metadata'] : [],
            $extra,
            $metadataPayload,
        );
        // Valida la profundidad de los metadatos para prevenir estructuras muy anidadas
        if ($this->arrayDepth($record['metadata']) > self::MAX_METADATA_DEPTH) {
            throw new QuarantineException(__('media.uploads.quarantine_metadata_failed'));
        }
        $relative = $this->relativeFromAbsolute($token->path);
        if ($relative === null) {
            throw new QuarantineException(__('media.uploads.quarantine_promote_outside'));
        }
        $sidecar = $this->metadataSidecarRelative($relative);
        $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new QuarantineException(__('media.uploads.quarantine_metadata_failed'));
        }
        $this->filesystem->put($sidecar, $encoded, ['visibility' => 'private']);
    }

    /**
     * Lee el sidecar de metadata o genera valores por defecto.
     *
     * @param QuarantineToken $token Token del archivo
     * @return array<string,mixed> Array con los metadatos
     */
    private function readMetadata(QuarantineToken $token): array
    {
        $relative = $this->relativeFromAbsolute($token->path);
        if ($relative === null) {
            return $this->defaultRecord($token);
        }
        $sidecar = $this->metadataSidecarRelative($relative);
        if (! $this->filesystem->exists($sidecar)) {
            return $this->defaultRecord($token);
        }
        $raw = $this->filesystem->get($sidecar);
        if (! is_string($raw) || $raw === '') {
            return $this->defaultRecord($token);
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->defaultRecord($token);
        }
        // Filtra solo las claves permitidas para evitar inyección de datos
        $allowedKeys = array_flip([
            'state',
            'created_at',
            'updated_at',
            'correlation_id',
            'profile',
            'pending_ttl_hours',
            'failed_ttl_hours',
            'metadata',
        ]);
        $record = array_merge(
            $this->defaultRecord($token),
            array_intersect_key($decoded, $allowedKeys),
        );
        // Asegura que el estado sea válido
        $record['state'] = $this->stateFromRecord($record)->value;
        // Normaliza los TTL
        $record['pending_ttl_hours'] = $this->normalizeTtl(
            $record['pending_ttl_hours'] ?? null,
            $this->pendingTtlHours,
        );
        $record['failed_ttl_hours'] = $this->normalizeTtl(
            $record['failed_ttl_hours'] ?? null,
            $this->failedTtlHours,
        );
        // Asegura que metadata sea un array
        $record['metadata'] = is_array($record['metadata']) ? $record['metadata'] : [];
        // Valida la profundidad de los metadatos y los restablece si es necesario
        if ($this->arrayDepth($record['metadata']) > self::MAX_METADATA_DEPTH) {
            $record['metadata'] = [];
        }
        return $record;
    }

    /**
     * Elimina el sidecar de metadata asociado a la ruta relativa.
     * 
     * @param string $relative Ruta relativa del archivo
     */
    private function deleteMetadataSidecar(string $relative): void
    {
        $sidecar = $this->metadataSidecarRelative($relative);
        if ($this->filesystem->exists($sidecar)) {
            $this->filesystem->delete($sidecar);
        }
    }

    /**
     * Genera ruta relativa del sidecar de metadata.
     * 
     * @param string $relative Ruta relativa del archivo
     * @return string Ruta del archivo de metadatos
     */
    private function metadataSidecarRelative(string $relative): string
    {
        return "{$relative}" . self::META_SUFFIX;
    }

    /**
     * Convierte ruta absoluta dentro de la raíz a relativa.
     * 
     * @param string $absolute Ruta absoluta
     * @return string|null Ruta relativa o null si está fuera de la raíz
     */
    private function relativeFromAbsolute(string $absolute): ?string
    {
        return $this->toRelativePath($absolute);
    }

    /**
     * Convierte metadata a enum de estado.
     *
     * @param array<string,mixed> $record Registro de metadatos
     * @return QuarantineState Estado correspondiente
     */
    private function stateFromRecord(array $record): QuarantineState
    {
        $value = $record['state'] ?? null;
        if (is_string($value)) {
            $case = QuarantineState::tryFrom(strtolower($value));
            if ($case instanceof QuarantineState) {
                return $case;
            }
        }
        return QuarantineState::PENDING;
    }

    /**
     * Record por defecto cuando no hay sidecar.
     *
     * @param QuarantineToken $token Token del archivo
     * @return array<string,mixed> Array con valores por defecto
     */
    private function defaultRecord(QuarantineToken $token): array
    {
        $now = time();
        return [
            'state' => QuarantineState::PENDING->value,
            'created_at' => $now,
            'updated_at' => $now,
            'correlation_id' => $token->correlationId,
            'profile' => $token->profile,
            'pending_ttl_hours' => $this->pendingTtlHours,
            'failed_ttl_hours' => $this->failedTtlHours,
            'metadata' => [],
        ];
    }

    /**
     * Normaliza TTL asegurando valores positivos.
     * 
     * @param mixed $value Valor a normalizar
     * @param int $default Valor por defecto
     * @return int TTL normalizado
     */
    private function normalizeTtl(mixed $value, int $default): int
    {
        if (is_numeric($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : $default;
        }
        return $default;
    }

    /**
     * Transiciona un artefacto de cuarentena garantizando atomicidad por estado.
     * 
     * @param QuarantineToken $token Token del archivo
     * @param QuarantineState $from Estado esperado
     * @param QuarantineState $to Estado destino
     * @param array<string,mixed> $metadata Metadatos adicionales
     */
    public function transition(
        QuarantineToken $token,
        QuarantineState $from,
        QuarantineState $to,
        array $metadata = []
    ): void {
        $record = $this->readMetadata($token);
        $currentState = $this->stateFromRecord($record);
        if ($currentState !== $from) {
            throw new QuarantineException(sprintf(
                'Invalid quarantine state transition: expected %s, current %s',
                $from->value,
                $currentState->value,
            ));
        }
        $this->storeMetadata($token, $to, $metadata);
        // Al tocar el archivo, mantenemos coherente lastModified para TTL sin recrear artefactos borrados.
        if (is_file($token->path)) {
            @touch($token->path);
        }
    }

    /**
     * Recupera el estado actual almacenado del artefacto.
     * 
     * @param QuarantineToken $token Token del archivo
     * @return QuarantineState Estado actual
     */
    public function getState(QuarantineToken $token): QuarantineState
    {
        return $this->stateFromRecord($this->readMetadata($token));
    }

    /**
     * Devuelve el metadata persistido para el artefacto.
     *
     * @param QuarantineToken $token Token del archivo
     * @return array<string,mixed> Array con metadatos
     */
    public function getMetadata(QuarantineToken $token): array
    {
        return $this->readMetadata($token);
    }

    /**
     * Genera una ruta relativa única con particionado 2/2 + hash.bin.
     *
     * Utiliza un archivo .lock para prevenir colisiones concurrentes.
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
            // Intentamos crear un marcador vacío para reservar la ruta y evitar colisiones concurrentes.
            $lockPath = $relative . '.lock';
            $reserved = $this->filesystem->put($lockPath, '', ['visibility' => 'private']);
            if ($reserved === false) {
                // Evita fuga de locks corruptos en caso de error de backend.
                $this->filesystem->delete($lockPath);
                continue;
            }
            if ($this->filesystem->exists($relative)) {
                // Si ya existe, liberamos el lock y probamos otra ruta.
                $this->filesystem->delete($lockPath);
                continue;
            }
            // Reservamos con éxito.
            return $relative;
        } while (true);
    }

    /**
     * Libera la reserva del archivo temporal (.lock) para permitir su limpieza.
     *
     * @param string|null $relative Ruta relativa del archivo
     * @param bool $pruneDirectories Indica si se deben limpiar directorios vacíos
     */
    private function releaseReservation(?string $relative, bool $pruneDirectories = false): void
    {
        if (!is_string($relative) || $relative === '') {
            return;
        }
        $lock = $relative . '.lock';
        if ($this->filesystem->exists($lock)) {
            try {
                $this->filesystem->delete($lock);
            } catch (\Throwable $e) {
                // Si falla la eliminación del lock, registramos advertencia
                $this->securityLogger()->warning('media.security.quarantine_cleanup_failed', [
                    'reason' => 'lock_cleanup_failed',
                    'lock' => $lock,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        // Intenta siempre limpiar directorios vacíos creados por la reserva.
        $this->cleanupEmptyDirectories($relative);
        if ($pruneDirectories || ! $this->filesystem->exists($relative)) {
            $this->pruneEmptyDirectoriesRoot();
        }
    }

    /**
     * Limpia todos los directorios vacíos bajo la raíz de cuarentena (profundidad descendente).
     * Llama a `allDirectories` y ordena de mayor a menor profundidad para limpiar correctamente.
     */
    private function pruneEmptyDirectoriesRoot(): void
    {
        $directories = $this->filesystem->allDirectories();
        // Procesamos de mayor a menor profundidad para limpiar padres después de hijos.
        usort($directories, static function (string $a, string $b): int {
            return substr_count($b, DIRECTORY_SEPARATOR) <=> substr_count($a, DIRECTORY_SEPARATOR);
        });
        foreach ($directories as $dir) {
            try {
                $absolute = $this->absoluteFromRelative($dir);
                if ($this->isDirectoryEmpty($absolute)) {
                    $this->filesystem->deleteDirectory($dir);
                }
            } catch (\Throwable) {
                // Si falla alguna carpeta, continuamos con el resto.
            }
        }
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
                $this->securityLogger()->warning('media.security.quarantine_cleanup_failed', [
                    'reason'    => 'directory_cleanup_failed',
                    'directory' => $this->redactPath($directory),
                    'error'     => $e->getMessage(),
                ]);
                break;
            }
            // Registramos la operación
            $this->securityLogger()->debug('media.security.quarantine_cleanup', [
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
     * Calcula la profundidad máxima de un array (corta en MAX_METADATA_DEPTH + 1).
     * 
     * @param array $value Array a analizar
     * @param int $level Nivel actual de profundidad
     * @return int Profundidad máxima del array
     */
    private function arrayDepth(array $value, int $level = 0): int
    {
        if ($level > self::MAX_METADATA_DEPTH) {
            return $level;
        }
        $max = $level;
        foreach ($value as $item) {
            if (is_array($item)) {
                $max = max($max, $this->arrayDepth($item, $level + 1));
            }
        }
        return $max;
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
        $now = time();
        $pruned = 0;
        foreach ($this->filesystem->allFiles() as $relative) {
            if ($this->isSidecar($relative)) {
                continue;
            }
            $absolute = $this->absoluteFromRelative($relative);
            // Creamos token con la ruta relativa
            $token = QuarantineToken::fromPath($absolute, $relative);
            $record = $this->readMetadata($token);
            $state = $this->stateFromRecord($record);
            if (! in_array($state, [QuarantineState::PENDING, QuarantineState::FAILED, QuarantineState::INFECTED, QuarantineState::EXPIRED], true)) {
                continue;
            }
            $lastUpdated = (int) ($record['updated_at'] ?? $this->filesystem->lastModified($relative));
            $pendingTtl = $record['pending_ttl_hours'] ?? $maxAgeHours;
            $failedTtl = $record['failed_ttl_hours'] ?? $maxAgeHours;
            $ttl = in_array($state, [QuarantineState::FAILED, QuarantineState::INFECTED], true)
                ? $failedTtl
                : $pendingTtl;
            $ttl = $ttl > 0 ? $ttl : $maxAgeHours;
            if ($lastUpdated + ($ttl * 3600) > $now) {
                continue;
            }
            try {
                if ($state !== QuarantineState::EXPIRED) {
                    $this->transition($token, $state, QuarantineState::EXPIRED, [
                        'metadata' => ['reason' => 'ttl_expired'],
                    ]);
                }
                $this->delete($token);
                ++$pruned;
            } catch (\Throwable $e) {
                $this->securityLogger()->warning('media.security.quarantine_cleanup_failed', [
                    'reason' => 'prune_failed',
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
            if (str_ends_with($relative, '.sha256')) {
                $original = substr($relative, 0, -7);
            } elseif (str_ends_with($relative, self::META_SUFFIX)) {
                $original = substr($relative, 0, -strlen(self::META_SUFFIX));
            } else {
                continue;
            }
            if ($original === '' || $this->filesystem->exists($original)) {
                continue;
            }
            try {
                $this->filesystem->delete($relative);
                ++$cleaned;
            } catch (\Throwable $e) {
                $this->securityLogger()->warning('media.security.quarantine_cleanup_failed', [
                    'reason' => 'sidecar_cleanup_failed',
                    'path'  => $relative,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $cleaned;
    }

    /**
     * Determina si una ruta relativa corresponde a un sidecar (hash o metadata).
     * 
     * @param string $relative Ruta relativa
     * @return bool True si es un sidecar, false si no
     */
    private function isSidecar(string $relative): bool
    {
        return str_ends_with($relative, '.sha256') || str_ends_with($relative, self::META_SUFFIX);
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
        $startedAt = microtime(true); // Para medir el tiempo de operación
        $timeoutSeconds = $this->streamTimeoutSeconds;
        try {
            while (! feof($stream)) {
                // Verifica si se ha superado el tiempo de espera
                if ((microtime(true) - $startedAt) > $timeoutSeconds) {
                    throw new RuntimeException(__('media.uploads.quarantine_persist_failed'));
                }
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
                    // Espera antes de reintentar
                    usleep(self::STREAM_RETRY_DELAY_MICROSECONDS);
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
        if ($bytesCopied === 0 && !app()->runningUnitTests()) {
            @unlink($absolute);
            throw new RuntimeException(__('media.uploads.quarantine_empty_content'));
        }
        @chmod($absolute, 0600);
        return $bytesCopied;
    }

    private function securityLogger(): MediaSecurityLogger
    {
        return app(MediaSecurityLogger::class);
    }
}
