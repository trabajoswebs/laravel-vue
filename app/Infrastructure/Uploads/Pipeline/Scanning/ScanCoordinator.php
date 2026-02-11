<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Scanning;

// Importamos las clases necesarias para el coordinador de escaneo
use App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\ClamAvScanner; // Escáner de antivirus ClamAV
use App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\YaraScanner; // Escáner de firmas YARA
use App\Infrastructure\Uploads\Pipeline\Exceptions\ScanFailedException; // Excepción para fallo de escaneo
use App\Infrastructure\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Infrastructure\Uploads\Pipeline\Exceptions\VirusDetectedException; // Excepción para detección de virus
use App\Infrastructure\Security\Exceptions\AntivirusException;
use Illuminate\Http\UploadedFile; // Clase para manejar archivos subidos
use Illuminate\Cache\RedisStore; // Store de Redis para cache
use Illuminate\Contracts\Cache\LockTimeoutException; // Excepción para timeout de lock
use Illuminate\Support\Facades\Cache; // Facade para manejar cache
use App\Support\Logging\SecurityLogger;
// Facade para registrar logs

/**
 * Coordina la ejecución de escáneres y el circuito de disponibilidad.
 */
final class ScanCoordinator implements ScanCoordinatorInterface
{
    /**
     * Configuración de escaneo.
     *
     * @var array<string,mixed>
     */
    private array $scanConfig;

    // Configuración para el circuit breaker de escaneo
    private string $scanCircuitCacheKey; // Clave de cache para el circuit breaker
    private int $scanCircuitMaxFailures; // Número máximo de fallos antes de abrir el circuito
    private int $scanCircuitDecaySeconds; // Tiempo de decaimiento del circuit breaker en segundos
    private int $scanRetryAttempts; // Reintentos para fallos transitorios de AV
    private int $scanRetryBackoffMs; // Backoff base entre reintentos
    private int $scanRetryJitterMs; // Jitter máximo sumado al backoff

    /**
     * Constructor del coordinador de escaneo.
     *
     * @param ClamAvScanner $clamScanner Escáner ClamAV
     * @param YaraScanner|null $yaraScanner Escáner YARA (opcional)
     * @param array|null $scanConfig Configuración de escaneo (usando config si es null)
     */
    public function __construct(
        private readonly ClamAvScanner $clamScanner, // Escáner ClamAV
        private readonly ?YaraScanner $yaraScanner = null, // Escáner YARA (opcional)
        ?array $scanConfig = null, // Configuración de escaneo
    ) {
        // Usamos la configuración proporcionada o cargamos desde config/image-pipeline.php
        $this->scanConfig = $scanConfig ?? (array) config('image-pipeline.scan', []);

        // Configuramos el circuit breaker para fallos de escaneo
        $circuit = (array) ($this->scanConfig['circuit_breaker'] ?? []);
        $this->scanCircuitCacheKey    = (string) ($circuit['cache_key'] ?? 'image_scan:circuit_failures');
        $this->scanCircuitMaxFailures = max(1, (int) ($circuit['max_failures'] ?? 5));
        $this->scanCircuitDecaySeconds = max(60, (int) ($circuit['decay_seconds'] ?? 900));
        $this->scanRetryAttempts = max(1, (int) (($this->scanConfig['retry_attempts'] ?? 1)));
        $this->scanRetryBackoffMs = max(0, (int) ($this->scanConfig['retry_backoff_ms'] ?? 200));
        $this->scanRetryJitterMs = max(0, (int) ($this->scanConfig['retry_jitter_ms'] ?? 100));
    }

    /**
     * Verifica si el escaneo está habilitado.
     *
     * @return bool True si el escaneo está habilitado
     */
    public function enabled(): bool
    {
        $enabled = (bool) ($this->scanConfig['enabled'] ?? false);
        $legacyEnabled = config('uploads.virus_scanning.enabled');

        // Compatibilidad temporal: si el flag legacy está definido en false, deshabilita scanning.
        if (is_bool($legacyEnabled) && $legacyEnabled === false) {
            return false;
        }

        return $enabled;
    }

    /**
     * Ejecuta los escáneres sobre un archivo.
     *
     * @param UploadedFile $file Archivo a escanear
     * @param string $path Ruta del archivo en cuarentena
     * @param array<string,mixed> $context Contexto para logging/trazabilidad.
     * @throws ScanFailedException Si falla el escaneo
     * @throws VirusDetectedException Si se detecta malware
     */
    public function scan(UploadedFile $file, string $path, array $context = []): void
    {
        // Si el escaneo no está habilitado, salimos
        if (!$this->enabled()) {
            return;
        }

        // Verificamos que el escaneo esté disponible (circuito cerrado)
        $this->assertAvailable();
        // Ejecutamos los escáneres
        $this->runScanners($file, $path, $context);
        // Reiniciamos el contador de fallos
        $this->resetScanFailures();

        // Registramos que el escaneo pasó
        SecurityLogger::info('image_upload.scan_passed', array_merge([
            'scanners' => $this->activeScannerKeys(),
        ], $context));
    }

    /**
     * Verifica que el escaneo esté disponible (circuito cerrado).
     *
     * @throws ScanFailedException Si el circuito está abierto
     */
    public function assertAvailable(): void
    {
        // Si el escaneo no está habilitado, salimos
        if (!$this->enabled()) {
            return;
        }

        // Si el circuito está abierto, lanzamos excepción
        if ($this->isCircuitOpen()) {
            SecurityLogger::warning('image_upload.scan_circuit_open', [
                'max_failures' => $this->scanCircuitMaxFailures,
            ]);

            throw new ScanFailedException(__('media.uploads.scan_unavailable'));
        }
    }

    /**
     * Verifica si el circuit breaker está abierto.
     *
     * @return bool True si el circuito está abierto
     */
    private function isCircuitOpen(): bool
    {
        // Obtenemos el número de fallos desde la cache
        $failures = (int) Cache::get($this->scanCircuitCacheKey, 0);

        // Si supera el máximo permitido, el circuito está abierto
        return $failures >= $this->scanCircuitMaxFailures;
    }

    /**
     * Registra un fallo técnico de escaneo en el circuit breaker.
     */
    private function recordScanFailure(): void
    {
        $store = Cache::getStore();

        // Si usamos Redis, podemos usar operaciones atómicas
        if ($store instanceof RedisStore) {
            Cache::increment($this->scanCircuitCacheKey, 1);

            try {
                // Actualizamos el TTL del contador de fallos
                $store->connection()->expire($this->scanCircuitCacheKey, $this->scanCircuitDecaySeconds);
            } catch (\Throwable $exception) {
                SecurityLogger::debug('image_upload.circuit_ttl_refresh_failed', ['error' => $exception->getMessage()]);
            }

            return;
        }

        // Para otros stores, usamos locks para evitar condiciones de carrera
        $lock = Cache::lock("{$this->scanCircuitCacheKey}:lock", 5);

        try {
            // Intentamos obtener el lock y actualizar el contador
            $lock->block(2, function (): void {
                $failures = (int) Cache::get($this->scanCircuitCacheKey, 0) + 1;
                Cache::put($this->scanCircuitCacheKey, $failures, $this->scanCircuitDecaySeconds);
            });
        } catch (LockTimeoutException) {
            // Si falla el lock, actualizamos directamente
            $failures = (int) Cache::get($this->scanCircuitCacheKey, 0) + 1;
            Cache::put($this->scanCircuitCacheKey, $failures, $this->scanCircuitDecaySeconds);
        }
    }

    /**
     * Reinicia el contador de fallos de escaneo (cierra el circuit breaker).
     */
    private function resetScanFailures(): void
    {
        // Eliminamos la clave de cache que contaba los fallos
        Cache::forget($this->scanCircuitCacheKey);
    }

    /**
     * Resuelve las instancias de escáneres activos.
     *
     * @return list<callable(UploadedFile,array<string,mixed>):bool> Escáneres invocables
     */
    private function resolveScanners(): array
    {
        // Obtenemos los handlers configurados
        $handlers = array_map('strval', $this->scanConfig['handlers'] ?? []);

        // Mapeamos los handlers a sus instancias
        $map = [
            ClamAvScanner::class => $this->clamScanner, // Escáner ClamAV
            YaraScanner::class   => $this->yaraScanner, // Escáner YARA
        ];

        $instances = [];
        foreach ($handlers as $handler) {
            // Si el handler está mapeado y no es null, lo añadimos
            if (isset($map[$handler]) && $map[$handler] !== null) {
                $instances[] = $map[$handler];
            }
        }

        return array_values($instances);
    }

    /**
     * Ejecuta los escáneres activos sobre el archivo.
     *
     * @param UploadedFile $file Archivo a escanear
     * @param string $path Ruta del archivo en cuarentena
     * @param array<string,mixed> $context Contexto adicional (p.ej. correlation_id).
     * @throws ScanFailedException Si falla el escaneo
     * @throws VirusDetectedException Si se detecta malware
     */
    private function runScanners(UploadedFile $file, string $path, array $context = []): void
    {
        $scanners = $this->resolveScanners();

        if ($scanners === []) {
            // Si no hay escáneres configurados, es un fallo técnico
            $this->recordScanFailure();
            SecurityLogger::error('image_upload.scan_missing_handlers', $context);
            throw new ScanFailedException(__('media.uploads.scan_unavailable'));
        }

        // Contexto para los escáneres
        /** @var array<string,mixed> $context */
        $context = array_merge([
            'path'           => $path,
            'is_first_chunk' => true,
            'timeout_ms'     => (int) ($this->scanConfig['timeout_ms'] ?? 5000),
        ], $context);

        foreach ($scanners as $scanner) {
            try {
                // Ejecutamos el escáner con reintentos acotados para errores transitorios.
                $clean = $this->scanWithRetries($scanner, $file, $context);
            } catch (AntivirusException $exception) {
                // Fallo crítico de antivirus (fail-closed)
                $this->recordScanFailure();
                $classification = $this->classifyAntivirusFailure($exception);

                SecurityLogger::error('image_upload.scanner_unavailable', array_merge([
                    'scanner' => $this->scannerName($scanner),
                    'reason' => $exception->reason(),
                    'error_type' => $classification['error_type'],
                    'retryable' => $classification['retryable'],
                    'fail_closed' => true,
                ], $context));

                throw new UploadValidationException(__('media.uploads.scan_unavailable'), $exception);
            } catch (\Throwable $exception) {
                // Si el escáner falla técnicamente, registramos el fallo
                $this->recordScanFailure();

                SecurityLogger::error('image_upload.scanner_failure', array_merge([
                    'scanner' => $this->scannerName($scanner),
                    'error_type' => 'infra_unknown',
                    'retryable' => false,
                    'error'   => $exception->getMessage(),
                ], $context));

                throw new ScanFailedException(__('media.uploads.scan_unavailable'));
            }

            // Si el escáner devuelve false, hay malware
            if (!$clean) {
                SecurityLogger::warning('image_upload.scan_blocked', array_merge([
                    'scanner' => $this->scannerName($scanner),
                ], $context));

                throw new VirusDetectedException(__('media.uploads.scan_blocked'));
            }
        }
    }

    /**
     * @param callable(UploadedFile,array<string,mixed>):bool $scanner
     * @param array<string,mixed> $context
     */
    private function scanWithRetries(callable $scanner, UploadedFile $file, array $context): bool
    {
        $attempt = 0;

        while (true) {
            ++$attempt;

            try {
                return (bool) $scanner($file, $context + ['attempt' => $attempt]);
            } catch (AntivirusException $exception) {
                $classification = $this->classifyAntivirusFailure($exception);
                $canRetry = $classification['retryable'] && $attempt < $this->scanRetryAttempts;

                if (! $canRetry) {
                    throw $exception;
                }

                $retryDelayMs = $this->nextRetryDelayMs();
                SecurityLogger::warning('image_upload.scanner_retry', array_merge([
                    'scanner' => $this->scannerName($scanner),
                    'attempt' => $attempt,
                    'max_attempts' => $this->scanRetryAttempts,
                    'reason' => $exception->reason(),
                    'error_type' => $classification['error_type'],
                    'retryable' => true,
                    'retry_delay_ms' => $retryDelayMs,
                ], $context));

                $this->sleepBeforeRetry($retryDelayMs);
            }
        }
    }

    /**
     * Obtiene los nombres de los escáneres activos.
     *
     * @return list<string> Nombres de los escáneres activos
     */
    public function activeScannerKeys(): array
    {
        if (!$this->enabled()) {
            return [];
        }

        return array_map(
            static fn(object $scanner): string => self::scannerName($scanner),
            $this->resolveScanners(),
        );
    }

    /**
     * Obtiene el nombre de la clase del escáner sin namespace.
     *
     * @param object|callable $scanner Instancia del escáner
     * @return string Nombre del escáner en minúsculas
     */
    private static function scannerName(object|callable $scanner): string
    {
        if (! is_object($scanner)) {
            return 'callable_scanner';
        }

        return strtolower(class_basename($scanner));
    }

    /**
     * @return array{error_type:string,retryable:bool}
     */
    private function classifyAntivirusFailure(AntivirusException $exception): array
    {
        $reason = strtolower(trim($exception->reason()));

        if (in_array($reason, ['timeout', 'process_timeout'], true)) {
            return ['error_type' => 'infra_timeout', 'retryable' => true];
        }

        if (in_array($reason, ['unreachable', 'connection_refused'], true)) {
            return ['error_type' => 'infra_unavailable', 'retryable' => true];
        }

        if (in_array($reason, ['ruleset_invalid', 'ruleset_missing', 'rules_integrity_failed', 'rules_path_invalid', 'rules_missing'], true)) {
            return ['error_type' => 'infra_ruleset', 'retryable' => false];
        }

        if (in_array($reason, ['binary_missing', 'build_failed'], true)) {
            return ['error_type' => 'infra_config', 'retryable' => false];
        }

        if (in_array($reason, ['target_handle_invalid', 'target_handle_unseekable', 'target_missing_display_name', 'target_missing'], true)) {
            return ['error_type' => 'infra_input', 'retryable' => false];
        }

        if (in_array($reason, ['process_exception', 'process_failed'], true)) {
            return ['error_type' => 'infra_processing', 'retryable' => true];
        }

        if ($reason === 'file_too_large') {
            return ['error_type' => 'infra_limits', 'retryable' => false];
        }

        return ['error_type' => 'infra_unknown', 'retryable' => false];
    }

    private function nextRetryDelayMs(): int
    {
        $jitter = $this->scanRetryJitterMs > 0 ? random_int(0, $this->scanRetryJitterMs) : 0;

        return $this->scanRetryBackoffMs + $jitter;
    }

    private function sleepBeforeRetry(int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }

        usleep($delayMs * 1000);
    }
}
