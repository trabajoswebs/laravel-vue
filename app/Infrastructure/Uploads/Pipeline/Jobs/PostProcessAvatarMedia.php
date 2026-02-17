<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Jobs;

use App\Support\Contracts\ClockInterface; // Reloj desacoplado; ej. now()
use App\Support\Contracts\LoggerInterface; // Logger desacoplado; ej. info/warning
use App\Application\User\Jobs\Enums\ConversionReadyState; // Enum de estado de conversions; ej. READY
use App\Models\Tenant; // Modelo Tenant para makeCurrent; ej. Tenant #3
use App\Infrastructure\Uploads\Pipeline\Optimizer\OptimizerService; // Servicio de optimización de imágenes; ej. optimize media
use App\Models\User; // Modelo User propietario
use Illuminate\Bus\Queueable; // Trait de colas; ej. onQueue('media')
use Illuminate\Cache\RedisStore; // Cache Redis para contadores; ej. release count
use Illuminate\Contracts\Queue\ShouldBeUnique; // Evita duplicados; ej. uniqueId por media
use Illuminate\Contracts\Queue\ShouldQueue; // Marca job para cola; ej. procesar en worker
use Illuminate\Filesystem\FilesystemAdapter; // FS adapter; ej. Storage::disk('public')
use Illuminate\Foundation\Bus\Dispatchable; // Permite dispatch estático; ej. ::dispatch()
use Illuminate\Queue\InteractsWithQueue; // Acceso a release/delete; ej. release(5)
use Illuminate\Queue\Middleware\WithoutOverlapping; // Middleware de no solapar; ej. lock por mediaId
use Illuminate\Queue\SerializesModels; // Serializa modelos en payload; ej. Media
use Illuminate\Support\Arr; // Helpers de arrays; ej. Arr::get
use Illuminate\Support\Facades\Cache; // Cache facade; ej. contador de releases
use Illuminate\Support\Facades\Storage; // Storage facade; ej. path media
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Modelo Media
use Throwable; // Errores genéricos
use Carbon\Carbon; // Fecha inmutable para comparaciones; ej. Carbon::now()
use Carbon\CarbonInterface; // Interface para formatear fechas; ej. toIso8601String()

/**
 * PostProcessAvatarMedia
 *
 * Job de post-procesado de avatar tras generar conversiones con Spatie Media Library.
 *
 * Este job se encarga de optimizar imágenes (original y conversiones) después de que
 * Spatie Media Library haya terminado de generar las conversiones.
 *
 * ✨ Claves:
 * - Espera no bloqueante hasta que las conversiones estén listas (release con intervalo).
 * - Circuit breaker por tiempo total y nº máx de releases (persistido en Cache).
 * - Anti-duplicación: ShouldBeUnique + WithoutOverlapping por mediaId.
 * - Seguridad: validación de disco, guard de paths locales, sin path traversal.
 * - Telemetría: tags, correlationId, logs con contexto.
 */
final class PostProcessAvatarMedia implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const MAX_RELEASES_DEFAULT     = 50;   // Reintentos por release
    private const UNIQUE_TTL_SECONDS       = 600;  // TTL lock unicidad
    private const OVERLAP_LOCK_EXPIRE_SEC  = 600;  // TTL lock overlapping
    private const RELEASECOUNT_TTL_MIN     = 30;   // TTL contador releases

    /**
     * Define un máximo de intentos coherente con el número de releases permitido.
     * Necesario porque el worker se está lanzando con --tries=1; sin esto, el
     * segundo intento tras un release fallaría inmediatamente con MaxAttemptsExceeded.
     */
    public int $tries = self::MAX_RELEASES_DEFAULT + 2; // margen extra para finalizar tras el último release

    private readonly array $normalizedConversions;
    private ?\DateTimeInterface $mediaBaselineUpdatedAt = null;
    public $deleteWhenMissingModels = true; // Limpia jobs con Media inexistente

    public readonly int $firstSeenAtEpoch;

    public function __construct(
        public readonly int $mediaId,                   // ID del media; ej. 42
        public int|string|null $tenantId = null,         // Tenant asociado; ej. 3
        public readonly array $conversions = [],         // Conversions objetivo; ej. ['thumb']
        public readonly string $collection = 'avatar',   // Colección; ej. avatar
        public readonly int $maxWaitSeconds = 60,        // Timeout total; ej. 60s
        public readonly int $checkIntervalSeconds = 5,   // Intervalo de recheck; ej. 5s
        ?int $firstSeenAtEpoch = null,                   // Marca de tiempo; ej. time()
        public readonly ?string $correlationId = null    // Correlación; ej. uuid
    ) {
        $this->onQueue(config('queue.aliases.media', 'media')); // Selecciona cola de medios; ej. media
        $this->afterCommit(); // Ejecuta tras commit para coherencia; ej. evita leer media sin guardar
        $now = time(); // Captura timestamp actual; ej. 1715000000
        $this->normalizedConversions = $this->normalizeConversionList($conversions); // Normaliza lista de conversions; ej. ['thumb']
        $this->firstSeenAtEpoch = $this->sanitizeFirstSeenEpoch($firstSeenAtEpoch, $now); // Limpia epoch; ej. dentro de 1h
    }

    /**
     * Helper estático para despachar el job desde listeners/spies.
     *
     * @param Media $media El modelo Media a procesar.
     * @param array $conversions Nombres de conversiones a optimizar (e.g., ['thumb']).
     * @param string $collection Nombre de la colección.
     * @param string|null $correlationId ID para correlacionar logs.
     */
    public static function dispatchFor(
        Media $media, // Media de origen; ej. Media #5
        int|string $tenantId, // Tenant asociado; ej. 3
        array $conversions, // Conversions; ej. ['thumb']
        string $collection, // Colección; ej. avatar
        ?string $correlationId = null // Correlación; ej. uuid
    ): void {
        self::dispatch( // Despacha el job con payload tenant-aware; ej. queue media
            mediaId: (int) $media->getKey(), // Pasa mediaId; ej. 5
            tenantId: $tenantId, // Pasa tenantId; ej. 3
            conversions: $conversions, // Pasa conversions; ej. ['thumb']
            collection: $collection, // Pasa colección; ej. avatar
            correlationId: $correlationId // Pasa correlación; ej. req-123
        );
    }

    // ───── Unicidad y antisolapamiento ──────────────────────────────────────────

    /**
     * Identificador único para evitar duplicados de este job.
     *
     * @return string Clave única para el lock.
     */
    public function uniqueId(): string
    {
        return 'ppam:' . $this->mediaId . ':' . $this->collection; // Ej.: "ppam:42:avatar"
    }

    /**
     * Tiempo de vida del lock de unicidad.
     *
     * @return int TTL en segundos.
     */
    public function uniqueFor(): int
    {
        return self::UNIQUE_TTL_SECONDS; // 10 min
    }

    /**
     * Middleware para evitar solapamiento de ejecuciones concurrentes.
     *
     * @return array Middleware aplicado al job.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ppam-lock:' . $this->mediaId))
                ->expireAfter(self::OVERLAP_LOCK_EXPIRE_SEC),
        ];
    }

    // ───── Observabilidad ───────────────────────────────────────────────────────

    /**
     * Loguea estados "esperables" (avatar borrado/reemplazado durante el post-proceso).
     * Evita contaminar logs con WARNING cuando el usuario está probando la app.
     *
     * Niveles soportados: none|debug|info|warning
     * Config: media.ppam_expected_log_level (env: PPAM_EXPECTED_LOG_LEVEL)
     */
    private function logExpected(string $event, array $extra = []): void
    {
        $level = (string) config('media.ppam_expected_log_level', 'debug');
        if ($level === 'none') {
            return;
        }

        $ctx = $this->context($extra);
        $logger = $this->logger();

        match ($level) {
            'debug'   => $logger->debug($event, $ctx),
            'info'    => $logger->info($event, $ctx),
            'warning' => $logger->warning($event, $ctx),
            default   => $logger->debug($event, $ctx),
        };
    }

    /**
     * Razones "esperables" cuando el usuario sube/borrra/reemplaza el avatar rápido.
     */
    private function isExpectedStaleReason(string $reason): bool
    {
        return in_array($reason, [
            'media_missing',
            'superseded',
            'source_missing',
            'user_missing',
        ], true);
    }

    /**
     * Etiquetas para identificar el job en el monitor de colas.
     *
     * @return array Lista de tags.
     */
    public function tags(): array
    {
        return [
            'job:postprocess-avatar',
            'media:' . $this->mediaId,
            'collection:' . $this->collection,
            $this->correlationId ? 'corr:' . $this->correlationId : 'corr:none',
        ];
    }

    /**
     * Intervalos de espera entre reintentos exponenciales.
     *
     * @return array<int> Segundos de espera por intento.
     */
    public function backoff(): array
    {
        return [5, 10, 20, 40, 60]; // segundos
    }

    // ───── Lógica principal ─────────────────────────────────────────────────────

    /**
     * Procesa la optimización de imágenes (original y conversiones) del Media.
     *
     * @param OptimizerService $optimizer Servicio de optimización de imágenes.
     */
    public function handle(OptimizerService $optimizer): void
    {
        if (!isset($optimizer)) {
            $this->logger()->critical('ppam_optimizer_not_available', $this->context());
            throw new \RuntimeException('OptimizerService not available');
        }

        $startedAt = microtime(true);

        $media = $this->findTargetMedia(); // Busca el media en DB; ej. Media #5
        if ($media === null) { // Si no existe, aborta
            return; // Evita procesar sin media
        }

        $owner = User::query()->find($media->model_id);
        if ($owner === null) {
            $this->staleSkip('user_missing', ['media_id' => $media->getKey()]);
            return;
        }

        $resolvedTenantId = $this->tenantId ?? $media->getCustomProperty('tenant_id'); // Resuelve tenant desde payload o custom_props; ej. 3
        if (!$this->makeTenantCurrent($resolvedTenantId)) { // Si no puede fijar tenant, aborta
            return; // Evita fuga cross-tenant
        }
        $this->tenantId = $resolvedTenantId; // Guarda tenantId resuelto; ej. 3

        if (!$this->ensureExpectedCollection($media)) {
            return;
        }

        if (!$this->isCurrentForModel($media)) {
            $this->staleSkip('superseded', ['media_id' => $media->getKey()]);
            return;
        }

        $this->mediaBaselineUpdatedAt =
            $media->updated_at instanceof \DateTimeInterface
            ? Carbon::instance($media->updated_at)->toImmutable()
            : null;

        $rawDiskName = (string) $media->disk;
        $diskName = $this->sanitizeDiskName($rawDiskName);
        if ($diskName === null) {
            $this->logger()->error('ppam_disk_name_invalid', $this->context(['disk' => $rawDiskName]));
            return;
        }

        $disk = $this->resolveMediaDisk($diskName);
        if ($disk === null) {
            return;
        }

        $firstSeen = $this->firstSeenAtEpoch ?? time();
        if ($this->hasTimedOut($firstSeen)) {
            return;
        }

        if (!$this->ensureConversionsReady($media, $disk)) {
            return;
        }

        $relative = $media->getPathRelativeToRoot();
        if (is_string($relative) && $relative !== '' && !$disk->exists($relative)) {
            $this->staleSkip('source_missing', ['media_id' => $media->getKey(), 'disk' => $media->disk, 'path' => $relative]);
            return;
        }

        if (!$this->ensureMediaStillCurrent($media)) {
            return;
        }

        try {
            $this->optimizeOriginalMedia($optimizer, $media, $diskName);
            $this->optimizeConversions($optimizer, $media, $diskName);

            $this->logger()->info('ppam_completed', $this->context([
                'duration_ms'   => (int) ((microtime(true) - $startedAt) * 1000),
                'release_count' => $this->getReleaseCount(),
                'conversions'   => $this->conversions,
            ]));
        } catch (Throwable $e) {
            if ($this->isTransient($e)) {
                $this->logger()->warning('ppam_transient_error', $this->context(['error' => $e->getMessage()]));
                throw $e;
            }

            $context = $this->context([
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            if (config('app.debug', false)) {
                $context['trace'] = substr($e->getTraceAsString(), 0, 2000);
            }

            $this->logger()->error('ppam_permanent_error', $context);
        } finally {
            $this->resetReleaseCount();
        }
    }

    /**
     * Carga el media objetivo o null si no existe.
     *
     * @return Media|null El modelo Media o null si no se encuentra.
     */
    private function findTargetMedia(): ?Media
    {
        $media = Media::query()->find($this->mediaId);

        if ($media === null) {
            // Esperable si el usuario borró/reemplazó el avatar antes de que corra el worker.
            $this->logExpected('ppam_expected_missing', [
                'reason'  => 'media_missing',
                'mediaId' => $this->mediaId,
            ]);
        }

        return $media;
    }

    /**
     * Verifica que el media siga siendo el vigente para su modelo/colección.
     *
     * Si el usuario ya reemplazó/eliminó este media (single-file), se evita
     * seguir procesando conversions u optimización sobre archivos obsoletos.
     */
    private function isCurrentForModel(Media $media): bool
    {
        $model = $media->model;
        if ($model === null) {
            $this->logger()->info('ppam_skip_no_model', $this->context());
            return false;
        }

        if (!method_exists($model, 'getFirstMedia')) {
            return true; // No podemos verificar; asumir vigente.
        }

        $current = $model->getFirstMedia($media->collection_name);
        if ($current === null || $current->getKey() === $media->getKey()) {
            return true;
        }

        $this->logger()->info('ppam_skip_replaced_media', $this->context([
            'current_media_id' => (string) $current->getKey(),
        ]));

        return false;
    }

    /**
     * Verifica que el media pertenezca a la colección esperada.
     */
    private function ensureExpectedCollection(Media $media): bool
    {
        if ($media->collection_name === $this->collection) {
            return true;
        }

        $this->logger()->info('ppam_skip_wrong_collection', $this->context([
            'expected' => $this->collection,
            'actual'   => (string) $media->collection_name,
        ]));

        return false;
    }

    /**
     * Obtiene el adapter de filesystem válido para el media.
     */
    private function resolveMediaDisk(string $diskName): ?FilesystemAdapter
    {
        if (!$this->isAllowedDisk($diskName)) {
            $this->logger()->error('ppam_invalid_disk', $this->context([
                'disk'    => $diskName,
                'allowed' => $this->allowedDisks(),
            ]));
            return null;
        }

        $disk = Storage::disk($diskName);
        if (!$disk instanceof FilesystemAdapter) {
            $this->logger()->error('ppam_unexpected_fs_adapter', $this->context([
                'disk' => $diskName,
                'type' => \is_object($disk) ? $disk::class : gettype($disk),
            ]));
            return null;
        }

        return $disk;
    }

    /**
     * Circuit breaker de espera total.
     */
    private function hasTimedOut(int $firstSeen): bool
    {
        $elapsed = time() - $firstSeen;

        if ($elapsed <= $this->maxWaitSeconds) {
            return false;
        }

        $this->logger()->error('ppam_timeout_total', $this->context([
            'elapsed' => $elapsed,
            'limit'   => $this->maxWaitSeconds,
        ]));

        return true;
    }

    /**
     * Garantiza que las conversiones estén listas (o programa un release).
     */
    private function ensureConversionsReady(Media $media, FilesystemAdapter $disk): bool
    {
        $readyState = $this->conversionsReady($media, $disk, $this->normalizedConversions);

        if (
            $readyState === ConversionReadyState::Transient
            || $readyState === ConversionReadyState::Pending
        ) {
            $releases = $this->incrementReleaseCount();
            if ($releases > $this->maxReleases()) {
                $this->logger()->error('ppam_max_releases_exceeded', $this->context(['releases' => $releases]));
                return false;
            }

            $this->release($this->checkIntervalSeconds);
            return false;
        }

        if ($readyState !== ConversionReadyState::Ready) {
            $this->logger()->warning('ppam_unknown_ready_state', $this->context(['state' => $readyState]));
            return false;
        }

        return true;
    }

    private function ensureMediaStillCurrent(Media $media): bool
    {
        $baseline = $this->mediaBaselineUpdatedAt;
        if ($baseline === null) {
            return true;
        }

        $latest = Media::query()->find($media->getKey());
        if ($latest === null) {
            $this->logExpected('ppam_expected_missing', [
                'reason' => 'media_missing_expected',
            ]);
            return false;
        }

        if (!$this->isCurrentForModel($latest)) {
            $this->logExpected('ppam_expected_missing', [
                'reason'           => 'stale_avatar',
                'current_media_id' => (string) ($latest->model?->getFirstMedia($latest->collection_name)?->getKey() ?? 'none'),
            ]);
            return false;
        }

        $currentUpdatedAt = $latest->updated_at instanceof \DateTimeInterface
            ? Carbon::instance($latest->updated_at)->toImmutable()
            : null;

        if ($currentUpdatedAt === null || $currentUpdatedAt->greaterThan($baseline)) {
            $baselineIso = $baseline instanceof CarbonInterface ? $baseline->toIso8601String() : $baseline?->format(DATE_ATOM);
            $currentIso = $currentUpdatedAt instanceof CarbonInterface ? $currentUpdatedAt->toIso8601String() : $currentUpdatedAt?->format(DATE_ATOM);

            $this->logger()->info('ppam_media_replaced_during_postprocess', $this->context([
                'baseline' => $baselineIso,
                'current'  => $currentIso,
            ]));
            return false;
        }

        return true;
    }

    /**
     * Optimiza el archivo original del media si corresponde.
     */
    private function optimizeOriginalMedia(OptimizerService $optimizer, Media $media, string $diskName): void
    {
        $originalPath = $this->resolveLocalPathIfAny($media);
        if ($originalPath && \is_file($originalPath) && \is_readable($originalPath)) {
            $this->guardLocalPath($originalPath, $diskName);
        }

        $optimizer->optimize($media);
    }

    /**
     * Optimiza las conversiones generadas del media.
     */
    private function optimizeConversions(OptimizerService $optimizer, Media $media, string $diskName): void
    {
        foreach ($this->normalizedConversions as $name) {
            if (!$media->hasGeneratedConversion($name)) {
                continue;
            }

            $conversionPath = $this->resolveLocalPathIfAny($media, $name);
            if ($conversionPath) {
                if (!\is_file($conversionPath) || !\is_readable($conversionPath)) {
                    continue;
                }

                $this->guardLocalPath($conversionPath, $diskName);
            }

            $optimizer->optimize($media, [$name]);
        }
    }

    // ───── Helpers de readiness / seguridad / disco ─────────────────────────────

    /**
     * Verifica si las conversiones están listas para ser procesadas.
     */
    private function conversionsReady(Media $media, FilesystemAdapter $disk, array $conversions): ConversionReadyState
    {
        try {
            $media->refresh();
        } catch (Throwable $e) {
            $this->logger()->debug('ppam_refresh_failed', $this->context(['error' => $e->getMessage()]));
            return ConversionReadyState::Transient;
        }

        if ($conversions === []) {
            return ConversionReadyState::Ready; // Solo optimización de original
        }

        foreach ($conversions as $name) {
            if (!$media->hasGeneratedConversion($name)) {
                return ConversionReadyState::Pending;
            }

            $localPath = $this->resolveLocalPathIfAny($media, $name);
            if ($localPath) {
                if (!is_file($localPath) || !is_readable($localPath)) {
                    return ConversionReadyState::Pending;
                }
            } else {
                $relative = $media->getPathRelativeToRoot($name);
                if (!$relative || !$disk->exists($relative)) {
                    return ConversionReadyState::Pending;
                }
            }
        }

        return ConversionReadyState::Ready;
    }

    /**
     * Verifica que una ruta absoluta esté dentro del root del disco para evitar path traversal.
     */
    private function guardLocalPath(string $absolutePath, string $diskName): void
    {
        $safeDiskName = $this->sanitizeDiskName($diskName);
        if ($safeDiskName === null) {
            $this->failSecurity('ppam_disk_name_invalid', ['disk' => $diskName]);
        }

        $diskRoot = (string) Arr::get(config("filesystems.disks.{$safeDiskName}"), 'root', '');
        if ($diskRoot === '') {
            return; // No local root (cloud): no aplica realpath guard
        }

        $realPath = @realpath($absolutePath);
        $realRoot = @realpath($diskRoot);

        if (!$realPath || !$realRoot) {
            $this->failSecurity('ppam_path_resolution_failed', [
                'path' => $absolutePath,
                'root' => $diskRoot,
                'realPath' => $realPath,
                'realRoot' => $realRoot,
            ]);
        }

        if (!is_file($realPath) || is_link($absolutePath)) {
            $this->failSecurity('ppam_path_symlink_detected', [
                'path' => $absolutePath,
                'realPath' => $realPath,
            ]);
        }

        $normalizedRoot = rtrim($realRoot, DIRECTORY_SEPARATOR);
        if ($realPath === $normalizedRoot) {
            return;
        }

        if (!str_starts_with($realPath, $normalizedRoot . DIRECTORY_SEPARATOR)) {
            $this->failSecurity('ppam_path_outside_root', [
                'path' => $absolutePath,
                'root' => $normalizedRoot,
            ]);
        }

        $relative = substr($realPath, strlen($normalizedRoot));
        if ($relative !== false && (str_contains($relative, '..') || str_contains($relative, './'))) {
            $this->failSecurity('ppam_path_segment_invalid', [
                'path' => $absolutePath,
            ]);
        }
    }

    private function failSecurity(string $tag, array $context = []): void
    {
        $this->logger()->critical($tag, $this->context($context));
        throw new RuntimeException("Security guard failed: {$tag}");
    }

    private function staleSkip(string $reason, array $context = []): void
    {
        $payload = array_merge(['reason' => $reason], $context);

        // Razones esperables → log configurable (por defecto debug)
        if ($this->isExpectedStaleReason($reason)) {
            $this->logExpected('job.stale_skipped', $payload);
            return;
        }

        $this->logger()->info('job.stale_skipped', $this->context($payload));
    }

    private function sanitizeDiskName(string $diskName): ?string
    {
        $trimmed = trim($diskName);
        if ($trimmed === '') {
            return null;
        }

        $sanitized = preg_replace('/[^a-z0-9_-]/i', '', $trimmed) ?? '';
        return $sanitized === '' ? null : $sanitized;
    }

    /**
     * Resuelve la ruta absoluta del archivo si el disco es local.
     */
    private function resolveLocalPathIfAny(Media $media, ?string $conversionName = null): ?string
    {
        try {
            return $conversionName ? $media->getPath($conversionName) : $media->getPath();
        } catch (Throwable $e) {
            $this->logger()->warning('ppam_path_resolution_error', $this->context([
                'conversion' => $conversionName,
                'error' => $e->getMessage(),
            ]));

            return null; // En cloud drivers puede lanzar o no estar disponible
        }
    }

    /**
     * Obtiene la lista de discos permitidos para este job.
     */
    private function allowedDisks(): array
    {
        $cfg = config('media.allowed_disks');
        if (is_array($cfg) && $cfg !== []) {
            return array_values(array_map('strval', $cfg));
        }
        return array_keys((array) config('filesystems.disks', []));
    }

    /**
     * Verifica si un disco es permitido.
     */
    private function isAllowedDisk(string $diskName): bool
    {
        return in_array($diskName, $this->allowedDisks(), true);
    }

    /**
     * Determina si un error es transitorio (puede reintentarse).
     */
    private function isTransient(Throwable $e): bool
    {
        $msg = $e->getMessage();
        return $e instanceof \PDOException
            || str_contains($msg, 'Timed out')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'Too many requests')
            || str_contains($msg, 'Connection refused')
            || str_contains($msg, 'Rate limit');
    }

    // ───── Persistencia del releaseCount en Cache ───────────────────────────────

    /**
     * Genera la clave para el contador de releases en cache.
     */
    private function releaseCountKey(): string
    {
        $seed = $this->firstSeenAtEpoch;
        $collection = $this->sanitizeCollectionName();
        return sprintf('ppam:releases:%d:%s:%d', $this->mediaId, $collection, $seed);
    }

    private function sanitizeCollectionName(): string
    {
        $normalized = preg_replace('/[^a-z0-9_-]/i', '', $this->collection);
        return $normalized === '' ? 'avatar' : mb_strtolower($normalized, 'UTF-8');
    }

    private function normalizeConversionList(array $conversions): array
    {
        $filtered = array_filter($conversions, static fn($value) => is_string($value) && $value !== '');
        $mapped = array_map(static fn($value) => (string) $value, $filtered);
        return array_values(array_unique($mapped));
    }

    /**
     * Obtiene el contador de releases actual.
     */
    private function getReleaseCount(): int
    {
        return (int) Cache::get($this->releaseCountKey(), 0);
    }

    /**
     * Incrementa el contador de releases y lo almacena en cache.
     */
    private function incrementReleaseCount(): int
    {
        $key = $this->releaseCountKey();
        $ttlSeconds = self::RELEASECOUNT_TTL_MIN * 60;

        $store = Cache::getStore();
        if ($store instanceof RedisStore) {
            $script = <<<'LUA'
                local cnt = redis.call('INCR', KEYS[1])
                redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
                return {cnt, redis.call('TTL', KEYS[1])}
            LUA;

            try {
                /** @var \Redis|\Predis\ClientInterface $redis */
                $redis = $store->getRedis();
                $result = method_exists($redis, 'eval')
                    ? $redis->eval($script, [$key, $ttlSeconds], 1)
                    : $redis->executeRaw(['EVAL', $script, 1, $key, $ttlSeconds]);

                $cnt = (int) ($result[0] ?? 0);
                $ttl = (int) ($result[1] ?? 0);

                if ($ttl <= 0) {
                    $this->logger()->warning('ppam_release_counter_no_ttl', $this->context([
                        'key' => $key,
                        'ttl' => $ttl,
                    ]));
                }

                return $cnt;
            } catch (Throwable $e) {
                $this->logger()->warning('ppam_release_counter_redis_error', $this->context([
                    'error' => $e->getMessage(),
                ]));
            }
        }

        $cnt = (int) Cache::increment($key);
        Cache::put($key, $cnt, $this->clock()->now()->addMinutes(self::RELEASECOUNT_TTL_MIN));
        return $cnt;
    }

    /**
     * Resetea el contador de releases.
     */
    private function resetReleaseCount(): void
    {
        Cache::forget($this->releaseCountKey());
    }

    /**
     * Obtiene el número máximo de releases permitidos.
     */
    private function maxReleases(): int
    {
        $value = config('media.ppam_max_releases');
        $fallback = config('image-pipeline.ppam_max_releases');
        $resolved = (int) ($value ?? $fallback ?? self::MAX_RELEASES_DEFAULT);
        return $resolved > 0 ? $resolved : self::MAX_RELEASES_DEFAULT;
    }

    private function sanitizeFirstSeenEpoch(?int $value, int $now): int
    {
        if ($value === null) {
            return $now;
        }

        if ($value > $now || $value < ($now - 3600)) {
            return $now;
        }

        return $value;
    }

    // ───── Contexto de logs ─────────────────────────────────────────────────────

    private function logger(): LoggerInterface
    {
        return app(LoggerInterface::class);
    }

    private function clock(): ClockInterface
    {
        return app(ClockInterface::class);
    }

    /**
     * Genera un contexto estructurado para logs.
     *
     * @param array<string,mixed> $extra Datos adicionales a incluir
     * @return array<string,mixed> Contexto estructurado
     */
    private function context(array $extra = []): array
    {
        return array_merge([
            'media_id'   => $this->mediaId,
            'collection' => $this->collection,
            'corr'       => $this->correlationId ?? 'none',
            'queue'      => $this->queue,
            'connection' => $this->connection,
            'tenant_id'  => $this->tenantId ?? 'none',
        ], $extra);
    }

    private function makeTenantCurrent(int|string|null $tenantId): bool
    {
        if ($tenantId === null) {
            $this->logger()->warning('ppam_missing_tenant', $this->context());
            return false;
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            $this->logger()->warning('ppam_tenant_not_found', $this->context(['tenant_id' => $tenantId]));
            return false;
        }

        $tenant->makeCurrent();

        return true;
    }
}
