<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Scanning;

use App\Support\Security\Exceptions\AntivirusException;
use App\Modules\Uploads\Pipeline\Exceptions\ScanFailedException;
use App\Modules\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Modules\Uploads\Pipeline\Exceptions\VirusDetectedException;
use App\Modules\Uploads\Pipeline\Scanning\ScanCircuitBreaker;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Coordina la ejecución de escáneres y el circuito de disponibilidad (Circuit Breaker).
 *
 * Mejoras implementadas:
 * - Inyección completa de dependencias (Cache, Logger, Config).
 * - Circuit Breaker encapsulado en su propia clase.
 * - Tipado fuerte en el registro de escáneres.
 * - Constantes para razones de fallo y valores de configuración.
 * - Trazabilidad mejorada de nombres de escáner.
 * - Sin llamadas estáticas a facades.
 *
 * @package App\Modules\Uploads\Pipeline\Scanning
 */
final class ScanCoordinator implements ScanCoordinatorInterface
{
    /**
     * Valores por defecto para la configuración de escaneo.
     */
    public const DEFAULT_ENABLED            = false;
    public const DEFAULT_TIMEOUT_MS         = 5000;
    public const DEFAULT_RETRY_ATTEMPTS     = 1;
    public const DEFAULT_RETRY_BACKOFF_MS   = 200;
    public const DEFAULT_RETRY_JITTER_MS    = 100;

    /**
     * @var array<string, callable(UploadedFile, array<string, mixed>): bool>
     */
    private readonly array $scannerRegistry;

    private readonly array $scanConfig;
    private readonly ScanCircuitBreaker $circuitBreaker;
    private readonly LoggerInterface $logger;
    private readonly ConfigRepository $config;

    /**
     * @param array<string, callable(UploadedFile, array<string, mixed>): bool> $scannerRegistry
     * @param array<string, mixed>|null                                          $scanConfig
     * @param ScanCircuitStoreInterface|null                                     $circuitStore
     * @param LoggerInterface|null                                               $logger
     * @param ConfigRepository|null                                              $config
     */
    public function __construct(
        array $scannerRegistry = [],
        ?array $scanConfig = null,
        ?ScanCircuitStoreInterface $circuitStore = null,
        ?LoggerInterface $logger = null,
        ?ConfigRepository $config = null,
    ) {
        $this->logger = $logger ?? app(LoggerInterface::class);
        $this->config = $config ?? app(ConfigRepository::class);
        $this->scannerRegistry = $this->validateScannerRegistry($scannerRegistry);

        // Fusionar configuración inyectada con valores por defecto
        $this->scanConfig = $scanConfig ?? (array) $this->config->get('image-pipeline.scan', []);

        // Configuración del Circuit Breaker
        $circuitConfig = (array) ($this->scanConfig['circuit_breaker'] ?? []);
        $this->circuitBreaker = new ScanCircuitBreaker(
            store: $circuitStore ?? app(ScanCircuitStoreInterface::class),
            logger: $this->logger,
            cacheKey: (string) ($circuitConfig['cache_key'] ?? ScanCircuitBreaker::DEFAULT_CACHE_KEY),
            maxFailures: (int) ($circuitConfig['max_failures'] ?? ScanCircuitBreaker::DEFAULT_MAX_FAILURES),
            decaySeconds: (int) ($circuitConfig['decay_seconds'] ?? ScanCircuitBreaker::DEFAULT_DECAY_SECONDS),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function enabled(): bool
    {
        // 1. Configuración inyectada (específica de la instancia)
        $enabled = (bool) ($this->scanConfig['enabled'] ?? self::DEFAULT_ENABLED);

        // 2. Compatibilidad con configuración legacy (solo si existe y es false)
        $legacyEnabled = $this->config->get('uploads.virus_scanning.enabled');
        if (is_bool($legacyEnabled) && $legacyEnabled === false) {
            return false;
        }

        return $enabled;
    }

    /**
     * {@inheritDoc}
     */
    public function assertAvailable(): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($this->circuitBreaker->isOpen()) {
            $this->logger->warning('image_upload.scan_circuit_open', [
                'max_failures' => $this->circuitBreaker->getMaxFailures(),
            ]);

            throw new ScanFailedException(__('media.uploads.scan_unavailable'));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function scan(UploadedFile $file, string $path, array $context = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->assertAvailable();

        $scanners = $this->resolveScanners();
        if ($scanners === []) {
            $this->circuitBreaker->recordFailure();
            $this->logger->error('image_upload.scan_missing_handlers', $context);
            throw new ScanFailedException(__('media.uploads.scan_unavailable'));
        }

        $scanContext = array_merge([
            'path'           => $path,
            'is_first_chunk' => true,
            'timeout_ms'     => (int) ($this->scanConfig['timeout_ms'] ?? self::DEFAULT_TIMEOUT_MS),
        ], $context);

        try {
            foreach ($scanners as $scanner) {
                $this->runSingleScanner($scanner, $file, $scanContext);
            }
        } catch (VirusDetectedException | UploadValidationException | ScanFailedException $e) {
            // No registrar fallo técnico en el circuit breaker si es virus o validación
            if ($e instanceof ScanFailedException) {
                $this->circuitBreaker->recordFailure();
            }
            throw $e;
        } catch (Throwable $e) {
            $this->circuitBreaker->recordFailure();
            throw new ScanFailedException(
                __('media.uploads.scan_unavailable'),
                scanner: $this->resolveScannerName($scanner ?? null),
                previous: $e
            );
        }

        $this->circuitBreaker->reset();
        $this->logger->info('image_upload.scan_passed', array_merge([
            'scanners' => $this->getActiveScannerKeys($scanners),
        ], $context));
    }

    /**
     * {@inheritDoc}
     */
    public function activeScannerKeys(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return $this->getActiveScannerKeys($this->resolveScanners());
    }

    /* -------------------------------------------------------------------------
     |  Métodos privados de soporte
     ------------------------------------------------------------------------- */

    /**
     * Valida que el registro de escáneres tenga el formato correcto.
     *
     * @param array<string, callable> $registry
     * @return array<string, callable(UploadedFile,array<string,mixed>):bool>
     * @throws \InvalidArgumentException
     */
    private function validateScannerRegistry(array $registry): array
    {
        foreach ($registry as $key => $scanner) {
            if (! is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Scanner registry keys must be non-empty strings.');
            }
            if (! is_callable($scanner)) {
                throw new \InvalidArgumentException(sprintf(
                    'Scanner for key "%s" must be callable, %s given.',
                    $key,
                    get_debug_type($scanner)
                ));
            }
        }
        return $registry;
    }

    /**
     * Resuelve las instancias de escáneres activos según la configuración.
     *
     * @return list<callable(UploadedFile,array<string,mixed>):bool>
     */
    private function resolveScanners(): array
    {
        /** @var list<string> $handlers */
        $handlers = array_map('strval', (array) ($this->scanConfig['handlers'] ?? []));

        $instances = [];
        foreach ($handlers as $handler) {
            $scanner = $this->scannerRegistry[$handler] ?? null;
            if (is_callable($scanner)) {
                $instances[] = $scanner;
            }
        }

        return array_values($instances);
    }

    /**
     * Ejecuta un único escáner con reintentos para fallos transitorios.
     *
     * @param callable(UploadedFile,array<string,mixed>):bool $scanner
     * @param UploadedFile                                    $file
     * @param array<string,mixed>                            $context
     * @throws AntivirusException
     * @throws VirusDetectedException
     * @throws UploadValidationException
     * @throws ScanFailedException
     */
    private function runSingleScanner(callable $scanner, UploadedFile $file, array $context): void
    {
        $attempt = 0;
        $maxAttempts = (int) ($this->scanConfig['retry_attempts'] ?? self::DEFAULT_RETRY_ATTEMPTS);
        $backoffMs = (int) ($this->scanConfig['retry_backoff_ms'] ?? self::DEFAULT_RETRY_BACKOFF_MS);
        $jitterMs = (int) ($this->scanConfig['retry_jitter_ms'] ?? self::DEFAULT_RETRY_JITTER_MS);

        while (true) {
            $attempt++;
            try {
                $clean = $scanner($file, $context + ['attempt' => $attempt]);
                if (! $clean) {
                    $this->logger->warning('image_upload.scan_blocked', array_merge([
                        'scanner' => $this->resolveScannerName($scanner),
                    ], $context));
                    throw new VirusDetectedException(__('media.uploads.scan_blocked'));
                }
                return; // Escaneo exitoso
            } catch (AntivirusException $e) {
                $classification = $this->classifyAntivirusFailure($e);
                $canRetry = $classification['retryable'] && $attempt < $maxAttempts;

                if (! $canRetry) {
                    $this->circuitBreaker->recordFailure();
                    $this->logger->error('image_upload.scanner_unavailable', array_merge([
                        'scanner'     => $this->resolveScannerName($scanner),
                        'reason'      => $e->getReason(),
                        'error_type'  => $classification['error_type'],
                        'retryable'   => $classification['retryable'],
                        'fail_closed' => true,
                    ], $context));
                    throw new UploadValidationException(__('media.uploads.scan_unavailable'), $e);
                }

                $retryDelayMs = $this->nextRetryDelayMs($backoffMs, $jitterMs);
                $this->logger->warning('image_upload.scanner_retry', array_merge([
                    'scanner'        => $this->resolveScannerName($scanner),
                    'attempt'        => $attempt,
                    'max_attempts'   => $maxAttempts,
                    'reason'         => $e->getReason(),
                    'error_type'     => $classification['error_type'],
                    'retry_delay_ms' => $retryDelayMs,
                ], $context));

                $this->sleepBeforeRetry($retryDelayMs);
            }
        }
    }

    /**
     * @param object|callable $scanner
     * @return string
     */
    private function resolveScannerName(object|callable $scanner): string
    {
        if (is_object($scanner)) {
            // Si es un objeto que implementa scannerKey(), lo usamos
            if (method_exists($scanner, 'scannerKey') && is_callable([$scanner, 'scannerKey'])) {
                return $scanner->scannerKey();
            }
            // Fallback: nombre corto de la clase
            return strtolower(class_basename($scanner));
        }

        // Es un closure / función
        if ($scanner instanceof \Closure) {
            return 'Closure';
        }

        // Función global o invocable de array
        return is_array($scanner) && is_string($scanner[0] ?? null)
            ? strtolower(class_basename($scanner[0])) . '@' . ($scanner[1] ?? '__invoke')
            : 'callable_scanner';
    }

    /**
     * @param list<callable> $scanners
     * @return list<string>
     */
    private function getActiveScannerKeys(array $scanners): array
    {
        return array_map(
            fn($scanner): string => $this->resolveScannerName($scanner),
            $scanners
        );
    }

    /**
     * Clasifica una excepción de antivirus para determinar si es reintentable.
     *
     * @param AntivirusException $exception
     * @return array{error_type: string, retryable: bool}
     */
    private function classifyAntivirusFailure(AntivirusException $exception): array
    {
        $reason = $exception->reason();

        return match (true) {
            $reason === AntivirusException::REASON_TIMEOUT,
            $reason === AntivirusException::REASON_PROCESS_TIMEOUT
                => ['error_type' => 'infra_timeout', 'retryable' => true],

            $reason === AntivirusException::REASON_UNREACHABLE,
            $reason === AntivirusException::REASON_CONNECTION_REFUSED
                => ['error_type' => 'infra_unavailable', 'retryable' => true],

            $reason === AntivirusException::REASON_RULESET_INVALID,
            $reason === AntivirusException::REASON_RULESET_MISSING,
            $reason === AntivirusException::REASON_RULES_INTEGRITY_FAILED,
            $reason === AntivirusException::REASON_RULES_PATH_INVALID,
            $reason === AntivirusException::REASON_RULES_MISSING
                => ['error_type' => 'infra_ruleset', 'retryable' => false],

            $reason === AntivirusException::REASON_BINARY_MISSING,
            $reason === AntivirusException::REASON_BUILD_FAILED,
            $reason === AntivirusException::REASON_ALLOWLIST_EMPTY
                => ['error_type' => 'infra_config', 'retryable' => false],

            $reason === AntivirusException::REASON_TARGET_HANDLE_INVALID,
            $reason === AntivirusException::REASON_TARGET_HANDLE_UNSEEKABLE,
            $reason === AntivirusException::REASON_TARGET_MISSING_DISPLAY_NAME,
            $reason === AntivirusException::REASON_TARGET_MISSING
                => ['error_type' => 'infra_input', 'retryable' => false],

            $reason === AntivirusException::REASON_PROCESS_EXCEPTION,
            $reason === AntivirusException::REASON_PROCESS_FAILED
                => ['error_type' => 'infra_processing', 'retryable' => true],

            $reason === AntivirusException::REASON_FILE_TOO_LARGE,
            $reason === AntivirusException::REASON_FILE_SIZE_UNKNOWN
                => ['error_type' => 'infra_limits', 'retryable' => false],

            default => ['error_type' => 'infra_unknown', 'retryable' => false],
        };
    }

    private function nextRetryDelayMs(int $backoffMs, int $jitterMs): int
    {
        if ($jitterMs <= 0) {
            return $backoffMs;
        }

        try {
            $jitter = random_int(0, $jitterMs);
        } catch (\Throwable) {
            $jitter = $jitterMs / 2; // fallback determinista
        }

        return $backoffMs + $jitter;
    }

    private function sleepBeforeRetry(int $delayMs): void
    {
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }
}
