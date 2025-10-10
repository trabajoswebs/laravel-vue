<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\OptimizerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Job: PostProcessAvatarMedia
 *
 * Optimiza el original y/o conversions de un Media (colección avatar) una vez generadas por
 * Spatie Media Library. Endurecido con:
 *
 * - Espera no bloqueante (release) hasta que las conversions estén listas.
 * - Circuit breaker por tiempo real transcurrido (presupuesto de espera finito).
 * - Anti-solapamiento (WithoutOverlapping) + unicidad (ShouldBeUnique) — evita duplicados.
 * - Reintentos automáticos en fallos transitorios (DB/FS/Red).
 * - Consistencia eventual (S3): si los archivos “no aparecen”, reintenta con delay corto.
 * - Telemetría completa: tags, contexto, correlationId, métricas de duración y memoria pico.
 *
 * Config recomendada de worker (ejemplo):
 *  php artisan queue:work --queue=image-optimization --timeout=300 --tries=5
 *
 * @author Dev Team
 * @version 1.0
 */
final class PostProcessAvatarMedia implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var string Cola dedicada para optimización de imágenes.
     */
    public string $queue = 'image-optimization';

    /**
     * @var int Timeout por intento (segundos).
     */
    public int $timeout = 300;

    /**
     * @var int Número máximo de reintentos.
     */
    public int $tries = 5;

    /**
     * @var array<int, int> Backoff exponencial (segundos).
     */
    public array $backoff = [15, 30, 60, 120, 300];

    /**
     * @var int Tiempo (segundos) durante el cual el job permanece único.
     */
    public int $uniqueFor = 600; // 10 minutos; ajusta según tu flujo

    /**
     * @var string Colección por defecto si no se especifica.
     */
    private const DEFAULT_COLLECTION = 'avatar';

    /**
     * @var array<int, string> Conversiones por defecto si no se especifican.
     */
    private const DEFAULT_CONVERSIONS = ['thumb', 'medium', 'large'];

    /**
     * @var int ID del Media.
     */
    public int $mediaId;

    /**
     * @var array<int, string> Conversions solicitadas.
     */
    public array $conversions;

    /**
     * @var string Colección esperada.
     */
    public string $collection;

    /**
     * @var int Presupuesto total de espera (segundos).
     */
    public int $maxWaitSeconds;

    /**
     * @var int Intervalo entre reintentos por conversions pendientes.
     */
    public int $checkIntervalSeconds;

    /**
     * @var int Marca temporal del primer intento (circuit breaker).
     */
    public int $firstSeenAtEpoch;

    /**
     * @var string|null Correlation ID para trazabilidad end-to-end.
     */
    public ?string $correlationId;

    // Añadir contador de releases
    private int $releaseCount = 0;
    private const MAX_RELEASES = 50;


    /**
     * Constructor.
     *
     * @param int $mediaId ID del modelo Media.
     * @param array<int, string> $conversions Si vacío, se toman de la config (image-pipeline.avatar_sizes keys).
     * @param string $collection Si vacío, se usa 'avatar'.
     * @param int $maxWaitSeconds Presupuesto total de espera (seg).
     * @param int $checkIntervalSeconds Delay entre cheques (seg).
     * @param int|null $firstSeenAtEpoch Marca de tiempo del primer intento.
     * @param string|null $correlationId Correlation ID de trazabilidad (opcional).
     */
    public function __construct(
        int $mediaId,
        array $conversions = [],
        string $collection = '',
        int $maxWaitSeconds = 60,
        int $checkIntervalSeconds = 5,
        ?int $firstSeenAtEpoch = null,
        ?string $correlationId = null,
    ) {
        $this->onQueue($this->queue);

        $this->mediaId = (int) $mediaId;
        $this->conversions = $this->normalizeConversions(
            !empty($conversions) ? $conversions : $this->defaultConversionsFromConfig()
        );
        $this->collection = trim($collection) !== '' ? $collection : self::DEFAULT_COLLECTION;
        [$this->maxWaitSeconds, $this->checkIntervalSeconds] = $this->normalizeTiming(
            $maxWaitSeconds,
            $checkIntervalSeconds
        );
        $this->firstSeenAtEpoch = $firstSeenAtEpoch ?? time();
        $this->correlationId = $correlationId;
    }

    /**
     * Middleware: evita solapamiento por mediaId.
     *
     * @return array<WithoutOverlapping> Lista de middlewares aplicados.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("postprocess-media-{$this->mediaId}"))->expireAfter(300),
        ];
    }

    /**
     * Define la clave única para evitar duplicados del job.
     * Evita duplicados incluso si el listener dispara varios en ráfaga o falla la caché.
     *
     * @return string Clave única del job.
     */
    public function uniqueId(): string
    {
        return "media:{$this->mediaId}|collection:{$this->collection}";
    }

    /**
     * Define tags para Horizon y otras herramientas de observabilidad.
     *
     * @return array<int, string> Lista de tags asociados al job.
     */
    public function tags(): array
    {
        return [
            'job:postprocess-avatar',
            "media:{$this->mediaId}",
            "collection:{$this->collection}",
            $this->correlationId ? "corr:{$this->correlationId}" : 'corr:none',
        ];
    }

    /**
     * Manejador principal del job.
     *
     * @param OptimizerService $optimizer Servicio de optimización (streaming S3 + in-place local).
     * @return void
     */
    public function handle(OptimizerService $optimizer): void
    {
        $startedAt = microtime(true);

        $media = $this->loadMedia();        // (1) DB: lanza si es transitorio
        if (!$media instanceof Media) {
            return; // not found → no reintentar
        }

        if ($this->shouldSkipByCollection($media)) {
            return;
        }

        if ($this->hasExceededWaitBudget()) {
            Log::warning('ppam_circuit_breaker_activated', $this->context([
                'media_id'      => $media->id,
                'attempts'      => $this->attempts(),
                'first_seen_at' => $this->firstSeenAtEpoch,
                'elapsed_sec'   => time() - $this->firstSeenAtEpoch,
                'max_wait_sec'  => $this->maxWaitSeconds,
            ]));
            return;
        }

        // (2) conversions pendientes → release no bloqueante
        if (!$this->conversionsReady($media)) {
            if ($this->releaseCount >= self::MAX_RELEASES) {
                Log::error('ppam_max_releases_exceeded', $this->context([
                    'releases' => $this->releaseCount,
                ]));
                return;
            }

            $this->releaseCount++;
            $this->release($this->checkIntervalSeconds);
            return;
        }

        // (3) Validación de originales/conversions
        $fileStatus = $this->validatePhysicalFilesStatus($media);
        if ($fileStatus === 'retry') {
            // Consistencia eventual S3 o FS retrasado → reintentar corto mientras quede budget.
            $this->release(min(15, $this->checkIntervalSeconds));
            return;
        }
        if ($fileStatus === 'fail') {
            // Fallo permanente (ej: realmente no existen) → no reintentar
            return;
        }

        // (4) Ejecutar optimización con manejo de permanentes vs transitorios
        $stats = $this->runOptimization($optimizer, $media);

        Log::info('ppam_completed', $this->context([
            'media_id'       => $media->id,
            'stats'          => $stats,
            'duration_ms'    => (int) ((microtime(true) - $startedAt) * 1000),
            'attempts'       => $this->attempts(),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]));
    }

    /**
     * Hook: fallo permanente tras agotar reintentos.
     *
     * @param Throwable $exception La excepción que causó el fallo.
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('ppam_job_failed_permanently', $this->context([
            'media_id'        => $this->mediaId,
            'collection'      => $this->collection,
            'attempts'        => $this->attempts(),
            'error'           => $exception->getMessage(),
            'exception_class' => get_class($exception),
        ]));
    }

    /**
     * Carga el modelo Media con manejo de errores.
     * - Not Found → devuelve null (no reintenta).
     * - Transitorio (DB/Conexión) → lanza para backoff/retry.
     *
     * @return Media|null Instancia de Media si se encuentra, null en caso contrario.
     *
     * @throws Throwable en caso de error transitorio (reintenta).
     */
    private function loadMedia(): ?Media
    {
        try {
            $media = Media::query()->find($this->mediaId);
            if (!$media instanceof Media) {
                Log::info('ppam_media_not_found', $this->context([
                    'media_id' => $this->mediaId,
                ]));
            }
            return $media;
        } catch (Throwable $e) {
            if ($this->isTransient($e)) {
                // Reintentar según backoff
                throw $e;
            }
            Log::warning('ppam_media_lookup_failed', $this->context([
                'media_id' => $this->mediaId,
                'error'    => $e->getMessage(),
            ]));
            return null;
        }
    }

    /**
     * Verifica si las conversions solicitadas están listas y filtra las no definidas.
     * - Si hay pending → false (para release corto).
     * - Si no hay conversions válidas → true (optimizar solo original).
     *
     * @param Media $media Modelo de medio a verificar.
     * @return bool true si todas las conversiones válidas están listas o no se solicitaron conversiones.
     */
    private function conversionsReady(Media $media): bool
    {
        if (empty($this->conversions)) {
            return true;
        }

        if (!$this->refreshMedia($media)) {
            // Un refresh fallido puede ser transitorio → reintentar (release en handle)
            return false;
        }

        $registered = $this->registeredConversions($media);
        $this->conversions = $this->constrainToRegisteredConversions($registered);

        if (empty($this->conversions)) {
            return true; // no hay conversions válidas → optimizar solo original
        }

        $pending = Collection::make($this->conversions)
            ->filter(static fn(string $name): bool => !$media->hasGeneratedConversion($name))
            ->values()
            ->all();

        if (!empty($pending)) {
            Log::debug('ppam_conversions_pending', $this->context([
                'media_id' => $media->id,
                'pending'  => $pending,
                'total'    => count($this->conversions),
                'delay'    => $this->checkIntervalSeconds,
                'attempts' => $this->attempts(),
            ]));
            return false;
        }

        return true;
    }

    /**
     * Determina estado de validación de archivos físicos.
     * Estados:
     * - 'ok'    → existen original y (si aplica) al menos una conversión válida.
     * - 'retry' → posible consistencia eventual (S3/FS); conviene release corto si queda budget.
     * - 'fail'  → fallo permanente (no reintentar).
     *
     * @param Media $media Modelo de medio a validar.
     * @return 'ok'|'retry'|'fail'
     *
     * @throws Throwable si el error se clasifica como transitorio (para backoff/retry).
     */
    private function validatePhysicalFilesStatus(Media $media): string
    {
        try {
            $disk = Storage::disk($media->disk);
            $isLocal = $this->isLocalDisk($media->disk);

            // Validar que el disco es seguro
            if (!in_array($disk, self::getAllowedDisks(), true)) {
                return 'fail';
            }

            if ($isLocal) {
                $path = $media->getPath();

                // Prevenir directory traversal
                $basePath = config("filesystems.disks.{$media->disk}.root");
                if (!str_starts_with(realpath($path) ?: '', realpath($basePath))) {
                    Log::critical('ppam_path_traversal_attempt', $this->context([
                        'path' => $path,
                        'base' => $basePath,
                    ]));
                    return 'fail';
                }

                if (!is_file($path) || !is_readable($path)) {
                    Log::warning('ppam_original_missing_local', $this->context([
                        'media_id' => $media->id,
                        'path' => $path,
                    ]));
                    return 'fail';
                }
            }

            // Conversions (si hay)
            if (empty($this->conversions)) {
                return 'ok';
            }

            // ¿Hay al menos una conversión válida físicamente?
            $hasValid = Collection::make($this->conversions)
                ->filter(fn(string $name): bool => $media->hasGeneratedConversion($name))
                ->contains(function (string $name) use ($media, $disk, $isLocal): bool {
                    try {
                        if ($isLocal) {
                            $p = $media->getPath($name);
                            return $p && is_file($p) && is_readable($p);
                        }
                        $p = $media->getPathRelativeToRoot($name);
                        return $p && $disk->exists($p);
                    } catch (Throwable) {
                        return false;
                    }
                });

            if ($hasValid) {
                return 'ok';
            }

            Log::warning('ppam_no_valid_conversions_exist', $this->context([
                'media_id'    => $media->id,
                'conversions' => $this->conversions,
            ]));

            // En remoto suele ser eventual; en local, generalmente permanente.
            return $isLocal
                ? 'fail'
                : ($this->hasExceededWaitBudget() ? 'fail' : 'retry');
        } catch (Throwable $e) {
            if ($this->isTransient($e)) {
                // Reintentar con backoff
                throw $e;
            }
            Log::error('ppam_file_validation_failed', $this->context([
                'media_id' => $media->id,
                'error'    => $e->getMessage(),
            ]));
            return 'fail';
        }
    }

    /**
     * Determina si un disco es local (local/public) o remoto (S3, etc.).
     *
     * @param string $diskName Nombre del disco definido en config/filesystems.php.
     * @return bool true si el disco es local, false si es remoto o desconocido.
     */
    private function isLocalDisk(string $diskName): bool
    {
        $driver = config("filesystems.disks.{$diskName}.driver");
        if (!$driver) {
            Log::warning('ppam_unknown_disk_driver', $this->context([
                'media_id' => $this->mediaId,
                'disk'     => $diskName,
            ]));
            // Conservador: tratar como remoto
            return false;
        }
        return in_array($driver, ['local', 'public'], true);
    }

    /**
     * Ejecuta la optimización del medio y sus conversions.
     * - RuntimeException → tratada como error permanente (no reintentar).
     * - Otros Throwable transitorios → se relanzan para backoff.
     *
     * @param OptimizerService $optimizer Servicio de optimización.
     * @param Media $media Modelo de medio a optimizar.
     * @return array<string, mixed> Estadísticas de la optimización (ej. bytes ahorrados, archivos procesados).
     *
     * @throws Throwable en caso de error transitorio (reintenta).
     */
    private function runOptimization(OptimizerService $optimizer, Media $media): array
    {
        try {
            return $optimizer->optimize($media, $this->conversions);
        } catch (RuntimeException $e) {
            // Permanente: validación estricta/formato no soportado
            Log::error('ppam_permanent_optimization_error', $this->context([
                'media_id' => $media->id,
                'error'    => $e->getMessage(),
            ]));
            return [];
        } catch (Throwable $e) {
            // Recuperable: red/timeout/Flysystem/…
            Log::error('ppam_optimization_failed', $this->context([
                'media_id' => $media->id,
                'attempts' => $this->attempts(),
                'error'    => $e->getMessage(),
            ]));
            throw $e;
        }
    }

    /**
     * Circuit breaker: verifica si se excedió el presupuesto de espera.
     *
     * @return bool true si se ha superado el tiempo máximo permitido + margen de gracia.
     */
    private function hasExceededWaitBudget(): bool
    {
        $elapsed = time() - $this->firstSeenAtEpoch;
        $grace = max(2 * $this->checkIntervalSeconds, 5);
        return $elapsed > ($this->maxWaitSeconds + $grace);
    }

    /**
     * Despacho helper (recibe opcionalmente correlationId).
     *
     * @param Media|int $media Instancia de Media o su ID.
     * @param array<int, string> $conversions Lista de conversiones a optimizar.
     * @param string $collection Nombre de la colección esperada.
     * @param string|null $correlationId Correlation ID para trazabilidad entre listener y job.
     * @return void
     */
    public static function dispatchFor(
        Media|int $media,
        array $conversions = [],
        string $collection = '',
        ?string $correlationId = null
    ): void {
        $id = $media instanceof Media ? $media->id : (int) $media;

        dispatch(new self(
            mediaId: $id,
            conversions: $conversions,
            collection: $collection,
            maxWaitSeconds: 60,
            checkIntervalSeconds: 5,
            firstSeenAtEpoch: null,
            correlationId: $correlationId
        ));
    }

    /**
     * Obtiene las conversiones por defecto desde la configuración o usa las internas.
     * - Usa las keys de image-pipeline.avatar_sizes si existe, p. ej. ['thumb','medium','large'].
     *
     * @return array<int, string> Lista de conversiones por defecto.
     */
    private function defaultConversionsFromConfig(): array
    {
        $sizes = config('image-pipeline.avatar_sizes');
        if (is_array($sizes) && !empty($sizes)) {
            return array_keys($sizes);
        }
        return self::DEFAULT_CONVERSIONS;
    }

    /**
     * Normaliza lista de conversions: trim, filtra vacíos, elimina duplicados.
     *
     * @param array<int, string> $conversions Lista cruda de conversiones.
     * @return array<int, string> Lista normalizada y saneada.
     */
    private function normalizeConversions(array $conversions): array
    {
        return Collection::make($conversions)
            ->map(static fn($v): ?string => is_string($v) ? trim($v) : null)
            ->filter(static fn($v): bool => is_string($v) && $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Sanea parámetros de tiempo.
     *
     * @param int $maxWaitSeconds Tiempo máximo de espera bruto.
     * @param int $checkIntervalSeconds Intervalo de reintentos bruto.
     * @return array{0: int, 1: int} Tupla con [maxWaitSeconds, checkIntervalSeconds] saneados.
     */
    private function normalizeTiming(int $maxWaitSeconds, int $checkIntervalSeconds): array
    {
        $normalizedMaxWait = max(1, (int) $maxWaitSeconds);
        $normalizedInterval = max(1, (int) $checkIntervalSeconds);
        return [$normalizedMaxWait, $normalizedInterval];
    }

    /**
     * Determina si el job debe omitirse por colección incorrecta.
     *
     * @param Media $media Modelo de medio a verificar.
     * @return bool true si la colección no coincide y debe omitirse.
     */
    private function shouldSkipByCollection(Media $media): bool
    {
        if ((string) $media->collection_name === (string) $this->collection) {
            return false;
        }

        Log::debug('ppam_skip_wrong_collection', $this->context([
            'media_id' => $media->id,
            'expected' => $this->collection,
            'actual'   => $media->collection_name,
        ]));

        return true;
    }

    /**
     * Refresca el modelo desde DB.
     * - Si lanza y es transitorio → handle reintentará (backoff).
     *
     * @param Media $media Modelo a refrescar.
     * @return bool true si el refresco fue exitoso, false en caso de error.
     */
    private function refreshMedia(Media $media): bool
    {
        try {
            $media->refresh();
            return true;
        } catch (Throwable $e) {
            if ($this->isTransient($e)) {
                // Devolvemos false para que el manejador haga release corto;
                // también podríamos relanzar, pero un release suele ser más amigable.
                Log::debug('ppam_media_refresh_transient', $this->context([
                    'media_id' => $this->mediaId,
                    'error'    => $e->getMessage(),
                ]));
            } else {
                Log::debug('ppam_media_refresh_failed', $this->context([
                    'media_id' => $this->mediaId,
                    'error'    => $e->getMessage(),
                ]));
            }
            return false;
        }
    }


    /**
     * Obtiene lista de conversions registradas/generadas.
     *
     * @param Media $media Modelo de medio.
     * @return array<int, string> Lista de nombres de conversiones registradas.
     */
    private function registeredConversions(Media $media): array
    {
        try {
            $generated = (array) ($media->generated_conversions ?? []);
            return array_keys($generated);
        } catch (Throwable $e) {
            Log::debug('ppam_cannot_read_generated_conversions', $this->context([
                'media_id' => $media->id,
                'error'    => $e->getMessage(),
            ]));
            return [];
        }
    }

    /**
     * Filtra conversions solicitadas para incluir solo las registradas.
     * Loguea diferencias para observabilidad.
     *
     * @param array<int, string> $registered Lista de conversiones registradas.
     * @return array<int, string> Lista filtrada de conversiones válidas.
     */
    private function constrainToRegisteredConversions(array $registered): array
    {
        if (empty($registered) || empty($this->conversions)) {
            return $this->conversions;
        }

        $undefined = array_diff($this->conversions, $registered);
        if (!empty($undefined)) {
            Log::warning('ppam_undefined_conversions_detected', $this->context([
                'media_id'   => $this->mediaId,
                'undefined'  => array_values($undefined),
                'registered' => $registered,
            ]));
        }

        $filtered = array_values(array_intersect($this->conversions, $registered));
        if (count($filtered) !== count($this->conversions)) {
            Log::info('ppam_filtered_conversions', $this->context([
                'media_id' => $this->mediaId,
                'before'   => count($this->conversions),
                'after'    => count($filtered),
                'final'    => $filtered,
            ]));
        }

        return $filtered;
    }

    /**
     * Clasifica si una excepción es potencialmente transitoria (reintentable).
     *
     * @param Throwable $e Excepción a evaluar.
     * @return bool true si se considera transitoria.
     */
    private function isTransient(Throwable $e): bool
    {
        // Ajusta esta heurística a tus drivers/infra:
        return $e instanceof \PDOException
            || $e instanceof \Illuminate\Database\QueryException
            || $e instanceof \League\Flysystem\FilesystemException
            || str_contains($e->getMessage(), 'Timed out')
            || str_contains($e->getMessage(), 'Connection refused')
            || str_contains($e->getMessage(), 'Too many requests')
            || str_contains($e->getMessage(), 'Rate exceeded');
    }


    /**
     * Combina y sanea el contexto proporcionado sin permitir que
     * el consumidor sobrescriba campos reservados de trazabilidad.
     *
     * - Sanea recursivamente arrays/strings.
     * - En strings que son URL: parsea query y enmascara claves sensibles.
     * - En headers/tokens: enmascara valores (p.ej., "Bearer abcdef..." → "Bearer ***def").
     * - Protege campos reservados para que no puedan ser pisados.
     *
     * @param array $context Contexto adicional opcional para mezclar
     * @return array Contexto combinado y saneado
     */
    private function context(array $context = []): array
    {
        // Claves sensibles que deben enmascararse si aparecen como nombres de campo
        $sensitiveKeys = [
            'signature',
            'token',
            'key',
            'api_key',
            'access_key',
            'secret',
            'password',
            'authorization',
            'auth',
            'x-api-key',
            'x-auth-token',
        ];

        // Claves internas que NO se pueden sobrescribir desde $context
        $reservedKeys = [
            'media_id',
            'collection',
            'queue',
            'job',
            'correlation_id',
        ];

        // 1) Saneamos profundamente el contexto de entrada
        $sanitizedInput = $this->deepSanitize($context, $sensitiveKeys);

        // 2) Evitamos que el input pise claves reservadas
        foreach ($reservedKeys as $k) {
            unset($sanitizedInput[$k]); // Ej.: si el caller pasa ['job' => 'otraCosa'] se ignora
        }

        // 3) Construimos los defaults internos de trazabilidad
        $defaults = [
            'media_id'       => $this->mediaId,       // Ej.: 12345
            'collection'     => $this->collection,    // Ej.: "avatar"
            'queue'          => $this->queue,         // Ej.: "images"
            'job'            => static::class,        // Ej.: "App\Jobs\PostProcessAvatar"
            'correlation_id' => $this->correlationId, // Ej.: "c38f...-id"
        ];

        // 4) Mezclamos: defaults ganan frente al input (no se pueden pisar)
        // Nota: array_merge($sanitizedInput, $defaults) → $defaults tiene prioridad.
        return array_merge($sanitizedInput, $defaults);
    }

    /**
     * Sanea un valor de forma recursiva.
     * - Si es array: sanea por clave/valor (y enmascara si la clave es sensible).
     * - Si es string:
     *     * Si parece URL (http/https): parsea y enmascara query sensible.
     *     * Si parece header Authorization Bearer: enmascara el token.
     *     * Si contiene "...password=xxx" en texto plano: aplica redacción defensiva.
     * - Otros tipos: se devuelven tal cual.
     *
     * @param mixed $value
     * @param string[] $sensitiveKeys
     * @param string|null $currentKey La clave padre (para decidir si enmascarar por nombre)
     * @return mixed
     */
    private function deepSanitize(mixed $value, array $sensitiveKeys, ?string $currentKey = null): mixed
    {
        // Si la clave actual es sensible, enmascara todo el valor (sea lo que sea)
        if ($currentKey !== null && $this->isSensitiveKey($currentKey, $sensitiveKeys)) {
            return $this->maskValue($value); // Ej.: "my-secret-token" → "***oken"
        }

        // Arrays: saneo recursivo por clave/valor
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->deepSanitize($v, $sensitiveKeys, is_string($k) ? $k : null);
            }
            return $out;
        }

        // Strings: tratamos casos especiales (URL, Bearer, patrones comunes)
        if (is_string($value)) {
            // Caso URL http/https
            if (preg_match('#^https?://#i', $value)) {
                return $this->sanitizeUrlQuery($value, $sensitiveKeys); // Enmascara firma/token/key en la query
            }

            // Caso Authorization: Bearer <token>
            if (preg_match('/^Bearer\s+(.+)$/i', trim($value), $m)) {
                return 'Bearer ' . $this->maskValue($m[1]); // Ej.: "Bearer abcdef..." → "Bearer ***ef"
            }

            // Patrones simples "password=..." o "token=..." embebidos en texto plano
            $pattern = '/(?<=^|[\s;&])(' . implode('|', array_map('preg_quote', $sensitiveKeys)) . ')=([^\s;&]+)/i';
            if (preg_match($pattern, $value)) {
                return preg_replace($pattern, '$1=***', $value);
            }

            return $value;
        }

        // Objetos/recursos/numéricos/bools/null → tal cual (no forzamos serialización aquí)
        return $value;
    }

    /**
     * Determina si una clave es sensible (case-insensitive, guiones o subrayados).
     */
    private function isSensitiveKey(string $key, array $sensitiveKeys): bool
    {
        $norm = strtolower(str_replace(['-', '_'], '', $key)); // "X-Api-Key" → "xapikey"
        foreach ($sensitiveKeys as $s) {
            $sNorm = strtolower(str_replace(['-', '_'], '', $s));
            if ($norm === $sNorm) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enmascara un valor preservando un final mínimo para depurar.
     * - Strings: deja los últimos 2–4 caracteres (según longitud), el resto '***'.
     * - Arrays/objetos: devuelve '***'.
     * - Otros: devuelve tal cual.
     *
     * @param mixed $value
     * @return mixed
     */
    private function maskValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return is_array($value) || is_object($value) ? '***' : $value;
        }

        $len = mb_strlen($value);
        if ($len <= 4) {
            return '***';
        }
        $tail = mb_substr($value, -min(4, max(2, (int)floor($len * 0.15)))); // Ej.: "abcdef123" → deja "f123"
        return '***' . $tail;
    }

    /**
     * Enmascara parámetros sensibles de la query en una URL preservando el resto.
     * - Usa parse_url/parse_str/http_build_query para evitar romper codificaciones.
     *
     * @param string $url
     * @param string[] $sensitiveKeys
     * @return string
     */
    private function sanitizeUrlQuery(string $url, array $sensitiveKeys): string
    {
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return $url; // No hay query, nada que hacer
        }

        // Parsear query a array (maneja a[]=1&a[]=2 correctamente)
        $query = [];
        parse_str($parts['query'], $query);

        // Redactar claves sensibles (recursivo por si hay sub-arrays)
        $query = $this->deepSanitize($query, $sensitiveKeys);

        // Reconstruir query
        $parts['query'] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        // Reconstrucción manual segura
        $scheme   = $parts['scheme'] ?? 'http';
        $host     = $parts['host']   ?? '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user     = $parts['user'] ?? null;
        $pass     = isset($parts['pass']) ? ':' . $parts['pass']  : '';
        $auth     = $user ? $user . $pass . '@' : '';
        $path     = $parts['path']  ?? '';
        $queryStr = $parts['query'] ? '?' . $parts['query'] : '';
        $frag     = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return "{$scheme}://{$auth}{$host}{$port}{$path}{$queryStr}{$frag}";
    }


    /**
     * Obtiene la lista de discos permitidos leyendo las configuraciones de filesystems.
     *
     * @return array Lista de nombres de discos disponibles
     */
    private static function getAllowedDisks(): array
    {
        return array_keys(config('filesystems.disks', []));
    }
}
