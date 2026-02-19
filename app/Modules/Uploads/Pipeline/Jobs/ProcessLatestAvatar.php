<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Jobs;

use App\Support\Contracts\ClockInterface;
use App\Support\Contracts\LoggerInterface;
use App\Models\User;
use App\Models\Tenant;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use App\Modules\Uploads\Pipeline\Jobs\CleanupMediaArtifactsJob;
use App\Modules\Uploads\Pipeline\Jobs\CleanupAvatarOrphans;
use App\Modules\Uploads\Pipeline\Support\MediaCleanupArtifactsBuilder;

/**
 * Job coalescedor: procesa únicamente el último avatar subido por usuario/tenant.
 *
 * ⚠️ PROBLEMA QUE RESUELVE:
 * Cuando un usuario sube múltiples avatares en rápida sucesión, se encolan múltiples jobs
 * que intentan procesar archivos que pueden haber sido reemplazados. Esto genera:
 *   - Warnings de "media missing"
 *   - Procesamiento redundante de conversiones
 *   - Condiciones de carrera en la asignación del avatar actual
 *
 * ✅ SOLUCIÓN IMPLEMENTADA:
 * Este job implementa un patrón de COALESCING:
 *   1. Solo el ÚLTIMO avatar subido en una ventana de 5 minutos es procesado
 *   2. Usa Redis como almacén central del estado (último media_id)
 *   3. Lock distribuido para garantizar 1 job activo por (tenant,user)
 *   4. Versionado optimista para reencolar si hay cambios durante ejecución
 *   5. Auto-limpieza de artefactos huérfanos
 *
 * @package App\Modules\Uploads\Pipeline\Jobs
 */
final class ProcessLatestAvatar implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * TTL para la clave del último avatar en Redis.
     * 5 minutos es suficiente para cubrir ráfagas de subidas consecutivas.
     */
    private const LAST_KEY_TTL = 300;

    /**
     * Ventana de coalescing - evita múltiples jobs simultáneos.
     * Durante estos 60 segundos, solo un job puede estar encolado/ejecutándose.
     */
    private const LOCK_TTL = 60;

    /** Nombre de la colección de Spatie Media Library para avatares */
    private const COLLECTION = 'avatar';

    /**
     * @param int|string $tenantId ID del tenant (soporta UUIDs o enteros)
     * @param int|string $userId   ID del usuario
     */
    public function __construct(
        public readonly int|string $tenantId,
        public readonly int|string $userId,
    ) {
        // Usa cola específica para procesos de media (evita bloquear colas críticas)
        $this->onQueue(config('queue.aliases.media', 'media'));
        
        // ⚠️ CRÍTICO: Solo ejecutar después del COMMIT de la transacción
        // Si el job se ejecuta antes del commit, podría encontrar el media aún no persistido
        $this->afterCommit();
    }

    /**
     * ID único para el sistema de "ShouldBeUnique" de Laravel.
     * Garantiza que no hayan dos jobs idénticos en cola simultáneamente.
     */
    public function uniqueId(): string
    {
        return sprintf('avatar-coalesce:%s:%s', $this->tenantId, $this->userId);
    }

    /**
     * Tiempo durante el cual este job se considera único.
     * Debe coincidir con LOCK_TTL para mantener consistencia.
     */
    public function uniqueFor(): int
    {
        return self::LOCK_TTL;
    }

    /**
     * Persiste en Redis el último avatar subido.
     *
     * Este método es invocado inmediatamente después de subir un nuevo avatar,
     * antes de encolar cualquier job. Almacena los metadatos necesarios para que
     * ProcessLatestAvatar pueda identificar cuál es el último media a procesar.
     *
     * @param int|string  $tenantId      Tenant al que pertenece el usuario
     * @param int|string  $userId        Usuario que subió el avatar
     * @param int|string  $mediaId       ID del registro Media creado
     * @param string      $uploadUuid    UUID único de esta subida (trazabilidad)
     * @param string|null $correlationId ID de correlación global (opcional)
     */
    public static function rememberLatest(
        int|string $tenantId,
        int|string $userId,
        int|string $mediaId,
        string $uploadUuid,
        ?string $correlationId = null
    ): void {
        // Payload con toda la información necesaria para procesar
        $payload = json_encode([
            'media_id'       => (string) $mediaId,
            'upload_uuid'    => $uploadUuid,
            'correlation_id' => $correlationId,
            'tenant_id'      => (string) $tenantId,
            'user_id'        => (string) $userId,
            'updated_at'     => app(ClockInterface::class)->now()->toIso8601String(),
        ]);

        try {
            // Almacena el payload con TTL de 5 minutos
            Redis::setex(self::lastKey($tenantId, $userId), self::LAST_KEY_TTL, $payload ?: '');
            
            // Incrementa contador de versión - permite detectar cambios durante procesamiento
            Redis::incr(self::versionKey($tenantId, $userId));
            Redis::expire(self::versionKey($tenantId, $userId), self::LAST_KEY_TTL);
        } catch (\Throwable $e) {
            // 🟡 FALLBACK: Redis no disponible, logueamos pero NO interrumpimos
            // La subida del archivo ya fue exitosa, solo perdemos coalescing
            app(LoggerInterface::class)->info('job.stale_skipped', app(MediaLogSanitizer::class)->safeContext([
                'reason'    => 'redis_unavailable_last',
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
            ]));
        }
    }

    /**
     * Encola el job coalescedor asegurando UNO SOLO por ventana de tiempo.
     *
     * Implementa un lock distribuido atómico en Redis (SET NX EX).
     * Si no puede adquirir el lock, es porque ya hay un job activo → no encola.
     *
     * @param int|string $tenantId
     * @param int|string $userId
     * @return bool True si se encoló el job, False si ya existía uno activo
     */
    public static function enqueueOnce(int|string $tenantId, int|string $userId): bool
    {
        $lockKey = self::lockKey($tenantId, $userId);
        
        try {
            // SET NX EX = Solo si no existe, con expiración
            $acquired = Redis::set($lockKey, '1', 'EX', self::LOCK_TTL, 'NX');
        } catch (\Throwable $e) {
            // 🟡 FALLBACK: Sin Redis, degradamos a dispatch directo (tests o desarrollo)
            app(LoggerInterface::class)->info('job.stale_skipped', app(MediaLogSanitizer::class)->safeContext([
                'reason'    => 'redis_unavailable_lock',
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
            ]));
            self::dispatch($tenantId, $userId);
            return true;
        }

        if ($acquired) {
            self::dispatch($tenantId, $userId);
            return true;
        }

        // No se adquirió el lock → ya hay un job activo
        return false;
    }

    /**
     * EJECUCIÓN PRINCIPAL DEL JOB.
     * 
     * 🔄 FLUJO COMPLETO:
     * 1. Cambia al contexto del tenant (multi-tenant)
     * 2. Lee el último payload desde Redis
     * 3. Valida existencia del usuario, tenant, media
     * 4. Verifica que este media sea el avatar ACTUAL del usuario
     * 5. Si es válido: encola PostProcessAvatarMedia para conversiones
     * 6. Si es inválido/obsoleto: limpia artefactos y reintenta
     * 7. Si hubo cambios durante ejecución: reencola automáticamente
     *
     * @throws \Throwable Si algo falla críticamente (el job será reintentado)
     */
    public function handle(): void
    {
        $iterations      = 0;
        $previousTenant  = function_exists('tenant') ? tenant() : null;
        $startingVersion = $this->readLatestVersion();
        
        // En el bloque finally determinaremos si hubo cambios y debemos reencolar
        $needsRequeueAfterUnlock = false;

        try {
            // 🔄 MÁXIMO 3 ITERACIONES - Prevención de bucles infinitos
            // Si después de 3 intentos aún hay trabajo, el próximo job lo tomará
            while ($iterations < 3) {
                ++$iterations;

                // --- 1. LEER ÚLTIMO PAYLOAD DESDE REDIS ---
                $payload = $this->readLatestPayload();
                if ($payload === null) {
                    return; // No hay nada que procesar, salimos limpiamente
                }

                $mediaId = (int) $payload['media_id'];
                $corr    = $payload['correlation_id'] ?? $payload['upload_uuid'] ?? null;

                // --- 2. VALIDAR USUARIO ---
                /** @var User|null $user */
                $user = User::query()->find($this->userId);
                if ($user === null) {
                    $this->staleSkip('user_missing', $mediaId, $corr);
                    if (!$this->shouldReprocess($mediaId)) {
                        return; // No hay nuevo avatar, terminamos
                    }
                    $this->refreshLockTtl(); // Hay nuevo avatar, extendemos lock
                    continue;
                }

                // --- 3. VALIDAR TENANT Y CAMBIAR CONTEXTO ---
                $tenant = Tenant::query()->find($this->tenantId);
                if ($tenant === null) {
                    $this->staleSkip('tenant_missing', $mediaId, $corr, ['user_id' => $this->userId]);
                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }
                
                // ⚠️ CRÍTICO: Cambiar al contexto del tenant
                // Todas las consultas siguientes (Media, Storage) deben ejecutarse en este contexto
                $tenant->makeCurrent();

                // --- 4. VALIDAR QUE EL MEDIA EXISTA EN BD ---
                $media = Media::query()->find($mediaId);
                if ($media === null) {
                    $this->staleSkip('media_missing', $mediaId, $corr, ['user_id' => $user->getKey()]);
                    $this->dispatchDirectCleanupFromPayload($payload, 'media_missing');

                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }

                // --- 5. VALIDAR COLECCIÓN CORRECTA ---
                if ($media->collection_name !== self::COLLECTION) {
                    $this->staleSkip('wrong_collection', $mediaId, $corr, ['collection' => $media->collection_name]);
                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }

                // --- 6. VALIDAR QUE SEA EL AVATAR ACTUAL ---
                $current = $user->getFirstMedia(self::COLLECTION);
                if ($current === null || $current->getKey() !== $media->getKey()) {
                    $this->staleSkip('superseded', $mediaId, $corr, [
                        'latest_media_id' => $current?->getKey(),
                        'user_id'         => $user->getKey(),
                    ]);
                    
                    // Este media ya fue reemplazado, limpiamos sus artefactos
                    $this->dispatchDirectCleanup($media, 'superseded');
                    CleanupAvatarOrphans::dispatch($this->tenantId, $this->userId);
                    
                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }

                // --- 7. VALIDAR QUE EL ARCHIVO FÍSICO EXISTA ---
                if (!$this->sourceExists($media)) {
                    $this->staleSkip('source_missing', $mediaId, $corr, ['disk' => $media->disk]);
                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }

                // --- 8. 🎯 TODO OK: PROCESAR EL AVATAR ---
                // Este es el único punto donde realmente procesamos el avatar
                PostProcessAvatarMedia::dispatchFor(
                    media:        $media,
                    tenantId:     $this->tenantId,
                    conversions:  $this->conversions(),
                    collection:   self::COLLECTION,
                    correlationId: $corr
                );

                // --- 9. VERIFICAR SI LLEGÓ UN NUEVO AVATAR DURANTE EL PROCESO ---
                if ($this->shouldReprocess($mediaId)) {
                    $this->refreshLockTtl(); // Extendemos lock para la siguiente iteración
                    continue;
                }

                // --- 10. 🟢 PROCESAMIENTO EXITOSO Y ACTUAL ---
                return;
            }
            
            // Si llegamos aquí, superamos las 3 iteraciones sin éxito
            // El próximo job (o el reencolado) continuará el trabajo
        } finally {
            // 🧹 BLOQUE FINALLY: SIEMPRE SE EJECUTA, HAYA ERROR O NO
            
            // --- A. DETECTAR CAMBIOS DURANTE LA EJECUCIÓN ---
            $endingVersion = $this->readLatestVersion();
            $needsRequeueAfterUnlock = $this->hasVersionChanged($startingVersion, $endingVersion);
            
            // --- B. LIBERAR LOCK ATOMICO ---
            $this->releaseLock();
            
            // --- C. RESTAURAR CONTEXTO TENANT ORIGINAL ---
            $this->restoreTenantContext($previousTenant);
            
            // --- D. REENCOLAR SI HUBO CAMBIOS ---
            if ($needsRequeueAfterUnlock) {
                self::enqueueOnce($this->tenantId, $this->userId);
                app(LoggerInterface::class)->info('job.stale_skipped', $this->safeContext([
                    'reason'        => 'requeued_on_version_change',
                    'tenant_id'     => $this->tenantId,
                    'user_id'       => $this->userId,
                    'from_version'  => $startingVersion,
                    'to_version'    => $endingVersion,
                ]));
            }
        }
    }

    /**
     * Lee y decodifica el payload del último avatar desde Redis.
     *
     * @return array|null Array con datos del último avatar o null si:
     *                    - Redis no está disponible
     *                    - No hay clave para este usuario
     *                    - El payload está corrupto (JSON inválido)
     */
    private function readLatestPayload(): ?array
    {
        try {
            $raw = Redis::get(self::lastKey($this->tenantId, $this->userId));
        } catch (\Throwable $e) {
            $this->staleSkip('redis_unavailable_read', 0, null, ['error' => $e->getMessage()]);
            return null;
        }
        
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['media_id'], $decoded['upload_uuid'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * Verifica que el archivo fuente exista físicamente en el disco.
     *
     * @param Media $media
     * @return bool True si:
     *              - El archivo existe
     *              - No se pudo determinar la ruta (asumimos que existe)
     *              False si el archivo definitivamente no existe
     */
    private function sourceExists(Media $media): bool
    {
        $relative = $media->getPathRelativeToRoot();
        if (!is_string($relative) || $relative === '') {
            // No se pudo determinar la ruta, asumimos que existe
            // para evitar falsos positivos
            return true;
        }

        return Storage::disk($media->disk)->exists($relative);
    }

    /**
     * Obtiene la lista de conversiones/configuraciones para el avatar.
     *
     * Las conversiones están definidas en config/image-pipeline.php
     * Formato esperado: ['thumb' => 128, 'medium' => 256, 'large' => 512]
     *
     * @return array Lista de nombres de conversiones a aplicar
     */
    private function conversions(): array
    {
        $sizes = config('image-pipeline.avatar_sizes', [
            'thumb'  => 128,
            'medium' => 256,
            'large'  => 512,
        ]);

        $list = array_values(array_filter(
            array_keys(is_array($sizes) ? $sizes : []),
            static fn($value) => is_string($value) && $value !== ''
        ));

        // Fallback por si la configuración está vacía o mal formada
        return $list === [] ? ['thumb', 'medium', 'large'] : $list;
    }

    /**
     * Registra un caso donde el procesamiento fue omitido por condiciones obsoletas.
     *
     * @param string      $reason   Código de la razón (user_missing, media_missing, etc.)
     * @param int         $mediaId  ID del media involucrado (0 si no aplica)
     * @param string|null $corr     ID de correlación para trazabilidad
     * @param array       $extra    Datos adicionales específicos del contexto
     */
    private function staleSkip(string $reason, int $mediaId, ?string $corr, array $extra = []): void
    {
        $context = array_merge([
            'reason'      => $reason,
            'media_id'    => $mediaId,
            'tenant_id'   => $this->tenantId,
            'user_id'     => $this->userId,
            'correlation' => $corr,
        ], $extra);

        app(LoggerInterface::class)->info('job.stale_skipped', $this->safeContext($context));
    }

    /**
     * Encola un job de limpieza para un Media existente.
     *
     * Utiliza MediaCleanupArtifactsBuilder para identificar todos los archivos
     * asociados al Media (original + conversiones) y los encola para eliminación.
     *
     * @param Media  $media  Media a limpiar
     * @param string $reason Razón por la que se limpia (superseded, media_missing_rehydrated)
     */
    private function dispatchDirectCleanup(Media $media, string $reason): void
    {
        try {
            $artifacts = app(MediaCleanupArtifactsBuilder::class)->forMedia($media);
            if ($artifacts === []) {
                return;
            }

            CleanupMediaArtifactsJob::dispatch($artifacts, []);

            app(LoggerInterface::class)->info('avatar.cleanup.direct_dispatched', $this->safeContext([
                'media_id' => $media->getKey(),
                'reason'   => $reason,
                'disks'    => array_keys($artifacts),
                'tenant_id'=> $this->tenantId,
                'user_id'  => $this->userId,
            ]));
        } catch (\Throwable $e) {
            app(LoggerInterface::class)->warning('avatar.cleanup.direct_failed', $this->safeContext([
                'media_id' => $media->getKey(),
                'reason'   => $reason,
                'error'    => $e->getMessage(),
            ]));
        }
    }

    /**
     * Encola limpieza usando el payload cuando el Media ya no existe en BD.
     *
     * Caso de uso: El registro Media fue eliminado pero aún tenemos su ID en Redis.
     * Intentamos hidratar el Media desde la BD, si no existe, ejecutamos limpieza
     * de huérfanos por usuario/tenant como fallback.
     *
     * @param array|null $payload Payload del último avatar
     * @param string     $reason  Razón de la limpieza
     */
    private function dispatchDirectCleanupFromPayload(?array $payload, string $reason): void
    {
        if ($payload === null) {
            return;
        }

        $media = Media::query()->find($payload['media_id'] ?? null);
        if ($media instanceof Media) {
            // El Media existe en BD, podemos limpiarlo directamente
            $this->dispatchDirectCleanup($media, $reason . '_rehydrated');
            return;
        }

        // El Media no existe en BD, solo podemos loguear y ejecutar limpieza general
        app(LoggerInterface::class)->info('avatar.cleanup.direct_skipped_payload', $this->safeContext([
            'reason'   => $reason,
            'media_id' => $payload['media_id'] ?? null,
            'tenant_id'=> $payload['tenant_id'] ?? null,
            'user_id'  => $payload['user_id'] ?? null,
        ]));

        // 🟡 FALLBACK: Limpieza de huérfanos por usuario/tenant
        CleanupAvatarOrphans::dispatch($this->tenantId, $this->userId);
    }

    /**
     * Genera la clave Redis para el último avatar.
     * Formato: ppam:avatar:last:{tenantId}:{userId}
     */
    private static function lastKey(int|string $tenantId, int|string $userId): string
    {
        return sprintf('ppam:avatar:last:%s:%s', $tenantId, $userId);
    }

    /**
     * Genera la clave Redis para el lock de coalescing.
     * Formato: ppam:avatar:lock:{tenantId}:{userId}
     */
    private static function lockKey(int|string $tenantId, int|string $userId): string
    {
        return sprintf('ppam:avatar:lock:%s:%s', $tenantId, $userId);
    }

    /**
     * Genera la clave Redis para el contador de versión.
     * Formato: ppam:avatar:ver:{tenantId}:{userId}
     */
    private static function versionKey(int|string $tenantId, int|string $userId): string
    {
        return sprintf('ppam:avatar:ver:%s:%s', $tenantId, $userId);
    }

    /**
     * Refresca el TTL del lock para mantenerlo activo durante procesamiento largo.
     * 
     * Útil cuando el job necesita múltiples iteraciones y no queremos que el lock
     * expire antes de terminar. Extiende la vida del lock por LOCK_TTL segundos.
     */
    private function refreshLockTtl(): void
    {
        try {
            Redis::expire(self::lockKey($this->tenantId, $this->userId), self::LOCK_TTL);
        } catch (\Throwable) {
            // 🟡 FALLBACK: Ignoramos errores de refresh
            // El lock expirará solo y será reemplazado por el próximo job
        }
    }

    /**
     * Libera explícitamente el lock de Redis.
     * 
     * Buena práctica: liberar el lock apenas terminamos, en lugar de esperar expiración.
     * Permite que el próximo job comience inmediatamente.
     */
    private function releaseLock(): void
    {
        try {
            Redis::del(self::lockKey($this->tenantId, $this->userId));
        } catch (\Throwable) {
            // 🟡 FALLBACK: Ignoramos errores de liberación
            // El lock expirará solo en LOCK_TTL segundos
        }
    }

    /**
     * Determina si debe reprocesarse porque llegó un nuevo avatar.
     * 
     * Compara el media_id que acabamos de procesar con el último media_id en Redis.
     * Si son diferentes, significa que durante nuestra ejecución alguien subió
     * un nuevo avatar y debemos reprocesar.
     *
     * @param int $mediaIdProcessed ID del media que ya procesamos
     * @return bool True si hay un media más reciente
     */
    private function shouldReprocess(int $mediaIdProcessed): bool
    {
        $latest = $this->readLatestPayload();
        if ($latest === null) {
            return false;
        }

        return (int) $latest['media_id'] !== $mediaIdProcessed;
    }

    /**
     * Lee el número de versión actual desde Redis.
     * 
     * El contador de versión se incrementa cada vez que se sube un nuevo avatar.
     * Permite detectar rápidamente si hubo cambios durante la ejecución.
     *
     * @return int|null Versión actual o null si no existe/error
     */
    private function readLatestVersion(): ?int
    {
        try {
            $raw = Redis::get(self::versionKey($this->tenantId, $this->userId));
        } catch (\Throwable) {
            return null;
        }

        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && trim($raw) !== '' && is_numeric($raw)) {
            return (int) $raw;
        }

        return null;
    }

    /**
     * Compara si la versión cambió durante el procesamiento.
     *
     * @param int|null $startingVersion Versión al inicio del handle()
     * @param int|null $endingVersion   Versión al final del handle()
     * @return bool True si hubo cambio (y por tanto debemos reencolar)
     */
    private function hasVersionChanged(?int $startingVersion, ?int $endingVersion): bool
    {
        // Si ambos son null, no hay versión (primer uso o Redis caído)
        if ($startingVersion === null && $endingVersion === null) {
            return false;
        }

        return $endingVersion !== $startingVersion;
    }

    /**
     * Sanitiza el contexto para logging, eliminando datos sensibles.
     *
     * Delega en MediaLogSanitizer que:
     *   - Ofusca IDs sensibles
     *   - Elimina tokens, passwords
     *   - Recorta campos muy largos
     *
     * @param array<string,mixed> $context Contexto original con posibles datos sensibles
     * @return array<string,mixed> Contexto sanitizado seguro para logs
     */
    private function safeContext(array $context): array
    {
        return app(MediaLogSanitizer::class)->safeContext($context);
    }

    /**
     * Restaura el contexto multi-tenant anterior.
     *
     * Importante: Siempre debemos restaurar el tenant original
     * para no afectar otros jobs que se ejecuten en el mismo proceso.
     *
     * @param Tenant|mixed|null $previousTenant Tenant activo antes del job
     */
    private function restoreTenantContext(mixed $previousTenant): void
    {
        try {
            if ($previousTenant instanceof Tenant) {
                $previousTenant->makeCurrent();
                return;
            }

            // Si no había tenant activo, aseguramos limpiar el contexto
            Tenant::forgetCurrent();
        } catch (\Throwable) {
            // 🟡 BEST-EFFORT: No interrumpimos el job por errores de tenant
            // El siguiente job en el proceso restaurará su propio contexto
        }
    }
}
